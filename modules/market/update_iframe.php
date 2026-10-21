<?php

Define('ALLOW_RUNNING_WITH_ERRORS', 1);
chdir(dirname(__FILE__) . '/../../');

include_once("./config.php");
include_once("./lib/loader.php");
include_once("./lib/threads.php");

Define('WAIT_FOR_MAIN_CYCLE', 0);
set_time_limit(0);

include_once("./load_settings.php");
include_once(DIR_MODULES . "market/market.class.php");

$mkt = new market();
$mkt->category_id = 'all';

$_REQUEST['op'] = 'iframe';
$mode2 = gr('mode2');
$name = gr('name');
$names = gr('names');
$link = gr('link');
$url = trim((string)gr('url'));
$repo_url = trim((string)gr('repo_url'));
$version = trim((string)gr('version'));
$installed = gr('installed') ? 1 : 0;

function marketFrameFlush()
{
    flush();
    @ob_flush();
}

function marketFramePlainText($message)
{
    $text = html_entity_decode(strip_tags((string)$message), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', $text);
    return trim((string)$text);
}

function marketFrameEmit($type, $payload = array())
{
    $payload['type'] = $type;
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        $json = '{"type":"' . $type . '"}';
    }
    echo '<script>if(window.marketFrameScan){window.marketFrameScan();}if(window.marketFrameEmit){window.marketFrameEmit(' . $json . ');}</script>';
    marketFrameFlush();
}

function marketFrameParentStatus($message, $state = 'active', $percent = 35, $step = 'prepare')
{
    $jsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;
    echo '<script>';
    echo 'if (window.parent && window.parent.marketSetInstallStatus) { window.parent.marketSetInstallStatus(' . json_encode($message, $jsonFlags) . ', ' . json_encode($state, $jsonFlags) . ', ' . (int)$percent . ', ' . json_encode($step, $jsonFlags) . '); }';
    echo '</script>';
    marketFrameFlush();
}

function marketFrameLog($message, $kind = 'info')
{
    $text = marketFramePlainText($message);
    if ($text === '') {
        return;
    }
    marketFrameEmit('log', array('text' => $text, 'kind' => $kind));
}

function marketFrameSection($title, $description = '')
{
    marketFrameEmit('section', array(
        'title' => marketFramePlainText($title),
        'description' => marketFramePlainText($description),
    ));
}

function marketFrameSuccessText($message)
{
    $text = marketFramePlainText($message);
    $known = array(
        'Updates Installed!' => 'Обновления установлены.',
        'Nothing to update.' => 'Обновлений нет.',
        'Uninstalled' => 'Модуль удалён.',
    );
    if (isset($known[$text])) {
        return $known[$text];
    }
    if (preg_match("/^Plugin '(.+?)' \((.+?)\) installed\\.$/i", $text, $matches)) {
        return 'Модуль ' . $matches[1] . ' установлен.';
    }
    return $text;
}

