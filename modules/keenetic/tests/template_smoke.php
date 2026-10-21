<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * Local template smoke test for the Keenetic module.
 * Renders every admin view with representative data through jTemplate and
 * reports leftover template tags, so syntax errors are caught without a DB.
 */
error_reporting(E_ALL & ~E_DEPRECATED);

define('ALTERNATIVE_TEMPLATES', 'templates_alt');
define('DIR_MODULES', dirname(__DIR__, 3) . '/modules/');
define('SETTINGS_SITE_LANGUAGE', 'ru');

$core = getenv('MAJORDOMO_ROOT');
if (!$core) {
    $candidates = array(
        dirname(__DIR__, 4) . '/___majordomo',
        dirname(__DIR__, 4) . '/majordomo',
        dirname(__DIR__) . '/majordomo',
    );
    foreach ($candidates as $candidate) {
        if (is_file($candidate . '/lib/jTemplate.class.php')) {
            $core = $candidate;
            break;
        }
    }
}
if (!$core || !is_file($core . '/lib/jTemplate.class.php')) {
    fwrite(STDERR, "SKIP: jTemplate.class.php not found. Set MAJORDOMO_ROOT to the MajorDoMo checkout.\n");
    exit(0);
}
require $core . '/lib/jTemplate.class.php';

function setEvalCode($code = '') {}
function startMeasure($name = '') {}
function endMeasure($name = '') {}
function DebMes($message = '', $folder = '') {}

class DummyOwner
{
    public $name = 'panel';
    public $action = 'admin';
    public $ajax = 0;
    public $owner;

    public function __construct()
    {
        $this->owner = $this;
    }

    public function parseLinks($html)
    {
        return $html;
    }
}

$src = dirname(__DIR__, 3) . '/templates/keenetic';
$tmp = sys_get_temp_dir() . '/keenetic-tpl-' . getmypid();
@mkdir($tmp, 0777, true);

$files = glob($src . '/*.html');
foreach ($files as $file) {
    $content = file_get_contents($file);
    $content = preg_replace(
        '/\[#module\s+name="linkedobject".*?#\]/s',
        '<div class="md-linkedobject"><div class="md-linkedobject__head"><label class="md-field__label md-linkedobject__label">Объект</label></div><div class="md-linkedobject__control"><div class="md-input-shell md-linkedobject__shell"><input class="md-input" value="Kitchen.Light" aria-label="Объект"></div></div><div class="md-linkedobject__foot"><div class="md-field__hint md-linkedobject__hint">Привязка к свойству объекта</div></div></div>',
        $content
    );
    $content = preg_replace(
        '/\[#module\s+name="xray".*?#\]/s',
        '<span class="md-badge md-badge--success"><span class="md-badge__dot"></span>Цикл активен</span>',
        $content
    );
    $content = preg_replace('/\[#module\s+.*?#\]/s', '<span class="module-stub"></span>', $content);
    file_put_contents($tmp . '/' . basename($file), $content);
}

// Define every <#CONST#> used by the templates.
foreach (glob($tmp . '/*.html') as $file) {
    if (preg_match_all('/<#(\w+)#>/', file_get_contents($file), $m)) {
        foreach (array_unique($m[1]) as $name) {
            if (!defined($name)) {
                define($name, $name === 'ROOTHTML' ? '/' : $name);
            }
        }
    }
}

// Collect loop definitions and tokens.
$loopRows = array();
$tokens = array();
foreach (glob($tmp . '/*.html') as $file) {
    $content = file_get_contents($file);
    if (preg_match_all('/\[#begin (\w+)#\](.*?)\[#end \1#\]/s', $content, $m, PREG_SET_ORDER)) {
        foreach ($m as $block) {
            preg_match_all('/\[#(\w+)#\]/', $block[2], $t);
            $loopRows[$block[1]] = array_combine(
                $t[1] ?: array('ID'),
                array_fill(0, count($t[1] ?: array('ID')), '1')
            );
        }
    }
    if (preg_match_all('/\[#(\w+)#\]/', $content, $t)) {
        foreach ($t[1] as $name) {
            $tokens[$name] = true;
        }
    }
}

