<?php

/**
 * Dashboard data collector.
 *
 * The admin dashboard is rendered by the shell and hydrated by a small AJAX
 * endpoint (modules/inc_panel_ajax.php, op=dashboard). This file keeps the
 * server-side collection in one place: resources, database, counts, cycles,
 * errors and the 24-hour activity slice.
 *
 * Nothing here changes application state. The only write is a tiny runtime
 * cache in cms/cached used to calculate a live queries-per-second value
 * between two dashboard polls.
 */

if (!defined('ROOT')) {
    http_response_code(404);
    exit;
}

/**
 * Human readable bytes.
 *
 * @param float|null $bytes
 * @param int        $precision
 * @return string
 */
function mdj_dashboard_format_bytes($bytes, $precision = 1)
{
    if ($bytes === null || !is_numeric($bytes)) {
        return '—';
    }
    $bytes = max(0, (float)$bytes);
    $units = array('Б', 'КБ', 'МБ', 'ГБ', 'ТБ');
    $index = 0;
    while ($bytes >= 1024 && $index < count($units) - 1) {
        $bytes /= 1024;
        $index++;
    }
    $decimals = $index === 0 ? 0 : $precision;
    return number_format($bytes, $decimals, ',', ' ') . ' ' . $units[$index];
}

/**
 * Russian plural form for dashboard sentences.
 *
 * @param int    $number
 * @param string $one
 * @param string $few
 * @param string $many
 * @return string
 */
function mdj_dashboard_plural($number, $one, $few, $many)
{
    $number = abs((int)$number) % 100;
    $last = $number % 10;
    if ($number > 10 && $number < 20) {
        return $many;
    }
    if ($last > 1 && $last < 5) {
        return $few;
    }
    if ($last === 1) {
        return $one;
    }
    return $many;
}

/**
 * Compact uptime label.
 *
 * @param int|null $seconds
 * @return string
 */
function mdj_dashboard_format_uptime($seconds)
{
    if ($seconds === null || !is_numeric($seconds)) {
        return '—';
    }
    $seconds = max(0, (int)$seconds);
    $days = (int)floor($seconds / 86400);
    $hours = (int)floor(($seconds % 86400) / 3600);
    $minutes = (int)floor(($seconds % 3600) / 60);

    if ($days > 0) {
        return $days . ' д ' . $hours . ' ч';
    }
    if ($hours > 0) {
        return $hours . ' ч ' . $minutes . ' мин';
    }
    return $minutes . ' мин';
}

/**
 * Parsed /proc/meminfo.
 *
 * @return array{total:float,available:float}|null
 */
function mdj_dashboard_memory_info()
{
    if (!is_readable('/proc/meminfo')) {
        return null;
    }
    $raw = @file_get_contents('/proc/meminfo');
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    $values = array();
    foreach (preg_split('/\r?\n/', $raw) as $line) {
        if (preg_match('/^([A-Za-z_()]+):\s+(\d+)\s+kB$/u', trim($line), $match)) {
            $values[$match[1]] = (float)$match[2] * 1024;
        }
    }
    $total = isset($values['MemTotal']) ? $values['MemTotal'] : 0;
    $available = 0;
    if (isset($values['MemAvailable'])) {
        $available = $values['MemAvailable'];
    } elseif (isset($values['MemFree'])) {
        $available = $values['MemFree']
            + (isset($values['Buffers']) ? $values['Buffers'] : 0)
            + (isset($values['Cached']) ? $values['Cached'] : 0);
    }
    if ($total <= 0) {
        return null;
    }
    return array('total' => $total, 'available' => min($total, $available));
}

/**
 * Windows system metrics via PowerShell, cached for two minutes.
 *
 * wmic is absent on current Windows builds, so the fallback uses a single
 * PowerShell call for CPU, memory and boot time. The result is cached in
 * cms/cached to keep dashboard polling inexpensive.
 *
 * @return array{cpu_percent:float|null,memory_total:float|null,memory_available:float|null,uptime:int|null}|null
 */
