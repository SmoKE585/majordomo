<?php

/*
 * @version 0.1 (auto-set)
 */

if (!function_exists('DebMes')) {
    chdir(dirname(__FILE__) . '/../');
    include_once("./config.php");
    include_once("./lib/loader.php");
    include_once("./load_settings.php");
    set_time_limit(10 * 60);
}

if (isset($_SERVER['REQUEST_URI'])) {
    DebMes("Running by URL: " . $_SERVER['REQUEST_URI'], 'maintenance');
} elseif (isset($argv[0])) {
    DebMes("Running from command line: " . implode(' ', $argv), 'maintenance');
}

echo "<pre>\n";

DebMes("Running maintenance script", 'maintenance');

if (defined('LANG_MODULE_SAVERESTORE')) {
    SQLExec("UPDATE project_modules SET TITLE='" . DBSafe(LANG_MODULE_SAVERESTORE) . "' WHERE NAME='saverestore'");
}

// Remove legacy paid Connect module records. The module files are no longer shipped.
DebMes("Removing Connect module records.", 'maintenance');
SQLExec("DELETE FROM project_modules WHERE NAME='connect'");
SQLExec("DELETE FROM settings WHERE NAME='MODULE_CONNECT'");
SQLExec("DELETE FROM pvalues WHERE PROPERTY_NAME LIKE '%connect%'");
SQLExec("DELETE FROM phistory WHERE VALUE_ID NOT IN (SELECT ID FROM pvalues)");
SQLExec("DELETE FROM properties WHERE TITLE LIKE '%connect%'");
SQLExec("DELETE FROM events WHERE EVENT_NAME LIKE '%connect%'");
if (SQLTableExists('history')) {
    $history_conditions = array();
    if (isset(SQLSelectOne("SHOW COLUMNS FROM `history` LIKE 'LINKED_OBJECT'")['Field'])) {
        $history_conditions[] = "LINKED_OBJECT LIKE 'connect'";
    }
    if (isset(SQLSelectOne("SHOW COLUMNS FROM `history` LIKE 'LINKED_METHOD'")['Field'])) {
        $history_conditions[] = "LINKED_METHOD LIKE '%connect%'";
    }
    if (count($history_conditions) > 0) {
        SQLExec("DELETE FROM history WHERE (" . implode(' OR ', $history_conditions) . ")");
    }
}
SQLExec("UPDATE settings SET VALUE=REPLACE(VALUE, '\"connect\":{\"filter\":\"\"},', '') WHERE NAME LIKE 'HOOK_EVENT_%'");
SQLExec("UPDATE settings SET VALUE=REPLACE(VALUE, ',\"connect\":{\"filter\":\"\"}', '') WHERE NAME LIKE 'HOOK_EVENT_%'");

// Remove legacy Connect module files.
$connect_paths = array(
    ROOT . 'modules/connect',
    ROOT . 'templates/connect',
);
foreach ($connect_paths as $connect_path) {
    if (is_dir($connect_path) && function_exists('removeTree')) {
        removeTree($connect_path);
        DebMes("Removed legacy Connect path: $connect_path", 'maintenance');
    }
}

$connect_cycle = ROOT . 'scripts/cycle_connect.php';
if (file_exists($connect_cycle)) {
    @unlink($connect_cycle);
    DebMes("Removed legacy Connect cycle: $connect_cycle", 'maintenance');
}

$connect_images = array(
    ROOT . 'img/connect_back_block.png',
    ROOT . 'img/modules/connect.png',
);
foreach ($connect_images as $img) {
    if (file_exists($img)) {
        @unlink($img);
        DebMes("Removed legacy Connect image: $img", 'maintenance');
    }
}