$base = array();
foreach (array_keys($tokens) as $name) {
    $base[$name] = '1';
}
$base = array_merge($base, array(
    'FLASH_OK' => '',
    'FLASH_ERR' => '',
    'VIEW_MODE' => 'search_keenetic_routers',
    'TAB' => '',
    'ACTIVE_TAB' => '',
    'ROOTHTML' => '/',
    'ID' => '1',
    'STATUS' => '1',
    'INET_STATUS' => '1',
    'STATE' => '1',
    'REGISTERED' => '1',
    'TRACK' => '1',
    'MWS' => '1',
    'UPDATE' => '1',
    'NO_UPDATE' => '0',
    'PASSWORD_SET' => '1',
    'IS_INTERNET' => '0',
    'RSSI' => '-52',
    'SIGNAL_PERCENT' => '80',
    'SIGNAL_TONE' => 'success',
    'SIGNAL_LABEL' => 'Отличный',
    'STATUS_STATE' => 'online',
    'STATUS_LABEL' => 'Онлайн',
    'INET_STATE' => 'online',
    'INET_LABEL' => 'Интернет есть',
    'LINK_STATE' => 'online',
    'LINK_LABEL' => 'Подключено',
    'ADMIN_STATE' => 'online',
    'ADMIN_LABEL' => 'Включено',
    'TRACK_STATE' => 'online',
    'TRACK_LABEL' => 'Отслеживается',
    'WIFI_ROUTER' => 'Keenetic Giga',
    'WIFI_MODE' => '802.11ac 2x2 80МГц 866Мбит',
    'WIFI_NET' => 'Home',
    'WIFI_FREQ' => '5ГГц',
    'WAN_STATE_LABEL' => 'Основной канал',
    'HREF_FW' => 'https://example.com/release-notes',
    'ADDRESS_HREF' => 'http://192.168.1.1',
    'IP_HREF' => 'http://192.168.1.10',
    'TYPE_TONE' => 'info',
    'TYPE_ICON' => 'wifi',
    'TYPE_LABEL' => 'Wi-Fi',
    'ROW_STATE' => 'online',
    'ROW_UPDATE' => '1',
    'ROW_TYPE' => 'wifi',
    'ROW_TRACK' => '1',
    'BINDING' => 'Kitchen.Light',
    'SELECTED' => '1',
    'INTERNET_STATUS_TEXT' => 'Есть, основной канал',
    'INTERNET_STATUS_STATE' => 'online',
    'INTERNET_IP' => '203.0.113.7',
    'INTERNET_UPDATED_HUMAN' => '5 минут назад',
    'INTERNET_LOG_HTML' => '2026-10-21 10:00:00 Соединение восстановлено.<br>',
    'LOG_HTML' => '2026-10-21 10:00:00 Устройство в сети.<br>',
    'LIVE_ERROR' => '',
    'ERR_ALERT' => 'Тестовая ошибка',
    'ERRORS' => 'syntax error',
    'WARN_ALERT' => 'Предупреждение',
    'UPDATED_HUMAN' => '5 минут назад',
    'UPDATED_TITLE' => '21.10.2026 10:00:00',
    'FLASH_OK' => '',
    'FLASH_ERR' => '',
    'ROUTERS_TOTAL' => '1',
    'ROUTERS_ONLINE' => '1',
    'ROUTERS_OFFLINE' => '1',
    'INTERNET_ONLINE' => '1',
    'INTERNET_OFFLINE' => '0',
    'UPDATES_TOTAL' => '1',
    'DEVICES_TOTAL' => '12',
    'DEVICES_ONLINE' => '9',
    'DEVICES_TRACKED' => '3',
    'DEVICES_WIFI' => '6',
    'CONNECTIONS_TOTAL' => '4',
    'CONNECTIONS_UP' => '2',
    'DNS_TOTAL' => '2',
    'STATS_ROUTERS' => '1',
    'STATS_DEVICES' => '12',
    'STATS_TRACKED' => '3',
    'STATS_CONNECTIONS' => '2',
    'DB_ROUTERS' => '1',
    'DB_DEVICES' => '3',
    'DB_CONNECTIONS' => '2',
    'HAS_TABLES' => '1',
    'KEENETIC_VERSION' => '0.5',
    'LOG_DEBMES' => '0',
    'SETTINGS_CODEEDITOR_TURNONSETTINGS' => '1',
    'SETTINGS_CODEEDITOR_SHOWERROR' => '1',
    'NOTIFY_FIRMWARE' => '1',
    'CHECK_FIRMWARE' => '1',
    'REQ_PERIOD' => '5',
    'AUTO_REBOOT' => '0',
    'TITLE' => 'Дом',
    'ADDRESS' => '192.168.1.1',
    'MODEL' => 'Keenetic Giga (KN-1011)',
    'FIRMWARE' => '4.2.1',
    'NEW_FIRMWARE' => '4.2.2',
    'SERIAL' => 'S1234567890',
    'LOGIN' => 'admin',
    'UPTIME_HUMAN' => '3 дня 4 ч',
    'RXBYTES_HUMAN' => '1,2 ГБ',
    'TXBYTES_HUMAN' => '640 МБ',
    'WAN_NAME' => 'PPPoE',
    'WAN_IP' => '203.0.113.7',
    'HOSTNAME' => 'tv-living-room',
    'MAC' => '68:72:c3:56:42:81',
    'IP' => '192.168.1.10',
    'ROUTER_ID' => '1',
    'ROUTER_TITLE' => 'Дом',
    'ROUTER_TITLE_OUT' => 'Дом',
    'ROUTER_ADDRESS' => '192.168.1.1',
    'IS_NEW' => '0',
    'RESULT' => array(),
    'DEVICES' => array(),
    'CONNECTIONS' => array(),
    'DNS' => array(),
    'ROUTER_OPTIONS' => array(),
    'PROPERTIES' => array(),
));

