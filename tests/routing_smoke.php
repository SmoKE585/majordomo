<?php

declare(strict_types=1);

// 2026-10-23 — проверка AJAX-запросов маркета с параметрами и границ маршрутов.

/**
 * Routing contract tests.
 *
 * Validates the shared route table in lib/admin_router.php:
 *   - pretty path -> legacy query (inbound, used by nf.php);
 *   - legacy query -> pretty path (outbound, used by parseLinks/redirects);
 *   - lossless round-trip (a pretty URL restores the same legacy state).
 *
 * Also validates the nginx-only deployment contract (no .htaccess leftovers).
 *
 * Usage:
 *   php tests/routing_smoke.php
 */

const EXIT_OK = 0;
const EXIT_FAIL = 1;

define('ROOTHTML', '/');
define('DIR_MODULES', dirname(__DIR__) . '/modules/');

$rootDir = dirname(__DIR__);
$nginxConfigPath = $rootDir . DIRECTORY_SEPARATOR . 'docs/deploy/nginx.conf.sample';

require_once($rootDir . '/lib/admin_router.php');

if (!is_file($nginxConfigPath)) {
    fwrite(STDERR, "docs/deploy/nginx.conf.sample not found\n");
    exit(EXIT_FAIL);
}
$nginxConfig = file_get_contents($nginxConfigPath);
if ($nginxConfig === false) {
    fwrite(STDERR, "Failed to read docs/deploy/nginx.conf.sample\n");
    exit(EXIT_FAIL);
}

$failures = [];

/**
 * Resolve a pretty path and return its query array.
 *
 * @return array<string,mixed>|null
 */
function routingResolve(string $path): ?array
{
    $resolved = mdj_admin_resolve_path($path);
    return $resolved === null ? null : $resolved['query'];
}

/**
 * Assert a condition.
 *
 * @param bool   $condition
 * @param string $message
 */
function routingCheck(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures[] = $message;
    }
}

// --- Inbound: pretty path -> legacy query --------------------------------

$objectPath = '/panel/class/10/object/7/properties.html';
$objectQuery = routingResolve($objectPath);
routingCheck($objectQuery !== null, "[MISS] {$objectPath}");
if ($objectQuery !== null) {
    routingCheck(($objectQuery['md'] ?? '') === 'objects', "[MD] {$objectPath} expected objects");
    routingCheck((string)($objectQuery['id'] ?? '') === '7', "[ID] {$objectPath} expected 7");
    routingCheck(($objectQuery['tab'] ?? '') === 'properties', "[TAB] {$objectPath} expected properties");
    routingCheck(($objectQuery['view_mode'] ?? '') === 'edit_objects', "[VIEW] {$objectPath} expected edit_objects");
    $parents = mdj_admin_decode_pd((string)($objectQuery['pd'] ?? ''));
    $parentNames = array_map(static function (array $entry): string {
        return $entry['name'];
    }, $parents);
    routingCheck($parentNames === ['panel', 'classes'], "[PARENTS] {$objectPath} expected panel+classes, got " . implode(',', $parentNames));
}

