<?php
// 2026-10-25 — метаданные уведомлений; старые записи и вызовы сохраняются.

use MajorDoMo\Core\Migrations;
use MajorDoMo\Core\Migrations\Migration;

return new class extends Migration {
    public function version(): string { return '20261025_module_notifications'; }
    public function description(): string { return 'Подробности и стабильные ключи уведомлений модулей'; }
    public function up(Migrations $migrations): void
    {
        $db = $migrations->database();
        $db->requireExec("CREATE TABLE IF NOT EXISTS module_notifications (
            ID int unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
            MODULE_NAME char(50) NOT NULL DEFAULT '', MESSAGE varchar(255) NOT NULL DEFAULT '',
            TYPE char(20) NOT NULL DEFAULT 'info', IS_READ int NOT NULL DEFAULT 0, ADDED datetime DEFAULT NULL,
            NOTIFICATION_KEY varchar(190) NOT NULL DEFAULT '', PAYLOAD text DEFAULT NULL,
            KEY idx_notifications_unread (IS_READ, MODULE_NAME)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
        $columns = array_column($db->select('SHOW COLUMNS FROM module_notifications'), 'Field');
        if (!in_array('NOTIFICATION_KEY', $columns, true)) $db->requireExec("ALTER TABLE module_notifications ADD NOTIFICATION_KEY varchar(190) NOT NULL DEFAULT ''");
        if (!in_array('PAYLOAD', $columns, true)) $db->requireExec('ALTER TABLE module_notifications ADD PAYLOAD text DEFAULT NULL');
        $migrations->ensureIndex('module_notifications', 'idx_notifications_unread', 'IS_READ,MODULE_NAME');
    }
};
