<?php
/*
* @version 0.3 (wizard)
*/

require_once(__DIR__ . '/system_errors_helpers.inc.php');

if ($this->owner->name == 'panel') {
    $out['CONTROLPANEL'] = 1;
}

$table_name = 'system_errors';
$rec = SQLSelectOne("SELECT * FROM $table_name WHERE ID='" . (int)$id . "'");
if (!is_array($rec)) {
    $rec = array();
}

$error_id = !empty($rec['ID']) ? (int)$rec['ID'] : 0;
$latest_comment = '';
if ($error_id > 0) {
    $latest_event = SQLSelectOne("SELECT COMMENTS FROM system_errors_data WHERE ERROR_ID=" . $error_id . " ORDER BY ID DESC LIMIT 1");
    $latest_comment = isset($latest_event['COMMENTS']) ? (string)$latest_event['COMMENTS'] : '';
}

if ($this->tab == '') {
    if ($this->mode == 'update') {
        $ok = 1;

        $rec['CODE'] = trim((string)gr('code'));
        if ($rec['CODE'] === '') {
            $out['ERR_CODE'] = 1;
            $ok = 0;
        }

        $rec['TITLE'] = trim((string)gr('title'));

        $rec['DETAILS'] = (string)gr('details');
        $rec['ACTIVE'] = (int)gr('active');
        $rec['KEEP_HISTORY'] = (int)gr('keep_history') ? 1 : 0;

        if ($ok) {
            if (!empty($rec['ID'])) {
                SQLUpdate($table_name, $rec);
            } else {
                $rec['ID'] = SQLInsert($table_name, $rec);
            }
            $out['OK'] = 1;
        } else {
            $out['ERR'] = 1;
        }
    }
}

$display_title = md_system_errors_title(
    isset($rec['TITLE']) ? $rec['TITLE'] : '',
    isset($rec['CODE']) ? $rec['CODE'] : '',
    $latest_comment
);

if (is_array($rec)) {
    foreach ($rec as $k => $v) {
        if (!is_array($v)) {
            $rec[$k] = htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        }
    }
}
outHash($rec, $out);

$out['TITLE_DISPLAY'] = htmlspecialchars($display_title, ENT_QUOTES, 'UTF-8');
$out['ACTIVE'] = isset($rec['ACTIVE']) ? (int)$rec['ACTIVE'] : 0;
$out['KEEP_HISTORY'] = !empty($rec['KEEP_HISTORY']) ? 1 : 0;
$out['LATEST_UPDATE_FORMATTED'] = md_system_errors_date(isset($rec['LATEST_UPDATE']) ? $rec['LATEST_UPDATE'] : '');

$latest_frames = md_system_errors_frames($latest_comment);
$latest_origin = md_system_errors_origin($latest_frames);
$latest_context = md_system_errors_context($latest_comment);

$out['HAS_ORIGIN'] = $latest_origin['HAS_ORIGIN'];
$out['ORIGIN_MODULE'] = $latest_origin['MODULE'];
$out['ORIGIN_FILE'] = $latest_origin['FILE'];
$out['ORIGIN_LINE'] = (int)$latest_origin['LINE'];
$out['ORIGIN_CALL'] = $latest_origin['CALL'];
$out['HAS_CONTEXT'] = ($latest_context['URL'] !== '' || $latest_context['METHOD'] !== '' || $latest_context['IP'] !== '') ? 1 : 0;
$out['CONTEXT_URL'] = htmlspecialchars($latest_context['URL'], ENT_QUOTES, 'UTF-8');
$out['CONTEXT_METHOD'] = htmlspecialchars($latest_context['METHOD'], ENT_QUOTES, 'UTF-8');
$out['CONTEXT_IP'] = htmlspecialchars($latest_context['IP'], ENT_QUOTES, 'UTF-8');
$out['CONTEXT_REFERER'] = htmlspecialchars($latest_context['REFERER'], ENT_QUOTES, 'UTF-8');

$history_total = $error_id > 0
    ? SQLSelectOne("SELECT COUNT(*) AS CNT FROM system_errors_data WHERE ERROR_ID = " . $error_id)
    : array('CNT' => 0);
