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
    flush();
    @ob_flush();
}

/**
 * Converts a legacy echonow()/HTML message into a plain text log line.
 */
function saverestoreFramePlainText($message)
{
    $text = html_entity_decode(strip_tags((string)$message), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', $text);
    return trim((string)$text);
}

/**
 * Queues one structured event for the parent modal. The worker page itself
 * stays invisible; the parent renders the progress UI with the md-* kit.
 */
function saverestoreFrameEmit($type, $payload = array())
{
    $payload['type'] = $type;
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        $json = '{"type":"' . $type . '"}';
    }
    echo '<script>if(window.mdjSaverestoreScan){window.mdjSaverestoreScan();}if(window.mdjSaverestoreEmit){window.mdjSaverestoreEmit(' . $json . ');}</script>';
    saverestoreFrameFlush();
}

function saverestoreFrameParentStatus($message, $state = 'active', $percent = 35, $step = 'prepare')
{
    echo '<script>';
    $jsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;
    echo 'if (window.parent && window.parent.systemUpdateSetStatus) { window.parent.systemUpdateSetStatus(' . json_encode($message, $jsonFlags) . ', ' . json_encode($state, $jsonFlags) . ', ' . (int)$percent . ', ' . json_encode($step, $jsonFlags) . '); }';
    echo '</script>';
    saverestoreFrameFlush();
}

function saverestoreFrameLog($message, $kind = 'info')
{
    $text = saverestoreFramePlainText($message);
    if ($text === '') {
        return;
    }
    saverestoreFrameEmit('log', array('text' => $text, 'kind' => $kind));
}

function saverestoreFrameSection($title, $description = '')
{
    saverestoreFrameEmit('section', array(
        'title' => saverestoreFramePlainText($title),
        'description' => saverestoreFramePlainText($description),
    ));
}

function saverestoreFrameChecklist($title, $checks)
{
    $has_errors = false;
    $items = array();
    foreach ($checks as $check) {
        $ok = !empty($check['ok']);
        $fatal = $check['fatal'] ?? true;
        if (!$ok && $fatal) {
            $has_errors = true;
        }
        $items[] = array(
            'title' => saverestoreFramePlainText(isset($check['title']) ? $check['title'] : ''),
            'details' => saverestoreFramePlainText(isset($check['details']) ? $check['details'] : ''),
            'ok' => $ok,
            'fatal' => (bool)$fatal,
        );
    }
    saverestoreFrameEmit('checks', array(
        'title' => saverestoreFramePlainText($title),
        'checks' => $items,
    ));
    return !$has_errors;
}

