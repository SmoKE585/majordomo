<?php
/**
 * LinkedObject
 *
 * Linkedobject
 *
 * @package project
 * @author Serge J. <sergejey@gmail.com>
 * @copyright https://majordomohome.com/ (c)
 * @version 0.2
 */
//
//
class linkedobject extends module
{
    var $property_field;
    var $method_field;

    /**
     * The object list is large and is rendered by every module instance on a
     * page. Send it to the browser only once per request; the front-end shares
     * the parsed list between all instances and falls back to AJAX when needed.
     *
     * @var bool
     */
    private static $objectsPayloadSent = false;

    function getAvailableObjects()
    {
        $objects = SQLSelect("SELECT objects.CLASS_ID, objects.TITLE, objects.DESCRIPTION, classes.TITLE AS CLASS_NAME
                                FROM objects
                                LEFT JOIN classes ON objects.CLASS_ID=classes.ID
                               ORDER BY objects.CLASS_ID, objects.TITLE");
        if (!is_array($objects)) {
            $objects = array();
        }

        $objects[] = array(
            'ID' => 'scripts',
            'CLASS_ID' => 0,
            'TITLE' => 'AllScripts',
            'DESCRIPTION' => defined('LANG_SCRIPTS') ? LANG_SCRIPTS : 'Scripts',
            'CLASS_NAME' => defined('LANG_SCRIPTS') ? LANG_SCRIPTS : 'Scripts',
            'SPECIAL' => 'scripts'
        );

        return $objects;
    }

    /**
     * Resolve a field value from globals/owner data.
     *
     * Supports simple names (linked_object) as well as PHP form-array names
     * (linked_object[1], linked_object[]). The latter are used by some custom
     * module templates and must not break the initial render.
     *
     * @param string $field_name
     * @return string
     */
    function getFieldValue($field_name)
    {
        $field_name = trim((string)$field_name);
        if ($field_name === '') {
            return '';
        }

        $base_name = $field_name;
        $array_key = null;
        if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\[([^\]]*)\]$/', $field_name, $matches)) {
            $base_name = $matches[1];
            $array_key = $matches[2];
        }

        $value = null;
        if (isset($GLOBALS[$base_name])) {
            $value = $GLOBALS[$base_name];
        } elseif (isset($this->owner) && is_object($this->owner)) {
            if (isset($this->owner->$base_name)) {
                $value = $this->owner->$base_name;
            } elseif (isset($this->owner->data) && is_array($this->owner->data)) {
                if (isset($this->owner->data[$base_name])) {
                    $value = $this->owner->data[$base_name];
                } else {
                    $upper_name = strtoupper($base_name);
                    if (isset($this->owner->data[$upper_name])) {
                        $value = $this->owner->data[$upper_name];
                    }
                }
            }
        }

        if ($array_key !== null) {
            if (!is_array($value)) {
                return '';
            }
            if ($array_key === '') {
                return isset($value[0]) && is_scalar($value[0]) ? (string)$value[0] : '';
            }
            return isset($value[$array_key]) && is_scalar($value[$array_key]) ? (string)$value[$array_key] : '';
        }