$cases = [
    '/panel/' => ['module' => 'panel', 'params' => []],
    '/panel/classes.html' => ['module' => 'classes', 'params' => []],
    '/panel/class/10.html' => ['module' => 'classes', 'params' => ['id' => '10', 'view_mode' => 'edit_classes']],
    '/panel/class/10/objects.html' => ['module' => 'classes', 'params' => ['id' => '10', 'tab' => 'objects', 'view_mode' => 'edit_classes']],
    '/panel/class/10/properties.html' => ['module' => 'classes', 'params' => ['id' => '10', 'tab' => 'properties', 'view_mode' => 'edit_classes']],
    '/panel/class/10/methods/45.html' => ['module' => 'methods', 'params' => ['id' => '45', 'view_mode' => 'edit_methods']],
    '/panel/class/10/properties/18.html' => ['module' => 'properties', 'params' => ['id' => '18', 'view_mode' => 'edit_properties']],
    '/panel/class/10/object/7.html' => ['module' => 'objects', 'params' => ['id' => '7', 'view_mode' => 'edit_objects']],
    '/panel/class/10/object/7/methods/45.html' => ['module' => 'objects', 'params' => ['id' => '7', 'tab' => 'methods', 'method_id' => '45', 'overwrite' => '1', 'view_mode' => 'edit_objects']],
    '/panel/script/10.html' => ['module' => 'scripts', 'params' => ['id' => '10', 'view_mode' => 'edit_scripts']],
    '/panel/event/12.html' => ['module' => 'events', 'params' => ['id' => '12', 'view_mode' => 'edit_events']],
    '/panel/scripts.html' => ['module' => 'scripts', 'params' => []],
    '/panel/xray.html' => ['module' => 'xray', 'params' => []],
    '/panel/linkedobject.html' => ['module' => 'linkedobject', 'params' => []],
    '/panel/users.html' => ['module' => 'users', 'params' => []],
    '/panel/users/add.html' => ['module' => 'users', 'params' => ['view_mode' => 'edit_users']],
    '/panel/users/5.html' => ['module' => 'users', 'params' => ['id' => '5', 'view_mode' => 'edit_users']],
    '/panel/users/5/delete.html' => ['module' => 'users', 'params' => ['id' => '5', 'view_mode' => 'delete_users']],
    '/panel/pinghosts.html' => ['module' => 'pinghosts', 'params' => []],
    '/panel/pinghosts/add.html' => ['module' => 'pinghosts', 'params' => ['view_mode' => 'edit_pinghosts']],
    '/panel/pinghosts/5.html' => ['module' => 'pinghosts', 'params' => ['id' => '5', 'view_mode' => 'edit_pinghosts']],
    '/panel/pinghosts/5/delete.html' => ['module' => 'pinghosts', 'params' => ['id' => '5', 'view_mode' => 'delete_pinghosts']],
    '/panel/yandexhome.html' => ['module' => 'yandexhome', 'params' => []],
    '/panel/yandexhome/add.html' => ['module' => 'yandexhome', 'params' => ['view_mode' => 'edit_yandexhome_devices']],
    '/panel/yandexhome/5.html' => ['module' => 'yandexhome', 'params' => ['id' => '5', 'view_mode' => 'edit_yandexhome_devices']],
    '/panel/yandexhome/5/delete.html' => ['module' => 'yandexhome', 'params' => ['id' => '5', 'view_mode' => 'delete_yandexhome_devices']],
    '/panel/yandexhome/settings.html' => ['module' => 'yandexhome', 'params' => ['view_mode' => 'settings']],
    '/panel/yandexhome/about.html' => ['module' => 'yandexhome', 'params' => ['view_mode' => 'about']],
    '/panel/yadevices.html' => ['module' => 'yadevices', 'params' => []],
    '/panel/yadevices/devices.html' => ['module' => 'yadevices', 'params' => ['view_mode' => 'search_yadevices']],
    '/panel/yadevices/scenarios.html' => ['module' => 'yadevices', 'params' => ['view_mode' => 'search_scenarios']],
    '/panel/yadevices/settings.html' => ['module' => 'yadevices', 'params' => ['view_mode' => 'settings']],
    '/panel/yadevices/auth.html' => ['module' => 'yadevices', 'params' => ['view_mode' => 'auth']],
    '/panel/yadevices/about.html' => ['module' => 'yadevices', 'params' => ['view_mode' => 'about']],
    '/panel/yadevices/stations/5.html' => ['module' => 'yadevices', 'params' => ['id' => '5', 'view_mode' => 'edit_yastations']],
    '/panel/yadevices/stations/5/delete.html' => ['module' => 'yadevices', 'params' => ['id' => '5', 'view_mode' => 'delete_yastations']],
    '/panel/yadevices/devices/7.html' => ['module' => 'yadevices', 'params' => ['id' => '7', 'view_mode' => 'edit_yadevices']],
    '/panel/yadevices/devices/7/delete.html' => ['module' => 'yadevices', 'params' => ['id' => '7', 'view_mode' => 'delete_yadevice']],
    '/panel/locations.html' => ['module' => 'locations', 'params' => []],
    '/panel/locations/add.html' => ['module' => 'locations', 'params' => ['view_mode' => 'edit_locations']],
    '/panel/locations/5.html' => ['module' => 'locations', 'params' => ['id' => '5', 'view_mode' => 'edit_locations']],
    '/panel/locations/5/logic.html' => ['module' => 'locations', 'params' => ['id' => '5', 'tab' => 'logic', 'view_mode' => 'edit_locations']],
    '/panel/locations/5/up.html' => ['module' => 'locations', 'params' => ['id' => '5', 'view_mode' => 'priority_up']],
    '/panel/locations/5/down.html' => ['module' => 'locations', 'params' => ['id' => '5', 'view_mode' => 'priority_down']],
    '/panel/locations/5/delete.html' => ['module' => 'locations', 'params' => ['id' => '5', 'view_mode' => 'delete_locations']],
    '/panel/system_errors.html' => ['module' => 'system_errors', 'params' => []],
    '/panel/system_errors/10.html' => ['module' => 'system_errors', 'params' => ['id' => '10', 'view_mode' => 'edit_system_errors']],
    '/panel/system_errors/10/history.html' => ['module' => 'system_errors', 'params' => ['id' => '10', 'tab' => 'history', 'view_mode' => 'edit_system_errors']],
    '/panel/system_errors/10/read.html' => ['module' => 'system_errors', 'params' => ['id' => '10', 'view_mode' => 'clear_system_errors']],
    '/panel/system_errors/10/delete.html' => ['module' => 'system_errors', 'params' => ['id' => '10', 'view_mode' => 'delete_system_errors']],
    '/panel/system_errors/readall.html' => ['module' => 'system_errors', 'params' => ['view_mode' => 'readall']],
    '/panel/system_errors/test.html' => ['module' => 'system_errors', 'params' => ['view_mode' => 'addtesterror']],
    '/panel/mqtt.html' => ['module' => 'mqtt', 'params' => []],
    '/panel/mqtt/10.html' => ['module' => 'mqtt', 'params' => ['id' => '10', 'view_mode' => 'edit_mqtt']],
    '/panel/mqtt/add.html' => ['module' => 'mqtt', 'params' => ['view_mode' => 'edit_mqtt']],
    '/panel/mqtt/10/delete.html' => ['module' => 'mqtt', 'params' => ['id' => '10', 'view_mode' => 'delete_mqtt']],
    '/panel/mqtt/settings.html' => ['module' => 'mqtt', 'params' => ['view_mode' => 'settings']],
    '/panel/mqtt/clear-trash.html' => ['module' => 'mqtt', 'params' => ['view_mode' => 'clear_trash']],
    '/panel/mqtt/optimize.html' => ['module' => 'mqtt', 'params' => ['view_mode' => 'optimize']],
    '/panel/knowledge_base.html' => ['module' => 'knowledge_base', 'params' => []],
    '/panel/knowledge_base/user/commands.html' => ['module' => 'knowledge_base', 'params' => ['view_mode' => 'view_doc', 'doc' => 'user/commands']],
    '/panel/knowledge_base/module/yandexhome/setup.html' => ['module' => 'knowledge_base', 'params' => ['view_mode' => 'view_doc', 'doc' => 'module/yandexhome/setup']],
    '/panel/ai_assistant.html' => ['module' => 'ai_assistant', 'params' => []],
    '/panel/ai_assistant/chat/5.html' => ['module' => 'ai_assistant', 'params' => ['view_mode' => 'chat', 'dialog_id' => '5']],
    '/panel/ai_assistant/agents.html' => ['module' => 'ai_assistant', 'params' => ['view_mode' => 'agents']],
    '/panel/ai_assistant/agents/add.html' => ['module' => 'ai_assistant', 'params' => ['view_mode' => 'agent_edit']],
    '/panel/ai_assistant/agents/3.html' => ['module' => 'ai_assistant', 'params' => ['view_mode' => 'agent_edit', 'id' => '3']],
    '/panel/ai_assistant/models/7.html' => ['module' => 'ai_assistant', 'params' => ['view_mode' => 'model_edit', 'id' => '7']],
    '/panel/ai_assistant/providers/add.html' => ['module' => 'ai_assistant', 'params' => ['view_mode' => 'provider_edit']],
    '/panel/ai_assistant/knowledge.html' => ['module' => 'ai_assistant', 'params' => ['view_mode' => 'knowledge']],
    '/panel/ai_assistant/diagnostics.html' => ['module' => 'ai_assistant', 'params' => ['view_mode' => 'diagnostics']],
];

