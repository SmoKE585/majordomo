<?php
/*
* MQTT topic explorer data.
*
* The page is server-paginated: filters and the selected device/path group are
* resolved in SQL, so a large topic tree never has to be rendered at once.
*/

require_once(__DIR__ . '/mqtt_helpers.inc.php');

if ($this->owner->name == 'panel') {
    $out['CONTROLPANEL'] = 1;
}

$go_linked_object = trim((string)gr('go_linked_object'));
$go_linked_property = trim((string)gr('go_linked_property'));
if ($go_linked_object !== '' && $go_linked_property !== '') {
    $linked_record = SQLSelectOne(
        "SELECT ID FROM mqtt WHERE LINKED_OBJECT='" . DBSafe($go_linked_object) . "' AND LINKED_PROPERTY='" . DBSafe($go_linked_property) . "'"
    );
    if (!empty($linked_record['ID'])) {
        $this->redirect("?id=" . (int)$linked_record['ID'] . "&view_mode=edit_mqtt");
    }
}

$group_by = trim((string)gr('group_by'));
if (!in_array($group_by, array('device', 'path'), true)) {
    $group_by = 'device';
}
if ((string)gr('tree') === '1') {
    // Legacy ?tree=1 link: keep the same data, show path grouping.
    $group_by = 'path';
}

$group = trim((string)gr('group'));
if ($group === '0' || $group === '__all__') {
    $group = '';
}

$path_prefix = trim((string)gr('path_prefix'), '/');
$root = trim((string)gr('root'), '/');
if ($path_prefix === '' && $root !== '') {
    $path_prefix = $root;
    $group_by = 'path';
}

$q = trim((string)gr('q'));
if ($q === '') {
    $q = trim((string)gr('title')); // legacy search parameter
}
if ($q === '') {
    $q = trim((string)gr('searchpath')); // legacy path filter
}

$linked = trim((string)gr('linked'));
if (!in_array($linked, array('', '1', '0'), true)) {
    $linked = '';
}

$location_id = (int)gr('location_id');

$sort = trim((string)gr('sort'));
if (!in_array($sort, array('updated', 'path', 'title', 'value'), true)) {
    $sort = 'updated';
}
$order_sql = array(
    'updated' => 'UPDATED DESC, ID DESC',
    'path' => 'PATH ASC, ID ASC',
    'title' => 'TITLE ASC, PATH ASC',
    'value' => 'VALUE ASC, ID ASC',
);
$order = $order_sql[$sort];

$page = max(1, (int)gr('page'));
$per_page = 20;

$where = array('1');

if ($group !== '') {
    if ($group === '__unlinked__') {
        $where[] = "LINKED_OBJECT=''";
    } elseif ($group_by === 'path') {
        $group_safe = DBSafe($group);
        $where[] = "(TRIM(BOTH '/' FROM PATH)='" . $group_safe . "' OR TRIM(BOTH '/' FROM PATH) LIKE '" . $group_safe . "/%')";
    } else {
        $where[] = "LINKED_OBJECT='" . DBSafe($group) . "'";
    }
}

if ($linked === '1') {
    $where[] = "LINKED_OBJECT<>''";
} elseif ($linked === '0') {
    $where[] = "LINKED_OBJECT=''";
}

if ($location_id > 0) {
    $where[] = "LOCATION_ID=" . $location_id;
}

if ($path_prefix !== '') {
    $prefix_safe = DBSafe($path_prefix);
    $where[] = "(TRIM(BOTH '/' FROM PATH)='" . $prefix_safe . "' OR TRIM(BOTH '/' FROM PATH) LIKE '" . $prefix_safe . "/%')";
}

if ($q !== '') {
    $like = '%' . DBSafe($q) . '%';
    $where[] = "(PATH LIKE '" . $like . "' OR TITLE LIKE '" . $like . "' OR VALUE LIKE '" . $like . "' OR LINKED_OBJECT LIKE '" . $like . "' OR LINKED_PROPERTY LIKE '" . $like . "' OR LINKED_METHOD LIKE '" . $like . "')";
}

$where_sql = implode(' AND ', $where);

