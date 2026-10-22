<?php

/** System helpers. Public names and argument defaults are part of the module API. */

function mjdGetRedisConnection()
    {
        // TODO: legacy compatibility facade; new code uses the service in lib/core/.
        return \MajorDoMo\Core\Core::redis()->get();
    }