// Remove legacy hook subscribers for deleted user systems.
DebMes("Removing legacy hook subscribers for deleted user systems.", 'maintenance');
$legacy_hook_subscribers = array('patterns', 'terminals');
$hook_settings = SQLSelect("SELECT * FROM settings WHERE NAME LIKE 'HOOK_EVENT_%' AND TYPE='json'");
$hook_total = count($hook_settings);
for ($i = 0; $i < $hook_total; $i++) {
    $hook_data = json_decode($hook_settings[$i]['VALUE'], true);
    if (!is_array($hook_data)) {
        continue;
    }

    $hook_changed = false;
    foreach ($legacy_hook_subscribers as $subscriber_name) {
        if (isset($hook_data[$subscriber_name])) {
            unset($hook_data[$subscriber_name]);
            $hook_changed = true;
        }
    }

    if ($hook_changed) {
        $hook_settings[$i]['VALUE'] = json_encode($hook_data, JSON_UNESCAPED_UNICODE);
        SQLUpdate('settings', $hook_settings[$i]);
    }
}

$config_file = ROOT . 'config.php';
if (file_exists($config_file)) {
    $config_content = LoadFile($config_file);

    // Remove MODULE_CONNECT entries, including broken plain-text leftovers:
    // 'MODULE_CONNECT' => '',
    // "MODULE_CONNECT"   =>   ""   ,  // comment
    // 'MODULE_CONNECT' => '1',
    // 'MODULE_CONNECT'=>'',<br>
    // 'MODULE_CONNECT'=&gt;'',<br>
    $config_content_clean = preg_replace(
        '/[ \t]*[\'"]MODULE_CONNECT[\'"][ \t]*(?:=>|=&gt;)[ \t]*[\'"][^\'"]*?[\'"][ \t]*,?[ \t]*(?:<br\s*\/?>)?[ \t]*(?:\/\/[^\r\n]*)?(?:\r?\n)?/i',
        '',
        $config_content
    );

    if ($config_content_clean !== $config_content) {
        SaveFile($config_file, $config_content_clean);
        DebMes("Removed legacy MODULE_CONNECT entries from config.php.", 'maintenance');
    }
}

// BACKUP DATABASE AND FILES

if (defined('SETTINGS_BACKUP_PATH') && SETTINGS_BACKUP_PATH != '' && is_dir(SETTINGS_BACKUP_PATH)) {
    $backups_dir = SETTINGS_BACKUP_PATH;
    $target_dir = $backups_dir;
    if (substr($target_dir, -1) != '/' && substr($target_dir, -1) != '\\')
        $target_dir .= '/';
    $target_dir .= date('Ymd');
} else {
    $backups_dir = DOC_ROOT . '/backup';
    $target_dir = $backups_dir . '/' . date('Ymd');
}

$full_backup = 0;

if (!is_dir($target_dir)) {
    mkdir($target_dir, 0777);
    $full_backup = 1;
}


if (!defined('LOG_FILES_EXPIRE')) {
    define('LOG_FILES_EXPIRE', 5);
}
if (!defined('BACKUP_FILES_EXPIRE')) {
    define('BACKUP_FILES_EXPIRE', 30);
}
if (!defined('CACHED_FILES_EXPIRE')) {
    define('CACHED_FILES_EXPIRE', 30);
}


echo "Target: " . $target_dir . PHP_EOL;
echo "Full backup: " . $full_backup . PHP_EOL;

sleep(2);

//removing old log files
if (defined('SETTINGS_SYSTEM_DEBMES_PATH') && SETTINGS_SYSTEM_DEBMES_PATH != '') {
    $path = SETTINGS_SYSTEM_DEBMES_PATH;
} elseif (defined('LOG_DIRECTORY') && LOG_DIRECTORY != '') {
    $path = LOG_DIRECTORY;
} else {
    $path = ROOT . 'cms/debmes';
}

DebMes("Checking log files.", 'maintenance');
if (!isset($files) || !is_array($files)) {
    $files = array();
}
getDirTree($path, $files);
foreach ($files as $file) {
    if (empty($file['FILENAME'])) {
        continue;
    }
    if (filemtime($file['FILENAME']) < time() - LOG_FILES_EXPIRE * 24 * 60 * 60) {
        DebMes("Removing log file " . $file['FILENAME'], 'maintenance');
        unlink($file['FILENAME']);
    }
}
removeEmptySubFolders($path);


