<?php
/*
* @version 0.3 (wizard)
*/

require_once(__DIR__ . '/pinghosts_helpers.inc.php');

$codeEditorSettings = array(
    'AUTOCLOSEQUOTES' => 1,
    'WRAPLINES' => 0,
    'SHOWERROR' => 0,
    'UPTOLINE' => 0,
    'THEME' => 'auto',
    'MIXLINE' => 12,
    'SHOWLINE' => 0,
    'TURNONSETTINGS' => 0,
    'AUTOSAVE' => 0,
);
foreach ($codeEditorSettings as $setting => $default) {
    $constantName = 'SETTINGS_CODEEDITOR_' . $setting;
    $value = defined($constantName) ? constant($constantName) : null;
    $out[$constantName] = ($value === null || $value === '') ? $default : $value;
}

$out['CODE_EDITOR_MODE'] = 'php';
$out['CODE_EDITOR_KEY_ONLINE'] = 'pinghost_online_' . (int)$id;
$out['CODE_EDITOR_KEY_OFFLINE'] = 'pinghost_offline_' . (int)$id;

if ($this->owner->name == 'panel') {
    $out['CONTROLPANEL'] = 1;
}

$table_name = 'pinghosts';
$rec = SQLSelectOne("SELECT * FROM $table_name WHERE ID='$id'");

if ($this->mode == 'update') {
    $ok = 1;

    // HOSTNAME (varchar, required)
    global $hostname;
    $rec['HOSTNAME'] = trim((string)$hostname);
    if ($rec['HOSTNAME'] === '') {
        $out['ERR_HOSTNAME'] = 1;
        $ok = 0;
    }

    // TITLE (varchar, required)
    global $title;
    $rec['TITLE'] = trim((string)$title);
    if ($rec['TITLE'] === '') {
        $out['ERR_TITLE'] = 1;
        $ok = 0;
    }

    // HOST TYPE (select)
    global $type;
    $rec['TYPE'] = (int)$type;

    // SEARCH WORD (varchar)
    if ($rec['TYPE']) {
        global $search_word;
        $rec['SEARCH_WORD'] = trim((string)$search_word);
    }

    global $counter_required;
    $rec['COUNTER_REQUIRED'] = (int)$counter_required;

    global $linked_object;
    global $linked_property;
    $rec['LINKED_OBJECT'] = trim((string)$linked_object);
    $rec['LINKED_PROPERTY'] = trim((string)$linked_property);

    // ONLINE action code
    global $code_online;
    $old_code_online = isset($rec['CODE_ONLINE']) ? $rec['CODE_ONLINE'] : '';
    $rec['CODE_ONLINE'] = (string)$code_online;

    // OFFLINE action code
    global $code_offline;
    $old_code_offline = isset($rec['CODE_OFFLINE']) ? $rec['CODE_OFFLINE'] : '';
    $rec['CODE_OFFLINE'] = (string)$code_offline;

    global $run_type_online;
    if ($run_type_online == 'script') {
        global $script_id_online;
        $rec['SCRIPT_ID_ONLINE'] = (int)$script_id_online;
    } else {
        $rec['SCRIPT_ID_ONLINE'] = 0;
    }

    if ($rec['CODE_ONLINE'] !== '' && $run_type_online == 'code') {
        $errorDetails = code_syntax_error_details($code_online);
        if ($errorDetails) {
            $out['ERR_LINE_ONLINE'] = (int)$errorDetails['line'];
            $out['ERR_CODE_ONLINE'] = 1;
            $out['ERRORS_ONLINE'] = md_pinghosts_error_message($errorDetails['message']);
            $out['ERR_FULL_ONLINE'] = md_pinghosts_error_message($errorDetails['full']);
            $out['ERR_OLD_CODE_ONLINE'] = $old_code_online;
            $out['ERR_OLD_CODE_ONLINE_B64'] = base64_encode((string)$old_code_online);
            $ok = 0;
        }
    }

    global $run_type_offline;
    if ($run_type_offline == 'script') {
        global $script_id_offline;
        $rec['SCRIPT_ID_OFFLINE'] = (int)$script_id_offline;
    } else {
        $rec['SCRIPT_ID_OFFLINE'] = 0;
    }

    if ($rec['CODE_OFFLINE'] !== '' && $run_type_offline == 'code') {
        $errorDetails = code_syntax_error_details($code_offline);
        if ($errorDetails) {
            $out['ERR_LINE_OFFLINE'] = (int)$errorDetails['line'];
            $out['ERR_CODE_OFFLINE'] = 1;
            $out['ERRORS_OFFLINE'] = md_pinghosts_error_message($errorDetails['message']);
            $out['ERR_FULL_OFFLINE'] = md_pinghosts_error_message($errorDetails['full']);
            $out['ERR_OLD_CODE_OFFLINE'] = $old_code_offline;
            $out['ERR_OLD_CODE_OFFLINE_B64'] = base64_encode((string)$old_code_offline);
            $ok = 0;
        }
    }

    // OFFLINE_INTERVAL (int)
    global $offline_interval;
    $rec['OFFLINE_INTERVAL'] = max(0, (int)$offline_interval);
    // ONLINE_INTERVAL (int)
    global $online_interval;
    $rec['ONLINE_INTERVAL'] = max(0, (int)$online_interval);

    // UPDATING RECORD
    if ($ok) {
        $rec['STATUS'] = 0;
        $rec['CHECK_LATEST'] = date('Y-m-d H:i:s');
        $rec['CHECK_NEXT'] = date('Y-m-d H:i:s');
        if ($rec['ID']) {
            SQLUpdate($table_name, $rec);
        } else {
            $rec['ID'] = SQLInsert($table_name, $rec);
        }
        $out['OK'] = 1;
    } else {
        $out['ERR'] = 1;
    }
}

