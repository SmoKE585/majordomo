<?php

namespace MajorDoMo\Core;

/**
 * Защита базы от случайного удаления в отладочном окружении.
 *
 * В docker-контейнере база нужна для длительной отладки: её данные и структура
 * должны переживать перезапуски, а эксперименты с ядром не должны приводить к
 * потере стенда. Поэтому при `DB_PROTECT_DROP=1` приложение отказывается
 * выполнять `DROP DATABASE` и его синоним `DROP SCHEMA`.
 *
 * Запрет действует только на удаление всей базы. `DROP TABLE`, `ALTER TABLE` и
 * миграции продолжают работать: без них нельзя проверить обновление схемы.
 * Проверка стоит в общем слое БД, поэтому её видят и старые модули через
 * SQLExec, и новый код через Core\Database.
 *
 * 2026-10-26 — добавлено для docker-стенда отладки.
 */
final class DatabaseGuard
{
    /** Включена ли защита. По умолчанию выключена: боевые установки её не наследуют. */
    public static function enabled(): bool
    {
        return Config::bool('DB_PROTECT_DROP', false);
    }

    /**
     * Бросает исключение, если запрос удаляет базу, а защита включена.
     *
     * Исключение не зависит от $ignore_errors: destructive-запрос нельзя
     * «проглотить» и продолжить работу как будто ничего не случилось.
     */
    public static function assertAllowed(string $sql): void
    {
        if (!self::enabled() || !self::isDestructive($sql)) {
            return;
        }
        throw new \RuntimeException(
            'Удаление базы запрещено: включена защита DB_PROTECT_DROP=1 (режим отладки). '
            . 'Чтобы пересоздать стенд, удалите базу вне приложения — например, том контейнера '
            . '(docker compose down -v).'
        );
    }

    /**
     * Возвращает true для DROP DATABASE / DROP SCHEMA в исполняемой части SQL.
     *
     * SQL разбирается вручную, а не регуляркой по всей строке: строковые
     * литералы и комментарии могут содержать этот текст как данные и не должны
     * блокировать обычную запись. Versioned-комментарии MySQL (начинаются с
     * `/*!`) считаются исполняемыми, поэтому их содержимое тоже проверяется.
     */
    public static function isDestructive(string $sql): bool
    {
        $length = strlen($sql);
        $words = array();

        for ($i = 0; $i < $length;) {
            $char = $sql[$i];

            if (ctype_space($char)) {
                $i++;
                continue;
            }

            // Строчный комментарий MySQL: # ... или -- ... до конца строки.
            if ($char === '#' || ($char === '-' && ($sql[$i + 1] ?? '') === '-')) {
                $end = strpos($sql, "\n", $i);
                if ($end === false) {
                    break;
                }
                $i = $end + 1;
                continue;
            }

            if ($char === '/' && ($sql[$i + 1] ?? '') === '*') {
                // /*! ... */ — versioned comment, MySQL исполняет его содержимое.
                if (($sql[$i + 2] ?? '') === '!') {
                    $i += 3;
                    continue;
                }
                $end = strpos($sql, '*/', $i + 2);
                if ($end === false) {
                    break;
                }
                $i = $end + 2;
                continue;
            }

            // Строковые литералы и quoted identifiers пропускаем целиком.
            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                $i++;
                while ($i < $length) {
                    if ($sql[$i] === '\\' && $quote !== '`') {
                        $i += 2;
                        continue;
                    }
                    if ($sql[$i] === $quote) {
                        // Удвоенная кавычка — экранирование внутри литерала.
                        if (($sql[$i + 1] ?? '') === $quote) {
                            $i += 2;
                            continue;
                        }
                        $i++;
                        break;
                    }
                    $i++;
                }
                continue;
            }

            if (preg_match('/\G[A-Za-z_][A-Za-z0-9_$]*/', $sql, $match, 0, $i)) {
                $words[] = strtoupper($match[0]);
                $i += strlen($match[0]);
                continue;
            }

            $i++;
        }

        $total = count($words);
        for ($i = 0; $i < $total - 1; $i++) {
            if ($words[$i] === 'DROP' && ($words[$i + 1] === 'DATABASE' || $words[$i + 1] === 'SCHEMA')) {
                return true;
            }
        }
        return false;
    }
}
