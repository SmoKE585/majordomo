<?php

global $op;

if (!headers_sent()) {
    header("HTTP/1.0 200 OK\n");
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
    $title = preg_replace('/\s+/u', ' ', $title);
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

    $normalizeSearchText = static function ($value) {
        $value = mb_strtolower(trim((string)$value));
        $value = preg_replace('/\s*\.\s*/u', '.', $value);
        $value = str_replace(['_', '-', '/', '\\', ':'], ' ', $value);
        $value = str_replace('.', ' ', $value);
        $value = preg_replace('/\s+/u', ' ', $value);
        return trim($value);
    };

    $normalizeFullTitle = static function ($value) {
        $value = mb_strtolower(trim((string)$value));
        $value = preg_replace('/\s*\.\s*/u', '.', $value);
        $value = preg_replace('/\s+/u', ' ', $value);
        return trim($value);
    };

    $queryNormalized = $normalizeSearchText($title);
    $queryFullNormalized = $normalizeFullTitle($title);
    $queryTokens = preg_split('/[\s\._:\-\/\\\\]+/u', $queryNormalized, -1, PREG_SPLIT_NO_EMPTY);
    $queryTokens = array_values(array_unique(array_filter(array_map('trim', $queryTokens), static function ($value) {
        return $value !== '';
    })));

    $buildTokenLikeCondition = static function (array $fields, array $tokens) {
        if (!$tokens) {
            return '1=1';
        }

        $groups = [];
        foreach ($tokens as $token) {
            $tokenSafe = DBSafe($token);
            $parts = [];
            foreach ($fields as $field) {
                $parts[] = $field . " LIKE '%" . $tokenSafe . "%'";
            }
            $groups[] = '(' . implode(' OR ', $parts) . ')';
        }

        return implode(' AND ', $groups);
    };

    $matchesAllTokens = static function ($haystack, array $tokens) {
        if (!$tokens) {
            return true;
        }
        foreach ($tokens as $token) {
            if (mb_strpos($haystack, $token) === false) {
                return false;
            }
        }
        return true;
    };

    $scoreResult = static function ($primaryTitle, $description = '', $meta = [], $aliases = []) use ($normalizeSearchText, $normalizeFullTitle, $matchesAllTokens, $queryNormalized, $queryFullNormalized, $queryTokens) {
        $score = 0;
        $candidates = array_merge([(string)$primaryTitle], (array)$aliases);

        foreach ($candidates as $candidate) {
            $candidateText = $normalizeSearchText($candidate);
            $candidateFull = $normalizeFullTitle($candidate);

            if ($candidateFull !== '' && $candidateFull === $queryFullNormalized) {
                $score = max($score, 1000);
            }
            if ($candidateText !== '' && $candidateText === $queryNormalized) {
                $score = max($score, 950);
            }
            if ($queryFullNormalized !== '' && mb_strpos($candidateFull, $queryFullNormalized) === 0) {
                $score = max($score, 900);
            }
            if ($queryNormalized !== '' && mb_strpos($candidateText, $queryNormalized) === 0) {
                $score = max($score, 850);
            }
            if ($candidateText !== '' && $matchesAllTokens($candidateText, $queryTokens)) {
                $score = max($score, 780);
            }
            if ($queryFullNormalized !== '' && mb_strpos($candidateFull, $queryFullNormalized) !== false) {
                $score = max($score, 720);
            }
            if ($queryNormalized !== '' && mb_strpos($candidateText, $queryNormalized) !== false) {
                $score = max($score, 680);
            }
        }

        $descriptionText = $normalizeSearchText($description);
        if ($descriptionText !== '') {
            if ($matchesAllTokens($descriptionText, $queryTokens)) {
                $score = max($score, 320);
            } elseif ($queryNormalized !== '' && mb_strpos($descriptionText, $queryNormalized) !== false) {
                $score = max($score, 260);
            }
        }

        foreach ((array)$meta as $metaItem) {
            $metaText = $normalizeSearchText($metaItem);
            if ($metaText === '') {
                continue;
            }
            if ($matchesAllTokens($metaText, $queryTokens)) {
                $score = max($score, 220);
            } elseif ($queryNormalized !== '' && mb_strpos($metaText, $queryNormalized) !== false) {
                $score = max($score, 180);
            }
        }

        return $score;
    };

    $addResult = static function ($section, $type, $title, $url, $description = '', $meta = [], $action = '', $aliases = []) use (&$sections, &$counts, &$seen, $makeUrl, $scoreResult) {
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
        $score = $scoreResult($title, $description, $meta, $aliases);
        $sections[$section][] = [
            'type' => $type,
            'title' => $title,
            'url' => $url,
            'description' => trim((string)$description),
            'meta' => array_values(array_filter(array_map('strval', (array)$meta), static function ($value) {
                return trim($value) !== '';
            })),
            'action' => $action,
            '_score' => $score,
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

    $objectMatch = [];

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

    if (preg_match('/^([^\.]+)\.([^\.]+)$/u', $title, $m)) {
        $objectMatch = [
            'owner' => trim($m[1]),
            'member' => trim($m[2]),
        ];
        $exactOwner = DBSafe($objectMatch['owner']);
        $exactMember = DBSafe($objectMatch['member']);

        $object = SQLSelectOne("SELECT ID, TITLE, DESCRIPTION, CLASS_ID FROM objects WHERE TITLE LIKE '" . $exactOwner . "'");
        if (!empty($object['ID'])) {
            $property = SQLSelectOne("SELECT properties.ID, properties.TITLE, properties.DESCRIPTION, properties.OBJECT_ID, properties.CLASS_ID FROM properties WHERE properties.TITLE LIKE '" . $exactMember . "' AND (properties.OBJECT_ID='" . (int)$object['ID'] . "' OR properties.CLASS_ID='" . (int)$object['CLASS_ID'] . "') ORDER BY properties.OBJECT_ID DESC LIMIT 1");
            if (!empty($property['ID'])) {
                $propertyObjectId = !empty($property['OBJECT_ID']) ? (int)$property['OBJECT_ID'] : (int)$object['ID'];
                $propertyClassId = (int)$object['CLASS_ID'];
                $addResult('Точное совпадение', 'property', $object['TITLE'] . '.' . $property['TITLE'], '/panel/class/' . $propertyClassId . '/object/' . $propertyObjectId . '/properties.html', $property['DESCRIPTION'] ?? '', ['Свойство'], '', [$object['TITLE'], $property['TITLE']]);
            }

            $method = SQLSelectOne("SELECT methods.ID, methods.TITLE, methods.DESCRIPTION, methods.OBJECT_ID, methods.CLASS_ID FROM methods WHERE methods.TITLE LIKE '" . $exactMember . "' AND (methods.OBJECT_ID='" . (int)$object['ID'] . "' OR methods.CLASS_ID='" . (int)$object['CLASS_ID'] . "') ORDER BY methods.OBJECT_ID DESC LIMIT 1");
            if (!empty($method['ID'])) {
                if (!empty($method['OBJECT_ID'])) {
                    $addResult('Точное совпадение', 'method', $object['TITLE'] . '.' . $method['TITLE'], '/panel/class/' . (int)$object['CLASS_ID'] . '/object/' . (int)$object['ID'] . '/methods/' . (int)$method['ID'] . '.html', $method['DESCRIPTION'] ?? '', ['Метод объекта'], '', [$object['TITLE'], $method['TITLE']]);
                } else {
                    $addResult('Точное совпадение', 'method', $object['TITLE'] . '.' . $method['TITLE'], '/panel/class/' . (int)$object['CLASS_ID'] . '/methods/' . (int)$method['ID'] . '.html', $method['DESCRIPTION'] ?? '', ['Метод класса'], '', [$object['TITLE'], $method['TITLE']]);
                }
            }
        }

        $class = SQLSelectOne("SELECT ID, TITLE, DESCRIPTION FROM classes WHERE TITLE LIKE '" . $exactOwner . "'");
        if (!empty($class['ID'])) {
            $property = SQLSelectOne("SELECT ID, TITLE, DESCRIPTION, CLASS_ID FROM properties WHERE CLASS_ID='" . (int)$class['ID'] . "' AND TITLE LIKE '" . $exactMember . "' LIMIT 1");
            if (!empty($property['ID'])) {
                $addResult('Точное совпадение', 'property', $class['TITLE'] . '.' . $property['TITLE'], '/panel/class/' . (int)$class['ID'] . '/properties.html', $property['DESCRIPTION'] ?? '', ['Свойство класса'], '', [$class['TITLE'], $property['TITLE']]);
            }
            $method = SQLSelectOne("SELECT ID, TITLE, DESCRIPTION, CLASS_ID FROM methods WHERE CLASS_ID='" . (int)$class['ID'] . "' AND TITLE LIKE '" . $exactMember . "' LIMIT 1");
            if (!empty($method['ID'])) {
                $addResult('Точное совпадение', 'method', $class['TITLE'] . '.' . $method['TITLE'], '/panel/class/' . (int)$class['ID'] . '/methods/' . (int)$method['ID'] . '.html', $method['DESCRIPTION'] ?? '', ['Метод класса'], '', [$class['TITLE'], $method['TITLE']]);
            }
        }
    }

    $moduleCondition = $buildTokenLikeCondition(['TITLE', 'NAME'], $queryTokens);
    $classCondition = $buildTokenLikeCondition(['classes.TITLE', 'classes.DESCRIPTION'], $queryTokens);
    $objectCondition = $buildTokenLikeCondition(['objects.TITLE', 'objects.DESCRIPTION', 'classes.TITLE'], $queryTokens);
    $propertyCondition = $buildTokenLikeCondition([
        'properties.TITLE',
        'properties.DESCRIPTION',
        'pvalues.VALUE',
        'objects.TITLE',
        'classes.TITLE',
        "CONCAT(IFNULL(objects.TITLE, ''), '.', properties.TITLE)",
        "CONCAT(IFNULL(classes.TITLE, ''), '.', properties.TITLE)"
    ], $queryTokens);
    $methodCondition = $buildTokenLikeCondition([
        'methods.TITLE',
        'methods.DESCRIPTION',
        'methods.CODE',
        'objects.TITLE',
        'classes.TITLE',
        "CONCAT(IFNULL(objects.TITLE, ''), '.', methods.TITLE)",
        "CONCAT(IFNULL(classes.TITLE, ''), '.', methods.TITLE)"
    ], $queryTokens);
    $scriptCondition = $buildTokenLikeCondition(['scripts.TITLE', 'scripts.CODE'], $queryTokens);

    $modules = SQLSelect("SELECT NAME, TITLE FROM project_modules WHERE HIDDEN=0 AND (TITLE LIKE '%" . $like . "%' OR NAME LIKE '%" . $like . "%' OR (" . $moduleCondition . ")) ORDER BY TITLE LIMIT " . $limit);
    foreach ($modules as $item) {
        $moduleTitle = processTitle($item['TITLE']);
        $addResult('Модули', 'module', $moduleTitle, '?md=panel&action=' . urlencode($item['NAME']), '', ['Модуль', $item['NAME']], '', [$item['NAME'], $moduleTitle]);
    }

    $classes = SQLSelect("SELECT ID, TITLE, DESCRIPTION FROM classes WHERE (TITLE LIKE '%" . $like . "%' OR DESCRIPTION LIKE '%" . $like . "%' OR (" . $classCondition . ")) ORDER BY TITLE LIMIT " . $limit);
    foreach ($classes as $class) {
        $addResult('Классы', 'class', $class['TITLE'], '/panel/class/' . (int)$class['ID'] . '.html', $class['DESCRIPTION'] ?? '', ['Класс']);
    }

    $objects = SQLSelect("SELECT objects.ID, objects.TITLE, objects.DESCRIPTION, objects.CLASS_ID, classes.TITLE AS CLASS FROM objects LEFT JOIN classes ON objects.CLASS_ID=classes.ID WHERE (objects.TITLE LIKE '%" . $like . "%' OR objects.DESCRIPTION LIKE '%" . $like . "%' OR (" . $objectCondition . ")) ORDER BY objects.TITLE LIMIT " . $limit);
    foreach ($objects as $object) {
        $addResult('Объекты', 'object', $object['TITLE'], '/panel/class/' . (int)$object['CLASS_ID'] . '/object/' . (int)$object['ID'] . '.html', $object['DESCRIPTION'] ?? '', [$object['CLASS'] ?? ''], '', [$object['CLASS'] ?? '']);
    }

    $properties = SQLSelect("SELECT properties.ID, properties.CLASS_ID, properties.TITLE, properties.DESCRIPTION, objects.CLASS_ID AS OBJECT_CLASS_ID, objects.ID AS OBJECT_ID, classes.TITLE AS CLASS, objects.TITLE AS OBJECT, pvalues.VALUE AS VALUE FROM properties LEFT JOIN classes ON properties.CLASS_ID=classes.ID LEFT JOIN pvalues ON (properties.ID=pvalues.PROPERTY_ID AND (properties.OBJECT_ID=pvalues.OBJECT_ID OR properties.OBJECT_ID=0)) LEFT JOIN objects ON (properties.OBJECT_ID=objects.ID OR pvalues.OBJECT_ID=objects.ID) WHERE (properties.TITLE LIKE '%" . $like . "%' OR properties.DESCRIPTION LIKE '%" . $like . "%' OR pvalues.VALUE LIKE '%" . $like . "%' OR CONCAT(IFNULL(objects.TITLE, ''), '.', properties.TITLE) LIKE '%" . $like . "%' OR CONCAT(IFNULL(classes.TITLE, ''), '.', properties.TITLE) LIKE '%" . $like . "%' OR (" . $propertyCondition . ")) ORDER BY properties.TITLE LIMIT " . $limit);
    foreach ($properties as $property) {
        $owner = !empty($property['OBJECT']) ? $property['OBJECT'] : $property['CLASS'];
        $url = !empty($property['OBJECT_ID'])
            ? '/panel/class/' . (int)$property['OBJECT_CLASS_ID'] . '/object/' . (int)$property['OBJECT_ID'] . '/properties.html'
            : '/panel/class/' . (int)$property['CLASS_ID'] . '/properties.html';
        $meta = ['Свойство'];
        if (isset($property['VALUE']) && $property['VALUE'] !== '') {
            $meta[] = mb_substr((string)$property['VALUE'], 0, 80);
        }
        $aliases = [$owner, $property['TITLE']];
        if (isset($property['CLASS']) && $property['CLASS'] !== '') {
            $aliases[] = $property['CLASS'];
        }
        $addResult('Свойства', 'property', $owner . '.' . $property['TITLE'], $url, $property['DESCRIPTION'] ?? '', $meta, '', $aliases);
    }

    $methods = SQLSelect("SELECT methods.ID, methods.TITLE, methods.OBJECT_ID, methods.DESCRIPTION, objects.CLASS_ID AS OBJECT_CLASS_ID, methods.CLASS_ID, classes.TITLE AS CLASS, objects.TITLE AS OBJECT FROM methods LEFT JOIN classes ON methods.CLASS_ID=classes.ID LEFT JOIN objects ON methods.OBJECT_ID=objects.ID WHERE (methods.TITLE LIKE '%" . $like . "%' OR methods.CODE LIKE '%" . $like . "%' OR methods.DESCRIPTION LIKE '%" . $like . "%' OR CONCAT(IFNULL(objects.TITLE, ''), '.', methods.TITLE) LIKE '%" . $like . "%' OR CONCAT(IFNULL(classes.TITLE, ''), '.', methods.TITLE) LIKE '%" . $like . "%' OR (" . $methodCondition . ")) ORDER BY methods.TITLE LIMIT " . $limit);
    foreach ($methods as $method) {
        if (!empty($method['OBJECT_ID'])) {
            $addResult('Методы', 'method', $method['OBJECT'] . '.' . $method['TITLE'], '/panel/class/' . (int)$method['OBJECT_CLASS_ID'] . '/object/' . (int)$method['OBJECT_ID'] . '/methods/' . (int)$method['ID'] . '.html', $method['DESCRIPTION'] ?? '', ['Метод объекта'], '', [$method['OBJECT'] ?? '', $method['TITLE'], $method['CLASS'] ?? '']);
        } else {
            $addResult('Методы', 'method', $method['CLASS'] . '.' . $method['TITLE'], '/panel/class/' . (int)$method['CLASS_ID'] . '/methods/' . (int)$method['ID'] . '.html', $method['DESCRIPTION'] ?? '', ['Метод класса'], '', [$method['CLASS'] ?? '', $method['TITLE']]);
        }
    }

    $scripts = SQLSelect("SELECT ID, TITLE FROM scripts WHERE (TITLE LIKE '%" . $like . "%' OR CODE LIKE '%" . $like . "%' OR (" . $scriptCondition . ")) ORDER BY TITLE LIMIT " . $limit);
    foreach ($scripts as $script) {
        $addResult('Скрипты', 'script', $script['TITLE'], '/panel/script/' . (int)$script['ID'] . '.html', '', ['Скрипт']);
    }


    if (file_exists(DIR_MODULES . 'zwave/zwave.class.php')) {
        $zwaveCondition = $buildTokenLikeCondition(['TITLE', 'LINKED_OBJECT', 'LINKED_PROPERTY'], $queryTokens);
        $devices = SQLSelect("SELECT ID, DEVICE_ID, TITLE, LINKED_OBJECT, LINKED_PROPERTY FROM zwave_properties WHERE (TITLE LIKE '%" . $like . "%' OR LINKED_OBJECT LIKE '%" . $like . "%' OR LINKED_PROPERTY LIKE '%" . $like . "%' OR (" . $zwaveCondition . ")) ORDER BY TITLE LIMIT " . $limit);
        foreach ($devices as $device) {
            $deviceFullTitle = trim(($device['LINKED_OBJECT'] ?? '') . '.' . ($device['LINKED_PROPERTY'] ?? ''), '.');
            $addResult('Z-Wave', 'zwave', $device['TITLE'], '/panel/zwave/' . (int)$device['DEVICE_ID'] . '.html', '', [$device['LINKED_OBJECT'] ?? '', $device['LINKED_PROPERTY'] ?? ''], '', [$deviceFullTitle]);
        }
    }


    if (file_exists(DIR_MODULES . 'app_gpstrack/app_gpstrack.class.php')) {
        $gpsCondition = $buildTokenLikeCondition(['gpslocations.TITLE', 'gpsactions.CODE', 'users.NAME'], $queryTokens);
        $actions = SQLSelect("SELECT gpsactions.ID, gpslocations.TITLE, users.NAME FROM gpsactions LEFT JOIN users ON gpsactions.USER_ID=users.ID LEFT JOIN gpslocations ON gpsactions.LOCATION_ID=gpslocations.ID WHERE (gpslocations.TITLE LIKE '%" . $like . "%' OR gpsactions.CODE LIKE '%" . $like . "%' OR (" . $gpsCondition . ")) ORDER BY gpslocations.TITLE LIMIT " . $limit);
        foreach ($actions as $action) {
            $addResult('GPS', 'gps', $action['TITLE'], '/panel/app_gpstrack/action_' . (int)$action['ID'] . '.html', '', [$action['NAME'] ?? '']);
        }
    }

    foreach ($sections as $sectionName => &$items) {
        usort($items, static function ($a, $b) {
            if (($a['_score'] ?? 0) === ($b['_score'] ?? 0)) {
                return strnatcasecmp((string)$a['title'], (string)$b['title']);
            }
            return (($b['_score'] ?? 0) <=> ($a['_score'] ?? 0));
        });
        foreach ($items as &$item) {
            unset($item['_score']);
        }
        unset($item);
        $counts[$sectionName] = count($items);
    }
    unset($items);

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
