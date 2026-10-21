<?php
/**
 * Shared formatting and lookup helpers for the MQTT module.
 */

if (!function_exists('md_mqtt_h')) {
    function md_mqtt_h($value)
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('md_mqtt_date_time')) {
    function md_mqtt_date_time($datetime)
    {
        $datetime = trim((string)$datetime);
        if ($datetime === '' || $datetime === '0000-00-00 00:00:00') {
            return '—';
        }
        $timestamp = strtotime($datetime);
        if ($timestamp === false) {
            return $datetime;
        }
        return date('d.m.Y H:i', $timestamp);
    }
}

if (!function_exists('md_mqtt_relative_time')) {
    function md_mqtt_relative_time($datetime)
    {
        $datetime = trim((string)$datetime);
        if ($datetime === '' || $datetime === '0000-00-00 00:00:00') {
            return '—';
        }
        $timestamp = strtotime($datetime);
        if ($timestamp === false) {
            return $datetime;
        }

        $diff = time() - $timestamp;
        if ($diff < 0) {
            $diff = 0;
        }
        if ($diff < 60) {
            return 'только что';
        }
        if ($diff < 3600) {
            return max(1, (int)floor($diff / 60)) . ' мин назад';
        }
        if ($diff < 86400) {
            return max(1, (int)floor($diff / 3600)) . ' ч назад';
        }
        if ($diff < 172800) {
            return 'вчера, ' . date('H:i', $timestamp);
        }
        return date('d.m.Y H:i', $timestamp);
    }
}

if (!function_exists('md_mqtt_freshness')) {
    function md_mqtt_freshness($datetime)
    {
        $datetime = trim((string)$datetime);
        if ($datetime === '') {
            return 'empty';
        }
        $timestamp = strtotime($datetime);
        if ($timestamp === false) {
            return 'stale';
        }
        $diff = time() - $timestamp;
        if ($diff <= 3600) {
            return 'fresh';
        }
        if ($diff <= 86400) {
            return 'recent';
        }
        return 'stale';
    }
}

if (!function_exists('md_mqtt_truncate')) {
    function md_mqtt_truncate($value, $limit = 160)
    {
        $value = (string)$value;
        if (mb_strlen($value, 'UTF-8') <= $limit) {
            return $value;
        }
        return mb_substr($value, 0, $limit, 'UTF-8') . '…';
    }
}

if (!function_exists('md_mqtt_value_preview')) {
    function md_mqtt_value_preview($value, $limit = 140)
    {
        $value = trim((string)$value);
        if ($value === '') {
            return '';
        }
        $value = str_replace(array("\r\n", "\r", "\n", "\t"), ' ', $value);
        $value = str_replace('":', '": ', $value);
        $value = preg_replace('/\s+/u', ' ', $value);
        return md_mqtt_truncate($value, $limit);
    }
}

if (!function_exists('md_mqtt_last_segment')) {
    function md_mqtt_last_segment($path)
    {
        $parts = preg_split('#/+#', trim((string)$path, '/'));
        $last = end($parts);
        return $last !== false && $last !== '' ? $last : trim((string)$path);
    }
}

if (!function_exists('md_mqtt_device_map')) {
    function md_mqtt_device_map($objects)
    {
        static $has_devices = null;
        if ($has_devices === null) {
            $has_devices = false;
            $check = SQLSelect("SHOW TABLES LIKE 'devices'");
            if (!empty($check)) {
                $has_devices = true;
            }
        }

        $map = array();
        if (!$has_devices || !is_array($objects)) {
            return $map;
        }

        $safe = array();
        foreach ($objects as $object) {
            $object = trim((string)$object);
            if ($object !== '' && !in_array($object, $safe, true)) {
                $safe[] = DBSafe($object);
            }
        }
        if (!$safe) {
            return $map;
        }

        $rows = SQLSelect("SELECT LINKED_OBJECT, TITLE FROM devices WHERE LINKED_OBJECT IN ('" . implode("','", $safe) . "')");
        foreach ($rows as $row) {
            if ($row['LINKED_OBJECT'] !== '') {
                $map[$row['LINKED_OBJECT']] = $row['TITLE'] !== '' ? $row['TITLE'] : $row['LINKED_OBJECT'];
            }
        }
        return $map;
    }
}

if (!function_exists('md_mqtt_location_map')) {
    function md_mqtt_location_map()
    {
        static $map = null;
        if ($map !== null) {
            return $map;
        }
        $map = array();
        $rows = SQLSelect("SELECT ID, TITLE FROM locations ORDER BY TITLE");
        foreach ($rows as $row) {
            $map[(int)$row['ID']] = $row['TITLE'];
        }
        return $map;
    }
}

if (!function_exists('md_mqtt_query_url')) {
    function md_mqtt_query_url($params, $base = '?')
    {
        $clean = array();
        foreach ((array)$params as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $clean[$key] = $value;
        }
        $query = http_build_query($clean);
        return $base . $query;
    }
}

if (!function_exists('md_mqtt_pagination_items')) {
    function md_mqtt_pagination_items($page, $pages, $window = 2)
    {
        $items = array();
        if ($pages < 2) {
            return $items;
        }
        for ($number = 1; $number <= $pages; $number++) {
            if ($number === 1 || $number === $pages || abs($number - $page) <= $window) {
                $items[] = array('NUM' => $number, 'ELLIPSIS' => 0);
            } elseif (empty($items) || empty($items[count($items) - 1]['ELLIPSIS'])) {
                $items[] = array('NUM' => 0, 'ELLIPSIS' => 1);
            }
        }
        return $items;
    }
}
