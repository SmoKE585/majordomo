<?php
// 2026-10-22 — фирменные логотипы для обеих тем и новый favicon.
if (!isset($session, $csrf) || !class_exists('MDJUI')) { http_response_code(404); exit; }
$theme = ($_COOKIE['theme'] ?? '') === 'dark' ? 'dark' : 'light';
?>
<!doctype html>
<html lang="ru" data-bs-theme="<?= $theme ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Вход — MajorDoMo</title>
    <link rel="icon" href="<?= MDJUI::e($rootUrl) ?>favicon.ico?v=2026-10-22-logo" sizes="any">
    <link rel="apple-touch-icon" href="<?= MDJUI::e($rootUrl) ?>assets/img/logos/favicon.png">
    <link rel="stylesheet" href="<?= MDJUI::e($rootUrl) ?>assets/admin/admin.css?v=2026-10-22-nav-profile">
    <link rel="stylesheet" href="<?= MDJUI::e($rootUrl) ?>assets/auth/login.css?v=2026-10-22-nav-profile">
</head>
<body class="md-admin-shell md-auth">
<main class="md-auth__main">
    <div class="md-auth__brand">
        <?= MDJUI::logo(array('root' => $rootUrl)) ?>
    </div>
    <h1 class="md-auth__title">Вход в панель управления</h1>
    <p class="md-auth__subtitle">Войдите с учётной записью администратора.</p>
    <?php ob_start(); ?>
    <form method="post" action="<?= MDJUI::e(\MajorDoMo\Core\Auth\AuthGate::loginUrl()) ?>" class="md-ui-form-stack">
        <?php if ($error !== ''): ?>
            <?= MDJUI::hint(array('tone' => 'danger', 'title' => 'Не удалось войти', 'text' => $error, 'attrs' => array('role' => 'alert'))) ?>
        <?php endif; ?>
        <input type="hidden" name="_md_csrf" value="<?= MDJUI::e($csrf) ?>">
        <?= MDJUI::input(array('id' => 'login', 'name' => 'username', 'label' => 'Логин', 'value' => $username, 'autocomplete' => 'username', 'required' => true, 'size' => 'lg', 'attrs' => array('maxlength' => 255, 'autofocus' => true, 'autocapitalize' => 'none', 'spellcheck' => 'false'))) ?>
        <div x-data="{ visible: false }">
            <?= MDJUI::input(array('id' => 'password', 'type' => 'password', 'name' => 'password', 'label' => 'Пароль', 'autocomplete' => 'current-password', 'required' => true, 'size' => 'lg', 'attrs' => array(':type' => "visible ? 'text' : 'password'"))) ?>
            <label class="md-check md-auth__reveal"><input class="md-check__input" type="checkbox" x-model="visible"><span class="md-check__box" aria-hidden="true"></span><span class="md-check__text">Показать пароль</span></label>
        </div>
        <?= MDJUI::button('Войти', array('type' => 'submit', 'icon' => 'login', 'size' => 'lg', 'class' => 'md-auth__submit')) ?>
    </form>
    <?php $form = ob_get_clean(); echo MDJUI::card(array(), $form); ?>
</main>
<script src="<?= MDJUI::e($rootUrl) ?>assets/admin/admin.js?v=2026-10-22-auth" defer></script>
</body>
</html>
