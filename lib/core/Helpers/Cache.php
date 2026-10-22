<?php

/** System helpers. Public names and argument defaults are part of the module API. */

function checkCycleFromCache($key)
    {
        // TODO: legacy compatibility facade; new code uses the service in lib/core/.
        return \MajorDoMo\Core\Core::cache()->cycle($key);
    }

/**
 * Summary of checkFromCache
 * @param mixed $key Key
 * @return mixed
 */
function checkFromCache($key)
    {
        // TODO: legacy compatibility facade; new code uses the service in lib/core/.
        return \MajorDoMo\Core\Core::cache()->get($key);
    }

/**
 * Summary of clearCacheData
 * @param mixed $prefix prefix
 * @return void
 */
function clearCacheData($prefix = '')
    {
        // TODO: legacy compatibility facade; new code uses the service in lib/core/.
        return \MajorDoMo\Core\Core::cache()->clear($prefix);
    }

function deleteFromCache($key)
    {
        // TODO: legacy compatibility facade; new code uses the service in lib/core/.
        return \MajorDoMo\Core\Core::cache()->delete($key);
    }

function ensureCachedValuesTable()
    {
        // TODO: legacy compatibility facade; new code uses the service in lib/core/.
        return \MajorDoMo\Core\Core::cache()->ensureSqlTable();
    }

/**
 *  Return all Cache Data from prefix
 * Summary of getAllCache
 * @param mixed $prefix
 * @return array
 */
function getAllCache($prefix = '')
    {
        // TODO: legacy compatibility facade; new code uses the service in lib/core/.
        return \MajorDoMo\Core\Core::cache()->all($prefix);
    }

function saveCycleToCache($key, $value)
    {
        // TODO: legacy compatibility facade; new code uses the service in lib/core/.
        return \MajorDoMo\Core\Core::cache()->saveCycle($key, $value);
    }

/**
 * Summary of saveToCache
 * @param mixed $key Key
 * @param mixed $value Value
 * @return void
 */
function saveToCache($key, $value, $only_if_missing = false)
    {
        // TODO: legacy compatibility facade; new code uses the service in lib/core/.
        return \MajorDoMo\Core\Core::cache()->set($key, $value, $only_if_missing);
    }
