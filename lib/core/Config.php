<?php

namespace MajorDoMo\Core;

/**
 * Runtime configuration.
 *
 * Resolution order for every key:
 *   1. a real process environment variable with the same name (getenv/$_SERVER)
 *   2. `.env.local`
 *   3. `.env`
 *   4. an already defined legacy constant (old config.php)
 *   5. the schema default / a computed value
 *
 * Legacy constants are defined only for keys that are actually present. That
 * keeps `defined('PYTHON_PATH')`-style feature detection working exactly as it
 * did before the migration.
 */
final class Config
{
    private static bool $booted = false;
    private static string $root = '';
    /** @var array<string, array<string, mixed>> */
    private static array $schema = array();
    /** @var array<string, mixed> */
    private static array $values = array();
    /** @var array<string, bool> */
    private static array $present = array();
    /** @var array<string, string> */
    private static array $sources = array();
    /** @var array<string, string> */
    private static array $process = array();
    /** @var string[] Diagnostics collected before paths/constants are resolved. */
    private static array $pendingWarnings = array();

    public static function boot(?string $root = null): void
    {
        if (self::$booted) {
            return;
        }

        self::$root = rtrim($root ?? dirname(__DIR__, 2), '/\\');
        self::$schema = ConfigSchema::all();
        self::$process = self::readProcessEnvironment();

        $fingerprint = ConfigCache::fingerprint(self::$root, self::$schema);
        $cached = ConfigCache::read(self::$root, $fingerprint);
        if ($cached === null) {
            $raw = self::readEnvironmentFiles();
            ConfigCache::write(self::$root, $raw, $fingerprint);
        } else {
            $raw = $cached['raw'];
        }

        self::resolve($raw);
        self::derive();
        self::applyTimezone();
        self::defineConstants();

        self::$booted = true;
        foreach (self::$pendingWarnings as $message) {
            self::log($message);
        }
        self::$pendingWarnings = array();
    }

    public static function root(): string
    {
        self::ensureBooted();
        return self::$root;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        self::ensureBooted();
        if (!(self::$present[$key] ?? false)) {
            return $default;
        }
        return self::$values[$key];
    }

    public static function has(string $key): bool
    {
        self::ensureBooted();
        return self::$present[$key] ?? false;
    }

    public static function string(string $key, string $default = ''): string
    {
        $value = self::get($key, $default);
        return is_scalar($value) ? (string)$value : $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        if (!self::has($key)) {
            return $default;
        }
        return (bool)self::get($key);
    }

    public static function int(string $key, int $default = 0): int
    {
        if (!self::has($key)) {
            return $default;
        }
        return (int)self::get($key);
    }

    public static function float(string $key, float $default = 0.0): float
    {
        if (!self::has($key)) {
            return $default;
        }
        return (float)self::get($key);
    }

    /**
     * @return array<int|string, mixed>
     */
    public static function arrayValue(string $key, array $default = array()): array
    {
        $value = self::get($key, $default);
        return is_array($value) ? $value : $default;
    }

    /**
     * Configuration snapshot without secrets, suitable for doctor/audit output.
     *
     * @return array<string, mixed>
     */
    public static function safeAll(): array
    {
        self::ensureBooted();
        $result = array();
        foreach (self::$schema as $key => $definition) {
            if (($definition['secret'] ?? false) || !(self::$present[$key] ?? false)) {
                continue;
            }
            $result[$key] = self::$values[$key];
        }
        return $result;
    }

    public static function source(string $key): ?string
    {
        self::ensureBooted();
        return self::$sources[$key] ?? null;
    }

    /**
     * Schema without the internal flags, for tools and documentation.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function schema(): array
    {
        return ConfigSchema::all();
    }

    private static function ensureBooted(): void
    {
        if (!self::$booted) {
            self::boot();
        }
    }

    /**
     * @return array<string, string>
     */
    private static function readEnvironmentFiles(): array
    {
        $values = Env::parseFile(self::$root . DIRECTORY_SEPARATOR . '.env');
        $local = Env::parseFile(self::$root . DIRECTORY_SEPARATOR . '.env.local');
        foreach ($local as $key => $value) {
            $values[$key] = $value;
        }
        return $values;
    }

