<?php
/**
 * Objects
 *
 * Objects
 *
 * @package MajorDoMo
 * @author Serge Dzheigalo <sergejey@gmail.com> https://majordomohome.com/
 * @version 0.4 (wizard, 12:05:51 [May 22, 2009])
 */
//
//
class objects extends module
{

    /**
     * objects
     *
     * Module class constructor
     *
     * @access private
     */
    function __construct()
    {
        $this->name = "objects";
        $this->title = "<#LANG_MODULE_OBJECT_INSTANCES#>";
        $this->module_category = "<#LANG_SECTION_OBJECTS#>";
        $this->checkInstalled();
        $this->id = '';
    }

    /**
     * saveParams
     *
     * Saving module parameters
     *
     * @access public
     */
    function saveParams($data = 1)
    {
        $data = array();
        if (isset($this->id)) {
            $data["id"] = $this->id;
        }
        if (isset($this->view_mode)) {
            $data["view_mode"] = $this->view_mode;
        }
        if (isset($this->edit_mode)) {
            $data["edit_mode"] = $this->edit_mode;
        }
        if (isset($this->tab)) {
            $data["tab"] = $this->tab;
        }
        return parent::saveParams($data);
    }

    /**
     * getParams
     *
     * Getting module parameters from query string
     *
     * @access public
     */
    function getParams()
    {
        global $id;
        global $mode;
        global $view_mode;
        global $edit_mode;
        global $tab;
        global $ajax;
        if (isset($id)) {
            $this->id = $id;
        }
        if (isset($mode)) {
            $this->mode = $mode;
        }
        if (isset($view_mode)) {
            $this->view_mode = $view_mode;
        }
        if (isset($edit_mode)) {
            $this->edit_mode = $edit_mode;
        }
        if (isset($tab)) {
            $this->tab = $tab;
        }
        if (isset($ajax) && $ajax) {
            $this->ajax = 1;
        }
    }

    /**
     * Run
     *
     * Description
     *
     * @access public
     */
    function run()
    {
        global $session;
        $out = array();
        if ($this->action == 'admin') {
            $this->admin($out);
        } else {
            $this->usual($out);
        }
        if (isset($this->owner->action)) {
            $out['PARENT_ACTION'] = $this->owner->action;
        }
        if (isset($this->owner->name)) {
            $out['PARENT_NAME'] = $this->owner->name;
        }
        $out['VIEW_MODE'] = $this->view_mode;
        $out['EDIT_MODE'] = $this->edit_mode;
        $out['MODE'] = $this->mode;
        $out['ACTION'] = $this->action;
        $out['TAB'] = $this->tab;
        if ($this->single_rec) {
            $out['SINGLE_REC'] = 1;
        }
        $this->data = $out;
        $p = new parser(DIR_TEMPLATES . $this->name . "/" . $this->name . ".html", $this->data, $this);
        $this->result = $p->result;
    }

    /**
     * BackEnd
     *
     * Module backend
     *
     * @access public
     */
    function admin(&$out)
    {
        if ($this->ajax) {
            global $op;
            if ($op == 'property_history') {
                $this->renderPropertyHistoryJson();
            } elseif ($op == 'property_trace') {
                $this->renderPropertyTraceJson();
            }
        }

        if (isset($this->data_source) && !$_GET['data_source'] && !$_POST['data_source']) {
            $out['SET_DATASOURCE'] = 1;
        }
        if ($this->data_source == 'objects' || $this->data_source == '') {
            if ($this->view_mode == '' || $this->view_mode == 'search_objects') {
                $this->search_objects($out);
            }

            if ($this->view_mode == 'clone' && $this->id) {
                $this->clone_object($this->id);
            }


            if ($this->view_mode == 'edit_objects') {
                $this->edit_objects($out, $this->id);
            }
            if ($this->view_mode == 'delete_objects') {
                $this->delete_objects($this->id);
                $this->redirect("?");
            }
        }
    }

    /**
     * Title
     *
     * Description
     *
     * @access public
     */
    function clone_object($id)
    {

        $rec = SQLSelectOne("SELECT * FROM objects WHERE ID='" . $id . "'");
        $rec['TITLE'] = $rec['TITLE'] . ' (copy)';
        unset($rec['ID']);
        $rec['SYSTEM'] = '';
        $rec['ID'] = SQLInsert('objects', $rec);

        $seen_pvalues = array();
        $properties = SQLSelect("SELECT * FROM properties WHERE OBJECT_ID='" . $id . "'");
        $total = count($properties);
        for ($i = 0; $i < $total; $i++) {
            $p_id = $properties[$i]['ID'];
            unset($properties[$i]['ID']);
            $properties[$i]['OBJECT_ID'] = $rec['ID'];
            $properties[$i]['ID'] = SQLInsert('properties', $properties[$i]);
            $p_value = SQLSelectOne("SELECT * FROM pvalues WHERE PROPERTY_ID='" . $p_id . "'");
            if ($p_value['ID']) {
                $seen_pvalues[$p_value['ID']] = 1;
                unset($p_value['ID']);
                $p_value['PROPERTY_ID'] = $properties[$i]['ID'];
                $p_value['OBJECT_ID'] = $rec['ID'];
                SQLInsert('pvalues', $p_value);
            }
        }

        $pvalues = SQLSelect("SELECT * FROM pvalues WHERE OBJECT_ID='" . $id . "'");
        $total = count($properties);
        for ($i = 0; $i < $total; $i++) {
            $p_id = $pvalues[$i]['ID'];
            if ($seen_pvalues[$p_id]) {
                continue;
            }
            unset($pvalues[$i]['ID']);
            $pvalues[$i]['OBJECT_ID'] = $rec['ID'];
            $pvalues[$i]['ID'] = SQLInsert('pvalues', $pvalues[$i]);
        }

        $methods = SQLSelect("SELECT * FROM methods WHERE OBJECT_ID='" . $id . "'");
        $total = count($methods);
        for ($i = 0; $i < $total; $i++) {
            unset($methods[$i]['ID']);
            $methods[$i]['OBJECT_ID'] = $rec['ID'];
            $methods[$i]['ID'] = SQLInsert('methods', $methods[$i]);
        }

        $this->redirect("?view_mode=edit_objects&id=" . $rec['ID']);

    }

