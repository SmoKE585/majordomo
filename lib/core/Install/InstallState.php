<?php

namespace MajorDoMo\Core\Install;

/**
 * Installation state.
 *
 * MajorDoMo has two "first run" layers:
 *
 *  - the legacy module installer creates cms/modules_installed/*.installed;
 *  - the new setup wizard owns a single install.installed marker.
 *
 * The marker is deliberately a file, not a database row: the gate must be able
 * to decide whether the system is configured even when the database is down or
 * contains no settings yet. Existing installations that only have the legacy
 * marker are treated as configured, so an upgrade never sends production users
 * into the wizard.
 */
final class InstallState
{
    public const STATUS_COMPLETE = 'complete';
    public const STATUS_INCOMPLETE = 'incomplete';

    private const RELATIVE_MARKER = 'cms/modules_installed/install.installed';
    private const RELATIVE_LEGACY_MARKER = 'cms/modules_installed/control_modules.installed';

    public static function markerPath(string $root): string
    {
        return self::join($root, self::RELATIVE_MARKER);
    }

    public static function legacyMarkerPath(string $root): string
    {
        return self::join($root, self::RELATIVE_LEGACY_MARKER);
    }

    /**
     * @return array{status?:string,completed_at?:string,source?:string,project_title?:string,reason?:string}|null
     */
    public static function read(string $root): ?array
    {
        $path = self::markerPath($root);
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }
        $contents = @file_get_contents($path);
        if (!is_string($contents) || trim($contents) === '') {
            return null;
        }
        $data = json_decode($contents, true);
        return is_array($data) ? $data : null;
    }

    public static function isLegacy(string $root): bool
    {
        return is_file(self::legacyMarkerPath($root));
    }

    public static function isComplete(string $root): bool
    {
        $marker = self::read($root);
        if (is_array($marker) && isset($marker['status'])) {
            if ($marker['status'] === self::STATUS_COMPLETE) {
                return true;
            }
            if ($marker['status'] === self::STATUS_INCOMPLETE) {
                return false;
            }
        }

        // No setup marker at all: honour the legacy installation marker.
        return self::isLegacy($root);
    }

    /**
     * Human-readable state for the wizard, CLI and tests.
     *
     * @return array{complete:bool,legacy:bool,marker_exists:bool,status:string,completed_at:string,source:string}
     */
    public static function state(string $root): array
    {
        $marker = self::read($root);
        $markerExists = is_file(self::markerPath($root));
        $legacy = self::isLegacy($root);
        $status = '';
        if (is_array($marker) && isset($marker['status']) && is_string($marker['status'])) {
            $status = $marker['status'];
        }
        if ($status === '') {
            $status = $legacy ? self::STATUS_COMPLETE : self::STATUS_INCOMPLETE;
        }

        return array(
            'complete' => self::isComplete($root),
            'legacy' => $legacy,
            'marker_exists' => $markerExists,
            'status' => $status,
            'completed_at' => is_array($marker) && isset($marker['completed_at']) ? (string)$marker['completed_at'] : '',
            'source' => is_array($marker) && isset($marker['source']) ? (string)$marker['source'] : ($legacy ? 'legacy' : ''),
        );
    }

    /**
     * Mark the wizard as finished.
     *
     * @param array<string,string> $meta Extra fields written to the marker (project_title, base_url).
     */
    public static function markComplete(string $root, array $meta = array()): bool
    {
        $payload = array(
            'status' => self::STATUS_COMPLETE,
            'completed_at' => gmdate('c'),
            'source' => isset($meta['source']) ? (string)$meta['source'] : 'install.php',
        );
        foreach (array('project_title', 'base_url', 'db_name') as $key) {
            if (isset($meta[$key]) && is_scalar($meta[$key])) {
                $payload[$key] = (string)$meta[$key];
            }
        }
        return self::writeMarker($root, $payload);
    }

    /**
     * Explicitly reopen the wizard. The legacy marker is left untouched: it
     * belongs to the module installer and other code depends on it.
     */
    public static function reset(string $root, string $reason = 'cli reset'): bool
    {
        return self::writeMarker($root, array(
            'status' => self::STATUS_INCOMPLETE,
            'reset_at' => gmdate('c'),
            'reason' => $reason,
            'source' => 'install.php',
        ));
    }

    /**
     * Canonical setup URL. `install.php` works without nginx rewrites, which is
     * important because a broken/updated nginx config must not lock the user
     * out of the only page that can fix the installation.
     */
    public static function installUrl(string $rootHtml = '/'): string
    {
        $root = '/' . trim(str_replace('\\', '/', $rootHtml), '/');
        return ($root === '/' ? '/' : $root . '/') . 'install.php';
    }

    private static function writeMarker(string $root, array $payload): bool
    {
        $path = self::markerPath($root);
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return false;
        }
        if (!is_writable($directory) && !(is_file($path) && is_writable($path))) {
            return false;
        }

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            return false;
        }
        $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (@file_put_contents($temporary, $json . PHP_EOL, LOCK_EX) === false) {
            return false;
        }
        @chmod($temporary, 0640);
        if (!@rename($temporary, $path)) {
            @unlink($path);
            if (!@rename($temporary, $path)) {
                @unlink($temporary);
                return false;
            }
        }
        return true;
    }

    private static function join(string $root, string $relative): string
    {
        return rtrim($root, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }
}
