<?php

/** System helpers. Public names and argument defaults are part of the module API. */
// 2026-10-22 — runScriptSafe передаёт исходное имя в очередь ядра без API.

/**
 * Summary of callScript
 * @param mixed $id ID
 * @param mixed $params Params (default '')
 * @return mixed
 */
function callScript($id, $params = '')
{
    return runScript($id, $params);
}

/**
 * Summary of runScript
 * @param mixed $id ID
 * @param mixed $params Params (default '')
 * @return mixed
 */
function runScript($id, $params = '')
{
    include_once(DIR_MODULES . 'scripts/scripts.class.php');
    $sc = new scripts();
    return $sc->runScript($id, $params);
}

function runScriptSafe($id, $params = 0)
{
    startMeasure('runScriptSafe');
    $current_call = 'script.' . $id;
    $call_stack = array();
    $raiseEvent = '';
    $run_SafeScript = '';

    if (is_array($params)) {
        if (isset($params['m_c_s']) && is_array($params['m_c_s']) && !empty($params['m_c_s'])) {
            $call_stack = $params['m_c_s'];
        }
        if (isset($params['r_s_s']) && !empty($params['r_s_s'])) {
            $run_SafeScript = $params['r_s_s'];
        }
        $raiseEvent = isset($params['raiseEvent']) ? $params['raiseEvent'] : '';
        unset($params['raiseEvent']);
        unset($params['r_s_m']);
        unset($params['m_c_s']);
        $current_call .= '.' . md5(json_encode($params));
    }
    if (isset($_SERVER['REQUEST_URI']) && ($_SERVER['REQUEST_URI'] != '')) {
        if (isset($_GET['m_c_s']) && is_array($_GET['m_c_s']) && !empty($_GET['m_c_s'])) {
            $call_stack = $_GET['m_c_s'];
        }
        $raiseEvent = isset($_GET['raiseEvent']) ? $_GET['raiseEvent'] : '';
        $run_SafeScript = isset($_GET['r_s_s']) ? $_GET['r_s_s'] : false;
        if (is_array($call_stack) && in_array($current_call, $call_stack)) {
            $call_stack[] = $current_call;
            DebMes("Warning: cross-linked call of " . $current_call . "\nlog:\n" . implode(" -> \n", $call_stack), 'scripts', 'warning');
            return 0;
        }
    }

    if (!is_array($params)) {
        $params = array();
    }

    $audit = class_exists('\\MajorDoMo\\Core\\Core') ? \MajorDoMo\Core\Core::audit() : null;
    $auditOwned = $audit ? $audit->beginIfMissing('script', (string)$id, $params) : false;
    if ($audit) \MajorDoMo\Core\PropertyAudit::attach($params);

    $call_stack[] = $current_call;
    $params['raiseEvent'] = $raiseEvent;
    $params['m_c_s'] = $call_stack;
    $params['r_s_s'] = $run_SafeScript;

    try {
        if (isset($_SERVER['REQUEST_URI']) && ($_SERVER['REQUEST_URI'] != '') && !$raiseEvent && $run_SafeScript) {
            $result = runScript($id, $params);
        } else {
            $params['r_s_s'] = 1;
            $result = \MajorDoMo\Core\Core::queue()->enqueueRuntime('script', $params, (string)$id);
        }
    } catch (Throwable $e) {
        if ($auditOwned) {
            $audit?->finishRun('error', null, $e);
            $audit?->leave();
        }
        throw $e;
    } finally {
        if ($auditOwned && \MajorDoMo\Core\PropertyAudit::hasTrace()) {
            $audit?->finishRun('done');
            $audit?->leave();
        }
    }
    endMeasure('runScriptSafe');
    return $result;
}
