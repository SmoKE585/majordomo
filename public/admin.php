<?php
/**
 * Admin entry point.
 *
 * The request lifecycle lives in MajorDoMo\Core\AdminApplication; this file is
 * only the public URL adapter. Keep it thin.
 *
 * @package MajorDoMo
 */

require dirname(__DIR__) . '/bootstrap.php';

(new \MajorDoMo\Core\AdminApplication(MD_APP_ROOT))->run();
