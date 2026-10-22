<?php

namespace MajorDoMo\Core\Migrations;

use MajorDoMo\Core\Migrations;

/**
 * Одна версионированная миграция ядра.
 *
 * Файлы лежат в `lib/core/Migrations/` и возвращают анонимный класс-наследник.
 * Версия — стабильная строка в `core_migrations`; переименовывать её после
 * применения нельзя, иначе миграция выполнится повторно.
 *
 * 2026-10-22 — добавлено: файловые миграции вместо жёсткого списка в Migrations.
 */
abstract class Migration
{
    /** Имя файла, из которого загружена миграция. Заполняет Migrations::discover(). */
    public string $sourceFile = '';

    /** Стабильный идентификатор версии в таблице `core_migrations`. */
    abstract public function version(): string;

    /** Короткое человекочитаемое описание для CLI и журнала обновления. */
    public function description(): string
    {
        return '';
    }

    /**
     * Применить миграцию. Повторный запуск после ошибки должен быть безопасен:
     * DDL в MySQL не откатывается транзакцией.
     */
    abstract public function up(Migrations $migrations): void;
}
