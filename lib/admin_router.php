<?php

/**
 * MajorDoMo admin routing helpers.
 *
 * The legacy admin keeps the whole navigation state in `pd` (base64 encoded
 * module states) plus `md`/`inst` and module params. That format is opaque and
 * produces unusable URLs, but old modules and bookmarks still depend on it.
 *
 * This file adds a thin bidirectional layer on top of the legacy contract:
 *
 *   legacy query  ->  prettified path  (used when rendering links)
 *   pretty path   ->  legacy query     (used by nf.php when a pretty URL is opened)
 *
 * A pretty URL is only produced when a round-trip check proves that the legacy
 * state is preserved. Otherwise the original legacy URL is returned untouched,
 * so unported modules keep working exactly as before.
 *
 * Pretty URL scheme (all admin paths live under `/panel/`):
 *
 *   /panel/                                      dashboard
 *   /panel/classes.html                          module list
 *   /panel/scripts/12.html                       generic module item
 *   /panel/script/12.html                        scripts item
 *   /panel/event/12.html                         events item
 *   /panel/class/10.html                         class card
 *   /panel/class/10/properties.html              class tab
 *   /panel/class/10/properties/18.html           class property edit
 *   /panel/class/10/methods/45.html              class method edit
 *   /panel/class/10/object/7.html                object card
 *   /panel/class/10/object/7/properties.html     object tab
 *   /panel/class/10/object/7/methods/45.html     object method edit
 *
 * @package MajorDoMo
 */

/**
 * Route table shared by nf.php (inbound) and the link prettifier (outbound).
 *
 * Targets use the legacy nf.php syntax:
 *   ?(module:{k=v,...})&md=module&...   parent module state + current params
 *
 * Important: the generic routes must stay after the specific ones.
 *
 * @param string|null $rootHtml Project root, usually ROOTHTML ("/").
 * @return array<string,string> regex => legacy target
 */
