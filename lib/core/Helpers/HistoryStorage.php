<?php

/** System helpers. Public names and argument defaults are part of the module API. */

function createHistoryTable($value_id)
    {
        // TODO: legacy compatibility facade; new code uses the service in lib/core/.
        return \MajorDoMo\Core\Core::historyStorage()->table($value_id);
    }

function moveDataFromMainHistoryToTable($value_id)
    {
        // TODO: legacy compatibility facade; new code uses the service in lib/core/.
        return \MajorDoMo\Core\Core::historyStorage()->moveFromMain($value_id);
    }

function moveDataFromTableToMainHistory($value_id)
    {
        // TODO: legacy compatibility facade; new code uses the service in lib/core/.
        return \MajorDoMo\Core\Core::historyStorage()->moveToMain($value_id);
    }
