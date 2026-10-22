<?php

namespace MajorDoMo\Core\Deploy;

use RuntimeException;

/**
 * Удаление файлов, которые выведены из проекта.
 *
 * Список хранится в `deploy/removals.json` и применяется при деплое. Сравнивать
 * git-дерево с каталогом пользователя нельзя: в проекте живут пользовательские
 * модули, шаблоны и данные. Поэтому удаляются только явно перечисленные пути и
 * только внутри корня проекта.
 *
 * 2026-10-22 — добавлено для безопасной очистки старых системных файлов.
 */
final class FileRemovals
{
    private string $root;
    private string $manifest;

    /** Префиксы, которые нельзя удалять даже явной записью в манифесте. */
    private const PROTECTED_PREFIXES = array(
        'cms/',
        'backup/',
        'database_backup/',
        'vendor/',
        'node_modules/',
        '.git/',
    );

    /** Точные файлы, которые не могут быть удалены манифестом. */
    private const PROTECTED_FILES = array(
        'config.php',
        '.env',
        '.env.local',
        'composer.json',
        'composer.lock',
        'deploy/removals.json',
    );

    public function __construct(string $root, ?string $manifest = null)
    {
        $this->root = rtrim($root, '/\\');
        $this->manifest = $manifest ?? $this->root . '/deploy/removals.json';
    }

    public function manifestPath(): string
    {
        return $this->manifest;
    }

    /**
     * Применить манифест. Отсутствующие пути пропускаются: повторный деплой
     * после частичного применения должен быть безопасным.
     *
     * @param callable|null $log function(string $kind, string $path): void
     * @return array{files:int,directories:int,patterns:int,skipped:int}
     */
    public function apply(?callable $log = null): array
    {
        $manifest = $this->load();
        $result = array('files' => 0, 'directories' => 0, 'patterns' => 0, 'skipped' => 0);

        foreach ($manifest['files'] as $path) {
            if ($this->removePath($path, false, $log)) $result['files']++;
            else $result['skipped']++;
        }
        foreach ($manifest['patterns'] as $pattern) {
            foreach ($this->expandPattern($pattern) as $path) {
                if ($this->removePath($path, false, $log)) $result['patterns']++;
            }
        }
        foreach ($manifest['directories'] as $directory) {
            if ($this->removePath($directory['path'], $directory['recursive'], $log)) $result['directories']++;
            else $result['skipped']++;
        }

        return $result;
    }

    /**
     * @return array{version:int,files:string[],directories:array<int,array{path:string,recursive:bool}>,patterns:string[]}
     */
    public function load(): array
    {
        $empty = array('version' => 1, 'files' => array(), 'directories' => array(), 'patterns' => array());
        if (!is_file($this->manifest)) return $empty;
        $data = json_decode((string)file_get_contents($this->manifest), true);
        if (!is_array($data)) {
            throw new RuntimeException('Не удалось прочитать deploy/removals.json: ожидается JSON-объект.');
        }

        $files = array();
        foreach ((array)($data['files'] ?? array()) as $path) {
            if (!is_string($path) || trim($path) === '') continue;
            $files[] = $this->normalize($path);
        }

        $directories = array();
        foreach ((array)($data['directories'] ?? array()) as $directory) {
            if (is_string($directory)) {
                $directories[] = array('path' => $this->normalize($directory), 'recursive' => false);
                continue;
            }
            if (!is_array($directory) || !isset($directory['path']) || !is_string($directory['path'])) {
                throw new RuntimeException('deploy/removals.json: элемент directories должен содержать path.');
            }
            $directories[] = array(
                'path' => $this->normalize($directory['path']),
                'recursive' => !empty($directory['recursive']),
            );
        }

        $patterns = array();
        foreach ((array)($data['patterns'] ?? array()) as $pattern) {
            if (!is_string($pattern) || trim($pattern) === '') continue;
            $patterns[] = $this->normalizePattern($pattern);
        }

        return array(
            'version' => (int)($data['version'] ?? 1),
            'files' => array_values(array_unique($files)),
            'directories' => $directories,
            'patterns' => array_values(array_unique($patterns)),
        );
    }

    /**
     * Удалить один путь относительно корня.
     *
     * @param callable|null $log function(string $kind, string $path): void
     */
    public function removePath(string $relativePath, bool $recursive = false, ?callable $log = null): bool
    {
        $relativePath = $this->normalize($relativePath);
        if ($this->isProtected($relativePath)) {
            throw new RuntimeException('deploy/removals.json: защищённый путь нельзя удалять: ' . $relativePath);
        }

        $target = $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        if (!file_exists($target) && !is_link($target)) {
            return false;
        }

        $this->assertInsideRoot($target);
        if (is_link($target) || is_file($target)) {
            if (!@unlink($target)) throw new RuntimeException('Не удалось удалить файл: ' . $relativePath);
            if ($log !== null) $log('file', $relativePath);
            return true;
        }
        if (!is_dir($target)) {
            return false;
        }

        if ($recursive) {
            $this->removeTree($target);
            if ($log !== null) $log('directory', $relativePath);
            return true;
        }

        if (!@rmdir($target)) {
            // Непустой каталог не является ошибкой: манифест мог перечислить
            // только конкретные файлы, а пользовательские файлы внутри важнее.
            return false;
        }
        if ($log !== null) $log('directory', $relativePath);
        return true;
    }

