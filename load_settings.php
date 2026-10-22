<?php

// E_STRICT is deprecated as a constant in PHP 8.4 and PHP 8 never emits that
// error level, so it must not appear in the mask.
error_reporting(E_ALL & ~(E_NOTICE | E_DEPRECATED));
mb_internal_encoding("UTF-8");


// get settings
$settings = SQLSelect('SELECT NAME, VALUE FROM settings');
if (!is_array($settings)) {
    // A failed query (connection lost, server restart) must not turn into
    // count(false) and must not silently produce an empty configuration.
    $settings = array();
}
$total = count($settings);

if (!$total) {
    // Without the settings rows the runtime has no SETTINGS_* constants at all.
    // Historically that surfaced much later as a misleading "Undefined
    // constant SETTINGS_THEME" inside application->run(); the log entry below
    // names the real cause (empty/unreadable settings table).
    DebMes('Settings table is empty or unreadable, default SETTINGS_* values are used', 'errors');
}

if (isset($_GET['theme']) && !defined('SETTINGS_THEME')) {
    Define('SETTINGS_THEME', $_GET['theme']);
}


if (isset($_GET['lang'])) {
    Define("SETTINGS_SITE_LANGUAGE", $_GET['lang']);
    if (isset($_SESSION) || session_id()) {
        $_SESSION['lang'] = SETTINGS_SITE_LANGUAGE;
    }
} elseif (isset($_SESSION) && isset($_SESSION['lang'])) {
    Define("SETTINGS_SITE_LANGUAGE", $_SESSION['lang']);
}


for ($i = 0; $i < $total; $i++) {
    if ($settings[$i]['NAME'] == 'SITE_TIMEZONE' && !in_array($settings[$i]['VALUE'], DateTimeZone::listIdentifiers())) {
        continue;
    }
    $setting_constant = 'SETTINGS_' . $settings[$i]['NAME'];
    if (!defined($setting_constant)) {
        // Values forced by the request (?theme=, ?lang=) win over the database;
        // re-defining an existing constant only produces an E_WARNING.
        Define($setting_constant, $settings[$i]['VALUE']);
    }
}


// Settings the runtime reads as bare constants (modules/application.class.php,
// templates, legacy modules) must always exist. A missing row is user data, not
// a fatal error: fall back to the same defaults modules/first_start.php uses.
$settings_defaults = array(
    'SITE_LANGUAGE' => 'en',
    'SITE_TIMEZONE' => 'Europe/Minsk',
    'THEME' => 'dark',
);
foreach ($settings_defaults as $setting_name => $setting_default) {
    if (!defined('SETTINGS_' . $setting_name)) {
        Define('SETTINGS_' . $setting_name, $setting_default);
    }
}
unset($settings_defaults, $setting_name, $setting_default, $setting_constant);


if (!defined('GIT_URL')) {
    Define('GIT_URL', 'https://github.com/SmoKE585/majordomo');
}
if (!defined('PROJECT_URL')) {
    Define('PROJECT_URL', GIT_URL);
}
if (!defined('PROJECT_COMMIT_URL')) {
    Define('PROJECT_COMMIT_URL', GIT_URL != '' ? rtrim(GIT_URL, '/') . '/commit/' : '');
}
if (!isset($aditional_git_urls)) {
    $aditional_git_urls = array();
}

// language selection by settings
if (SETTINGS_SITE_LANGUAGE && file_exists(ROOT . 'languages/' . SETTINGS_SITE_LANGUAGE . '.php')) {
    include_once(ROOT . 'languages/' . SETTINGS_SITE_LANGUAGE . '.php');
}
include_once(ROOT . 'languages/default.php');

if (LANG_SETTINGS_SITE_LANGUAGE_CODE) {
    Define('SETTINGS_SITE_LANGUAGE_CODE', LANG_SETTINGS_SITE_LANGUAGE_CODE);
} elseif (SETTINGS_SITE_LANGUAGE == 'en') {
    Define('SETTINGS_SITE_LANGUAGE_CODE', 'en_GB');
} else {
    Define('SETTINGS_SITE_LANGUAGE_CODE', '');
}

ini_set('date.timezone', SETTINGS_SITE_TIMEZONE);
date_default_timezone_set(SETTINGS_SITE_TIMEZONE);

