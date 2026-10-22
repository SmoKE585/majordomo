<?php

namespace MajorDoMo\Core;

use \Throwable;

final class Methods
{
    public function __construct(private Database $db, private MetadataCache $metadata, private CodeExecutor $code) {}

    public function resolve($object, $name, $class_id, $id)
    {
        $record = $this->metadata->get('methods-resolution:' . (int)$class_id . ':' . (int)$id . ':' . strtolower($name), function () use ($name, $class_id, $id) {
                if ($id) {
                    $row = $this->db->one("SELECT ID FROM methods WHERE OBJECT_ID=" . (int)$id . " AND TITLE='" . $this->db->escape($name) . "' LIMIT 1");
                    if (isset($row['ID'])) return $row;
                }
                $visited = array();
                while ($class_id && !isset($visited[$class_id])) {
                    $visited[$class_id] = true;
                    $row = $this->db->one("SELECT ID FROM methods WHERE CLASS_ID=" . (int)$class_id . " AND OBJECT_ID=0 AND TITLE='" . $this->db->escape($name) . "' LIMIT 1");
                    if (isset($row['ID'])) return $row;
                    $class = $this->db->one("SELECT PARENT_ID FROM classes WHERE ID=" . (int)$class_id);
                    $class_id = (int)($class['PARENT_ID'] ?? 0);
                }
                return array();
            });
        return $record['ID'] ?? false;

    }

    public function call($object, $name, $params = 0, $parentClassId = 0)
    {
        if (!$parentClassId) {
            verbose_log("Method [" . $object->object_title . ".$name] (" . (is_array($params) ? json_encode($params) : '') . ")");
            //dprint("Method [" . $object->object_title . ".$name] (" . (is_array($params) ? json_encode($params) : '') . ")",false);
        } else {
            verbose_log("Class method [" . $object->class_title . '/' . $object->object_title . ".$name] (" . (is_array($params) ? json_encode($params) : '') . ")");
            //dprint("Class method [" . $object->class_title . '/' . $object->object_title . ".$name] (" . (is_array($params) ? json_encode($params) : '') . ")",false);
        }
        //debug_print_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        //echo "<hr>";
        startMeasure('callMethod');

        $name = trim($name);
        $original_method_name = $object->object_title . '.' . $name;

        startMeasure('callMethod (' . $original_method_name . ')');

        if (!$parentClassId) {
            $id = $this->resolve($object, $name, $object->class_id, $object->id);
            $parentClassId = $object->class_id;
        } else {
            $id = $this->resolve($object, $name, $parentClassId, 0);
        }

        if ($id) {

            $source = '';
            $method = $this->metadata->get('method:' . (int)$id, function () use ($id) { return $this->db->one("SELECT * FROM methods WHERE ID=" . (int)$id); });
            $update_rec = array('ID' => $method['ID']);
            $update_rec['EXECUTED'] = date('Y-m-d H:i:s');
            if (defined('CALL_SOURCE')) {
                $source = CALL_SOURCE;
            } elseif (isset($_SERVER['REQUEST_URI'])) {
                $source = urldecode($_SERVER['REQUEST_URI']);
            }
            if (mb_strlen($source) > 250) {
                $source = mb_substr($source, 0, 250) . '...';
            }
            $update_rec['EXECUTED_SRC'] = $source;

            if (!$method['OBJECT_ID']) {
                if (!$params) {
                    $params = array();
                }
                $params['ORIGINAL_OBJECT_TITLE'] = $object->object_title;
            }

            if ($params) {
                $saved_params = $params;
                unset($params['r_s_m']);
                unset($saved_params['m_c_s']);
                unset($saved_params['SOURCE']);
                $update_rec['EXECUTED_PARAMS'] = json_encode($saved_params, JSON_UNESCAPED_UNICODE);
                if (mb_strlen($update_rec['EXECUTED_PARAMS']) > 250) {
                    $update_rec['EXECUTED_PARAMS'] = mb_substr($update_rec['EXECUTED_PARAMS'], 0, 250);
                }
            }
            $this->db->update('methods', $update_rec);

            if ($method['OBJECT_ID'] && $method['CALL_PARENT'] == 1) {
                // call class method
                startMeasure('callParentMethod');
                $parent_success = $this->call($object, $name, $params, $object->class_id);
                endMeasure('callParentMethod');
            } elseif ($method['CALL_PARENT'] == 1) {
                $parentClass = $this->db->one("SELECT ID, PARENT_ID FROM classes WHERE ID=" . (int)$parentClassId);
                if ($parentClass['PARENT_ID']) {
                    startMeasure('callParentMethod');
                    $parent_success = $this->call($object, $name, $params, $parentClass['PARENT_ID']);
                    endMeasure('callParentMethod');
                }
            }

            if ($method['SCRIPT_ID']) {
                /*
                 $script=$this->db->one("SELECT * FROM scripts WHERE ID='".$method['SCRIPT_ID']."'");
                 $code=$script['CODE'];
                */
                runScriptSafe($method['SCRIPT_ID']);
            } else {
                $code = $method['CODE'];
            }

            if (isset($code) && $code != '') {
                if (Config::has('PYTHON_PATH') and isItPythonCode($code)) {
                    echo($code);
                    python_run_code($code, $params, $object->object_title);
                } else {
                    try {
                        setEvalCode($code);
                        $variables = array('params' => &$params, 'name' => $name, 'method' => $method, 'parent_success' => &$parent_success, 'parentClassId' => $parentClassId);
                        $success = $this->code->evaluate($object, $code, $variables);
                        setEvalCode();
                        if ($success === false) {
                            //getLogger($this)->error(sprintf('Error in "%s.%s" method.', $object->object_title, $name));
                            registerError('method', sprintf('Exception in "%s.%s" method.', $object->object_title, $name));
                        }
                    } catch (Throwable $e) {
                        //getLogger($this)->error(sprintf('Exception in "%s.%s" method', $object->object_title, $name), $e);
                        registerError('method', sprintf('Exception in "%s.%s" method ' . $e->getMessage(), $object->object_title, $name));
                        throw $e;
                    }
                }
            }
            endMeasure('callMethod', 1);
            endMeasure('callMethod (' . $original_method_name . ')', 1);
            if ($method['OBJECT_ID'] && $method['CALL_PARENT'] == 2) {
                startMeasure('callParentMethod');
                $parent_success = $this->call($object, $name, $params, $object->class_id);
                endMeasure('callParentMethod');
            } elseif ($method['CALL_PARENT'] == 2) {
                $parentClass = $this->db->one("SELECT ID, PARENT_ID FROM classes WHERE ID=" . (int)$parentClassId);
                if ($parentClass['PARENT_ID']) {
                    startMeasure('callParentMethod');
                    $parent_success = $this->call($object, $name, $params, $parentClass['PARENT_ID']);
                    endMeasure('callParentMethod');
                }
            } else {
                $parent_success = true;
            }

            if (isset($success)) {
                return $success;
            } else {
                return $parent_success;
            }

        } else {
            endMeasure('callMethod (' . $original_method_name . ')', 1);
            endMeasure('callMethod', 1);
            return false;
        }

    }

