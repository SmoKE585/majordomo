<?php

// 2026-11-11 — прежние backup-циклы используют общее расписание и блокировку,
// чтобы не создавать параллельные дампы и не задерживать основную автоматику.
$maintenanceCycleName = basename($_SERVER['SCRIPT_FILENAME'], '.php');
require __DIR__ . '/cycle_maintenance.php';