foreach ($cases as $path => $expected) {
    $query = routingResolve($path);
    routingCheck($query !== null, "[MISS] {$path}");
    if ($query === null) {
        continue;
    }
    $state = mdj_admin_state_from_query($query);
    routingCheck($state['module'] === $expected['module'], "[MODULE] {$path} expected {$expected['module']}, got {$state['module']}");
    $normalized = mdj_admin_normalize_params($state['module'], $state['params']);
    $expectedNormalized = mdj_admin_normalize_params($expected['module'], $expected['params']);
    routingCheck($normalized === $expectedNormalized, "[PARAMS] {$path} expected " . json_encode($expectedNormalized) . ', got ' . json_encode($normalized));
}

// Unknown *.html must not fall through to a fake "docs" page any more.
routingCheck(mdj_admin_resolve_path('/nonexistent.html') === null, '[404] /nonexistent.html must not match any route');

// Полный REQUEST_URI, как в nf.php: параметры не должны превращать AJAX в 404.
$ajaxRequests = [
    '/ajax/market.html',
    '/ajax/market.html?op=list&category_id=installed&search=',
    '/ajax/market.html?op=list&category_id=all&search=zigbee%20mqtt',
    '/ajax/market.html?op=commits&name=mqtt',
    '/ajax/market.html?op=check_updates',
];
foreach ($ajaxRequests as $uri) {
    routingCheck(
        routingResolve($uri) === ['action' => 'market', 'ajax' => '1'],
        '[AJAX] market request must reach the existing module: ' . $uri
    );
}
routingCheck(
    routingResolve('/getlatestnote.html?_=123') === ['action' => 'getlatestnote'],
    '[AJAX] latest note accepts cache-busting params'
);
foreach (['/ajax/market.html/extra', '/ajax/market.html.bak', '/getlatestnote.html/extra'] as $uri) {
    routingCheck(mdj_admin_resolve_path($uri) === null, '[404] invalid AJAX suffix: ' . $uri);
}

