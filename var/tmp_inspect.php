<?php
$env = parse_ini_file(__DIR__ . '/../.env');
$pdo = new PDO('mysql:host=' . $env['DB_HOST'] . ';dbname=' . $env['DB_NAME'] . ';charset=utf8mb4', $env['DB_USER'], $env['DB_PASSWORD']);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
foreach (['properties','pvalues'] as $t) {
    echo "== $t ==\n";
    foreach ($pdo->query("SHOW COLUMNS FROM `$t`")->fetchAll(PDO::FETCH_ASSOC) as $c) {
        echo str_pad($c['Field'], 25), $c['Type'], ' ', $c['Key'], "\n";
    }
}
echo "\n== pvalues (linked) ==\n";
foreach ($pdo->query("SELECT * FROM pvalues WHERE LINKED_MODULES IS NOT NULL AND LINKED_MODULES<>''")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo json_encode($r, JSON_UNESCAPED_UNICODE), "\n";
}
