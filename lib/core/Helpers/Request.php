<?php

/** System helpers. Public names and argument defaults are part of the module API. */

function gr($var_name, $type = 'trim')
{

    if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] == 'DELETE') {
        $content = file_get_contents('php://input');
        parse_str($content, $_REQUEST);
    }

    if (isset($_REQUEST[$var_name])) {
        $value = $_REQUEST[$var_name];
    } else {
        $value = '';
    }
    if ($type == 'int') {
        $value = (int)$value;
    } elseif ($type == 'float') {
        $value = (float)$value;
    } elseif ($type == 'trim' && !is_array($value)) {
        $value = trim($value);
    }
    return $value;
}

/**
 * Summary of redirect
 * @param mixed $url Url
 * @param mixed $owner Owner (default '')
 * @param mixed $no_sid No sid (default 0)
 * @return void
 */
function redirect($url, $owner = "", $no_sid = 0)
{
    // redirect inside module
    global $session;

    if (is_object($owner)) {
        $owner->redirect($url);
    } else {
        $isAdminPath = strpos($url, 'admin.php') !== false;
        if ($isAdminPath && function_exists('mdj_admin_pretty_url')) {
            $url = mdj_admin_pretty_url($url);
        }
        $url = mdjExtractFlashNotificationsFromUrl($url, 'admin');
        $url = "Location:$url\n\n";
        if (!headers_sent()) {
            header($url);
        }
        exit;
    }
}