$stats = SQLSelectOne(
    "SELECT COUNT(*) AS TOTAL,
            SUM(LINKED_OBJECT<>'') AS LINKED,
            SUM(LINKED_OBJECT='') AS UNLINKED,
            SUM(UPDATED>=DATE_SUB(NOW(), INTERVAL 24 HOUR)) AS ACTIVE,
            MAX(UPDATED) AS LAST_UPDATE
       FROM mqtt"
);
$all_total = isset($stats['TOTAL']) ? (int)$stats['TOTAL'] : 0;
$linked_total = isset($stats['LINKED']) ? (int)$stats['LINKED'] : 0;
$unlinked_total = isset($stats['UNLINKED']) ? (int)$stats['UNLINKED'] : 0;
$active_total = isset($stats['ACTIVE']) ? (int)$stats['ACTIVE'] : 0;
$last_update = isset($stats['LAST_UPDATE']) ? (string)$stats['LAST_UPDATE'] : '';

$devices_total_rec = SQLSelectOne("SELECT COUNT(DISTINCT LINKED_OBJECT) AS CNT FROM mqtt WHERE LINKED_OBJECT<>''");
$devices_total = isset($devices_total_rec['CNT']) ? (int)$devices_total_rec['CNT'] : 0;

$count_rec = SQLSelectOne("SELECT COUNT(*) AS CNT FROM mqtt WHERE " . $where_sql);
$total = isset($count_rec['CNT']) ? (int)$count_rec['CNT'] : 0;
$pages = max(1, (int)ceil($total / $per_page));
$page = min($page, $pages);
$offset = ($page - 1) * $per_page;

$result = $total
    ? SQLSelect("SELECT * FROM mqtt WHERE " . $where_sql . " ORDER BY " . $order . " LIMIT " . $offset . "," . $per_page)
    : array();

$objects = array();
foreach ($result as $row) {
    if ($row['LINKED_OBJECT'] !== '') {
        $objects[] = $row['LINKED_OBJECT'];
    }
}
$device_titles = md_mqtt_device_map($objects);
$location_titles = md_mqtt_location_map();

