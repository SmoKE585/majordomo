<?php
/**
 * Keenetic module — display helpers.
 *
 * These functions only format data for templates. They do not touch the
 * database or the router API, so they are safe to include from any admin page.
 * Names are prefixed with `keenetic_` to avoid collisions with core helpers.
 */

if (!function_exists('keenetic_h')) {
    /**
     * Escape a value for HTML output.
     *
     * @param mixed $value
     * @return string
     */
    function keenetic_h($value)
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('keenetic_plural')) {
    /**
     * Pick a Russian plural form: 1 роутер, 2 роутера, 5 роутеров.
     *
     * @param int $number
     * @param string $one
     * @param string $few
     * @param string $many
     * @return string
     */
    function keenetic_plural($number, $one, $few, $many)
    {
        $number = abs((int)$number);
        $mod10 = $number % 10;
        $mod100 = $number % 100;
        if ($mod10 === 1 && $mod100 !== 11) {
            return $one;
        }
        if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 10 || $mod100 >= 20)) {
            return $few;
        }
        return $many;
    }
}

if (!function_exists('keenetic_relative_time')) {
    /**
     * Human-readable relative time for a database datetime.
     *
     * @param string $datetime
     * @param bool $future true for "через ..." labels
     * @return string
     */
    function keenetic_relative_time($datetime, $future = false)
    {
        $datetime = trim((string)$datetime);
        if ($datetime === '' || $datetime === '0000-00-00 00:00:00') {
            return '—';
        }
        $timestamp = strtotime($datetime);
        if ($timestamp === false) {
            return $datetime;
        }

        $diff = $timestamp - time();
        if ($future) {
            if ($diff <= 0) {
                return 'сейчас';
            }
            $abs = $diff;
        } else {
            if ($diff >= 0) {
                return 'только что';
            }
            $abs = -$diff;
        }

        if ($abs < 604800) {
            if ($abs < 60) {
                $value = $abs;
                $unit = keenetic_plural($value, 'секунду', 'секунды', 'секунд');
            } elseif ($abs < 3600) {
                $value = (int)floor($abs / 60);
                $unit = keenetic_plural($value, 'минуту', 'минуты', 'минут');
            } elseif ($abs < 86400) {
                $value = (int)floor($abs / 3600);
                $unit = keenetic_plural($value, 'час', 'часа', 'часов');
            } else {
                $value = (int)floor($abs / 86400);
                $unit = keenetic_plural($value, 'день', 'дня', 'дней');
            }

            $label = $value . ' ' . $unit;
            return $future ? 'через ' . $label : $label . ' назад';
        }

        return date('d.m.Y H:i', $timestamp);
    }
}

if (!function_exists('keenetic_datetime')) {
    /**
     * Exact datetime for a title/tooltip.
     *
     * @param string $datetime
     * @return string
     */
    function keenetic_datetime($datetime)
    {
        $datetime = trim((string)$datetime);
        if ($datetime === '' || $datetime === '0000-00-00 00:00:00') {
            return '';
        }
        $timestamp = strtotime($datetime);
        return $timestamp === false ? $datetime : date('d.m.Y H:i:s', $timestamp);
    }
}

if (!function_exists('keenetic_uptime_human')) {
    /**
     * Seconds => "3 д 4 ч 12 мин".
     *
     * @param int $seconds
     * @return string
     */
    function keenetic_uptime_human($seconds)
    {
        $seconds = max(0, (int)$seconds);
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        $parts = array();
        if ($days > 0) {
            $parts[] = $days . ' ' . keenetic_plural($days, 'день', 'дня', 'дней');
        }
        if ($hours > 0) {
            $parts[] = $hours . ' ч';
        }
        if ($days === 0 && $minutes > 0) {
            $parts[] = $minutes . ' мин';
        }
        if (!$parts) {
            $parts[] = $seconds . ' с';
        }

        return implode(' ', $parts);
    }
}