if ($full_backup) {
    DebMes("Backing up files...", 'maintenance');
    echo "Backing up files...";

    if (!is_dir($target_dir . '/cms')) {
        mkdir($target_dir . '/cms', 0777);
    }
    $cms_dirs = scandir(ROOT . 'cms');
    foreach ($cms_dirs as $d) {
        if ($d == '.' ||
            $d == '..' ||
            $d == 'cached' ||
            $d == 'debmes' ||
            $d == 'saverestore'
        ) continue;
        DebMes("Backing up dir " . ROOT . 'cms/' . $d . ' to ' . $target_dir . '/cms/' . $d, 'maintenance');
        copyTree(ROOT . 'cms/' . $d, $target_dir . '/cms/' . $d, 1);
    }

    $target_file = $target_dir . "/" . DB_NAME . ".sql";
    DebMes("Backing up database " . DB_NAME . ' to ' . $target_file, 'maintenance');
    if (SQLMakeDBDump($target_file)) {
        DebMes("Backup done.", 'maintenance');
    } else {
        DebMes("Backup error.", 'maintenance');
    }
    echo "OK\n";
}


if (!isset($run_from_start) || $run_from_start == 0) {
    // removing old files from cms/saverestore
    DebMes("Checking cms/saverestore files.", 'maintenance');
    if (is_dir(ROOT . 'cms/saverestore')) {
        $files = scandir(ROOT . 'cms/saverestore');
        foreach ($files as $file) {
            $path = ROOT . 'cms/saverestore/' . $file;
            if (is_file($path)
                && (preg_match('/\.tgz$/', $file) || preg_match('/\.tar\.gz$/', $file) || preg_match('/\.zip\.gz$/', $file) || preg_match('/^db_before_update_.+\.sql$/', $file))
                && filemtime($path) < time() - BACKUP_FILES_EXPIRE * 24 * 60 * 60
            ) {
                echo("Removing $path");
                DebMes("Removing $path.", 'maintenance');
                @unlink($path);
            }
        }
    } else {
        DebMes(ROOT . 'cms/saverestore - NOT FOUND', 'maintenance');
    }
// removing old backus
    DebMes('Checking old backups.', 'maintenance');
    if (is_dir($backups_dir)) {
        $backups = scandir($backups_dir);
        foreach ($backups as $file) {
            if ($file == '.' || $file == '..') continue;
            $path = $backups_dir . '/' . $file;
            if (is_dir($path) && filemtime($path) < time() - BACKUP_FILES_EXPIRE * 24 * 60 * 60) {
                echo("Removing $path");
                DebMes("Removing $path.", 'maintenance');
                removeTree($path);
            }
        }
        echo "OK";
    } else {
        DebMes($backups_dir . ' - NOT FOUND', 'maintenance');
        echo $backups_dir . " not found";
    }
} else {
    // RUN ON START ONLY
    if (time() >= getGlobal('ThisComputer.started_time')) {
        DebMes("Incorrect date on start, fixing.", 'maintenance');
        SQLExec("DELETE FROM events WHERE ADDED > NOW()");
        SQLExec("DELETE FROM phistory WHERE ADDED > NOW()");
        SQLExec("DELETE FROM history WHERE ADDED > NOW()");
        SQLExec("DELETE FROM shouts WHERE ADDED > NOW()");
        SQLExec("DELETE FROM jobs WHERE PROCESSED = 1");
        SQLExec("DELETE FROM history WHERE (TO_DAYS(NOW()) - TO_DAYS(ADDED)) >= 5");
    }
    setGlobal('ThisComputer.started_time', time());
}