foreach ($result as $index => $row) {
    $path = (string)$row['PATH'];
    $title = trim((string)$row['TITLE']);
    $has_custom_title = $title !== '' && $title !== $path;
    $display_title = $has_custom_title ? $title : $path;

    $value = (string)$row['VALUE'];
    $updated = (string)$row['UPDATED'];
    $has_link = $row['LINKED_OBJECT'] !== '';
    $linked_device = $has_link
        ? (isset($device_titles[$row['LINKED_OBJECT']]) ? $device_titles[$row['LINKED_OBJECT']] : $row['LINKED_OBJECT'])
        : '';
    $linked_target = $row['LINKED_PROPERTY'] !== '' ? $row['LINKED_PROPERTY'] : $row['LINKED_METHOD'];
    $linked_kind = $row['LINKED_PROPERTY'] !== '' ? 'P' : ($row['LINKED_METHOD'] !== '' ? 'M' : '');
    $has_write = $row['PATH_WRITE'] !== '';
    $is_http_write = $has_write && preg_match('#^https?://#i', $row['PATH_WRITE']);
    $location_title = isset($location_titles[(int)$row['LOCATION_ID']]) ? $location_titles[(int)$row['LOCATION_ID']] : '';

    $result[$index]['TITLE_DISPLAY'] = md_mqtt_truncate($display_title, 180);
    $result[$index]['HAS_CUSTOM_TITLE'] = $has_custom_title ? 1 : 0;
    $result[$index]['PATH_DISPLAY'] = md_mqtt_truncate($path, 220);
    $result[$index]['PATH_FULL'] = $path;
    $result[$index]['VALUE_DISPLAY'] = md_mqtt_value_preview($value, 160);
    $result[$index]['VALUE_FULL'] = md_mqtt_truncate($value, 600);
    $result[$index]['VALUE_EMPTY'] = $value === '' ? 1 : 0;
    $result[$index]['UPDATED_HUMAN'] = md_mqtt_relative_time($updated);
    $result[$index]['UPDATED_EXACT'] = md_mqtt_date_time($updated);
    $result[$index]['FRESHNESS'] = md_mqtt_freshness($updated);
    $result[$index]['HAS_LINK'] = $has_link ? 1 : 0;
    $result[$index]['LINKED_DEVICE'] = $linked_device;
    $result[$index]['LINKED_TARGET'] = $linked_target;
    $result[$index]['LINKED_KIND'] = $linked_kind;
    $result[$index]['LINKED_FULL'] = $has_link ? $linked_device . ' · ' . $linked_kind . ': ' . $linked_target : '';
    $result[$index]['LOCATION_TITLE'] = $location_title;
    $result[$index]['HAS_WRITE'] = $has_write ? 1 : 0;
    $result[$index]['WRITE_DISPLAY'] = $has_write ? md_mqtt_truncate((string)$row['PATH_WRITE'], 140) : '';
    $result[$index]['IS_HTTP_WRITE'] = $is_http_write ? 1 : 0;
    $result[$index]['READONLY'] = (int)$row['READONLY'];
    $result[$index]['LOGGING'] = (int)$row['LOGGING'];
    $result[$index]['ONLY_NEW_VALUE'] = (int)$row['ONLY_NEW_VALUE'];
    $result[$index]['QOS'] = (int)$row['QOS'];
    $result[$index]['RETAIN'] = (int)$row['RETAIN'];
    $result[$index]['DIRECTION_LABEL'] = (int)$row['READONLY'] ? 'R' : 'R/W';
    $result[$index]['EDIT_URL'] = '?view_mode=edit_mqtt&id=' . (int)$row['ID'];
    $result[$index]['DELETE_URL'] = '?view_mode=delete_mqtt&id=' . (int)$row['ID'];

    $text_fields = array(
        'TITLE_DISPLAY', 'PATH_DISPLAY', 'PATH_FULL', 'VALUE_DISPLAY', 'VALUE_FULL',
        'UPDATED_HUMAN', 'UPDATED_EXACT', 'LINKED_DEVICE', 'LINKED_TARGET', 'LINKED_KIND',
        'LINKED_FULL', 'LOCATION_TITLE', 'WRITE_DISPLAY', 'DIRECTION_LABEL'
    );
    foreach ($text_fields as $field) {
        $result[$index][$field] = md_mqtt_h($result[$index][$field]);
    }
}

$groups = array();
$groups_limited = 0;
if ($group_by === 'path') {
    $group_rows = SQLSelect(
        "SELECT SUBSTRING_INDEX(TRIM(BOTH '/' FROM PATH), '/', 1) AS GROUP_KEY,
                COUNT(*) AS CNT,
                MAX(UPDATED) AS LAST_UPDATE
           FROM mqtt
          WHERE TRIM(BOTH '/' FROM PATH)<>''
          GROUP BY GROUP_KEY
          ORDER BY CNT DESC, GROUP_KEY
          LIMIT 301"
    );
} else {
    $group_rows = SQLSelect(
        "SELECT LINKED_OBJECT AS GROUP_KEY,
                COUNT(*) AS CNT,
                MAX(UPDATED) AS LAST_UPDATE
           FROM mqtt
          WHERE LINKED_OBJECT<>''
          GROUP BY LINKED_OBJECT
          ORDER BY CNT DESC, LINKED_OBJECT
          LIMIT 301"
    );
}
if (count($group_rows) > 300) {
    $groups_limited = 1;
    array_pop($group_rows);
}

$group_objects = array();
foreach ($group_rows as $group_row) {
    if ($group_by === 'device' && $group_row['GROUP_KEY'] !== '') {
        $group_objects[] = $group_row['GROUP_KEY'];
    }
}
$group_device_titles = md_mqtt_device_map($group_objects);

$base_params = array(
    'group_by' => $group_by,
    'group' => $group,
    'q' => $q,
    'linked' => $linked,
    'location_id' => $location_id ?: '',
    'path_prefix' => $path_prefix,
    'sort' => $sort,
);