// --- Outbound: legacy query -> pretty path --------------------------------

$legacyObject = '/admin.php?pd=Y2xhc3Nlczp7aWQ9MTAsdmlld19tb2RlPWVkaXRfY2xhc3Nlcyx0YWI9b2JqZWN0cyxpbnN0YW5jZT1hZG19pz_cGFuZWw6e2FjdGlvbj1jbGFzc2VzfQ%3D%3Dpz_&md=objects&inst=&data_source=&view_mode=edit_objects&id=7&tab=properties';
$prettyObject = mdj_admin_pretty_url($legacyObject);
routingCheck($prettyObject === $objectPath, "[PRETTY] object URL expected {$objectPath}, got {$prettyObject}");

$legacyClassObjects = '/admin.php?pd=cGFuZWw6e2FjdGlvbj1jbGFzc2VzfQ%3D%3Dpz_&md=classes&inst=adm&view_mode=edit_classes&id=10&tab=objects';
routingCheck(
    mdj_admin_pretty_url($legacyClassObjects) === '/panel/class/10/objects.html',
    '[PRETTY] class objects tab'
);

$sidebarXray = '/admin.php?pd=cGFuZWw6e2FjdGlvbj1jbGFzc2VzfQ%3D%3Dpz_&md=panel&inst=&action=xray';
routingCheck(mdj_admin_pretty_url($sidebarXray) === '/panel/xray.html', '[PRETTY] sidebar xray');

routingCheck(
    mdj_admin_pretty_url('/admin.php?action=linkedobject') === '/panel/linkedobject.html',
    '[PRETTY] linkedobject component page'
);

