<?php
/*
* @version 0.2 (redesigned admin UI)
*/
global $session;
if ($this->owner->name == 'panel') {
    $out['CONTROLPANEL'] = 1;
}
$qry = "1";

// search filters
$period = trim(gr('period'));
if ($period == '') {
    $period = 'custom';
}
$out['PERIOD'] = $period;

$from = trim(gr('from'));
$to = trim(gr('to'));
if ($period != 'custom') {
    $period_to = date('Y-m-d H:i:s');
    if ($period == 'today') {
        $period_from = date('Y-m-d 00:00:00');
    } elseif ($period == 'day') {
        $period_from = date('Y-m-d H:i:s', time() - 24 * 60 * 60);
    } elseif ($period == 'week') {
        $period_from = date('Y-m-d H:i:s', time() - 7 * 24 * 60 * 60);
    } elseif ($period == 'month') {
        $period_from = date('Y-m-d H:i:s', time() - 30 * 24 * 60 * 60);
    } else {
        $period_from = '';
    }
    if ($period_from != '') {
        $from = $period_from;
        $to = $period_to;
    }
}

$from = $this->normalizeDateInput($from, false);
$to = $this->normalizeDateInput($to, true);

if ($from != '') {
    $out['FROM'] = $from;
    $out['FROM_DATE'] = date('Y-m-d', strtotime($from));
    $qry .= " AND actions_log.ADDED>='" . DBSafe($from) . "'";
}

if ($to != '') {
    $out['TO'] = $to;
    $out['TO_DATE'] = date('Y-m-d', strtotime($to));
    $qry .= " AND actions_log.ADDED<='" . DBSafe($to) . "'";
}

$action_type = trim(gr('action_type'));
if ($action_type != '') {
    $qry .= " AND actions_log.ACTION_TYPE='" . DBSafe($action_type) . "'";
    $out['ACTION_TYPE'] = $action_type;
}

$source = trim(gr('source'));
if ($source != '') {
    $qry .= " AND actions_log.SOURCE='" . DBSafe($source) . "'";
    $out['SOURCE'] = $source;
}

$module_name = trim(gr('module_name'));
if ($module_name != '') {
    $qry .= " AND actions_log.MODULE='" . DBSafe($module_name) . "'";
    $out['MODULE_NAME'] = $module_name;
}

$result = trim(gr('result'));
if ($result != '') {
    $qry .= " AND actions_log.RESULT='" . DBSafe($result) . "'";
    $out['RESULT_FILTER'] = $result;
}

$request_method = trim(gr('request_method'));
if ($request_method != '') {
    $qry .= " AND actions_log.REQUEST_METHOD='" . DBSafe($request_method) . "'";
    $out['REQUEST_METHOD'] = $request_method;
}

$user_name = trim(gr('user_name'));
if ($user_name != '') {
    $qry .= " AND actions_log.USER LIKE '%" . DBSafe($user_name) . "%'";
    $out['USER_NAME'] = htmlspecialchars($user_name, ENT_QUOTES);
}

$terminal = trim(gr('terminal'));
if ($terminal != '') {
    $qry .= " AND actions_log.TERMINAL LIKE '%" . DBSafe($terminal) . "%'";
    $out['TERMINAL_FILTER'] = htmlspecialchars($terminal, ENT_QUOTES);
}

$ip = trim(gr('ip'));
if ($ip != '') {
    $qry .= " AND actions_log.IP LIKE '%" . DBSafe($ip) . "%'";
    $out['IP_FILTER'] = htmlspecialchars($ip, ENT_QUOTES);
}

$search = trim(gr('search'));
if ($search != '') {
    $safe_search = DBSafe($search);
    $search_conditions = array();
    $search_conditions[] = "actions_log.TITLE LIKE '%" . $safe_search . "%'";
    $search_conditions[] = "actions_log.USER LIKE '%" . $safe_search . "%'";
    $search_conditions[] = "actions_log.TERMINAL LIKE '%" . $safe_search . "%'";
    $search_conditions[] = "actions_log.IP LIKE '%" . $safe_search . "%'";
    $search_conditions[] = "actions_log.ACTION_TYPE LIKE '%" . $safe_search . "%'";
    $search_conditions[] = "actions_log.SOURCE LIKE '%" . $safe_search . "%'";
    $search_conditions[] = "actions_log.MODULE LIKE '%" . $safe_search . "%'";
    $search_conditions[] = "actions_log.OBJECT_TITLE LIKE '%" . $safe_search . "%'";
    $search_conditions[] = "actions_log.OBJECT_ID LIKE '%" . $safe_search . "%'";
    $search_conditions[] = "actions_log.URL LIKE '%" . $safe_search . "%'";
    $search_conditions[] = "actions_log.DETAILS_JSON LIKE '%" . $safe_search . "%'";
    $qry .= " AND (" . implode(' OR ', $search_conditions) . ")";
    $out['SEARCH'] = htmlspecialchars($search, ENT_QUOTES);
}