foreach ($group_rows as $group_row) {
    $key = (string)$group_row['GROUP_KEY'];
    if ($key === '') {
        continue;
    }
    $is_selected = $group_by === 'path'
        ? ($group === $key)
        : ($group === $key);
    $title = $group_by === 'device' && isset($group_device_titles[$key])
        ? $group_device_titles[$key]
        : $key;
    $params = $base_params;
    $params['group'] = $key;
    $params['group_by'] = $group_by;
    $groups[] = array(
        'KEY' => md_mqtt_h($key),
        'TITLE' => md_mqtt_h($title),
        'COUNT' => (int)$group_row['CNT'],
        'LAST_UPDATE_HUMAN' => md_mqtt_h(md_mqtt_relative_time((string)$group_row['LAST_UPDATE'])),
        'URL' => md_mqtt_query_url($params),
        'SELECTED' => $is_selected ? 1 : 0,
        'IS_DEVICE' => $group_by === 'device' ? 1 : 0,
        'ICON' => $group_by === 'device' ? 'device-desktop' : 'folder',
    );
}

$selected_title = 'Все топики';
$selected_meta = 'Все устройства и пути';
if ($group === '__unlinked__') {
    $selected_title = 'Без привязки';
    $selected_meta = 'Топики без объекта и свойства';
} elseif ($group !== '') {
    if ($group_by === 'device') {
        $selected_title = isset($group_device_titles[$group]) ? $group_device_titles[$group] : $group;
        $selected_meta = 'Устройство: ' . $group;
    } else {
        $selected_title = $group;
        $selected_meta = 'Ветка пути';
    }
}

$locations = SQLSelect("SELECT ID, TITLE FROM locations ORDER BY TITLE");
foreach ($locations as $index => $location) {
    $locations[$index]['TITLE'] = md_mqtt_h($location['TITLE']);
    $locations[$index]['SELECTED'] = ((int)$location['ID'] === $location_id) ? 1 : 0;
}

$page_items = md_mqtt_pagination_items($page, $pages);
$page_params = $base_params;
foreach ($page_items as $index => $item) {
    if ($item['ELLIPSIS']) {
        continue;
    }
    $params = $page_params;
    $params['page'] = $item['NUM'];
    $page_items[$index]['URL'] = md_mqtt_query_url($params);
}

$prev_url = '';
if ($page > 1) {
    $params = $page_params;
    $params['page'] = $page - 1;
    $prev_url = md_mqtt_query_url($params);
}
$next_url = '';
if ($page < $pages) {
    $params = $page_params;
    $params['page'] = $page + 1;
    $next_url = md_mqtt_query_url($params);
}

$filters = array();
if ($q !== '') {
    $params = $base_params;
    unset($params['q']);
    $filters[] = array('LABEL' => 'Поиск: ' . $q, 'URL' => md_mqtt_query_url($params));
}
if ($linked !== '') {
    $params = $base_params;
    unset($params['linked']);
    $filters[] = array(
        'LABEL' => $linked === '1' ? 'С привязкой' : 'Без привязки',
        'URL' => md_mqtt_query_url($params),
    );
}
if ($location_id > 0 && isset($location_titles[$location_id])) {
    $params = $base_params;
    unset($params['location_id']);
    $filters[] = array(
        'LABEL' => 'Локация: ' . $location_titles[$location_id],
        'URL' => md_mqtt_query_url($params),
    );
}
if ($path_prefix !== '') {
    $params = $base_params;
    unset($params['path_prefix']);
    $filters[] = array('LABEL' => 'Путь: ' . $path_prefix, 'URL' => md_mqtt_query_url($params));
}
foreach ($filters as $index => $filter) {
    $filters[$index]['LABEL'] = md_mqtt_h($filter['LABEL']);
}

