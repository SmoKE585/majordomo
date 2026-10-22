<?php

/** System helpers. Public names and argument defaults are part of the module API. */

function actionLogDetectSource($context = array())
{
    if (isset($context['source']) && $context['source'] !== '') {
        return (string)$context['source'];
    }

    if (php_sapi_name() === 'cli') {
        return 'cli';
    }

    $request_uri = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '';
    if (stripos($request_uri, '/admin.php') !== false) {
        return 'admin';
    }
    if (stripos($request_uri, '/api.php') !== false) {
        return 'api';
    }
    if (stripos($request_uri, '/objects/') !== false) {
        return 'objects';
    }
    return 'web';
}

function echonow($msg, $color = '')
{
    DebMes(strip_tags($msg), 'auto_update', $color === 'red' ? 'error' : 'info');
    if ($color) {
        echo '<font color="' . $color . '">';
    }
    echo $msg;
    if ($color) {
        echo '</font>';
    }
    echo str_repeat(' ', 16 * 1024);
    flush();
    ob_flush();
}

function getActionLogRequestId($context = array())
{
    if (isset($context['request_id']) && $context['request_id'] !== '') {
        return (string)$context['request_id'];
    }
    if (isset($_SERVER['HTTP_X_REQUEST_ID']) && $_SERVER['HTTP_X_REQUEST_ID'] !== '') {
        return (string)$_SERVER['HTTP_X_REQUEST_ID'];
    }

    static $generated_request_id = null;
    if ($generated_request_id !== null) {
        return $generated_request_id;
    }

    $seed = '';
    if (isset($_SERVER['REQUEST_TIME_FLOAT'])) {
        $seed .= (string)$_SERVER['REQUEST_TIME_FLOAT'];
    } else {
        $seed .= microtime(true);
    }
    if (isset($_SERVER['REQUEST_URI'])) {
        $seed .= '|' . $_SERVER['REQUEST_URI'];
    }
    if (function_exists('session_id')) {
        $seed .= '|' . session_id();
    }
    $generated_request_id = md5($seed);
    return $generated_request_id;
}

function getActionsLogAvailableFields()
{
    static $fields_map = null;
    if ($fields_map !== null) {
        return $fields_map;
    }

    $fields_map = array();
    if (!SQLTableExists('actions_log')) {
        return $fields_map;
    }

    $fields = SQLGetFields('actions_log');
    if (is_array($fields)) {
        $total = count($fields);
        for ($i = 0; $i < $total; $i++) {
            if (isset($fields[$i]['Field'])) {
                $fields_map[strtoupper($fields[$i]['Field'])] = 1;
            }
        }
    }

    return $fields_map;
}