function mdj_admin_route_table($rootHtml = null)
{
    if ($rootHtml === null) {
        $rootHtml = defined('ROOTHTML') ? ROOTHTML : '/';
    }
    $root = preg_quote((string)$rootHtml, '/');

    return array(
        // --- Specific admin pages -----------------------------------------
        '/^' . $root . 'panel\/modes\/add\.html/is'
            => '?(panel:{action=modes})&md=modes&view_mode=edit_modes',
        '/^' . $root . 'panel\/modes\/(\d+)\.html/is'
            => '?(panel:{action=modes})&md=modes&view_mode=edit_modes&id=\1',
        '/^' . $root . 'panel\/event\/(\d+)\/delete\.html/is'
            => '?(panel:{action=events})&md=events&view_mode=delete_events&id=\1',
        '/^' . $root . 'panel\/event\/(\d+)\.html/is'
            => '?(panel:{action=events})&md=events&view_mode=edit_events&id=\1',
        '/^' . $root . 'panel\/script\/(\d+)\.html/is'
            => '?(panel:{action=scripts})&md=scripts&view_mode=edit_scripts&id=\1',
        '/^' . $root . 'panel\/xray\.html/is'
            => '?(panel:{action=xray})&md=xray',
        '/^' . $root . 'panel\/linkedobject\.html/is'
            => '?(panel:{action=linkedobject})&md=linkedobject',
        '/^' . $root . 'panel\/users\/add\.html/is'
            => '?(panel:{action=users})&md=users&view_mode=edit_users',
        '/^' . $root . 'panel\/users\/(\d+)\/delete\.html/is'
            => '?(panel:{action=users})&md=users&view_mode=delete_users&id=\1',
        '/^' . $root . 'panel\/users\/(\d+)\.html/is'
            => '?(panel:{action=users})&md=users&view_mode=edit_users&id=\1',
        '/^' . $root . 'panel\/pinghosts\/add\.html/is'
            => '?(panel:{action=pinghosts})&md=pinghosts&view_mode=edit_pinghosts',
        '/^' . $root . 'panel\/pinghosts\/(\d+)\/delete\.html/is'
            => '?(panel:{action=pinghosts})&md=pinghosts&view_mode=delete_pinghosts&id=\1',
        '/^' . $root . 'panel\/pinghosts\/(\d+)\.html/is'
            => '?(panel:{action=pinghosts})&md=pinghosts&view_mode=edit_pinghosts&id=\1',
        '/^' . $root . 'panel\/yandexhome\/settings\.html/is'
            => '?(panel:{action=yandexhome})&md=yandexhome&view_mode=settings',
        '/^' . $root . 'panel\/yandexhome\/about\.html/is'
            => '?(panel:{action=yandexhome})&md=yandexhome&view_mode=about',
        '/^' . $root . 'panel\/yandexhome\/add\.html/is'
            => '?(panel:{action=yandexhome})&md=yandexhome&view_mode=edit_yandexhome_devices',
        '/^' . $root . 'panel\/yandexhome\/(\d+)\/delete\.html/is'
            => '?(panel:{action=yandexhome})&md=yandexhome&view_mode=delete_yandexhome_devices&id=\1',
        '/^' . $root . 'panel\/yandexhome\/(\d+)\.html/is'
            => '?(panel:{action=yandexhome})&md=yandexhome&view_mode=edit_yandexhome_devices&id=\1',
        '/^' . $root . 'panel\/yadevices\/settings\.html/is'
            => '?(panel:{action=yadevices})&md=yadevices&view_mode=settings',
        '/^' . $root . 'panel\/yadevices\/about\.html/is'
            => '?(panel:{action=yadevices})&md=yadevices&view_mode=about',
        '/^' . $root . 'panel\/yadevices\/auth\.html/is'
            => '?(panel:{action=yadevices})&md=yadevices&view_mode=auth',
        '/^' . $root . 'panel\/yadevices\/devices\.html/is'
            => '?(panel:{action=yadevices})&md=yadevices&view_mode=search_yadevices',
        '/^' . $root . 'panel\/yadevices\/scenarios\.html/is'
            => '?(panel:{action=yadevices})&md=yadevices&view_mode=search_scenarios',
        '/^' . $root . 'panel\/yadevices\/stations\/(\d+)\/delete\.html/is'
            => '?(panel:{action=yadevices})&md=yadevices&view_mode=delete_yastations&id=\1',
        '/^' . $root . 'panel\/yadevices\/stations\/(\d+)\.html/is'
            => '?(panel:{action=yadevices})&md=yadevices&view_mode=edit_yastations&id=\1',
        '/^' . $root . 'panel\/yadevices\/devices\/(\d+)\/delete\.html/is'
            => '?(panel:{action=yadevices})&md=yadevices&view_mode=delete_yadevice&id=\1',
        '/^' . $root . 'panel\/yadevices\/devices\/(\d+)\.html/is'
            => '?(panel:{action=yadevices})&md=yadevices&view_mode=edit_yadevices&id=\1',
        '/^' . $root . 'panel\/yadevices\/(\d+)\.html/is'
            => '?(panel:{action=yadevices})&md=yadevices&view_mode=edit_yastations&id=\1',
        '/^' . $root . 'panel\/locations\/add\.html/is'
            => '?(panel:{action=locations})&md=locations&view_mode=edit_locations',
        '/^' . $root . 'panel\/locations\/(\d+)\/delete\.html/is'
            => '?(panel:{action=locations})&md=locations&view_mode=delete_locations&id=\1',
        '/^' . $root . 'panel\/locations\/(\d+)\/up\.html/is'
            => '?(panel:{action=locations})&md=locations&view_mode=priority_up&id=\1',
        '/^' . $root . 'panel\/locations\/(\d+)\/down\.html/is'
            => '?(panel:{action=locations})&md=locations&view_mode=priority_down&id=\1',
        '/^' . $root . 'panel\/locations\/(\d+)\/logic\.html/is'
            => '?(panel:{action=locations})&md=locations&view_mode=edit_locations&id=\1&tab=logic',
        '/^' . $root . 'panel\/locations\/(\d+)\.html/is'
            => '?(panel:{action=locations})&md=locations&view_mode=edit_locations&id=\1',
        '/^' . $root . 'panel\/system_errors\/readall\.html/is'
            => '?(panel:{action=system_errors})&md=system_errors&view_mode=readall',
        '/^' . $root . 'panel\/system_errors\/test\.html/is'
            => '?(panel:{action=system_errors})&md=system_errors&view_mode=addtesterror',
        '/^' . $root . 'panel\/system_errors\/(\d+)\/history\.html/is'
            => '?(panel:{action=system_errors})&md=system_errors&view_mode=edit_system_errors&id=\1&tab=history',
        '/^' . $root . 'panel\/system_errors\/(\d+)\/read\.html/is'
            => '?(panel:{action=system_errors})&md=system_errors&view_mode=clear_system_errors&id=\1',
        '/^' . $root . 'panel\/system_errors\/(\d+)\/delete\.html/is'
            => '?(panel:{action=system_errors})&md=system_errors&view_mode=delete_system_errors&id=\1',
        '/^' . $root . 'panel\/system_errors\/(\d+)\.html/is'
            => '?(panel:{action=system_errors})&md=system_errors&view_mode=edit_system_errors&id=\1',
        '/^' . $root . 'panel\/knowledge_base\/([\w.-]+(?:\/[\w.-]+)*)\.html/is'
            => '?(panel:{action=knowledge_base})&md=knowledge_base&view_mode=view_doc&doc=\1',
        '/^' . $root . 'panel\/mqtt\/settings\.html/is'
            => '?(panel:{action=mqtt})&md=mqtt&view_mode=settings',
        '/^' . $root . 'panel\/mqtt\/add\.html/is'
            => '?(panel:{action=mqtt})&md=mqtt&view_mode=edit_mqtt',
        '/^' . $root . 'panel\/mqtt\/clear-trash\.html/is'
            => '?(panel:{action=mqtt})&md=mqtt&view_mode=clear_trash',
        '/^' . $root . 'panel\/mqtt\/optimize\.html/is'
            => '?(panel:{action=mqtt})&md=mqtt&view_mode=optimize',
        '/^' . $root . 'panel\/mqtt\/(\d+)\/delete\.html/is'
            => '?(panel:{action=mqtt})&md=mqtt&view_mode=delete_mqtt&id=\1',
        '/^' . $root . 'panel\/mqtt\/(\d+)\.html/is'
            => '?(panel:{action=mqtt})&md=mqtt&view_mode=edit_mqtt&id=\1',
        '/^' . $root . 'panel\/zigbee\/(bridges|journal|discovered|monitoring)\.html/is'
            => '?(panel:{action=zigbee})&md=zigbee&view_mode=\1',
        '/^' . $root . 'panel\/zigbee\/(device|property)\/(\d+)\.html/is'
            => '?(panel:{action=zigbee})&md=zigbee&view_mode=\1&id=\2',
        '/^' . $root . 'panel\/popup\/(.+?)\.html/is'
            => '?(panel:{action=\1,print=1})&print=1',
        '/^' . $root . 'panel\/class\/(\d+)\.html/is'
            => '?(panel:{action=classes})&md=classes&view_mode=edit_classes&id=\1',
        '/^' . $root . 'panel\/class\/(\d+)\/export\.html/is'
            => '?(panel:{action=classes})&md=classes&view_mode=export_classes&id=\1',
        '/^' . $root . 'panel\/class\/(\d+)\/objects\.html/is'
            => '?(panel:{action=classes})&md=classes&view_mode=edit_classes&id=\1&tab=objects',
        '/^' . $root . 'panel\/class\/(\d+)\/properties\.html/is'
            => '?(panel:{action=classes})&md=classes&view_mode=edit_classes&id=\1&tab=properties',
        '/^' . $root . 'panel\/class\/(\d+)\/methods\.html/is'
            => '?(panel:{action=classes})&md=classes&view_mode=edit_classes&id=\1&tab=methods',
        '/^' . $root . 'panel\/class\/(\d+)\/properties\/list\.html/is'
            => '?(panel:{action=classes}classes:{view_mode=edit_classes, tab=properties, id=\1, instance=adm})&md=properties&view_mode=search_properties',
        '/^' . $root . 'panel\/class\/(\d+)\/properties\/add\.html/is'
            => '?(panel:{action=classes}classes:{view_mode=edit_classes, tab=properties, id=\1, instance=adm})&md=properties&view_mode=edit_properties',
        '/^' . $root . 'panel\/class\/(\d+)\/properties\/(\d+)\.html/is'
            => '?(panel:{action=classes}classes:{view_mode=edit_classes, tab=properties, id=\1, instance=adm})&md=properties&view_mode=edit_properties&id=\2',
        '/^' . $root . 'panel\/class\/(\d+)\/properties\/(\d+)\/delete\.html/is'
            => '?(panel:{action=classes}classes:{view_mode=edit_classes, tab=properties, id=\1, instance=adm})&md=properties&view_mode=delete_properties&id=\2',
        '/^' . $root . 'panel\/class\/(\d+)\/methods\/list\.html/is'
            => '?(panel:{action=classes}classes:{view_mode=edit_classes, tab=methods, id=\1, instance=adm})&md=methods&view_mode=search_methods',
        '/^' . $root . 'panel\/class\/(\d+)\/methods\/add\.html/is'
            => '?(panel:{action=classes}classes:{view_mode=edit_classes, tab=methods, id=\1, instance=adm})&md=methods&view_mode=edit_methods',
        '/^' . $root . 'panel\/class\/(\d+)\/methods\/(\d+)\.html/is'
            => '?(panel:{action=classes}classes:{view_mode=edit_classes, tab=methods, id=\1, instance=adm})&md=methods&view_mode=edit_methods&id=\2',
        '/^' . $root . 'panel\/class\/(\d+)\/methods\/(\d+)\/delete\.html/is'
            => '?(panel:{action=classes}classes:{view_mode=edit_classes, tab=methods, id=\1, instance=adm})&md=methods&view_mode=delete_methods&id=\2',
        '/^' . $root . 'panel\/class\/(\d+)\/object\/add\.html/is'
            => '?(panel:{action=classes}classes:{view_mode=edit_classes, tab=objects, id=\1, instance=adm})&md=objects&view_mode=edit_objects',
        '/^' . $root . 'panel\/class\/(\d+)\/objects\/list\.html/is'
            => '?(panel:{action=classes}classes:{view_mode=edit_classes, tab=objects, id=\1, instance=adm})&md=objects&view_mode=search_objects',
        '/^' . $root . 'panel\/class\/(\d+)\/object\/(\d+)\.html/is'
            => '?(panel:{action=classes}classes:{view_mode=edit_classes, tab=objects, id=\1, instance=adm})&md=objects&view_mode=edit_objects&id=\2',
        '/^' . $root . 'panel\/class\/(\d+)\/object\/(\d+)\/methods\.html/is'
            => '?(panel:{action=classes}classes:{view_mode=edit_classes, tab=objects, id=\1, instance=adm})&md=objects&view_mode=edit_objects&id=\2&tab=methods',
        '/^' . $root . 'panel\/class\/(\d+)\/object\/(\d+)\/methods\/(\d+)\.html/is'
            => '?(panel:{action=classes}classes:{view_mode=edit_classes, tab=objects, id=\1, instance=adm})&md=objects&view_mode=edit_objects&id=\2&tab=methods&overwrite=1&method_id=\3',
        '/^' . $root . 'panel\/class\/(\d+)\/object\/(\d+)\/properties\.html/is'
            => '?(panel:{action=classes}classes:{view_mode=edit_classes, tab=objects, id=\1, instance=adm})&md=objects&view_mode=edit_objects&id=\2&tab=properties',
        '/^' . $root . 'panel\/class\/(\d+)\/object\/(\d+)\/delete\.html/is'
            => '?(panel:{action=classes}classes:{view_mode=edit_classes, tab=objects, id=\1, instance=adm})&md=objects&view_mode=delete_objects&id=\2',
        '/^' . $root . 'panel\/class\/(\d+)\/object\/(\d+)\/clone\.html/is'
            => '?(panel:{action=classes}classes:{view_mode=edit_classes, tab=objects, id=\1, instance=adm})&md=objects&view_mode=clone&id=\2',
        '/^' . $root . 'panel\/class\/(\d+)\/object\/(\d+)\/history\/clear\.html/is'
            => '?(panel:{action=classes}classes:{view_mode=edit_classes, tab=objects, id=\1, instance=adm}objects:{id=\2, view_mode=edit_objects, tab=history})&md=history&view_mode=&object_id=\2&mode=clear_history',
        '/^' . $root . 'panel\/zwave\/(\d+)\.html/is'
            => '?(panel:{action=zwave})&md=zwave&view_mode=edit_zwave_devices&id=\1',
        '/^' . $root . 'panel\/app_gpstrack\/action_(\d+)\.html/is'
            => '?(panel:{action=app_gpstrack})&md=app_gpstrack&data_source=gpsactions&view_mode=edit_gpsactions&id=\1',

        // --- Generic admin tabs/items -------------------------------------
        // Class tab (objects/properties/methods/...).
        '/^' . $root . 'panel\/class\/(\d+)\/([\w-]+)\.html/is'
            => '?(panel:{action=classes})&md=classes&view_mode=edit_classes&id=\1&tab=\2',
        // Object tab (details/history/debug/...).
        '/^' . $root . 'panel\/class\/(\d+)\/object\/(\d+)\/([\w-]+)\.html/is'
            => '?(panel:{action=classes}classes:{view_mode=edit_classes, tab=objects, id=\1, instance=adm})&md=objects&view_mode=edit_objects&id=\2&tab=\3',
        // Any module item tab.
        '/^' . $root . 'panel\/(\w+)\/(\d+)\/([\w-]+)\.html/is'
            => '?(panel:{action=\1})&md=\1&id=\2&tab=\3',
        // Any module item.
        '/^' . $root . 'panel\/(\w+)\/(\d+)\.html/is'
            => '?(panel:{action=\1})&md=\1&id=\2',
        // Any module list.
        '/^' . $root . 'panel\/(\w+)\.html/is'
            => '?(panel:{action=\1})&md=\1',
        // Admin root (dashboard).
        '/^' . $root . 'panel\/?$/is'
            => '',

        // Temporary admin AJAX aliases; all requests require an admin session.
        '/^' . $root . 'ajax\/([a-zA-Z0-9_]+)\.html$/s' => '?action=\1&ajax=1',
        '/^' . $root . 'getlatestnote\.html$/s' => '?action=getlatestnote',
    );
}

