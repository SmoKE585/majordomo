<?php
/**
 * General functions
 *
 * Frequiently Used Functions
 *
 */
include_once(ROOT . '3rdparty/php-mailer/Exception.php');
include_once(ROOT . '3rdparty/php-mailer/PHPMailer.php');
include_once(ROOT . '3rdparty/php-mailer/SMTP.php');

if (isset($_SERVER['REQUEST_METHOD'])) {
    $blocked = array('_SERVER', '_COOKIE', 'HTTP_POST_VARS', 'HTTP_GET_VARS', 'HTTP_SERVER_VARS',
        '_GET', '_POST', '_FILES', '_REQUEST', '_ENV', '_SESSION', 'GLOBALS', 'session', 'db');

    if ($_SERVER['REQUEST_METHOD'] == "POST") {
        $blocked[] = '_GET';
    } else {
        $blocked[] = '_POST';
    }

    foreach ($blocked as $b) {
        unset($_GET[$b]);
        unset($_POST[$b]);
        unset($_REQUEST[$b]);
    }

    /**
     * Summary of stripit
     * @param mixed $a A
     * @return void
     */
    function stripit(&$a)
    {
        if (is_array($a)) {
            foreach ($a as $fname => $fval) {
                if (is_array($fval))
                    stripit($a[$fname]);
                else
                    $a[$fname] = stripslashes($fval);
            }
        }
    }

    if ($_SERVER['REQUEST_METHOD'] == "POST") {
        $params = $_POST;
    } else {
        $params = $_GET;
    }

    // function get_magic_quotes_gpc() is deprecated
    //if (get_magic_quotes_gpc()) {
    //    stripit($params);
    //}

    // Use $GLOBALS instead of ${$k}: legacy entry points used to include this
    // file at global scope, but Application::bootLibraries() includes it from a
    // method. Modules still read request parameters through `global $action`,
    // `global $view_mode`, `global $ajax_panel`, etc.
    foreach ($params as $k => $v) {
        $GLOBALS[$k] = $v;
    }

    if (isset($_FILES) && count($_FILES) > 0) {
        $ks = array_keys($_FILES);
        $ksCnt = count($ks);

        for ($i = 0; $i < $ksCnt; $i++) {
            $k = $ks[$i];
            if (in_array($k, $blocked, true)) continue;

            $GLOBALS[$k] = $_FILES[$k]['tmp_name'];
            $GLOBALS[$k . "_name"] = $_FILES[$k]['name'];
        }
    }
}

// Compatibility entry point. Implementations live in lib/core/Helpers/.
require_once __DIR__ . '/core/Helpers/LocalTime.php';
require_once __DIR__ . '/core/Helpers/Logging.php';
require_once __DIR__ . '/core/Helpers/Mail.php';
require_once __DIR__ . '/core/Helpers/Request.php';
require_once __DIR__ . '/core/Helpers/Utilities.php';
require_once __DIR__ . '/core/Helpers/Validation.php';
require_once __DIR__ . '/core/Helpers/ViewData.php';
