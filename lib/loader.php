<?php
/**
 * Libraries loader
 *
 * Used to load required libraries
 *
 * @author Serge Dzheigalo <jey@activeunit.com>
 * @package framework
 * @copyright ActiveUnit, Inc. 2001-2004
 * @version 1.0
 * @modified 01-Jan-2004
 */

if (isset($_SERVER['REQUEST_URI'])) {
    Define("THIS_URL", $_SERVER['REQUEST_URI']);
}

// Direct module/script endpoints include the loader without going through a
// public/Application entry point. Keep them behind the setup gate too. CLI
// cycles are deliberately excluded: they must keep running on a fresh system.
// The guard runs before library includes and the DB connection.
if (PHP_SAPI !== 'cli' && class_exists('\\MajorDoMo\\Core\\Install\\InstallGate')) {
    \MajorDoMo\Core\Install\InstallGate::redirectIfRequired(dirname(__DIR__));
    \MajorDoMo\Core\Auth\AuthGate::enforce(dirname(__DIR__));
}

$ignore_libs = array();
if (function_exists('mysqli_connect')) {
    $ignore_libs[] = 'mysql.class.php';
} else {
    $ignore_libs[] = 'mysqli.class.php';
}

$preload_libraries = array(
    'perfmonitor.class.php',
    'general.class.php',
    'module.class.php',
    'errors.class.php',
    'core.class.php',
    'redis_helper.class.php',
    'objects.class.php',
    'common.class.php',
    'caching.class.php'
);
foreach($preload_libraries as $lib_file) {
    if (file_exists('./lib/'.$lib_file)) include_once('./lib/'.$lib_file);
    $ignore_libs[] = $lib_file;
}

$ignore_libs[] = 'loader.php';
$ignore_libs[] = 'threads.php';

// Register all public system helpers through their compatibility entry points.
require_once __DIR__ . '/core/Helpers/bootstrap.php';

// liblary modules loader
if ($lib_dir = @opendir("./lib")) {
    while (($lib_file = readdir($lib_dir)) !== false) {
        if ((preg_match("/\.php$/", $lib_file)) && !in_array($lib_file, $ignore_libs)) {
            include_once('./lib/'.$lib_file);
        }
    }
    closedir($lib_dir);

}

if (defined('DB_HOST') && DB_HOST) {
    startMeasure('db_connection');
    global $db;
    $db = new mysql(
        DB_HOST,
        \MajorDoMo\Core\Config::string('DB_PORT'),
        \MajorDoMo\Core\Config::string('DB_USER'),
        \MajorDoMo\Core\Config::string('DB_PASSWORD'),
        \MajorDoMo\Core\Config::string('DB_NAME')
    );
    endMeasure('db_connection');
}
