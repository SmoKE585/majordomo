<?php

use MajorDoMo\Core\Migrations;
use MajorDoMo\Core\Migrations\Migration;

/** 2026-10-25 — задания сохраняют цепочку и поколение восстанавливаемого индекса Redis. */
return new class extends Migration {
    public function version(): string { return '20261025_scheduler_trace'; }
    public function description(): string { return 'Цепочки таймеров и индекс планировщика Redis'; }
    public function up(Migrations $migrations): void { $migrations->ensureScheduledJobTraceSchema(); }
};
