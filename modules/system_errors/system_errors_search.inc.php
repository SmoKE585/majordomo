<?php
/*
* @version 0.2 (wizard)
*/

require_once(__DIR__ . '/system_errors_helpers.inc.php');

if ($this->owner->name == 'panel') {
    $out['CONTROLPANEL'] = 1;
}

$res = SQLSelect("SELECT * FROM system_errors ORDER BY ACTIVE DESC, LATEST_UPDATE DESC, CODE");
$total = is_array($res) ? count($res) : 0;

$history_counts = array();
$history_rows = SQLSelect("SELECT ERROR_ID, COUNT(*) AS CNT, MAX(ADDED) AS LAST_ADDED FROM system_errors_data GROUP BY ERROR_ID");
if (is_array($history_rows)) {
    foreach ($history_rows as $history_row) {
        $history_counts[(int)$history_row['ERROR_ID']] = array(
            'CNT' => (int)$history_row['CNT'],
            'LAST_ADDED' => (string)$history_row['LAST_ADDED'],
        );
    }
}

$events_total = 0;
if (is_array($history_rows)) {
    foreach ($history_rows as $history_row) {
        $events_total += (int)$history_row['CNT'];
    }
}

$active_groups = 0;
$active_occurrences = 0;
$last_error_update = '';

for ($i = 0; $i < $total; $i++) {
    $active = (int)$res[$i]['ACTIVE'];
    $error_id = (int)$res[$i]['ID'];
    $history = isset($history_counts[$error_id]) ? $history_counts[$error_id] : array('CNT' => 0, 'LAST_ADDED' => '');

    if ($active > 0) {
        $active_groups++;
        $active_occurrences += $active;
    }

    $latest_update = (string)$res[$i]['LATEST_UPDATE'];
    $latest_update_formatted = md_system_errors_date($latest_update);
    if ($latest_update !== '' && ($last_error_update === '' || $latest_update > $last_error_update)) {
        $last_error_update = $latest_update;
    }

    $last_event_formatted = $history['LAST_ADDED'] !== '' ? md_system_errors_date($history['LAST_ADDED']) : '—';

    $title = trim((string)$res[$i]['TITLE']);
    $code = (string)$res[$i]['CODE'];
    $latest_comment = '';
    if ($error_id > 0) {
        $latest_event = SQLSelectOne("SELECT COMMENTS FROM system_errors_data WHERE ERROR_ID=" . $error_id . " ORDER BY ID DESC LIMIT 1");
        $latest_comment = isset($latest_event['COMMENTS']) ? (string)$latest_event['COMMENTS'] : '';
    }

    $display_title = md_system_errors_title($title, $code, $latest_comment);
    $frames = md_system_errors_frames($latest_comment);
    $origin = md_system_errors_origin($frames);
    $events_label = $history['CNT'] . ' ' . md_system_errors_plural($history['CNT'], 'событие', 'события', 'событий');

    $res[$i]['ACTIVE'] = $active;
    $res[$i]['STATUS_STATE'] = $active > 0 ? 'active' : 'read';
    $res[$i]['STATUS_LABEL'] = $active > 0 ? 'Активна' : 'Прочитана';
    $res[$i]['TITLE_DISPLAY'] = $display_title;
    $res[$i]['SHOW_CODE'] = $display_title !== $code ? 1 : 0;
    $res[$i]['KEEP_HISTORY'] = (int)$res[$i]['KEEP_HISTORY'] ? 1 : 0;
    $res[$i]['EVENTS_TOTAL'] = $history['CNT'];
    $res[$i]['EVENTS_LABEL'] = $events_label;
    $res[$i]['LAST_EVENT_FORMATTED'] = $last_event_formatted;
    $res[$i]['LATEST_UPDATE_FORMATTED'] = $latest_update_formatted;
    $res[$i]['HAS_ORIGIN'] = $origin['HAS_ORIGIN'];
    $res[$i]['ORIGIN_MODULE'] = $origin['MODULE'];
    $res[$i]['ORIGIN_FILE'] = $origin['FILE'];
    $res[$i]['ORIGIN_FILE_SHORT'] = $origin['FILE'] !== '' ? basename($origin['FILE']) : '';
    $res[$i]['ORIGIN_LINE'] = (int)$origin['LINE'];
    $res[$i]['ORIGIN_CALL'] = $origin['CALL'];

    foreach ($res[$i] as $field => $value) {
        if (is_scalar($value) && !in_array($field, array('ID', 'ACTIVE', 'KEEP_HISTORY', 'EVENTS_TOTAL', 'SHOW_CODE', 'HAS_ORIGIN', 'ORIGIN_LINE', 'ORIGIN_MODULE', 'ORIGIN_FILE', 'ORIGIN_CALL'), true)) {
            $res[$i][$field] = htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
        }
    }
}

$keep_history_days = defined('SETTINGS_ERRORS_KEEP_HISTORY') ? (int)SETTINGS_ERRORS_KEEP_HISTORY : 0;
$last_error_update = md_system_errors_date($last_error_update);

$out['RESULT'] = $total ? $res : array();
$out['RESULT_TOTAL'] = $total;
$out['ERRORS_TOTAL'] = $total;
$out['ERRORS_ACTIVE_TOTAL'] = $active_groups;
$out['ERRORS_ACTIVE_OCCURRENCES'] = $active_occurrences;
$out['ERRORS_FOUND'] = $active_groups;
$out['EVENTS_TOTAL'] = $events_total;
$out['LAST_ERROR_UPDATE'] = htmlspecialchars($last_error_update, ENT_QUOTES, 'UTF-8');
$out['SETTINGS_ERRORS_KEEP_HISTORY'] = $keep_history_days;

if ($this->ajax && gr('op') == 'check') {
    echo $active_groups;
    exit;
}