    /**
     * FrontEnd
     *
     * Module frontend
     *
     * @access public
     */
    function usual(&$out)
    {

        if ($this->ajax) {

            header("HTTP/1.0 200 OK\n");
            header('Content-Type: application/json; charset=utf-8');

            global $op;
            global $id;
            $res = array();
            if ($op == 'get_object') {
                $res = $this->processObject($id);
            } elseif ($op == 'property_history') {
                $property_id = (int)gr('property_id');
                $range = gr('history_range', 'trim');
                $page = (int)gr('history_page');
                $res = $this->buildPropertyHistoryResponse((int)$id, $property_id, $range, $page);
            } elseif ($op == 'property_trace') {
                $property_id = (int)gr('property_id');
                $range = gr('trace_range', 'trim');
                $page = (int)gr('trace_page');
                $res = $this->buildPropertyTraceResponse((int)$id, $property_id, $range, $page);
            }
            echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            global $db;
            exit;
        }

        if ($this->class) {
            $objects = getObjectsByClass($this->class);
            if (!$this->code) {
                $template = '#title# <i>#description#</i><br/>';
            } else {
                $template = $this->code;
            }
            $result = '';
            if ($objects[0]['ID']) {
                $total = count($objects);
                for ($i = 0; $i < $total; $i++) {
                    $objects[$i] = SQLSelectOne("SELECT * FROM objects WHERE ID='" . $objects[$i]['ID'] . "'");
                    $line = $template;
                    $line = preg_replace('/\#title\#/is', $objects[$i]['TITLE'], $line);
                    $line = preg_replace('/\#description\#/is', $objects[$i]['DESCRIPTION'], $line);
                    if (preg_match_all('/\#([\w\d_-]+?)\#/is', $line, $m)) {
                        $totalm = count($m[0]);
                        for ($im = 0; $im < $totalm; $im++) {
                            $property = trim($objects[$i]['TITLE'] . '.' . $m[1][$im]);
                            $line = str_replace($m[0][$im], getGlobal($property), $line);
                        }
                    }
                    $result .= $line;
                }
            }
            $out['RESULT'] = $result;
        }

    }

    /**
     * objects search
     *
     * @access public
     */
    function search_objects(&$out)
    {
        require(DIR_MODULES . $this->name . '/objects_search.inc.php');
    }

    /**
     * Readable label for pvalues.SOURCE.
     *
     * Properties::set() stores "CALL_SOURCE <request URI>", so the raw value is
     * usually a long URL. Printing it as-is broke the card layout, and making it
     * a link could repeat the request that changed the value.
     */
    private static function describePropertySource($source): array
    {
        $source = trim((string)preg_replace('/\s+/u', ' ', (string)$source));
        if ($source === '') {
            return array('label' => '', 'icon' => 'help-circle', 'kind' => '');
        }
        if (preg_match('/^Job:\s*(.+)$/u', $source, $match)) {
            $job = preg_split('/\s+(?:https?:\/\/|\/)/', $match[1]);
            $job = is_array($job) ? trim((string)$job[0]) : $match[1];
            return array('label' => 'Задание · ' . self::shortenSourceLabel($job), 'icon' => 'calendar-check', 'kind' => 'job');
        }
        if (preg_match('~(?:https?://|/)\S*~u', $source, $match)) {
            $url = $match[0];
            $path = (string)parse_url($url, PHP_URL_PATH);
            $host = (string)parse_url($url, PHP_URL_HOST);
            if ($path === '') {
                $path = '/';
            }
            if ($host !== '') {
                $path = $host . $path;
            }
            if (str_starts_with($path, '/panel/')) {
                return array('label' => 'Панель', 'icon' => 'layout-dashboard', 'kind' => 'panel');
            }
            if (str_starts_with($path, '/api/')) {
                return array('label' => 'API · ' . self::shortenSourceLabel($path), 'icon' => 'plug-connected', 'kind' => 'api');
            }
            if (str_contains($path, 'scripts')) {
                return array('label' => 'Сценарий · ' . self::shortenSourceLabel(basename($path)), 'icon' => 'code', 'kind' => 'script');
            }
            return array('label' => 'HTTP · ' . self::shortenSourceLabel($path), 'icon' => 'world', 'kind' => 'http');
        }
        return array('label' => self::shortenSourceLabel($source), 'icon' => 'info-circle', 'kind' => 'other');
    }

    private static function shortenSourceLabel($value): string
    {
        $value = trim((string)$value);
        return mb_strimwidth($value, 0, 56, '…', 'UTF-8');
    }

