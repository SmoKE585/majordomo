<?php

namespace MajorDoMo\Core\Deploy;

use MajorDoMo\Core\Config;

/**
 * Выбор CLI-бинарника PHP.
 *
 * В веб-запросе PHP_BINARY указывает на php-fpm, а не на CLI. Запускать
 * `php-fpm -r` нельзя: он печатает usage и ломает health-check/миграции.
 * Поэтому берём явный PATH_TO_PHP, иначе ищем обычный php в PHP_BINDIR.
 *
 * 2026-10-23 — добавлено после ошибки с php-fpm вместо CLI.
 */
final class PhpBinary
{
    public static function cli(): string
    {
        $configured = trim(Config::string('PATH_TO_PHP', ''));
        if ($configured !== '' && Config::source('PATH_TO_PHP') !== 'computed' && self::usable($configured)) {
            return $configured;
        }

        $candidates = array();
        if (DIRECTORY_SEPARATOR === '\\') {
            $candidates[] = PHP_BINARY;
            if (defined('PHP_BINDIR')) $candidates[] = PHP_BINDIR . DIRECTORY_SEPARATOR . 'php.exe';
        } else {
            $bindir = defined('PHP_BINDIR') ? PHP_BINDIR : '/usr/bin';
            // Сначала CLI той же версии, что и текущий PHP-FPM, иначе
            // /usr/bin/php может указывать на другую версию из FastPanel.
            $candidates[] = $bindir . '/php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
            $candidates[] = $bindir . '/php';
            $candidates[] = '/usr/bin/php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
            $candidates[] = '/usr/bin/php';
            $candidates[] = '/usr/local/bin/php';
        }
        foreach ($candidates as $candidate) {
            if (self::usable($candidate)) return $candidate;
        }
        return PHP_BINARY;
    }

    public static function usable(string $path): bool
    {
        if ($path === '' || str_contains($path, "\0")) return false;
        if (!is_file($path)) return false;
        if (is_executable($path)) return true;
        return DIRECTORY_SEPARATOR === '\\' && (bool)preg_match('/\.exe$/i', $path);
    }
}
