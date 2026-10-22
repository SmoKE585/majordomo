<?php

/**
 * One entry point for the public PHP helpers, after application configuration.
 * Declarations only: the legacy loader still owns database and settings boot.
 * Include the compatibility paths so a later legacy include_once stays a no-op.
 */
require_once dirname(__DIR__, 2) . '/core.class.php';

require_once dirname(__DIR__, 2) . '/perfmonitor.class.php';
require_once dirname(__DIR__, 2) . '/general.class.php';
require_once dirname(__DIR__, 2) . '/objects.class.php';
require_once dirname(__DIR__, 2) . '/common.class.php';
require_once dirname(__DIR__, 2) . '/caching.class.php';
require_once dirname(__DIR__, 2) . '/redis_helper.class.php';
require_once dirname(__DIR__, 2) . '/events.class.php';
require_once dirname(__DIR__, 2) . '/hooks.inc.php';
require_once dirname(__DIR__, 2) . '/messages.class.php';
require_once dirname(__DIR__, 2) . '/context.inc.php';
require_once dirname(__DIR__, 2) . '/modes.php';
require_once dirname(__DIR__, 2) . '/add.class.php';
require_once dirname(__DIR__, 2) . '/sqlFunctions.class.php';
require_once dirname(__DIR__, 2) . '/syncfiles.class.php';
require_once dirname(__DIR__, 2) . '/terminals.class.php';
require_once dirname(__DIR__, 2) . '/python.inc.php';
require_once dirname(__DIR__, 2) . '/php_check.class.php';
require_once dirname(__DIR__, 2) . '/xml.class.php';
require_once dirname(__DIR__, 2) . '/xml_data.class.php';