    /** Удалить каталог рекурсивно, не разыменовывая symlink. */
    public function removeTree(string $target): void
    {
        $this->assertInsideRoot($target);
        if (is_link($target) || is_file($target)) {
            if (!@unlink($target)) throw new RuntimeException('Не удалось удалить файл: ' . $target);
            return;
        }
        if (!is_dir($target)) return;
        $items = scandir($target);
        if ($items === false) throw new RuntimeException('Не удалось прочитать каталог: ' . $target);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $child = $target . DIRECTORY_SEPARATOR . $item;
            $this->assertInsideRoot($child);
            if (is_link($child) || is_file($child)) {
                if (!@unlink($child)) throw new RuntimeException('Не удалось удалить файл: ' . $child);
                continue;
            }
            if (is_dir($child)) $this->removeTree($child);
        }
        if (!@rmdir($target)) throw new RuntimeException('Не удалось удалить каталог: ' . $target);
    }

    /** @return string[] */
    private function expandPattern(string $pattern): array
    {
        $matches = glob($this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $pattern));
        if (!is_array($matches)) return array();
        $paths = array();
        foreach ($matches as $match) {
            $realRoot = realpath($this->root);
            $realMatch = realpath($match);
            if ($realRoot === false || $realMatch === false) continue;
            if ($realMatch !== $realRoot && strpos($realMatch, $realRoot . DIRECTORY_SEPARATOR) !== 0) continue;
            if (is_dir($realMatch) && !is_link($realMatch)) continue;
            $relative = substr($realMatch, strlen($realRoot) + 1);
            $relative = str_replace('\\', '/', $relative);
            if ($relative !== '' && !$this->isProtected($relative)) $paths[] = $relative;
        }
        return array_values(array_unique($paths));
    }

    public function normalize(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        if ($path === '' || str_contains($path, "\0")) {
            throw new RuntimeException('deploy/removals.json: пустой путь.');
        }
        if (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:/', $path)) {
            throw new RuntimeException('deploy/removals.json: путь должен быть относительным: ' . $path);
        }
        $segments = explode('/', $path);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new RuntimeException('deploy/removals.json: недопустимый сегмент пути: ' . $path);
            }
        }
        return implode('/', $segments);
    }

    private function normalizePattern(string $pattern): string
    {
        $pattern = str_replace('\\', '/', trim($pattern));
        if ($pattern === '' || str_contains($pattern, "\0") || str_starts_with($pattern, '/') || preg_match('/^[A-Za-z]:/', $pattern)) {
            throw new RuntimeException('deploy/removals.json: недопустимый pattern: ' . $pattern);
        }
        foreach (explode('/', $pattern) as $segment) {
            if ($segment === '.' || $segment === '..') {
                throw new RuntimeException('deploy/removals.json: недопустимый pattern: ' . $pattern);
            }
        }
        return $pattern;
    }

    public function isProtected(string $relativePath): bool
    {
        $path = strtolower(str_replace('\\', '/', $relativePath));
        foreach (self::PROTECTED_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) return true;
        }
        if (in_array($path, array('cms', 'backup', 'database_backup', 'vendor', 'node_modules', '.git'), true)) return true;
        if (in_array($path, self::PROTECTED_FILES, true)) return true;
        return (bool)preg_match('#^\.env\..+\.local$#D', $path);
    }

    private function assertInsideRoot(string $target): void
    {
        $realRoot = realpath($this->root);
        if ($realRoot === false) throw new RuntimeException('Не найден корень проекта.');
        // Проверяем именно родителя: сам target может быть symlink, который
        // нужно удалить, не разыменовывая. Родитель же не должен выводить за
        // пределы проекта.
        $parent = realpath(dirname($target));
        if ($parent === false) {
            throw new RuntimeException('Не найден родительский каталог: ' . $target);
        }
        $root = str_replace('\\', '/', $realRoot);
        $parent = str_replace('\\', '/', $parent);
        if ($parent !== $root && !str_starts_with($parent, $root . '/')) {
            throw new RuntimeException('Путь выходит за корень проекта: ' . $target);
        }
        if ($parent === $root && basename($target) === '') {
            throw new RuntimeException('Нельзя удалить корень проекта.');
        }
    }
}
