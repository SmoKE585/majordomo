<?php
/**
 * Первичная настройка docker-стенда MajorDoMo.
 *
 * Запускается entrypoint'ом после миграций, от имени www-data. Скрипт
 * идемпотентен: не меняет пароль существующего администратора без явного
 * MJD_ADMIN_RESET_PASSWORD=1, не перезаписывает настройки, которые уже заданы,
 * и только при первом запуске направляет MQTT на встроенный брокер.
 *
 * Код возврата:
 *   0 — настройка выполнена (или уже была выполнена);
 *   3 — в базе нет обязательных таблиц, мастер /install.php должен остаться
 *       доступным.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "configure.php запускается только из CLI\n");
    exit(1);
}

$root = dirname(__DIR__, 2);

require_once $root . '/bootstrap.php';
require_once $root . '/config.php';
require_once $root . '/lib/loader.php';

use MajorDoMo\Core\Config;
use MajorDoMo\Core\Core;
use MajorDoMo\Core\Install\InstallState;
use MajorDoMo\Core\Users\Passwords;

function dockerOut(string $message): void
{
    echo '[configure] ' . $message . PHP_EOL;
}

function envValue(string $name, string $default = ''): string
{
    $value = getenv($name);
    return is_string($value) && $value !== '' ? $value : $default;
}

$database = Core::database();

foreach (array('users', 'settings', 'project_modules') as $table) {
    if (!$database->tableExists($table)) {
        dockerOut('нет таблицы ' . $table . ': оставляю мастер /install.php');
        exit(3);
    }
}

// ---------------------------------------------------------------------------
// Администратор
// ---------------------------------------------------------------------------

$username = envValue('MJD_ADMIN_USER', 'admin');
$password = envValue('MJD_ADMIN_PASSWORD', 'majordomo');
$displayName = envValue('MJD_ADMIN_NAME', 'Docker Admin');
$resetPassword = envValue('MJD_ADMIN_RESET_PASSWORD', '0') === '1';

try {
    $hash = Passwords::hash($password);
} catch (Throwable $error) {
    fwrite(STDERR, '[configure] некорректный MJD_ADMIN_PASSWORD: ' . $error->getMessage() . PHP_EOL);
    exit(4);
}

$escapedUsername = $database->escape($username);
$user = $database->one("SELECT * FROM users WHERE USERNAME='" . $escapedUsername . "' LIMIT 1");

if (!$user) {
    $record = array(
        'USERNAME' => $username,
        'NAME' => $displayName,
        'EMAIL' => '',
        'SKYPE' => '',
        'MOBILE' => '',
        'AVATAR' => '',
        'PASSWORD' => $hash,
        'IS_ADMIN' => 1,
        'IS_DEFAULT' => 1,
        'LINKED_OBJECT' => '',
        'HOST' => '',
        'ACTIVE_CONTEXT_ID' => 0,
        'ACTIVE_CONTEXT_EXTERNAL' => 0,
        'ACTIVE_CONTEXT_UPDATED' => date('Y-m-d H:i:s'),
        'ACTIVE_CONTEXT_HISTORY' => '',
        'COLOR' => '',
    );
    $userId = $database->insert('users', $record);
    dockerOut('создан администратор ' . $username . ' (ID ' . $userId . ')');
} else {
    $stored = (string)($user['PASSWORD'] ?? '');
    $emptyHash = hash('sha512', '');
    $needsPassword = $resetPassword || $stored === '' || hash_equals($emptyHash, $stored);
    $record = array('ID' => (int)$user['ID'], 'IS_ADMIN' => 1);
    if ((string)($user['IS_DEFAULT'] ?? '0') !== '1') {
        $record['IS_DEFAULT'] = 1;
    }
    if ($needsPassword) {
        $record['PASSWORD'] = $hash;
    }
    $database->update('users', $record);
    dockerOut($needsPassword
        ? 'администратор ' . $username . ': пароль установлен'
        : 'администратор ' . $username . ' уже настроен (пароль не меняю)');
}

// ---------------------------------------------------------------------------
// Параметры интерфейса, которых нет в старом демо-дампе
// ---------------------------------------------------------------------------

$settingDefaults = array(
    'SITE_TIMEZONE' => envValue('MJD_SITE_TIMEZONE', envValue('TZ', 'Europe/Moscow')),
    'SITE_LANGUAGE' => envValue('MJD_SITE_LANGUAGE', 'ru'),
    'THEME' => envValue('MJD_THEME', 'dark'),
    'GENERAL_ALICE_NAME' => 'MajorDoMo Docker',
);

foreach ($settingDefaults as $name => $value) {
    $existing = $database->one("SELECT ID, VALUE FROM settings WHERE NAME='" . $database->escape($name) . "' LIMIT 1");
    if (!$existing) {
        $record = array(
            'PRIORITY' => 0,
            'HR' => 0,
            'TITLE' => $name,
            'NAME' => $name,
            'TYPE' => 'text',
            'NOTES' => '',
            'VALUE' => $value,
            'DEFAULTVALUE' => '',
            'URL' => '',
            'URL_TITLE' => '',
            'DATA' => '',
        );
        $database->insert('settings', $record);
        dockerOut('добавлена настройка ' . $name . '=' . $value);
    } elseif (trim((string)($existing['VALUE'] ?? '')) === '' && $value !== '') {
        $database->update('settings', array('ID' => (int)$existing['ID'], 'VALUE' => $value));
        dockerOut('заполнена пустая настройка ' . $name . '=' . $value);
    }
}

// ---------------------------------------------------------------------------
// MQTT: встроенный брокер mosquitto
// ---------------------------------------------------------------------------

$mqttHost = envValue('MJD_MQTT_HOST', 'mosquitto');
$mqttPort = (int)envValue('MJD_MQTT_PORT', '1883');
$forceMqtt = envValue('MJD_MQTT_FORCE_CONFIG', '0') === '1';

$module = $database->one("SELECT * FROM project_modules WHERE NAME='mqtt' LIMIT 1");
$config = array();
if ($module && !empty($module['DATA'])) {
    $decoded = @unserialize((string)$module['DATA'], array('allowed_classes' => false));
    if (is_array($decoded)) {
        $config = $decoded;
    }
}

$currentHost = (string)($config['MQTT_HOST'] ?? '');
$useDockerBroker = $forceMqtt
    || $currentHost === ''
    || $currentHost === 'localhost'
    || $currentHost === '127.0.0.1';

if ($useDockerBroker) {
    $config['MQTT_HOST'] = $mqttHost;
}
if ($forceMqtt || empty($config['MQTT_PORT']) || (int)$config['MQTT_PORT'] <= 0) {
    $config['MQTT_PORT'] = $mqttPort;
}
if (empty($config['MQTT_CLIENT'])) {
    $config['MQTT_CLIENT'] = 'MajorDoMo Docker';
}
if (empty($config['MQTT_QUERY'])) {
    $config['MQTT_QUERY'] = '/var/now/#';
}
if (!array_key_exists('MQTT_AUTH', $config)) {
    $config['MQTT_AUTH'] = 0;
}
if (!array_key_exists('MQTT_WRITE_METHOD', $config)) {
    $config['MQTT_WRITE_METHOD'] = 0;
}
if (!array_key_exists('MQTT_STRIPMODE', $config)) {
    $config['MQTT_STRIPMODE'] = 0;
}
if (!array_key_exists('DEBUG_MODE', $config)) {
    $config['DEBUG_MODE'] = 0;
}

$serialized = serialize($config);
if (!$module) {
    $record = array(
        'NAME' => 'mqtt',
        'TITLE' => 'MQTT',
        'CATEGORY' => '<#LANG_SECTION_DEVICES#>',
        'PARENT_NAME' => '',
        'DATA' => $serialized,
        'HIDDEN' => 0,
        'PRIORITY' => 0,
        'ADDED' => date('Y-m-d H:i:s'),
    );
    $database->insert('project_modules', $record);
    dockerOut('создана конфигурация MQTT: ' . $mqttHost . ':' . $mqttPort);
} elseif ((string)($module['DATA'] ?? '') !== $serialized) {
    $database->update('project_modules', array('ID' => (int)$module['ID'], 'DATA' => $serialized));
    dockerOut('обновлена конфигурация MQTT: ' . $config['MQTT_HOST'] . ':' . $config['MQTT_PORT']);
} else {
    dockerOut('конфигурация MQTT уже актуальна: ' . $config['MQTT_HOST'] . ':' . $config['MQTT_PORT']);
}

// ---------------------------------------------------------------------------
// Маркер установки: без него панель уводит на /install.php
// ---------------------------------------------------------------------------

$state = InstallState::state($root);
if (empty($state['complete'])) {
    $ok = InstallState::markComplete($root, array(
        'source' => 'docker',
        'project_title' => Config::string('PROJECT_TITLE', 'MajorDoMo Docker Debug'),
        'base_url' => Config::string('BASE_URL'),
        'db_name' => Config::string('DB_NAME'),
    ));
    dockerOut($ok ? 'мастер установки отмечен завершённым' : 'не удалось записать маркер установки');
}

dockerOut('готово: ' . $username . ' / пароль из MJD_ADMIN_PASSWORD, MQTT ' . $config['MQTT_HOST'] . ':' . $config['MQTT_PORT']);
exit(0);
