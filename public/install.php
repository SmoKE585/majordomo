<?php
/**
 * First-run setup entry point.
 *
 * The request lifecycle lives in MajorDoMo\Core\InstallApplication; this file
 * is only the public URL adapter. Keep it thin.
 *
 * @package MajorDoMo
 */

require dirname(__DIR__) . '/bootstrap.php';

(new \MajorDoMo\Core\InstallApplication(MD_APP_ROOT))->run();