/**
 * Decode a legacy `pd` value into module states.
 *
 * The value is a `pz_`-separated list of `urlencode(base64_encode('module:{k=v,k=v}'))`
 * chunks. Leading/trailing empty chunks are tolerated: saveParams() emits a
 * delimiter even when the module itself has no params.
 *
 * @param string $pd
 * @return array<int,array{name:string,params:array<string,string>}>
 */
function mdj_admin_decode_pd($pd)
{
    $pd = (string)$pd;
    if ($pd === '') {
        return array();
    }

    $result = array();
    foreach (explode('pz_', $pd) as $chunk) {
        $chunk = trim($chunk);
        if ($chunk === '') {
            continue;
        }

        $chunk = rawurldecode($chunk);
        // PHP's query parser turns "+" into a space; base64 uses "+".
        $chunk = str_replace(' ', '+', $chunk);
        $decoded = base64_decode($chunk, true);
        if ($decoded === false || $decoded === '') {
            continue;
        }

        if (!preg_match('/^([A-Za-z0-9_]+):\{(.*)\}$/s', $decoded, $m)) {
            continue;
        }

        $params = array();
        foreach (explode(',', $m[2]) as $pair) {
            $pair = trim($pair);
            if ($pair === '') {
                continue;
            }
            $parts = explode('=', $pair, 2);
            $key = trim($parts[0]);
            if ($key === '') {
                continue;
            }
            $params[$key] = isset($parts[1]) ? trim($parts[1]) : '';
        }

        $result[] = array('name' => $m[1], 'params' => $params);
    }

    return $result;
}

/**
 * Encode module state chunks in the legacy `pd` format.
 *
 * @param string $state e.g. "(panel:{action=classes}classes:{id=10})"
 * @return string
 */
function mdj_admin_encode_state($state)
{
    $state = (string)$state;
    if ($state === '') {
        return '';
    }

    $encoded = array();
    if (preg_match_all('/([A-Za-z0-9_]+):\{(.*?)\}/s', $state, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $params = array();
            foreach (explode(',', $match[2]) as $pair) {
                $pair = trim($pair);
                if ($pair === '') {
                    continue;
                }
                $parts = explode('=', $pair, 2);
                $key = trim($parts[0]);
                if ($key === '') {
                    continue;
                }
                $params[] = $key . '=' . (isset($parts[1]) ? trim($parts[1]) : '');
            }
            if ($params) {
                $payload = $match[1] . ':{' . implode(',', $params) . '}';
                $encoded[] = urlencode(base64_encode($payload));
            }
        }
    }

    return implode('pz_', $encoded);
}

/**
 * Convert a legacy nf.php target into a normal query array.
 *
 * @param string $target e.g. "?(panel:{action=classes})&md=objects&id=7"
 * @return array<string,mixed>
 */
function mdj_admin_target_to_query($target)
{
    $target = (string)$target;
    if ($target === '' || $target === '?') {
        return array();
    }

    if ($target[0] === '?') {
        $target = substr($target, 1);
    }

    $query = array();
    if (preg_match('/^\((.+?)\)(.*)$/s', $target, $m)) {
        $pd = mdj_admin_encode_state($m[1]);
        if ($pd !== '') {
            $query['pd'] = $pd;
        }
        $other = ltrim($m[2], '&');
        if ($other !== '') {
            $parsed = array();
            parse_str($other, $parsed);
            $query = array_merge($query, $parsed);
        }
    } else {
        $parsed = array();
        parse_str($target, $parsed);
        $query = $parsed;
    }

    return $query;
}

/**
 * Find a route for a path.
 *
 * @param string $path Raw request path.
 * @return array{target:string,query:array<string,mixed>}|null
 */
function mdj_admin_resolve_path($path)
{
    $path = (string)$path;
    foreach (mdj_admin_route_table() as $regex => $target) {
        if (preg_match($regex, $path, $m)) {
            $resolved = $target;
            for ($i = 1, $c = count($m); $i < $c; $i++) {
                $resolved = str_replace('\\' . $i, $m[$i], $resolved);
            }
            $resolved = preg_replace('/\\\\(\d+?)/', '', $resolved);

            return array(
                'target' => $resolved,
                'query' => mdj_admin_target_to_query($resolved),
            );
        }
    }

    return null;
}

/**
 * Extract a normalized request state from a query array.
 *
 * @param array<string,mixed> $query
 * @return array{module:string,params:array<string,mixed>,parents:array<int,array{name:string,params:array<string,mixed>}>,forbidden:bool}
 */
