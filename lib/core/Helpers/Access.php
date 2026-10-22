<?php

/** System helpers. Public names and argument defaults are part of the module API. */

/**
 * Summary of checkAccess
 * @param mixed $object_type Object type
 * @param mixed $object_id Object ID
 * @return bool
 */
function checkAccess($object_type, $object_id)
{

    global $access_rules_cached;
    global $session;

    startMeasure('checkAccess');

    if (!isset($access_rules_cached)) {
        $all_rules = SQLSelect("SELECT OBJECT_TYPE, OBJECT_ID FROM security_rules");
        foreach ($all_rules as $rule) {
            $access_rules_cached[$rule['OBJECT_TYPE'] . $rule['OBJECT_ID']] = 1;
        }
    }

    if (!isset($access_rules_cached[$object_type . $object_id])) {
        endMeasure('checkAccess');
        return true;
    }

    $rule = SQLSelectOne("SELECT * FROM security_rules WHERE OBJECT_TYPE='" . DBSafe($object_type) . "' AND OBJECT_ID='" . (int)$object_id . "'");
    if (!isset($rule['ID']) || !$rule['ID']) {
        endMeasure('checkAccess');
        return true;
    }

    if (isset($rule['TIMES']) && $rule['TIMES']) {
        $hours_matched = false;
        $tmp = explode(',', $rule['TIMES']);
        $total = count($tmp);
        for ($i = 0; $i < $total; $i++) {
            $tmp2 = explode('-', $tmp[$i]);
            if (timeBetween($tmp2[0], $tmp2[1])) {
                $hours_matched = true;
            }
        }
        if (!$hours_matched && !$rule['TIMES_EXCEPT']) {
            endMeasure('checkAccess');
            return false;
        } elseif ($hours_matched && $rule['TIMES_EXCEPT']) {
            endMeasure('checkAccess');
            return false;
        }
    }

    if (isset($rule['USERS']) && $rule['USERS']) {
        $users_matched = false;
        if ($session->data['SITE_USERNAME'] && !$session->data['SITE_USER_ID']) {
            $user = SQLSelectOne("SELECT ID FROM users WHERE USERNAME='" . DBSafe($session->data['SITE_USERNAME']) . "'");
            if ($user['ID']) {
                $session->data['SITE_USER_ID'] = $user['ID'];
            }
        }
        $user_id = (int)$session->data['SITE_USER_ID'];
        $tmp = explode(',', $rule['USERS']);
        if (in_array($user_id, $tmp)) {
            $users_matched = true;
        }
        if (!$users_matched && !$rule['USERS_EXCEPT']) {
            endMeasure('checkAccess');
            return false;
        } elseif ($users_matched && $rule['USERS_EXCEPT']) {
            endMeasure('checkAccess');
            return false;
        }
    }

    if (isset($rule['TERMINALS']) && $rule['TERMINALS']) {
        $terminals_matched = false;
        if ($session->data['TERMINAL']) {
            $terminal = getTerminalsByName($session->data['TERMINAL'], 1);
            if ($terminal[0]['ID']) {
                $session->data['TERMINAL_ID'] = $terminal[0]['ID'];
            }
        }
        $terminal_id = (int)$session->data['TERMINAL_ID'];
        $tmp = explode(',', $rule['TERMINALS']);
        if (in_array($terminal_id, $tmp)) {
            $terminals_matched = true;
        }
        if (!$terminals_matched && !$rule['TERMINALS_EXCEPT']) {
            endMeasure('checkAccess');
            return false;
        } elseif ($terminals_matched && $rule['TERMINALS_EXCEPT']) {
            endMeasure('checkAccess');
            return false;
        }
    }

    if ($rule['CONDITION_ACTIVE'] && $rule['CONDITION_LINKED_OBJECT'] && $rule['CONDITION_LINKED_PROPERTY']) {
        $value = getGlobal($rule['CONDITION_LINKED_OBJECT'] . '.' . $rule['CONDITION_LINKED_PROPERTY']);
        if (($rule['CONDITION'] == 2 || $rule['CONDITION'] == 3)
            && $rule['CONDITION_VALUE'] != ''
            && !is_numeric($rule['CONDITION_VALUE'])
            && !preg_match('/^%/', $rule['CONDITION_VALUE'])) {
            $rule['CONDITION_VALUE'] = '%' . $rule['CONDITION_VALUE'] . '%';
        }

        if (is_integer(strpos($rule['CONDITION_VALUE'], "%"))) {
            $rule['CONDITION_VALUE'] = processTitle($rule['CONDITION_VALUE']);
        }

        $result = 0;
        if ($rule['CONDITION'] == 1 && $value == $rule['CONDITION_VALUE']) {
            $result = 1;
        } elseif ($rule['CONDITION'] == 2 && (float)$value > (float)$rule['CONDITION_VALUE']) {
            $result = 1;
        } elseif ($rule['CONDITION'] == 3 && (float)$value < (float)$rule['CONDITION_VALUE']) {
            $result = 1;
        } elseif ($rule['CONDITION'] == 4 && $value != $rule['CONDITION_VALUE']) {
            $result = 1;
        } elseif ($rule['CONDITION'] == 5) {
            $result = 1;
        }
        if (!$result) {
            endMeasure('checkAccess');
            return false;
        }
    }

    if ($rule['CONDITION_ACTIVE'] && isset($rule['CONDITIONS']) && $rule['CONDITIONS'] != '') {
        $conditions = json_decode($rule['CONDITIONS'], true);
        if (is_array($conditions)) {
            $total = count($conditions);
            for ($i = 0; $i < $total; $i++) {
                $value = getGlobal($conditions[$i]['LINKED_OBJECT'] . '.' . $conditions[$i]['LINKED_PROPERTY']);
                $condition_value = $conditions[$i]['VALUE'];
                if (($conditions[$i]['CONDITION'] == 2 || $conditions[$i]['CONDITION'] == 3)
                    && $condition_value != ''
                    && !is_numeric($condition_value)
                    && !preg_match('/^%/', $condition_value)) {
                    $condition_value = '%' . $condition_value . '%';
                }

                if (is_integer(strpos($condition_value, "%"))) {
                    $condition_value = processTitle($condition_value);
                }

                $result = 0;
                if ($conditions[$i]['CONDITION'] == 1 && $value == $condition_value) {
                    $result = 1;
                } elseif ($conditions[$i]['CONDITION'] == 2 && (float)$value > (float)$condition_value) {
                    $result = 1;
                } elseif ($conditions[$i]['CONDITION'] == 3 && (float)$value < (float)$condition_value) {
                    $result = 1;
                } elseif ($conditions[$i]['CONDITION'] == 4 && $value != $condition_value) {
                    $result = 1;
                } elseif ($conditions[$i]['CONDITION'] == 5) {
                    $result = 1;
                }
                if (!$result) {
                    endMeasure('checkAccess');
                    return false;
                }
            }
        }
    }

    $result = true;
    endMeasure('checkAccess');
    return $result;
}

function checkAccessCopy($object_type, $src_id, $dst_id)
{
    $rec = SQLSelectOne("SELECT * FROM security_rules WHERE OBJECT_TYPE='" . DBSafe($object_type) . "' AND OBJECT_ID=" . (int)$src_id);
    if (isset($rec['ID']) && $rec['ID']) {
        SQLExec("DELETE FROM security_rules WHERE OBJECT_TYPE='" . DBSafe($object_type) . "' AND OBJECT_ID=" . (int)$dst_id);
        unset($rec['ID']);
        $rec['OBJECT_ID'] = (int)$dst_id;
        SQLInsert('security_rules', $rec);
    }
}

function checkAccessDefined($object_type, $object_id)
{
    $rec = SQLSelectOne("SELECT ID FROM security_rules WHERE OBJECT_TYPE='" . DBSafe($object_type) . "' AND OBJECT_ID=" . (int)$object_id);
    if (isset($rec['ID']) && $rec['ID']) return true;
    return false;
}
