<?php

/** Isolated integration checks. Never loads config.php, loader.php or an application environment. */
if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'testing' || !getenv('MJD_TEST_DB_PORT') || !getenv('MJD_TEST_REDIS_PORT')) {
    fwrite(STDERR, "Use APP_ENV=testing and explicit ports of disposable local MariaDB and Redis.\n");
    exit(2);
}
define('APP_ENV', 'testing');
define('ROOT', dirname(__DIR__) . '/');
define('DIR_MODULES', ROOT . 'modules/');
define('ROOTHTML', '/');
define('BASE_URL', 'http://127.0.0.1:1');
define('USE_REDIS', getenv('MJD_TEST_SQL_CACHE') ? false : '127.0.0.1');
define('REDIS_PORT', (int)getenv('MJD_TEST_REDIS_PORT'));
define('REDIS_DB', 15);
define('REDIS_TIMEOUT', 0.5);
define('SEPARATE_HISTORY_STORAGE', getenv('MJD_TEST_SEPARATE_HISTORY') ? 1 : 0);
define('DB_NAME', 'mjd_core_test_' . bin2hex(random_bytes(6)));
require __DIR__ . '/bootstrap.php';

use MajorDoMo\Core\Core;

$admin = new mysqli('127.0.0.1', 'root', '', '', (int)getenv('MJD_TEST_DB_PORT'));
$guard=$admin->query("SELECT TOKEN FROM codex_mjd_test_guard.marker LIMIT 1")->fetch_assoc();
if (($guard['TOKEN'] ?? '') !== 'disposable-20261021') throw new RuntimeException('Database is not the disposable test sandbox');
$admin->query('CREATE DATABASE `' . DB_NAME . '` CHARACTER SET utf8');
$db = new mysql('127.0.0.1', (int)getenv('MJD_TEST_DB_PORT'), 'root', '', DB_NAME);
$checks = 0;
function check($condition, $message) { global $checks; if (!$condition) throw new RuntimeException('FAILED: ' . $message); $checks++; echo 'PASS ' . $message . PHP_EOL; }
function execTest($sql) { global $db; if (!$db->Exec($sql)) throw new RuntimeException('Fixture SQL failed'); }

