<?php

spl_autoload_register(function ($class) {
    $prefix = 'MajorDoMo\\Core\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) return;
    $name = substr($class, strlen($prefix));
    if (!preg_match('/^[A-Za-z][A-Za-z0-9]*$/D', $name)) return;
    $file = __DIR__ . '/core/' . $name . '.php';
    if (is_file($file)) require_once $file;
});
