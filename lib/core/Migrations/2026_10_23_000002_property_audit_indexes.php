<?php

use MajorDoMo\Core\Migrations;
use MajorDoMo\Core\Migrations\Migration;

/**
 * Страховка от повторения инцидента с полным сканом журнала свойств:
 * `SELECT ... FROM property_audit_events WHERE RUN_ID=?` не мог использовать
 * индекс и при большом журнале выполнялся секундами. Поиск запуска теперь идёт
 * по TRACE_ID, и этот индекс должен существовать на любой установке, включая
 * те, где таблица была создана до появления индекса.
 */
return new class extends Migration {
    public function version(): string { return '20261023_property_audit_indexes'; }
    public function description(): string { return 'Индексы журнала свойств для поиска запуска'; }

    public function up(Migrations $migrations): void
    {
        $migrations->ensureIndex('property_audit_events', 'idx_property_audit_events_trace', 'TRACE_ID,ID');
        $migrations->ensureIndex('property_audit_events', 'idx_property_audit_events_property', 'PROPERTY_ID,OBJECT_ID,ADDED');
        $migrations->ensureIndex('property_audit_events', 'idx_property_audit_events_added', 'ADDED');
    }
};
