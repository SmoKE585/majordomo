<?php

use MajorDoMo\Core\Migrations;
use MajorDoMo\Core\Migrations\Migration;

return new class extends Migration {
    public function version(): string { return '20261021_core_runtime_v1'; }
    public function description(): string { return 'Базовая runtime-схема: очереди, InnoDB, индексы, очистка Connect'; }
    public function up(Migrations $migrations): void { $migrations->upgradeRuntime(); }
};
