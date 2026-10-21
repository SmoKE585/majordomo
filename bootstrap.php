<?php
/**
 * Application bootstrap for public entry points.
 *
 * The document root is public/, application code lives one level up.
 * Legacy entry points rely on relative includes ("./config.php", "./lib/..."),
 * so we switch the working directory to the application root before they run.
 *
 * @package MajorDoMo
 */

if (!defined('MD_APP_ROOT')) {
    define('MD_APP_ROOT', __DIR__);
    chdir(MD_APP_ROOT);
}

// Make MajorDoMo\Core\* available to the thin entry points before config.php
// runs. The legacy loader includes this file again with require_once semantics.
require_once __DIR__ . '/lib/core.class.php';