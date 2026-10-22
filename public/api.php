<?php
/** Public API adapter. Routing, access control and JSON live in the core. */
require dirname(__DIR__) . '/bootstrap.php';
(new \MajorDoMo\Core\ApiApplication(MD_APP_ROOT))->run();
