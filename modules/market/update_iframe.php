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
$version = trim((string)gr('version'));

function marketFrameFinish($mkt, $message, $is_error = false)
{
    $mkt->removeTree(ROOT . 'cms/saverestore/temp');
    $mkt->echonow($message . "<br/>", $is_error ? 'red' : 'green');
    marketFrameRedirect($mkt, $message, $is_error);
}

function marketFrameParentStatus($message, $state = 'active')
{
    echo '<script language="javascript">';
    echo 'if (window.parent && window.parent.marketSetInstallStatus) { window.parent.marketSetInstallStatus(' . json_encode($message) . ', ' . json_encode($state) . '); }';
    echo '</script>';
    echo str_repeat(' ', 4 * 1024);
    flush();
    @ob_flush();
}

function marketFrameRedirect($mkt, $message, $is_error = false)
{
    $arg = $is_error ? 'err_msg' : 'ok_msg';
    $state = $is_error ? 'error' : 'success';
    $mkt->echonow("Redirecting to main page...");
    $mkt->echonow('<script language="javascript">'
        . 'if (window.top && window.top.marketFinishInstall) { window.top.marketFinishInstall(' . json_encode($message) . ', ' . json_encode($state) . '); }'
        . 'if (window.top) { window.top.onbeforeunload = null; }'
        . 'window.top.location.href="' . ROOTHTML . 'admin.php?md=panel&action=market&' . $arg . '=' . urlencode($message) . '";'
        . '</script>');
}

header('X-Accel-Buffering: no');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
echo "<html>";
echo "<head>";
echo '<link rel="stylesheet" href="/3rdparty/bootstrap/css/bootstrap.min.css" type="text/css"><script type="text/javascript" src="/3rdparty/bootstrap/js/bootstrap.min.js"></script>';
echo "</head>";
echo '<body style="height: auto;overflow: auto;padding: 10px;font-family: Consolas, Verdana;background: #000080;color: #c0c0c0;border-radius: 5px;">';
marketFrameParentStatus('Операция запущена. Идет подготовка...', 'active');

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
        logAction('market_install', $name);
        $mkt->echonow("Uploaded " . $name);
        $folder = str_replace('.tgz', '', $name);
        $restore = $name;
        $version = 'Unknown version';
        $res = $mkt->upload($out, 1);
        if ($res) {
            $mkt->removeTree(ROOT . 'cms/saverestore/temp');
            marketFrameRedirect($mkt, $res);
        } else {
            marketFrameFinish($mkt, "Error installing uploaded file $name", true);
        }
    } else {
        marketFrameFinish($mkt, "Uploaded file not found: $name", true);
    }
}

if ($mode2 == 'install' && $name != '') {
    $operation_handled = true;
    // install/update one extension

    $out = array();
    if ($url != '') {
        $url = $mkt->normalizeCustomRepositoryUrl($url);
    }

    if ($link != '') {
        if (preg_match('/\/commit\/(.+?)$/', $link, $m)) {
            $commit = $m[1];
            $url = str_replace('/commit/','/archive/',$link);
            $url.='.tar.gz';
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
        marketFrameFinish($mkt, "Error getting download URL for $name", true);
        echo "</body>";
        echo "</html>";
        exit;
    }

    if ($version == '') {
        $version = 'Unknown version';
    }

    marketFrameParentStatus("Скачивание архива модуля $name...", 'active');
    $res = $mkt->getLatest($out, $url, $name, $version, 1);
    if ($res) {
        logAction('market_install', $name);
        $folder = $name;
        $restore = $name . '.tgz';
        marketFrameParentStatus("Распаковка и установка файлов модуля $name...", 'active');
        $res = $mkt->upload($out, 1);
        if ($res) {
            $mkt->removeTree(ROOT . 'cms/saverestore/temp');
            marketFrameRedirect($mkt, $res);
        } else {
            marketFrameFinish($mkt, "Error installing $name", true);
        }
    } else {
        marketFrameFinish($mkt, "Error downloading $name", true);
    }
}

if ($mode2 == 'install_multiple' && $names != '') {
    $operation_handled = true;
    // install/update multiple extensions
    logAction('market_update', implode(', ', $names));
    $mkt->admin($out);
    $res = $mkt->updateAll($mkt->selected_plugins, 1);
    if ($res) {
        $mkt->removeTree(ROOT . 'cms/saverestore/temp');
        $mkt->echonow("Rebooting system ... ");
        setRebootRequired('market_update_multiple');
        $mkt->echonow(" OK<br/> ", 'green');
        marketFrameRedirect($mkt, $res);
    }
}


if ($mode2 == 'update_new') {
    $operation_handled = true;
    logAction('market_update', 'Update new');
    $mkt->admin($out);
    if (count($mkt->can_be_updated_new) > 0) {
        $res = $mkt->updateAll($mkt->can_be_updated_new, 1);
        if ($res) {
            $mkt->removeTree(ROOT . 'cms/saverestore/temp');
            $mkt->echonow("Rebooting system ... ");
            setRebootRequired('market_update_new');
            $mkt->echonow(" OK<br/> ", 'green');
            marketFrameRedirect($mkt, $res);
        }
    } else {
        $res = 'Nothing to update.';
        $mkt->echonow("Nothing to update ... ");
        marketFrameRedirect($mkt, $res);
    }
}

if ($mode2 == 'update_all') {
    $operation_handled = true;
    // update all extensions
    logAction('market_update', 'Update all');
    $mkt->admin($out);
    $res = $mkt->updateAll($mkt->can_be_updated, 1);
    if ($res) {
        $mkt->removeTree(ROOT . 'cms/saverestore/temp');
        $mkt->echonow("Rebooting system ... ");
        setRebootRequired('market_update_all');
        $mkt->echonow(" OK<br/> ", 'green');
        marketFrameRedirect($mkt, $res);
    }
}

if ($mode2 == 'uninstall' && $name != '') {
    $operation_handled = true;
    // remove one extension
    logAction('market_uninstall', $name);
    $res = $mkt->uninstallPlugin($name, 1);
    if ($res) {
        marketFrameRedirect($mkt, $res);
    }
}

if ($mode2 == '') {
    marketFrameFinish($mkt, 'No market operation specified', true);
} elseif (!in_array($mode2, array('uploaded', 'install', 'install_multiple', 'update_new', 'update_all', 'uninstall'))) {
    marketFrameFinish($mkt, "Unknown market operation: $mode2", true);
} elseif (!$operation_handled) {
    marketFrameFinish($mkt, "Missing parameters for market operation: $mode2", true);
}

echo "</body>";
echo "</html>";