function mdj_admin_state_from_query($query)
{
    $query = is_array($query) ? $query : array();
    $forbidden = false;

    foreach (array('print', 'popup', 'ajax', 'ajax_panel', 'part_load', 'dynids', 'ajt', 'prj', 'PHPSESSID') as $key) {
        if (isset($query[$key]) && $query[$key] !== '' && $query[$key] !== null) {
            $forbidden = true;
        }
    }
    if (isset($query['view_mode']) && is_string($query['view_mode'])
        && stripos($query['view_mode'], 'ajax') !== false) {
        $forbidden = true;
    }

    $pd = isset($query['pd']) && is_scalar($query['pd']) ? (string)$query['pd'] : '';
    $md = isset($query['md']) && is_scalar($query['md']) ? trim((string)$query['md']) : '';
    $action = isset($query['action']) && is_scalar($query['action']) ? trim((string)$query['action']) : '';

    $chain = array_reverse(mdj_admin_decode_pd($pd));

    $params = $query;
    unset($params['pd'], $params['md'], $params['inst'], $params['action']);

    if ($md === '' || $md === 'panel') {
        if ($action !== '') {
            $module = $action;
            unset($params['action']);
        } else {
            $module = 'panel';
        }
    } else {
        $module = $md;
    }

    $parents = array();
    foreach ($chain as $entry) {
        if (!isset($entry['name']) || $entry['name'] === 'panel') {
            continue;
        }
        if ($entry['name'] === $module) {
            continue;
        }
        $parents[] = array(
            'name' => $entry['name'],
            'params' => isset($entry['params']) && is_array($entry['params']) ? $entry['params'] : array(),
        );
    }

    return array(
        'module' => $module,
        'params' => $params,
        'parents' => $parents,
        'forbidden' => $forbidden,
    );
}

/**
 * Normalize params before comparing two states. Empty/default values are
 * dropped, keys are sorted.
 *
 * @param string $module
 * @param array<string,mixed> $params
 * @return array<string,string>
 */
function mdj_admin_normalize_params($module, $params)
{
    $module = (string)$module;
    $result = array();
    if (!is_array($params)) {
        return $result;
    }

    foreach ($params as $key => $value) {
        $key = (string)$key;
        if (in_array($key, array('pd', 'md', 'inst', 'instance', 'action', 'prj', 'PHPSESSID'), true)) {
            continue;
        }
        if (is_array($value)) {
            $normalized = array();
            foreach ($value as $itemKey => $itemValue) {
                if (is_scalar($itemValue)) {
                    $normalized[(string)$itemKey] = trim((string)$itemValue);
                }
            }
            if ($normalized) {
                ksort($normalized);
                $result[$key] = http_build_query($normalized, '', '&', PHP_QUERY_RFC3986);
            }
            continue;
        }
        if (!is_scalar($value)) {
            continue;
        }
        $value = trim((string)$value);
        if ($value === '') {
            continue;
        }
        if ($key === 'data_source' && ($value === $module || $value === 'classes' && $module === 'classes')) {
            continue;
        }
        if ($key === 'view_mode' && $value === 'search_' . $module) {
            continue;
        }
        // The Yandex Home add screen and the edit screen are the same form.
        if ($module === 'yandexhome' && $key === 'view_mode' && $value === 'addnew_yandexhome_devices') {
            $value = 'edit_yandexhome_devices';
        }
        $result[$key] = $value;
    }

    ksort($result);

    return $result;
}

/**
 * Compare two extracted states.
 *
 * @param array $a
 * @param array $b
 * @return bool
 */
function mdj_admin_states_match($a, $b)
{
    if (!is_array($a) || !is_array($b)) {
        return false;
    }
    if ($a['module'] !== $b['module']) {
        return false;
    }
    if (mdj_admin_normalize_params($a['module'], $a['params']) !== mdj_admin_normalize_params($b['module'], $b['params'])) {
        return false;
    }

    $ap = isset($a['parents']) && is_array($a['parents']) ? $a['parents'] : array();
    $bp = isset($b['parents']) && is_array($b['parents']) ? $b['parents'] : array();

    // Parent order depends on the legacy pd encoding direction. Compare the
    // same set of parent states independent of that order.
    $sortParents = static function (array $parents): array {
        usort($parents, static function ($left, $right) {
            $byName = strcmp((string)$left['name'], (string)$right['name']);
            if ($byName !== 0) {
                return $byName;
            }
            return strcmp(
                http_build_query((array)$left['params'], '', '&', PHP_QUERY_RFC3986),
                http_build_query((array)$right['params'], '', '&', PHP_QUERY_RFC3986)
            );
        });
        return $parents;
    };
    $ap = $sortParents($ap);
    $bp = $sortParents($bp);

    if (count($ap) !== count($bp)) {
        return false;
    }
    foreach ($ap as $i => $parent) {
        if (!isset($bp[$i]) || $parent['name'] !== $bp[$i]['name']) {
            return false;
        }
        if (mdj_admin_normalize_params($parent['name'], $parent['params']) !== mdj_admin_normalize_params($bp[$i]['name'], $bp[$i]['params'])) {
            return false;
        }
    }

    return true;
}

/**
 * Build a pretty path for a state.
 *
 * @param array $state Extracted state.
 * @return array{path:string,query:array<string,string>}|null
 */
