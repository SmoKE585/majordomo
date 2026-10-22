<?php

// Shared by public/bootstrap, config.php and the legacy loader (including CLI
// cycles). Package classes and autoload.files must be available before config.
if (is_file(dirname(__DIR__) . '/vendor/autoload.php')) {
    require_once dirname(__DIR__) . '/vendor/autoload.php';
}

// Keep the local core loader for installations that have not run Composer yet.
// Legacy libraries in 3rdparty/ continue to use their existing includes.
spl_autoload_register(function ($class) {
    $prefix = 'MajorDoMo\\Core\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) return;
    $name = substr($class, strlen($prefix));
    // Flat core classes (Database) and namespaced feature packages
    // (Install\InstallState -> lib/core/Install/InstallState.php).
    if (!preg_match('/^[A-Za-z][A-Za-z0-9]*(?:\\\\[A-Za-z][A-Za-z0-9]*)*$/D', $name)) return;
    $file = __DIR__ . '/core/' . str_replace('\\', '/', $name) . '.php';
    if (is_file($file)) require_once $file;
});