function mdj_dashboard_windows_metrics()
{
    if (PHP_OS_FAMILY !== 'Windows' || !function_exists('exec')) {
        return null;
    }

    $file = mdj_dashboard_cache_dir() . 'dashboard_windows_metrics.json';
    $now = time();
    if (is_readable($file)) {
        $cached = json_decode((string)@file_get_contents($file), true);
        if (is_array($cached) && isset($cached['at']) && ($now - (int)$cached['at']) < 120) {
            unset($cached['at']);
            return $cached;
        }
    }

    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    if (in_array('exec', $disabled, true)) {
        return null;
    }

    $script = <<<'POWERSHELL'
$os=Get-CimInstance Win32_OperatingSystem; $cpu=(Get-CimInstance Win32_Processor | Measure-Object -Property LoadPercentage -Average).Average; Write-Output ('CPU=' + [math]::Round($cpu,1)); Write-Output ('MEMTOTAL=' + ($os.TotalVisibleMemorySize * 1024)); Write-Output ('MEMFREE=' + ($os.FreePhysicalMemory * 1024)); Write-Output ('UPTIME=' + [int](((Get-Date) - $os.LastBootUpTime).TotalSeconds))
POWERSHELL;
    $command = 'powershell -NoProfile -NonInteractive -Command "' . $script . '" 2>NUL';

    $output = array();
    @exec($command, $output);
    $values = array(
        'cpu_percent' => null,
        'memory_total' => null,
        'memory_available' => null,
        'uptime' => null,
    );
    foreach ($output as $line) {
        if (preg_match('/^CPU=([\d.]+)/i', trim($line), $match)) {
            $values['cpu_percent'] = min(100, max(0, (float)$match[1]));
        } elseif (preg_match('/^MEMTOTAL=(\d+)/i', trim($line), $match)) {
            $values['memory_total'] = (float)$match[1];
        } elseif (preg_match('/^MEMFREE=(\d+)/i', trim($line), $match)) {
            $values['memory_available'] = (float)$match[1];
        } elseif (preg_match('/^UPTIME=(\d+)/i', trim($line), $match)) {
            $values['uptime'] = (int)$match[1];
        }
    }

    if ($values['cpu_percent'] === null && $values['memory_total'] === null && $values['uptime'] === null) {
        return null;
    }

    @file_put_contents($file, json_encode($values + array('at' => $now), JSON_UNESCAPED_UNICODE), LOCK_EX);

    return $values;
}

/**
 * CPU cores and load average.
 *
 * @param array|null $windows Pre-collected Windows metrics.
 * @return array{cores:int,load1:float|null,load5:float|null,load15:float|null,percent:float|null,source:string}
 */
function mdj_dashboard_cpu_info($windows = null)
{
    $cores = 1;
    if (is_readable('/proc/cpuinfo')) {
        $cpuinfo = @file_get_contents('/proc/cpuinfo');
        if (is_string($cpuinfo)) {
            $count = preg_match_all('/^processor\s*:/m', $cpuinfo);
            if ($count > 0) {
                $cores = $count;
            }
        }
    } elseif (getenv('NUMBER_OF_PROCESSORS')) {
        $cores = max(1, (int)getenv('NUMBER_OF_PROCESSORS'));
    }

    $load = null;
    if (is_readable('/proc/loadavg')) {
        $pieces = preg_split('/\s+/', trim((string)@file_get_contents('/proc/loadavg')));
        if (count($pieces) >= 3) {
            $load = array((float)$pieces[0], (float)$pieces[1], (float)$pieces[2]);
        }
    }
    if ($load === null && function_exists('sys_getloadavg')) {
        $average = @sys_getloadavg();
        if (is_array($average) && count($average) >= 3) {
            $load = array((float)$average[0], (float)$average[1], (float)$average[2]);
        }
    }

    $percent = null;
    $source = 'load';
    if ($load !== null) {
        $percent = min(100, max(0, ($load[0] / $cores) * 100));
    } elseif (is_array($windows) && isset($windows['cpu_percent']) && $windows['cpu_percent'] !== null) {
        $percent = (float)$windows['cpu_percent'];
        $source = 'powershell';
    } elseif (PHP_OS_FAMILY === 'Windows' && function_exists('exec')) {
        $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
        if (!in_array('exec', $disabled, true)) {
            $output = array();
            @exec('wmic cpu get loadpercentage /value 2>NUL', $output);
            foreach ($output as $line) {
                if (preg_match('/LoadPercentage=(\d+)/i', $line, $match)) {
                    $percent = min(100, max(0, (float)$match[1]));
                    $source = 'wmic';
                    break;
                }
            }
        }
    }

    return array(
        'cores' => $cores,
        'load1' => $load ? $load[0] : null,
        'load5' => $load ? $load[1] : null,
        'load15' => $load ? $load[2] : null,
        'percent' => $percent === null ? null : round($percent, 1),
        'source' => $source,
    );
}