    /**
     * Read only keys declared in the schema; unrelated FPM/server variables must
     * not leak into the configuration namespace.
     *
     * @return array<string, string>
     */
    private static function readProcessEnvironment(): array
    {
        $values = array();
        foreach (self::$schema as $key => $definition) {
            $value = getenv($key);
            if ($value === false && isset($_SERVER[$key]) && is_scalar($_SERVER[$key])) {
                $value = (string)$_SERVER[$key];
            }
            if ($value !== false) {
                $values[$key] = (string)$value;
            }
        }
        // Legacy entry point for the public base URL. getenv() is reliable,
        // $_ENV is not: its contents depend on variables_order.
        $base = getenv('S2G_BASE_URL');
        if ($base === false && isset($_SERVER['S2G_BASE_URL']) && is_scalar($_SERVER['S2G_BASE_URL'])) {
            $base = (string)$_SERVER['S2G_BASE_URL'];
        }
        if ($base !== false) {
            $values['S2G_BASE_URL'] = (string)$base;
        }
        return $values;
    }

    /**
     * @param array<string, string> $raw
     */
    private static function resolve(array $raw): void
    {
        foreach (self::$schema as $key => $definition) {
            $value = null;
            $source = null;

            if (($definition['env'] ?? true) !== false) {
                if (array_key_exists($key, self::$process)) {
                    $value = self::$process[$key];
                    $source = 'process';
                } elseif (array_key_exists($key, $raw)) {
                    $value = $raw[$key];
                    $source = 'env';
                }
            }

            if ($value !== null) {
                self::$values[$key] = self::cast($key, $value);
                self::$present[$key] = true;
                self::$sources[$key] = $source;
                continue;
            }

            if (defined($key)) {
                self::$values[$key] = self::cast($key, constant($key));
                self::$present[$key] = true;
                self::$sources[$key] = 'constant';
                continue;
            }

            // Computed keys are filled by derive(); optional keys stay absent.
            if (($definition['computed'] ?? false) || ($definition['optional'] ?? false)) {
                self::$present[$key] = false;
                continue;
            }

            if (array_key_exists('default', $definition)) {
                self::$values[$key] = self::cast($key, $definition['default']);
                self::$present[$key] = true;
                self::$sources[$key] = 'default';
                continue;
            }

            self::$present[$key] = false;
        }
    }

    private static function derive(): void
    {
        if (!(self::$present['DOC_ROOT'] ?? false)) {
            self::$values['DOC_ROOT'] = self::$root;
            self::$present['DOC_ROOT'] = true;
            self::$sources['DOC_ROOT'] = 'computed';
        }
        if (!(self::$present['ROOT'] ?? false)) {
            self::$values['ROOT'] = self::$root . '/';
            self::$present['ROOT'] = true;
            self::$sources['ROOT'] = 'computed';
        }
        if (!(self::$present['SERVER_ROOT'] ?? false)) {
            self::$values['SERVER_ROOT'] = self::$values['DOC_ROOT'];
            self::$present['SERVER_ROOT'] = true;
            self::$sources['SERVER_ROOT'] = 'computed';
        }
        if (!(self::$present['BASE_URL'] ?? false)) {
            $base = self::$process['S2G_BASE_URL'] ?? 'http://127.0.0.1:80';
            self::$values['BASE_URL'] = self::cast('BASE_URL', $base);
            self::$present['BASE_URL'] = true;
            self::$sources['BASE_URL'] = isset(self::$process['S2G_BASE_URL']) ? 'process' : 'computed';
        }
        if (!(self::$present['PATH_TO_PHP'] ?? false)) {
            self::$values['PATH_TO_PHP'] = PHP_BINARY;
            self::$present['PATH_TO_PHP'] = true;
            self::$sources['PATH_TO_PHP'] = 'computed';
        }

        if (!(self::$present['GIT_URL'] ?? false)) {
            return;
        }
        $gitUrl = (string)self::$values['GIT_URL'];
        $derived = array(
            'PROJECT_URL' => $gitUrl,
            'PROJECT_COMMIT_URL' => $gitUrl !== '' ? rtrim($gitUrl, '/') . '/commit/' : '',
            'MASTER_UPDATE_URL' => $gitUrl !== '' ? rtrim($gitUrl, '/') . '/archive/master.tar.gz' : '',
        );
        foreach ($derived as $key => $value) {
            if (self::$present[$key] ?? false) {
                continue;
            }
            self::$values[$key] = $value;
            self::$present[$key] = true;
            self::$sources[$key] = 'derived';
        }
    }

