<?php

namespace MajorDoMo\Core\Filesystem;

/**
 * Read-only directory browser restricted to one project root.
 *
 * The admin uses it for path fields (backups, logs, certificates). The UI must
 * never be able to walk outside the project, even through "..", absolute paths
 * or a symlink. The class deliberately exposes directories only: it is a
 * picker, not a file manager.
 */
final class FolderBrowser
{
    private string $root;
    private string $rootName;
    private int $maxEntries;

    /** @var string[] */
    private const SKIP = array('node_modules', 'vendor');

    public function __construct(string $root, int $maxEntries = 400)
    {
        $real = realpath($root);
        if ($real === false || !is_dir($real)) {
            throw new \RuntimeException('Project root is not available.');
        }

        $this->root = rtrim($real, '/\\');
        if ($this->root === '') {
            $this->root = DIRECTORY_SEPARATOR;
        }
        $name = basename($this->root);
        $this->rootName = $name !== '' ? $name : $this->root;
        $this->maxEntries = max(1, $maxEntries);
    }

    public function root(): string
    {
        return $this->root;
    }

    /**
     * Resolve a user-supplied path to an existing directory inside the root.
     * Returns null for files, missing paths, traversal attempts and symlinks
     * that point outside the project.
     */
    public function resolve(string $path): ?string
    {
        $path = trim($path);
        if ($path === '' || $path === '.' || $path === '/' || $path === '\\') {
            return $this->root;
        }

        if (strlen($path) > 4096) {
            return null;
        }

        if ($this->isAbsolute($path)) {
            $candidate = $path;
        } else {
            $relative = $this->normalizeRelative($path);
            $candidate = $relative === '' ? $this->root : $this->root . DIRECTORY_SEPARATOR . $relative;
        }

        $real = realpath($candidate);
        if ($real === false || !is_dir($real) || !$this->contains($real)) {
            return null;
        }

        $real = $this->trimSeparator($real);
        if (!$this->isVisibleRelative($this->relative($real))) {
            return null;
        }

        return $real;
    }

    /**
     * @return array{
     *     ok:bool,
     *     error?:string,
     *     path:string,
     *     absolute:string,
     *     parent:?string,
     *     root:array{name:string,path:string},
     *     dirs:array<int,array{name:string,path:string,absolute:string,readable:bool}>,
     *     truncated:bool,
     *     writable:bool,
     *     fallback:bool
     * }
     */
    public function listing(string $path = ''): array
    {
        $fallback = false;
        $current = $this->resolve($path);
        if ($current === null) {
            $current = $this->root;
            $fallback = true;
        }

        $dirs = array();
        $truncated = false;
        $scanned = 0;
        $handle = @opendir($current);
        if ($handle === false) {
            return array(
                'ok' => false,
                'error' => 'Нет доступа к выбранной папке.',
                'path' => $this->relative($current),
                'absolute' => $current,
                'parent' => $this->parent($current),
                'root' => array('name' => $this->rootName, 'path' => ''),
                'dirs' => array(),
                'truncated' => false,
                'writable' => false,
                'fallback' => $fallback,
            );
        }

        while (($entry = readdir($handle)) !== false) {
            $scanned++;
            if ($scanned > 5000) {
                $truncated = true;
                break;
            }
            if ($entry === '.' || $entry === '..' || $entry === '') {
                continue;
            }
            if ($entry[0] === '.') {
                continue;
            }
            if (in_array(strtolower($entry), self::SKIP, true)) {
                continue;
            }

            $absolute = $current . DIRECTORY_SEPARATOR . $entry;
            if (!is_dir($absolute)) {
                continue;
            }
            $real = realpath($absolute);
            if ($real === false || !$this->contains($real)) {
                continue;
            }

            $dirs[] = array(
                'name' => $entry,
                'path' => $this->relative($real),
                'absolute' => $this->trimSeparator($real),
                'readable' => is_readable($real),
            );
        }
        closedir($handle);

        usort($dirs, static function (array $a, array $b): int {
            return strnatcasecmp($a['name'], $b['name']);
        });

        if (count($dirs) > $this->maxEntries) {
            $dirs = array_slice($dirs, 0, $this->maxEntries);
            $truncated = true;
        }

        return array(
            'ok' => true,
            'path' => $this->relative($current),
            'absolute' => $current,
            'parent' => $this->parent($current),
            'root' => array('name' => $this->rootName, 'path' => ''),
            'dirs' => $dirs,
            'truncated' => $truncated,
            'writable' => is_writable($current),
            'fallback' => $fallback,
        );
    }

    private function parent(string $absolute): ?string
    {
        $parent = realpath(dirname($absolute));
        if ($parent === false || !$this->contains($parent)) {
            return null;
        }
        return $this->relative($parent);
    }

    private function relative(string $absolute): string
    {
        $absolute = $this->trimSeparator($absolute);
        if ($absolute === $this->root) {
            return '';
        }

        $root = rtrim($this->root, '/\\');
        if ($root === '') {
            $relative = ltrim($absolute, '/\\');
        } else {
            $relative = substr($absolute, strlen($root));
        }

        return trim(str_replace('\\', '/', $relative), '/');
    }

    private function contains(string $path): bool
    {
        $path = $this->trimSeparator($path);
        $root = rtrim($this->root, '/\\');
        if ($root === '') {
            return true;
        }
        if (DIRECTORY_SEPARATOR === '\\') {
            return strcasecmp($path, $root) === 0
                || stripos($path, $root . DIRECTORY_SEPARATOR) === 0;
        }
        return $path === $root || str_starts_with($path, $root . DIRECTORY_SEPARATOR);
    }

    private function trimSeparator(string $path): string
    {
        if ($path === DIRECTORY_SEPARATOR) {
            return $path;
        }
        $trimmed = rtrim($path, '/\\');
        return $trimmed === '' ? DIRECTORY_SEPARATOR : $trimmed;
    }

    private function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || (bool)preg_match('/^[A-Za-z]:[\\\\\/]/', $path);
    }

    private function isVisibleRelative(string $relative): bool
    {
        foreach (explode('/', $relative) as $segment) {
            if ($segment === '') {
                continue;
            }
            if ($segment[0] === '.' || in_array(strtolower($segment), self::SKIP, true)) {
                return false;
            }
        }
        return true;
    }

    private function normalizeRelative(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $segments = array();
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }
        return implode('/', $segments);
    }
}