// CHECK/REPAIR/OPTIMIZE TABLES
DebMes('Checking database tables.', 'maintenance');
$tables = SQLSelect("SELECT TABLE_NAME, ENGINE FROM information_schema.tables WHERE TABLE_SCHEMA='" . DBSafe(DB_NAME) . "' AND ENGINE!='MEMORY'");
$total = count($tables);
for ($i = 0; $i < $total; $i++) {
    $table = $tables[$i]['TABLE_NAME'] ?? null;
    if (!$table) {
        continue;
    }
    maintenanceCheckAndRepairTable($table, $tables[$i]['ENGINE'] ?? '');
}

// checking property history schema
DebMes('Checking property history schema.', 'maintenance');
maintenanceEnsureTableIndex('phistory', 'idx_phistory_value_added', 'VALUE_ID,ADDED');
maintenanceEnsureTableIndex('phistory', 'idx_phistory_value_id', 'VALUE_ID,ID');
maintenanceEnsureTableIndex('phistory_queue', 'idx_phistory_queue_value_id', 'VALUE_ID');
maintenanceEnsureTableIndex('phistory_queue', 'idx_phistory_queue_added', 'ADDED');
maintenanceEnsureTableIndex('history', 'idx_history_object_added', 'OBJECT_ID,ADDED');
maintenanceEnsureTableIndex('history', 'idx_history_method_added', 'METHOD_ID,ADDED');
maintenanceEnsureTableIndex('history', 'idx_history_value_added', 'VALUE_ID,ADDED');

$history_tables = SQLSelect("SHOW TABLES LIKE 'phistory_value_%'");
$total_history_tables = count($history_tables);
for ($i = 0; $i < $total_history_tables; $i++) {
    $table_name = reset($history_tables[$i]);
    if (!preg_match('/^phistory_value_\d+$/', $table_name)) {
        continue;
    }
    maintenanceEnsureTableColumn($table_name, 'SOURCE', "varchar(255) NOT NULL DEFAULT ''", 'varchar(255)');
    maintenanceEnsureTableIndex($table_name, 'idx_phistory_value_id', 'VALUE_ID,ID');
}

// removing obsolete code editor modes
DebMes('Checking blockly code types.', 'maintenance');
if (SQLSelectOne("SHOW TABLES LIKE 'blockly_code'")) {
    SQLExec("UPDATE blockly_code SET CODE_TYPE=0 WHERE CODE_TYPE NOT IN (0,1)");
}
if (SQLSelectOne("SHOW TABLES LIKE 'blockly_code_history'")) {
    SQLExec("UPDATE blockly_code_history SET CODE_TYPE=0 WHERE CODE_TYPE NOT IN (0,1)");
}

// removing incorrect pvalues
DebMes("Checking for incorrect pvalues.", 'maintenance');
$sqlQuery = "SELECT pvalues.*, properties.ID AS PROP_ID, objects.ID as OBJ_ID  FROM `pvalues` LEFT JOIN properties ON pvalues.PROPERTY_ID=properties.ID LEFT JOIN objects ON pvalues.OBJECT_ID=objects.ID";
$data = SQLSelect($sqlQuery);
$total = count($data);
$found_pvalues = array();
for ($i = 0; $i < $total; $i++) {
    $propId = $data[$i]['PROP_ID'] ?? null;
    $objId = $data[$i]['OBJ_ID'] ?? null;
    if (!$propId || !$objId) {
        $propertyName = $data[$i]['PROPERTY_NAME'] ?? '';
        echo "Removing incorrect property value: " . $propertyName . PHP_EOL;
        DebMes("Removing incorrect property value: " . $propertyName, 'maintenance');
        SQLExec("DELETE FROM phistory WHERE VALUE_ID=" . $data[$i]['ID']);
        SQLExec("DELETE FROM pvalues WHERE ID=" . $data[$i]['ID']);
    } else {
        $found_pvalues[] = $data[$i]['ID'];
    }
}
if (isset($found_pvalues[0])) {
    $sqlQuery = "DELETE FROM phistory WHERE VALUE_ID NOT IN (" . implode(',', $found_pvalues) . ")";
    $data = SQLExec($sqlQuery);
}

