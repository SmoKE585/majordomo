<?php
/*
* MQTT topic editor data.
*/

require_once(__DIR__ . '/mqtt_helpers.inc.php');

if ($this->owner->name == 'panel') {
    $out['CONTROLPANEL'] = 1;
}

$table_name = 'mqtt';
$rec = SQLSelectOne("SELECT * FROM " . $table_name . " WHERE ID=" . (int)$id);
if (!is_array($rec)) {
    $rec = array();
}

if ($this->mode == 'update') {
    $ok = 1;
    $old_path = isset($rec['PATH']) ? (string)$rec['PATH'] : '';
    $old_linked_object = isset($rec['LINKED_OBJECT']) ? (string)$rec['LINKED_OBJECT'] : '';
    $old_linked_property = isset($rec['LINKED_PROPERTY']) ? (string)$rec['LINKED_PROPERTY'] : '';
    $old_linked_method = isset($rec['LINKED_METHOD']) ? (string)$rec['LINKED_METHOD'] : '';

    if (isset($this->location_id)) {
        $rec['LOCATION_ID'] = (int)$this->location_id;
    } else {
        $rec['LOCATION_ID'] = (int)gr('location_id');
    }

    $rec['PATH'] = trim((string)gr('path'));
    if ($rec['PATH'] === '') {
        $out['ERR_PATH'] = 1;
        $ok = 0;
    }

    $title = trim((string)gr('title'));
    if ($title === '') {
        $title = md_mqtt_last_segment($rec['PATH']);
    }
    $rec['TITLE'] = mb_substr($title, 0, 255);

    $rec['PATH_WRITE'] = trim((string)gr('path_write'));
    $rec['DISP_FLAG'] = (int)gr('disp_flag');
    $rec['QOS'] = (int)gr('qos');
    $rec['RETAIN'] = (int)gr('retain');
    $rec['REPLACE_LIST'] = trim((string)gr('replace_list'));

    $rec['LINKED_OBJECT'] = trim((string)gr('linked_object'));
    $rec['LINKED_PROPERTY'] = trim((string)gr('linked_property'));
    $rec['LINKED_METHOD'] = trim((string)gr('linked_method'));

    $rec['READONLY'] = (int)gr('readonly');
    $rec['ONLY_NEW_VALUE'] = (int)gr('only_new_value');
    $rec['LOGGING'] = (int)gr('logging');
    $rec['WRITE_TYPE'] = (int)gr('write_type');

    if ($ok) {
        if (!empty($rec['ID'])) {
            SQLUpdate($table_name, $rec);
        } else {
            $rec['ID'] = SQLInsert($table_name, $rec);
            $new_rec = 1;
        }

        $this->invalidateLookupCache();

        if (isset($new_rec) || $old_path !== $rec['PATH'] ||
            $old_linked_object !== $rec['LINKED_OBJECT'] ||
            $old_linked_property !== $rec['LINKED_PROPERTY'] ||
            $old_linked_method !== $rec['LINKED_METHOD']) {
            setGlobal('cycle_mqttControl', 'restart');
        }

        if ($rec['LINKED_OBJECT'] !== '' && $rec['LINKED_PROPERTY'] !== '') {
            addLinkedProperty($rec['LINKED_OBJECT'], $rec['LINKED_PROPERTY'], $this->name);
        }
        if ($old_linked_object !== '' && $old_linked_property !== '' &&
            ($old_linked_object !== $rec['LINKED_OBJECT'] || $old_linked_property !== $rec['LINKED_PROPERTY'])) {
            removeLinkedProperty($old_linked_object, $old_linked_property, $this->name);
        }

        if ($rec['PATH_WRITE'] !== '' && $rec['PATH_WRITE'] !== $rec['PATH']) {
            $conflict = SQLSelectOne("SELECT ID FROM mqtt WHERE PATH='" . DBSafe($rec['PATH_WRITE']) . "' AND ID!=" . (int)$rec['ID']);
            if (!empty($conflict['ID'])) {
                SQLExec("DELETE FROM mqtt WHERE ID=" . (int)$conflict['ID']);
            }
            $this->invalidateLookupCache();
        }

        $set_new_value = (int)gr('set_new_value');
        if ($set_new_value) {
            $new_value = (string)gr('new_value');
            $this->setProperty($rec['ID'], $new_value, 1);
            $out['PUBLISHED'] = 1;
        }

        $rec = SQLSelectOne("SELECT * FROM " . $table_name . " WHERE ID=" . (int)$rec['ID']);
        if (!is_array($rec)) {
            $rec = array();
        }
        $out['OK'] = 1;
    } else {
        $out['ERR'] = 1;
    }
}