function saverestoreFrameRedirect($message, $is_error = false, $url = '')
{
    $state = $is_error ? 'error' : 'success';
    if ($url == '') {
        $url = ROOTHTML . 'admin.php?md=panel&action=saverestore';
    }
    if ($is_error) {
        mdjFlashError($message);
    } else {
        mdjFlashSuccess($message);
        saverestoreFrameLog(LANG_UPDATEBACKUP_GET_REDIRECT, 'muted');
    }

    $finish_json = json_encode(array(
        'source' => 'mdj-saverestore',
        'type' => 'finish',
        'text' => saverestoreFramePlainText($message),
        'state' => $state,
    ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($finish_json === false) {
        $finish_json = '{"source":"mdj-saverestore","type":"finish","state":"' . $state . '"}';
    }

    echo '<script>';
    echo 'if (window.mdjSaverestoreFlush) { window.mdjSaverestoreFlush(); }';
    echo 'var mdjFinishHandled = false;';
    echo 'if (window.parent && window.parent !== window && typeof window.parent.systemUpdateEvent === "function") { window.parent.postMessage(' . $finish_json . ', window.location.origin || "*"); mdjFinishHandled = true; }';
    echo 'else if (window.top && typeof window.top.systemUpdateFinish === "function") { window.top.systemUpdateFinish(' . json_encode(saverestoreFramePlainText($message)) . ', ' . json_encode($state) . '); mdjFinishHandled = true; }';
    echo 'if (window.top) { window.top.onbeforeunload = null; }';
    if ($is_error) {
        // Keep the modal open so the user can read the journal. Only fall back
        // to navigation when the parent has no bridge at all.
        echo 'if (!mdjFinishHandled && window.top) { window.top.location.href = ' . json_encode($url) . '; }';
    } else {
        // A short pause lets the parent render the 100% state before reload.
        echo 'setTimeout(function () { if (window.top) { window.top.location.href = ' . json_encode($url) . '; } }, 900);';
    }
    echo '</script>';
    saverestoreFrameFlush();
}

function saverestoreFrameFinishError($message)
{
    // The parent keeps the last active step/percent and marks that step as
    // failed, so an early error does not look like a completed operation.
    saverestoreFrameLog($message, 'error');
    saverestoreFrameRedirect($message, true);
}

function saverestoreFrameBuildUpdateChecks($sv)
{
    $checks = array(
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
            'details' => function_exists('exec') ? 'Функция exec() доступна' : 'Функция exec() отключена',
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
            'title' => 'Каталог cms/cycles',
            'details' => 'Хранит состояние циклов и запрос паузы на время обновления',
            'ok' => $sv->canWritePath(DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/cycles')
        ),
        array(
            'title' => 'Каталог cms/deploy',
            'details' => 'Хранит состояние последнего деплоя и кэш Composer',
            'ok' => $sv->canWritePath(DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/deploy')
        ),
        array(
            'title' => 'Резервная копия базы',
            'details' => 'database_backup должен быть доступен для записи',
            'ok' => $sv->canWritePath(DOC_ROOT . DIRECTORY_SEPARATOR . 'database_backup')
        ),
    );

    try {
        $deployment = new \MajorDoMo\Core\Deploy\Deployment(DOC_ROOT);
        foreach ($deployment->preflight() as $check) {
            $checks[] = array(
                'title' => 'Деплой: ' . $check['title'],
                'details' => $check['details'],
                'ok' => $check['ok'],
                'fatal' => !empty($check['fatal']),
            );
        }
    } catch (\Throwable $error) {
        $checks[] = array(
            'title' => 'Деплой: проверка окружения',
            'details' => $error->getMessage(),
            'ok' => false,
        );
    }

    return $checks;
}

function saverestoreFrameBuildBackupChecks($sv)
{
    return array(
        array(
            'title' => 'Выполнение системных команд',
            'details' => 'Нужно для сборки архива',
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
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="robots" content="noindex, nofollow">
    <title>Выполнение операции</title>
    <style>
        html, body { margin: 0; padding: 0; background: transparent; }
        #srFrameStream { display: none; }
    </style>
</head>
<body>
<div id="srFrameStream" aria-hidden="true">
<script>
    (function () {
        var stream = document.getElementById('srFrameStream');
        if (!stream) {
            return;
        }

        var queue = [];
        var timer = null;

        function send() {
            timer = null;
            if (!queue.length) {
                return;
            }
            var items = queue.slice();
            queue = [];
            try {
                if (window.parent && window.parent !== window) {
                    window.parent.postMessage({
                        source: 'mdj-saverestore',
                        type: 'events',
                        items: items
                    }, window.location.origin || '*');
                }
            } catch (error) {
                // The parent may be navigating away; nothing to recover here.
            }
        }

        function schedule() {
            if (!timer) {
                timer = window.setTimeout(send, 30);
            }
        }

        function push(event) {
            if (!event || typeof event !== 'object') {
                return;
            }
            queue.push(event);
            if (queue.length >= 80) {
                send();
            } else {
                schedule();
            }
        }

        window.mdjSaverestoreEmit = push;
        window.mdjSaverestoreScan = scanStream;
        window.mdjSaverestoreFlush = function () {
            if (scanTimer) {
                window.clearTimeout(scanTimer);
                scanTimer = null;
            }
            scanStream();
            if (timer) {
                window.clearTimeout(timer);
                timer = null;
            }
            send();
        };

        function normalizeText(value) {
            return String(value || '')
                .replace(/\u00a0/g, ' ')
                .replace(/\s+/g, ' ')
                .trim();
        }

        function addLog(value, kind) {
            var text = normalizeText(value);
            if (!text) {
                return;
            }
            if (text.length > 4000) {
                text = text.slice(0, 3997) + '…';
            }
            push({ type: 'log', text: text, kind: kind || 'info' });
        }

        function kindFor(element) {
            var node = element;
            while (node && node !== stream) {
                if (node.nodeType === 1) {
                    var className = node.className || '';
                    if (typeof className === 'string') {
                        if (/(^|\s)(text-danger|text-red|sr-error)(\s|$)/.test(className)) {
                            return 'error';
                        }
                        if (/(^|\s)(text-success|text-green|sr-success)(\s|$)/.test(className)) {
                            return 'success';
                        }
                        if (/(^|\s)(text-warning|text-amber|sr-warning)(\s|$)/.test(className)) {
                            return 'warning';
                        }
                        if (/(^|\s)(text-muted|sr-muted)(\s|$)/.test(className)) {
                            return 'muted';
                        }
                    }
                    var color = node.getAttribute ? (node.getAttribute('color') || '') : '';
                    if (color === 'red') {
                        return 'error';
                    }
                    if (color === 'green') {
                        return 'success';
                    }
                    if (color === 'orange' || color === 'amber') {
                        return 'warning';
                    }
                }
                node = node.parentNode;
            }
            return 'info';
        }

        var processed = new WeakSet();
        var scanTimer = null;

        /**
         * Legacy echonow() output is parsed by the browser piece by piece.
         * Scanning the stable top-level children once avoids logging the same
         * line for the element, its wrapper and its text node.
         */
        function scanStream() {
            scanTimer = null;
            Array.prototype.forEach.call(stream.childNodes, function (node) {
                if (processed.has(node)) {
                    return;
                }
                if (node.nodeType === 3) {
                    var text = normalizeText(node.nodeValue);
                    if (text) {
                        addLog(text, 'info');
                    }
                    processed.add(node);
                    return;
                }
                if (node.nodeType !== 1) {
                    return;
                }
                if (node.tagName === 'SCRIPT' || node.tagName === 'STYLE' || node.tagName === 'LINK') {
                    processed.add(node);
                    return;
                }
                var elementText = normalizeText(node.textContent);
                if (!elementText) {
                    return;
                }
                addLog(elementText, kindFor(node));
                processed.add(node);
            });
        }

        function scheduleScan() {
            if (!scanTimer) {
                scanTimer = window.setTimeout(scanStream, 40);
            }
        }

        var observer = new MutationObserver(scheduleScan);
        observer.observe(stream, { childList: true, subtree: true });
    })();
</script>
<?php
saverestoreFrameParentStatus('Операция запущена. Проверяю параметры...', 'active', 20, 'prepare');
$out = array();

if ($backup) {
    $backup_checks = saverestoreFrameBuildBackupChecks($sv);
    $backup_ok = saverestoreFrameChecklist('Проверка перед созданием резервной копии', $backup_checks);
    if (!$backup_ok) {
        saverestoreFrameFinishError('Не пройдена предварительная проверка перед созданием резервной копии');
        echo '</body></html>';
        return;
    }
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
        saverestoreFrameFinishError('Не удалось создать резервную копию');
    }
} else {
    $update_checks = saverestoreFrameBuildUpdateChecks($sv);
    $update_ok = saverestoreFrameChecklist('Проверка перед обновлением системы', $update_checks);
    if (!$update_ok) {
        saverestoreFrameFinishError('Не пройдена предварительная проверка перед обновлением');
        echo '</body></html>';
        return;
    }
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
            if (function_exists('processSubscriptionsSafe')) {
                try {
                    if (!processSubscriptionsSafe('SYSTEM_UPDATED')) saverestoreFrameLog('Не удалось поставить уведомление об обновлении в очередь.', 'warning');
                } catch (Throwable $failure) {
                    saverestoreFrameLog('Обновление применено, но очередь уведомлений недоступна.', 'warning');
                    error_log('MajorDoMo SYSTEM_UPDATED: ' . get_class($failure));
                }
            }
            saverestoreFrameLog(LANG_UPDATEBACKUP_UPDATE_GET_DONE, 'success');
            if ($with_extensions) {
                saverestoreFrameParentStatus('Системное обновление применено. Перехожу к обновлению модулей...', 'active', 95, 'finish');
                saverestoreFrameLog(LANG_UPDATEBACKUP_GET_REDIRECT_TO_MERKET, 'muted');
                sleep(2);
                saverestoreFrameRedirect(LANG_UPDATEBACKUP_UPDATE_GET_DONE, false, ROOTHTML . 'admin.php?action=market&mode=iframe&mode2=update_all');
            } else {
                saverestoreFrameParentStatus('Запрашиваю перезагрузку системы...', 'active', 94, 'finish');
                saverestoreFrameLog(LANG_UPDATEBACKUP_REQUEST_REBOOT, 'muted');
                // Перезапуск службы выполняет Deployment после успешных
                // composer/npm/миграций; отдельный reboot-флаг здесь не нужен.
                saverestoreFrameLog(LANG_UPDATEBACKUP_REBOOT_WELL_DONE, 'success');
                sleep(2);
                saverestoreFrameRedirect(LANG_UPDATEBACKUP_UPDATE_GET_DONE);
            }
        } else {
            saverestoreFrameFinishError('Не удалось применить системное обновление');
        }
    } else {
        saverestoreFrameFinishError(LANG_UPDATEBACKUP_ERROR_DOWNLOAD);
    }
}
?>
</div>
</body>
</html>