    /**
     * objects edit/add
     *
     * @access public
     */
    function edit_objects(&$out, $id)
    {
        require(DIR_MODULES . $this->name . '/objects_edit.inc.php');
    }

    /**
     * objects delete record
     *
     * @access public
     */
    function delete_objects($id)
    {
        $rec = SQLSelectOne("SELECT * FROM objects WHERE ID='$id'");

        if (!isset($rec['ID'])) return false;

        // DELETE LINKED OBJECT FROM ALL TABLES

        $tables = SQLSelect("SELECT DISTINCT TABLE_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE COLUMN_NAME IN ('LINKED_OBJECT') AND TABLE_SCHEMA='" . DB_NAME . "';");
        $total = count($tables);
        for ($i = 0; $i < $total; $i++) {
            $table_name = $tables[$i]['TABLE_NAME'];
            $rows = SQLSelect("SELECT * FROM $table_name WHERE LINKED_OBJECT = '" . DBSafe($rec['TITLE']) . "'");
            if (isset($rows[0]['ID'])) {
                $total_rows = count($rows);
                for ($ir = 0; $ir < $total_rows; $ir++) {
                    $rows[$ir]['LINKED_OBJECT'] = '';
                    if (isset($rows[$ir]['LINKED_PROPERTY'])) {
                        $rows[$ir]['LINKED_PROPERTY'] = '';
                    }
                    if (isset($rows[$ir]['LINKED_METHOD'])) {
                        $rows[$ir]['LINKED_METHOD'] = '';
                    }
                    SQLUpdate($table_name, $rows[$ir]);
                }
            }
        }

        // some action for related tables
        SQLExec("DELETE FROM history WHERE OBJECT_ID='" . $rec['ID'] . "'");
        SQLExec("DELETE FROM methods WHERE OBJECT_ID='" . $rec['ID'] . "'");
        $pvalues = SQLSelect("SELECT * FROM pvalues WHERE OBJECT_ID=" . $rec['ID']);
        foreach ($pvalues as $pvalue) {
            if (defined('SEPARATE_HISTORY_STORAGE') && SEPARATE_HISTORY_STORAGE == 1) {
                $history_table = createHistoryTable($pvalue['ID']);
            } else {
                $history_table = 'phistory';
            }
            SQLExec("DELETE FROM $history_table WHERE VALUE_ID=" . $pvalue['ID']);
            SQLExec("DELETE FROM pvalues WHERE ID='" . $pvalue['ID'] . "'");
        }
        SQLExec("DELETE FROM properties WHERE OBJECT_ID='" . $rec['ID'] . "'");
        SQLExec("DELETE FROM objects WHERE ID='" . $rec['ID'] . "'");
    }


    /**
     * Title
     *
     * Description
     *
     * @access public
     */
    function loadObject($id)
    {
        $rec = SQLSelectOne("SELECT * FROM objects WHERE ID=" . (int)$id);
        return $this->loadObjectRecord($rec);
    }

    function loadObjectRecord($rec)
    {
        if (isset($rec['ID'])) {
            $this->id = $rec['ID'];
            $this->object_title = $rec['TITLE'];
            $this->class_id = $rec['CLASS_ID'];
            if ($this->class_id) {
                $class_rec = \MajorDoMo\Core\Core::metadata()->get('class:' . $this->class_id, function () { return SQLSelectOne("SELECT ID,TITLE,PARENT_ID FROM classes WHERE ID=" . (int)$this->class_id); });
                $this->class_title = $class_rec['TITLE'] ?? '';
            }
            $this->description = $rec['DESCRIPTION'];
            $this->location_id = $rec['LOCATION_ID'];
            if (preg_match('/^sdevice(\d+)/', $rec['SYSTEM'], $m)) {
                $this->device_id = $m[1];
            }
        } else {
            return false;
        }

    }


    /**
     * Title
     *
     * Description
     *
     * @access public
     */
    function getParentProperties($id, $def = '', $include_self = 0)
    {
        startMeasure('getParentProperties');
        $class = SQLSelectOne("SELECT PARENT_ID FROM classes WHERE ID='" . (int)$id . "'");

        global $class_properties_cached;
        if (isset($class_properties_cached[$id])) {
            $properties = $class_properties_cached[$id];
        } else {
            $properties = SQLSelect("SELECT properties.*, classes.TITLE AS CLASS_TITLE FROM properties LEFT JOIN classes ON properties.CLASS_ID=classes.ID WHERE CLASS_ID='" . $id . "' AND OBJECT_ID=0");
            $class_properties_cached[$id] = $properties;
        }

        if ($include_self) {
            $res = $properties;
        } else {
            $res = array();
        }

        if (!is_array($def)) {
            $def = array();
            foreach ($properties as $p) {
                $def[] = $p['TITLE'];
            }
        }

        foreach ($properties as $p) {
            if (!in_array($p['TITLE'], $def)) {
                $res[] = $p;
                $def[] = $p['TITLE'];
            }
        }

        endMeasure('getParentProperties');
        if ($class['PARENT_ID']) {
            $p_res = $this->getParentProperties($class['PARENT_ID'], $def);
            if (isset($p_res[0]['ID'])) {
                $res = array_merge($res, $p_res);
            }
        }

        return $res;

    }