if (!function_exists('keenetic_traffic_human')) {
    /**
     * Bytes => "1,2 ГБ".
     *
     * @param int|float $bytes
     * @return string
     */
    function keenetic_traffic_human($bytes)
    {
        $bytes = max(0, (float)$bytes);
        $units = array('Б', 'КБ', 'МБ', 'ГБ', 'ТБ');
        $index = 0;
        while ($bytes >= 1024 && $index < count($units) - 1) {
            $bytes /= 1024;
            $index++;
        }
        $decimals = $index === 0 ? 0 : ($bytes < 10 ? 1 : 0);
        return number_format($bytes, $decimals, ',', ' ') . ' ' . $units[$index];
    }
}

if (!function_exists('keenetic_router_state')) {
    /**
     * Router availability state.
     *
     * @param int $status
     * @return array{state:string,label:string,tone:string,icon:string}
     */
    function keenetic_router_state($status)
    {
        if ((int)$status === 1) {
            return array('state' => 'online', 'label' => 'Онлайн', 'tone' => 'success', 'icon' => 'wifi');
        }
        return array('state' => 'offline', 'label' => 'Недоступен', 'tone' => 'danger', 'icon' => 'wifi-off');
    }
}

if (!function_exists('keenetic_internet_state')) {
    /**
     * Internet state for a router row.
     *
     * @param array $router
     * @return array{state:string,label:string,tone:string,icon:string}
     */
    function keenetic_internet_state($router)
    {
        if ((int)($router['STATUS'] ?? 0) !== 1) {
            return array('state' => 'idle', 'label' => 'Нет данных', 'tone' => 'neutral', 'icon' => 'world-question');
        }
        if ((int)($router['INET_STATUS'] ?? 0) === 1) {
            return array('state' => 'online', 'label' => 'Интернет есть', 'tone' => 'success', 'icon' => 'world');
        }
        return array('state' => 'error', 'label' => 'Интернета нет', 'tone' => 'danger', 'icon' => 'world-off');
    }
}

if (!function_exists('keenetic_firmware_update')) {
    /**
     * Whether a firmware update is available and checking is enabled.
     *
     * @param array $router
     * @return bool
     */
    function keenetic_firmware_update($router)
    {
        if (!empty($router['NO_UPDATE'])) {
            return false;
        }
        $current = trim((string)($router['FIRMWARE'] ?? ''));
        $available = trim((string)($router['NEW_FIRMWARE'] ?? ''));
        return $available !== '' && $available !== $current;
    }
}

if (!function_exists('keenetic_signal_meta')) {
    /**
     * Wi-Fi signal quality for an RSSI value.
     *
     * @param int|string $rssi
     * @return array{percent:int,tone:string,label:string,known:bool}
     */
    function keenetic_signal_meta($rssi)
    {
        $rssi = (int)$rssi;
        if ($rssi === 0) {
            return array('percent' => 0, 'tone' => 'neutral', 'label' => 'Нет данных', 'known' => false);
        }
        $percent = (int)max(0, min(100, 2 * ($rssi + 100)));
        if ($percent >= 75) {
            $tone = 'success';
            $label = 'Отличный';
        } elseif ($percent >= 50) {
            $tone = 'warning';
            $label = 'Средний';
        } else {
            $tone = 'danger';
            $label = 'Слабый';
        }
        return array('percent' => $percent, 'tone' => $tone, 'label' => $label, 'known' => true);
    }
}

if (!function_exists('keenetic_device_kind')) {
    /**
     * Connection type label for a device row.
     *
     * @param int|string $typeConnect
     * @return array{label:string,icon:string,tone:string}
     */
    function keenetic_device_kind($typeConnect)
    {
        if ((int)$typeConnect === 1) {
            return array('label' => 'Wi-Fi', 'icon' => 'wifi', 'tone' => 'info');
        }
        return array('label' => 'LAN', 'icon' => 'plug-connected', 'tone' => 'outline');
    }
}