    private static function cast(string $key, mixed $value): mixed
    {
        $type = self::$schema[$key]['type'] ?? 'string';

        if ($type === 'boolean') {
            return self::toBool($value, $key);
        }
        if ($type === 'integer') {
            return (int)$value;
        }
        if ($type === 'decimal') {
            return (float)$value;
        }
        if ($type === 'host_or_false') {
            if (is_bool($value)) {
                return $value ? (string)$value : false;
            }
            $normalized = strtolower(trim((string)$value));
            if (in_array($normalized, array('', '0', 'false', 'no', 'off', 'none', 'null'), true)) {
                return false;
            }
            return (string)$value;
        }
        if ($type === 'list') {
            return self::toList($value);
        }
        if ($type === 'json') {
            return self::toJson($value, $key);
        }
        return (string)$value;
    }

    private static function toBool(mixed $value, string $key): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (bool)$value;
        }
        $normalized = strtolower(trim((string)$value));
        if (in_array($normalized, array('1', 'true', 'yes', 'on', 'enabled'), true)) {
            return true;
        }
        if (in_array($normalized, array('', '0', 'false', 'no', 'off', 'disabled', 'none'), true)) {
            return false;
        }
        self::log('Invalid boolean value for ' . $key . ', using false');
        return false;
    }

    /**
     * @return array<int, string>
     */
    private static function toList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_map('strval', $value));
        }
        $value = trim((string)$value);
        if ($value === '') {
            return array();
        }
        if (str_starts_with($value, '[')) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return array_values(array_map('strval', $decoded));
            }
        }
        return array_values(array_filter(array_map('trim', explode(',', $value)), static fn($item) => $item !== ''));
    }

    /**
     * @return array<mixed>
     */
    private static function toJson(mixed $value, string $key): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string)$value, true);
        if (!is_array($decoded)) {
            self::log('Invalid JSON value for ' . $key . ', using an empty array');
            return array();
        }
        return $decoded;
    }

    private static function applyTimezone(): void
    {
        if (!(self::$present['TIMEZONE'] ?? false)) {
            return;
        }
        $timezone = (string)self::$values['TIMEZONE'];
        if ($timezone === '') {
            return;
        }
        if (!in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            self::log('Unknown TIMEZONE "' . $timezone . '", keeping the PHP default');
            return;
        }
        date_default_timezone_set($timezone);
    }

    private static function defineConstants(): void
    {
        foreach (self::$schema as $key => $definition) {
            if (($definition['constant'] ?? true) === false) {
                continue;
            }
            if (!(self::$present[$key] ?? false) || defined($key)) {
                continue;
            }
            $value = self::$values[$key];
            if (is_array($value)) {
                continue;
            }
            define($key, $value);
        }
    }

    private static function log(string $message): void
    {
        if (!self::$booted) {
            self::$pendingWarnings[] = $message;
            return;
        }
        if (function_exists('DebMes')) {
            DebMes($message, 'config', 'warning');
            return;
        }
        // Config boots before the general library on CLI/public entry points.
        $path = self::string('LOG_DIRECTORY');
        $directory = ($path !== '' ? rtrim($path, '/\\') : self::$root . '/cms/debmes') . '/' . date('Y-m-d');
        $previousUmask = umask(0);
        try {
            if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
                error_log('MajorDoMo config: ' . $message);
                return;
            }
            $file = $directory . '/errors.log';
            $maxSize = self::int('LOG_MAX_SIZE');
            $maxSize = ($maxSize > 0 ? $maxSize : 5) * 1024 * 1024;
            if (is_file($file) && filesize($file) > $maxSize) return;
            if (file_put_contents(
                $file,
                date('H:i:s') . ' ' . explode(' ', microtime())[0] . ' [WARNING] [config] ' . $message . PHP_EOL,
                FILE_APPEND | LOCK_EX
            ) === false) {
                error_log('MajorDoMo config: ' . $message);
            }
        } finally {
            umask($previousUmask);
        }
    }
}
