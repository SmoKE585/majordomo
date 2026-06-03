<?php

Define('ALLOW_RUNNING_WITH_ERRORS', 1);
chdir(dirname(__FILE__) . '/../../');

include_once("./config.php");
include_once("./lib/loader.php");
include_once("./lib/threads.php");

Define('WAIT_FOR_MAIN_CYCLE', 0);
set_time_limit(0);

include_once("./load_settings.php");
include_once(DIR_MODULES . "saverestore/saverestore.class.php");

$sv = new saverestore();

$with_extensions = gr('with_extensions');
$with_backup = gr('with_backup');
$backup = gr('backup');
$link = gr('link');

function saverestoreFrameParentStatus($message, $state = 'active', $percent = 35, $step = 'prepare')
{
    echo '<script language="javascript">';
    echo 'if (window.parent && window.parent.systemUpdateSetStatus) { window.parent.systemUpdateSetStatus(' . json_encode($message) . ', ' . json_encode($state) . ', ' . (int)$percent . ', ' . json_encode($step) . '); }';
    echo '</script>';
    echo str_repeat(' ', 4 * 1024);
    flush();
    @ob_flush();
}

function saverestoreFrameRedirect($message, $is_error = false, $url = '')
{
    $state = $is_error ? 'error' : 'success';
    if ($url == '') {
        $arg = $is_error ? 'err_msg' : 'ok_msg';
        $url = ROOTHTML . 'admin.php?md=panel&action=saverestore&' . $arg . '=' . urlencode($message);
    }
    echonow('<div><i style="font-size: 7pt;" class="glyphicon glyphicon-chevron-right"></i> ' . LANG_UPDATEBACKUP_GET_REDIRECT . '</div>');
    echonow('<script language="javascript">'
        . 'if (window.top && window.top.systemUpdateFinish) { window.top.systemUpdateFinish(' . json_encode($message) . ', ' . json_encode($state) . '); }'
        . 'if (window.top) { window.top.onbeforeunload = null; }'
        . 'window.top.location.href="' . $url . '";'
        . '</script>');
}

function saverestoreFrameFinishError($message)
{
    saverestoreFrameParentStatus($message, 'error', 100, 'finish');
    saverestoreFrameRedirect($message, true);
}

header('X-Accel-Buffering: no');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
echo "<html>";
echo "<head>";
echo '<link rel="stylesheet" href="/3rdparty/bootstrap/css/bootstrap.min.css" type="text/css"><script type="text/javascript" src="/3rdparty/bootstrap/js/bootstrap.min.js"></script>';
echo "</head>";
echo '<body style="height: auto;overflow: auto;padding: 10px;font-family: Consolas, Verdana;background: #000080;color: #c0c0c0;border-radius: 5px;">';
saverestoreFrameParentStatus('Операция запущена. Проверяю параметры...', 'active', 20, 'prepare');

$out = array();

if ($backup) {

    logAction('system_backup');
    saverestoreFrameParentStatus('Создание резервной копии...', 'active', 30, 'backup');
    $res = $sv->dump($out, 1);
    if ($res) {
        saverestoreFrameParentStatus('Очистка временных файлов...', 'active', 85, 'cleanup');
        echonow('<div><i style="font-size: 7pt;" class="glyphicon glyphicon-chevron-right"></i> ' . LANG_UPDATEBACKUP_DELETE_TEMP_FILES . '</div>');
        removeTree(ROOT . 'cms/saverestore/temp');
        echonow('<div><i style="font-size: 7pt;" class="glyphicon glyphicon-usd"></i> ' . LANG_UPDATEBACKUP_DONE . '</div>');
        echonow('<div><i style="font-size: 7pt;" class="glyphicon glyphicon-usd"></i> ' . LANG_UPDATEBACKUP_BACKUP_DONE . '</div>');
        sleep(1);
        echonow('<div><i style="font-size: 7pt;" class="glyphicon glyphicon-usd"></i> ' . LANG_UPDATEBACKUP_CLOSE_CONSOLE_AUTO . '</div>');
        sleep(1);
        sleep(2);
        saverestoreFrameRedirect(LANG_UPDATEBACKUP_BACKUP_DONE);
    } else {
        saverestoreFrameFinishError('Error creating backup');
    }

} else {

    $res = $sv->admin($out);
    saverestoreFrameParentStatus('Скачивание архива обновления...', 'active', 35, 'download');
    $res = $sv->getLatest($out, 1, $with_backup, $link);
    if ($res) {
        logAction('system_update');
        global $restore;
        $restore = 'master.tgz';
        $folder = '';
        saverestoreFrameParentStatus('Распаковка и применение обновления...', 'active', 62, 'apply');
        $res = $sv->upload($out, 1);
        if ($res) {
            saverestoreFrameParentStatus('Очистка временных файлов...', 'active', 86, 'cleanup');
            echonow('<div><i style="font-size: 7pt;" class="glyphicon glyphicon-chevron-right"></i> ' . LANG_UPDATEBACKUP_DELETE_TEMP_FILES . '</div>');
            removeTree(ROOT . 'cms/saverestore/temp');
            @unlink(ROOT . "cms/modules_installed/control_modules.installed");
            echonow('<div><i style="font-size: 7pt;" class="glyphicon glyphicon-usd"></i> ' . LANG_UPDATEBACKUP_DONE . '</div>');
            echonow('<div><i style="font-size: 7pt;" class="glyphicon glyphicon-usd"></i> ' . LANG_UPDATEBACKUP_UPDATE_GET_DONE . '</div>');
            if ($with_extensions) {
                saverestoreFrameParentStatus('Системное обновление применено. Перехожу к обновлению модулей...', 'active', 95, 'finish');
                echonow('<div><i style="font-size: 7pt;" class="glyphicon glyphicon-chevron-right"></i> ' . LANG_UPDATEBACKUP_GET_REDIRECT_TO_MERKET . '</div>');
                sleep(2);
                saverestoreFrameRedirect(LANG_UPDATEBACKUP_UPDATE_GET_DONE, false, ROOTHTML . 'admin.php?action=market&mode=iframe&mode2=update_all');
            } else {
                saverestoreFrameParentStatus('Запрашиваю перезагрузку системы...', 'active', 94, 'finish');
                echonow('<div><i style="font-size: 7pt;" class="glyphicon glyphicon-chevron-right"></i> ' . LANG_UPDATEBACKUP_REQUEST_REBOOT . '</div>');
                setRebootRequired('system_update_iframe');
                echonow('<div><i style="font-size: 7pt;" class="glyphicon glyphicon-usd"></i> ' . LANG_UPDATEBACKUP_REBOOT_WELL_DONE . '</div>');
                sleep(2);
                sleep(2);
                saverestoreFrameRedirect(LANG_UPDATEBACKUP_UPDATE_GET_DONE);
            }
        } else {
            saverestoreFrameFinishError('Error applying system update');
        }
    } else {
        saverestoreFrameFinishError(LANG_UPDATEBACKUP_ERROR_DOWNLOAD);
    }
}

echo "</body>";
echo "</html>";
