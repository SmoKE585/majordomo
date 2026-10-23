<?php
// 2026-10-23 — существующие webhook остаются модульными; добавлены сценарии и PHP-код.

use MajorDoMo\Core\Api\Schema;
use MajorDoMo\Core\Migrations;
use MajorDoMo\Core\Migrations\Migration;

return new class extends Migration {
    public function version(): string { return '20261027_api_hook_handlers'; }
    public function description(): string { return 'Webhook для сценариев и собственного PHP-кода'; }
    public function up(Migrations $migrations): void { Schema::upgradeHookHandlers($migrations->database()); }
};