$filters_active = ($period != 'custom'
    || $from != ''
    || $to != ''
    || $action_type != ''
    || $source != ''
    || $module_name != ''
    || $result != ''
    || $request_method != ''
    || $user_name != ''
    || $terminal != ''
    || $ip != ''
    || $search != '');
$out['FILTERS_ACTIVE'] = $filters_active ? 1 : 0;

// QUERY READY
global $save_qry;
if ($save_qry) {
    $qry = $session->get('actions_log_qry');
} else {
    $session->set('actions_log_qry', $qry);
}
if (!$qry) {
    $qry = "1";
}

$sortby_actions_log = "actions_log.ID DESC";
$out['SORTBY'] = $sortby_actions_log;

// SEARCH RESULTS
$stats = SQLSelectOne("SELECT COUNT(*) AS TOTAL,
        SUM(CASE WHEN RESULT='ok' THEN 1 ELSE 0 END) AS OK_TOTAL,
        SUM(CASE WHEN RESULT='error' THEN 1 ELSE 0 END) AS ERROR_TOTAL,
        COUNT(DISTINCT USER) AS USER_TOTAL,
        COUNT(DISTINCT SOURCE) AS SOURCE_TOTAL
    FROM actions_log WHERE $qry");
$out['LOG_TOTAL'] = isset($stats['TOTAL']) ? (int)$stats['TOTAL'] : 0;
$out['LOG_OK_TOTAL'] = isset($stats['OK_TOTAL']) ? (int)$stats['OK_TOTAL'] : 0;
$out['LOG_ERROR_TOTAL'] = isset($stats['ERROR_TOTAL']) ? (int)$stats['ERROR_TOTAL'] : 0;
$out['LOG_USER_TOTAL'] = isset($stats['USER_TOTAL']) ? (int)$stats['USER_TOTAL'] : 0;
$out['LOG_SOURCE_TOTAL'] = isset($stats['SOURCE_TOTAL']) ? (int)$stats['SOURCE_TOTAL'] : 0;

// 2026-10-22 — пагинация ядра ограничивает страницу до расчёта SQL LIMIT.
$on_page = 50;
$pagination = new \MajorDoMo\Core\Pagination($out['LOG_TOTAL'], $on_page, (int)gr('page', 'int'));
$page = $pagination->page;
$limit = $pagination->offset . ',' . $on_page;
$out = array_replace($out, $pagination->template(compact(
    'period', 'from', 'to', 'action_type', 'source', 'module_name', 'result',
    'request_method', 'user_name', 'terminal', 'ip', 'search'
)));

$res = SQLSelect("SELECT actions_log.* FROM actions_log WHERE $qry ORDER BY " . $sortby_actions_log . " LIMIT $limit");

if (isset($res[0]['ID'])) {
    $total = count($res);
    for ($i = 0; $i < $total; $i++) {
        $res[$i]['ACTION_TYPE_TITLE'] = $this->getActionLabel($res[$i]['ACTION_TYPE']);
        $res[$i]['SOURCE_TITLE'] = $this->getSourceLabel($res[$i]['SOURCE']);
        $res[$i]['RESULT_TITLE'] = $this->getResultLabel($res[$i]['RESULT']);

        $source_key = strtolower(trim((string)$res[$i]['SOURCE']));
        $source_presets = array(
            'admin' => array('tone' => 'primary', 'icon' => 'layout-dashboard'),
            'api' => array('tone' => 'info', 'icon' => 'api'),
            'web' => array('tone' => 'success', 'icon' => 'world'),
            'objects' => array('tone' => 'info', 'icon' => 'box'),
            'command' => array('tone' => 'warning', 'icon' => 'terminal-2'),
            'cli' => array('tone' => 'warning', 'icon' => 'terminal'),
        );
        $source_preset = isset($source_presets[$source_key])
            ? $source_presets[$source_key]
            : array('tone' => 'neutral', 'icon' => 'world');
        $res[$i]['SOURCE_TONE'] = $source_preset['tone'];
        $res[$i]['SOURCE_ICON'] = $source_preset['icon'];

        $result_key = strtolower(trim((string)$res[$i]['RESULT']));
        $result_presets = array(
            'ok' => array('tone' => 'success', 'icon' => 'circle-check'),
            'error' => array('tone' => 'danger', 'icon' => 'alert-triangle'),
            'skipped' => array('tone' => 'warning', 'icon' => 'minus'),
        );
        $result_preset = isset($result_presets[$result_key])
            ? $result_presets[$result_key]
            : array('tone' => 'neutral', 'icon' => 'info-circle');
        $res[$i]['RESULT_TONE'] = $result_preset['tone'];
        $res[$i]['RESULT_ICON'] = $result_preset['icon'];

        $action_key = strtolower(trim((string)$res[$i]['ACTION_TYPE']));
        $action_icon = 'activity';
        if (strpos($action_key, 'control_panel_enter') !== false) {
            $action_icon = 'login';
        } elseif (strpos($action_key, 'control_panel_navigation') !== false) {
            $action_icon = 'arrow-right';
        } elseif (strpos($action_key, 'control_panel_submit') !== false) {
            $action_icon = 'device-floppy';
        } elseif (strpos($action_key, 'market_') === 0) {
            $action_icon = 'plug';
        } elseif (strpos($action_key, 'system_') === 0) {
            $action_icon = 'settings';
        } elseif (strpos($action_key, 'menu_') !== false || strpos($action_key, 'scene_') !== false) {
            $action_icon = 'click';
        } elseif (strpos($action_key, 'device_') !== false) {
            $action_icon = 'device-desktop';
        }
        $res[$i]['ACTION_ICON'] = $action_icon;

        $user_display = trim((string)$res[$i]['USER']);
        if ($user_display === '') {
            $user_display = 'Система';
        }
        $res[$i]['USER_DISPLAY'] = $user_display;
        $res[$i]['USER_INITIAL'] = mb_strtoupper(mb_substr($user_display, 0, 1, 'UTF-8'), 'UTF-8');

        $added_ts = strtotime((string)$res[$i]['ADDED']);
        $res[$i]['ADDED_DATE'] = $added_ts ? date('d.m.Y', $added_ts) : (string)$res[$i]['ADDED'];
        $res[$i]['ADDED_TIME'] = $added_ts ? date('H:i:s', $added_ts) : '';
        $res[$i]['ADDED_ISO'] = $added_ts ? date('c', $added_ts) : '';

        $target_parts = array();
        if ($res[$i]['OBJECT_TYPE'] != '') {
            $target_parts[] = $res[$i]['OBJECT_TYPE'];
        }
        if ($res[$i]['OBJECT_TITLE'] != '') {
            $target_parts[] = $res[$i]['OBJECT_TITLE'];
        } elseif ($res[$i]['OBJECT_ID'] != '') {
            $target_parts[] = '#' . $res[$i]['OBJECT_ID'];
        }
        if (isset($target_parts[0])) {
            $res[$i]['TARGET_TITLE'] = implode(': ', $target_parts);
        } else {
            $res[$i]['TARGET_TITLE'] = '';
        }
        $res[$i]['HAS_TARGET'] = $res[$i]['TARGET_TITLE'] !== '' ? 1 : 0;

        $location_parts = array();
        if ($res[$i]['MODULE'] != '') {
            $location_parts[] = $res[$i]['MODULE'];
        }
        if ($res[$i]['VIEW_MODE'] != '') {
            $location_parts[] = $res[$i]['VIEW_MODE'];
        }
        if ($res[$i]['REQUEST_METHOD'] != '') {
            $location_parts[] = strtoupper($res[$i]['REQUEST_METHOD']);
        }
        $res[$i]['LOCATION_TITLE'] = isset($location_parts[0]) ? implode(' / ', $location_parts) : '';

        $details_rows = array();
        if ($res[$i]['TITLE'] != '') {
            $details_rows[] = $res[$i]['TITLE'];
        }
        if ($res[$i]['URL'] != '') {
            $details_rows[] = 'URL: ' . $res[$i]['URL'];
        }
        if ($res[$i]['REFERER'] != '') {
            $details_rows[] = 'Referer: ' . $res[$i]['REFERER'];
        }
        if ($res[$i]['DETAILS_JSON'] != '') {
            $details_payload = $res[$i]['DETAILS_JSON'];
            $decoded_payload = json_decode($details_payload, true);
            if (is_array($decoded_payload)) {
                $json_options = 0;
                if (defined('JSON_UNESCAPED_UNICODE')) {
                    $json_options = $json_options | JSON_UNESCAPED_UNICODE;
                }
                if (defined('JSON_UNESCAPED_SLASHES')) {
                    $json_options = $json_options | JSON_UNESCAPED_SLASHES;
                }
                if (defined('JSON_PRETTY_PRINT')) {
                    $json_options = $json_options | JSON_PRETTY_PRINT;
                }
                $pretty_payload = json_encode($decoded_payload, $json_options);
                if (is_string($pretty_payload) && $pretty_payload != '') {
                    $details_payload = $pretty_payload;
                }
            }
            $details_rows[] = $details_payload;
        }
        $res[$i]['HAS_DETAILS'] = isset($details_rows[0]) ? 1 : 0;
        $res[$i]['DETAILS_BODY'] = implode("\n\n", $details_rows);
        $res[$i]['ROW_UID'] = 'log_' . $res[$i]['ID'];

        $safe_fields = array(
            'ADDED', 'ADDED_DATE', 'ADDED_TIME', 'ADDED_ISO', 'IP', 'USER', 'USER_DISPLAY', 'USER_INITIAL',
            'TERMINAL', 'ACTION_TYPE_TITLE', 'SOURCE_TITLE', 'RESULT_TITLE', 'TARGET_TITLE', 'LOCATION_TITLE',
            'MODULE', 'VIEW_MODE', 'REQUEST_METHOD', 'URL', 'DETAILS_BODY', 'TITLE',
            'ACTION_ICON', 'SOURCE_TONE', 'SOURCE_ICON', 'RESULT_TONE', 'RESULT_ICON',
        );
        foreach ($safe_fields as $field_name) {
            if (!isset($res[$i][$field_name])) {
                $res[$i][$field_name] = '';
            }
            $res[$i][$field_name . '_HTML'] = htmlspecialchars((string)$res[$i][$field_name], ENT_QUOTES);
        }

        if ($res[$i]['URL'] != '') {
            $short_url = $res[$i]['URL'];
            if (mb_strlen($short_url, 'utf-8') > 80) {
                $short_url = mb_substr($short_url, 0, 80, 'utf-8') . '...';
            }
            $res[$i]['URL_SHORT_HTML'] = htmlspecialchars($short_url, ENT_QUOTES);
        } else {
            $res[$i]['URL_SHORT_HTML'] = '';
        }
    }
    $out['RESULT'] = $res;
}

$action_types = SQLSelect("SELECT DISTINCT(ACTION_TYPE) as VALUE FROM actions_log WHERE ACTION_TYPE!='' ORDER BY ACTION_TYPE");
for ($i = 0; $i < count($action_types); $i++) {
    $action_types[$i]['TITLE'] = $this->getActionLabel($action_types[$i]['VALUE']);
    if ($action_types[$i]['VALUE'] == $action_type) {
        $action_types[$i]['SELECTED'] = 1;
    }
}
$out['ACTION_TYPES'] = $action_types;

$sources = SQLSelect("SELECT DISTINCT(SOURCE) as VALUE FROM actions_log WHERE SOURCE!='' ORDER BY SOURCE");
for ($i = 0; $i < count($sources); $i++) {
    $sources[$i]['TITLE'] = $this->getSourceLabel($sources[$i]['VALUE']);
    if ($sources[$i]['VALUE'] == $source) {
        $sources[$i]['SELECTED'] = 1;
    }
}
$out['SOURCES'] = $sources;

$modules = SQLSelect("SELECT DISTINCT(MODULE) as VALUE FROM actions_log WHERE MODULE!='' ORDER BY MODULE");
for ($i = 0; $i < count($modules); $i++) {
    if ($modules[$i]['VALUE'] == $module_name) {
        $modules[$i]['SELECTED'] = 1;
    }
}
$out['MODULES_LIST'] = $modules;

$results = SQLSelect("SELECT DISTINCT(RESULT) as VALUE FROM actions_log WHERE RESULT!='' ORDER BY RESULT");
for ($i = 0; $i < count($results); $i++) {
    $results[$i]['TITLE'] = $this->getResultLabel($results[$i]['VALUE']);
    if ($results[$i]['VALUE'] == $result) {
        $results[$i]['SELECTED'] = 1;
    }
}
$out['RESULTS_LIST'] = $results;

$methods = SQLSelect("SELECT DISTINCT(REQUEST_METHOD) as VALUE FROM actions_log WHERE REQUEST_METHOD!='' ORDER BY REQUEST_METHOD");
for ($i = 0; $i < count($methods); $i++) {
    if ($methods[$i]['VALUE'] == $request_method) {
        $methods[$i]['SELECTED'] = 1;
    }
}
$out['METHODS_LIST'] = $methods;