/**
 * System uptime in seconds.
 *
 * @param array|null $windows Pre-collected Windows metrics.
 * @return int|null
 */
function mdj_dashboard_uptime_seconds($windows = null)
{
    if (is_readable('/proc/uptime')) {
        $raw = trim((string)@file_get_contents('/proc/uptime'));
        if ($raw !== '') {
            $parts = preg_split('/\s+/', $raw);
            if (isset($parts[0]) && is_numeric($parts[0])) {
                return (int)$parts[0];
            }
        }
    }

    if (is_array($windows) && isset($windows['uptime']) && $windows['uptime'] !== null) {
        return max(0, (int)$windows['uptime']);
    }

    if (PHP_OS_FAMILY === 'Windows' && function_exists('exec')) {
        $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
        if (!in_array('exec', $disabled, true)) {
            $output = array();
            @exec('wmic os get lastbootuptime /value 2>NUL', $output);
            foreach ($output as $line) {
                if (preg_match('/LastBootUpTime=(\d{14})/i', $line, $match)) {
                    $boot = \DateTime::createFromFormat('YmdHis', $match[1]);
                    if ($boot instanceof \DateTime) {
                        return max(0, time() - $boot->getTimestamp());
                    }
                }
            }
        }
    }

    return null;
}

/**
 * Windows physical memory from wmic or pre-collected PowerShell metrics.
 *
 * @param array|null $windows
 * @return array{total:float,available:float}|null
 */
function mdj_dashboard_memory_info_windows($windows = null)
{
    if (is_array($windows) && !empty($windows['memory_total'])) {
        $total = (float)$windows['memory_total'];
        $available = isset($windows['memory_available']) ? (float)$windows['memory_available'] : 0;
        return array('total' => $total, 'available' => min($total, $available));
    }

    if (PHP_OS_FAMILY !== 'Windows' || !function_exists('exec')) {
        return null;
    }
    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    if (in_array('exec', $disabled, true)) {
        return null;
    }
    $output = array();
    @exec('wmic OS get FreePhysicalMemory,TotalVisibleMemorySize /value 2>NUL', $output);
    $values = array();
    foreach ($output as $line) {
        if (preg_match('/^(FreePhysicalMemory|TotalVisibleMemorySize)=(\d+)/i', trim($line), $match)) {
            $values[strtolower($match[1])] = (float)$match[2] * 1024;
        }
    }
    if (empty($values['totalvisiblememorysize'])) {
        return null;
    }
    $total = $values['totalvisiblememorysize'];
    $available = isset($values['freephysicalmemory']) ? $values['freephysicalmemory'] : 0;
    return array('total' => $total, 'available' => min($total, $available));
}

/**
 * Dashboard cache directory.
 *
 * @return string
 */
function mdj_dashboard_cache_dir()
{
    $dir = ROOT . 'cms/cached';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return rtrim($dir, '/\\') . DIRECTORY_SEPARATOR;
}

/**
 * Read the tiny runtime state used for live qps and poll timestamps.
 *
 * @return array
 */
