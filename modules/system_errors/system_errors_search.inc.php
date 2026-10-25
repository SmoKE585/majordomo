<?php
/*
 * Подготовка данных списка групп ошибок.
 *
 * Подставляет название и описание кода из ErrorCatalog для карточки и поиска,
 * не меняя запись в базе.
 *
 * Изменения:
 * 2026-10-25 — сводка, фильтры и статусы; последние события читаются одним запросом.
 * 2026-10-22 — название и описание берутся из ErrorCatalog.
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
$status_counts = array('new' => 0, 'repeated' => 0, 'closed' => 0);
$sources = array();
$latest_comments = array();
foreach (SQLSelect('SELECT d.ERROR_ID, d.COMMENTS FROM system_errors_data d JOIN (SELECT ERROR_ID, MAX(ID) AS LAST_ID FROM system_errors_data GROUP BY ERROR_ID) h ON h.LAST_ID=d.ID') as $event) {
    $latest_comments[(int)$event['ERROR_ID']] = (string)$event['COMMENTS'];
}
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

    if (class_exists('MajorDoMo\\Core\\ErrorCatalog')) {
        $res[$i] = \MajorDoMo\Core\ErrorCatalog::fill($res[$i], (string)$res[$i]['CODE']);
    }

    $title = trim((string)$res[$i]['TITLE']);
    $code = (string)$res[$i]['CODE'];
    $latest_comment = $latest_comments[$error_id] ?? '';
    $diagnostic = md_system_errors_diagnostic($latest_comment, $res[$i]['DETAILS']);

    $display_title = md_system_errors_title($title, $code, $latest_comment);
    $frames = md_system_errors_frames($latest_comment);
    $origin = md_system_errors_origin($frames);
    $events_label = $history['CNT'] . ' ' . md_system_errors_plural($history['CNT'], 'событие', 'события', 'событий');

    $res[$i]['ACTIVE'] = $active;
    $status = \MajorDoMo\Core\ErrorDetails::status($active, \MajorDoMo\Core\ErrorDetails::decode($latest_comment), $history['CNT']);
    $status_counts[$status]++;
    $res[$i]['STATUS_STATE'] = $status;
    $res[$i]['STATUS_LABEL'] = \MajorDoMo\Core\ErrorDetails::label($status);
    $res[$i]['SUMMARY'] = mb_substr($diagnostic['SUMMARY'], 0, 230, 'UTF-8');
    $res[$i]['SOURCE'] = $diagnostic['SOURCE'] ?: html_entity_decode($origin['MODULE'], ENT_QUOTES, 'UTF-8');
    $sources[$res[$i]['SOURCE']] = $res[$i]['SOURCE'];
    $res[$i]['ENTITY'] = $diagnostic['ENTITY'];
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
$out['ERRORS_NEW_TOTAL'] = $status_counts['new'];
$out['ERRORS_REPEATED_TOTAL'] = $status_counts['repeated'];
$out['ERRORS_CLOSED_TOTAL'] = $status_counts['closed'];
ksort($sources);
$out['SOURCE_OPTIONS'] = array_map(static fn($source) => array('VALUE' => htmlspecialchars($source, ENT_QUOTES, 'UTF-8')), array_values($sources));

if ($this->ajax && gr('op') == 'check') {
    echo $active_groups;
    exit;
}
