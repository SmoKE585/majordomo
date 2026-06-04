<?php

Define('ALLOW_RUNNING_WITH_ERRORS', 1);
chdir(dirname(__FILE__) . '/../../');

include_once('./config.php');
include_once('./lib/loader.php');
include_once('./lib/threads.php');

Define('WAIT_FOR_MAIN_CYCLE', 0);
set_time_limit(0);

include_once('./load_settings.php');
include_once(DIR_MODULES . 'saverestore/saverestore.class.php');

$sv = new saverestore();

$with_extensions = gr('with_extensions');
$with_backup = gr('with_backup');
$backup = gr('backup');

function saverestoreFrameFlush()
{
    echo '<!-- sr-frame-flush -->';
    flush();
    @ob_flush();
}

function saverestoreFrameParentStatus($message, $state = 'active', $percent = 35, $step = 'prepare')
{
    echo '<script>';
    echo 'if (window.parent && window.parent.systemUpdateSetStatus) { window.parent.systemUpdateSetStatus(' . json_encode($message) . ', ' . json_encode($state) . ', ' . (int)$percent . ', ' . json_encode($step) . '); }';
    echo '</script>';
    saverestoreFrameFlush();
}

function saverestoreFrameLog($message, $kind = 'info')
{
    echo '<div class="sr-frame-log sr-frame-log--' . htmlspecialchars($kind) . '">' . $message . '</div>';
    saverestoreFrameFlush();
}

function saverestoreFrameSection($title, $description = '')
{
    echo '<section class="sr-frame-section">';
    echo '<div class="sr-frame-section__title">' . htmlspecialchars($title) . '</div>';
    if ($description !== '') {
        echo '<div class="sr-frame-section__description">' . htmlspecialchars($description) . '</div>';
    }
    echo '</section>';
    saverestoreFrameFlush();
}

function saverestoreFrameChecklist($title, $checks)
{
    $has_errors = false;
    echo '<section class="sr-frame-checks">';
    echo '<div class="sr-frame-section__title">' . htmlspecialchars($title) . '</div>';
    echo '<div class="sr-frame-checklist">';
    foreach ($checks as $check) {
        $ok = !empty($check['ok']);
        if (!$ok) {
            $has_errors = true;
        }
        echo '<div class="sr-frame-check sr-frame-check--' . ($ok ? 'ok' : 'fail') . '">';
        echo '<span class="sr-frame-check__icon">' . ($ok ? '&#10003;' : '&#10005;') . '</span>';
        echo '<div class="sr-frame-check__content">';
        echo '<div class="sr-frame-check__title">' . htmlspecialchars($check['title']) . '</div>';
        if (!empty($check['details'])) {
            echo '<div class="sr-frame-check__details">' . htmlspecialchars($check['details']) . '</div>';
        }
        echo '</div>';
        echo '</div>';
    }
    echo '</div>';
    echo '</section>';
    saverestoreFrameFlush();
    return !$has_errors;
}

function saverestoreFrameRedirect($message, $is_error = false, $url = '')
{
    $state = $is_error ? 'error' : 'success';
    if ($url == '') {
        $arg = $is_error ? 'err_msg' : 'ok_msg';
        $url = ROOTHTML . 'admin.php?md=panel&action=saverestore&' . $arg . '=' . urlencode($message);
    }
    saverestoreFrameLog(LANG_UPDATEBACKUP_GET_REDIRECT, 'muted');
    echo '<script>'
        . 'if (window.top && window.top.systemUpdateFinish) { window.top.systemUpdateFinish(' . json_encode($message) . ', ' . json_encode($state) . '); }'
        . 'if (window.top) { window.top.onbeforeunload = null; window.top.location.href = ' . json_encode($url) . '; }'
        . '</script>';
    saverestoreFrameFlush();
}

function saverestoreFrameFinishError($message)
{
    saverestoreFrameParentStatus($message, 'error', 100, 'finish');
    saverestoreFrameLog(htmlspecialchars($message), 'error');
    saverestoreFrameRedirect($message, true);
}