// Keep the action tab that the user was editing. A code error forces the
// corresponding tab so the message and the editor are visible after submit.
$active_tab = isset($this->tab) ? (string)$this->tab : '';
if (isset($out['ERR_FULL_OFFLINE']) && $out['ERR_FULL_OFFLINE'] !== '') {
    $active_tab = 'offline';
} elseif (isset($out['ERR_FULL_ONLINE']) && $out['ERR_FULL_ONLINE'] !== '') {
    $active_tab = 'online';
} elseif (!in_array($active_tab, array('online', 'offline'), true)) {
    $active_tab = 'online';
}
$out['ACTIVE_TAB'] = $active_tab;

// Defaults for a new record (and to keep the template free of undefined keys).
if (!is_array($rec)) {
    $rec = array();
}
$rec += array(
    'ID' => '',
    'TITLE' => '',
    'HOSTNAME' => '',
    'TYPE' => 0,
    'STATUS' => 0,
    'SEARCH_WORD' => '',
    'CHECK_LATEST' => '',
    'CHECK_NEXT' => '',
    'SCRIPT_ID_ONLINE' => 0,
    'SCRIPT_ID_OFFLINE' => 0,
    'CODE_ONLINE' => '',
    'CODE_OFFLINE' => '',
    'COUNTER_CURRENT' => 0,
    'COUNTER_REQUIRED' => 0,
    'ONLINE_INTERVAL' => 0,
    'OFFLINE_INTERVAL' => 0,
    'LINKED_OBJECT' => '',
    'LINKED_PROPERTY' => '',
    'LOG' => '',
);

// Options for HOST TYPE (select)
$tmp = explode('|', PING_TYPE_OPTIONS);
foreach ($tmp as $option) {
    if (preg_match('/(.+)=(.+)/', $option, $matches)) {
        $optionValue = $matches[1];
        $optionTitle = $matches[2];
    } else {
        $optionValue = $option;
        $optionTitle = $option;
    }
    $out['TYPE_OPTIONS'][] = array('VALUE' => $optionValue, 'TITLE' => $optionTitle);
}
$optionsTypeCnt = count($out['TYPE_OPTIONS']);
for ($i = 0; $i < $optionsTypeCnt; $i++) {
    if ($out['TYPE_OPTIONS'][$i]['VALUE'] == (isset($rec['TYPE']) ? $rec['TYPE'] : 0)) {
        $out['TYPE_OPTIONS'][$i]['SELECTED'] = 1;
    }
}

if (is_array($rec)) {
    foreach ($rec as $k => $v) {
        if (!is_array($v)) {
            $rec[$k] = htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        }
    }
}
outHash($rec, $out);

$out['LOG_RAW'] = isset($rec['LOG']) ? (string)$rec['LOG'] : '';
$out['LOG'] = nl2br(isset($out['LOG']) ? (string)$out['LOG'] : '');

$out['SCRIPTS'] = SQLSelect("SELECT ID, TITLE FROM scripts ORDER BY TITLE");

$status_meta = md_pinghosts_status_meta(isset($rec['STATUS']) ? $rec['STATUS'] : 0);
$type_meta = md_pinghosts_type_meta(isset($rec['TYPE']) ? $rec['TYPE'] : 0);

$out['STATUS_STATE'] = $status_meta['state'];
$out['STATUS_LABEL'] = $status_meta['label'];
$out['TYPE_LABEL'] = $type_meta['label'];
$out['TYPE_TITLE'] = $type_meta['title'];
$out['TYPE_ICON'] = $type_meta['icon'];
$out['LINK'] = md_pinghosts_link_label(isset($rec['LINKED_OBJECT']) ? $rec['LINKED_OBJECT'] : '', isset($rec['LINKED_PROPERTY']) ? $rec['LINKED_PROPERTY'] : '');
$out['CHECK_LATEST_HUMAN'] = md_pinghosts_relative_time(isset($rec['CHECK_LATEST']) ? $rec['CHECK_LATEST'] : '');
$out['CHECK_NEXT_HUMAN'] = md_pinghosts_relative_time(isset($rec['CHECK_NEXT']) ? $rec['CHECK_NEXT'] : '', true);

$online_interval = isset($rec['ONLINE_INTERVAL']) ? (int)$rec['ONLINE_INTERVAL'] : 0;
if ($online_interval <= 0) {
    $online_interval = 60;
}
$offline_interval = isset($rec['OFFLINE_INTERVAL']) ? (int)$rec['OFFLINE_INTERVAL'] : 0;
if ($offline_interval <= 0) {
    $offline_interval = $online_interval;
}
$out['ONLINE_INTERVAL_EFFECTIVE'] = $online_interval;
$out['OFFLINE_INTERVAL_EFFECTIVE'] = $offline_interval;