    function getParentMethods($id, $def = '', $include_self = 0)
    {
        $class = SQLSelectOne("SELECT PARENT_ID FROM classes WHERE ID='" . (int)$id . "'");

        $methods = SQLSelect("SELECT methods.*, classes.TITLE AS CLASS_TITLE FROM methods LEFT JOIN classes ON methods.CLASS_ID=classes.ID WHERE CLASS_ID='" . $id . "' AND OBJECT_ID=0");

        if ($include_self) {
            $res = $methods;
        } else {
            $res = array();
        }


        if (!is_array($def)) {
            $def = array();
            foreach ($methods as $p) {
                $def[] = $p['TITLE'];
            }
        }

        foreach ($methods as $p) {
            if (!in_array($p['TITLE'], $def)) {
                $res[] = $p;
                $def[] = $p['TITLE'];
            }
        }

        if ($class['PARENT_ID']) {
            $p_res = $this->getParentMethods($class['PARENT_ID'], $def);
            if ($p_res[0]['ID']) {
                $res = array_merge($res, $p_res);
            }
        }

        return $res;

    }


    /**
     * Title
     *
     * Description
     *
     * @access public
     */
    function getMethodByName($name, $class_id, $id)
    {
        // TODO: legacy compatibility facade; new code uses the service in lib/core/.
        return \MajorDoMo\Core\Core::methods()->resolve($this, $name, $class_id, $id);
    }

    /**
     * Title
     *
     * Description
     *
     * @access public
     */
    function raiseEvent($name, $params = 0, $parent = 0)
    {
        if (!is_array($params)) {
            $params = array();
        }
        $params['raiseEvent'] = '1';
        $this->callMethodSafe($name, $params);
    }

    function callClassMethod($name, $params = 0)
    {
        $this->callMethod($name, $params, 1);
    }

    function callMethodSafe($name, $params = 0)
    {
        // TODO: legacy compatibility facade; new code uses the service in lib/core/.
        return \MajorDoMo\Core\Core::methods()->callSafe($this, $name, $params);
    }

    /**
     * Title
     *
     * Description
     *
     * @access public
     */
    function callMethod($name, $params = 0, $parentClassId = 0)
    {
        // TODO: legacy compatibility facade; new code uses the service in lib/core/.
        return \MajorDoMo\Core\Core::methods()->call($this, $name, $params, $parentClassId);
    }

    /**
     * Title
     *
     * Description
     *
     * @access public
     */
    function getPropertyByName($name, $class_id, $object_id)
    {
        // TODO: legacy compatibility facade; new code uses the service in lib/core/.
        return \MajorDoMo\Core\Core::properties()->resolve($this, $name, $class_id, $object_id);
    }


    function getProperty($property, $cache_checked = false)
    {
        // TODO: legacy compatibility facade; new code uses the service in lib/core/.
        return \MajorDoMo\Core\Core::properties()->get($this, $property, $cache_checked);
    }

    /**
     * Title
     *
     * Description
     *
     * @access public
     */
    function setProperty($property, $value, $no_linked = 0, $source = '')
    {
        // TODO: legacy compatibility facade; new code uses the service in lib/core/.
        return \MajorDoMo\Core\Core::properties()->set($this, $property, $value, $no_linked, $source);
    }

    function getWatchedProperties($objects)
    {
        $properties = array();
        $ids = explode(',', $objects);
        include_once(DIR_MODULES . 'classes/classes.class.php');
        $cl = new classes();

        foreach ($ids as $object_id) {
            $this->loadObject($object_id);
            $props = $cl->getParentProperties($this->class_id, '', 1);
            $my_props = SQLSelect("SELECT * FROM properties WHERE OBJECT_ID='" . (int)$object_id . "'");
            if ($my_props[0]['ID']) {
                foreach ($my_props as $p) {
                    $props[] = $p;
                }
            }
            if (is_array($props)) {
                foreach ($props as $k => $v) {
                    if (mb_substr($v['TITLE'], 0, 1) == '_') continue;
                    $properties[] = array('PROPERTY' => mb_strtolower($this->object_title . '.' . $v['TITLE'], 'UTF-8'), 'OBJECT_ID' => $object_id);
                }
            }
        }
        return $properties;
    }

    function processObject($object_id)
    {
        $object_rec = SQLSelectOne("SELECT * FROM objects WHERE ID=" . (int)$object_id);
        $result = array('HTML' => '', 'OBJECT_ID' => $object_rec['ID']);
        $template = getObjectClassTemplate($object_rec['TITLE']);
        $result['HTML'] = processTitle($template, $this);
        return $result;
    }

    function renderPropertyHistoryJson()
    {
        header("HTTP/1.0 200 OK\n");
        header('Content-Type: application/json; charset=utf-8');

        $object_id = (int)$this->id;
        $property_id = (int)gr('property_id');
        $range = gr('history_range', 'trim');
        $page = (int)gr('history_page');

        $response = $this->buildPropertyHistoryResponse($object_id, $property_id, $range, $page);
        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        global $db;
        exit;
    }

    function renderPropertyTraceJson()
    {
        header("HTTP/1.0 200 OK\n");
        header('Content-Type: application/json; charset=utf-8');

        $object_id = (int)$this->id;
        $property_id = (int)gr('property_id');
        $range = gr('trace_range', 'trim');
        $page = (int)gr('trace_page');

        $response = $this->buildPropertyTraceResponse($object_id, $property_id, $range, $page);
        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        global $db;
        exit;
    }