if (!function_exists('keenetic_binding_label')) {
    /**
     * "Object.Property", "Object → Method" or an empty string.
     *
     * @param string $object
     * @param string $property
     * @param string $method
     * @return string
     */
    function keenetic_binding_label($object, $property, $method)
    {
        $object = trim((string)$object);
        $property = trim((string)$property);
        $method = trim((string)$method);
        if ($object === '') {
            return '';
        }
        if ($property !== '') {
            return $object . '.' . $property;
        }
        if ($method !== '') {
            return $object . ' → ' . $method;
        }
        return $object;
    }
}

if (!function_exists('keenetic_connection_state')) {
    /**
     * Administrative and actual state of a Keenetic connection.
     *
     * @param array $connection
     * @return array{admin_state:string,admin_label:string,link_state:string,link_label:string}
     */
    function keenetic_connection_state($connection)
    {
        $admin = (int)($connection['STATE'] ?? 0) === 1;
        $link = (int)($connection['STATUS'] ?? 0) === 1;

        if ($admin) {
            $adminState = 'online';
            $adminLabel = 'Включено';
        } else {
            $adminState = 'idle';
            $adminLabel = 'Выключено';
        }

        if (!$admin) {
            $linkState = 'idle';
            $linkLabel = 'Отключено вручную';
        } elseif ($link) {
            $linkState = 'online';
            $linkLabel = 'Подключено';
        } else {
            $linkState = 'error';
            $linkLabel = 'Нет соединения';
        }

        return array(
            'admin_state' => $adminState,
            'admin_label' => $adminLabel,
            'link_state' => $linkState,
            'link_label' => $linkLabel,
        );
    }
}

if (!function_exists('keenetic_escape_row')) {
    /**
     * Escape every scalar value of a row for template output.
     *
     * @param array $row
     * @param array $except fields that are already HTML (for example nl2br'ed logs)
     * @return array
     */
    function keenetic_escape_row($row, $except = array())
    {
        if (!is_array($row)) {
            return array();
        }
        foreach ($row as $key => $value) {
            if (in_array($key, $except, true)) {
                continue;
            }
            if (is_scalar($value) || $value === null) {
                $row[$key] = keenetic_h($value);
            }
        }
        return $row;
    }
}

if (!function_exists('keenetic_is_ip')) {
    /**
     * Check an IPv4 address, optionally with a port.
     *
     * @param string $address
     * @return bool
     */
    function keenetic_is_ip($address)
    {
        $address = trim((string)$address);
        if ($address === '') {
            return false;
        }
        $parts = explode(':', $address);
        $ip = $parts[0];
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }
        if (isset($parts[1]) && (!ctype_digit($parts[1]) || (int)$parts[1] < 1 || (int)$parts[1] > 65535)) {
            return false;
        }
        return true;
    }
}

if (!function_exists('keenetic_address_href')) {
    /**
     * Safe web-interface URL for a router address.
     *
     * @param string $address
     * @return string
     */
    function keenetic_address_href($address)
    {
        $address = trim((string)$address);
        if ($address === '') {
            return '';
        }
        return (keenetic_is_ip($address) ? 'http://' : 'https://') . $address;
    }
}

if (!function_exists('keenetic_safe_url')) {
    /**
     * Only http(s) links are allowed for release notes and external pages.
     *
     * @param string $url
     * @return string
     */
    function keenetic_safe_url($url)
    {
        $url = trim((string)$url);
        if ($url === '') {
            return '';
        }
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return '';
        }
        return $url;
    }
}

if (!function_exists('keenetic_error_message')) {
    /**
     * Make a PHP error readable and safe for HTML output.
     *
     * @param string $message
     * @return string
     */
    function keenetic_error_message($message)
    {
        $message = (string)$message;
        if (defined('ROOT') && ROOT !== '') {
            $message = str_replace(ROOT, '', $message);
        }
        return keenetic_h($message);
    }
}
