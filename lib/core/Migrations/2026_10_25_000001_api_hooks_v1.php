<?php

use MajorDoMo\Core\Api\Schema;
use MajorDoMo\Core\Migrations;
use MajorDoMo\Core\Migrations\Migration;

return new class extends Migration {
    public function version(): string { return '20261025_api_hooks_v1'; }
    public function description(): string { return 'Публичные webhook-входы api_hooks'; }
    public function up(Migrations $migrations): void { Schema::installHooks($migrations->database()); }
};
