<?php

/** System helpers. Public names and argument defaults are part of the module API. */

function hexToHsv($hex)
{
    $hex = str_replace('#', '', $hex);
    $length = strlen($hex);
    $rgb['r'] = hexdec($length == 6 ? substr($hex, 0, 2) : ($length == 3 ? str_repeat(substr($hex, 0, 1), 2) : 0));
    $rgb['g'] = hexdec($length == 6 ? substr($hex, 2, 2) : ($length == 3 ? str_repeat(substr($hex, 1, 1), 2) : 0));
    $rgb['b'] = hexdec($length == 6 ? substr($hex, 4, 2) : ($length == 3 ? str_repeat(substr($hex, 2, 1), 2) : 0));
    return rgbToHsv($rgb['r'], $rgb['g'], $rgb['b']);
}

function hsvToHex($h, $s, $v)
{
    $rgb = hsvToRgb($h, $s, $v);
    return sprintf("%02x%02x%02x", $rgb[0], $rgb[1], $rgb[2]);
}

function hsvToRgb($h, $s, $v)
{
    $r;
    $g;
    $b;
    $h = intval($h);
    if ($h < 0) $h = 0;
    if ($h > 360) $h = 360;

    $Vmin = ((100 - $s) * $v) / 100;
    $a = ($v - $Vmin) * (($h % 60) / 60);
    $Vinc = $Vmin + $a;
    $Vdec = $v - $a;
    $Hi = intval($h / 60) % 6;
    if ($Hi == 0) {
        $r = $v;
        $g = $Vinc;
        $b = $Vmin;
    } elseif ($Hi == 1) {
        $r = $Vdec;
        $g = $v;
        $b = $Vmin;
    } elseif ($Hi == 2) {
        $r = $Vmin;
        $g = $v;
        $b = $Vinc;
    } elseif ($Hi == 3) {
        $r = $Vmin;
        $g = $Vdec;
        $b = $v;
    } elseif ($Hi == 4) {
        $r = $Vinc;
        $g = $Vmin;
        $b = $v;
    } elseif ($Hi == 5) {
        $r = $v;
        $g = $Vmin;
        $b = $Vdec;
    }

    $r = intval(($r * 255) / 100);
    $g = intval(($g * 255) / 100);
    $b = intval(($b * 255) / 100);

    return array($r, $g, $b);
}

function rgbToHsv($r, $g, $b)
{
    $r /= 255;
    $g /= 255;
    $b /= 255;
    $max = max($r, $g, $b);
    $min = min($r, $g, $b);
    $h;
    $s;
    $v;
    $d = $max - $min;
    if ($max == $min) $h = 0;
    else {
        if ($max == $r) {
            $h = 60 * (($g - $b) / $d);
            if ($g < $b) $h += 360;
        } elseif ($max == $g) {
            $h = 60 * (($b - $r) / $d) + 120;
        } elseif ($max == $b) {
            $h = 60 * (($r - $g) / $d) + 240;
        }
    }
    if ($max == 0) $s = 0;
    else $s = 1 - ($min / $max);
    $v = $max;
    return array(round($h, 2), round($s, 2), round($v, 2));
}