routingCheck(mdj_admin_pretty_url('/admin.php') === '/panel/', '[PRETTY] dashboard');
routingCheck(mdj_admin_pretty_url('/admin.php?action=settings') === '/panel/settings.html', '[PRETTY] settings');
routingCheck(
    mdj_admin_pretty_url('/admin.php?md=scripts&inst=adm&view_mode=edit_scripts&id=10') === '/panel/script/10.html',
    '[PRETTY] script item'
);
routingCheck(
    mdj_admin_pretty_url('/admin.php?md=scripts&inst=adm&view_mode=multiple&ids%5B%5D=22') === '/panel/scripts.html?ids%5B0%5D=22&view_mode=multiple',
    '[PRETTY] bulk scripts keep array params'
);
routingCheck(
    mdj_admin_pretty_url('/admin.php?action=users') === '/panel/users.html',
    '[PRETTY] users list'
);
routingCheck(
    mdj_admin_pretty_url('/admin.php?action=users&view_mode=edit_users') === '/panel/users/add.html',
    '[PRETTY] user add'
);
routingCheck(
    mdj_admin_pretty_url('/admin.php?action=users&view_mode=edit_users&id=5') === '/panel/users/5.html',
    '[PRETTY] user edit'
);
routingCheck(
    mdj_admin_pretty_url('/admin.php?action=users&view_mode=delete_users&id=5') === '/panel/users/5/delete.html',
    '[PRETTY] user delete'
);
routingCheck(
    mdj_admin_pretty_url('/admin.php?action=pinghosts') === '/panel/pinghosts.html',
    '[PRETTY] pinghosts list'
);
routingCheck(
    mdj_admin_pretty_url('/admin.php?action=pinghosts&view_mode=edit_pinghosts') === '/panel/pinghosts/add.html',
    '[PRETTY] pinghosts add'
);
routingCheck(
    mdj_admin_pretty_url('/admin.php?action=pinghosts&view_mode=edit_pinghosts&id=5') === '/panel/pinghosts/5.html',
    '[PRETTY] pinghosts edit'
);
routingCheck(
    mdj_admin_pretty_url('/admin.php?action=pinghosts&view_mode=delete_pinghosts&id=5') === '/panel/pinghosts/5/delete.html',
    '[PRETTY] pinghosts delete'
);
routingCheck(
    mdj_admin_pretty_url('/admin.php?action=yandexhome') === '/panel/yandexhome.html',
    '[PRETTY] yandexhome list'
);
routingCheck(
    mdj_admin_pretty_url('/admin.php?action=yandexhome&view_mode=edit_yandexhome_devices') === '/panel/yandexhome/add.html',
    '[PRETTY] yandexhome add'
);
routingCheck(
    mdj_admin_pretty_url('/admin.php?action=yandexhome&view_mode=addnew_yandexhome_devices') === '/panel/yandexhome/add.html',
    '[PRETTY] yandexhome legacy add'
);
routingCheck(
    mdj_admin_pretty_url('/admin.php?action=yandexhome&view_mode=edit_yandexhome_devices&id=5') === '/panel/yandexhome/5.html',
    '[PRETTY] yandexhome edit'
);
routingCheck(
    mdj_admin_pretty_url('/admin.php?action=yandexhome&view_mode=delete_yandexhome_devices&id=5') === '/panel/yandexhome/5/delete.html',
    '[PRETTY] yandexhome delete'
);
routingCheck(
    mdj_admin_pretty_url('/admin.php?action=yandexhome&view_mode=settings') === '/panel/yandexhome/settings.html',
    '[PRETTY] yandexhome settings'
);
routingCheck(
    mdj_admin_pretty_url('/admin.php?action=yandexhome&view_mode=about') === '/panel/yandexhome/about.html',
    '[PRETTY] yandexhome about'
);
routingCheck(
    mdj_admin_pretty_url('/admin.php?action=yadevices') === '/panel/yadevices.html',
    '[PRETTY] yadevices list'
);
routingCheck(
    mdj_admin_pretty_url('/admin.php?action=yadevices&view_mode=search_yadevices') === '/panel/yadevices/devices.html',
    '[PRETTY] yadevices devices'
);
routingCheck(
    mdj_admin_pretty_url('/admin.php?action=yadevices&view_mode=search_scenarios') === '/panel/yadevices/scenarios.html',
    '[PRETTY] yadevices scenarios'
);
routingCheck(
    mdj_admin_pretty_url('/admin.php?action=yadevices&view_mode=settings') === '/panel/yadevices/settings.html',
    '[PRETTY] yadevices settings'
);
routingCheck(
    mdj_admin_pretty_url('/admin.php?action=yadevices&view_mode=auth') === '/panel/yadevices/auth.html',
    '[PRETTY] yadevices auth'
);
routingCheck(
    mdj_admin_pretty_url('/admin.php?action=yadevices&view_mode=about') === '/panel/yadevices/about.html',
    '[PRETTY] yadevices about'
);
routingCheck(
    mdj_admin_pretty_url('/admin.php?action=yadevices&view_mode=edit_yastations&id=5') === '/panel/yadevices/stations/5.html',
    '[PRETTY] yadevices station edit'
);
routingCheck(
    mdj_admin_pretty_url('/admin.php?action=yadevices&view_mode=delete_yastations&id=5') === '/panel/yadevices/stations/5/delete.html',
    '[PRETTY] yadevices station delete'
);
routingCheck(
    mdj_admin_pretty_url('/admin.php?action=yadevices&view_mode=edit_yadevices&id=7') === '/panel/yadevices/devices/7.html',
    '[PRETTY] yadevices device edit'
);
routingCheck(
    mdj_admin_pretty_url('/admin.php?action=yadevices&view_mode=delete_yadevice&id=7') === '/panel/yadevices/devices/7/delete.html',
    '[PRETTY] yadevices device delete'
);
routingCheck(
    mdj_admin_pretty_url('/admin.php?action=locations') === '/panel/locations.html',
    '[PRETTY] locations list'
);
routingCheck(
    mdj_admin_pretty_url('/admin.php?action=locations&view_mode=edit_locations') === '/panel/locations/add.html',
    '[PRETTY] location add'
);
routingCheck(
    mdj_admin_pretty_url('/admin.php?action=locations&view_mode=edit_locations&id=5') === '/panel/locations/5.html',
    '[PRETTY] location edit'
);
routingCheck(
    mdj_admin_pretty_url('/admin.php?action=locations&view_mode=edit_locations&id=5&tab=logic') === '/panel/locations/5/logic.html',
    '[PRETTY] location logic'
);
routingCheck(
    mdj_admin_pretty_url('/admin.php?action=locations&view_mode=priority_up&id=5') === '/panel/locations/5/up.html',
    '[PRETTY] location priority up'
);
routingCheck(
    mdj_admin_pretty_url('/admin.php?action=locations&view_mode=priority_down&id=5') === '/panel/locations/5/down.html',
    '[PRETTY] location priority down'
);
routingCheck(
    mdj_admin_pretty_url('/admin.php?action=locations&view_mode=delete_locations&id=5') === '/panel/locations/5/delete.html',
    '[PRETTY] location delete'
);
routingCheck(
    mdj_admin_pretty_url('/admin.php?action=system_errors') === '/panel/system_errors.html',
    '[PRETTY] system errors list'
);
routingCheck(
    mdj_admin_pretty_url('/admin.php?action=system_errors&view_mode=edit_system_errors&id=10') === '/panel/system_errors/10.html',
    '[PRETTY] system error edit'
);
routingCheck(
    mdj_admin_pretty_url('/admin.php?action=system_errors&view_mode=edit_system_errors&id=10&tab=history') === '/panel/system_errors/10/history.html',
    '[PRETTY] system error history'
);
routingCheck(
    mdj_admin_pretty_url('/admin.php?action=system_errors&view_mode=clear_system_errors&id=10') === '/panel/system_errors/10/read.html',
    '[PRETTY] system error read'
);
routingCheck(
    mdj_admin_pretty_url('/admin.php?action=system_errors&view_mode=delete_system_errors&id=10') === '/panel/system_errors/10/delete.html',
    '[PRETTY] system error delete'
);
routingCheck(
    mdj_admin_pretty_url('/admin.php?action=system_errors&view_mode=readall') === '/panel/system_errors/readall.html',
    '[PRETTY] system errors read all'
);
routingCheck(
    mdj_admin_pretty_url('/admin.php?action=system_errors&view_mode=addtesterror') === '/panel/system_errors/test.html',
    '[PRETTY] system errors test'
);
routingCheck(
    mdj_admin_pretty_url('/admin.php?action=mqtt') === '/panel/mqtt.html',
    '[PRETTY] mqtt list'
);
routingCheck(
    mdj_admin_pretty_url('/admin.php?action=mqtt&view_mode=edit_mqtt&id=10') === '/panel/mqtt/10.html',
    '[PRETTY] mqtt edit'
);
routingCheck(
    mdj_admin_pretty_url('/admin.php?action=mqtt&view_mode=edit_mqtt') === '/panel/mqtt/add.html',
    '[PRETTY] mqtt add'
);
routingCheck(
    mdj_admin_pretty_url('/admin.php?action=mqtt&view_mode=delete_mqtt&id=10') === '/panel/mqtt/10/delete.html',
    '[PRETTY] mqtt delete'
);
routingCheck(
    mdj_admin_pretty_url('/admin.php?action=mqtt&view_mode=settings') === '/panel/mqtt/settings.html',
    '[PRETTY] mqtt settings'
);
routingCheck(
    mdj_admin_pretty_url('/admin.php?action=mqtt&view_mode=clear_trash') === '/panel/mqtt/clear-trash.html',
    '[PRETTY] mqtt clear trash'
);
routingCheck(
    mdj_admin_pretty_url('/admin.php?action=mqtt&view_mode=optimize') === '/panel/mqtt/optimize.html',
    '[PRETTY] mqtt optimize'
);
routingCheck(
    mdj_admin_pretty_url('/admin.php?action=knowledge_base') === '/panel/knowledge_base.html',
    '[PRETTY] knowledge base list'
);
routingCheck(
    mdj_admin_pretty_url('/admin.php?action=knowledge_base&cat=user') === '/panel/knowledge_base.html?cat=user',
    '[PRETTY] knowledge base category'
);
routingCheck(
    mdj_admin_pretty_url('/admin.php?action=knowledge_base&view_mode=view_doc&doc=user%2Fcommands') === '/panel/knowledge_base/user/commands.html',
    '[PRETTY] knowledge base document'
);