// fixing property names
DebMes("Checking for incorrect property names.", 'maintenance');
$sqlQuery = "SELECT pvalues.*, objects.TITLE AS OBJECT_TITLE, properties.TITLE AS PROPERTY_TITLE
               FROM pvalues
               JOIN objects ON pvalues.OBJECT_ID = objects.id
               JOIN properties ON pvalues.PROPERTY_ID = properties.id
              WHERE pvalues.PROPERTY_NAME != CONCAT_WS('.', objects.TITLE, properties.TITLE)";

$data = SQLSelect($sqlQuery);
$total = count($data);

for ($i = 0; $i < $total; $i++) {
    $objectProperty = $data[$i]['OBJECT_TITLE'] . "." . $data[$i]['PROPERTY_TITLE'];
    if (!empty($data[$i]['PROPERTY_NAME'])) {
        echo "Incorrect: " . $data[$i]['PROPERTY_NAME'] . " should be $objectProperty" . PHP_EOL;
        DebMes("Incorrect: " . $data[$i]['PROPERTY_NAME'] . " should be $objectProperty", 'maintenance');
    } else {
        echo "Missing: " . $objectProperty . PHP_EOL;
        DebMes("Missing: " . $objectProperty, 'maintenance');
    }

    $sqlQuery = "SELECT *
                  FROM pvalues
                 WHERE ID = '" . $data[$i]['ID'] . "'";

    $rec = SQLSelectOne($sqlQuery);
    $rec['PROPERTY_NAME'] = $data[$i]['OBJECT_TITLE'] . "." . $data[$i]['PROPERTY_TITLE'];
    SQLUpdate('pvalues', $rec);
}

// Removing incorrect history for images
DebMes('Checking incorrect history images', 'maintenance');
$folder = ROOT . 'cms/images/';
$properties = SQLSelect("SELECT * FROM properties WHERE DATA_TYPE=5");
$total = count($properties);
for ($i = 0; $i < $total; $i++) {
    $found_in_db = 0;
    $found_in_dir = 0;
    $files = array();
    $found_files = array();
    getDirFiles($folder . $properties[$i]['ID'], $files);
    foreach ($files as $file) {
        $found_files[$properties[$i]['ID'] . '/' . $file['NAME']] = 1;
        $found_in_dir++;
    }
    $values = SQLSelect("SELECT * FROM pvalues WHERE PROPERTY_ID=" . $properties[$i]['ID']);
    foreach ($values as $pvalue) {
        if (defined('SEPARATE_HISTORY_STORAGE') && SEPARATE_HISTORY_STORAGE == 1) {
            $table_name = 'phistory_value_' . $pvalue['ID'];
        } else {
            $table_name = 'phistory';
        }
        $history = SQLSelect("SELECT * FROM $table_name WHERE VALUE_ID=" . $pvalue['ID']);
        $h_total = count($history);
        if ($h_total > 0) {
            for ($ih = 0; $ih < $h_total; $ih++) {
                if (isset($found_files[$history[$ih]['VALUE']])) {
                    unset($found_files[$history[$ih]['VALUE']]);
                    $found_in_db++;
                }
            }
        }
    }
    echo("Found in db $found_in_db / found in dir: $found_in_dir\n");
    foreach ($found_files as $k => $v) {
        $path = $folder . $k;
        if (is_file($path)) {
            DebMes('Removing ' . $path, 'maintenance');
            echo "Removing $path<br/>";
            unlink($path);
        }
    }
}


// Removing duplicates when we have both class property and object property with the same name
DebMes('Checking duplicates with both class property and object property.', 'maintenance');

include_once(DIR_MODULES . 'classes/classes.class.php');
$cls_module = new classes();