function saverestoreFrameBuildUpdateChecks($sv)
{
    return array(
        array(
            'title' => 'URL архива обновления',
            'details' => $sv->getUpdateURL() != '' ? $sv->getUpdateURL() : 'Не задан',
            'ok' => $sv->getUpdateURL() != ''
        ),
        array(
            'title' => 'Расширение cURL',
            'details' => function_exists('curl_init') ? 'Доступно' : 'Отсутствует',
            'ok' => function_exists('curl_init')
        ),
        array(
            'title' => 'Выполнение системных команд',
            'details' => function_exists('exec') ? 'exec() доступна' : 'exec() отключена',
            'ok' => function_exists('exec')
        ),
        array(
            'title' => 'Команда tar',
            'details' => $sv->isTarAvailable() ? 'Доступна' : 'Не найдена в системе',
            'ok' => $sv->isTarAvailable()
        ),
        array(
            'title' => 'Каталог cms/saverestore',
            'details' => 'Должен быть доступен для записи',
            'ok' => $sv->canWritePath(DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/saverestore')
        ),
        array(
            'title' => 'Временный каталог',
            'details' => 'cms/saverestore/temp должен создаваться и очищаться',
            'ok' => $sv->canWritePath(DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/saverestore/temp')
        ),
        array(
            'title' => 'Служебные маркеры модулей',
            'details' => 'cms/modules_installed должен быть доступен для записи',
            'ok' => $sv->canWritePath(DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/modules_installed')
        ),
        array(
            'title' => 'Резервная копия базы',
            'details' => 'database_backup должен быть доступен для записи',
            'ok' => $sv->canWritePath(DOC_ROOT . DIRECTORY_SEPARATOR . 'database_backup')
        ),
    );
}

function saverestoreFrameBuildBackupChecks($sv)
{
    return array(
        array(
            'title' => 'Выполнение системных команд',
            'details' => 'Нужно для упаковки архива',
            'ok' => function_exists('exec')
        ),
        array(
            'title' => 'Команда tar',
            'details' => $sv->isTarAvailable() ? 'Доступна' : 'Не найдена в системе',
            'ok' => $sv->isTarAvailable()
        ),
        array(
            'title' => 'Каталог cms/saverestore',
            'details' => 'Нужно сохранить итоговый архив и служебные файлы',
            'ok' => $sv->canWritePath(DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/saverestore')
        ),
        array(
            'title' => 'Временный каталог',
            'details' => 'Нужно собрать содержимое будущего бэкапа',
            'ok' => $sv->canWritePath(DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/saverestore/temp')
        )
    );
}

header('X-Accel-Buffering: no');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
?>
<html>
<head>
    <meta charset="utf-8">
    <link rel="stylesheet" href="/3rdparty/bootstrap/css/bootstrap.min.css" type="text/css">
    <style>
        :root {
            --sr-bg: #0f1724;
            --sr-surface: rgba(20, 32, 51, .94);
            --sr-surface-soft: rgba(71, 146, 209, .08);
            --sr-border: rgba(148, 163, 184, .18);
            --sr-text: #e6edf5;
            --sr-muted: #9db0c3;
            --sr-accent: #6ab1df;
            --sr-success: #4ec08a;
            --sr-danger: #ef6b73;
            --sr-warning: #f3c96b;
        }

        * { box-sizing: border-box; }
        html, body { margin: 0; min-height: 100%; }
        body {
            padding: 14px;
            background:
                radial-gradient(circle at top right, rgba(71, 146, 209, .22), transparent 18rem),
                linear-gradient(180deg, #101826 0%, var(--sr-bg) 100%);
            color: var(--sr-text);
            font: 14px/1.6 Consolas, "SFMono-Regular", Menlo, Monaco, monospace;
        }

        .sr-frame-shell {
            display: grid;
            gap: 12px;
        }

        .sr-frame-hero,
        .sr-frame-section,
        .sr-frame-checks,
        .sr-frame-log {
            border: 1px solid var(--sr-border);
            border-radius: 16px;
            background: var(--sr-surface);
            box-shadow: 0 18px 40px rgba(0, 0, 0, .18);
        }

        .sr-frame-hero,
        .sr-frame-section,
        .sr-frame-checks {
            padding: 16px 18px;
        }

        .sr-frame-hero__eyebrow {
            color: var(--sr-accent);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
        }

        .sr-frame-hero__title {
            margin: 6px 0 0;
            font-size: 20px;
            font-weight: 700;
        }

        .sr-frame-hero__text,
        .sr-frame-section__description,
        .sr-frame-check__details {
            margin-top: 6px;
            color: var(--sr-muted);
        }

        .sr-frame-section__title,
        .sr-frame-check__title {
            font-weight: 700;
        }

        .sr-frame-checklist {
            display: grid;
            gap: 10px;
            margin-top: 12px;
        }

        .sr-frame-check {
            display: flex;
            gap: 12px;
            padding: 12px 14px;
            border-radius: 14px;
            background: var(--sr-surface-soft);
            border: 1px solid transparent;
        }

        .sr-frame-check--ok {
            border-color: rgba(78, 192, 138, .22);
        }

        .sr-frame-check--fail {
            border-color: rgba(239, 107, 115, .24);
            background: rgba(239, 107, 115, .08);
        }

        .sr-frame-check__icon {
            width: 22px;
            flex: 0 0 22px;
            font-weight: 700;
            text-align: center;
        }

        .sr-frame-check--ok .sr-frame-check__icon { color: var(--sr-success); }
        .sr-frame-check--fail .sr-frame-check__icon { color: var(--sr-danger); }

        .sr-frame-log {
            padding: 10px 14px;
            color: var(--sr-text);
        }

        .sr-frame-log--success { border-color: rgba(78, 192, 138, .24); background: rgba(78, 192, 138, .10); }
        .sr-frame-log--error { border-color: rgba(239, 107, 115, .28); background: rgba(239, 107, 115, .12); }
        .sr-frame-log--warning { border-color: rgba(243, 201, 107, .24); background: rgba(243, 201, 107, .10); }
        .sr-frame-log--muted { color: var(--sr-muted); }

        .sr-frame-stream {
            display: grid;
            gap: 8px;
            padding-bottom: 12px;
        }

        .sr-frame-stream > div,
        .sr-frame-stream > font {
            display: block;
            margin: 0;
        }

        .sr-frame-stream > div,
        .sr-frame-stream > font > div {
            padding: 10px 14px;
            border-radius: 12px;
            border: 1px solid rgba(148, 163, 184, .12);
            background: rgba(255, 255, 255, .04);
        }

        .sr-frame-stream > font[color="green"] > div {
            color: #b9f5d0;
            border-color: rgba(78, 192, 138, .22);
            background: rgba(78, 192, 138, .10);
        }

        .sr-frame-stream > font[color="red"] > div {
            color: #ffc4c8;
            border-color: rgba(239, 107, 115, .28);
            background: rgba(239, 107, 115, .12);
        }
    </style>
    <script>
        (function () {
            function scrollToBottom() {
                window.scrollTo({
                    top: document.documentElement.scrollHeight || document.body.scrollHeight,
                    behavior: 'auto'
                });
            }

            document.addEventListener('DOMContentLoaded', function () {
                var stream = document.getElementById('srFrameStream');
                if (!stream || typeof MutationObserver === 'undefined') {
                    scrollToBottom();
                    return;
                }

                var observer = new MutationObserver(function () {
                    scrollToBottom();
                });

                observer.observe(stream, {
                    childList: true,
                    subtree: true,
                    characterData: true
                });

                scrollToBottom();
            });
        })();
    </script>
</head>
<body>
<div class="sr-frame-shell">
    <section class="sr-frame-hero">
        <div class="sr-frame-hero__eyebrow"><?php echo $backup ? 'Backup job' : 'System update job'; ?></div>
        <h1 class="sr-frame-hero__title"><?php echo $backup ? 'Создание резервной копии' : 'Обновление системы'; ?></h1>
        <div class="sr-frame-hero__text"><?php echo $backup ? 'Перед упаковкой проверяем рабочие каталоги и возможность собрать архив.' : 'Перед скачиванием и применением обновления выполняется preflight-проверка окружения, прав записи и служебных каталогов.'; ?></div>
    </section>
<?php
saverestoreFrameParentStatus('Операция запущена. Проверяю параметры...', 'active', 20, 'prepare');

$out = array();

if ($backup) {
    $backup_checks = saverestoreFrameBuildBackupChecks($sv);
    $backup_ok = saverestoreFrameChecklist('Preflight: создание резервной копии', $backup_checks);
    if (!$backup_ok) {
        saverestoreFrameFinishError('Не пройдена предварительная проверка перед созданием резервной копии');
        echo '</div></body></html>';
        return;
    }

    echo '<div id="srFrameStream" class="sr-frame-stream">';
    saverestoreFrameFlush();
    logAction('system_backup');
    saverestoreFrameSection('Запуск', 'Проверки пройдены, начинаю формирование архива.');
    saverestoreFrameParentStatus('Создание резервной копии...', 'active', 30, 'backup');
    $res = $sv->dump($out, 1);
    if ($res) {
        saverestoreFrameParentStatus('Очистка временных файлов...', 'active', 85, 'cleanup');
        saverestoreFrameLog(LANG_UPDATEBACKUP_DELETE_TEMP_FILES, 'muted');
        removeTree(ROOT . 'cms/saverestore/temp');
        saverestoreFrameLog(LANG_UPDATEBACKUP_DONE, 'success');
        saverestoreFrameLog(LANG_UPDATEBACKUP_BACKUP_DONE, 'success');
        saverestoreFrameLog(LANG_UPDATEBACKUP_CLOSE_CONSOLE_AUTO, 'muted');
        sleep(2);
        saverestoreFrameRedirect(LANG_UPDATEBACKUP_BACKUP_DONE);
    } else {
        saverestoreFrameFinishError('Error creating backup');
    }
    echo '</div>';
} else {
    $update_checks = saverestoreFrameBuildUpdateChecks($sv);
    $update_ok = saverestoreFrameChecklist('Preflight: обновление системы', $update_checks);
    if (!$update_ok) {
        saverestoreFrameFinishError('Не пройдена предварительная проверка перед обновлением');
        echo '</div></body></html>';
        return;
    }

    echo '<div id="srFrameStream" class="sr-frame-stream">';
    saverestoreFrameFlush();
    saverestoreFrameSection('Подготовка', 'Базовые проверки пройдены. Дальше будет обязательный бэкап базы, скачивание архива и точная проверка прав на файлы после распаковки.');
    $res = $sv->admin($out);
    saverestoreFrameParentStatus('Скачивание архива обновления...', 'active', 35, 'download');
    $res = $sv->getLatest($out, 1, $with_backup);
    if ($res) {
        logAction('system_update');
        global $restore;
        $restore = 'master.tgz';
        $folder = '';
        saverestoreFrameParentStatus('Распаковка и применение обновления...', 'active', 62, 'apply');
        $res = $sv->upload($out, 1);
        if ($res) {
            saverestoreFrameParentStatus('Очистка временных файлов...', 'active', 86, 'cleanup');
            saverestoreFrameLog(LANG_UPDATEBACKUP_DELETE_TEMP_FILES, 'muted');
            removeTree(ROOT . 'cms/saverestore/temp');
            @unlink(ROOT . 'cms/modules_installed/control_modules.installed');
            saverestoreFrameLog(LANG_UPDATEBACKUP_DONE, 'success');
            saverestoreFrameLog(LANG_UPDATEBACKUP_UPDATE_GET_DONE, 'success');
            if ($with_extensions) {
                saverestoreFrameParentStatus('Системное обновление применено. Перехожу к обновлению модулей...', 'active', 95, 'finish');
                saverestoreFrameLog(LANG_UPDATEBACKUP_GET_REDIRECT_TO_MERKET, 'muted');
                sleep(2);
                saverestoreFrameRedirect(LANG_UPDATEBACKUP_UPDATE_GET_DONE, false, ROOTHTML . 'admin.php?action=market&mode=iframe&mode2=update_all');
            } else {
                saverestoreFrameParentStatus('Запрашиваю перезагрузку системы...', 'active', 94, 'finish');
                saverestoreFrameLog(LANG_UPDATEBACKUP_REQUEST_REBOOT, 'muted');
                setRebootRequired('system_update_iframe');
                saverestoreFrameLog(LANG_UPDATEBACKUP_REBOOT_WELL_DONE, 'success');
                sleep(2);
                saverestoreFrameRedirect(LANG_UPDATEBACKUP_UPDATE_GET_DONE);
            }
        } else {
            saverestoreFrameFinishError('Error applying system update');
        }
    } else {
        saverestoreFrameFinishError(LANG_UPDATEBACKUP_ERROR_DOWNLOAD);
    }
    echo '</div>';
}
?>
</div>
</body>
</html>
