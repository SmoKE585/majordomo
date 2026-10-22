<?php

use MajorDoMo\Core\Migrations;
use MajorDoMo\Core\Migrations\Migration;

return new class extends Migration {
    public function version(): string { return '20261022_system_events_v1'; }
    public function description(): string { return 'Журнал системных событий system_events'; }
    public function up(Migrations $migrations): void { $migrations->ensureSystemEventsSchema(); }
};