$raw_path = isset($rec['PATH']) ? (string)$rec['PATH'] : '';
$raw_title = isset($rec['TITLE']) ? (string)$rec['TITLE'] : '';
$raw_value = isset($rec['VALUE']) ? (string)$rec['VALUE'] : '';
$raw_updated = isset($rec['UPDATED']) ? (string)$rec['UPDATED'] : '';
$raw_object = isset($rec['LINKED_OBJECT']) ? (string)$rec['LINKED_OBJECT'] : '';
$raw_property = isset($rec['LINKED_PROPERTY']) ? (string)$rec['LINKED_PROPERTY'] : '';
$raw_method = isset($rec['LINKED_METHOD']) ? (string)$rec['LINKED_METHOD'] : '';
$raw_path_write = isset($rec['PATH_WRITE']) ? (string)$rec['PATH_WRITE'] : '';
$raw_location_id = isset($rec['LOCATION_ID']) ? (int)$rec['LOCATION_ID'] : 0;

$locations = SQLSelect("SELECT ID, TITLE FROM locations ORDER BY TITLE");
$location_titles = array();
foreach ($locations as $index => $location) {
    $location_titles[(int)$location['ID']] = $location['TITLE'];
    $locations[$index]['TITLE'] = md_mqtt_h($location['TITLE']);
    $locations[$index]['SELECTED'] = ((int)$location['ID'] === $raw_location_id) ? 1 : 0;
}
$out['LOCATION_OPTIONS'] = $locations;

$device_titles = $raw_object !== '' ? md_mqtt_device_map(array($raw_object)) : array();
$linked_device = $raw_object !== ''
    ? (isset($device_titles[$raw_object]) ? $device_titles[$raw_object] : $raw_object)
    : '';
$linked_target = $raw_property !== '' ? $raw_property : $raw_method;
$linked_kind = $raw_property !== '' ? 'P' : ($raw_method !== '' ? 'M' : '');
$has_link = $raw_object !== '';

$display_title = ($raw_title !== '' && $raw_title !== $raw_path) ? $raw_title : $raw_path;
$is_http_write = $raw_path_write !== '' && preg_match('#^https?://#i', $raw_path_write);

if (is_array($rec)) {
    foreach ($rec as $key => $value) {
        if (!is_array($value)) {
            $rec[$key] = md_mqtt_h($value);
        }
    }
}
outHash($rec, $out);

$out['TITLE_DISPLAY'] = md_mqtt_h($display_title !== '' ? $display_title : 'Новый топик');
$out['HAS_CUSTOM_TITLE'] = ($raw_title !== '' && $raw_title !== $raw_path) ? 1 : 0;
$out['PATH_DISPLAY'] = md_mqtt_h(md_mqtt_truncate($raw_path, 300));
$out['PATH_FULL'] = md_mqtt_h($raw_path);
$out['VALUE_DISPLAY'] = md_mqtt_h(md_mqtt_value_preview($raw_value, 240));
$out['VALUE_FULL'] = md_mqtt_h(md_mqtt_truncate($raw_value, 600));
$out['VALUE_EMPTY'] = $raw_value === '' ? 1 : 0;
$out['VALUE_LENGTH'] = mb_strlen($raw_value, 'UTF-8');
$out['IS_LONG_VALUE'] = mb_strlen($raw_value, 'UTF-8') > 240 ? 1 : 0;
$out['UPDATED_HUMAN'] = md_mqtt_h(md_mqtt_relative_time($raw_updated));
$out['UPDATED_EXACT'] = md_mqtt_h(md_mqtt_date_time($raw_updated));
$out['FRESHNESS'] = md_mqtt_freshness($raw_updated);
$out['HAS_LINK'] = $has_link ? 1 : 0;
$out['LINKED_DEVICE'] = md_mqtt_h($linked_device);
$out['LINKED_TARGET'] = md_mqtt_h($linked_target);
$out['LINKED_KIND'] = $linked_kind;
$out['LINKED_FULL'] = md_mqtt_h($has_link ? $linked_device . ' · ' . $linked_kind . ': ' . $linked_target : '');
$out['HAS_WRITE'] = $raw_path_write !== '' ? 1 : 0;
$out['WRITE_DISPLAY'] = md_mqtt_h(md_mqtt_truncate($raw_path_write, 180));
$out['IS_HTTP_WRITE'] = $is_http_write ? 1 : 0;
$out['LOCATION_TITLE'] = md_mqtt_h(isset($location_titles[$raw_location_id]) ? $location_titles[$raw_location_id] : '');
$out['IS_NEW'] = empty($rec['ID']) ? 1 : 0;
$out['READONLY'] = isset($rec['READONLY']) ? (int)$rec['READONLY'] : 0;
$out['LOGGING'] = isset($rec['LOGGING']) ? (int)$rec['LOGGING'] : 0;
$out['ONLY_NEW_VALUE'] = isset($rec['ONLY_NEW_VALUE']) ? (int)$rec['ONLY_NEW_VALUE'] : 0;
$out['QOS'] = isset($rec['QOS']) ? (int)$rec['QOS'] : 0;
$out['RETAIN'] = isset($rec['RETAIN']) ? (int)$rec['RETAIN'] : 0;
$out['WRITE_TYPE'] = isset($rec['WRITE_TYPE']) ? (int)$rec['WRITE_TYPE'] : 0;
$out['DISP_FLAG'] = isset($rec['DISP_FLAG']) ? (int)$rec['DISP_FLAG'] : 0;

