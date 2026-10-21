<?php

/**
 * Shared 404 renderer.
 *
 * It intentionally does not touch the database: a missing page should look
 * normal even when the database or the application bootstrap is broken.
 *
 * @package MajorDoMo
 */

/**
 * Detect clients that expect JSON instead of HTML.
 *
 * @return bool
 */
function mdj_error_wants_json()
{
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        return true;
    }
    if (isset($_GET['part_load']) || isset($_GET['ajax']) || isset($_GET['ajax_panel'])) {
        return true;
    }
    if (isset($_SERVER['HTTP_ACCEPT'])) {
        $accept = strtolower((string)$_SERVER['HTTP_ACCEPT']);
        return strpos($accept, 'application/json') !== false && strpos($accept, 'text/html') === false;
    }

    return false;
}

/**
 * Current theme from the admin cookie.
 *
 * @return string light|dark
 */
function mdj_error_theme()
{
    if (isset($_COOKIE['theme']) && $_COOKIE['theme'] === 'dark') {
        return 'dark';
    }

    return 'light';
}

/**
 * Render a styled 404 page and stop.
 *
 * @param array{root?:string,area?:string,path?:string,code?:int,message?:string} $options
 * @return void
 */
function mdj_render_404($options = array())
{
    $root = isset($options['root']) ? (string)$options['root'] : (defined('ROOTHTML') ? ROOTHTML : '/');
    $area = isset($options['area']) ? (string)$options['area'] : 'site';
    $path = isset($options['path']) ? (string)$options['path'] : (isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '');
    $code = isset($options['code']) ? (int)$options['code'] : 404;
    $message = isset($options['message']) ? (string)$options['message'] : 'Такой страницы нет или она была удалена.';

    if (!headers_sent()) {
        http_response_code($code);
    }

    if (mdj_error_wants_json()) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(array(
            'ok' => false,
            'error' => 'not_found',
            'message' => $message,
            'path' => $path,
        ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
    }

    $projectRoot = defined('ROOT') ? ROOT : dirname(__DIR__) . DIRECTORY_SEPARATOR;
    if (is_file($projectRoot . 'lib/ui/MDJUI.php')) {
        require_once($projectRoot . 'lib/ui/MDJUI.php');
    }

    $cssFile = $projectRoot . 'assets/admin/admin.css';
    $cssVersion = is_file($cssFile) ? (string)filemtime($cssFile) : '1';
    $theme = mdj_error_theme();
    $homeUrl = $area === 'admin' ? rtrim($root, '/') . '/panel/' : rtrim($root, '/') . '/';
    $safePath = htmlspecialchars(mb_substr($path, 0, 240), ENT_QUOTES, 'UTF-8');
    $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    $safeRoot = htmlspecialchars(rtrim($root, '/') . '/', ENT_QUOTES, 'UTF-8');

    $content = '';
    if (class_exists('MDJUI')) {
        $content = MDJUI::emptyState(array(
            'icon' => 'compass',
            'title' => 'Страница не найдена',
            'text' => $message,
            'action' => MDJUI::button('На главную', array('href' => $homeUrl, 'icon' => 'home'))
                . MDJUI::button('Открыть админку', array('href' => rtrim($root, '/') . '/panel/', 'variant' => 'secondary', 'icon' => 'layout-dashboard')),
            'class' => 'md-error-state',
        ));
    } else {
        $content = '<div class="md-empty md-error-state">'
            . '<div class="md-empty__title">Страница не найдена</div>'
            . '<p class="md-empty__text">' . $safeMessage . '</p>'
            . '<div class="md-empty__actions"><a class="md-btn md-btn--primary" href="' . htmlspecialchars($homeUrl, ENT_QUOTES, 'UTF-8') . '">На главную</a></div>'
            . '</div>';
    }
    ?>
<!DOCTYPE html>
<html lang="ru" data-bs-theme="<?= $theme === 'dark' ? 'dark' : 'light' ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>404 — Страница не найдена</title>
    <link rel="stylesheet" href="<?= $safeRoot ?>assets/admin/admin.css?v=<?= htmlspecialchars($cssVersion, ENT_QUOTES, 'UTF-8') ?>">
</head>
<body class="md-admin-shell md-error-page">
    <main class="md-error-page__main">
        <div class="md-error-page__card">
            <div class="md-error-page__code" aria-hidden="true">404</div>
            <?= $content ?>
            <div class="md-error-page__path" title="<?= $safePath ?>"><?= $safePath ?></div>
            <div class="md-error-page__actions">
                <button type="button" class="md-btn md-btn--ghost" onclick="history.length > 1 ? history.back() : location.assign('<?= htmlspecialchars($homeUrl, ENT_QUOTES, 'UTF-8') ?>')">Назад</button>
            </div>
        </div>
    </main>
</body>
</html>
    <?php
    exit;
}
