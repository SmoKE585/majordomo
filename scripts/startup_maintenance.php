<?php

// 2026-11-11 — совместимый вход в обслуживание; тяжёлые операции больше не выполняются внутри main.
if (!empty($run_from_start)) return;

chdir(dirname(__DIR__));
require_once './config.php';
require_once './lib/loader.php';
require_once './load_settings.php';

$result = (new \MajorDoMo\Core\Maintenance\AutomaticMaintenance(ROOT))->runDue(force: true);
foreach ($result['tasks'] as $task => $state) {
    DebMes('Обслуживание ' . $task . ': ' . $state['status'] . (isset($state['error']) ? ' — ' . $state['error'] : ''),
        'maintenance', $state['status'] === 'failed' ? 'error' : '');
}
