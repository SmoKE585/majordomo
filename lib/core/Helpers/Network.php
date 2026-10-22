<?php

/** System helpers. Public names and argument defaults are part of the module API. */

/**
 * Summary of isOnline
 * @param mixed $host Host
 * @return int
 */
function isOnline($host)
{
    $sqlQuery = "SELECT *
                  FROM pinghosts
                 WHERE HOSTNAME = '" . DBSafe($host) . "'
                    OR TITLE =    '" . DBSafe($host) . "'";

    $rec = SQLSelectOne($sqlQuery);
    if (!isset($rec['STATUS']) || !$rec['STATUS'] || $rec['STATUS'] == 2) {
        return 0;
    } else {
        return 1;
    }
}

/**
 * Ping host
 * @param mixed $host Host address
 * @return bool
 */
function ping($host)
{
    if (IsWindowsOS())
        exec(sprintf('ping -n 1 %s', escapeshellarg($host)), $res, $rval);
    elseif (substr(php_uname(), 0, 7) === "FreeBSD")
        exec(sprintf('ping -c 1 -t 5 %s', escapeshellarg($host)), $res, $rval);
    else
        exec(sprintf('ping -c 1 -W 5 %s', escapeshellarg($host)), $res, $rval);

    return $rval === 0 && preg_match('/ttl/is', join('', $res));
}
