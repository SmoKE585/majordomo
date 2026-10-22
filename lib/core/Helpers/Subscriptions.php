<?php

/** Подписки ядра. 2026-10-22 — фоновая обработка через очередь без HTTP. */

function processSubscriptionByModule($module_name, $event_name, &$details)
{
    $modulePath = DIR_MODULES . $module_name . '/' . $module_name . '.class.php';
    $output = '';
    if (file_exists($modulePath)) {
        include_once($modulePath);
        $module_object = new $module_name();
        if (method_exists($module_object, 'processSubscription')) {
            //DebMes("$module_name.processSubscription ($event_name)",'process_subscription');
            verbose_log("Processing subscription to [" . $event_name . "] by [" . $module_name . "] (" . (is_array($details) ? json_encode($details) : '') . ")");
            try {
                $output = $module_object->processSubscription($event_name, $details);
            } catch (Exception $e) {
                DebMes('Subscription ' . $module_name . '.' . $event_name . ': ' . get_class($e) . ': ' . $e->getMessage(), 'process_subscription', 'error');
            }
            //DebMes("$module_name.processSubscription ($event_name) DONE",'process_subscription');
        } else {
            //DebMes("$module_name.processSubscription error (method not found)", 'process_subscription');
        }
        unset($module_object);
    } else {
        //DebMes("$module_name.processSubscription error (module class not found)", 'process_subscription');
    }
    return $output;
}

/**
 * Summary of processSubscriptions
 * @param mixed $event_name Event name
 * @param mixed $details Details (default '')
 * @return int|void
 */
function processSubscriptions($event_name, $details = '', $return_output = false)
{
    if (!$return_output) {
        postToWebSocketQueue($event_name, $details, 'PostEvent');
    }
    if (!is_array($details)) {
        $details = array();
    }
    // Mode rules are editable at runtime. Deliver independently of immutable
    // SETTINGS_HOOK_EVENT_* constants cached by long-running legacy cycles.
    if (function_exists('modesProcessEvent')) modesProcessEvent((string)$event_name, $details);
    if (!defined('SETTINGS_HOOK_EVENT_' . strtoupper($event_name))) {
        return 0;
    }
    $data = json_decode(constant('SETTINGS_HOOK_EVENT_' . strtoupper($event_name)), true);
    if (is_array($data)) {
        $data2 = array();
        foreach ($data as $k => $v) {
            $priority = isset($v['priority']) ? $v['priority'] : 0;
            $filter = isset($v['filter']) ? $v['filter'] : '';
            $data2[] = array('module' => $k, 'filter' => $filter, 'priority' => $priority);
        }
        usort($data2, function ($a, $b) {
            if ($a['priority'] == $b['priority']) return 0;
            return ($a['priority'] > $b['priority']) ? -1 : 1;
        });
        $output = '';
        $total = count($data2);
        for ($i = 0; $i < $total; $i++) {
            $module_name = $data2[$i]['module'];
            $filter_details = $data2[$i]['filter'];
            $output .= processSubscriptionByModule($module_name, $event_name, $details);
            if (!isset($details['BREAK'])) {
                $details['BREAK'] = false;
            }
            if ($details['BREAK']) break;
        }

        if (!isset($details['PROCESSED'])) {
            $details['PROCESSED'] = false;
        }
        //if (!$details['PROCESSED'] && $event_name == 'COMMAND') { sayReplySafe(LANG_DEVICES_UNKNOWN_COMMAND,2);}
        if ($return_output) {
            return $output;
        } else {
            return (int)$details['PROCESSED'];
        }
    }
    return 0;

}

function processSubscriptionsSafe($event_name, $details = '')
{
    if (!is_array($details)) {
        $details = array();
    }
    return \MajorDoMo\Core\Core::queue()->enqueueRuntime('subscriptions', array(
        'event' => $event_name, 'details' => $details,
    )) ? 1 : 0;
}

/**
 * Summary of removeMissingSubscribers
 * @return void
 */
function removeMissingSubscribers()
{
    $settings = SQLSelect("SELECT * FROM settings WHERE NAME LIKE 'HOOK_EVENT_%' AND TYPE='json'");
    $total = count($settings);

    for ($i = 0; $i < $total; $i++) {
        $data = json_decode($settings[$i]['VALUE'], true);
        $changed = 0;

        if (is_array($data)) {
            foreach ($data as $k => $v) {
                $module_name = $k;
                if (!file_exists(DIR_MODULES . $module_name . '/' . $module_name . '.class.php')) {
                    unset($data[$module_name]);
                    $changed = 1;
                }
            }

            if ($changed) {
                $settings[$i]['VALUE'] = json_encode($data, JSON_UNESCAPED_UNICODE);
                SQLUpdate('settings', $settings[$i]);
            }
        }
    }
}

/**
 * Summary of subscribeToEvent
 * @param mixed $module_name Module name
 * @param mixed $event_name Event name
 * @param mixed $filter_details Filter details (default '')
 * @return void
 */
function subscribeToEvent($module_name, $event_name, $filter_details = '', $priority = 0)
{
    $rec = SQLSelectOne("SELECT * FROM settings WHERE NAME = 'HOOK_EVENT_" . DBSafe(strtoupper($event_name)) . "'");

    if (!isset($rec['ID']) || !$rec['ID']) {
        $rec = array();
        $rec['NAME'] = 'HOOK_EVENT_' . strtoupper($event_name);
        $rec['TITLE'] = $rec['NAME'];
        $rec['TYPE'] = 'json';
        $rec['NOTES'] = '';
        $rec['DATA'] = '';
        $rec['PRIORITY'] = 0;
        $rec['ID'] = SQLInsert('settings', $rec);
    }

    $data = json_decode(isset($rec['VALUE']) ? $rec['VALUE'] : '', true);
    if (!is_array($data)) $data = array();
    if (!isset($data[$module_name])) {
        $data[$module_name] = array();
    } else {
        return;
    }
    $data[$module_name]['filter'] = $filter_details;
    if ($priority) {
        $data[$module_name]['priority'] = $priority;
    }
    $rec['VALUE'] = json_encode($data, JSON_UNESCAPED_UNICODE);
    SQLUpdate('settings', $rec);
}

/**
 * Summary of unsubscribeFromEvent
 * @param mixed $module_name Module name
 * @param mixed $event_name Event name (default '')
 * @return void
 */
function unsubscribeFromEvent($module_name, $event_name = '')
{
    $sqlQuery = "SELECT *
                  FROM settings
                 WHERE NAME LIKE 'HOOK_EVENT_" . DBSafe(strtoupper($event_name)) . "'
                   AND TYPE = 'json'";

    $rec = SQLSelectOne($sqlQuery);

    if (isset($rec['ID'])) {
        $data = json_decode(isset($rec['VALUE']) ? $rec['VALUE'] : '', true);
        if (!is_array($data)) $data = array();

        if (isset($data[$module_name])) {
            unset($data[$module_name]);
            $rec['VALUE'] = json_encode($data, JSON_UNESCAPED_UNICODE);
            SQLUpdate('settings', $rec);
        }
    }
}