// Unsupported state must stay on the legacy URL.
$noParentObject = '/admin.php?md=objects&view_mode=edit_objects&id=7';
routingCheck(mdj_admin_pretty_url($noParentObject) === $noParentObject, '[FALLBACK] object without class context must stay legacy');
$printUrl = '/admin.php?pd=cGFuZWw6e2FjdGlvbj1jbGFzc2VzfQ%3D%3Dpz_&md=classes&view_mode=edit_classes&id=10&print=1';
routingCheck(mdj_admin_pretty_url($printUrl) === $printUrl, '[FALLBACK] print URL must stay legacy');

// Every pretty URL we emit must resolve back to the same state.
foreach (array($objectPath, '/panel/class/10/objects.html', '/panel/class/10/methods/45.html', '/panel/script/10.html', '/panel/users/5.html', '/panel/users/add.html', '/panel/users/5/delete.html', '/panel/pinghosts/5.html', '/panel/pinghosts/add.html', '/panel/pinghosts/5/delete.html', '/panel/yandexhome.html', '/panel/yandexhome/5.html', '/panel/yandexhome/add.html', '/panel/yandexhome/5/delete.html', '/panel/yandexhome/settings.html', '/panel/yandexhome/about.html', '/panel/yadevices.html', '/panel/yadevices/devices.html', '/panel/yadevices/scenarios.html', '/panel/yadevices/settings.html', '/panel/yadevices/auth.html', '/panel/yadevices/about.html', '/panel/yadevices/stations/5.html', '/panel/yadevices/stations/5/delete.html', '/panel/yadevices/devices/7.html', '/panel/yadevices/devices/7/delete.html', '/panel/locations/5.html', '/panel/locations/5/logic.html', '/panel/locations/add.html', '/panel/locations/5/up.html', '/panel/system_errors/10.html', '/panel/system_errors/10/history.html', '/panel/system_errors/readall.html', '/panel/system_errors/test.html', '/panel/mqtt/10.html', '/panel/mqtt/add.html', '/panel/mqtt/10/delete.html', '/panel/mqtt/settings.html', '/panel/mqtt/clear-trash.html', '/panel/mqtt/optimize.html', '/panel/knowledge_base.html', '/panel/knowledge_base/user/commands.html', '/panel/knowledge_base/module/yandexhome/setup.html') as $pretty) {
    $query = routingResolve($pretty);
    routingCheck($query !== null, "[ROUNDTRIP] {$pretty} did not resolve");
    if ($query !== null) {
        $state = mdj_admin_state_from_query($query);
        routingCheck($state['module'] !== '', "[ROUNDTRIP] {$pretty} has no module");
    }
}

