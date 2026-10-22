<?php

/** System helpers. Public names and argument defaults are part of the module API. */

/**
 * Summary of HexStringToArray
 * @param mixed $buf Buffer
 * @return array
 */
function HexStringToArray($buf)
{
    $res = array();
    $bufLength = strlen($buf) - 1;

    for ($i = 0; $i < $bufLength; $i += 2) {
        $res[] = (hexdec($buf[$i] . $buf[$i + 1]));
    }

    return $res;
}

/**
 * Summary of HexStringToString
 * @param mixed $buf Buf
 * @return string
 */
function HexStringToString($buf)
{
    $res = '';
    $bufLength = strlen($buf) - 1;
    for ($i = 0; $i < $bufLength; $i += 2) {
        $res .= chr(hexdec($buf[$i] . $buf[$i + 1]));
    }

    return $res;
}

/**
 * Summary of binaryToString
 * @param mixed $buf Buf
 * @return string
 */
function binaryToString($buf)
{
    $res = '';
    $bufLength = strlen($buf);

    for ($i = 0; $i < $bufLength; $i++) {
        $num = dechex(ord($buf[$i]));
        if (strlen($num) == 1) {
            $num = '0' . $num;
        }

        $res .= $num;
    }

    return $res;
}

/**
 * Summary of makePayload
 * @param mixed $data Data
 * @return string
 */
function makePayload($data)
{
    $res = '';
    foreach ($data as $v) {
        $res .= chr($v);
    }

    return $res;
}

function urlsafe_b64decode($string)
{
    $data = urldecode($data);
    $data = str_replace(array('-', '_'), array('+', '/'), $string);
    $mod4 = strlen($data) % 4;
    if ($mod4) {
        $data .= substr('====', $mod4);
    }
    return base64_decode($data);
}

/**
 * Encode/Decode a string for safe transfer to a URL
 * @param mixed $string String
 * @return string
 */
function urlsafe_b64encode($string)
{
    $data = base64_encode($string);
    $data = str_replace(array('+', '/', '='), array('-', '_', ''), $data);
    $data = urlencode($data);
    return $data;
}