try {
    if (USE_REDIS && mjdGetRedisConnection()->get('codex:mjd-test-sandbox') !== 'disposable-20261021') throw new RuntimeException('Redis is not the disposable test sandbox');
    $schema = array(
        "CREATE TABLE classes (ID int NOT NULL AUTO_INCREMENT PRIMARY KEY,TITLE varchar(255) NOT NULL,PARENT_ID int NOT NULL DEFAULT 0,SUB_LIST text) ENGINE=MyISAM",
        "CREATE TABLE objects (ID int NOT NULL AUTO_INCREMENT PRIMARY KEY,TITLE varchar(255) NOT NULL,CLASS_ID int NOT NULL DEFAULT 0,SYSTEM varchar(255) NOT NULL DEFAULT '',DESCRIPTION text,LOCATION_ID int NOT NULL DEFAULT 0) ENGINE=MyISAM",
        "CREATE TABLE properties (ID int NOT NULL AUTO_INCREMENT PRIMARY KEY,CLASS_ID int NOT NULL DEFAULT 0,OBJECT_ID int NOT NULL DEFAULT 0,TITLE varchar(255) NOT NULL,KEEP_HISTORY int NOT NULL DEFAULT 0,DATA_TYPE int NOT NULL DEFAULT 0,VALIDATION_TYPE int NOT NULL DEFAULT 0,VALIDATION_NUM_MIN varchar(20) NOT NULL DEFAULT '',VALIDATION_NUM_MAX varchar(20) NOT NULL DEFAULT '',VALIDATION_LIST varchar(255) NOT NULL DEFAULT '',VALIDATION_CODE text,ONCHANGE varchar(255) NOT NULL DEFAULT '') ENGINE=MyISAM",
        "CREATE TABLE methods (ID int NOT NULL AUTO_INCREMENT PRIMARY KEY,CLASS_ID int NOT NULL DEFAULT 0,OBJECT_ID int NOT NULL DEFAULT 0,TITLE varchar(255) NOT NULL,CODE text,SCRIPT_ID int DEFAULT 0,CALL_PARENT int DEFAULT 0,EXECUTED datetime,EXECUTED_SRC varchar(255),EXECUTED_PARAMS varchar(255)) ENGINE=MyISAM",
        "CREATE TABLE pvalues (ID int NOT NULL AUTO_INCREMENT PRIMARY KEY,PROPERTY_ID int NOT NULL,OBJECT_ID int NOT NULL,VALUE text NOT NULL,UPDATED datetime,PROPERTY_NAME varchar(100) NOT NULL DEFAULT '',LINKED_MODULES varchar(255) NOT NULL DEFAULT '',SOURCE varchar(255) NOT NULL DEFAULT '') ENGINE=MyISAM",
        "CREATE TABLE phistory (ID int NOT NULL AUTO_INCREMENT PRIMARY KEY,VALUE_ID int NOT NULL,ADDED datetime,VALUE varchar(255) NOT NULL,SOURCE varchar(255) NOT NULL DEFAULT '') ENGINE=MyISAM",
        "CREATE TABLE phistory_queue (ID int NOT NULL AUTO_INCREMENT PRIMARY KEY,VALUE_ID int NOT NULL,ADDED datetime,VALUE text,OLD_VALUE text,KEEP_HISTORY int NOT NULL DEFAULT 0,SOURCE varchar(255) NOT NULL DEFAULT '') ENGINE=MyISAM",
        "CREATE TABLE history (ID int NOT NULL AUTO_INCREMENT PRIMARY KEY,VALUE_ID int NOT NULL DEFAULT 0,ADDED datetime) ENGINE=MyISAM",
        "CREATE TABLE jobs (ID int NOT NULL AUTO_INCREMENT PRIMARY KEY,TITLE varchar(255) NOT NULL,COMMANDS text,RUNTIME datetime,EXPIRE datetime,STARTED datetime,PROCESSED int NOT NULL DEFAULT 0,EXPIRED int NOT NULL DEFAULT 0) ENGINE=MyISAM",
        "CREATE TABLE cached_ws (PROPERTY char(100) NOT NULL PRIMARY KEY,DATAVALUE varchar(20000) NOT NULL,POST_ACTION char(100) NOT NULL,ADDED datetime NOT NULL) ENGINE=MEMORY",
        "CREATE TABLE cached_cycles (TITLE varchar(255) NOT NULL PRIMARY KEY,VALUE varchar(255) NOT NULL) ENGINE=MEMORY",
        "CREATE TABLE operations_queue (TOPIC char(255) NOT NULL,DATANAME varchar(1024) NOT NULL,DATAVALUE varchar(1024) NOT NULL,EXPIRE datetime NOT NULL) ENGINE=MEMORY",
        "CREATE TABLE settings (ID int NOT NULL AUTO_INCREMENT PRIMARY KEY,NAME varchar(255),VALUE text,TYPE varchar(30) DEFAULT '') ENGINE=InnoDB",
        "CREATE TABLE project_modules (ID int NOT NULL AUTO_INCREMENT PRIMARY KEY,NAME varchar(255)) ENGINE=InnoDB",
        "CREATE TABLE events (ID int NOT NULL AUTO_INCREMENT PRIMARY KEY,EVENT_NAME varchar(255),DETAILS text,ADDED datetime,EVENT_TYPE varchar(20)) ENGINE=InnoDB"
    );
    if (getenv('MJD_TEST_USE_SEED')) {
        $dump=file_get_contents(ROOT.'docs/dev/db_terminal.sql');
        if (preg_match('/^\s*(?:USE\s|(?:CREATE|DROP)\s+DATABASE\b)/mi',$dump)) throw new RuntimeException('Seed must not change databases');
        $db->dbh->multi_query($dump);
        do { $result=$db->dbh->store_result(); if($result) $result->free(); } while($db->dbh->more_results() && $db->dbh->next_result());
        foreach (array('classes','objects','properties','methods','pvalues','phistory','phistory_queue','history','jobs','operations_queue','settings','project_modules','events','cached_ws') as $table) execTest('TRUNCATE TABLE `'.$table.'`');
        $schema=array_map(fn($sql)=>str_replace('CREATE TABLE ','CREATE TABLE IF NOT EXISTS ',$sql),$schema);
        check(true,'current docs/dev/db_terminal.sql imports into an isolated database');
    }
    foreach ($schema as $sql) execTest($sql);
    execTest("INSERT INTO classes (ID,TITLE) VALUES (1,'Computer')");
    execTest("INSERT INTO objects (ID,TITLE,CLASS_ID,DESCRIPTION) VALUES (1,'ThisComputer',1,''),(2,'Lamp',1,'')");
    execTest("INSERT INTO properties (ID,CLASS_ID,TITLE,KEEP_HISTORY) VALUES (1,1,'status',7),(2,1,'connectionStatus',0),(3,1,'cycle_connectRun',0)");
    execTest("INSERT INTO pvalues (ID,PROPERTY_ID,OBJECT_ID,VALUE,PROPERTY_NAME,UPDATED,LINKED_MODULES) VALUES (1,1,2,'0','Lamp.status','2026-01-01','a'),(2,1,2,'1','Lamp.status','2026-01-02','b'),(3,2,2,'keep','Lamp.connectionStatus','2026-01-01',''),(4,3,1,'1','ThisComputer.cycle_connectRun','2026-01-01','')");
    execTest("INSERT INTO phistory (VALUE_ID,ADDED,VALUE,SOURCE) VALUES (1,NOW(),'0','seed')");
    execTest("INSERT INTO settings (NAME,VALUE,TYPE) VALUES ('MODULE_CONNECT','1',''),('HOOK_EVENT_TEST','{\"connect\":{\"priority\":10},\"mqtt\":{}}','json')");
    execTest("INSERT INTO project_modules (NAME) VALUES ('connect'),('mqtt')");
    execTest("CREATE TABLE public_calls (ID int NOT NULL PRIMARY KEY,PUBLIC_KEY varchar(255)) ENGINE=InnoDB");
    execTest("INSERT INTO public_calls VALUES (1,'retired')");
    execTest("CREATE TABLE phistory_value_1 LIKE phistory");
    execTest("INSERT INTO phistory_value_1 (VALUE_ID,ADDED,VALUE,SOURCE) VALUES (1,NOW(),'preserved','old separate history')");
    Core::migrations()->run();
    check(!Core::database()->tableExists('public_calls'), 'Connect-only public_calls table removed');
    check((int)SQLSelectOne('SELECT COUNT(*) AS C FROM phistory_value_2')['C'] === 1, 'duplicate consolidation preserves separate history');
    check((int)SQLSelectOne('SELECT COUNT(*) AS C FROM pvalues WHERE PROPERTY_ID=1 AND OBJECT_ID=2')['C'] === 1, 'migration consolidates duplicate property values');
    check(SQLSelectOne('SELECT VALUE,LINKED_MODULES FROM pvalues WHERE ID=2')['LINKED_MODULES'] === 'b,a', 'migration preserves bindings and newest value');
    check((int)SQLSelectOne('SELECT VALUE_ID FROM phistory LIMIT 1')['VALUE_ID'] === 2, 'migration preserves history references');
    check(SQLSelectOne("SELECT VALUE FROM pvalues WHERE PROPERTY_NAME='Lamp.connectionStatus'")['VALUE'] === 'keep', 'Connect cleanup preserves unrelated connection properties');
    check(!SQLSelectOne("SELECT ID FROM project_modules WHERE NAME='connect'"), 'Connect module registration removed');
    check(!isset(json_decode(SQLSelectOne("SELECT VALUE FROM settings WHERE NAME='HOOK_EVENT_TEST'")['VALUE'], true)['connect']), 'Connect hook removed structurally');
    Core::migrations()->run();
    check((int)SQLSelectOne('SELECT COUNT(*) AS C FROM core_migrations')['C'] === 3, 'migration is idempotent');
    check(SQLSelectOne("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pvalues'")['ENGINE'] === 'InnoDB', 'hot tables migrated to InnoDB');

    sg('Lamp.status', 0);
    check((string)gg('Lamp.status') === '0', 'legacy sg/gg preserve zero');
    sg('Lamp.status', '');
    check(gg('Lamp.status') === '', 'legacy sg/gg preserve empty string');
    check(gg('Lamp.unknown') === false && gg('Lamp.unknown') === false, 'missing values remain false on repeat reads');
    sg('Lamp.newProperty', 'value');
    check(gg('Lamp.newProperty') === 'value', 'automatic property creation remains compatible');
    check(getObject('Lamp')->object_title === 'Lamp', 'legacy object factory remains compatible');
    sg('Lamp.status', 1);
    $before = $db->queryCount;
    sg('Lamp.status', 0);
    $queries = $db->queryCount - $before;
    check((string)gg('Lamp.status') === '0', 'repeated value writes stay current');
    // The always-on property audit adds a run/event write for a real value change.
    if (USE_REDIS) check($queries <= 10, 'warm sg plus audit stays within ten SQL statements');
    echo 'METRIC warm sg SQL statements: ' . $queries . PHP_EOL;
    $property = SQLSelectOne("SELECT * FROM properties WHERE TITLE='status'");
    $property['VALIDATION_TYPE'] = 1; $property['VALIDATION_NUM_MIN'] = '10'; $property['VALIDATION_NUM_MAX'] = '20';
    SQLUpdate('properties', $property);
    check(sg('Lamp.status', 5) === false, 'metadata invalidation applies changed validation immediately');
    sg('Lamp.status', 15);
    check(gg('Lamp.status') === 15, 'valid update after rejected update releases lock');
    $property['VALIDATION_TYPE'] = 0; SQLUpdate('properties', $property);

    $method = array('TITLE'=>'calculate','OBJECT_ID'=>2,'CODE'=>'return $this->getProperty("status") + ($params["offset"] ?? 0);');
    SQLInsert('methods',$method);
    check(callMethod('Lamp.calculate',array('offset'=>5)) === 20, 'method code retains original object as $this and receives params');
    $property['VALIDATION_TYPE']=100; $property['VALIDATION_CODE']='$value = (int)$value + (int)$this->id;'; SQLUpdate('properties',$property);
    sg('Lamp.status',10);
    check(gg('Lamp.status') === 12, 'custom validation retains object context and can transform value');
    $property['VALIDATION_TYPE']=0; SQLUpdate('properties',$property);
    check((bool)callMethodSafe('Lamp.calculate',array('offset'=>1)), 'safe internal method call enters durable queue without HTTP');
    $operation=Core::queue()->claim('core_runtime',1)[0];
    check(Core::worker()->execute($operation['QUEUE_ID'],$operation['CLAIM_TOKEN'])['success'] === true, 'queued method executes through new runtime');
    Core::queue()->acknowledge($operation);
    unset($_GET['r_s_m'],$_SERVER['REQUEST_URI'],$_SERVER['REQUEST_METHOD']); $_REQUEST=array();

    $queue = Core::queue();
    $queue->enqueue('test','first','1'); $first = $queue->claim('test',1)[0];
    $queue->enqueue('test','second','2'); $queue->acknowledge($first);
    $second = $queue->claim('test',1)[0];
    check($second['DATANAME'] === 'second', 'acknowledgement preserves concurrent enqueue');
    $queue->release($second,0); $retry = $queue->claim('test',1)[0];
    check($retry['DATAVALUE'] === '2' && $retry['CLAIM_TOKEN'] !== $second['CLAIM_TOKEN'], 'failed operation is redelivered with a new owner');
    $queue->acknowledge($second);
    check($queue->claimed('test',$retry['QUEUE_ID'],$retry['CLAIM_TOKEN'])['DATAVALUE'] === '2', 'old owner cannot acknowledge redelivered message');
    $queue->acknowledge($retry);
    $queue->enqueue('unique','lamp','old',true); $queue->enqueue('unique','lamp','new',true);
    $unique = $queue->claim('unique');
    check(count($unique) === 1 && $unique[0]['DATAVALUE'] === 'new', 'unique queue keeps latest pending value');
    $queue->acknowledge($unique[0]);
    $queue->enqueue('expiry','expired','x',false,0);
    check($queue->claim('expiry') === array(), 'expired messages are never delivered');
    for ($i=0;$i<5;$i++) $queue->enqueue('bounded','n'.$i,'v');
    check(count($queue->claim('bounded',2)) === 2, 'queue reservations are bounded');

    $queue->enqueue('mixed','same','old-a'); $queue->enqueue('mixed','same','old-b'); $queue->enqueue('mixed','same','new',true);
    $mixed=$queue->claim('mixed');
    check(count($mixed)===1 && $mixed[0]['DATAVALUE']==='new', 'unique enqueue replaces all pending copies, including nonunique ones');
    $queue->acknowledge($mixed[0]);
    $queue->enqueue('lease','same','leased'); $leased=$queue->claim('lease',1)[0];
    if (USE_REDIS) mjdGetRedisConnection()->zAdd($queue->keys('lease')[1],microtime(true)-1,$leased['QUEUE_ID']);
    else execTest("UPDATE operations_queue SET CLAIM_UNTIL=NOW()-INTERVAL 1 SECOND WHERE ID=".(int)$leased['QUEUE_ID']);
    $recovered=$queue->claim('lease',1)[0];
    check($recovered['QUEUE_ID']===$leased['QUEUE_ID'] && $recovered['CLAIM_TOKEN']!==$leased['CLAIM_TOKEN'], 'expired worker lease is recovered');
    $queue->acknowledge($recovered);
    if (USE_REDIS) {
        $legacyTopic='legacy-'.DB_NAME;
        mjdGetRedisConnection()->rPush('mjd:queue:'.$legacyTopic,json_encode(array('DATANAME'=>'name','DATAVALUE'=>'left|right')));
        $legacy=$queue->claim($legacyTopic);
        check(count($legacy)===1 && $legacy[0]['DATAVALUE']==='left|right', 'legacy Redis list imports atomically without corrupting payload');
        $queue->acknowledge($legacy[0]);
    }

    Core::websockets()->post('Lamp.status','0'); $old = Core::websockets()->claim(100);
    Core::websockets()->post('Lamp.status','1');
    foreach ($old as $item) Core::websockets()->acknowledge($item);
    $current = Core::websockets()->claim(100);
    check(count($current) === 1 && (string)$current[0]['DATAVALUE'] === '1', 'WebSocket acknowledgement cannot delete a newer property value');
    foreach ($current as $item) Core::websockets()->acknowledge($item);

    Core::websockets()->post('test-event',array('key'=>'value'),'PostEvent');
    $eventMessages=Core::websockets()->claim(100);
    $eventData=$eventMessages[0]['DATAVALUE'];
    if (is_string($eventData)) $eventData=json_decode($eventData,true);
    check($eventData===array('key'=>'value'), 'WebSocket event retains structured payload');
    foreach($eventMessages as $eventMessage) Core::websockets()->acknowledge($eventMessage);

    saveCycleToCache('cycle_coreRun',123); saveToCache('test:cache','value');
    $queue->enqueue('clear-test','message','keep');
    if (USE_REDIS) mjdGetRedisConnection()->set('foreign:key','keep');
    clearCacheData();
    check(checkFromCache('test:cache') === false, 'cache clear removes cache values');
    check((string)checkCycleFromCache('cycle_coreRun') === '123', 'cache clear preserves cycle heartbeats');
    check(count($queue->claim('clear-test')) === 1, 'cache clear preserves operation queues');
    if (USE_REDIS) check(mjdGetRedisConnection()->get('foreign:key') === 'keep', 'cache clear preserves foreign Redis keys');
    saveToCache('test:race','new'); saveToCache('test:race','old',true);
    check(checkFromCache('test:race') === 'new', 'late cache fill cannot overwrite newer write');

    $historyCount = (int)SQLSelectOne('SELECT COUNT(*) AS C FROM phistory_queue')['C'];
    check(Core::history()->process(200) === $historyCount, 'history writer processes queued batch');
    check((int)SQLSelectOne('SELECT COUNT(*) AS C FROM phistory_queue')['C'] === 0, 'history queue is acknowledged after commit');
    check(Core::history()->process() === 0, 'history worker is idle after acknowledgement');
    for ($i=0;$i<20;$i++) sg('Lamp.status',(string)($i%2));
    $before = $db->queryCount; $processed = Core::history()->process(200); $historyQueries = $db->queryCount-$before;
    check($processed === 20 && $historyQueries < 15, 'history writer batches twenty events without per-row insert/delete');
    echo 'METRIC history batch SQL statements: ' . $historyQueries . PHP_EOL;

    $table=SEPARATE_HISTORY_STORAGE ? createHistoryTable(2) : 'phistory';
    $row=array('VALUE_ID'=>2,'ADDED'=>date('Y-m-d H:i:s'),'VALUE'=>'rollback','OLD_VALUE'=>'different','SOURCE'=>'test','KEEP_HISTORY'=>7); SQLInsert('phistory_queue',$row);
    $beforeHistory=(int)SQLSelectOne("SELECT COUNT(*) AS C FROM `$table`")['C'];
    execTest("CREATE TRIGGER fail_history BEFORE INSERT ON `$table` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='expected history failure'");
    $rolledBack=false;
    try { Core::history()->process(); } catch (RuntimeException $expected) { $rolledBack=true; }
    execTest('DROP TRIGGER fail_history');
    check($rolledBack && (int)SQLSelectOne('SELECT COUNT(*) AS C FROM phistory_queue')['C']===1 && (int)SQLSelectOne("SELECT COUNT(*) AS C FROM `$table`")['C']===$beforeHistory, 'history insert failure rolls back and preserves pending queue');
    check(Core::history()->process()===1, 'history batch recovers after failed transaction');
    $beforeHistory=(int)SQLSelectOne("SELECT COUNT(*) AS C FROM `$table`")['C'];
    for($i=0;$i<4;$i++) { $row=array('VALUE_ID'=>2,'ADDED'=>date('Y-m-d H:i:s',time()+$i),'VALUE'=>'7','OLD_VALUE'=>'7','SOURCE'=>'sensor-'.$i,'KEEP_HISTORY'=>7); SQLInsert('phistory_queue',$row); }
    Core::history()->process();
    check((int)SQLSelectOne("SELECT COUNT(*) AS C FROM `$table`")['C']===$beforeHistory+2, 'batch writer preserves repeated-value compression');
    // A failed compression update happens after the batch INSERT: retry must
    // roll back that INSERT as well, and must not enter the legacy fatal logger.
    execTest("CREATE TRIGGER retry_history BEFORE UPDATE ON `$table` FOR EACH ROW BEGIN SET @mjd_history_attempts=COALESCE(@mjd_history_attempts,0)+1; IF COALESCE(@mjd_history_failures,0)>0 THEN SET @mjd_history_failures=@mjd_history_failures-1; IF @mjd_history_error=1205 THEN SIGNAL SQLSTATE 'HY000' SET MYSQL_ERRNO=1205, MESSAGE_TEXT='fixture lock timeout'; ELSE SIGNAL SQLSTATE '40001' SET MYSQL_ERRNO=1213, MESSAGE_TEXT='fixture deadlock'; END IF; END IF; END");
    foreach (array(1213, 1205) as $lockError) {
        execTest("SET @mjd_history_failures=1,@mjd_history_attempts=0,@mjd_history_error=$lockError");
        $beforeHistory=(int)SQLSelectOne("SELECT COUNT(*) AS C FROM `$table`")['C'];
        $beforeErrors=count($GLOBALS['test_sql_errors'] ?? array());
        foreach (array(array('7','7'),array('8','7')) as $event) { $row=array('VALUE_ID'=>2,'ADDED'=>date('Y-m-d H:i:s'),'VALUE'=>$event[0],'OLD_VALUE'=>$event[1],'SOURCE'=>'sensor-9','KEEP_HISTORY'=>7); SQLInsert('phistory_queue',$row); }
        check(Core::history()->process()===2, "history retries full batch after SQL $lockError");
        check((int)SQLSelectOne('SELECT @mjd_history_attempts AS C')['C']===2, "SQL $lockError rebuilds compression update on retry");
        check((int)SQLSelectOne("SELECT COUNT(*) AS C FROM `$table`")['C']===$beforeHistory+1 && (int)SQLSelectOne('SELECT COUNT(*) AS C FROM phistory_queue')['C']===0, "SQL $lockError retry commits once without losing queue events");
        check(count($GLOBALS['test_sql_errors'] ?? array())===$beforeErrors, "SQL $lockError retry bypasses terminating legacy error handler");
        // Restore two equal history tails for the next compression scenario.
        for($i=0;$i<3;$i++) { $row=array('VALUE_ID'=>2,'ADDED'=>date('Y-m-d H:i:s'),'VALUE'=>'7','OLD_VALUE'=>'7','SOURCE'=>'sensor-9','KEEP_HISTORY'=>7); SQLInsert('phistory_queue',$row); }
        Core::history()->process();
    }
    execTest('SET @mjd_history_failures=10,@mjd_history_attempts=0,@mjd_history_error=1213');
    $beforeHistory=(int)SQLSelectOne("SELECT COUNT(*) AS C FROM `$table`")['C'];
    foreach (array(array('7','7'),array('8','7')) as $event) { $row=array('VALUE_ID'=>2,'ADDED'=>date('Y-m-d H:i:s'),'VALUE'=>$event[0],'OLD_VALUE'=>$event[1],'SOURCE'=>'sensor-9','KEEP_HISTORY'=>7); SQLInsert('phistory_queue',$row); }
    $retryError=null;
    try { Core::history()->process(); } catch (RuntimeException $expected) { $retryError=$expected; }
    check($retryError && $retryError->getCode()===1213 && (int)SQLSelectOne('SELECT @mjd_history_attempts AS C')['C']===3, 'persistent deadlock stops after three complete attempts');
    check((int)SQLSelectOne("SELECT COUNT(*) AS C FROM `$table`")['C']===$beforeHistory && (int)SQLSelectOne('SELECT COUNT(*) AS C FROM phistory_queue')['C']===2, 'exhausted deadlock retries leave history and pending events intact');
    execTest('DROP TRIGGER retry_history');
    check(Core::history()->process()===2, 'history resumes pending batch after persistent contention clears');

    // Exercise both real counter queries in strict SQL mode, including legacy
    // zero dates stored before strict mode was enabled.
    execTest('CREATE TABLE IF NOT EXISTS scripts (ID int NOT NULL AUTO_INCREMENT PRIMARY KEY,EXECUTED datetime NULL) ENGINE=InnoDB');
    $beforeExecuted=(int)SQLSelectOne("SELECT COUNT(*) AS TOTAL FROM scripts WHERE EXECUTED >= '1000-01-01 00:00:00'")['TOTAL'];
    execTest("SET SESSION sql_mode=''");
    execTest("INSERT INTO scripts (EXECUTED) VALUES (NULL),('0000-00-00 00:00:00'),('2026-10-21 11:25:19')");
    execTest("SET SESSION sql_mode='STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
    foreach (array('modules/scripts/scripts_search.inc.php','modules/scripts/scripts.class.php') as $counterFile) {
        $counterSource=file_get_contents(ROOT.$counterFile);
        if (!preg_match('/\$executed_total = SQLSelectOne\("([^"]+)"\);/', $counterSource, $counterMatch)) throw new RuntimeException('Script counter query not found');
        check((int)SQLSelectOne($counterMatch[1])['TOTAL']===$beforeExecuted+1, "script execution count accepts strict DATETIME mode ($counterFile)");
    }
    $beforePending=(int)SQLSelectOne('SELECT COUNT(*) AS C FROM phistory_queue')['C'];
    $admin->query("SELECT GET_LOCK('mjd:history-writer',0)");
    check(Core::history()->process()===0, 'second history consumer cannot process an owned batch');
    $admin->query("SELECT RELEASE_LOCK('mjd:history-writer')");
    saveToCache('literal:*:one','keep'); saveToCache('literal:x:one','other'); clearCacheData('literal:*');
    check(checkFromCache('literal:*:one')===false && checkFromCache('literal:x:one')==='other', 'cache prefix clearing treats glob characters literally');

    $job = array('TITLE'=>'test','COMMANDS'=>'return false;','RUNTIME'=>date('Y-m-d H:i:s',time()-1));
    $jobId = SQLInsert('jobs',$job);
    Core::scheduler()->run(fn($row,$token)=>Core::scheduler()->execute($row['ID'],$token));
    $failed = SQLSelectOne('SELECT * FROM jobs WHERE ID='.$jobId);
    check((int)$failed['PROCESSED'] === 0 && (int)$failed['ATTEMPTS'] === 1 && $failed['LAST_ERROR'] !== '', 'scheduler does not acknowledge job returning false');
    execTest("UPDATE jobs SET COMMANDS='throw new RuntimeException(\"expected\");',RUNTIME=NOW() WHERE ID=$jobId");
    Core::scheduler()->run(fn($row,$token)=>Core::scheduler()->execute($row['ID'],$token));
    check((int)SQLSelectOne('SELECT ATTEMPTS FROM jobs WHERE ID='.$jobId)['ATTEMPTS'] === 2, 'scheduler retries thrown exceptions');
    execTest("UPDATE jobs SET RUNTIME=NOW() WHERE ID=$jobId");
    Core::scheduler()->run(fn($row,$token)=>Core::scheduler()->execute($row['ID'],$token));
    check((int)SQLSelectOne('SELECT EXPIRED FROM jobs WHERE ID='.$jobId)['EXPIRED'] === 1, 'scheduler stops after bounded failed attempts');
    $job = array('TITLE'=>'success','COMMANDS'=>'return true;','RUNTIME'=>date('Y-m-d H:i:s',time()-1));
    $successId=SQLInsert('jobs',$job);
    Core::scheduler()->run(fn($row,$token)=>Core::scheduler()->execute($row['ID'],$token));
    check((int)SQLSelectOne('SELECT PROCESSED FROM jobs WHERE ID='.$successId)['PROCESSED'] === 1, 'scheduler acknowledges successful job');
    check(Core::scheduler()->execute($successId,'wrong')['success'] === false, 'job cannot be replayed without its claim');

    $network=Core::network();
    check($network->inNetworks('192.168.1.5','192.168.1.*') && !$network->inNetworks('1192.168.1.5','192.168.1.*'), 'home network match validates the whole address');
    check($network->inNetworks('192.168.1.5','192.168.1.0/24') && $network->inNetworks('::1','::1/128'), 'IPv4 and IPv6 CIDR matching');
    check($network->clientIp('203.0.113.1','192.168.1.1','') === '203.0.113.1', 'untrusted forwarded address cannot bypass access control');
    check($network->clientIp('127.0.0.1','not-an-ip','127.0.0.1/32') === '', 'invalid forwarded address cannot fall back to trusted loopback');
    check($network->clientIp('127.0.0.1','192.168.1.1','127.0.0.1/32') === '192.168.1.1', 'explicit trusted proxy works');
    $record = array('ID'=>"1' OR '1'='1",'VALUE'=>'injected'); SQLUpdate('settings',$record);
    check((int)SQLSelectOne("SELECT COUNT(*) AS C FROM settings WHERE VALUE='injected'")['C'] === 0, 'SQLUpdate quotes its condition value');
    $eventName="sensor' OR '1'='1"; $event=array('EVENT_NAME'=>$eventName,'DETAILS'=>'literal','ADDED'=>date('Y-m-d H:i:s')); SQLInsert('events',$event);
    check(registeredEventDetails($eventName) === 'literal', 'event lookup escapes literal event name');

    $fixture = sys_get_temp_dir() . '/mjd-worker-' . bin2hex(random_bytes(6)) . '/';
    mkdir($fixture . 'lib',0777,true); mkdir($fixture . 'scripts',0777,true);
    copy(ROOT . 'scripts/core_worker.php',$fixture . 'scripts/core_worker.php');
    $constants = array('APP_ENV'=>'testing','ROOT'=>$fixture,'MJD_TEST_SOURCE_ROOT'=>ROOT,'DIR_MODULES'=>DIR_MODULES,'ROOTHTML'=>'/','BASE_URL'=>BASE_URL,'USE_REDIS'=>USE_REDIS,'REDIS_PORT'=>REDIS_PORT,'REDIS_DB'=>15,'REDIS_TIMEOUT'=>0.5,'DB_NAME'=>DB_NAME,'SEPARATE_HISTORY_STORAGE'=>SEPARATE_HISTORY_STORAGE);
    $config="<?php\nif (getenv('APP_ENV') !== 'testing') exit(2);\n";
    foreach ($constants as $name=>$value) $config .= 'define(' . var_export($name,true) . ',' . var_export($value,true) . ");\n";
    file_put_contents($fixture . 'config.php',$config);
    $loader = '<?php require ' . var_export(ROOT . 'tests/bootstrap.php',true) . '; $db=new mysql("127.0.0.1",' . (int)getenv('MJD_TEST_DB_PORT') . ',"root","",DB_NAME);';
    file_put_contents($fixture . 'lib/loader.php',$loader); file_put_contents($fixture . 'load_settings.php','<?php');
    $processes = new \MajorDoMo\Core\ProcessRunner($fixture);
    $scheduler = new \MajorDoMo\Core\Scheduler(Core::database(),$processes);
    $job=array('TITLE'=>'subprocess','COMMANDS'=>'sg("Lamp.status",42); return true;','RUNTIME'=>date('Y-m-d H:i:s',time()-1)); $id=SQLInsert('jobs',$job);
    $scheduler->run();
    check((int)SQLSelectOne('SELECT PROCESSED FROM jobs WHERE ID='.$id)['PROCESSED'] === 1 && gg('Lamp.status') === 42, 'real isolated PHP worker completes job without HTTP');
    $job=array('TITLE'=>'fatal','COMMANDS'=>'exit(0);','RUNTIME'=>date('Y-m-d H:i:s',time()-1)); $id=SQLInsert('jobs',$job);
    $scheduler->run();
    check((int)SQLSelectOne('SELECT PROCESSED FROM jobs WHERE ID='.$id)['PROCESSED'] === 0, 'worker exit without result is not acknowledged');
    $worker = new \MajorDoMo\Core\OperationWorker(Core::database(),Core::redis(),Core::queue(),$processes);
    callMethodSafe('Lamp.calculate',array('offset'=>2));
    check($worker->process() === 1 && Core::queue()->claim('core_runtime') === array(), 'real operation subprocess executes and acknowledges method');
    $beforeWrites=(int)SQLSelectOne('SELECT COUNT(*) AS C FROM phistory_queue')['C'];
    $children=array();
    for ($n=0;$n<6;$n++) {
        $token=bin2hex(random_bytes(16));
        $commands='for ($i=0;$i<20;$i++) sg("Lamp.status",' . ($n*1000) . '+$i); return true;';
        $job=array('TITLE'=>'concurrent-'.$n,'COMMANDS'=>$commands,'RUNTIME'=>date('Y-m-d H:i:s'),'CLAIM_TOKEN'=>$token);
        $id=SQLInsert('jobs',$job);
        $process=proc_open(array(PHP_BINARY,$fixture.'scripts/core_worker.php','job',(string)$id,$token),array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')),$pipes);
        fclose($pipes[0]); $children[]=array($process,$pipes);
    }
    foreach ($children as [$process,$pipes]) {
        $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); proc_close($process);
        check(str_contains($out,'"success":true') && $err === '', 'concurrent property writer completed without warnings');
    }
    $stored=SQLSelectOne("SELECT VALUE FROM pvalues WHERE PROPERTY_NAME='Lamp.status'")['VALUE'];
    check((string)gg('Lamp.status') === $stored, 'six concurrent writers leave cache equal to persisted value');
    check((int)SQLSelectOne('SELECT COUNT(*) AS C FROM phistory_queue')['C'] === $beforeWrites+120, 'all 120 concurrent updates retain history');
    check((int)SQLSelectOne("SELECT COUNT(*) AS C FROM pvalues WHERE PROPERTY_NAME='Lamp.status'")['C'] === 1, 'concurrent writers do not duplicate property rows');
    $messages=Core::websockets()->claim(100); $latest=null;
    foreach ($messages as $message) { if ($message['PROPERTY']==='Lamp.status') $latest=(string)$message['DATAVALUE']; Core::websockets()->acknowledge($message); }
    check($latest === $stored, 'latest WebSocket update matches final committed value');
    saveCycleToCache('cycle_testRun',123);
    $cycles=array_column(Core::cache()->cycles(),'VALUE','TITLE');
    check(($cycles['cycle_testRun'] ?? null) === '123', 'cycle snapshot preserves names used by controller');

    foreach (array('config.php','load_settings.php','lib/loader.php','scripts/core_worker.php') as $file) unlink($fixture.$file);
    rmdir($fixture.'lib'); rmdir($fixture.'scripts'); rmdir($fixture);

    if (getenv('MJD_TEST_SIM_URL')) {
        $base=rtrim(getenv('MJD_TEST_SIM_URL'),'/');
        if (!preg_match('#^http://127\.0\.0\.1:[0-9]+$#D',$base)) throw new RuntimeException('Simulation must be local');
        check(Core::api()->request($base.'/api/success','GET',array(),true) === array('ok'=>true), 'real HTTP client unwraps API response');
        check(Core::api()->request($base.'/api/json-error','GET',array(),true) === false, 'real HTTP client rejects JSON 500');
        check(Core::api()->request($base.'/api/html-error','GET',array(),true) === false, 'real HTTP client rejects proxy HTML 500');
        $short=new \MajorDoMo\Core\ApiClient(Core::queue(),0.05);
        check($short->request($base.'/slow/success','GET',array(),true) === false, 'real HTTP client enforces timeout');
        check($short->request('http://127.0.0.1:1/unreachable','GET',array(),true) === false, 'real HTTP client reports refused connection');
    }

    $connectionId = (int)SQLSelectOne('SELECT CONNECTION_ID() AS C')['C'];
    $admin->query('KILL '.$connectionId);
    check((int)SQLSelectOne('SELECT 42 AS N')['N'] === 42, 'safe read reconnects after connection loss');
    $connectionId = (int)SQLSelectOne('SELECT CONNECTION_ID() AS C')['C']; $admin->query('KILL '.$connectionId);
    check($db->Exec("INSERT INTO settings (NAME,VALUE) VALUES ('must-not-retry','x')",true) === false, 'write is not replayed after ambiguous connection failure');
    check(!SQLSelectOne("SELECT ID FROM settings WHERE NAME='must-not-retry'"), 'ambiguous write did not create a duplicate');
    execTest('START TRANSACTION'); $connectionId=(int)SQLSelectOne('SELECT CONNECTION_ID() AS C')['C']; $admin->query('KILL '.$connectionId);
    check($db->Exec('SELECT 1',true) === false, 'transaction is never silently resumed after reconnect');
    check($db->Exec('COMMIT',true) === false, 'lost transaction cannot report a successful commit on a new connection');
    execTest('ROLLBACK');
    check((int)SQLSelectOne('SELECT 1 AS N')['N']===1, 'explicit rollback restores normal database operations');
    echo 'RESULT ' . $checks . ' checks passed' . PHP_EOL;
} finally {
    $db->Disconnect();
    $admin->query('DROP DATABASE `' . DB_NAME . '`');
    $admin->close();
}
