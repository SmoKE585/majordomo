<?php

namespace MajorDoMo\Core\Api;

/**
 * Превращает сохранённые модуль и относительный PHP-обработчик в реальный файл
 * внутри каталога установленного модуля.
 *
 * Признак установки — тот же маркер, что использует остальной MajorDoMo
 * (cms/modules_installed/<module>.installed). Абсолютные пути, stream wrappers,
 * сегменты обхода и symlink за пределы каталога модуля отклоняются.
 *
 * Изменения: 2026-10-22 — добавлен резолвер обработчиков публичных webhook.
 */
final class HookRegistry
{
    /** @return string[] */
    public static function installedModules(string $root): array
    {
        $directory = rtrim($root, '/\\') . '/cms/modules_installed';
        $modules = array();
        foreach (glob($directory . '/*.installed') ?: array() as $file) {
            $name = basename($file, '.installed');
            if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,99}$/D', $name)) {
                continue;
            }
            if (is_dir(self::normalize($root) . '/modules/' . $name)) {
                $modules[] = $name;
            }
        }
        sort($modules, SORT_NATURAL | SORT_FLAG_CASE);
        return array_values(array_unique($modules));
    }

    /**
     * PHP-файлы, которые администратор видит в интерфейсе. Служебные class-файлы
     * и каталоги lib/docs самого модуля скрыты для читаемости списка; resolve()
     * всё равно независимо проверяет каждый вызов.
     *
     * @return string[] относительные пути от корня модуля
     */
    public static function moduleHandlers(string $root, string $module): array
    {
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,99}$/D', $module)) {
            return array();
        }
        $modulesRoot = realpath(self::normalize($root) . '/modules');
        $moduleDirectory = realpath(self::normalize($root) . '/modules/' . $module);
        if ($modulesRoot === false || $moduleDirectory === false) {
            return array();
        }
        $modulesRoot = self::normalize($modulesRoot);
        $moduleDirectory = self::normalize($moduleDirectory);
        if ($moduleDirectory === $modulesRoot || !str_starts_with($moduleDirectory, $modulesRoot . '/')) {
            return array();
        }
        $handlers = array();
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($moduleDirectory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->isLink() || !$file->isFile()) {
                continue;
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($moduleDirectory) + 1));
            if (!str_ends_with(strtolower($relative), '.php') || str_ends_with(strtolower($relative), '.class.php')) {
                continue;
            }
            $first = strtolower(explode('/', $relative)[0]);
            if (in_array($first, array('lib', 'docs', 'vendor', 'node_modules', '.git'), true)) {
                continue;
            }
            if (count($handlers) >= 200) {
                break;
            }
            $handlers[] = $relative;
        }
        sort($handlers, SORT_NATURAL | SORT_FLAG_CASE);
        return $handlers;
    }

    public static function available(string $root, string $module, string $handler): bool
    {
        try {
            self::resolve($root, $module, $handler);
            return true;
        } catch (\Throwable $error) {
            return false;
        }
    }

    /**
     * @throws \RuntimeException если модуль не установлен или файл отсутствует
     *         либо выходит за каталог модуля. Сообщения остаются общими для HTTP.
     */
    public static function resolve(string $root, string $module, string $handler): string
    {
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,99}$/D', $module)) {
            throw new \RuntimeException('Module is not available');
        }
        if (str_contains($handler, '\\')) {
            throw new \RuntimeException('Handler path is not valid');
        }
        $handler = trim($handler);
        if ($handler === '' || strlen($handler) > 255 || preg_match('/[\x00-\x1f\x7f:]/', $handler)) {
            throw new \RuntimeException('Handler path is not valid');
        }
        if (!preg_match('#^[A-Za-z0-9_][A-Za-z0-9_.-]*(?:/[A-Za-z0-9_][A-Za-z0-9_.-]*)*\.php$#D', $handler)) {
            throw new \RuntimeException('Handler path is not valid');
        }
        foreach (explode('/', $handler) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new \RuntimeException('Handler path leaves the module');
            }
        }
        if (!is_file(self::normalize($root) . '/cms/modules_installed/' . $module . '.installed')) {
            throw new \RuntimeException('Module is not installed');
        }

        $modulesRoot = realpath(self::normalize($root) . '/modules');
        $moduleDirectory = realpath(self::normalize($root) . '/modules/' . $module);
        $handlerPath = realpath(self::normalize($root) . '/modules/' . $module . '/' . $handler);
        if ($modulesRoot === false || $moduleDirectory === false || $handlerPath === false) {
            throw new \RuntimeException('Handler is not available');
        }
        $modulesRoot = self::normalize($modulesRoot);
        $moduleDirectory = self::normalize($moduleDirectory);
        $handlerPath = self::normalize($handlerPath);
        if (!str_starts_with($moduleDirectory, $modulesRoot . '/') || $moduleDirectory === $modulesRoot) {
            throw new \RuntimeException('Module is outside the modules directory');
        }
        if (!str_starts_with($handlerPath, $moduleDirectory . '/')) {
            throw new \RuntimeException('Handler is outside the module directory');
        }
        if (!is_file($handlerPath) || strtolower(pathinfo($handlerPath, PATHINFO_EXTENSION)) !== 'php') {
            throw new \RuntimeException('Handler is not a PHP file');
        }
        return $handlerPath;
    }

    private static function normalize(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }
}