    function buildPropertyTraceResponse($object_id, $property_id, $range, $page = 1)
    {
        $object = SQLSelectOne("SELECT * FROM objects WHERE ID=" . (int)$object_id);
        if (empty($object['ID'])) {
            return array('status' => 'error', 'message' => 'Object not found');
        }
        $property = SQLSelectOne("SELECT * FROM properties WHERE ID=" . (int)$property_id);
        if (empty($property['ID'])) {
            return array('status' => 'error', 'message' => 'Property not found');
        }
        $object_instance = getObject($object['TITLE']);
        if (!$object_instance) {
            return array('status' => 'error', 'message' => 'Object instance not found');
        }
        $linked_property_id = $object_instance->getPropertyByName($property['TITLE'], $object_instance->class_id, $object_instance->id);
        if ((int)$linked_property_id !== (int)$property['ID']) {
            return array('status' => 'error', 'message' => 'Property is not linked to the selected object');
        }

        $range_data = $this->getPropertyTraceRangeDefinition($range);
        $page = max(1, (int)$page);
        $data = \MajorDoMo\Core\Core::audit()->tracesForProperty(
            (int)$object['ID'],
            (int)$property['ID'],
            (int)$range_data['start_time'],
            (int)$range_data['stop_time'],
            $page,
            10
        );

        foreach ($data['runs'] as &$run) {
            foreach ($run['events'] as &$event) {
                $event['links'] = $this->buildTraceEventLinks($event, $object, $property);
            }
            unset($event);
            $run['target_property_name'] = $object['TITLE'] . '.' . $property['TITLE'];
        }
        unset($run);

        return array(
            'status' => 'ok',
            'meta' => array(
                'object_id' => (int)$object['ID'],
                'property_id' => (int)$property['ID'],
                'object_title' => $object['TITLE'],
                'property_title' => $property['TITLE'],
                'property_name' => $object['TITLE'] . '.' . $property['TITLE'],
                'description' => $property['DESCRIPTION'],
                'retention_days' => \MajorDoMo\Core\Config::int('PROPERTY_AUDIT_RETENTION_DAYS'),
            ),
            'range' => array(
                'key' => $range_data['key'],
                'label' => $range_data['label'],
                'started_at' => date('c', $range_data['start_time']),
                'ended_at' => date('c', $range_data['stop_time']),
            ),
            'runs' => $data['runs'],
            'pagination' => $data['pagination'],
        );
    }

    function getPropertyTraceRangeDefinition($range)
    {
        $definitions = array(
            '3h' => array('seconds' => 3 * 60 * 60, 'label' => '3 часа'),
            '12h' => array('seconds' => 12 * 60 * 60, 'label' => '12 часов'),
            '24h' => array('seconds' => 24 * 60 * 60, 'label' => '24 часа'),
            '7d' => array('seconds' => 7 * 24 * 60 * 60, 'label' => '7 дней'),
            '30d' => array('seconds' => 30 * 24 * 60 * 60, 'label' => '30 дней'),
        );
        if (empty($definitions[$range])) $range = '24h';
        $stop_time = time();
        return array(
            'key' => $range,
            'label' => $definitions[$range]['label'],
            'seconds' => $definitions[$range]['seconds'],
            'stop_time' => $stop_time,
            'start_time' => $stop_time - $definitions[$range]['seconds'],
        );
    }

    function buildTraceEventLinks($event, $object, $property)
    {
        $links = array();
        $root = defined('ROOTHTML') ? ROOTHTML : '/';
        $object_id = (int)($event['object_id'] ?? 0);
        $class_id = (int)($event['class_id'] ?? 0);
        $method_id = (int)($event['method_id'] ?? 0);
        $script_id = (int)($event['script_id'] ?? 0);
        $property_id = (int)($event['property_id'] ?? 0);

        if ($method_id > 0) {
            if ($class_id > 0 && $object_id < 1) {
                $links[] = array(
                    'label' => 'Метод класса',
                    'icon' => 'hierarchy-2',
                    'url' => $root . 'admin.php?action=classes&md=classes&view_mode=edit_classes&id=' . $class_id
                        . '&tab=methods&overwrite=1&method_id=' . $method_id,
                );
            } else {
                $links[] = array(
                    'label' => 'Метод',
                    'icon' => 'braces',
                    'url' => $root . 'admin.php?action=objects&md=objects&view_mode=edit_objects&id='
                        . ($object_id > 0 ? $object_id : (int)$object['ID'])
                        . '&tab=methods&overwrite=1&method_id=' . $method_id,
                );
            }
        }
        if ($script_id > 0) {
            $links[] = array(
                'label' => 'Сценарий',
                'icon' => 'file-code',
                'url' => $root . 'admin.php?action=scripts&md=scripts&view_mode=edit_scripts&id=' . $script_id,
            );
        }
        if (($event['type'] ?? '') === 'property' && $property_id > 0) {
            $links[] = array(
                'label' => 'Свойство',
                'icon' => 'adjustments-horizontal',
                'url' => $root . 'admin.php?action=objects&md=objects&view_mode=edit_objects&id=' . (int)$object['ID']
                    . '&tab=properties#prop-' . $property_id,
            );
        }
        $module = trim((string)($event['module'] ?? ''));
        if ($module !== '' && preg_match('/^[a-zA-Z0-9_]+$/D', $module)) {
            $links[] = array(
                'label' => 'Модуль',
                'icon' => 'puzzle',
                'url' => $root . 'admin.php?action=' . $module . '&md=' . $module,
            );
        }
        return $links;
    }