$problems_found = 0;
$properties = SQLSelect("SELECT * FROM properties WHERE OBJECT_ID!=0");
$total = count($properties);
$classes = array();
for ($i = 0; $i < $total; $i++) {
    $prop_title = $properties[$i]['TITLE'];
    $object_id = $properties[$i]['OBJECT_ID'];
    $object_rec = SQLSelectOne("SELECT * FROM objects WHERE ID=" . $object_id);
    if (isset($object_rec['CLASS_ID']) && $object_rec['CLASS_ID']) {
        $class_id = $object_rec['CLASS_ID'];
        $class_property = array();
        $parent_props = $cls_module->getParentProperties($class_id, '', true);
        if (!is_array($parent_props)) {
            $parent_props = array();
        }
        foreach ($parent_props as $class_prop) {
            if ($class_prop['TITLE'] == $prop_title) {
                $class_property = $class_prop;
            }
        }
        if (isset($class_property['ID'])) {
            DebMes('Fixing ' . json_encode($properties[$i]), 'maintenance');
            $object_pvalue = SQLSelectOne("SELECT * FROM pvalues WHERE PROPERTY_ID=" . $properties[$i]['ID'] . " AND OBJECT_ID=" . $properties[$i]['OBJECT_ID']);
            $class_pvalue = SQLSelectOne("SELECT * FROM pvalues WHERE PROPERTY_ID=" . $class_property['ID'] . " AND OBJECT_ID=" . $properties[$i]['OBJECT_ID']);
            if (empty($class_pvalue['ID'])) {
                if (!empty($object_pvalue['ID'])) {
                    $object_pvalue['PROPERTY_ID'] = $class_property['ID'];
                    SQLUpdate('pvalues', $object_pvalue);
                }
            } else {
                if (!empty($object_pvalue['ID'])) {
                    SQLExec("DELETE FROM phistory WHERE VALUE_ID=" . $object_pvalue['ID']);
                    SQLExec("DELETE FROM pvalues WHERE ID=" . $object_pvalue['ID']);
                }
            }
            SQLExec("DELETE FROM properties WHERE ID=" . $properties[$i]['ID']);
            $problems_found++;
        }
    }
}

clearCacheData();

// removing old errors
if (defined('SETTINGS_ERRORS_KEEP_HISTORY') && SETTINGS_ERRORS_KEEP_HISTORY > 0) {
    DebMes("Deleting old system_errors_data", 'maintenance');
    SQLExec("DELETE FROM system_errors_data WHERE ADDED<'" . date('Y-m-d H:i:s', time() - SETTINGS_ERRORS_KEEP_HISTORY * 24 * 60 * 60) . "'");
}

// SET SERIAL
$serial_data = '';
$current_serial = gg('ThisComputer.Serial');
if (IsWindowsOS()) {

} else {
    $serial_data = trim(exec("cat /proc/cpuinfo | grep Serial | cut -d ':' -f 2"));
    $serial_data = ltrim($serial_data, '0');
}

if ($serial_data != '') {
    DebMes("Setting serial to: " . $serial_data, 'maintenance');
    sg('ThisComputer.Serial', $serial_data);
}


// caching some data
DebMes("Caching market data.", 'maintenance');
include_once DIR_MODULES . 'market/market.class.php';
$mkt = new market();
$mkt->marketRequest('op=didyouknow');
$mkt->marketRequest('op=news');

include_once DIR_MODULES . 'saverestore/saverestore.class.php';
$sv = new saverestore();
$out = array();
$sv->admin($out);

DebMes("Maintenance complete.", 'maintenance');

