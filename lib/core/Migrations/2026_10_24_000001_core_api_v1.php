<?php

use MajorDoMo\Core\Api\Schema;
use MajorDoMo\Core\Migrations;
use MajorDoMo\Core\Migrations\Migration;

return new class extends Migration {
    public function version(): string { return '20261024_core_api_v1'; }
    public function description(): string { return 'API-токены, webhook-схема и InnoDB для locations'; }
    public function up(Migrations $migrations): void
    {
        $db = $migrations->database();
        Schema::install($db);
        $table = $db->one("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='locations'");
        if ($table && strcasecmp((string)$table['ENGINE'], 'InnoDB') !== 0) {
            $db->requireExec('ALTER TABLE locations ENGINE=InnoDB');
        }
    }
};