function timezone_offset_string($offset)
{
    return sprintf("%s%02d:%02d", ($offset >= 0) ? '+' : '-', abs($offset / 3600), abs($offset % 3600));
}

$offset = timezone_offset_get(new DateTimeZone(SETTINGS_SITE_TIMEZONE), new DateTime());
$offset_text = timezone_offset_string($offset);
SQLExec("SET time_zone = '" . $offset_text . "';");


if (isset($_SERVER['REQUEST_METHOD']) &&
    ($_SERVER['REQUEST_METHOD'] == 'GET' || $_SERVER['REQUEST_METHOD'] == 'POST') &&
    defined('WAIT_FOR_MAIN_CYCLE') &&
    WAIT_FOR_MAIN_CYCLE == 1 &&
    !preg_match('/clear_all_history\.php/', $_SERVER['REQUEST_URI']) &&
    !preg_match('/\/ajax\//', $_SERVER['REQUEST_URI']) &&
    !preg_match('/\/api/', $_SERVER['REQUEST_URI']) &&
    !preg_match('/admin\.php/', $_SERVER['REQUEST_URI']) &&
    !preg_match('/xray\.html/', $_SERVER['REQUEST_URI']) &&
    !defined('NO_DATABASE_CONNECTION')
) {

    $maincycleUpdate = (int)getGlobal('ThisComputer.cycle_mainRun');
    $maincycleTimeout = 60;
    $maincycleTimePassed = time() - $maincycleUpdate;
    if ($maincycleTimePassed > $maincycleTimeout) { //main cycle is offline
        if ($_GET['system_call']) {
            echo "Main cycle is down (timeout: $maincycleTimePassed)";
        } else {
            echo "<html><head>
<meta http-equiv=\"Content-Type\" content=\"text/html; charset=utf-8\" />
 <meta http-equiv=\"refresh\" content=\"10\">
<link rel=\"stylesheet\" href=\"https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css\" integrity=\"sha384-BVYiiSIFeK1dGmJRAkycuHAHRg32OmUcww7on3RYdg4Va+PmSTsz/K68vbdEjh4u\" crossorigin=\"anonymous\">
<link rel=\"stylesheet\" href=\"https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap-theme.min.css\" integrity=\"sha384-rHyoN1iRsVXV4nD0JutlnGaslCJuC7uwjduW9SVrLvRYooPp2bWYgmgJQIXwl/Sp\" crossorigin=\"anonymous\">
<script src=\"https://code.jquery.com/jquery-3.3.1.min.js\" type=\"text/javascript\"></script>
<script src=\"https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js\" integrity=\"sha384-Tc5IQib027qvyjSMfHjOMaLkfuWVxZxUPnCJA7l2mCWNIpG9mGCD8wGNIcPD7Txa\" crossorigin=\"anonymous\"></script>
</head><body><div class=\"container\">";
            echo "&nbsp;<div class='alert alert-danger'>" . LANG_MAINCYCLEDOWN . "</div>";
            echo '<p>' . LANG_MAINCYCLEDOWN_DETAILS . '<br/>(timeout: ' . $maincycleTimePassed . ')</p>';
            echo "<div>" . LANG_CONTROL_PANEL . ": <a href='" . ROOTHTML . "panel/xray.html?view_mode=services' class='btn btn-default'>Services</a>&nbsp;";
            echo "<a href='" . ROOTHTML . "panel/xray.html?view_mode=database' class='btn btn-default'>Database</a>&nbsp;";
            echo "<a href='" . ROOTHTML . "admin.php?md=panel&action=saverestore' class='btn btn-default'>" . LANG_SAVE_BACKUP . " / " . LANG_RESTORE . "</a>&nbsp;";
            echo "</div>&nbsp;";
            echo "</div></body></html>";
        }
        exit;
    }
}

if (isset($_SERVER['SERVER_ADDR']) && isset($_SERVER['SERVER_PORT'])) {
    Define('SERVER_URL', 'http://' . (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost'));
    Define('SERVER_ADDR', $_SERVER['SERVER_ADDR']);
} else {
    Define('SERVER_URL', 'http://localhost:80');
}

// Access control is delegated to the reverse proxy (nginx basic auth).
// The built-in HTTP Basic Auth check was removed together with the old
// control_access layer; EXT_ACCESS_* / REMOTE_* settings are no longer read.