// --- nginx / deployment contract -----------------------------------------

$nginxChecks = [
    'location = /install {' => 'setup wizard alias',
    'return 302 /install.php;' => 'setup wizard alias target',
    'location = /panel {' => 'admin root redirect',
    'return 301 /panel/;' => 'admin root redirect target',
    'location ^~ /panel/ {' => 'admin pretty URL location',
    'try_files $uri $uri/ /nf.php?$query_string;' => 'nf.php fallback',
    'error_page 404 /404.php;' => 'styled 404 error_page',
    'root /var/www/example/data/www/example.com/public;' => 'public/ is the document root',
    'location ^~ /assets/' => 'static assets location',
    'location ~ ^/public/(.*)$' => 'old docroot prefix redirect',
    'return 301 /assets/$1/$2$is_args$args;' => 'legacy css/js/img redirect',
    '/assets/modules/$1/$2$is_args$args;' => 'module page css/js redirect',
    'location = /rc {' => 'removed rc redirect',
    'location ^~ /modules/ {' => 'module endpoints location',
    'location ~ ^/(scripts|objects|3rdparty|packages|vendor)(/|$) {' => 'private system paths',
    'location ^~ /cms/ {' => 'cms media location',
    'location ^~ /docs/ {' => 'documentation location',
    'location ~ ^/cms/(cached|debmes|modules_installed|saverestore)/ {' => 'cms runtime data is private',
    'location = /ws {' => 'same-origin WebSocket path',
    'proxy_pass http://127.0.0.1:8001/majordomo;' => 'loopback WebSocket upstream',
    'proxy_set_header X-Forwarded-For $remote_addr;' => 'WebSocket proxy replaces client forwarding headers',
    'set $md_path_info $fastcgi_path_info;' => 'PATH_INFO saved before try_files',
    'try_files $fastcgi_script_name =404;' => 'only existing PHP scripts reach FastCGI',
    'fastcgi_param PATH_INFO $md_path_info;' => 'saved PATH_INFO reaches PHP',
];

