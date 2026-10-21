<?php

require_once __DIR__ . '/core.class.php';

function mjdGetRedisConnection()
    {
        // TODO: legacy compatibility facade; new code uses the service in lib/core/.
        return \MajorDoMo\Core\Core::redis()->get();
    }
