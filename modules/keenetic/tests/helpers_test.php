<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * Helper unit checks for the Keenetic module (no database required).
 */
require dirname(__DIR__) . '/keenetic_helpers.inc.php';

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

check(keenetic_h('<b>&') === '&lt;b&gt;&amp;', 'HTML escaping');

$online = keenetic_router_state(1);
check($online['state'] === 'online' && $online['label'] === 'Онлайн', 'router online state');
check(keenetic_router_state(0)['state'] === 'offline', 'router offline state');

check(keenetic_internet_state(array('STATUS' => 0))['state'] === 'idle', 'internet unknown');
check(keenetic_internet_state(array('STATUS' => 1, 'INET_STATUS' => 1))['state'] === 'online', 'internet online');
check(keenetic_internet_state(array('STATUS' => 1, 'INET_STATUS' => 0))['state'] === 'error', 'internet offline');

check(keenetic_firmware_update(array('FIRMWARE' => '4.1', 'NEW_FIRMWARE' => '4.2')) === true, 'firmware update available');
check(keenetic_firmware_update(array('FIRMWARE' => '4.2', 'NEW_FIRMWARE' => '4.2')) === false, 'firmware up to date');
check(keenetic_firmware_update(array('FIRMWARE' => '4.1', 'NEW_FIRMWARE' => '4.2', 'NO_UPDATE' => 1)) === false, 'firmware check disabled');

check(keenetic_uptime_human(90061) === '1 день 1 ч', 'uptime formatting');
check(keenetic_uptime_human(45) === '45 с', 'uptime seconds');
check(keenetic_traffic_human(0) === '0 Б', 'traffic zero');
check(keenetic_traffic_human(1024 * 1024 * 1.5) === '1,5 МБ', 'traffic megabytes');

$signal = keenetic_signal_meta(-50);
check($signal['percent'] === 100 && $signal['tone'] === 'success', 'signal excellent');
check(keenetic_signal_meta(-75)['tone'] === 'warning', 'signal medium');
check(keenetic_signal_meta(0)['known'] === false, 'signal unknown');

check(keenetic_device_kind(1)['label'] === 'Wi-Fi', 'device wifi kind');
check(keenetic_device_kind(0)['label'] === 'LAN', 'device lan kind');

check(keenetic_binding_label('Kitchen', 'Light', '') === 'Kitchen.Light', 'binding property');
check(keenetic_binding_label('Kitchen', '', 'On') === 'Kitchen → On', 'binding method');
check(keenetic_binding_label('', 'Light', '') === '', 'binding empty');

$conn = keenetic_connection_state(array('STATE' => 1, 'STATUS' => 1));
check($conn['link_state'] === 'online', 'connection online');
$conn = keenetic_connection_state(array('STATE' => 0, 'STATUS' => 0));
check($conn['link_state'] === 'idle' && $conn['admin_label'] === 'Выключено', 'connection disabled');
$conn = keenetic_connection_state(array('STATE' => 1, 'STATUS' => 0));
check($conn['link_state'] === 'error', 'connection error');

check(keenetic_is_ip('192.168.1.1') === true, 'ipv4');
check(keenetic_is_ip('192.168.1.1:8080') === true, 'ipv4 with port');
check(keenetic_is_ip('my.keenetic.net') === false, 'dns is not ip');
check(keenetic_is_ip('192.168.1.999') === false, 'invalid ipv4');

check(keenetic_address_href('192.168.1.1') === 'http://192.168.1.1', 'http for local ip');
check(keenetic_address_href('my.keenetic.net') === 'https://my.keenetic.net', 'https for dns');

check(keenetic_safe_url('https://help.keenetic.com/x') !== '', 'safe https url');
check(keenetic_safe_url('javascript:alert(1)') === '', 'unsafe url rejected');

$row = keenetic_escape_row(array('TITLE' => '<script>', 'NUM' => 5));
check($row['TITLE'] === '&lt;script&gt;' && $row['NUM'] === '5', 'row escaping');

check(strpos(keenetic_relative_time(date('Y-m-d H:i:s', time() - 120)), 'минут') !== false, 'relative past');
check(keenetic_relative_time('') === '—', 'relative empty');

exit($failed === 0 ? 0 : 1);