    function buildPropertyHistoryResponse($object_id, $property_id, $range, $page = 1)
    {
        $object = SQLSelectOne("SELECT * FROM objects WHERE ID=" . (int)$object_id);
        if (empty($object['ID'])) {
            return array(
                'status' => 'error',
                'message' => 'Object not found'
            );
        }

        $property = SQLSelectOne("SELECT * FROM properties WHERE ID=" . (int)$property_id);
        if (empty($property['ID'])) {
            return array(
                'status' => 'error',
                'message' => 'Property not found'
            );
        }

        $object_instance = getObject($object['TITLE']);
        if (!$object_instance) {
            return array(
                'status' => 'error',
                'message' => 'Object instance not found'
            );
        }

        $linked_property_id = $object_instance->getPropertyByName($property['TITLE'], $object_instance->class_id, $object_instance->id);
        if ((int)$linked_property_id !== (int)$property['ID']) {
            return array(
                'status' => 'error',
                'message' => 'Property is not linked to the selected object'
            );
        }

        $range_data = $this->getPropertyHistoryRangeDefinition($range);
        $property_name = $object['TITLE'] . '.' . $property['TITLE'];
        $pvalue = SQLSelectOne("SELECT * FROM pvalues WHERE PROPERTY_ID='" . (int)$property['ID'] . "' AND OBJECT_ID='" . (int)$object['ID'] . "'");
        $page = max(1, (int)$page);
        $per_page = 50;

        $history = array();
        $history_page = array(
            'items' => array(),
            'page' => 1,
            'per_page' => $per_page,
            'total' => 0,
            'pages' => 0,
            'from' => 0,
            'to' => 0,
            'has_prev' => false,
            'has_next' => false
        );
        $stats = array(
            'count' => 0,
            'min' => null,
            'max' => null,
            'avg' => null
        );

        if (!empty($pvalue['ID'])) {
            $history = getHistory($property_name, $range_data['start_time'], $range_data['stop_time']);
            $stats = array(
                'count' => count($history),
                'min' => $this->normalizeHistoryMetric(getHistoryMin($property_name, $range_data['start_time'], $range_data['stop_time'])),
                'max' => $this->normalizeHistoryMetric(getHistoryMax($property_name, $range_data['start_time'], $range_data['stop_time'])),
                'avg' => $this->normalizeHistoryMetric(getHistoryAvg($property_name, $range_data['start_time'], $range_data['stop_time']))
            );
            $history_page = $this->getPropertyHistoryPage($pvalue['ID'], $range_data['start_time'], $range_data['stop_time'], $page, $per_page);
        }

        $current_value = isset($pvalue['VALUE']) ? $pvalue['VALUE'] : getGlobal($property_name);
        $chart_data = $this->preparePropertyHistoryChart($history, 160);
        $is_numeric_property = !empty($chart_data['is_numeric']);
        if (!$is_numeric_property && $current_value !== '' && $current_value !== null && is_numeric($current_value)) {
            $is_numeric_property = true;
        }

        return array(
            'status' => 'ok',
            'meta' => array(
                'object_id' => (int)$object['ID'],
                'property_id' => (int)$property['ID'],
                'object_title' => $object['TITLE'],
                'property_title' => $property['TITLE'],
                'property_name' => $property_name,
                'description' => $property['DESCRIPTION'],
                'keep_history' => (int)$property['KEEP_HISTORY'],
                'is_numeric' => $is_numeric_property
            ),
            'range' => array(
                'key' => $range_data['key'],
                'label' => $range_data['label'],
                'started_at' => date('c', $range_data['start_time']),
                'ended_at' => date('c', $range_data['stop_time'])
            ),
            'current' => array(
                'value' => $current_value,
                'updated' => !empty($pvalue['UPDATED']) ? date('c', strtotime($pvalue['UPDATED'])) : null,
                'updated_label' => !empty($pvalue['UPDATED']) ? date('d.m.Y H:i:s', strtotime($pvalue['UPDATED'])) : '',
                'source' => isset($pvalue['SOURCE']) ? $pvalue['SOURCE'] : ''
            ),
            'stats' => array(
                'changes' => (int)$stats['count'],
                'min' => $stats['min'],
                'max' => $stats['max'],
                'avg' => $stats['avg']
            ),
            'chart' => $chart_data,
            'history' => $history_page['items'],
            'history_pagination' => array(
                'page' => (int)$history_page['page'],
                'per_page' => (int)$history_page['per_page'],
                'total' => (int)$history_page['total'],
                'pages' => (int)$history_page['pages'],
                'from' => (int)$history_page['from'],
                'to' => (int)$history_page['to'],
                'has_prev' => !empty($history_page['has_prev']),
                'has_next' => !empty($history_page['has_next'])
            )
        );
    }

