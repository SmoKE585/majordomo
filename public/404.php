<?php
require dirname(__DIR__) . '/bootstrap.php';

/**
 * Public 404 entry point (nginx `error_page 404 /404.php`).
 *
 * @package MajorDoMo
 */

$root = '/';
if (is_file(dirname(__DIR__) . '/config.php')) {
    include_once(dirname(__DIR__) . '/config.php');
    if (defined('ROOTHTML')) {
        $root = ROOTHTML;
    }
}

require_once(dirname(__DIR__) . '/lib/error_page.php');

mdj_render_404(array(
    'root' => $root,
    'area' => 'site',
    'path' => isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '',
));
