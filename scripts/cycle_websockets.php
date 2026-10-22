<?php

// Compatibility entry point for the existing cycle supervisor.
require_once dirname(__DIR__) . '/lib/core.class.php';
\MajorDoMo\Core\WebSockets\Runtime::run(dirname(__DIR__));
