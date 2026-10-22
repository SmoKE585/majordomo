<?php

use MajorDoMo\Core\Migrations;
use MajorDoMo\Core\Migrations\Migration;

return new class extends Migration {
    public function version(): string { return '20261023_property_audit_v1'; }
    public function description(): string { return 'Журнал изменений свойств property_audit_runs/events'; }
    public function up(Migrations $migrations): void { $migrations->ensurePropertyAuditSchema(); }
};