function mdj_admin_build_pretty($state)
{
    if (!is_array($state) || !empty($state['forbidden'])) {
        return null;
    }

    $module = isset($state['module']) ? (string)$state['module'] : '';
    if ($module === '' || !preg_match('/^[A-Za-z0-9_]+$/', $module)) {
        return null;
    }
    if (in_array($module, array('application', 'index', 'admin', 'nf'), true)) {
        return null;
    }

    $params = isset($state['params']) && is_array($state['params']) ? $state['params'] : array();
    $parents = isset($state['parents']) && is_array($state['parents']) ? $state['parents'] : array();

    $id = isset($params['id']) && is_scalar($params['id']) ? (int)$params['id'] : 0;
    $tab = isset($params['tab']) && is_scalar($params['tab']) ? trim((string)$params['tab']) : '';
    $view = isset($params['view_mode']) && is_scalar($params['view_mode']) ? trim((string)$params['view_mode']) : '';

    $extra = $params;
    unset($extra['id'], $extra['tab']);

    $dropView = false;
    $dropDataSource = true;

    // Find the class parent for object/method/property routes.
    $classId = 0;
    foreach ($parents as $parent) {
        if ($parent['name'] === 'classes' && isset($parent['params']['id'])) {
            $classId = (int)$parent['params']['id'];
        }
    }

    if ($module === 'panel') {
        if (!$params) {
            return array('path' => '/panel/', 'query' => array());
        }
        return null;
    }

    if ($module === 'classes') {
        if ($id > 0) {
            if ($view === 'export_classes') {
                $dropView = true;
                return array(
                    'path' => '/panel/class/' . $id . '/export.html',
                    'query' => mdj_admin_finish_extra($extra, $view, $module, $dropView, $dropDataSource),
                );
            }
            if ($view !== '' && $view !== 'edit_classes') {
                return null;
            }
            $dropView = true;
            if ($tab !== '') {
                return array(
                    'path' => '/panel/class/' . $id . '/' . rawurlencode($tab) . '.html',
                    'query' => mdj_admin_finish_extra($extra, $view, $module, $dropView, $dropDataSource),
                );
            }
            return array(
                'path' => '/panel/class/' . $id . '.html',
                'query' => mdj_admin_finish_extra($extra, $view, $module, $dropView, $dropDataSource),
            );
        }

        if ($view !== '' && $view !== 'search_classes' && $view !== 'edit_classes') {
            return null;
        }
        $dropView = $view === 'search_classes';
        return array(
            'path' => '/panel/classes.html',
            'query' => mdj_admin_finish_extra($extra, $view, $module, $dropView, $dropDataSource),
        );
    }

    if ($module === 'objects') {
        if ($id > 0) {
            if ($classId <= 0) {
                return null;
            }
            if ($view === 'delete_objects') {
                $dropView = true;
                return array(
                    'path' => '/panel/class/' . $classId . '/object/' . $id . '/delete.html',
                    'query' => mdj_admin_finish_extra($extra, $view, $module, $dropView, $dropDataSource),
                );
            }
            if ($view === 'clone') {
                $dropView = true;
                return array(
                    'path' => '/panel/class/' . $classId . '/object/' . $id . '/clone.html',
                    'query' => mdj_admin_finish_extra($extra, $view, $module, $dropView, $dropDataSource),
                );
            }
            if ($view !== '' && $view !== 'edit_objects') {
                return null;
            }
            $dropView = true;

            if ($tab === 'methods'
                && isset($params['method_id']) && (int)$params['method_id'] > 0
                && isset($params['overwrite']) && (string)$params['overwrite'] === '1') {
                $methodId = (int)$params['method_id'];
                unset($extra['method_id'], $extra['overwrite']);
                return array(
                    'path' => '/panel/class/' . $classId . '/object/' . $id . '/methods/' . $methodId . '.html',
                    'query' => mdj_admin_finish_extra($extra, $view, $module, $dropView, $dropDataSource),
                );
            }

            if ($tab !== '') {
                return array(
                    'path' => '/panel/class/' . $classId . '/object/' . $id . '/' . rawurlencode($tab) . '.html',
                    'query' => mdj_admin_finish_extra($extra, $view, $module, $dropView, $dropDataSource),
                );
            }

            return array(
                'path' => '/panel/class/' . $classId . '/object/' . $id . '.html',
                'query' => mdj_admin_finish_extra($extra, $view, $module, $dropView, $dropDataSource),
            );
        }

        if ($classId > 0) {
            if ($view === 'edit_objects') {
                $dropView = true;
                return array(
                    'path' => '/panel/class/' . $classId . '/object/add.html',
                    'query' => mdj_admin_finish_extra($extra, $view, $module, $dropView, $dropDataSource),
                );
            }
            if ($view !== '' && $view !== 'search_objects') {
                return null;
            }
            $dropView = $view === 'search_objects';
            return array(
                'path' => '/panel/class/' . $classId . '/objects/list.html',
                'query' => mdj_admin_finish_extra($extra, $view, $module, $dropView, $dropDataSource),
            );
        }

        $dropView = $view === 'search_objects';
        return array(
            'path' => '/panel/objects.html',
            'query' => mdj_admin_finish_extra($extra, $view, $module, $dropView, $dropDataSource),
        );
    }

    if ($module === 'methods') {
        if ($classId <= 0) {
            return null;
        }
        if ($id <= 0) {
            if ($view === 'search_methods') {
                $dropView = true;
                return array(
                    'path' => '/panel/class/' . $classId . '/methods/list.html',
                    'query' => mdj_admin_finish_extra($extra, $view, $module, $dropView, $dropDataSource),
                );
            }
            if ($view !== '' && $view !== 'edit_methods') {
                return null;
            }
            $dropView = true;
            return array(
                'path' => '/panel/class/' . $classId . '/methods/add.html',
                'query' => mdj_admin_finish_extra($extra, $view, $module, $dropView, $dropDataSource),
            );
        }
        if ($view === 'delete_methods') {
            $dropView = true;
            return array(
                'path' => '/panel/class/' . $classId . '/methods/' . $id . '/delete.html',
                'query' => mdj_admin_finish_extra($extra, $view, $module, $dropView, $dropDataSource),
            );
        }
        if ($view !== '' && $view !== 'edit_methods') {
            return null;
        }
        $dropView = true;
        return array(
            'path' => '/panel/class/' . $classId . '/methods/' . $id . '.html',
            'query' => mdj_admin_finish_extra($extra, $view, $module, $dropView, $dropDataSource),
        );
    }

    if ($module === 'properties') {
        if ($classId <= 0) {
            return null;
        }
        if ($id <= 0) {
            if ($view === 'search_properties') {
                $dropView = true;
                return array(
                    'path' => '/panel/class/' . $classId . '/properties/list.html',
                    'query' => mdj_admin_finish_extra($extra, $view, $module, $dropView, $dropDataSource),
                );
            }
            if ($view !== '' && $view !== 'edit_properties') {
                return null;
            }
            $dropView = true;
            return array(
                'path' => '/panel/class/' . $classId . '/properties/add.html',
                'query' => mdj_admin_finish_extra($extra, $view, $module, $dropView, $dropDataSource),
            );
        }
        if ($view === 'delete_properties') {
            $dropView = true;
            return array(
                'path' => '/panel/class/' . $classId . '/properties/' . $id . '/delete.html',
                'query' => mdj_admin_finish_extra($extra, $view, $module, $dropView, $dropDataSource),
            );
        }
        if ($view !== '' && $view !== 'edit_properties') {
            return null;
        }
        $dropView = true;
        return array(
            'path' => '/panel/class/' . $classId . '/properties/' . $id . '.html',
            'query' => mdj_admin_finish_extra($extra, $view, $module, $dropView, $dropDataSource),
        );
    }

    if ($module === 'history') {
        $objectId = 0;
        foreach ($parents as $parent) {
            if ($parent['name'] === 'objects' && isset($parent['params']['id'])) {
                $objectId = (int)$parent['params']['id'];
            }
        }
        $mode = isset($params['mode']) && is_scalar($params['mode']) ? trim((string)$params['mode']) : '';
        $objectParam = isset($params['object_id']) && is_scalar($params['object_id']) ? (int)$params['object_id'] : 0;
        if ($classId > 0 && $objectId > 0 && $id === 0 && $view === ''
            && $mode === 'clear_history' && $objectParam === $objectId) {
            unset($extra['object_id'], $extra['mode']);
            $dropView = true;
            return array(
                'path' => '/panel/class/' . $classId . '/object/' . $objectId . '/history/clear.html',
                'query' => mdj_admin_finish_extra($extra, $view, $module, $dropView, $dropDataSource),
            );
        }
        return null;
    }

    if ($module === 'scripts' && $id > 0 && ($view === '' || $view === 'edit_scripts')) {
        $dropView = true;
        return array(
            'path' => '/panel/script/' . $id . '.html',
            'query' => mdj_admin_finish_extra($extra, $view, $module, $dropView, $dropDataSource),
        );
    }

    if ($module === 'events') {
        if ($id > 0) {
            if ($view === 'delete_events') {
                $dropView = true;
                return array(
                    'path' => '/panel/event/' . $id . '/delete.html',
                    'query' => mdj_admin_finish_extra($extra, $view, $module, $dropView, $dropDataSource),
                );
            }
            if ($view === '' || $view === 'edit_events') {
                $dropView = true;
                return array(
                    'path' => '/panel/event/' . $id . '.html',
                    'query' => mdj_admin_finish_extra($extra, $view, $module, $dropView, $dropDataSource),
                );
            }
            return null;
        }
        if ($view === '' || $view === 'search_events') {
            $dropView = true;
            return array(
                'path' => '/panel/events.html',
                'query' => mdj_admin_finish_extra($extra, $view, $module, $dropView, $dropDataSource),
            );
        }
        return null;
    }

    if ($module === 'users') {
        if ($parents || $tab !== '') {
            return null;
        }
        if ($id > 0 && $view === 'edit_users') {
            $dropView = true;
            return array(
                'path' => '/panel/users/' . $id . '.html',
                'query' => mdj_admin_finish_extra($extra, $view, $module, $dropView, $dropDataSource),
            );
        }
        if ($id > 0 && $view === 'delete_users') {
            $dropView = true;
            return array(
                'path' => '/panel/users/' . $id . '/delete.html',
                'query' => mdj_admin_finish_extra($extra, $view, $module, $dropView, $dropDataSource),
            );
        }
        if ($id <= 0 && $view === 'edit_users') {
            $dropView = true;
            return array(
                'path' => '/panel/users/add.html',
                'query' => mdj_admin_finish_extra($extra, $view, $module, $dropView, $dropDataSource),
            );
        }
        if ($id <= 0 && ($view === '' || $view === 'search_users')) {
            $dropView = true;
            return array(
                'path' => '/panel/users.html',
                'query' => mdj_admin_finish_extra($extra, $view, $module, $dropView, $dropDataSource),
            );
        }
        return null;
    }

    if ($module === 'pinghosts') {
        if ($parents || $tab !== '') {
            return null;
        }
        if ($id > 0 && $view === 'edit_pinghosts') {
            $dropView = true;
            return array(
                'path' => '/panel/pinghosts/' . $id . '.html',
                'query' => mdj_admin_finish_extra($extra, $view, $module, $dropView, $dropDataSource),
            );
        }
        if ($id > 0 && $view === 'delete_pinghosts') {
            $dropView = true;
            return array(
                'path' => '/panel/pinghosts/' . $id . '/delete.html',
                'query' => mdj_admin_finish_extra($extra, $view, $module, $dropView, $dropDataSource),
            );
        }
        if ($id <= 0 && $view === 'edit_pinghosts') {
            $dropView = true;
            return array(
                'path' => '/panel/pinghosts/add.html',
                'query' => mdj_admin_finish_extra($extra, $view, $module, $dropView, $dropDataSource),
            );
        }
        if ($id <= 0 && ($view === '' || $view === 'search_pinghosts')) {
            $dropView = true;
            return array(
                'path' => '/panel/pinghosts.html',
                'query' => mdj_admin_finish_extra($extra, $view, $module, $dropView, $dropDataSource),
            );
        }
        return null;
    }

    if ($module === 'yandexhome') {
        if ($parents) {
            return null;
        }
        if ($id > 0 && $tab === '' && $view === 'edit_yandexhome_devices') {
            $dropView = true;
            return array(
                'path' => '/panel/yandexhome/' . $id . '.html',
                'query' => mdj_admin_finish_extra($extra, $view, $module, $dropView, $dropDataSource),
            );
        }
        if ($id > 0 && $tab === '' && $view === 'delete_yandexhome_devices') {
            $dropView = true;
            return array(
                'path' => '/panel/yandexhome/' . $id . '/delete.html',
                'query' => mdj_admin_finish_extra($extra, $view, $module, $dropView, $dropDataSource),
            );
        }
        if ($id <= 0 && $tab === '' && ($view === 'edit_yandexhome_devices' || $view === 'addnew_yandexhome_devices')) {
            $dropView = true;
            return array(
                'path' => '/panel/yandexhome/add.html',
                'query' => mdj_admin_finish_extra($extra, $view, $module, $dropView, $dropDataSource),
            );
        }
        if ($id <= 0 && $tab === '' && ($view === 'settings' || $view === 'update_settings')) {
            $dropView = true;
            return array(
                'path' => '/panel/yandexhome/settings.html',
                'query' => mdj_admin_finish_extra($extra, $view, $module, $dropView, $dropDataSource),
            );
        }
        if ($id <= 0 && $tab === '' && $view === 'about') {
            $dropView = true;
            return array(
                'path' => '/panel/yandexhome/about.html',
                'query' => mdj_admin_finish_extra($extra, $view, $module, $dropView, $dropDataSource),
            );
        }
        if ($id <= 0 && $tab === '' && ($view === '' || $view === 'search_yandexhome_devices')) {
            $dropView = true;
            return array(
                'path' => '/panel/yandexhome.html',
                'query' => mdj_admin_finish_extra($extra, $view, $module, $dropView, $dropDataSource),
            );
        }
        return null;
    }

    if ($module === 'yadevices') {
        if ($parents) {
            return null;
        }
        if ($id > 0 && $tab === '' && ($view === '' || $view === 'edit_yastations')) {
            $dropView = $view === 'edit_yastations';
            return array(
                'path' => '/panel/yadevices/stations/' . $id . '.html',
                'query' => mdj_admin_finish_extra($extra, $view, $module, $dropView, $dropDataSource),
            );
        }
        if ($id > 0 && $tab === '' && $view === 'delete_yastations') {
            $dropView = true;
            return array(
                'path' => '/panel/yadevices/stations/' . $id . '/delete.html',
                'query' => mdj_admin_finish_extra($extra, $view, $module, $dropView, $dropDataSource),
            );
        }
        if ($id > 0 && $tab === '' && ($view === '' || $view === 'edit_yadevices')) {
            $dropView = $view === 'edit_yadevices';
            return array(
                'path' => '/panel/yadevices/devices/' . $id . '.html',
                'query' => mdj_admin_finish_extra($extra, $view, $module, $dropView, $dropDataSource),
            );
        }
        if ($id > 0 && $tab === '' && $view === 'delete_yadevice') {
            $dropView = true;
            return array(
                'path' => '/panel/yadevices/devices/' . $id . '/delete.html',
                'query' => mdj_admin_finish_extra($extra, $view, $module, $dropView, $dropDataSource),
            );
        }
        if ($id <= 0 && $tab === '' && $view === 'search_yadevices') {
            $dropView = true;
            return array(
                'path' => '/panel/yadevices/devices.html',
                'query' => mdj_admin_finish_extra($extra, $view, $module, $dropView, $dropDataSource),
            );
        }
        if ($id <= 0 && $tab === '' && $view === 'search_scenarios') {
            $dropView = true;
            return array(
                'path' => '/panel/yadevices/scenarios.html',
                'query' => mdj_admin_finish_extra($extra, $view, $module, $dropView, $dropDataSource),
            );
        }
        if ($id <= 0 && $tab === '' && ($view === 'settings' || $view === 'update_settings')) {
            $dropView = true;
            return array(
                'path' => '/panel/yadevices/settings.html',
                'query' => mdj_admin_finish_extra($extra, $view, $module, $dropView, $dropDataSource),
            );
        }
        if ($id <= 0 && $tab === '' && $view === 'auth') {
            $dropView = true;
            return array(
                'path' => '/panel/yadevices/auth.html',
                'query' => mdj_admin_finish_extra($extra, $view, $module, $dropView, $dropDataSource),
            );
        }
        if ($id <= 0 && $tab === '' && $view === 'about') {
            $dropView = true;
            return array(
                'path' => '/panel/yadevices/about.html',
                'query' => mdj_admin_finish_extra($extra, $view, $module, $dropView, $dropDataSource),
            );
        }
        if ($id <= 0 && $tab === '' && ($view === '' || $view === 'search_yastations')) {
            $dropView = true;
            return array(
                'path' => '/panel/yadevices.html',
                'query' => mdj_admin_finish_extra($extra, $view, $module, $dropView, $dropDataSource),
            );
        }
        return null;
    }

    if ($module === 'locations') {
        if ($parents) {
            return null;
        }
        if ($id > 0 && $view === 'priority_up' && $tab === '') {
            $dropView = true;
            return array(
                'path' => '/panel/locations/' . $id . '/up.html',
                'query' => mdj_admin_finish_extra($extra, $view, $module, $dropView, $dropDataSource),
            );
        }
        if ($id > 0 && $view === 'priority_down' && $tab === '') {
            $dropView = true;
            return array(
                'path' => '/panel/locations/' . $id . '/down.html',
                'query' => mdj_admin_finish_extra($extra, $view, $module, $dropView, $dropDataSource),
            );
        }
        if ($id > 0 && $view === 'delete_locations' && $tab === '') {
            $dropView = true;
            return array(
                'path' => '/panel/locations/' . $id . '/delete.html',
                'query' => mdj_admin_finish_extra($extra, $view, $module, $dropView, $dropDataSource),
            );
        }
        if ($id > 0 && $view === 'edit_locations') {
            $dropView = true;
            if ($tab === 'logic') {
                return array(
                    'path' => '/panel/locations/' . $id . '/logic.html',
                    'query' => mdj_admin_finish_extra($extra, $view, $module, $dropView, $dropDataSource),
                );
            }
            if ($tab === '') {
                return array(
                    'path' => '/panel/locations/' . $id . '.html',
                    'query' => mdj_admin_finish_extra($extra, $view, $module, $dropView, $dropDataSource),
                );
            }
            return null;
        }
        if ($id <= 0 && $view === 'edit_locations') {
            $dropView = true;
            return array(
                'path' => '/panel/locations/add.html',
                'query' => mdj_admin_finish_extra($extra, $view, $module, $dropView, $dropDataSource),
            );
        }
        if ($id <= 0 && $tab === '' && ($view === '' || $view === 'search_locations')) {
            $dropView = true;
            return array(
                'path' => '/panel/locations.html',
                'query' => mdj_admin_finish_extra($extra, $view, $module, $dropView, $dropDataSource),
            );
        }
        return null;
    }

    if ($module === 'system_errors') {
        if ($parents) {
            return null;
        }
        if ($id > 0 && $view === 'edit_system_errors') {
            $dropView = true;
            if ($tab === 'history') {
                return array(
                    'path' => '/panel/system_errors/' . $id . '/history.html',
                    'query' => mdj_admin_finish_extra($extra, $view, $module, $dropView, $dropDataSource),
                );
            }
            if ($tab === '') {
                return array(
                    'path' => '/panel/system_errors/' . $id . '.html',
                    'query' => mdj_admin_finish_extra($extra, $view, $module, $dropView, $dropDataSource),
                );
            }
            return null;
        }
        if ($id > 0 && $tab === '' && $view === 'clear_system_errors') {
            $dropView = true;
            return array(
                'path' => '/panel/system_errors/' . $id . '/read.html',
                'query' => mdj_admin_finish_extra($extra, $view, $module, $dropView, $dropDataSource),
            );
        }
        if ($id > 0 && $tab === '' && $view === 'delete_system_errors') {
            $dropView = true;
            return array(
                'path' => '/panel/system_errors/' . $id . '/delete.html',
                'query' => mdj_admin_finish_extra($extra, $view, $module, $dropView, $dropDataSource),
            );
        }
        if ($id <= 0 && $tab === '' && $view === 'readall') {
            $dropView = true;
            return array(
                'path' => '/panel/system_errors/readall.html',
                'query' => mdj_admin_finish_extra($extra, $view, $module, $dropView, $dropDataSource),
            );
        }
        if ($id <= 0 && $tab === '' && $view === 'addtesterror') {
            $dropView = true;
            return array(
                'path' => '/panel/system_errors/test.html',
                'query' => mdj_admin_finish_extra($extra, $view, $module, $dropView, $dropDataSource),
            );
        }
        if ($id <= 0 && $tab === '' && ($view === '' || $view === 'search_system_errors')) {
            $dropView = true;
            return array(
                'path' => '/panel/system_errors.html',
                'query' => mdj_admin_finish_extra($extra, $view, $module, $dropView, $dropDataSource),
            );
        }
        return null;
    }

    if ($module === 'knowledge_base') {
        if ($parents) {
            return null;
        }

        $doc = isset($params['doc']) && is_scalar($params['doc']) ? trim((string)$params['doc']) : '';
        if ($doc !== '') {
            if (!preg_match('/^[\w.-]+(?:\/[\w.-]+)*$/', $doc)) {
                return null;
            }
            unset($extra['doc']);
            $dropView = true;
            $encodedDoc = implode('/', array_map('rawurlencode', explode('/', $doc)));

            return array(
                'path' => '/panel/knowledge_base/' . $encodedDoc . '.html',
                'query' => mdj_admin_finish_extra($extra, $view, $module, $dropView, $dropDataSource),
            );
        }

        if ($view !== '' && $view !== 'search_knowledge_base') {
            return null;
        }
        $dropView = $view === 'search_knowledge_base';

        return array(
            'path' => '/panel/knowledge_base.html',
            'query' => mdj_admin_finish_extra($extra, $view, $module, $dropView, $dropDataSource),
        );
    }

    if ($module === 'modes') {
        if ($parents || $tab !== '') return null;
        $path = null;
        if ($view === 'edit_modes') $path = $id > 0 ? '/panel/modes/' . $id . '.html' : '/panel/modes/add.html';
        if ($view === '' && $id <= 0) $path = '/panel/modes.html';
        if ($path === null) return null;
        return array('path' => $path, 'query' => mdj_admin_finish_extra($extra, $view, $module, true, $dropDataSource));
    }

    if ($module === 'zigbee') {
        if ($parents || $tab !== '') return null;
        $path = null;
        if ($id > 0 && in_array($view, array('device', 'property'), true)) $path = '/panel/zigbee/' . $view . '/' . $id . '.html';
        if ($id <= 0 && in_array($view, array('bridges', 'journal', 'discovered', 'monitoring'), true)) $path = '/panel/zigbee/' . $view . '.html';
        if ($id <= 0 && $view === '') $path = '/panel/zigbee.html';
        if ($path === null) return null;
        return array('path' => $path, 'query' => mdj_admin_finish_extra($extra, $view, $module, true, $dropDataSource));
    }

    if ($module === 'mqtt') {
        if ($parents) {
            return null;
        }
        if ($id > 0 && $view === 'edit_mqtt') {
            $dropView = true;
            return array(
                'path' => '/panel/mqtt/' . $id . '.html',
                'query' => mdj_admin_finish_extra($extra, $view, $module, $dropView, $dropDataSource),
            );
        }
        if ($id <= 0 && $view === 'edit_mqtt') {
            $dropView = true;
            return array(
                'path' => '/panel/mqtt/add.html',
                'query' => mdj_admin_finish_extra($extra, $view, $module, $dropView, $dropDataSource),
            );
        }
        if ($id > 0 && $tab === '' && $view === 'delete_mqtt') {
            $dropView = true;
            return array(
                'path' => '/panel/mqtt/' . $id . '/delete.html',
                'query' => mdj_admin_finish_extra($extra, $view, $module, $dropView, $dropDataSource),
            );
        }
        if ($id <= 0 && $tab === '' && ($view === 'settings' || $view === 'update_settings')) {
            $dropView = true;
            return array(
                'path' => '/panel/mqtt/settings.html',
                'query' => mdj_admin_finish_extra($extra, $view, $module, $dropView, $dropDataSource),
            );
        }
        if ($id <= 0 && $tab === '' && $view === 'clear_trash') {
            $dropView = true;
            return array(
                'path' => '/panel/mqtt/clear-trash.html',
                'query' => mdj_admin_finish_extra($extra, $view, $module, $dropView, $dropDataSource),
            );
        }
        if ($id <= 0 && $tab === '' && $view === 'optimize') {
            $dropView = true;
            return array(
                'path' => '/panel/mqtt/optimize.html',
                'query' => mdj_admin_finish_extra($extra, $view, $module, $dropView, $dropDataSource),
            );
        }
        if ($id <= 0 && $tab === '' && ($view === '' || $view === 'search_mqtt')) {
            $dropView = true;
            return array(
                'path' => '/panel/mqtt.html',
                'query' => mdj_admin_finish_extra($extra, $view, $module, $dropView, $dropDataSource),
            );
        }
        return null;
    }

    // Generic module pages are safe only without a parent module state.
    if ($parents) {
        return null;
    }

    if ($id > 0) {
        if ($tab !== '') {
            return array(
                'path' => '/panel/' . $module . '/' . $id . '/' . rawurlencode($tab) . '.html',
                'query' => mdj_admin_finish_extra($extra, $view, $module, $dropView, $dropDataSource),
            );
        }
        return array(
            'path' => '/panel/' . $module . '/' . $id . '.html',
            'query' => mdj_admin_finish_extra($extra, $view, $module, $dropView, $dropDataSource),
        );
    }

    $dropView = $view === 'search_' . $module;
    return array(
        'path' => '/panel/' . $module . '.html',
        'query' => mdj_admin_finish_extra($extra, $view, $module, $dropView, $dropDataSource),
    );
}

