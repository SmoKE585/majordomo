<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * Pure-logic checks for keenetic.class.php (no database, no HTTP).
 */
error_reporting(E_ALL);

define('DIR_MODULES', dirname(__DIR__, 3) . '/modules/');
define('DIR_TEMPLATES', dirname(__DIR__, 3) . '/templates/');
define('ROOT', dirname(__DIR__, 3) . '/');

class module
{
    public $config = array();
    public $name = '';
    public $title = '';
    public $module_category = '';
    public $action = '';
    public $id = 0;
    public $view_mode = '';
    public $edit_mode = '';
    public $data_source = '';
    public $tab = '';
    public $mode = '';
    public $result = '';

    public function checkInstalled() {}
    public function getConfig() { $this->config = array(); }
    public function saveConfig() {}
    public function saveParams($data = 1) { return ''; }
    public function getParams() {}
    public function redirect($url) {}
}

require dirname(__DIR__) . '/keenetic.class.php';

$failed = 0;
function check($condition, $message)
{
    global $failed;
    if ($condition) {
        echo "ok   {$message}\n";
    } else {
        $failed++;
        echo "FAIL {$message}\n";
    }
}

function invoke($object, $method, $args = array())
{
    $reflection = new ReflectionMethod($object, $method);
    $reflection->setAccessible(true);
    return $reflection->invokeArgs($object, $args);
}

$keenetic = new keenetic();

$encrypted = $keenetic->dsCrypt('Test123!');
check($encrypted !== 'Test123!' && $encrypted !== '', 'password is encrypted');
check($keenetic->dsCrypt($encrypted, true) === 'Test123!', 'password round-trip');
check($keenetic->decryptPassword($encrypted) === 'Test123!', 'legacy password decrypts');

$special = 'p@ss-w~rd:1 & more';
$stored = $keenetic->encryptPassword($special);
check(strpos($stored, 'b64:') === 0, 'new password format');
check($stored !== $special, 'new password is encrypted');
check($keenetic->decryptPassword($stored) === $special, 'new password round-trip');

check(invoke($keenetic, 'normalizeAddress', array('https://my.keenetic.net/')) === 'my.keenetic.net', 'address scheme stripped');
check(invoke($keenetic, 'normalizeAddress', array('http://192.168.1.1:8080/a/b')) === '192.168.1.1:8080', 'address path stripped');
check(invoke($keenetic, 'normalizeAddress', array(' 192.168.1.1/ ')) === '192.168.1.1', 'address slash trimmed');

check(invoke($keenetic, 'isValidAddress', array('192.168.1.1')) === true, 'valid ip');
check(invoke($keenetic, 'isValidAddress', array('my.keenetic.net')) === true, 'valid dns');
check(invoke($keenetic, 'isValidAddress', array('my.keenetic.net:8443')) === true, 'valid dns with port');
check(invoke($keenetic, 'isValidAddress', array('bad addr')) === false, 'invalid address');

check(invoke($keenetic, 'isValidDnsName', array('camera.local')) === true, 'valid dns name');
check(invoke($keenetic, 'isValidDnsName', array('bad name')) === false, 'invalid dns name');

check(invoke($keenetic, 'normalizeRows', array(array('id' => 'x'))) === array(array('id' => 'x')), 'normalize single row');
check(invoke($keenetic, 'normalizeRows', array(array(array('id' => '1'), array('id' => '2')))) === array(array('id' => '1'), array('id' => '2')), 'normalize list');

$log = implode("\n", range(1, 40));
$trimmed = invoke($keenetic, 'trimLog', array($log));
check(count(explode("\n", $trimmed)) === 30, 'log trimmed to 30 lines');

$router = array('MODEL' => 'Keenetic Giga');
$host = array(
    'uptime' => 120,
    'hostname' => 'tv',
    'rxbytes' => 2048,
    'txbytes' => 1024,
    'ap' => 'WifiMaster0/AccessPoint1',
    'mode' => '11ac',
    'txss' => 2,
    'ht' => 80,
    'txrate' => 866,
    'rssi' => -55,
);
$wifies = array(array('id' => 'WifiMaster0/AccessPoint1', 'ssid' => 'Home'));
$info = $keenetic->parse_data($router, $host, array(), $wifies);
check($info['UPTIME'] === 120 && $info['RXBYTES'] === 2048, 'parse uptime and traffic');
check($info['NET'] === 'Home' && $info['FREQ'] === '5ГГц', 'parse wifi network and frequency');
check($info['RSSI'] === -55 && $info['ROUTER'] === 'Keenetic Giga', 'parse rssi and router');

$mwsHost = array('mws' => array('ap' => 'WifiMaster1/AccessPoint0', 'cid' => 'c1', 'mode' => '11ax', 'rssi' => -60, 'txss' => 1, 'ht' => 20, 'txrate' => 300));
$mwsMembers = array(array('cid' => 'c1', 'known-host' => 'Extender'));
$mwsInfo = $keenetic->parse_data($router, $mwsHost, $mwsMembers, array());
check($mwsInfo['ROUTER'] === 'Extender' && $mwsInfo['RSSI'] === -60, 'parse mws member');

$interfaces = array(
    array('global' => 'true', 'connected' => 'yes', 'defaultgw' => '1', 'priority' => 100, 'description' => 'PPPoE', 'address' => '203.0.113.7', 'uptime' => 60),
    array('global' => 'true', 'connected' => 'yes', 'defaultgw' => '1', 'priority' => 10, 'description' => 'Backup', 'address' => '198.51.100.2', 'uptime' => 5),
);
$wan = $keenetic->backupWAN($interfaces);
check($wan['STATE'] === 1 && $wan['NAME'] === 'PPPoE' && $wan['WAN'] === 0, 'active WAN detected');
$wan = $keenetic->backupWAN(array(array('global' => 'true', 'connected' => 'no', 'priority' => 1)));
check($wan['STATE'] === 0, 'no active WAN');

check($keenetic->isIP('10.0.0.1') === true, 'isIP true');
check($keenetic->isIP('example.com') === false, 'isIP false');

exit($failed === 0 ? 0 : 1);
