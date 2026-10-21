<?php
/**
 * Public site entry point.
 *
 * The request lifecycle lives in MajorDoMo\Core\SiteApplication; this file is
 * only the public URL adapter. Keep it thin.
 *
 * @package MajorDoMo
 */

require dirname(__DIR__) . '/bootstrap.php';

(new \MajorDoMo\Core\SiteApplication(MD_APP_ROOT))->run();