$out['RESULT'] = $result;
$out['GROUPS'] = $groups;
$out['GROUPS_LIMITED'] = $groups_limited;
$out['GROUP_BY'] = $group_by;
$out['GROUP_BY_DEVICE'] = $group_by === 'device' ? 1 : 0;
$out['GROUP_BY_PATH'] = $group_by === 'path' ? 1 : 0;
$out['GROUP_MODE_TITLE'] = $group_by === 'device' ? 'Устройства' : 'Пути';
$out['SIDEBAR_TITLE'] = $group_by === 'device' ? 'Устройства' : 'Пути';
$out['GROUP'] = md_mqtt_h($group);
$out['SELECTED_GROUP_TITLE'] = md_mqtt_h($selected_title);
$out['SELECTED_GROUP_META'] = md_mqtt_h($selected_meta);
$out['IS_ALL_VIEW'] = ($group === '' && $q === '' && $linked === '' && $location_id === 0 && $path_prefix === '') ? 1 : 0;
$out['IS_UNLINKED_VIEW'] = $group === '__unlinked__' ? 1 : 0;
$out['IS_GROUP_ALL'] = $group === '' ? 1 : 0;
$out['IS_GROUP_UNLINKED'] = $group === '__unlinked__' ? 1 : 0;

$all_params = $base_params;
$all_params['group'] = '';
$out['ALL_URL'] = md_mqtt_query_url($all_params);
$unlinked_params = $base_params;
$unlinked_params['group_by'] = 'device';
$unlinked_params['group'] = '__unlinked__';
$out['UNLINKED_URL'] = md_mqtt_query_url($unlinked_params);
$out['RESET_URL'] = md_mqtt_query_url(array('group_by' => $group_by));
$mode_params = array('q' => $q, 'linked' => $linked, 'location_id' => $location_id ?: '', 'sort' => $sort);
$mode_params['group_by'] = 'device';
$out['MODE_DEVICE_URL'] = md_mqtt_query_url($mode_params);
$mode_params['group_by'] = 'path';
$out['MODE_PATH_URL'] = md_mqtt_query_url($mode_params);

$out['TOTAL'] = $total;
$out['ALL_TOTAL'] = $all_total;
$out['LINKED_TOTAL'] = $linked_total;
$out['UNLINKED_TOTAL'] = $unlinked_total;
$out['DEVICES_TOTAL'] = $devices_total;
$out['ACTIVE_24H'] = $active_total;
$out['LAST_UPDATE_HUMAN'] = md_mqtt_h(md_mqtt_relative_time($last_update));
$out['LAST_UPDATE_EXACT'] = md_mqtt_h(md_mqtt_date_time($last_update));
$out['PAGE'] = $page;
$out['PAGES_TOTAL'] = $pages;
$out['PAGE_ITEMS'] = $page_items;
$out['PAGE_PREV'] = $prev_url;
$out['PAGE_NEXT'] = $next_url;
$out['SHOW_FROM'] = $total ? $offset + 1 : 0;
$out['SHOW_TO'] = min($offset + $per_page, $total);
$out['PER_PAGE'] = $per_page;
$out['HAS_PAGES'] = $pages > 1 ? 1 : 0;
$out['FILTERS'] = $filters;
$out['HAS_FILTERS'] = count($filters) ? 1 : 0;
$out['FILTER_Q'] = md_mqtt_h($q);
$out['FILTER_LINKED'] = $linked;
$out['FILTER_LOCATION_ID'] = $location_id;
$out['FILTER_SORT'] = $sort;
$out['FILTER_PATH_PREFIX'] = md_mqtt_h($path_prefix);
$out['HAS_PATH_PREFIX'] = $path_prefix !== '' ? 1 : 0;
$out['LOCATIONS'] = $locations;

$broker_host = !empty($this->config['MQTT_HOST']) ? $this->config['MQTT_HOST'] : 'localhost';
$broker_port = !empty($this->config['MQTT_PORT']) ? $this->config['MQTT_PORT'] : '1883';
$out['MQTT_BROKER_LABEL'] = md_mqtt_h($broker_host . ':' . $broker_port);
$out['MQTT_QUERY_LABEL'] = md_mqtt_h(!empty($this->config['MQTT_QUERY']) ? $this->config['MQTT_QUERY'] : '/var/now/#');
$out['MQTT_AUTH_STATE'] = !empty($this->config['MQTT_AUTH']) ? 1 : 0;
$out['MQTT_TLS_STATE'] = !empty($this->config['MQTT_TLS_ENABLE']) ? 1 : 0;
