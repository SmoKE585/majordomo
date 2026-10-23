<?php
// 2026-10-23 — временные пользовательские токены хранятся отдельно от ключей интеграций.

use MajorDoMo\Core\Api\Schema;
use MajorDoMo\Core\Migrations;
use MajorDoMo\Core\Migrations\Migration;

return new class extends Migration {
    public function version(): string { return '20261026_api_user_sessions'; }
    public function description(): string { return 'Пользовательские сеансы API'; }
    public function up(Migrations $migrations): void { $migrations->database()->requireExec(Schema::userSessionsSql()); }
};