// Realistic rows keep the previews readable while still exercising the same
// template blocks as the generic defaults above.
$routerRow = array_merge($loopRows['RESULT'] ?? array(), array(
    'ID' => '1',
    'TITLE' => 'Дом',
    'ADDRESS' => '192.168.1.1',
    'ADDRESS_HREF' => 'http://192.168.1.1',
    'MODEL' => 'Keenetic Giga (KN-1011)',
    'FIRMWARE' => '4.2.1',
    'NEW_FIRMWARE' => '4.2.2',
    'HREF_FW' => 'https://help.keenetic.com/release',
    'UPDATE' => '1',
    'STATUS_STATE' => 'online',
    'STATUS_LABEL' => 'Онлайн',
    'INET_STATE' => 'online',
    'INET_LABEL' => 'Интернет есть',
    'ROW_STATE' => 'online',
    'ROW_UPDATE' => '1',
    'DEVICES_TOTAL' => '12',
    'DEVICES_ONLINE' => '9',
    'DEVICES_TRACKED' => '3',
    'CONNECTIONS_TOTAL' => '4',
    'CONNECTIONS_UP' => '2',
    'REQ_PERIOD' => '5',
    'UPDATED_HUMAN' => '12 секунд назад',
    'UPDATED_TITLE' => '21.10.2026 15:00:00',
    'SEARCH' => 'Дом 192.168.1.1 Keenetic Giga 4.2.1',
));
$deviceRow = array_merge($loopRows['DEVICES'] ?? array(), array(
    'ID' => '7',
    'ROUTER_ID' => '1',
    'ROUTER_TITLE' => 'Дом',
    'TITLE' => 'TV в гостиной',
    'MAC' => '68:72:c3:56:42:81',
    'IP' => '192.168.1.126',
    'IP_HREF' => 'http://192.168.1.126',
    'STATUS_STATE' => 'online',
    'STATUS_LABEL' => 'В сети',
    'TYPE_LABEL' => 'Wi-Fi',
    'TYPE_ICON' => 'wifi',
    'TYPE_TONE' => 'info',
    'ROW_TYPE' => 'wifi',
    'ROW_STATE' => 'online',
    'REGISTERED_LABEL' => 'Зарегистрировано',
    'TRACK' => '1',
    'TRACK_LABEL' => 'Отслеживается',
    'BINDING' => 'TV.LivingRoom',
    'UPDATED_HUMAN' => '2 минуты назад',
    'UPDATED_TITLE' => '21.10.2026 14:58:00',
    'SEARCH' => 'TV в гостиной 68:72:c3:56:42:81 192.168.1.126 Wi-Fi TV.LivingRoom',
));
$connectionRow = array_merge($loopRows['CONNECTIONS'] ?? array(), array(
    'ID' => '3',
    'TITLE' => 'Wireguard0',
    'TYPE' => 'Wireguard',
    'ADDRESS' => '10.8.0.2',
    'STATE' => '1',
    'STATUS' => '1',
    'ADMIN_STATE' => 'online',
    'ADMIN_LABEL' => 'Включено',
    'LINK_STATE' => 'online',
    'LINK_LABEL' => 'Подключено',
    'BINDING' => 'Home.VPN',
    'UPDATED_HUMAN' => '5 минут назад',
    'UPDATED_TITLE' => '21.10.2026 14:55:00',
    'SEARCH' => 'Wireguard0 Wireguard 10.8.0.2 Включено Подключено Home.VPN',
));
$dnsRow = array_merge($loopRows['DNS'] ?? array(), array(
    'ROUTER_ID' => '1',
    'TITLE' => 'camera.local',
    'IP' => '192.168.1.10',
));

