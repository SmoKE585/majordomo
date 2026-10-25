<?php
// 2026-10-25 — обновление схемы установленного Zigbee до запуска циклов.

use MajorDoMo\Core\Migrations;
use MajorDoMo\Core\Migrations\Migration;

return new class extends Migration {
    public function version(): string { return '20261025_zigbee_delivery'; }
    public function description(): string { return 'Подготовка схемы и индекса исходящих привязок Zigbee'; }
    public function up(Migrations $migrations): void
    {
        $file = dirname(__DIR__, 3) . '/modules/zigbee/Schema.php';
        if (!is_file($file) || !$migrations->database()->tableExists('zigbee_devices')) return;
        require_once $file;
        \MajorDoMo\Modules\Zigbee\Schema::upgrade($migrations->database());
    }
};