    public function callSafe($object, $name, $params = 0)
    {
        startMeasure('callMethodSafe');
        $current_call = $object->object_title . '.' . $name;
        $call_stack = array();
        $raiseEvent = '';
        $run_SafeMethod = '';

        if (is_array($params)) {
            if (isset($params['m_c_s']) && is_array($params['m_c_s']) && !empty($params['m_c_s'])) {
                $call_stack = $params['m_c_s'];
                unset($params['m_c_s']);
            }
            if (isset($params['r_s_m']) && !empty($params['r_s_m'])) {
                $run_SafeMethod = $params['r_s_m'];
                unset($params['r_s_m']);
            }
            if (isset($params['raiseEvent']) && !empty($params['raiseEvent'])) {
                $raiseEvent = $params['raiseEvent'];
                unset($params['raiseEvent']);
            }
            $current_call .= '.' . md5(json_encode($params));
        }
        if (isset($_SERVER['REQUEST_URI']) && ($_SERVER['REQUEST_URI'] != '')) {
            if (isset($_GET['m_c_s']) && is_array($_GET['m_c_s']) && !empty($_GET['m_c_s'])) {
                $call_stack = $_GET['m_c_s'];
            }
            if (isset($_GET['raiseEvent']) && !empty($_GET['raiseEvent'])) {
                $raiseEvent = $_GET['raiseEvent'];
            } else {
                $raiseEvent = '';
            }
            if (isset($_GET['r_s_m']) && !empty($_GET['r_s_m'])) {
                $run_SafeMethod = $_GET['r_s_m'];
            } else {
                $run_SafeMethod = '';
            }
        }

        if (is_array($call_stack) && in_array($current_call, $call_stack)) {
            $call_stack[] = $current_call;
            DebMes("Warning: cross-linked call of " . $current_call . "\nlog:\n" . implode(" -> \n", $call_stack), 'methods', 'warning');
            return 0;
        }

        if (!is_array($params)) {
            $params = array();
        }

        // A method invoked from a module/ONCHANGE handler must carry the
        // property chain into the queue, otherwise a cross-link can restart
        // from an empty chain in the worker process.
        $chain = PropertyChain::current();
        if ($chain && !isset($params['_chain'])) $params['_chain'] = $chain;

        $call_stack[] = $current_call;
        $params['raiseEvent'] = $raiseEvent;
        $params['m_c_s'] = $call_stack;
        $params['r_s_m'] = $run_SafeMethod;
        if (isset($_SERVER['REQUEST_URI']) && ($_SERVER['REQUEST_URI'] != '') && ((!$raiseEvent && $run_SafeMethod) || Config::bool('LOWER_BACKGROUND_PROCESSES'))) {
            $result = $this->call($object, $name, $params);
        } else {
            $params['r_s_m'] = 1;
            $result = callAPI('/api/method/' . urlencode($object->object_title . '.' . $name), 'GET', $params);
        }
        endMeasure('callMethodSafe');
        return $result;

    }
}
