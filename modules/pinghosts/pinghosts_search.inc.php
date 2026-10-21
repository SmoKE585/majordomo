<?php
/*
* @version 0.3 (wizard)
*/

require_once(__DIR__ . '/pinghosts_helpers.inc.php');

if ($this->owner->name == 'panel') {
    $out['CONTROLPANEL'] = 1;
}

// The status filter is applied on the client by the data table. The query
// parameter is kept for legacy links and only preselects the filter.
$status_filter = gr('status');
if (is_scalar($status_filter) && in_array((string)$status_filter, array('0', '1', '2'), true)) {
    $out['STATUS'] = (string)$status_filter;
}

$res = SQLSelect("SELECT * FROM pinghosts ORDER BY TITLE");
$total = is_array($res) ? count($res) : 0;

$status_counts = array('0' => 0, '1' => 0, '2' => 0);
$type_counts = array('0' => 0, '1' => 0, '2' => 0);

for ($i = 0; $i < $total; $i++) {
    $status = (int)$res[$i]['STATUS'];
    $type = (int)$res[$i]['TYPE'];
    if (!isset($status_counts[$status])) {
        $status_counts[$status] = 0;
    }
    if (!isset($type_counts[$type])) {
        $type_counts[$type] = 0;
    }
    $status_counts[$status]++;
    $type_counts[$type]++;

    $status_meta = md_pinghosts_status_meta($status);
    $type_meta = md_pinghosts_type_meta($type);

    $online_interval = (int)$res[$i]['ONLINE_INTERVAL'];
    if ($online_interval <= 0) {
        $online_interval = 60;
    }
    $offline_interval = (int)$res[$i]['OFFLINE_INTERVAL'];
    if ($offline_interval <= 0) {
        $offline_interval = $online_interval;
    }

    $res[$i]['STATUS_STATE'] = $status_meta['state'];
    $res[$i]['STATUS_LABEL'] = $status_meta['label'];
    $res[$i]['TYPE_LABEL'] = $type_meta['label'];
    $res[$i]['TYPE_TITLE'] = $type_meta['title'];
    $res[$i]['TYPE_TONE'] = $type_meta['tone'];
    $res[$i]['TYPE_ICON'] = $type_meta['icon'];
    $res[$i]['ONLINE_INTERVAL_EFFECTIVE'] = $online_interval;
    $res[$i]['OFFLINE_INTERVAL_EFFECTIVE'] = $offline_interval;
    $res[$i]['CHECK_LATEST_HUMAN'] = md_pinghosts_relative_time($res[$i]['CHECK_LATEST']);
    $res[$i]['CHECK_NEXT_HUMAN'] = md_pinghosts_relative_time($res[$i]['CHECK_NEXT'], true);
    $res[$i]['LINK'] = md_pinghosts_link_label($res[$i]['LINKED_OBJECT'], $res[$i]['LINKED_PROPERTY']);

    foreach ($res[$i] as $field => $value) {
        if (is_scalar($value) && $field !== 'ID') {
            $res[$i][$field] = htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
        }
    }
}

$out['RESULT'] = $total ? $res : array();
$out['RESULT_TOTAL'] = $total;
$out['HOSTS_TOTAL'] = $total;
$out['HOSTS_ONLINE_TOTAL'] = $status_counts['1'];
$out['HOSTS_OFFLINE_TOTAL'] = $status_counts['2'];
$out['HOSTS_UNKNOWN_TOTAL'] = $status_counts['0'];
$out['HOSTS_PING_TOTAL'] = $type_counts['0'];
$out['HOSTS_WEB_TOTAL'] = $type_counts['1'];
$out['HOSTS_PORT_TOTAL'] = $type_counts['2'];