    function getPropertyHistoryRangeDefinition($range)
    {
        $definitions = array(
            '3h' => array('seconds' => 3 * 60 * 60, 'label' => '3 часа'),
            '12h' => array('seconds' => 12 * 60 * 60, 'label' => '12 часов'),
            '24h' => array('seconds' => 24 * 60 * 60, 'label' => '24 часа'),
            '7d' => array('seconds' => 7 * 24 * 60 * 60, 'label' => '7 дней'),
            '30d' => array('seconds' => 30 * 24 * 60 * 60, 'label' => '30 дней'),
            '90d' => array('seconds' => 90 * 24 * 60 * 60, 'label' => '90 дней')
        );

        if (empty($definitions[$range])) {
            $range = '3h';
        }

        $stop_time = time();
        $start_time = $stop_time - $definitions[$range]['seconds'];

        return array(
            'key' => $range,
            'label' => $definitions[$range]['label'],
            'start_time' => $start_time,
            'stop_time' => $stop_time
        );
    }

    function getPropertyHistoryPage($value_id, $start_time, $stop_time, $page = 1, $per_page = 50)
    {
        if (defined('SEPARATE_HISTORY_STORAGE') && SEPARATE_HISTORY_STORAGE == 1) {
            $table_name = createHistoryTable($value_id);
        } else {
            $table_name = 'phistory';
        }

        $page = max(1, (int)$page);
        $per_page = max(1, min(200, (int)$per_page));
        $where = "VALUE_ID='" . (int)$value_id . "' AND ADDED>=('" . date('Y-m-d H:i:s', $start_time) . "') AND ADDED<=('" . date('Y-m-d H:i:s', $stop_time) . "')";
        $count_row = SQLSelectOne("SELECT COUNT(*) AS TOTAL FROM $table_name WHERE " . $where);
        $total_rows = isset($count_row['TOTAL']) ? (int)$count_row['TOTAL'] : 0;
        $total_pages = $total_rows > 0 ? (int)ceil($total_rows / $per_page) : 0;
        if ($total_pages > 0 && $page > $total_pages) {
            $page = $total_pages;
        }
        $offset = ($page - 1) * $per_page;
        $rows = SQLSelect("SELECT VALUE, SOURCE, ADDED FROM $table_name WHERE " . $where . " ORDER BY ADDED DESC LIMIT " . (int)$offset . "," . (int)$per_page);
        $items = array();
        $total = count($rows);

        for ($i = 0; $i < $total; $i++) {
            $items[] = array(
                'value' => $rows[$i]['VALUE'],
                'source' => $rows[$i]['SOURCE'],
                'added' => date('c', strtotime($rows[$i]['ADDED'])),
                'added_label' => date('d.m.Y H:i:s', strtotime($rows[$i]['ADDED']))
            );
        }

        return array(
            'items' => $items,
            'page' => $total_pages > 0 ? $page : 1,
            'per_page' => $per_page,
            'total' => $total_rows,
            'pages' => $total_pages,
            'from' => $total_rows > 0 ? ($offset + 1) : 0,
            'to' => $total_rows > 0 ? ($offset + count($items)) : 0,
            'has_prev' => $page > 1,
            'has_next' => $total_pages > 0 && $page < $total_pages
        );
    }

    function preparePropertyHistoryChart($history, $max_points = 160)
    {
        $points = array();
        $total = is_array($history) ? count($history) : 0;

        for ($i = 0; $i < $total; $i++) {
            if (!isset($history[$i]['VALUE']) || !is_numeric($history[$i]['VALUE'])) {
                continue;
            }

            $points[] = array(
                'timestamp' => strtotime($history[$i]['ADDED']),
                'value' => (float)$history[$i]['VALUE'],
                'label' => date('d.m H:i', strtotime($history[$i]['ADDED']))
            );
        }

        $sampled_points = $this->samplePropertyHistoryPoints($points, $max_points);
        $values = array();
        foreach ($sampled_points as $point) {
            $values[] = $point['value'];
        }

        return array(
            'has_data' => count($sampled_points) > 1,
            'is_numeric' => count($points) > 0,
            'points' => $sampled_points,
            'min' => count($values) ? min($values) : null,
            'max' => count($values) ? max($values) : null,
            'first_label' => count($sampled_points) ? $sampled_points[0]['label'] : '',
            'last_label' => count($sampled_points) ? $sampled_points[count($sampled_points) - 1]['label'] : ''
        );
    }

    function samplePropertyHistoryPoints($points, $max_points)
    {
        $total = count($points);
        if ($total <= $max_points) {
            return $points;
        }

        $result = array();
        $last_index = $total - 1;
        for ($i = 0; $i < $max_points; $i++) {
            $index = (int)round(($i / ($max_points - 1)) * $last_index);
            if (!isset($points[$index])) {
                continue;
            }
            $result[] = $points[$index];
        }

        return $result;
    }

