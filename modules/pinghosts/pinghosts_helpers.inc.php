<?php
/**
 * Shared display helpers for the pinghosts module.
 *
 * These functions only format data for templates; they do not touch the
 * monitoring logic or the database schema.
 */

if (!function_exists('md_pinghosts_status_meta')) {
    function md_pinghosts_status_meta($status)
    {
        switch ((int)$status) {
            case 1:
                return array('state' => 'online', 'label' => 'Онлайн', 'tone' => 'success', 'icon' => 'wifi');
            case 2:
                return array('state' => 'offline', 'label' => 'Офлайн', 'tone' => 'danger', 'icon' => 'wifi-off');
            default:
                return array('state' => 'idle', 'label' => 'Не проверялся', 'tone' => 'neutral', 'icon' => 'clock-question');
        }
    }
}

if (!function_exists('md_pinghosts_type_meta')) {
    function md_pinghosts_type_meta($type)
    {
        switch ((int)$type) {
            case 1:
                return array('label' => 'WEB', 'title' => 'WEB PAGE (URL)', 'tone' => 'info', 'icon' => 'world-www');
            case 2:
                return array('label' => 'PORT', 'title' => 'PING (HOST:PORT)', 'tone' => 'primary', 'icon' => 'plug');
            default:
                return array('label' => 'PING', 'title' => 'PING (HOST)', 'tone' => 'outline', 'icon' => 'wifi');
        }
    }
}

if (!function_exists('md_pinghosts_plural')) {
    function md_pinghosts_plural($number, $one, $few, $many)
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

if (!function_exists('md_pinghosts_relative_time')) {
    /**
     * Human-readable time for a DB datetime.
     *
     * @param string $datetime
     * @param bool $future true for "через ..." labels
     * @return string
     */
    function md_pinghosts_relative_time($datetime, $future = false)
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
                return 'пора проверить';
            }
            $abs = $diff;
        } else {
            if ($diff >= 0) {
                return 'сейчас';
            }
            $abs = -$diff;
        }

        if ($abs < 604800) {
            if ($abs < 60) {
                $value = $abs;
                $unit = md_pinghosts_plural($value, 'секунду', 'секунды', 'секунд');
            } elseif ($abs < 3600) {
                $value = (int)floor($abs / 60);
                $unit = md_pinghosts_plural($value, 'минуту', 'минуты', 'минут');
            } elseif ($abs < 86400) {
                $value = (int)floor($abs / 3600);
                $unit = md_pinghosts_plural($value, 'час', 'часа', 'часов');
            } else {
                $value = (int)floor($abs / 86400);
                $unit = md_pinghosts_plural($value, 'день', 'дня', 'дней');
            }

            $label = $value . ' ' . $unit;
            return $future ? 'через ' . $label : $label . ' назад';
        }

        return date('d.m.Y H:i', $timestamp);
    }
}

if (!function_exists('md_pinghosts_error_message')) {
    /**
     * Make a PHP syntax error readable in the UI: hide the absolute project
     * path and escape it before it is inserted into HTML.
     */
    function md_pinghosts_error_message($message)
    {
        $message = (string)$message;
        if (defined('ROOT') && ROOT !== '') {
            $message = str_replace(ROOT, '', $message);
        }
        return htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('md_pinghosts_link_label')) {
    function md_pinghosts_link_label($object, $property)
    {
        $object = trim((string)$object);
        $property = trim((string)$property);
        if ($object === '') {
            return '';
        }
        return $property !== '' ? $object . '.' . $property : $object;
    }
}