$scenarios = array(
    'routers' => array('VIEW_MODE' => 'search_keenetic_routers', 'RESULT' => array($routerRow)),
    'routers-empty' => array(
        'VIEW_MODE' => 'search_keenetic_routers',
        'RESULT' => array(),
        'ROUTERS_TOTAL' => '0',
        'ROUTERS_ONLINE' => '0',
        'INTERNET_ONLINE' => '0',
        'UPDATES_TOTAL' => '0',
        'DEVICES_TOTAL' => '0',
        'DEVICES_ONLINE' => '0',
        'DEVICES_TRACKED' => '0',
    ),
    'routers-offline' => array(
        'VIEW_MODE' => 'search_keenetic_routers',
        'RESULT' => array(array_merge($loopRows['RESULT'] ?? array(), array(
            'STATUS' => '0',
            'STATUS_STATE' => 'offline',
            'STATUS_LABEL' => 'Недоступен',
            'INET_STATUS' => '0',
            'INET_STATE' => 'idle',
            'INET_LABEL' => 'Нет данных',
            'UPDATE' => '0',
            'NO_UPDATE' => '1',
            'ROW_STATE' => 'offline',
            'ROW_UPDATE' => '0',
            'DEVICES_TOTAL' => '0',
            'DEVICES_ONLINE' => '0',
            'REQ_PERIOD' => '0',
        ))),
    ),
    'router-overview' => array('VIEW_MODE' => 'edit_keenetic_routers', 'ID' => '1', 'TAB' => ''),
    'router-overview-empty' => array(
        'VIEW_MODE' => 'edit_keenetic_routers',
        'ID' => '1',
        'TAB' => '',
        'UPDATE' => '0',
        'INTERNET_LOG_HTML' => '',
        'DEVICES_ONLINE' => '0',
        'CONNECTIONS_UP' => '0',
    ),
    'router-settings' => array('VIEW_MODE' => 'edit_keenetic_routers', 'ID' => '1', 'TAB' => 'settings'),
    'router-settings-error' => array(
        'VIEW_MODE' => 'edit_keenetic_routers',
        'ID' => '1',
        'TAB' => 'settings',
        'ERR' => '1',
        'PASSWORD_SET' => '0',
        'NO_UPDATE' => '1',
    ),
    'router-new' => array('VIEW_MODE' => 'edit_keenetic_routers', 'ID' => '', 'TAB' => 'settings', 'IS_NEW' => '1'),
    'router-devices' => array('VIEW_MODE' => 'edit_keenetic_routers', 'ID' => '1', 'TAB' => 'data', 'DEVICES' => array($deviceRow)),
    'router-devices-empty' => array('VIEW_MODE' => 'edit_keenetic_routers', 'ID' => '1', 'TAB' => 'data', 'DEVICES' => array()),
    'router-connections' => array('VIEW_MODE' => 'edit_keenetic_routers', 'ID' => '1', 'TAB' => 'cnct', 'CONNECTIONS' => array($connectionRow)),
    'router-connections-empty' => array('VIEW_MODE' => 'edit_keenetic_routers', 'ID' => '1', 'TAB' => 'cnct', 'CONNECTIONS' => array(), 'ERR_CODE' => '1'),
    'router-dns' => array('VIEW_MODE' => 'edit_keenetic_routers', 'ID' => '1', 'TAB' => 'dns', 'DNS' => array($dnsRow)),
    'router-dns-empty' => array('VIEW_MODE' => 'edit_keenetic_routers', 'ID' => '1', 'TAB' => 'dns', 'DNS' => array(), 'DNS_ERR' => '1'),
    'devices' => array('VIEW_MODE' => 'devices', 'RESULT' => array($deviceRow), 'ROUTER_OPTIONS' => array(array('ID' => '1', 'TITLE' => 'Дом', 'SELECTED' => '1'))),
    'devices-empty' => array('VIEW_MODE' => 'devices', 'RESULT' => array(), 'ROUTER_OPTIONS' => array(), 'DEVICES_TOTAL' => '0'),
    'device-overview' => array('VIEW_MODE' => 'info_keenetic_devices', 'ID' => '7', 'ROUTER_ID' => '1', 'ACTIVE_TAB' => ''),
    'device-internet' => array(
        'VIEW_MODE' => 'info_keenetic_devices',
        'ID' => '7',
        'ROUTER_ID' => '1',
        'ACTIVE_TAB' => '',
        'IS_INTERNET' => '1',
        'TRACK' => '0',
        'TRACK_STATE' => 'idle',
        'STATUS' => '0',
        'WIFI_ROUTER' => '',
        'WIFI_MODE' => '',
        'RSSI' => '0',
    ),
    'device-code' => array('VIEW_MODE' => 'info_keenetic_devices', 'ID' => '7', 'ROUTER_ID' => '1', 'ACTIVE_TAB' => 'code'),
    'device-code-error' => array(
        'VIEW_MODE' => 'info_keenetic_devices',
        'ID' => '7',
        'ROUTER_ID' => '1',
        'ACTIVE_TAB' => 'code',
        'ERR_CODE' => '1',
        'OK' => '0',
        'ERRORS' => 'syntax error',
        'ERR_LINE' => '12',
    ),
    'device-log' => array('VIEW_MODE' => 'info_keenetic_devices', 'ID' => '7', 'ROUTER_ID' => '1', 'ACTIVE_TAB' => 'log', 'LOG_HTML' => ''),
    'device-error' => array(
        'VIEW_MODE' => 'info_keenetic_devices',
        'ID' => '7',
        'ROUTER_ID' => '1',
        'ACTIVE_TAB' => '',
        'ERR' => '1',
        'LIVE_ERROR' => 'Роутер не отдал данные.',
    ),
    'settings' => array('VIEW_MODE' => 'settings'),
    'settings-no-tables' => array('VIEW_MODE' => 'settings', 'HAS_TABLES' => '0'),
    'help' => array('VIEW_MODE' => 'show_help'),
    'dashboard-fallback' => array('VIEW_MODE' => ''),
);