function logAction($action_type, $details = '', $context = array(), ...$legacy_args)
{
    if (!isModuleInstalled('actions_log')) return;

    // Backward compatibility:
    // - custom modules may pass 3rd argument as scalar
    // - details may be passed as array/object
    // - extra positional args are accepted and safely stored in details JSON
    if (is_array($details) || is_object($details)) {
        $details_payload = sanitizeActionLogData($details);
        $json_options = 0;
        if (defined('JSON_UNESCAPED_UNICODE')) {
            $json_options = $json_options | JSON_UNESCAPED_UNICODE;
        }
        if (defined('JSON_UNESCAPED_SLASHES')) {
            $json_options = $json_options | JSON_UNESCAPED_SLASHES;
        }
        $encoded_details = json_encode($details_payload, $json_options);
        if (is_string($encoded_details) && $encoded_details !== '') {
            $details = $encoded_details;
        } else {
            $details = print_r($details_payload, true);
        }
    } else {
        $details = (string)$details;
    }

    if (!is_array($context)) {
        // Legacy style: logAction('type', 'details', 'module_name')
        // or logAction('type', 'details', 123)
        $context = array('legacy_context' => (string)$context);
    }

    if (isset($legacy_args[0])) {
        $context['legacy_args'] = sanitizeActionLogData($legacy_args);
    }

    $fields_map = getActionsLogAvailableFields();
    if (!isset($fields_map['ID'])) {
        return;
    }

    global $session;
    $rec = array();
    $rec['ADDED'] = date('Y-m-d H:i:s');
    if (isset($session->data['SITE_USERNAME']) && $session->data['SITE_USERNAME'] !== '') {
        $rec['USER'] = $session->data['SITE_USERNAME'];
    } elseif (isset($_SERVER['REQUEST_URI']) && preg_match('/^\/admin\.php/', $_SERVER['REQUEST_URI'])) {
        $rec['USER'] = 'Control Panel';
    } else {
        $rec['USER'] = '';
    }
    if (isset($session->data['TERMINAL'])) {
        $rec['TERMINAL'] = $session->data['TERMINAL'];
    } else {
        $rec['TERMINAL'] = '';
    }
    $rec['ACTION_TYPE'] = (string)$action_type;
    $rec['TITLE'] = mb_substr($details, 0, 250, 'utf-8');
    $rec['IP'] = isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '';

    if (isset($fields_map['SOURCE'])) {
        $rec['SOURCE'] = mb_substr(actionLogDetectSource($context), 0, 50, 'utf-8');
    }
    if (isset($fields_map['MODULE'])) {
        $module_name = isset($context['module']) ? (string)$context['module'] : '';
        if ($module_name === '' && isset($_REQUEST['action'])) {
            $module_name = (string)$_REQUEST['action'];
        }
        $rec['MODULE'] = mb_substr($module_name, 0, 100, 'utf-8');
    }
    if (isset($fields_map['VIEW_MODE'])) {
        $view_mode = isset($context['view_mode']) ? (string)$context['view_mode'] : '';
        if ($view_mode === '' && isset($_REQUEST['view_mode'])) {
            $view_mode = (string)$_REQUEST['view_mode'];
        }
        $rec['VIEW_MODE'] = mb_substr($view_mode, 0, 100, 'utf-8');
    }
    if (isset($fields_map['OBJECT_TYPE'])) {
        $rec['OBJECT_TYPE'] = mb_substr(isset($context['object_type']) ? (string)$context['object_type'] : '', 0, 50, 'utf-8');
    }
    if (isset($fields_map['OBJECT_ID'])) {
        $object_id = isset($context['object_id']) ? (string)$context['object_id'] : '';
        if ($object_id === '' && isset($_REQUEST['id'])) {
            $object_id = (string)$_REQUEST['id'];
        }
        $rec['OBJECT_ID'] = mb_substr($object_id, 0, 100, 'utf-8');
    }
    if (isset($fields_map['OBJECT_TITLE'])) {
        $rec['OBJECT_TITLE'] = mb_substr(isset($context['object_title']) ? (string)$context['object_title'] : '', 0, 255, 'utf-8');
    }
    if (isset($fields_map['URL'])) {
        $url = isset($context['url']) ? (string)$context['url'] : '';
        if ($url === '' && isset($_SERVER['REQUEST_URI'])) {
            $url = (string)$_SERVER['REQUEST_URI'];
        }
        $rec['URL'] = mb_substr($url, 0, 255, 'utf-8');
    }
    if (isset($fields_map['REQUEST_METHOD'])) {
        $method = isset($context['request_method']) ? (string)$context['request_method'] : '';
        if ($method === '' && isset($_SERVER['REQUEST_METHOD'])) {
            $method = (string)$_SERVER['REQUEST_METHOD'];
        }
        if ($method === '' && php_sapi_name() === 'cli') {
            $method = 'CLI';
        }
        $rec['REQUEST_METHOD'] = mb_substr($method, 0, 10, 'utf-8');
    }
    if (isset($fields_map['RESULT'])) {
        $result = isset($context['result']) ? (string)$context['result'] : 'ok';
        $rec['RESULT'] = mb_substr($result, 0, 20, 'utf-8');
    }
    if (isset($fields_map['REQUEST_ID'])) {
        $rec['REQUEST_ID'] = mb_substr(getActionLogRequestId($context), 0, 64, 'utf-8');
    }
    if (isset($fields_map['REFERER'])) {
        $referer = isset($context['referer']) ? (string)$context['referer'] : '';
        if ($referer === '' && isset($_SERVER['HTTP_REFERER'])) {
            $referer = (string)$_SERVER['HTTP_REFERER'];
        }
        $rec['REFERER'] = mb_substr($referer, 0, 255, 'utf-8');
    }
    if (isset($fields_map['USER_AGENT'])) {
        $user_agent = isset($context['user_agent']) ? (string)$context['user_agent'] : '';
        if ($user_agent === '' && isset($_SERVER['HTTP_USER_AGENT'])) {
            $user_agent = (string)$_SERVER['HTTP_USER_AGENT'];
        }
        $rec['USER_AGENT'] = mb_substr($user_agent, 0, 255, 'utf-8');
    }
    if (isset($fields_map['DETAILS_JSON'])) {
        $context_data = $context;
        unset($context_data['source'], $context_data['module'], $context_data['view_mode'], $context_data['object_type'], $context_data['object_id'], $context_data['object_title'], $context_data['url'], $context_data['request_method'], $context_data['result'], $context_data['request_id'], $context_data['referer'], $context_data['user_agent']);
        $context_data = sanitizeActionLogData($context_data);
        if (!empty($context_data)) {
            $options = 0;
            if (defined('JSON_UNESCAPED_UNICODE')) {
                $options = $options | JSON_UNESCAPED_UNICODE;
            }
            if (defined('JSON_UNESCAPED_SLASHES')) {
                $options = $options | JSON_UNESCAPED_SLASHES;
            }
            $encoded = json_encode($context_data, $options);
            if (is_string($encoded) && $encoded !== '') {
                $rec['DETAILS_JSON'] = mb_substr($encoded, 0, 65535, 'utf-8');
            }
        }
    }

    SQLInsert('actions_log', $rec);
}