function marketFrameRedirect($message, $is_error = false)
{
    $state = $is_error ? 'error' : 'success';
    $arg = $is_error ? 'err_msg' : 'ok_msg';
    $message = marketFramePlainText($message);
    if (!$is_error) {
        $message = marketFrameSuccessText($message);
    }
    if ($message === '') {
        $message = $is_error ? 'Операция не выполнена.' : 'Операция завершена.';
    }
    $url = ROOTHTML . 'admin.php?md=panel&action=market&' . $arg . '=' . urlencode($message);

    if ($is_error) {
        mdjFlashError($message);
    } else {
        marketFrameLog('Перенаправление в маркет…', 'muted');
    }

    $finish_json = json_encode(array(
        'source' => 'mdj-market',
        'type' => 'finish',
        'text' => $message,
        'state' => $state,
    ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($finish_json === false) {
        $finish_json = '{"source":"mdj-market","type":"finish","state":"' . $state . '"}';
    }

    echo '<script>';
    echo 'if (window.marketFrameFlush) { window.marketFrameFlush(); }';
    echo 'var marketFinishHandled = false;';
    echo 'if (window.parent && window.parent !== window && typeof window.parent.marketInstallEvent === "function") { window.parent.postMessage(' . $finish_json . ', window.location.origin || "*"); marketFinishHandled = true; }';
    echo 'else if (window.top && typeof window.top.marketFinishInstall === "function") { window.top.marketFinishInstall(' . json_encode($message) . ', ' . json_encode($state) . '); marketFinishHandled = true; }';
    echo 'if (window.top) { window.top.onbeforeunload = null; }';
    if ($is_error) {
        echo 'if (!marketFinishHandled && window.top) { window.top.location.href = ' . json_encode($url) . '; }';
    } else {
        echo 'setTimeout(function () { if (window.top) { window.top.location.href = ' . json_encode($url) . '; } }, 900);';
    }
    echo '</script>';
    marketFrameFlush();
}

function marketFrameFinish($mkt, $message, $is_error = false)
{
    $mkt->removeTree(ROOT . 'cms/saverestore/temp');
    marketFrameLog($message, $is_error ? 'error' : 'success');
    marketFrameRedirect($message, $is_error);
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
    <title>Установка модуля</title>
    <style>
        html, body { margin: 0; padding: 0; background: transparent; }
        #marketFrameStream { display: none; }
    </style>
</head>
<body>
<div id="marketFrameStream" aria-hidden="true">
<script>
    (function () {
        var stream = document.getElementById('marketFrameStream');
        if (!stream) {
            return;
        }

        var queue = [];
        var timer = null;
        var processed = new WeakSet();
        var scanTimer = null;

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
                        source: 'mdj-market',
                        type: 'events',
                        items: items
                    }, window.location.origin || '*');
                }
            } catch (error) {
                // The parent may be navigating away.
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

        window.marketFrameEmit = push;
        window.marketFrameScan = scanStream;
        window.marketFrameFlush = function () {
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

        new MutationObserver(scheduleScan).observe(stream, { childList: true, subtree: true });
    })();
</script>
<?php
marketFrameParentStatus('Операция запущена. Проверяю параметры...', 'active', 18, 'prepare');
marketFrameSection('Подготовка', 'Проверяю параметры операции и источник установки.');

$out = array();
$operation_handled = false;

if ($names != '') {
    $names = explode(',', $names);
}

if ($mode2 == 'uploaded' && $name != '') {
    $operation_handled = true;
    $out = array();
    $mkt->admin($out);
    $filename = ROOT . 'cms/saverestore/' . $name;
    if (file_exists($filename)) {
        marketFrameParentStatus("Проверка загруженного архива $name...", 'active', 28, 'prepare');
        logAction('market_install', $name);
        marketFrameSection('Установка', 'Распаковываю загруженный архив и применяю файлы модуля.');
        $mkt->echonow("Uploaded " . $name);
        $folder = str_replace('.tgz', '', $name);
        $restore = $name;
        $version = 'Unknown version';
        marketFrameParentStatus("Распаковка и установка файлов модуля $name...", 'active', 62, 'apply');
        $res = $mkt->upload($out, 1);
        if ($res) {
            marketFrameParentStatus("Очистка временных файлов...", 'active', 88, 'cleanup');
            marketFrameSection('Очистка', 'Удаляю временные файлы после установки.');
            $mkt->removeTree(ROOT . 'cms/saverestore/temp');
            marketFrameRedirect($res);
        } else {
            marketFrameFinish($mkt, "Не удалось установить загруженный архив $name", true);
        }
    } else {
        marketFrameFinish($mkt, "Загруженный архив не найден: $name", true);
    }
}

if ($mode2 == 'install' && $name != '') {
    $operation_handled = true;

    $out = array();
    if ($url != '') {
        $url = $mkt->normalizeCustomRepositoryUrl($url);
    }

    if ($link != '') {
        if (preg_match('/\/commit\/(.+?)$/', $link, $m)) {
            $commit = $m[1];
            $url = str_replace('/commit/', '/archive/', $link);
            $url .= '.tar.gz';
        }
    }

    if ($url == '') {
        $mkt->admin($out);
        $url = $mkt->url;
        if ($version == '') {
            $version = $mkt->version;
        }
    }

    if (!$url) {
        marketFrameFinish($mkt, "Не удалось получить ссылку на архив для $name", true);
        echo "</div></body></html>";
        exit;
    }

    if ($version == '') {
        $version = 'Unknown version';
    }

    marketFrameSection('Загрузка', 'Скачиваю архив модуля из выбранного источника.');
    marketFrameParentStatus("Скачивание архива модуля $name...", 'active', 38, 'download');
    $res = $mkt->getLatest($out, $url, $name, $version, 1);
    if ($res) {
        logAction('market_install', $name);
        $folder = $name;
        $restore = $name . '.tgz';
        marketFrameSection('Установка', 'Распаковываю архив и применяю файлы модуля.');
        marketFrameParentStatus("Распаковка и установка файлов модуля $name...", 'active', 64, 'apply');
        $res = $mkt->upload($out, 1);
        if ($res) {
            marketFrameParentStatus("Очистка временных файлов...", 'active', 88, 'cleanup');
            marketFrameSection('Очистка', 'Удаляю временные файлы после установки.');
            $mkt->removeTree(ROOT . 'cms/saverestore/temp');
            marketFrameRedirect($res);
        } else {
            marketFrameFinish($mkt, "Не удалось установить модуль $name", true);
        }
    } else {
        marketFrameFinish($mkt, "Не удалось скачать архив модуля $name", true);
    }
}

if ($mode2 == 'install_repository' && $repo_url != '') {
    $operation_handled = true;
    marketFrameParentStatus('Проверка ссылки на репозиторий...', 'active', 24, 'prepare');
    logAction('market_install', $repo_url);
    marketFrameSection('Загрузка', 'Скачиваю архив из указанного Git-репозитория.');
    marketFrameParentStatus('Скачивание архива репозитория...', 'active', 40, 'download');
    $res = $mkt->installFromRepositoryUrl($out, $repo_url, 1);
    if ($res) {
        marketFrameParentStatus('Очистка временных файлов...', 'active', 88, 'cleanup');
        marketFrameSection('Очистка', 'Удаляю временные файлы после установки.');
        $mkt->removeTree(ROOT . 'cms/saverestore/temp');
        marketFrameRedirect($res);
    } else {
        marketFrameFinish($mkt, "Не удалось установить пакет из репозитория", true);
    }
}

if ($mode2 == 'install_multiple' && $names != '') {
    $operation_handled = true;
    marketFrameParentStatus('Подготовка пакетного обновления модулей...', 'active', 24, 'prepare');
    logAction('market_update', implode(', ', $names));
    marketFrameSection('Обновление модулей', 'Скачиваю и устанавливаю выбранные модули.');
    $mkt->admin($out);
    marketFrameParentStatus('Скачивание и установка выбранных модулей...', 'active', 54, 'apply');
    $res = $mkt->updateAll($mkt->selected_plugins, 1);
    if ($res) {
        marketFrameParentStatus('Очистка временных файлов...', 'active', 86, 'cleanup');
        marketFrameSection('Очистка', 'Удаляю временные файлы после обновления.');
        $mkt->removeTree(ROOT . 'cms/saverestore/temp');
        $mkt->echonow("Rebooting system ... ");
        setRebootRequired('market_update_multiple');
        $mkt->echonow(" OK<br/> ", 'green');
        marketFrameRedirect($res);
    }
}

if ($mode2 == 'update_new') {
    $operation_handled = true;
    marketFrameParentStatus('Подготовка установки новых обновлений...', 'active', 24, 'prepare');
    logAction('market_update', 'Update new');
    marketFrameSection('Обновление модулей', 'Проверяю и устанавливаю доступные обновления.');
    $mkt->admin($out);
    if (count($mkt->can_be_updated_new) > 0) {
        marketFrameParentStatus('Скачивание и установка обновлений...', 'active', 54, 'apply');
        $res = $mkt->updateAll($mkt->can_be_updated_new, 1);
        if ($res) {
            marketFrameParentStatus('Очистка временных файлов...', 'active', 86, 'cleanup');
            marketFrameSection('Очистка', 'Удаляю временные файлы после обновления.');
            $mkt->removeTree(ROOT . 'cms/saverestore/temp');
            $mkt->echonow("Rebooting system ... ");
            setRebootRequired('market_update_new');
            $mkt->echonow(" OK<br/> ", 'green');
            marketFrameRedirect($res);
        }
    } else {
        $res = 'Nothing to update.';
        $mkt->echonow("Nothing to update ... ");
        marketFrameRedirect($res);
    }
}

if ($mode2 == 'update_all') {
    $operation_handled = true;
    marketFrameParentStatus('Подготовка полного обновления модулей...', 'active', 24, 'prepare');
    logAction('market_update', 'Update all');
    marketFrameSection('Обновление модулей', 'Обновляю все установленные модули.');
    $mkt->admin($out);
    marketFrameParentStatus('Скачивание и установка обновлений...', 'active', 54, 'apply');
    $res = $mkt->updateAll($mkt->can_be_updated, 1);
    if ($res) {
        marketFrameParentStatus('Очистка временных файлов...', 'active', 86, 'cleanup');
        marketFrameSection('Очистка', 'Удаляю временные файлы после обновления.');
        $mkt->removeTree(ROOT . 'cms/saverestore/temp');
        $mkt->echonow("Rebooting system ... ");
        setRebootRequired('market_update_all');
        $mkt->echonow(" OK<br/> ", 'green');
        marketFrameRedirect($res);
    }
}

if ($mode2 == 'uninstall' && $name != '') {
    $operation_handled = true;
    marketFrameSection('Удаление', 'Удаляю файлы модуля и связанные записи.');
    marketFrameParentStatus("Удаление модуля $name...", 'active', 58, 'apply');
    logAction('market_uninstall', $name);
    $res = $mkt->uninstallPlugin($name, 1);
    if ($res) {
        marketFrameRedirect($res);
    }
}

if ($mode2 == '') {
    marketFrameFinish($mkt, 'Операция не указана', true);
} elseif (!in_array($mode2, array('uploaded', 'install', 'install_repository', 'install_multiple', 'update_new', 'update_all', 'uninstall'))) {
    marketFrameFinish($mkt, "Неизвестная операция: $mode2", true);
} elseif (!$operation_handled) {
    marketFrameFinish($mkt, "Не хватает параметров для операции: $mode2", true);
}
?>
</div>
</body>
</html>