function mdj_dashboard_runtime_read()
{
    $file = mdj_dashboard_cache_dir() . 'dashboard_runtime.json';
    if (!is_readable($file)) {
        return array();
    }
    $raw = @file_get_contents($file);
    if (!is_string($raw) || $raw === '') {
        return array();
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : array();
}

/**
 * Persist runtime state. Failure is non-fatal: the dashboard only loses the
 * live qps delta and falls back to the average value.
 *
 * @param array $data
 * @return void
 */
function mdj_dashboard_runtime_write(array $data)
{
    $file = mdj_dashboard_cache_dir() . 'dashboard_runtime.json';
    @file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

/**
 * Table rows and size from SHOW TABLE STATUS.
 *
 * @param array $tables
 * @return array{rows:array<string,int>,size:int,total:int}
 */
function mdj_dashboard_tables_summary(array $tables)
{
    $rows = array();
    $size = 0;
    foreach ($tables as $table) {
        if (!is_array($table) || !isset($table['Name'])) {
            continue;
        }
        $name = strtolower((string)$table['Name']);
        $rows[$name] = isset($table['Rows']) ? (int)$table['Rows'] : 0;
        if (isset($table['Data_length'])) {
            $size += (int)$table['Data_length'];
        }
        if (isset($table['Index_length'])) {
            $size += (int)$table['Index_length'];
        }
    }
    return array('rows' => $rows, 'size' => $size, 'total' => count($rows));
}

/**
 * Cycle state derived from scripts/cycle_*.php and globals.
 *
 * @return array{items:array<int,array<string,mixed>>,counts:array<string,int>}
 */
function mdj_dashboard_cycles()
{
    $items = array();
    $counts = array('running' => 0, 'stopped' => 0, 'offline' => 0, 'disabled' => 0, 'total' => 0);
    $dir = ROOT . 'scripts';
    if (!is_dir($dir)) {
        return array('items' => array(), 'counts' => $counts);
    }

    $files = @scandir($dir);
    if (!is_array($files)) {
        return array('items' => array(), 'counts' => $counts);
    }

    $now = time();
    foreach ($files as $file) {
        if (!preg_match('/^cycle_(.+)\.php$/i', $file, $match)) {
            continue;
        }
        $title = $match[1];
        $disabled = (bool)getGlobal($title . 'Disabled');
        $run = (int)getGlobal($title . 'Run');
        $control = (string)getGlobal($title . 'Control');

        if ($disabled) {
            $status = 'disabled';
        } elseif ($run > 0 && ($now - $run) < 5 * 60) {
            $status = 'running';
        } elseif ($run > 0) {
            $status = 'stopped';
        } else {
            $status = 'offline';
        }

        if (isset($counts[$status])) {
            $counts[$status]++;
        }
        $counts['total']++;

        $items[] = array(
            'title' => $title,
            'status' => $status,
            'disabled' => $disabled ? 1 : 0,
            'control' => $control,
            'run' => $run > 0 ? $run : null,
            'run_label' => $run > 0 ? date('d.m.Y H:i', $run) : '',
            'since_update' => $run > 0 ? max(0, $now - $run) : null,
        );
    }

    usort($items, static function ($left, $right) {
        $priority = array('stopped' => 0, 'offline' => 1, 'running' => 2, 'disabled' => 3);
        $leftPriority = isset($priority[$left['status']]) ? $priority[$left['status']] : 9;
        $rightPriority = isset($priority[$right['status']]) ? $priority[$right['status']] : 9;
        if ($leftPriority !== $rightPriority) {
            return $leftPriority <=> $rightPriority;
        }
        return strnatcasecmp((string)$left['title'], (string)$right['title']);
    });

    return array('items' => $items, 'counts' => $counts);
}

/**
 * System errors summary.
 *
 * @return array{active:int,total:int,items:array<int,array<string,mixed>>}
 */
function mdj_dashboard_errors()
{
    $result = array('active' => 0, 'total' => 0, 'items' => array());
    if (!SQLTableExists('system_errors')) {
        return $result;
    }

    $records = SQLSelect("SELECT ID, TITLE, CODE, ACTIVE, LATEST_UPDATE, DETAILS FROM system_errors ORDER BY ACTIVE DESC, LATEST_UPDATE DESC LIMIT 6");
    if (!is_array($records)) {
        $records = array();
    }
    foreach ($records as $record) {
        if (!empty($record['ACTIVE'])) {
            $result['active'] += (int)$record['ACTIVE'];
        }
        if (count($result['items']) >= 4) {
            continue;
        }
        $result['items'][] = array(
            'id' => (int)$record['ID'],
            'title' => (string)$record['TITLE'],
            'code' => (string)$record['CODE'],
            'active' => !empty($record['ACTIVE']) ? 1 : 0,
            'latest_update' => (string)$record['LATEST_UPDATE'],
            'latest_label' => !empty($record['LATEST_UPDATE']) ? date('d.m.Y H:i', strtotime((string)$record['LATEST_UPDATE'])) : '',
            'details' => mb_substr(trim(strip_tags((string)$record['DETAILS'])), 0, 240, 'UTF-8'),
        );
    }

    $count = SQLSelectOne("SELECT COUNT(*) AS C FROM system_errors");
    $result['total'] = isset($count['C']) ? (int)$count['C'] : count($result['items']);

    return $result;
}

/**
 * Hourly activity slice for the last 24 hours.
 *
 * Uses the last update/execution timestamp, so the chart is bounded by the
 * number of properties and scripts instead of scanning a potentially huge
 * history table on every dashboard poll.
 *
 * @return array{labels:array<int,string>,properties:array<int,int>,scripts:array<int,int>,properties_total:int,scripts_total:int}
 */
function mdj_dashboard_activity()
{
    $labels = array();
    $keys = array();
    $properties = array();
    $scripts = array();
    $hour = (int)floor(time() / 3600) * 3600;

    for ($i = 23; $i >= 0; $i--) {
        $timestamp = $hour - ($i * 3600);
        $keys[] = date('Y-m-d H:00:00', $timestamp);
        $labels[] = date('H:00', $timestamp);
        $properties[] = 0;
        $scripts[] = 0;
    }

    $index = array_flip($keys);
    $from = date('Y-m-d H:i:s', $hour - (23 * 3600));

    $propertyRows = SQLSelect("SELECT DATE_FORMAT(UPDATED, '%Y-%m-%d %H:00:00') AS HOUR_KEY, COUNT(*) AS C FROM pvalues WHERE UPDATED >= '" . DBSafe($from) . "' GROUP BY HOUR_KEY");
    if (!is_array($propertyRows)) {
        $propertyRows = array();
    }
    foreach ($propertyRows as $row) {
        if (isset($index[$row['HOUR_KEY']])) {
            $position = $index[$row['HOUR_KEY']];
            $properties[$position] = (int)$row['C'];
        }
    }

    $scriptRows = SQLSelect("SELECT DATE_FORMAT(EXECUTED, '%Y-%m-%d %H:00:00') AS HOUR_KEY, COUNT(*) AS C FROM scripts WHERE EXECUTED >= '" . DBSafe($from) . "' GROUP BY HOUR_KEY");
    if (!is_array($scriptRows)) {
        $scriptRows = array();
    }
    foreach ($scriptRows as $row) {
        if (isset($index[$row['HOUR_KEY']])) {
            $position = $index[$row['HOUR_KEY']];
            $scripts[$position] = (int)$row['C'];
        }
    }

    return array(
        'labels' => $labels,
        'properties' => $properties,
        'scripts' => $scripts,
        'properties_total' => array_sum($properties),
        'scripts_total' => array_sum($scripts),
    );
}

/**
 * Build the full dashboard payload.
 *
 * @param bool $fresh Bypass the short payload cache (manual refresh).
 * @return array
 */
function mdj_dashboard_collect($fresh = false)
{
    $now = time();
    $cacheFile = mdj_dashboard_cache_dir() . 'dashboard_payload.json';
    $cacheTtl = 20;
    if (!$fresh && is_readable($cacheFile)) {
        $cached = json_decode((string)@file_get_contents($cacheFile), true);
        if (is_array($cached) && isset($cached['generated_ts']) && ($now - (int)$cached['generated_ts']) < $cacheTtl) {
            $cached['cached'] = true;
            return $cached;
        }
    }

    $tables = array();
    if (SQLTableExists('objects')) {
        $tables = SQLSelect("SHOW TABLE STATUS");
    }
    if (!is_array($tables)) {
        $tables = array();
    }
    $tableSummary = mdj_dashboard_tables_summary($tables);
    $rows = $tableSummary['rows'];

    $counts = array(
        'objects' => isset($rows['objects']) ? (int)$rows['objects'] : 0,
        'classes' => isset($rows['classes']) ? (int)$rows['classes'] : 0,
        'locations' => isset($rows['locations']) ? (int)$rows['locations'] : 0,
        'properties' => isset($rows['properties']) ? (int)$rows['properties'] : 0,
        'methods' => isset($rows['methods']) ? (int)$rows['methods'] : 0,
        'scripts' => isset($rows['scripts']) ? (int)$rows['scripts'] : 0,
        'users' => isset($rows['users']) ? (int)$rows['users'] : 0,
        'active_objects' => 0,
        'active_window_minutes' => 15,
        'devices_total' => 0,
        'devices_online' => 0,
        'devices_offline' => 0,
        'devices_available' => false,
    );

    $activeFrom = date('Y-m-d H:i:s', $now - ($counts['active_window_minutes'] * 60));
    $active = SQLSelectOne("SELECT COUNT(DISTINCT OBJECT_ID) AS C FROM pvalues WHERE UPDATED >= '" . DBSafe($activeFrom) . "'");
    $counts['active_objects'] = isset($active['C']) ? (int)$active['C'] : 0;

    if (SQLTableExists('pinghosts')) {
        $devices = SQLSelectOne("SELECT COUNT(*) AS TOTAL, SUM(CASE WHEN STATUS=1 THEN 1 ELSE 0 END) AS ONLINE, SUM(CASE WHEN STATUS=2 THEN 1 ELSE 0 END) AS OFFLINE FROM pinghosts");
        $counts['devices_total'] = isset($devices['TOTAL']) ? (int)$devices['TOTAL'] : 0;
        $counts['devices_online'] = isset($devices['ONLINE']) ? (int)$devices['ONLINE'] : 0;
        $counts['devices_offline'] = isset($devices['OFFLINE']) ? (int)$devices['OFFLINE'] : 0;
        $counts['devices_available'] = true;
    }

    $windows = mdj_dashboard_windows_metrics();
    $cpu = mdj_dashboard_cpu_info($windows);
    $memory = mdj_dashboard_memory_info();
    if ($memory === null) {
        $memory = mdj_dashboard_memory_info_windows($windows);
    }
    $memoryPercent = null;
    $memoryUsed = null;
    $memoryTotal = null;
    if ($memory !== null && $memory['total'] > 0) {
        $memoryTotal = $memory['total'];
        $memoryUsed = max(0, $memoryTotal - $memory['available']);
        $memoryPercent = round(($memoryUsed / $memoryTotal) * 100, 1);
    }

    $diskTotal = @disk_total_space(ROOT);
    $diskFree = @disk_free_space(ROOT);
    $diskUsed = null;
    $diskPercent = null;
    if ($diskTotal !== false && $diskFree !== false && $diskTotal > 0) {
        $diskUsed = max(0, $diskTotal - $diskFree);
        $diskPercent = round(($diskUsed / $diskTotal) * 100, 1);
    }

    // Database load. The delta between two polls gives a live qps; the average
    // since server start is used on the first poll.
    $statusRows = SQLSelect("SHOW GLOBAL STATUS");
    if (!is_array($statusRows)) {
        $statusRows = array();
    }
    $dbStatus = array();
    foreach ($statusRows as $row) {
        if (isset($row['Variable_name'])) {
            $dbStatus[$row['Variable_name']] = $row['Value'];
        }
    }
    $queries = isset($dbStatus['Queries']) ? (float)$dbStatus['Queries'] : 0;
    $dbUptime = isset($dbStatus['Uptime']) ? (float)$dbStatus['Uptime'] : 0;
    $qps = $dbUptime > 0 ? $queries / $dbUptime : 0;
    $qpsSource = 'average';

    $runtime = mdj_dashboard_runtime_read();
    if (!empty($runtime['queries']) && !empty($runtime['queries_at']) && $queries >= (float)$runtime['queries']) {
        $deltaSeconds = $now - (int)$runtime['queries_at'];
        if ($deltaSeconds > 0 && $deltaSeconds < 3600) {
            $deltaQueries = $queries - (float)$runtime['queries'];
            $qps = $deltaQueries / $deltaSeconds;
            $qpsSource = 'live';
        }
    }
    mdj_dashboard_runtime_write(array('queries' => $queries, 'queries_at' => $now));

    $connections = array(
        'running' => isset($dbStatus['Threads_running']) ? (int)$dbStatus['Threads_running'] : 0,
        'connected' => isset($dbStatus['Threads_connected']) ? (int)$dbStatus['Threads_connected'] : 0,
        'cached' => isset($dbStatus['Threads_cached']) ? (int)$dbStatus['Threads_cached'] : 0,
        'created' => isset($dbStatus['Threads_created']) ? (int)$dbStatus['Threads_created'] : 0,
        'max' => 0,
    );
    $maxConnections = SQLSelectOne("SELECT @@max_connections AS M");
    if (isset($maxConnections['M'])) {
        $connections['max'] = (int)$maxConnections['M'];
    }
    $connectionUsage = $connections['max'] > 0
        ? round($connections['connected'] / $connections['max'] * 100, 1)
        : 0;

    $dbLevel = 'ok';
    $dbStatusText = 'Нагрузка в норме';
    if ($qps >= 250 || $connectionUsage >= 80 || $connections['running'] >= 20) {
        $dbLevel = 'danger';
        $dbStatusText = 'Высокая нагрузка';
    } elseif ($qps >= 170 || $connectionUsage >= 60 || $connections['running'] >= 10) {
        $dbLevel = 'warning';
        $dbStatusText = 'Повышенная нагрузка';
    }

    $cycles = mdj_dashboard_cycles();
    $errors = mdj_dashboard_errors();
    $activity = mdj_dashboard_activity();
    $uptime = mdj_dashboard_uptime_seconds($windows);

    $attention = array();
    $addAttention = static function ($tone, $icon, $title, $text, $actionLabel, $actionUrl) use (&$attention) {
        $attention[] = array(
            'tone' => $tone,
            'icon' => $icon,
            'title' => $title,
            'text' => $text,
            'action_label' => $actionLabel,
            'action_url' => $actionUrl,
        );
    };

    if (file_exists(ROOT . 'reboot')) {
        $started = @filemtime(ROOT . 'reboot');
        // A reboot marker is cleared by the reboot routine. Markers older than
        // 30 minutes are stale leftovers (for example after a package update)
        // and must not keep the dashboard in a permanent red state.
        if ($started && (time() - $started) < 1800) {
            $addAttention(
                'danger',
                'restart',
                'Система перезагружается',
                'Перезагрузка запущена ' . date('d.m.Y H:i', $started) . '.',
                'Открыть обновление',
                '?action=saverestore'
            );
        }
    }

    if ($errors['active'] > 0) {
        $latest = isset($errors['items'][0]) ? $errors['items'][0] : null;
        $text = $latest && $latest['title'] !== '' ? 'Последняя: ' . $latest['title'] : 'Откройте журнал, чтобы посмотреть детали.';
        $addAttention(
            'danger',
            'alert-triangle',
            $errors['active'] . ' ' . mdj_dashboard_plural($errors['active'], 'активная ошибка', 'активные ошибки', 'активных ошибок'),
            $text,
            'Открыть ошибки',
            '?action=system_errors'
        );
    }

    $brokenCycles = $cycles['counts']['stopped'] + $cycles['counts']['offline'];
    if ($brokenCycles > 0) {
        $names = array();
        foreach ($cycles['items'] as $cycle) {
            if (in_array($cycle['status'], array('stopped', 'offline'), true)) {
                $names[] = $cycle['title'];
            }
            if (count($names) >= 3) {
                break;
            }
        }
        $addAttention(
            'warning',
            'repeat-off',
            $brokenCycles . ' ' . mdj_dashboard_plural($brokenCycles, 'цикл не работает', 'цикла не работают', 'циклов не работают'),
            $names ? 'Проверьте: ' . implode(', ', $names) : 'Откройте X-Ray и проверьте фоновые процессы.',
            'Проверить циклы',
            '?action=xray&md=xray&inst=adm&view_mode=services'
        );
    }

    if ($diskPercent !== null && $diskPercent >= 80) {
        $addAttention(
            $diskPercent >= 90 ? 'danger' : 'warning',
            'database',
            'Диск заполнен на ' . number_format($diskPercent, 0, ',', ' ') . '%',
            'Свободно ' . mdj_dashboard_format_bytes($diskFree) . '. Проверьте резервные копии и логи.',
            'Открыть X-Ray',
            '?action=xray&md=xray&inst=adm&view_mode=database'
        );
    }

    if ($memoryPercent !== null && $memoryPercent >= 80) {
        $addAttention(
            $memoryPercent >= 90 ? 'danger' : 'warning',
            'cpu',
            'Память занята на ' . number_format($memoryPercent, 0, ',', ' ') . '%',
            'Использовано ' . mdj_dashboard_format_bytes($memoryUsed) . ' из ' . mdj_dashboard_format_bytes($memoryTotal) . '.',
            'Проверить нагрузку',
            '?action=xray&md=xray&inst=adm&view_mode=performance'
        );
    }

    if ($cpu['percent'] !== null && $cpu['percent'] >= 75) {
        $addAttention(
            $cpu['percent'] >= 90 ? 'danger' : 'warning',
            'activity',
            'Высокая нагрузка CPU',
            'Средняя загрузка за 1 минуту — ' . number_format($cpu['percent'], 1, ',', ' ') . '% на ' . $cpu['cores'] . ' ядер.',
            'Открыть X-Ray',
            '?action=xray&md=xray&inst=adm&view_mode=performance'
        );
    }

    if ($counts['devices_available'] && $counts['devices_offline'] > 0) {
        $addAttention(
            'warning',
            'server-off',
            $counts['devices_offline'] . ' ' . mdj_dashboard_plural($counts['devices_offline'], 'устройство offline', 'устройства offline', 'устройств offline'),
            'Проверьте питание и сеть. Всего устройств: ' . $counts['devices_total'] . '.',
            'Открыть Online',
            '?action=pinghosts'
        );
    }

    if ($dbLevel === 'danger') {
        $addAttention(
            'danger',
            'database',
            'Высокая нагрузка на базу данных',
            'Запросов в секунду: ' . number_format($qps, 0, ',', ' ') . ', соединений: ' . $connections['connected'] . ' из ' . $connections['max'] . '.',
            'Открыть X-Ray',
            '?action=xray&md=xray&inst=adm&view_mode=database'
        );
    } elseif ($dbLevel === 'warning') {
        $addAttention(
            'warning',
            'database',
            'Повышенная нагрузка на базу данных',
            'Запросов в секунду: ' . number_format($qps, 0, ',', ' ') . ', соединений: ' . $connections['connected'] . ' из ' . $connections['max'] . '.',
            'Открыть X-Ray',
            '?action=xray&md=xray&inst=adm&view_mode=database'
        );
    }

    $severity = array('danger' => 0, 'warning' => 1, 'info' => 2, 'success' => 3);
    usort($attention, static function ($left, $right) use ($severity) {
        $leftPriority = isset($severity[$left['tone']]) ? $severity[$left['tone']] : 9;
        $rightPriority = isset($severity[$right['tone']]) ? $severity[$right['tone']] : 9;
        return $leftPriority <=> $rightPriority;
    });
    $attention = array_slice($attention, 0, 4);

    $payload = array(
        'generated_at' => date('Y-m-d H:i:s'),
        'generated_ts' => $now,
        'cached' => false,
        'system' => array(
            'os' => PHP_OS_FAMILY . ' ' . php_uname('r'),
            'php' => PHP_VERSION,
            'server' => isset($_SERVER['SERVER_SOFTWARE']) ? (string)$_SERVER['SERVER_SOFTWARE'] : '',
            'host' => (string)@gethostname(),
            'uptime' => array(
                'seconds' => $uptime,
                'label' => mdj_dashboard_format_uptime($uptime),
            ),
            'cpu' => $cpu,
            'memory' => array(
                'total' => $memoryTotal,
                'used' => $memoryUsed,
                'available' => $memory !== null ? $memory['available'] : null,
                'percent' => $memoryPercent,
            ),
            'disk' => array(
                'total' => $diskTotal !== false ? (float)$diskTotal : null,
                'used' => $diskUsed,
                'free' => $diskFree !== false ? (float)$diskFree : null,
                'percent' => $diskPercent,
            ),
        ),
        'db' => array(
            'tables' => $tableSummary['total'],
            'size' => $tableSummary['size'],
            'size_label' => mdj_dashboard_format_bytes($tableSummary['size']),
            'queries' => (int)$queries,
            'qps' => round($qps, 1),
            'qps_source' => $qpsSource,
            'connections' => $connections,
            'connection_usage' => $connectionUsage,
            'level' => $dbLevel,
            'status_text' => $dbStatusText,
            'uptime' => (int)$dbUptime,
        ),
        'counts' => $counts,
        'cycles' => $cycles,
        'errors' => $errors,
        'activity' => $activity,
        'attention' => $attention,
    );

    @file_put_contents($cacheFile, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);

    return $payload;
}