        return is_scalar($value) ? (string)$value : '';
    }

    /**
     * Legacy markup builder. Kept because custom templates may call it
     * directly; the bundled template no longer uses a <select>.
     *
     * @param array $objects
     * @return string
     */
    function buildObjectsListMarkup($objects)
    {
        $total = count($objects);
        $old_class_id = null;
        $list_result = '';

        for ($i = 0; $i < $total; $i++) {
            $class_id = isset($objects[$i]['CLASS_ID']) ? (int)$objects[$i]['CLASS_ID'] : 0;
            $class_name = isset($objects[$i]['CLASS_NAME']) && $objects[$i]['CLASS_NAME'] !== ''
                ? (string)$objects[$i]['CLASS_NAME']
                : '—';

            if ($old_class_id === null || $class_id != $old_class_id) {
                if ($old_class_id !== null) {
                    $list_result .= '</optgroup>';
                }
                $list_result .= '<optgroup label="' . htmlspecialchars($class_name, ENT_QUOTES, 'UTF-8') . '">';
                $old_class_id = $class_id;
            }

            $value = isset($objects[$i]['TITLE']) ? (string)$objects[$i]['TITLE'] : '';
            $title = $value;
            if (!empty($objects[$i]['DESCRIPTION'])) {
                $title .= ' - ' . $objects[$i]['DESCRIPTION'];
            }

            $list_result .= '<option value="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '"'
                . ' data-md-description="' . htmlspecialchars((string)($objects[$i]['DESCRIPTION'] ?? ''), ENT_QUOTES, 'UTF-8') . '">'
                . htmlspecialchars($title, ENT_QUOTES, 'UTF-8')
                . '</option>';
        }

        if ($old_class_id !== null) {
            $list_result .= '</optgroup>';
        }

        return $list_result;
    }

    /**
     * Normalize an object row for the front-end.
     *
     * @param array $objects
     * @return array
     */
    private function normalizeObjects($objects)
    {
        $result = array();
        $seen = array();

        foreach ((array)$objects as $item) {
            if (!is_array($item)) {
                continue;
            }
            $title = isset($item['TITLE']) ? trim((string)$item['TITLE']) : '';
            if ($title === '' || isset($seen[$title])) {
                continue;
            }
            $seen[$title] = 1;

            $class_name = isset($item['CLASS_NAME']) ? trim((string)$item['CLASS_NAME']) : '';
            $result[] = array(
                'ID' => isset($item['ID']) ? $item['ID'] : '',
                'TITLE' => $title,
                'DESCRIPTION' => isset($item['DESCRIPTION']) ? (string)$item['DESCRIPTION'] : '',
                'CLASS_ID' => isset($item['CLASS_ID']) ? (int)$item['CLASS_ID'] : 0,
                'CLASS_NAME' => $class_name !== '' ? $class_name : '—',
                'SPECIAL' => isset($item['SPECIAL']) ? (string)$item['SPECIAL'] : ''
            );
        }

        return $result;
    }

    /**
     * Normalize class/object properties or methods.
     *
     * @param array  $rows
     * @param string $kind
     * @param string $default_source
     * @return array
     */
    private function normalizeItems($rows, $kind, $default_source = '')
    {
        $result = array();
        $seen = array();

        foreach ((array)$rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $title = isset($row['TITLE']) ? trim((string)$row['TITLE']) : '';
            if ($title === '' || isset($seen[$title])) {
                continue;
            }
            $seen[$title] = 1;

            $source = $default_source;
            if (!empty($row['OBJECT_ID'])) {
                $source = 'object';
            } elseif (!empty($row['CLASS_ID'])) {
                $source = 'class';
            } elseif ($source === '') {
                $source = $kind;
            }

            $item = array(
                'ID' => isset($row['ID']) ? (int)$row['ID'] : 0,
                'CLASS_ID' => isset($row['CLASS_ID']) ? (int)$row['CLASS_ID'] : 0,
                'OBJECT_ID' => isset($row['OBJECT_ID']) ? (int)$row['OBJECT_ID'] : 0,
                'TITLE' => $title,
                'DESCRIPTION' => isset($row['DESCRIPTION']) ? (string)$row['DESCRIPTION'] : '',
                'SOURCE' => $source,
                'CLASS_TITLE' => isset($row['CLASS_TITLE']) ? (string)$row['CLASS_TITLE'] : ''
            );

            if ($kind === 'property') {
                $item['DATA_TYPE'] = isset($row['DATA_TYPE']) ? (int)$row['DATA_TYPE'] : 0;
                $item['VALIDATION_TYPE'] = isset($row['VALIDATION_TYPE']) ? (int)$row['VALIDATION_TYPE'] : 0;
            } elseif ($kind === 'method') {
                $item['SCRIPT_ID'] = isset($row['SCRIPT_ID']) ? (int)$row['SCRIPT_ID'] : 0;
            }

            $result[] = $item;
        }

        return $result;
    }

    /**
     * Build object information, properties and methods in one response.
     *
     * One atomic response is much more stable than two parallel legacy
     * requests: the property/method lists always belong to the same object
     * snapshot, even if the user changes the selection while requests are in
     * flight.
     *
     * @param string $object_name
     * @return array
     */
    private function buildContext($object_name)
    {
        $object_name = trim((string)$object_name);
        $payload = array(
            'OBJECT' => array(
                'TITLE' => $object_name,
                'DESCRIPTION' => '',
                'CLASS_NAME' => '',
                'CLASS_ID' => 0,
                'DEVICE_ID' => '',
                'FOUND' => false,
                'SPECIAL' => ''
            ),
            'PROPERTIES' => array(),
            'METHODS' => array(),
            'DEVICE_ID' => ''
        );

        if ($object_name === '') {
            return $payload;
        }

        if ($object_name === 'AllScripts') {
            $scripts = SQLSelect("SELECT TITLE, DESCRIPTION FROM scripts ORDER BY TITLE");
            $payload['OBJECT'] = array(
                'TITLE' => 'AllScripts',
                'DESCRIPTION' => defined('LANG_SCRIPTS') ? LANG_SCRIPTS : 'Scripts',
                'CLASS_NAME' => defined('LANG_SCRIPTS') ? LANG_SCRIPTS : 'Scripts',
                'CLASS_ID' => 0,
                'DEVICE_ID' => '',
                'FOUND' => true,
                'SPECIAL' => 'scripts'
            );
            $payload['METHODS'] = $this->normalizeItems($scripts, 'method', 'script');
            return $payload;
        }

        try {
            $obj = getObject($object_name);
        } catch (Throwable $e) {
            $obj = null;
        }

        if (!is_object($obj) || empty($obj->id)) {
            return $payload;
        }

        $class_id = isset($obj->class_id) ? (int)$obj->class_id : 0;
        $device_id = isset($obj->device_id) ? (string)$obj->device_id : '';
        $class = $class_id > 0
            ? SQLSelectOne("SELECT ID, TITLE FROM classes WHERE ID='" . $class_id . "'")
            : array();

        $payload['OBJECT'] = array(
            'TITLE' => $object_name,
            'DESCRIPTION' => isset($obj->description) ? (string)$obj->description : '',
            'CLASS_NAME' => isset($class['TITLE']) ? (string)$class['TITLE'] : '',
            'CLASS_ID' => $class_id,
            'DEVICE_ID' => $device_id,
            'FOUND' => true,
            'SPECIAL' => ''
        );
        $payload['DEVICE_ID'] = $device_id;

        $parent_properties = array();
        $parent_methods = array();
        if (method_exists($obj, 'getParentProperties')) {
            // Legacy helper: suppress warnings from old class hierarchy data.
            $parent_properties = @$obj->getParentProperties($class_id, '', 1);
        }
        if (method_exists($obj, 'getParentMethods')) {
            $parent_methods = @$obj->getParentMethods($class_id, '', 1);
        }

        $own_properties = SQLSelect("SELECT * FROM properties WHERE OBJECT_ID='" . (int)$obj->id . "' ORDER BY TITLE");
        $own_methods = SQLSelect("SELECT * FROM methods WHERE OBJECT_ID='" . (int)$obj->id . "' ORDER BY TITLE");

        // Own items first: they must win over inherited declarations with the
        // same title.
        $payload['PROPERTIES'] = $this->normalizeItems(
            array_merge((array)$own_properties, (array)$parent_properties),
            'property'
        );
        $payload['METHODS'] = $this->normalizeItems(
            array_merge((array)$own_methods, (array)$parent_methods),
            'method'
        );

        return $payload;
    }

    /**
     * Encode JSON for an inline <script> block. `#` is escaped as well: the
     * template parser treats `[#` as a directive even inside JSON strings.
     *
     * @param mixed $payload
     * @return string
     */
    private function encodeInlineJson($payload)
    {
        $json = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_HEX_TAG
            | JSON_HEX_AMP
            | JSON_HEX_APOS
            | JSON_HEX_QUOT
            | JSON_INVALID_UTF8_SUBSTITUTE
        );
        if ($json === false) {
            return '';
        }
        return str_replace('#', '\\u0023', $json);
    }

    /**
     * Send a clean JSON response and stop execution.
     *
     * @param mixed $payload
     * @param int   $status
     */
    private function respondJson($payload, $status = 200)
    {
        // Drop any buffered warnings/notices/partial shell markup. A stray
        // newline before `{` used to turn a valid response into "bad JSON".
        while (ob_get_level() > 0) {
            if (!@ob_end_clean()) {
                break;
            }
        }

        if (!headers_sent()) {
            http_response_code((int)$status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('X-Content-Type-Options: nosniff');
        }

        $json = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_HEX_TAG
            | JSON_HEX_AMP
            | JSON_HEX_APOS
            | JSON_HEX_QUOT
            | JSON_INVALID_UTF8_SUBSTITUTE
        );
        if ($json === false) {
            $json = '{"ERROR":"json_encode_failed","MESSAGE":"Unable to encode response"}';
        }

        echo $json;
        exit;
    }

    /**
     * @param string $width
     * @return string
     */
    private function normalizeWidth($width)
    {
        $width = trim((string)$width);
        if ($width === '') {
            return '100%';
        }
        if (preg_match('/^\d+(?:\.\d+)?$/', $width)) {
            return $width . 'px';
        }
        if (preg_match('/^\d+(?:\.\d+)?(?:px|%|rem|em)$/i', $width)) {
            return $width;
        }
        return '100%';
    }

    /**
     * @param string $uniq
     * @return string
     */
    private function normalizeUniq($uniq)
    {
        $uniq = preg_replace('/[^A-Za-z0-9_-]/', '', (string)$uniq);
        if ($uniq === '' || $uniq === '0') {
            $uniq = (string)mt_rand(100000, 999999);
        }
        return $uniq;
    }

    /**
     * linkedobject
     *
     * Module class constructor
     *
     * @access private
     */
    function __construct()
    {
        $this->name = "linkedobject";
        $this->title = "LinkedObject";
        $this->module_category = "<#LANG_SECTION_SYSTEM#>";
        $this->checkInstalled();
    }

    /**
     * saveParams
     *
     * Saving module parameters
     *
     * @access public
     */
    function saveParams($data = 0)
    {
        $p = array();
        if (isset($this->id)) {
            $p["id"] = $this->id;
        }
        if (isset($this->view_mode)) {
            $p["view_mode"] = $this->view_mode;
        }
        if (isset($this->edit_mode)) {
            $p["edit_mode"] = $this->edit_mode;
        }
        if (isset($this->tab)) {
            $p["tab"] = $this->tab;
        }
        return parent::saveParams($p);
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
        global $op;
        global $ajax;
        global $object;
        global $uniq;
        global $first_run;

        if ($ajax == 1) {
            // The module is included from a page parser. Make sure nothing
            // printed before or during module execution can corrupt JSON.
            while (ob_get_level() > 0) {
                if (!@ob_end_clean()) {
                    break;
                }
            }
            ob_start();

            $op = isset($op) ? (string)$op : '';
            $object = isset($object) ? (string)$object : '';

            try {
                if ($op === 'objects') {
                    $objects = $this->normalizeObjects($this->getAvailableObjects());
                    $this->respondJson(array(
                        'OBJECTS' => $objects,
                        'TOTAL' => count($objects),
                        'LATEST_OBJECT' => '',
                        'SERVER_TIME' => time()
                    ));
                }

                if ($op === 'context') {
                    $this->respondJson($this->buildContext($object));
                }

                if ($op === 'properties') {
                    $context = $this->buildContext($object);
                    $this->respondJson(array(
                        'OBJECT' => $context['OBJECT'],
                        'PROPERTIES' => $context['PROPERTIES'],
                        'DEVICE_ID' => $context['DEVICE_ID']
                    ));
                }

                if ($op === 'methods') {
                    $context = $this->buildContext($object);
                    $this->respondJson(array(
                        'OBJECT' => $context['OBJECT'],
                        'METHODS' => $context['METHODS'],
                        'DEVICE_ID' => $context['DEVICE_ID']
                    ));
                }

                $this->respondJson(array(
                    'ERROR' => 'unknown_op',
                    'MESSAGE' => 'Unknown linkedobject operation'
                ), 400);
            } catch (Throwable $e) {
                $this->respondJson(array(
                    'ERROR' => 'server_error',
                    'MESSAGE' => 'Linked object data is temporarily unavailable'
                ), 500);
            }
        }

        $out = array();
        $this->action = 'admin';
        $this->admin($out);
        if (isset($this->owner->action)) {
            $out['PARENT_ACTION'] = $this->owner->action;
        }
        if (isset($this->owner->name)) {
            $out['PARENT_NAME'] = $this->owner->name;
        }

        if (!$first_run) {
            $out['FIRST_RUN'] = 1;
            $first_run = 1;
        }

        $width = $this->normalizeWidth(isset($this->width) ? $this->width : '');
        $uniq = $this->normalizeUniq(isset($uniq) ? $uniq : '');

        if ($op == 'redirect') {
            $device_id = gr('device_id', 'int');
            if ($device_id) {
                $linked_object = isset($this->linked_object) && $this->linked_object !== ''
                    ? $this->linked_object
                    : gr('object');
                $object_rec = SQLSelectOne("SELECT ID FROM objects WHERE TITLE LIKE '" . DBSafe($linked_object) . "'");
                if (!empty($object_rec['ID'])) {
                    redirect(ROOTHTML . 'admin.php?action=objects&view_mode=edit_objects&id=' . (int)$object_rec['ID'], '', 1);
                }
            }

            $object = gr('object');
            $sub = gr('sub');
            if (!$object) {
                redirect(ROOTHTML);
            }
            $obj = getObject($object);
            if (!$obj) {
                redirect(ROOTHTML);
            }
            if ($sub != '') {
                redirect(ROOTHTML . 'panel/class/' . $obj->class_id . '/object/' . $obj->id . '/' . $sub . '.html');
            } else {
                redirect(ROOTHTML . 'panel/class/' . $obj->class_id . '/object/' . $obj->id . '.html');
            }
        }

        $out['WIDTH'] = htmlspecialchars($width, ENT_QUOTES, 'UTF-8');
        $out['UNIQ'] = htmlspecialchars($uniq, ENT_QUOTES, 'UTF-8');
        $out['BASE_URL'] = htmlspecialchars(rtrim(ROOTHTML, '/') . '/panel/linkedobject.html', ENT_QUOTES, 'UTF-8');

        $out['OBJECT_LABEL'] = htmlspecialchars(
            defined('LANG_LINKED_OBJECT') ? LANG_LINKED_OBJECT : 'Объект',
            ENT_QUOTES,
            'UTF-8'
        );
        $out['PROPERTY_LABEL'] = htmlspecialchars(
            defined('LANG_LINKED_PROPERTY') ? LANG_LINKED_PROPERTY : 'Свойство',
            ENT_QUOTES,
            'UTF-8'
        );
        $out['METHOD_LABEL'] = htmlspecialchars(
            defined('LANG_LINKED_METHOD') ? LANG_LINKED_METHOD : 'Метод',
            ENT_QUOTES,
            'UTF-8'
        );

        if ($this->object_field) {
            $objects = $this->getAvailableObjects();
            $out['OBJECTS_LIST_RESULT'] = $this->buildObjectsListMarkup($objects);

            $out['OBJECT_FIELD'] = htmlspecialchars((string)$this->object_field, ENT_QUOTES, 'UTF-8');
            $out['OBJECT_VALUE'] = htmlspecialchars($this->getFieldValue($this->object_field), ENT_QUOTES, 'UTF-8');
            $out['OBJECTS_TOTAL'] = count($objects);

            if (!self::$objectsPayloadSent) {
                $out['OBJECTS_JSON'] = $this->encodeInlineJson($this->normalizeObjects($objects));
                self::$objectsPayloadSent = true;
            } else {
                $out['OBJECTS_JSON'] = '';
            }
        }

        if ($this->property_field) {
            $out['PROPERTY_FIELD'] = htmlspecialchars((string)$this->property_field, ENT_QUOTES, 'UTF-8');
            $out['PROPERTY_VALUE'] = htmlspecialchars($this->getFieldValue($this->property_field), ENT_QUOTES, 'UTF-8');
        }

        if ($this->method_field) {
            $out['METHOD_FIELD'] = htmlspecialchars((string)$this->method_field, ENT_QUOTES, 'UTF-8');
            $out['METHOD_VALUE'] = htmlspecialchars($this->getFieldValue($this->method_field), ENT_QUOTES, 'UTF-8');
        }

        $out['VIEW_MODE'] = $this->view_mode;
        $out['EDIT_MODE'] = $this->edit_mode;
        $out['MODE'] = $this->mode;
        $out['ACTION'] = $this->action;
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
    }




    /**
     * Install
     *
     * Module installation routine
     *
     * @access private
     */
    function install($data = '')
    {
        parent::install();
        SQLExec("UPDATE project_modules SET HIDDEN=1 WHERE NAME LIKE '" . $this->name . "'");
    }
// --------------------------------------------------------------------
}

/*
*
* TW9kdWxlIGNyZWF0ZWQgTm92IDE5LCAyMDE0IHVzaW5nIFNlcmdlIEouIHdpemFyZCAoQWN0aXZlVW5pdCBJbmMgd3d3LmFjdGl2ZXVuaXQuY29tKQ==
*
*/
?>