/**
 * Clean up params that are not represented in a pretty path.
 *
 * @param array<string,mixed> $extra
 * @param string $view
 * @param string $module
 * @param bool $dropView
 * @param bool $dropDataSource
 * @return array<string,string>
 */
function mdj_admin_finish_extra($extra, $view, $module, $dropView, $dropDataSource)
{
    if ($dropView) {
        unset($extra['view_mode']);
    }
    if ($dropDataSource && isset($extra['data_source'])) {
        $dataSource = trim((string)$extra['data_source']);
        if ($dataSource === '' || $dataSource === $module || ($module === 'classes' && $dataSource === 'classes')) {
            unset($extra['data_source']);
        }
    }

    $result = array();
    foreach ($extra as $key => $value) {
        $key = (string)$key;
        if (in_array($key, array('pd', 'md', 'inst', 'instance', 'action', 'prj', 'PHPSESSID'), true)) {
            continue;
        }
        if (is_array($value)) {
            $items = array();
            foreach ($value as $itemKey => $itemValue) {
                if (is_scalar($itemValue)) {
                    $items[(string)$itemKey] = trim((string)$itemValue);
                }
            }
            if ($items) {
                $result[$key] = $items;
            }
            continue;
        }
        if (!is_scalar($value)) {
            continue;
        }
        $value = trim((string)$value);
        if ($value === '') {
            continue;
        }
        $result[$key] = $value;
    }

    ksort($result);

    return $result;
}

