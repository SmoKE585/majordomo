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