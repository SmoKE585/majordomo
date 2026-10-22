<?php
$rootUrl = defined('ROOTHTML') ? rtrim((string)ROOTHTML, '/') . '/' : '/';
$message = (string)($maintenance['message'] ?? 'Сайт временно на обслуживании.');
$retryAfter = max(1, (int)($maintenance['retry_after'] ?? 120));
$failed = !empty($maintenance['failed']);
$startedAt = (int)($maintenance['started_at'] ?? 0);
$e = static fn($value) => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $failed ? 'Обновление прервано' : 'Технические работы' ?> — MajorDoMo</title>
    <link rel="stylesheet" href="<?= $e($rootUrl) ?>assets/css/maintenance.css">
</head>
<body class="md-maintenance<?= $failed ? ' md-maintenance--failed' : '' ?>">
    <main class="md-maintenance__card" role="main">
        <div class="md-maintenance__mark" aria-hidden="true">
            <?= $failed ? '!' : '···' ?>
        </div>
        <p class="md-maintenance__eyebrow">MajorDoMo</p>
        <h1 class="md-maintenance__title"><?= $failed ? 'Обновление прервано' : 'Технические работы' ?></h1>
        <p class="md-maintenance__text"><?= $e($message) ?></p>
        <p class="md-maintenance__hint">
            Страница временно недоступна. Попробуйте обновить её через
            <strong><?= $retryAfter ?> сек.</strong>
        </p>
        <?php if ($startedAt > 0): ?>
            <p class="md-maintenance__meta">Начало работ: <?= $e(date('d.m.Y H:i', $startedAt)) ?></p>
        <?php endif; ?>
        <p class="md-maintenance__meta">
            Администратору доступна <a href="<?= $e($rootUrl) ?>panel/">панель управления</a>.
        </p>
    </main>
</body>
</html>
