<?php

use MajorDoMo\Core\Migrations;
use MajorDoMo\Core\Migrations\Migration;

/** 2026-10-25 — задания ядра переносятся в SQL до запуска новых исполнителей. */
return new class extends Migration {
    public function version(): string { return '20261025_durable_runtime'; }
    public function description(): string { return 'Долговечная очередь ядра и перенос прежних заданий'; }
    public function up(Migrations $migrations): void { $migrations->ensureRuntimeQueueSchema(); }
};