/**
 * Summary of registerError
 * @param mixed $code Code (default 'custom')
 * @param mixed $details Details (default '')
 * @return void
 */
function registerError($code = 'custom', $details = '')
{

    $e = new \Exception;
    $backtrace = $e->getTraceAsString();
    if (isset($_SERVER['REQUEST_URI'])) {
        $url = $_SERVER['REQUEST_URI'];
    } else {
        $url = 'n/a';
    }
    DebMes("Error registered (type: $code):\n" . $details . "\nURL: $url\nBacktrace:\n" . $backtrace, 'errors');
    $code = trim($code);

    if ($code == 'sql') {
        return 0;
    }

    $error_rec = SQLSelectOne("SELECT * FROM system_errors WHERE CODE = '" . DBSafe($code) . "'");

    if (!$error_rec['ID']) {
        $error_rec['CODE'] = $code;
        $error_rec['KEEP_HISTORY'] = 1;
        $error_rec['ID'] = SQLInsert('system_errors', $error_rec);
    }

    $error_rec['LATEST_UPDATE'] = date('Y-m-d H:i:s');
    @$error_rec['ACTIVE'] = (int)$error_rec['ACTIVE'] + 1;
    SQLUpdate('system_errors', $error_rec);

    $history_rec = array();
    $history_rec['ERROR_ID'] = $error_rec['ID'];
    $history_rec['COMMENTS'] = $details . "\nBacktrace:\n" . $backtrace;
    $history_rec['ADDED'] = $error_rec['LATEST_UPDATE'];

    // Request context helps to understand where a registered error came from.
    $context_lines = array();
    if ($url !== '' && $url !== 'n/a') {
        $context_lines[] = 'URL: ' . str_replace(array("\r", "\n"), '', $url);
    }
    if (!empty($_SERVER['REQUEST_METHOD'])) {
        $context_lines[] = 'Method: ' . str_replace(array("\r", "\n"), '', (string)$_SERVER['REQUEST_METHOD']);
    }
    if (!empty($_SERVER['REMOTE_ADDR'])) {
        $context_lines[] = 'IP: ' . str_replace(array("\r", "\n"), '', (string)$_SERVER['REMOTE_ADDR']);
    }
    if (!empty($_SERVER['HTTP_REFERER'])) {
        $context_lines[] = 'Referer: ' . str_replace(array("\r", "\n"), '', (string)$_SERVER['HTTP_REFERER']);
    }
    if (count($context_lines)) {
        $history_rec['COMMENTS'] .= "\nContext:\n" . implode("\n", $context_lines);
    }

    //Temporary disabled
    /*
    $xrayUrl = BASE_URL . ROOTHTML.'popup/xray.html?ajax=1&md=xray&op=getcontent&view_mode=';
    $history_rec['PROPERTIES_DATA'] = getURL($xrayUrl, 0);
    $history_rec['METHODS_DATA']    = getURL($xrayUrl . 'methods', 0);
    $history_rec['SCRIPTS_DATA']    = getURL($xrayUrl . 'scripts', 0);
    $history_rec['TIMERS_DATA']     = getURL($xrayUrl . 'timers', 0);
    $history_rec['EVENTS_DATA']     = getURL($xrayUrl . 'events', 0);
    $history_rec['DEBUG_DATA']      = getURL($xrayUrl . 'debmes', 0);
     */
    $history_rec['ID'] = SQLInsert('system_errors_data', $history_rec);

    if (!$error_rec['KEEP_HISTORY']) {
        SQLExec("DELETE FROM system_errors_data WHERE ERROR_ID=" . (int)$error_rec['ID'] . " AND ID != '" . $history_rec['ID'] . "'");
    } elseif (defined('SETTINGS_ERRORS_KEEP_HISTORY') && SETTINGS_ERRORS_KEEP_HISTORY > 0) {
        SQLExec("DELETE FROM system_errors_data WHERE ADDED<'" . date('Y-m-d H:i:s', time() - SETTINGS_ERRORS_KEEP_HISTORY * 24 * 60 * 60) . "'");
    } else {
        $tmp = SQLSelect("SELECT ID FROM system_errors_data WHERE ERROR_ID=" . (int)$error_rec['ID'] . " ORDER BY ID DESC LIMIT 50");
        if ($tmp[0]['ID'] && count($tmp) == 50) {
            $tmp = array_reverse($tmp);
            SQLExec("DELETE FROM system_errors_data WHERE ERROR_ID=" . (int)$error_rec['ID'] . " AND ID<" . $tmp[0]['ID']);
        }

    }
}