function maintenanceCheckAndRepairTable($table_name, $engine)
{
    $table_name_safe = maintenanceNormalizeSqlIdentifier($table_name);
    if ($table_name_safe == '') {
        return false;
    }

    echo 'Checking table [' . $table_name_safe . '] ...';
    $check = maintenanceCheckTable($table_name_safe);
    if (maintenanceTableCheckIsOk($check)) {
        echo "OK\n";
        return true;
    }

    $message = $check['Msg_text'] ?? 'unknown error';
    echo " broken ...";
    DebMes("Checking table [$table_name_safe] broken ($message)", 'maintenance');

    if (strtolower((string)$engine) !== 'myisam') {
        echo " repair is not supported for engine " . $engine . "\n";
        DebMes("Repair skipped for $table_name_safe: engine $engine is not MyISAM", 'maintenance');
        return false;
    }

    $repair_modes = array('', ' EXTENDED', ' USE_FRM');
    foreach ($repair_modes as $repair_mode) {
        $repair_label = trim($repair_mode);
        if ($repair_label == '') {
            $repair_label = 'default';
        }

        echo " repair $repair_label ...";
        DebMes("Repairing $table_name_safe ($repair_label)", 'maintenance');
        SQLExec("REPAIR TABLE `$table_name_safe`$repair_mode;");

        $check = maintenanceCheckTable($table_name_safe);
        if (maintenanceTableCheckIsOk($check)) {
            echo "OK\n";
            DebMes("$table_name_safe repaired OK ($repair_label)", 'maintenance');
            return true;
        }

        $message = $check['Msg_text'] ?? 'unknown error';
        DebMes("Repair $repair_label failed for $table_name_safe ($message)", 'maintenance');
    }

    echo "FAILED\n";
    DebMes("Repair of $table_name_safe failed", 'maintenance');
    return false;
}

function maintenanceCheckTable($table_name)
{
    return SQLSelectOne("CHECK TABLE `$table_name`;");
}

function maintenanceTableCheckIsOk($check)
{
    return isset($check['Msg_text']) && strtoupper((string)$check['Msg_text']) === 'OK';
}

function maintenanceEnsureTableIndex($table_name, $index_name, $index_columns)
{
    $table_name_safe = maintenanceNormalizeSqlIdentifier($table_name);
    $index_name_safe = preg_replace('/[^a-z0-9_]/i', '', (string)$index_name);
    $index_columns_safe = preg_replace('/[^a-z0-9_,]/i', '', (string)$index_columns);
    if ($table_name_safe == '' || $index_name_safe == '' || $index_columns_safe == '') {
        return;
    }

    $table_exists = SQLSelectOne("SHOW TABLES LIKE '" . DBSafe($table_name_safe) . "'");
    if (!$table_exists) {
        return;
    }

    $check = SQLSelectOne("SHOW INDEX FROM `$table_name_safe` WHERE Key_name='" . DBSafe($index_name_safe) . "'");
    if (!isset($check['Key_name'])) {
        SQLExec("ALTER TABLE `$table_name_safe` ADD INDEX `$index_name_safe` ($index_columns_safe)");
    }
}

function maintenanceEnsureTableColumn($table_name, $column_name, $definition, $required_type)
{
    $table_name_safe = maintenanceNormalizeSqlIdentifier($table_name);
    $column_name_safe = preg_replace('/[^a-z0-9_]/i', '', (string)$column_name);
    $definition_safe = preg_replace("/[^a-z0-9_(), '`]/i", '', (string)$definition);
    if ($table_name_safe == '' || $column_name_safe == '' || $definition_safe == '') {
        return;
    }

    $column = SQLSelectOne("SHOW COLUMNS FROM `$table_name_safe` LIKE '" . DBSafe($column_name_safe) . "'");
    if (!isset($column['Field'])) {
        SQLExec("ALTER TABLE `$table_name_safe` ADD `$column_name_safe` $definition_safe");
        return;
    }

    if (isset($column['Type']) && stripos($column['Type'], (string)$required_type) === false) {
        SQLExec("ALTER TABLE `$table_name_safe` MODIFY `$column_name_safe` $definition_safe");
    }
}

function maintenanceNormalizeSqlIdentifier($identifier)
{
    $identifier = (string)$identifier;
    if (!preg_match('/^[a-z0-9_]+$/i', $identifier)) {
        return '';
    }
    return $identifier;
}