foreach ($nginxChecks as $needle => $name) {
    if (!str_contains($nginxConfig, $needle)) {
        $failures[] = "[NGINX] missing: {$name}";
    }
}

// --- Static layout contract ----------------------------------------------

routingCheck(is_dir($rootDir . '/public/assets'), '[LAYOUT] public/assets must exist');
foreach (['css', 'js', 'img'] as $legacyDir) {
    routingCheck(
        !is_dir($rootDir . '/' . $legacyDir),
        "[LAYOUT] legacy /{$legacyDir} must be moved to public/assets/{$legacyDir}"
    );
}
routingCheck(
    !is_dir($rootDir . '/3rdparty/bootstrap'),
    '[LAYOUT] 3rdparty/bootstrap must be moved to public/assets/vendor/bootstrap'
);
routingCheck(!is_dir($rootDir . '/3rdparty'), '[LAYOUT] PHP libraries are managed by Composer');
routingCheck(!is_dir($rootDir . '/objects'), '[LAYOUT] old object HTTP entry is removed');
routingCheck(is_file($rootDir . '/scripts/core_worker.php'), '[LAYOUT] internal worker is preserved');
routingCheck(is_file($rootDir . '/public/assets/fonts/rubik/rubik.css'), '[LAYOUT] fonts live in assets');
foreach (array('objects', 'scripts', '3rdparty') as $directory) {
    routingCheck(!str_contains($nginxConfig, 'location ^~ /' . $directory . '/'), '[NGINX] no root mount for ' . $directory);
}

// --- public docroot contract ---------------------------------------------

foreach (['index.php', 'admin.php', 'nf.php', 'api.php', 'install.php', '404.php'] as $entry) {
    routingCheck(
        is_file($rootDir . '/public/' . $entry),
        "[PUBLIC] /{$entry} must live in public/"
    );
    routingCheck(
        !is_file($rootDir . '/' . $entry),
        "[PUBLIC] /{$entry} must not stay in the project root"
    );
}
routingCheck(is_file($rootDir . '/bootstrap.php'), '[PUBLIC] bootstrap.php must define the application root');
routingCheck(is_file($rootDir . '/public/assets/install/install.css'), '[SETUP] page CSS must live in public/assets/install');
routingCheck(is_file($rootDir . '/public/assets/install/install.js'), '[SETUP] page JS must live in public/assets/install');
routingCheck(is_file($rootDir . '/templates/install/install.php'), '[SETUP] wizard template must exist');
routingCheck(!is_dir($rootDir . '/rc'), '[PUBLIC] rc/ is removed (redirected to /panel/)');
routingCheck(!is_dir($rootDir . '/pChart'), '[PUBLIC] pChart/ is removed (unused chart library)');

foreach (findHtaccessLeftovers($rootDir) as $htaccess) {
    $failures[] = "[LEGACY] {$htaccess} — web server config lives in docs/deploy/nginx.conf.sample only";
}

if ($failures) {
    fwrite(STDERR, "Routing smoke tests FAILED:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, " - {$failure}\n");
    }
    exit(EXIT_FAIL);
}

fwrite(STDOUT, "Routing smoke tests passed (" . count($cases) . " route cases + " . count($nginxChecks) . " nginx checks)\n");
exit(EXIT_OK);

/**
 * @return array<int,string> Paths relative to the project root.
 */
function findHtaccessLeftovers(string $rootDir): array
{
    $skipDirs = ['.git', 'node_modules'];
    $found = [];

    $directory = new RecursiveDirectoryIterator($rootDir, FilesystemIterator::SKIP_DOTS);
    $filter = new RecursiveCallbackFilterIterator(
        $directory,
        static function (SplFileInfo $file) use ($skipDirs): bool {
            return !($file->isDir() && in_array($file->getFilename(), $skipDirs, true));
        }
    );

    foreach (new RecursiveIteratorIterator($filter) as $file) {
        if ($file->isFile() && $file->getFilename() === '.htaccess') {
            $found[] = substr($file->getPathname(), strlen($rootDir) + 1);
        }
    }

    sort($found);

    return $found;
}
