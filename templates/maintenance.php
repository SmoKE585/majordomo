<?php
// 2026-10-23 — страница обслуживания в стиле входа: логотип, карточка MDJUI,
// та же ширина и типографика, что у /admin.php?auth=login.
if (!isset($maintenance) || !is_array($maintenance)) {
    http_response_code(503);
    exit;
}
$theme = ($_COOKIE['theme'] ?? '') === 'dark' ? 'dark' : 'light';
$rootUrl = defined('ROOTHTML') ? rtrim((string)ROOTHTML, '/') . '/' : '/';
$message = (string)($maintenance['message'] ?? 'Сайт временно на обслуживании.');
$retryAfter = max(1, (int)($maintenance['retry_after'] ?? 120));
$failed = !empty($maintenance['failed']);
$startedAt = (int)($maintenance['started_at'] ?? 0);
$retryUrl = (string)($_SERVER['REQUEST_URI'] ?? $rootUrl);
$e = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="ru" data-bs-theme="<?= $theme ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $failed ? 'Обновление прервано' : 'Технические работы' ?> — MajorDoMo</title>
    <link rel="icon" href="<?= $e($rootUrl) ?>favicon.ico" sizes="any">
    <link rel="apple-touch-icon" href="<?= $e($rootUrl) ?>assets/img/logos/favicon.png">
    <link rel="stylesheet" href="<?= $e($rootUrl) ?>assets/admin/admin.css">
    <link rel="stylesheet" href="<?= $e($rootUrl) ?>assets/auth/login.css">
    <link rel="stylesheet" href="<?= $e($rootUrl) ?>assets/css/maintenance.css">
</head>
<body class="md-admin-shell md-auth md-maintenance<?= $failed ? ' md-maintenance--failed' : '' ?>">
<main class="md-auth__main">
    <div class="md-auth__brand">
        <?= MDJUI::logo(array('root' => $rootUrl)) ?>
    </div>
    <h1 class="md-auth__title"><?= $failed ? 'Обновление прервано' : 'Технические работы' ?></h1>
    <p class="md-auth__subtitle">
        <?= $failed
            ? 'Система временно недоступна. Администратор уже получил информацию об ошибке.'
            : 'Сайт обновляется. Обычно это занимает несколько минут.' ?>
    </p>
    <?php ob_start(); ?>
    <div class="md-ui-form-stack">
        <?= MDJUI::hint(array(
            'tone' => $failed ? 'danger' : 'warning',
            'title' => $failed ? 'Что случилось' : 'Идёт обновление',
            'text' => $message,
        )) ?>
        <p class="md-maintenance__meta">
            Попробуйте обновить страницу через <strong><?= $retryAfter ?> сек.</strong>
            <?php if ($startedAt > 0): ?>
                <br>Начало работ: <?= $e(date('d.m.Y H:i', $startedAt)) ?>
            <?php endif; ?>
        </p>
        <?= MDJUI::button('Обновить страницу', array(
            'href' => $retryUrl,
            'icon' => 'refresh',
            'variant' => $failed ? 'secondary' : 'primary',
            'size' => 'lg',
            'block' => true,
        )) ?>
        <p class="md-maintenance__meta md-maintenance__admin">
            Администратору доступна <a href="<?= $e($rootUrl) ?>panel/">панель управления</a>.
        </p>
    </div>
    <?php $body = ob_get_clean(); echo MDJUI::card(array(), $body); ?>
</main>
</body>
</html>
