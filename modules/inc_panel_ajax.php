<?php

global $op;

if (!headers_sent()) {
    header("HTTP/1.0: 200 OK\n");
    header('Content-Type: text/html; charset=utf-8');
}

function evalConsole($code, $print = 0)
{
    if ($print == 1) {
        if (mb_substr($code, -1) == ';') {
            $code = mb_substr($code, 0, -1);
        }
        return eval('print_r(' . $code . ');');
    } else {
        setEvalCode($code);
        $eval_result = eval($code);
        setEvalCode();
        return $eval_result;
    }
}

if ($op == 'dismiss_notification') {
    $id = gr('id', 'int');
    SQLExec("UPDATE module_notifications SET IS_READ=1 WHERE ID=" . $id);
    echo "OK";
    exit;
}

if ($op == 'console') {
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
    error_reporting(E_ALL);

    global $command;
    $code = explode('PHP_EOL', $command);

    foreach ($code as $value) {
        $value = trim($value);
        if ($value === '') {
            continue;
        }
        if (substr(mb_strtolower($value), 0, 4) == 'echo' || mb_substr($value, 0, 1) == '$' || preg_match('/include/', $value)) {
            evalConsole(trim($value));
        } else {
            evalConsole(trim($value), 1);
        }
    }

}

if ($op == 'filter') {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }

    $title = trim((string)gr('title', 'trim'));
    $limit = (int)gr('limit', 'int');
    if ($limit <= 0) {
        $limit = 60;
    } else {
        $limit = min($limit, 60);
    }

    $sections = [];
    $counts = [];
    $seen = [];

    $makeUrl = static function ($url) {
        return (string)$url;
    };

    $addResult = static function ($section, $type, $title, $url, $description = '', $meta = [], $action = '') use (&$sections, &$counts, &$seen, $makeUrl) {
        $title = trim((string)$title);
        if ($title === '') {
            return;
        }
        $url = $makeUrl($url);
        $key = $type . '|' . $title . '|' . $url;
        if (isset($seen[$key])) {
            return;
        }
        $seen[$key] = true;
        if (!isset($sections[$section])) {
            $sections[$section] = [];
            $counts[$section] = 0;
        }
        $sections[$section][] = [
            'type' => $type,
            'title' => $title,
            'url' => $url,
            'description' => trim((string)$description),
            'meta' => array_values(array_filter(array_map('strval', (array)$meta), static function ($value) {
                return trim($value) !== '';
            })),
            'action' => $action,
        ];
        $counts[$section]++;
    };

    $like = DBSafe($title);

    if (mb_strlen($title) <= 2) {
        echo json_encode([
            'ok' => true,
            'query' => $title,
            'total' => 0,
            'sections' => [],
            'counts' => [],
            'message' => 'Введите более 2-х символов для поиска',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if (preg_match('/^(.+)\.$/', $title, $m)) {
        $exactTitle = DBSafe($m[1]);
        $object = SQLSelectOne("SELECT ID, TITLE, DESCRIPTION, CLASS_ID FROM objects WHERE TITLE LIKE '" . $exactTitle . "'");
        if (!empty($object['ID'])) {
            $class = SQLSelectOne("SELECT ID, TITLE, DESCRIPTION FROM classes WHERE ID='" . (int)$object['CLASS_ID'] . "'");
            if (!empty($class['ID'])) {
                $addResult('Точное совпадение', 'class', $class['TITLE'], '/panel/class/' . (int)$class['ID'] . '.html', $class['DESCRIPTION'] ?? '', ['Класс объекта']);
            }
            $addResult('Точное совпадение', 'object', $object['TITLE'], '/panel/class/' . (int)$object['CLASS_ID'] . '/object/' . (int)$object['ID'] . '.html', $object['DESCRIPTION'] ?? '', ['Объект']);

            $properties = SQLSelect("SELECT properties.ID, properties.TITLE, properties.DESCRIPTION, properties.CLASS_ID, properties.OBJECT_ID, classes.TITLE AS CLASS, objects.TITLE AS OBJECT, objects.CLASS_ID AS OBJECT_CLASS_ID FROM properties LEFT JOIN classes ON properties.CLASS_ID=classes.ID LEFT JOIN objects ON properties.OBJECT_ID=objects.ID WHERE (properties.OBJECT_ID = '" . (int)$object['ID'] . "' OR properties.CLASS_ID = '" . (int)$object['CLASS_ID'] . "') ORDER BY properties.TITLE LIMIT " . $limit);
            foreach ($properties as $property) {
                $propertyObjectId = !empty($property['OBJECT_ID']) ? (int)$property['OBJECT_ID'] : (int)$object['ID'];
                $propertyClassId = !empty($property['OBJECT_CLASS_ID']) ? (int)$property['OBJECT_CLASS_ID'] : (int)$object['CLASS_ID'];
                $owner = !empty($property['OBJECT']) ? $property['OBJECT'] : ($object['TITLE'] ?? $property['CLASS']);
                $addResult('Связанные свойства', 'property', $owner . '.' . $property['TITLE'], '/panel/class/' . $propertyClassId . '/object/' . $propertyObjectId . '/properties.html', $property['DESCRIPTION'] ?? '', ['Свойство']);
            }

            $methods = SQLSelect("SELECT methods.ID, methods.TITLE, methods.DESCRIPTION, methods.OBJECT_ID, methods.CLASS_ID, classes.TITLE AS CLASS, objects.TITLE AS OBJECT, objects.CLASS_ID AS OBJECT_CLASS_ID FROM methods LEFT JOIN classes ON methods.CLASS_ID=classes.ID LEFT JOIN objects ON methods.OBJECT_ID=objects.ID WHERE (methods.OBJECT_ID = '" . (int)$object['ID'] . "' OR methods.CLASS_ID = '" . (int)$object['CLASS_ID'] . "') ORDER BY methods.OBJECT_ID DESC, methods.TITLE LIMIT " . $limit);
            foreach ($methods as $method) {
                if (!empty($method['OBJECT_ID'])) {
                    $addResult('Связанные методы', 'method', $method['OBJECT'] . '.' . $method['TITLE'], '/panel/class/' . (int)$method['OBJECT_CLASS_ID'] . '/object/' . (int)$method['OBJECT_ID'] . '/methods/' . (int)$method['ID'] . '.html', $method['DESCRIPTION'] ?? '', ['Метод объекта']);
                } else {
                    $addResult('Связанные методы', 'method', $method['CLASS'] . '.' . $method['TITLE'], '/panel/class/' . (int)$method['CLASS_ID'] . '/methods/' . (int)$method['ID'] . '.html', $method['DESCRIPTION'] ?? '', ['Метод класса']);
                }
            }
        }

        $class = SQLSelectOne("SELECT ID, TITLE, DESCRIPTION FROM classes WHERE TITLE LIKE '" . $exactTitle . "'");
        if (!empty($class['ID'])) {
            $addResult('Точное совпадение', 'class', $class['TITLE'], '/panel/class/' . (int)$class['ID'] . '.html', $class['DESCRIPTION'] ?? '', ['Класс']);
        }
    }

    $modules = SQLSelect("SELECT NAME, TITLE FROM project_modules WHERE TITLE LIKE '%" . $like . "%' AND HIDDEN=0 ORDER BY TITLE LIMIT " . $limit);
    foreach ($modules as $item) {
        $addResult('Модули', 'module', processTitle($item['TITLE']), '?md=panel&action=' . urlencode($item['NAME']), '', ['Модуль']);
    }

    $classes = SQLSelect("SELECT ID, TITLE, DESCRIPTION FROM classes WHERE TITLE LIKE '%" . $like . "%' OR DESCRIPTION LIKE '%" . $like . "%' ORDER BY TITLE LIMIT " . $limit);
    foreach ($classes as $class) {
        $addResult('Классы', 'class', $class['TITLE'], '/panel/class/' . (int)$class['ID'] . '.html', $class['DESCRIPTION'] ?? '', ['Класс']);
    }

    $objects = SQLSelect("SELECT objects.ID, objects.TITLE, objects.DESCRIPTION, objects.CLASS_ID, classes.TITLE AS CLASS FROM objects LEFT JOIN classes ON objects.CLASS_ID=classes.ID WHERE (objects.TITLE LIKE '%" . $like . "%' OR objects.DESCRIPTION LIKE '%" . $like . "%') ORDER BY objects.TITLE LIMIT " . $limit);
    foreach ($objects as $object) {
        $addResult('Объекты', 'object', $object['TITLE'], '/panel/class/' . (int)$object['CLASS_ID'] . '/object/' . (int)$object['ID'] . '.html', $object['DESCRIPTION'] ?? '', [$object['CLASS'] ?? '']);
    }

    $properties = SQLSelect("SELECT properties.ID, properties.CLASS_ID, properties.TITLE, properties.DESCRIPTION, objects.CLASS_ID AS OBJECT_CLASS_ID, objects.ID AS OBJECT_ID, classes.TITLE AS CLASS, objects.TITLE AS OBJECT, pvalues.VALUE AS VALUE FROM properties LEFT JOIN classes ON properties.CLASS_ID=classes.ID LEFT JOIN pvalues ON (properties.ID=pvalues.PROPERTY_ID AND (properties.OBJECT_ID=pvalues.OBJECT_ID OR properties.OBJECT_ID=0)) LEFT JOIN objects ON (properties.OBJECT_ID=objects.ID OR pvalues.OBJECT_ID=objects.ID) WHERE (properties.TITLE LIKE '%" . $like . "%' OR properties.DESCRIPTION LIKE '%" . $like . "%' OR pvalues.VALUE LIKE '%" . $like . "%') ORDER BY properties.TITLE LIMIT " . $limit);
    foreach ($properties as $property) {
        $owner = !empty($property['OBJECT']) ? $property['OBJECT'] : $property['CLASS'];
        $url = !empty($property['OBJECT_ID'])
            ? '/panel/class/' . (int)$property['OBJECT_CLASS_ID'] . '/object/' . (int)$property['OBJECT_ID'] . '/properties.html'
            : '/panel/class/' . (int)$property['CLASS_ID'] . '/properties.html';
        $meta = ['Свойство'];
        if (isset($property['VALUE']) && $property['VALUE'] !== '') {
            $meta[] = mb_substr((string)$property['VALUE'], 0, 80);
        }
        $addResult('Свойства', 'property', $owner . '.' . $property['TITLE'], $url, $property['DESCRIPTION'] ?? '', $meta);
    }

    $methods = SQLSelect("SELECT methods.ID, methods.TITLE, methods.OBJECT_ID, methods.DESCRIPTION, objects.CLASS_ID AS OBJECT_CLASS_ID, methods.CLASS_ID, classes.TITLE AS CLASS, objects.TITLE AS OBJECT FROM methods LEFT JOIN classes ON methods.CLASS_ID=classes.ID LEFT JOIN objects ON methods.OBJECT_ID=objects.ID WHERE (methods.TITLE LIKE '%" . $like . "%' OR methods.CODE LIKE '%" . $like . "%' OR methods.DESCRIPTION LIKE '%" . $like . "%') ORDER BY methods.TITLE LIMIT " . $limit);
    foreach ($methods as $method) {
        if (!empty($method['OBJECT_ID'])) {
            $addResult('Методы', 'method', $method['OBJECT'] . '.' . $method['TITLE'], '/panel/class/' . (int)$method['OBJECT_CLASS_ID'] . '/object/' . (int)$method['OBJECT_ID'] . '/methods/' . (int)$method['ID'] . '.html', $method['DESCRIPTION'] ?? '', ['Метод объекта']);
        } else {
            $addResult('Методы', 'method', $method['CLASS'] . '.' . $method['TITLE'], '/panel/class/' . (int)$method['CLASS_ID'] . '/methods/' . (int)$method['ID'] . '.html', $method['DESCRIPTION'] ?? '', ['Метод класса']);
        }
    }

    $scripts = SQLSelect("SELECT ID, TITLE FROM scripts WHERE (TITLE LIKE '%" . $like . "%' OR CODE LIKE '%" . $like . "%') ORDER BY TITLE LIMIT " . $limit);
    foreach ($scripts as $script) {
        $addResult('Скрипты', 'script', $script['TITLE'], '/panel/script/' . (int)$script['ID'] . '.html', '', ['Скрипт']);
    }

    $patterns = SQLSelect("SELECT ID, TITLE FROM patterns WHERE (TITLE LIKE '%" . $like . "%' OR SCRIPT LIKE '%" . $like . "%' OR PATTERN LIKE '%" . $like . "%') ORDER BY TITLE LIMIT " . $limit);
    foreach ($patterns as $pattern) {
        $addResult('Шаблоны поведения', 'pattern', $pattern['TITLE'], '/panel/pattern/' . (int)$pattern['ID'] . '.html', '', ['Pattern']);
    }

    if (file_exists(DIR_MODULES . 'zwave/zwave.class.php')) {
        $devices = SQLSelect("SELECT ID, DEVICE_ID, TITLE, LINKED_OBJECT, LINKED_PROPERTY FROM zwave_properties WHERE (TITLE LIKE '%" . $like . "%' OR LINKED_OBJECT LIKE '%" . $like . "%' OR LINKED_PROPERTY LIKE '%" . $like . "%') ORDER BY TITLE LIMIT " . $limit);
        foreach ($devices as $device) {
            $addResult('Z-Wave', 'zwave', $device['TITLE'], '/panel/zwave/' . (int)$device['DEVICE_ID'] . '.html', '', [$device['LINKED_OBJECT'] ?? '', $device['LINKED_PROPERTY'] ?? '']);
        }
    }

    if (file_exists(DIR_MODULES . 'devices/devices.class.php')) {
        $devices = SQLSelect("SELECT ID, TITLE, LINKED_OBJECT FROM devices WHERE (TITLE LIKE '%" . $like . "%' OR LINKED_OBJECT LIKE '%" . $like . "%') ORDER BY TITLE LIMIT " . $limit);
        foreach ($devices as $device) {
            $addResult('Простые устройства', 'device', $device['TITLE'], '/panel/devices/' . (int)$device['ID'] . '.html', '', [$device['LINKED_OBJECT'] ?? '']);
        }
    }

    if (file_exists(DIR_MODULES . 'app_gpstrack/app_gpstrack.class.php')) {
        $actions = SQLSelect("SELECT gpsactions.ID, gpslocations.TITLE, users.NAME FROM gpsactions LEFT JOIN users ON gpsactions.USER_ID=users.ID LEFT JOIN gpslocations ON gpsactions.LOCATION_ID=gpslocations.ID WHERE (gpslocations.TITLE LIKE '%" . $like . "%' OR gpsactions.CODE LIKE '%" . $like . "%') ORDER BY gpslocations.TITLE LIMIT " . $limit);
        foreach ($actions as $action) {
            $addResult('GPS', 'gps', $action['TITLE'], '/panel/app_gpstrack/action_' . (int)$action['ID'] . '.html', '', [$action['NAME'] ?? '']);
        }
    }

    $total = 0;
    foreach ($sections as $items) {
        $total += count($items);
    }

    echo json_encode([
        'ok' => true,
        'query' => $title,
        'total' => $total,
        'counts' => $counts,
        'sections' => $sections,
        'message' => $total ? '' : 'Ничего не найдено',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
exit;