$out['HISTORY'] = array();
$out['HISTORY_TOTAL'] = 0;
$out['HISTORY_LIMIT'] = 20;
if (!empty($rec['ID'])) {
    $history_total = SQLSelectOne("SELECT COUNT(*) AS CNT FROM mqtt_history WHERE MQTT_ID=" . (int)$rec['ID']);
    $out['HISTORY_TOTAL'] = isset($history_total['CNT']) ? (int)$history_total['CNT'] : 0;

    $history = SQLSelect("SELECT * FROM mqtt_history WHERE MQTT_ID=" . (int)$rec['ID'] . " ORDER BY ID DESC LIMIT 20");
    $history_rows = array();
    foreach ($history as $item) {
        $direction = (int)$item['DESTINATION'] === 1 ? 'out' : 'in';
        $history_rows[] = array(
            'ID' => (int)$item['ID'],
            'DIRECTION' => $direction,
            'DIRECTION_LABEL' => $direction === 'out' ? 'Исходящее' : 'Входящее',
            'TOPIC_DISPLAY' => md_mqtt_h(md_mqtt_truncate((string)$item['TOPIC'], 180)),
            'TOPIC_FULL' => md_mqtt_h((string)$item['TOPIC']),
            'PAYLOAD_DISPLAY' => md_mqtt_h(md_mqtt_value_preview((string)$item['DATA_PAYLOAD'], 120)),
            'PAYLOAD_FULL' => md_mqtt_h(md_mqtt_truncate((string)$item['DATA_PAYLOAD'], 500)),
            'VALUE_DISPLAY' => md_mqtt_h(md_mqtt_value_preview((string)$item['VALUE'], 120)),
            'VALUE_FULL' => md_mqtt_h(md_mqtt_truncate((string)$item['VALUE'], 500)),
            'LINKED_DATA' => md_mqtt_h((string)$item['LINKED_DATA']),
            'UPDATED_HUMAN' => md_mqtt_h(md_mqtt_relative_time((string)$item['UPDATED'])),
            'UPDATED_EXACT' => md_mqtt_h(md_mqtt_date_time((string)$item['UPDATED'])),
        );
    }
    $out['HISTORY'] = $history_rows;
}

$out['CHILDS'] = array();
$out['CHILDS_TOTAL'] = 0;
if (!empty($rec['ID']) && $raw_path !== '') {
    $child_total = SQLSelectOne(
        "SELECT COUNT(*) AS CNT FROM mqtt WHERE PATH LIKE '" . DBSafe($raw_path) . "/%' AND ID!=" . (int)$rec['ID']
    );
    $out['CHILDS_TOTAL'] = isset($child_total['CNT']) ? (int)$child_total['CNT'] : 0;

    $child_rows = SQLSelect(
        "SELECT * FROM mqtt WHERE PATH LIKE '" . DBSafe($raw_path) . "/%' AND ID!=" . (int)$rec['ID'] . " ORDER BY PATH LIMIT 50"
    );
    $children = array();
    foreach ($child_rows as $child) {
        $child_title = trim((string)$child['TITLE']);
        $child_path = (string)$child['PATH'];
        $children[] = array(
            'ID' => (int)$child['ID'],
            'TITLE_DISPLAY' => md_mqtt_h(($child_title !== '' && $child_title !== $child_path) ? $child_title : $child_path),
            'PATH_DISPLAY' => md_mqtt_h(md_mqtt_truncate($child_path, 220)),
            'PATH_FULL' => md_mqtt_h($child_path),
            'VALUE_DISPLAY' => md_mqtt_h(md_mqtt_value_preview((string)$child['VALUE'], 100)),
            'UPDATED_HUMAN' => md_mqtt_h(md_mqtt_relative_time((string)$child['UPDATED'])),
            'FRESHNESS' => md_mqtt_freshness((string)$child['UPDATED']),
            'HAS_LINK' => $child['LINKED_OBJECT'] !== '' ? 1 : 0,
            'LINKED_DEVICE' => md_mqtt_h($child['LINKED_OBJECT']),
        );
    }
    $out['CHILDS'] = $children;
}