/**
 * Convert an admin URL to a pretty URL when the conversion is lossless.
 *
 * @param string $url Absolute, root-relative or "?query" admin URL.
 * @return string
 */
function mdj_admin_pretty_url($url)
{
    $url = (string)$url;
    $root = defined('ROOTHTML') ? ROOTHTML : '/';

    if ($url === '') {
        return $url;
    }

    if ($url[0] === '?') {
        $url = rtrim($root, '/') . '/admin.php' . $url;
    }

    $parts = parse_url($url);
    if ($parts === false || !isset($parts['path'])) {
        return $url;
    }
    if (isset($parts['scheme']) || isset($parts['host'])) {
        return $url;
    }

    $path = $parts['path'];
    $adminPath = rtrim($root, '/') . '/admin.php';
    if ($path !== $adminPath && $path !== 'admin.php' && $path !== './admin.php') {
        return $url;
    }

    $fragment = isset($parts['fragment']) && $parts['fragment'] !== '' ? '#' . $parts['fragment'] : '';

    $query = array();
    if (isset($parts['query']) && $parts['query'] !== '') {
        parse_str($parts['query'], $query);
    }

    // No state at all: dashboard.
    if (!$query) {
        return ($adminPath === '/' ? '/panel/' : rtrim(str_replace('/admin.php', '', $adminPath), '/') . '/panel/') . $fragment;
    }

    $state = mdj_admin_state_from_query($query);
    $candidate = mdj_admin_build_pretty($state);
    if ($candidate === null) {
        return $url;
    }

    $candidateQuery = $candidate['query'];
    $candidatePath = $candidate['path'];
    $absolutePath = rtrim($root, '/') . $candidatePath;

    // Verify that the pretty URL restores exactly the same legacy state.
    $resolved = mdj_admin_resolve_path($absolutePath);
    if ($resolved === null) {
        return $url;
    }

    $resolvedQuery = array_merge($candidateQuery, $resolved['query']);
    $resolvedState = mdj_admin_state_from_query($resolvedQuery);
    if (!mdj_admin_states_match($state, $resolvedState)) {
        return $url;
    }

    $result = $absolutePath;
    if ($candidateQuery) {
        $result .= '?' . http_build_query($candidateQuery, '', '&', PHP_QUERY_RFC3986);
    }
    $result .= $fragment;

    return $result;
}