function sanitizeActionLogData($data, $key = '')
{
    $key_string = is_string($key) ? $key : '';
    if ($key_string !== '' && preg_match('/pass|password|token|secret|auth|cookie|session|private|apikey|api_key|key/i', $key_string)) {
        return '***';
    }

    if (is_array($data)) {
        $result = array();
        foreach ($data as $k => $v) {
            $result[$k] = sanitizeActionLogData($v, (string)$k);
        }
        return $result;
    }

    if (is_object($data)) {
        $converted = (array)$data;
        return sanitizeActionLogData($converted, $key_string);
    }

    if (is_string($data)) {
        return mb_substr($data, 0, 2000, 'utf-8');
    }

    return $data;
}

function verbose_log($data)
{
    if (defined('VERBOSE_LOG') && VERBOSE_LOG == 1) {
        if (defined('VERBOSE_LOG_IGNORE') && VERBOSE_LOG_IGNORE != '') {
            $tmp = explode(',', VERBOSE_LOG_IGNORE);
            $total = count($tmp);
            for ($i = 0; $i < $total; $i++) {
                $regex = trim($tmp[$i]);
                if (preg_match('/' . $regex . '/is', $data)) {
                    return;
                }
            }
        }
        global $verbose_thread_id;
        global $argv;
        if (!isset($verbose_thread_id)) {
            $verbose_thread_id = date('H:i:s') . '_' . rand(1000, 9999);
            $cmd = '';
            if (isset($_SERVER['REQUEST_URI']) && $_SERVER['REQUEST_URI'] != '') {
                $cmd = $_SERVER['REQUEST_METHOD'] . ' ' . $_SERVER['REQUEST_URI'];
            } elseif (isset($argv[0]) && $argv[0] != '') {
                $cmd = implode(' ', $argv);
                $verbose_thread_id .= '_' . basename($argv[0]);
            }
            DebMes('th_' . $verbose_thread_id . ' start ' . $cmd, 'verbose');
        }
        $bt = debug_backtrace();
        $total_bt = count($bt);
        $max_bt = 5;
        //$bt = array_reverse($bt);
        $bt = array_slice($bt, 1, $max_bt);
        $total = count($bt);
        if ($total > 0) {
            $res_trace = array();
            for ($i = 0; $i < $total; $i++) {
                $res_trace[] = $bt[$i]['function'];
            }
            if ($total_bt > ($max_bt + 1)) {
                $res_trace[] = '...';
            }
            $data = $data . ' (' . implode('<', $res_trace) . ')';
        }
        DebMes('th_' . $verbose_thread_id . ' ' . $data, 'verbose');
    }
}