$out['HISTORY_TOTAL'] = isset($history_total['CNT']) ? (int)$history_total['CNT'] : 0;
$out['EVENTS_TOTAL'] = $out['HISTORY_TOTAL'];
$out['EVENTS_LABEL'] = $out['HISTORY_TOTAL'] . ' ' . md_system_errors_plural($out['HISTORY_TOTAL'], 'событие', 'события', 'событий');

if ($this->tab == 'history') {
    $history = SQLSelect(
        "SELECT d.*, e.CODE
           FROM system_errors_data d
           JOIN system_errors e ON e.ID = d.ERROR_ID
          WHERE d.ERROR_ID = " . $error_id . "
          ORDER BY d.ADDED DESC
          LIMIT 100"
    );
    $history_prepared = array();
    if (is_array($history)) {
        foreach ($history as $history_index => $history_item) {
            $raw_comments = (string)$history_item['COMMENTS'];
            $display_comments = $raw_comments;
            if (defined('ROOT') && ROOT !== '') {
                $display_comments = str_replace(ROOT, '', $display_comments);
            }

            $split = md_system_errors_split_comment($raw_comments);
            $message = trim($split['MESSAGE']) !== '' ? trim($split['MESSAGE']) : 'Ошибка без описания';
            $frames = md_system_errors_frames($raw_comments);
            $origin = md_system_errors_origin($frames);
            $context = md_system_errors_context($raw_comments);
            $has_context = ($context['URL'] !== '' || $context['METHOD'] !== '' || $context['IP'] !== '' || $context['REFERER'] !== '') ? 1 : 0;

            $preview = preg_replace('/\s+/u', ' ', $message);
            if (mb_strlen($preview, 'UTF-8') > 180) {
                $preview = mb_substr($preview, 0, 180, 'UTF-8') . '…';
            }

            $history_prepared[] = array(
                'ID' => isset($history_item['ID']) ? (int)$history_item['ID'] : 0,
                'ADDED' => md_system_errors_date((string)$history_item['ADDED']),
                'ADDED_RAW' => htmlspecialchars((string)$history_item['ADDED'], ENT_QUOTES, 'UTF-8'),
                'MESSAGE' => htmlspecialchars($message, ENT_QUOTES, 'UTF-8'),
                'COMMENTS_PREVIEW' => htmlspecialchars($preview, ENT_QUOTES, 'UTF-8'),
                'COMMENTS' => htmlspecialchars($display_comments, ENT_QUOTES, 'UTF-8'),
                'CODE' => htmlspecialchars((string)$history_item['CODE'], ENT_QUOTES, 'UTF-8'),
                'FRAMES' => array_slice($frames, 0, 5),
                'FRAMES_REST' => array_slice($frames, 5),
                'FRAMES_REST_TOTAL' => max(0, count($frames) - 5),
                'FRAMES_REST_LABEL' => max(0, count($frames) - 5) . ' ' . md_system_errors_plural(max(0, count($frames) - 5), 'кадр', 'кадра', 'кадров'),
                'FRAMES_TOTAL' => count($frames),
                'FRAMES_LABEL' => count($frames) . ' ' . md_system_errors_plural(count($frames), 'кадр', 'кадра', 'кадров'),
                'HAS_ORIGIN' => $origin['HAS_ORIGIN'],
                'ORIGIN_MODULE' => $origin['MODULE'],
                'ORIGIN_FILE' => $origin['FILE'],
                'ORIGIN_LINE' => (int)$origin['LINE'],
                'ORIGIN_CALL' => $origin['CALL'],
                'HAS_CONTEXT' => $has_context,
                'CONTEXT_URL' => htmlspecialchars($context['URL'], ENT_QUOTES, 'UTF-8'),
                'CONTEXT_METHOD' => htmlspecialchars($context['METHOD'], ENT_QUOTES, 'UTF-8'),
                'CONTEXT_IP' => htmlspecialchars($context['IP'], ENT_QUOTES, 'UTF-8'),
                'CONTEXT_REFERER' => htmlspecialchars($context['REFERER'], ENT_QUOTES, 'UTF-8'),
                'OPEN' => $history_index === 0 ? 1 : 0,
            );
        }
    }

    $out['HISTORY'] = $history_prepared;
    $out['LAST_EVENT_FORMATTED'] = isset($history_prepared[0]['ADDED']) ? $history_prepared[0]['ADDED'] : '—';
}