    function normalizeHistoryMetric($value)
    {
        if ($value === false || $value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (float)$value : $value;
    }

    /**
     * Install
     *
     * Module installation routine
     *
     * @access private
     */
    function install($parent_name = "")
    {
        unsubscribeFromEvent($this->name, 'DAILY');
        parent::install($parent_name);
    }

    /**
     * Uninstall
     *
     * Module uninstall routine
     *
     * @access public
     */
    function uninstall()
    {
        unsubscribeFromEvent($this->name, 'DAILY');
        SQLDropTable('objects');
        parent::uninstall();
    }

    /**
     * dbInstall
     *
     * Database installation routine
     *
     * @access private
     */
    function dbInstall($data)
    {

        if (defined('CACHE_VALUE_MAX_SIZE')) {
            $cache_value_max_size = CACHE_VALUE_MAX_SIZE;
        } else {
            $cache_value_max_size = 255;
        }

        $sqlQuery = "CREATE TABLE IF NOT EXISTS `cached_values`
               (`KEYWORD`   CHAR(100) NOT NULL,
                `DATAVALUE` VARCHAR($cache_value_max_size) NOT NULL,
                PRIMARY KEY (`KEYWORD`)
               ) ENGINE = MEMORY DEFAULT CHARSET=utf8;";
        SQLExec($sqlQuery);
        //SQLExec("ALTER TABLE `cached_values` CHANGE COLUMN `DATAVALUE` `DATAVALUE` VARCHAR($cache_value_max_size) NOT NULL DEFAULT ''");

        $sqlQuery = "CREATE TABLE IF NOT EXISTS `cached_ws`
               (`PROPERTY`   CHAR(100) NOT NULL,
                `DATAVALUE` VARCHAR(20000) NOT NULL,
                `POST_ACTION`   CHAR(100) NOT NULL,
                `ADDED`    DATETIME  NOT NULL,
                PRIMARY KEY (`PROPERTY`)
               ) ENGINE = MEMORY DEFAULT CHARSET=utf8;";
        SQLExec($sqlQuery);

        \MajorDoMo\Core\Core::migrations()->ensureOperationsSchema();


        /*
        objects - Objects
        */
        $data = <<<EOD
 objects: ID int(10) unsigned NOT NULL auto_increment
 objects: SYSTEM varchar(255) NOT NULL DEFAULT ''
 objects: TITLE varchar(255) NOT NULL DEFAULT ''
 objects: CLASS_ID int(10) NOT NULL DEFAULT '0'
 objects: DESCRIPTION text
 objects: LOCATION_ID int(10) NOT NULL DEFAULT '0'

 properties: ID int(10) unsigned NOT NULL auto_increment
 properties: CLASS_ID int(10) NOT NULL DEFAULT '0'
 properties: OBJECT_ID int(10) NOT NULL DEFAULT '0'
 properties: SYSTEM varchar(255) NOT NULL DEFAULT ''
 properties: TITLE varchar(255) NOT NULL DEFAULT ''
 properties: KEEP_HISTORY int(10) NOT NULL DEFAULT '0'
 properties: DATA_KEY int(3) NOT NULL DEFAULT '0' 
 properties: DATA_TYPE int(3) NOT NULL DEFAULT '0' 
 properties: DESCRIPTION text
 properties: VALIDATION_TYPE int(3) NOT NULL DEFAULT '0'
 properties: VALIDATION_NUM_MIN varchar(20) NOT NULL DEFAULT ''
 properties: VALIDATION_NUM_MAX varchar(20) NOT NULL DEFAULT ''
 properties: VALIDATION_LIST varchar(255) NOT NULL DEFAULT ''
 properties: VALIDATION_CODE text
 properties: ONCHANGE varchar(255) NOT NULL DEFAULT ''
 properties: INDEX (CLASS_ID)
 properties: INDEX (OBJECT_ID)
 
 pvalues: ID int(10) unsigned NOT NULL auto_increment
 pvalues: PROPERTY_NAME varchar(100) NOT NULL DEFAULT ''
 pvalues: PROPERTY_ID int(10) NOT NULL DEFAULT '0'
 pvalues: OBJECT_ID int(10) NOT NULL DEFAULT '0'
 pvalues: VALUE text
 pvalues: UPDATED datetime
 pvalues: SOURCE varchar(255) NOT NULL DEFAULT ''
 pvalues: LINKED_MODULES varchar(255) NOT NULL DEFAULT ''
 pvalues: INDEX (PROPERTY_ID)
 pvalues: INDEX (OBJECT_ID)
 pvalues: INDEX (PROPERTY_NAME) 

 phistory: ID int(10) unsigned NOT NULL auto_increment
 phistory: VALUE_ID int(10) unsigned NOT NULL DEFAULT '0'
 phistory: SOURCE varchar(255) NOT NULL DEFAULT ''
 phistory: ADDED datetime
 phistory: INDEX (VALUE_ID)

 phistory_queue: ID int(10) unsigned NOT NULL auto_increment
 phistory_queue: VALUE_ID int(10) unsigned NOT NULL DEFAULT '0'
 phistory_queue: VALUE text
 phistory_queue: OLD_VALUE text
 phistory_queue: KEEP_HISTORY int(10) unsigned NOT NULL DEFAULT '0'
 phistory_queue: SOURCE varchar(255) NOT NULL DEFAULT ''
 phistory_queue: ADDED datetime

EOD;
        parent::dbInstall($data);

        //SQLExec("ALTER TABLE `pvalues` CHANGE `SOURCE` `SOURCE` VARCHAR(255) NOT NULL DEFAULT ''");
        //SQLExec("ALTER TABLE `phistory` CHANGE `SOURCE` `SOURCE` VARCHAR(255) NOT NULL DEFAULT ''");
        //SQLExec("ALTER TABLE `phistory_queue` CHANGE `SOURCE` `SOURCE` VARCHAR(255) NOT NULL DEFAULT ''");

    }
// --------------------------------------------------------------------
}
/*
*
* TW9kdWxlIGNyZWF0ZWQgTWF5IDIyLCAyMDA5IHVzaW5nIFNlcmdlIEouIHdpemFyZCAoQWN0aXZlVW5pdCBJbmMgd3d3LmFjdGl2ZXVuaXQuY29tKQ==
*
*/