/**
 * Base URL for links generated by parseLinks().
 *
 * nf.php renders pretty pages with PHP_SELF like "/index.php/panel/...".
 * Building links from PHP_SELF there produces broken URLs, so admin requests
 * always use admin.php as their base.
 *
 * @return string
 */
function mdj_admin_link_base()
{
    $root = defined('ROOTHTML') ? ROOTHTML : '/';
    $phpSelf = isset($_SERVER['PHP_SELF']) ? (string)$_SERVER['PHP_SELF'] : '';
    $request = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '';

    $panelPrefix = rtrim($root, '/') . '/panel/';
    if (strpos($request, $panelPrefix) === 0 || $request === rtrim($panelPrefix, '/')
        || preg_match('#/admin\.php(?:/|$|\?)#', $phpSelf)) {
        return rtrim($root, '/') . '/admin.php';
    }
    if (preg_match('#/nf\.php(?:/|$|\?)#', $phpSelf)) {
        return rtrim($root, '/') . '/index.php';
    }

    return $phpSelf !== '' ? $phpSelf : rtrim($root, '/') . '/index.php';
}

/**
 * Check whether a module has a class file.
 *
 * @param string $name
 * @return bool
 */
function mdj_admin_module_exists($name)
{
    $name = (string)$name;
    if ($name === '' || !preg_match('/^[A-Za-z0-9_]+$/', $name)) {
        return false;
    }
    if (!defined('DIR_MODULES')) {
        return true;
    }
    if ($name === 'panel') {
        return is_file(DIR_MODULES . 'panel.class.php');
    }

    if (is_file(DIR_MODULES . $name . '/' . $name . '.class.php')
        || is_file(DIR_MODULES . $name . '.class.php')) {
        return true;
    }

    // Third-party modules sometimes use a different class file name.
    $candidates = glob(DIR_MODULES . $name . '/*.class.php');
    return is_array($candidates) && count($candidates) > 0;
}

/**
 * Whether the current admin GET request should be redirected to its pretty URL.
 *
 * @return bool
 */
function mdj_admin_should_canonicalize_request()
{
    if (PHP_SAPI === 'cli' || !isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'GET') {
        return false;
    }
    if (!isset($_SERVER['REQUEST_URI'])) {
        return false;
    }

    $path = parse_url((string)$_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (!is_string($path) || substr($path, -9) !== 'admin.php') {
        return false;
    }

    foreach (array('part_load', 'ajax', 'ajax_panel', 'print', 'popup', 'dynids', 'ajt', '_') as $key) {
        if (isset($_GET[$key]) && $_GET[$key] !== '') {
            return false;
        }
    }
    if (isset($_GET['view_mode']) && is_string($_GET['view_mode']) && stripos($_GET['view_mode'], 'ajax') !== false) {
        return false;
    }

    return true;
}