$owner = new DummyOwner();
$previewDir = '';
$previewTheme = 'light';
foreach (isset($argv) ? $argv : array() as $argument) {
    if (strpos($argument, '--preview-dir=') === 0) {
        $previewDir = rtrim(substr($argument, 14), '/\\');
    }
    if (strpos($argument, '--theme=') === 0) {
        $previewTheme = substr($argument, 8) === 'dark' ? 'dark' : 'light';
    }
}
if ($previewDir !== '' && !is_dir($previewDir)) {
    @mkdir($previewDir, 0777, true);
}

$failed = false;
foreach ($scenarios as $name => $overrides) {
    $data = array_merge($base, $overrides);
    $data['VIEW_MODE'] = $overrides['VIEW_MODE'];

    $template = $tmp . '/action_admin.html';
    $j = new jTemplate($template, $data, $owner);
    $out = $j->result;

    // The preview page is opened from file:// and supplies its own CSS links.
    $out = preg_replace('#<link rel="stylesheet"[^>]*keenetic\.css[^>]*>#i', '', $out);
    $out = preg_replace('#<script src="[^"]*keenetic\.js[^"]*"></script>#i', '', $out);

    $problems = array();
    if (strpos($out, '[#') !== false) {
        $problems[] = 'leftover [# tag';
    }
    if (strpos($out, '<#') !== false) {
        $problems[] = 'leftover <# tag';
    }
    if (strpos($out, '[表单]') !== false) {
        $problems[] = 'placeholder text';
    }
    if (trim($out) === '') {
        $problems[] = 'empty output';
    }
    if ($previewDir !== '') {
        $html = '<!doctype html><html lang="ru" data-bs-theme="' . $previewTheme . '">'
            . '<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>Keenetic · ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</title>'
            . '<link rel="stylesheet" href="../assets/admin/admin.css">'
            . '<link rel="stylesheet" href="../assets/modules/keenetic/keenetic.css">'
            . '<style>html,body{min-height:100%}body{padding:24px;background:var(--md-ui-bg);color:var(--md-ui-text)}'
            . '.preview{max-width:1440px;margin:0 auto}@media(max-width:575.98px){body{padding:12px}}</style>'
            . '</head><body class="md-admin-shell"><div class="preview">' . $out . '</div></body></html>';
        file_put_contents($previewDir . '/' . $name . '.html', $html);
    }
    if ($problems) {
        $failed = true;
        echo "FAIL {$name}: " . implode(', ', $problems) . "\n";
        if (preg_match('/(\[#.{0,80})/', $out, $m)) {
            echo "  near: " . $m[1] . "\n";
        }
    } else {
        echo "ok   {$name} (" . strlen($out) . " bytes)\n";
    }
}

foreach (glob($tmp . '/*') as $file) {
    @unlink($file);
}
@rmdir($tmp);

exit($failed ? 1 : 0);
