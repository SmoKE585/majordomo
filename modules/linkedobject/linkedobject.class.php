<?php
/**
 * LinkedObject
 *
 * Linkedobject
 *
 * @package project
 * @author Serge J. <sergejey@gmail.com>
 * @copyright https://majordomohome.com/ (c)
 * @version 0.1 (wizard, 13:11:32 [Nov 19, 2014])
 */
//
//
class linkedobject extends module
{
    var $property_field;
    var $method_field;

    function getAvailableObjects()
    {
        $objects = SQLSelect("SELECT objects.CLASS_ID, objects.TITLE, objects.DESCRIPTION, classes.TITLE AS CLASS_NAME FROM objects JOIN classes ON CLASS_ID=classes.ID ORDER BY CLASS_ID, TITLE");
        $objects[] = array(
            'ID' => 'scripts',
            'CLASS_ID' => 0,
            'TITLE' => 'AllScripts',
            'DESCRIPTION' => LANG_SCRIPTS,
            'CLASS_NAME' => LANG_SCRIPTS
        );

        return $objects;
    }

    function getFieldValue($field_name)
    {
        if (!$field_name) {
            return '';
        }

        // Simple field names can be resolved directly from globals during template render.
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $field_name)) {
            if (isset($GLOBALS[$field_name])) {
                return $GLOBALS[$field_name];
            }
            return '';
        }

        return '';
    }

    function buildObjectsListMarkup($objects)
    {
        $total = count($objects);
        $old_class_id = 0;
        $list_result = '';

        if (!$total) {
            return $list_result;
        }

        for ($i = 0; $i < $total; $i++) {
            $class_id = isset($objects[$i]['CLASS_ID']) ? (int)$objects[$i]['CLASS_ID'] : 0;
            $class_name = isset($objects[$i]['CLASS_NAME']) ? $objects[$i]['CLASS_NAME'] : '';
            if ($class_id != $old_class_id || $i == 0) {
                if ($i > 0) {
                    $list_result .= '</optgroup>';
                }
                $list_result .= '<optgroup label="' . htmlspecialchars($class_name, ENT_QUOTES) . '">';
                $old_class_id = $class_id;
            }

            $value = isset($objects[$i]['TITLE']) ? $objects[$i]['TITLE'] : '';
            $title = $value;
            if (!empty($objects[$i]['DESCRIPTION'])) {
                $title .= ' - ' . $objects[$i]['DESCRIPTION'];
            }

            $list_result .= '<option value="' . htmlspecialchars($value, ENT_QUOTES) . '" data-md-description="' . htmlspecialchars((string)($objects[$i]['DESCRIPTION'] ?? ''), ENT_QUOTES) . '">';
            $list_result .= htmlspecialchars($title, ENT_QUOTES);
            $list_result .= '</option>';
        }

        $list_result .= '</optgroup>';
        return $list_result;
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


        global $op;
        global $ajax;
        global $object;
        global $uniq;
        global $first_run;

        if (!$first_run) {
            $out['FIRST_RUN'] = 1;
            $first_run = 1;
        }

        if (isset($this->width)) {
            $ifPX = substr($this->width, -1);
            if ($ifPX != 'x' && $ifPX != '%') $this->width = $this->width . 'px';

            $out['WIDTH'] = $this->width;
        } else {
            $out['WIDTH'] = '90%';
        }

        if ($uniq) {
            $out['UNIQ'] = $uniq;
        } else {
            $out['UNIQ'] = rand(0, 999999);
        }

        if ($op == 'redirect') {
            $device_id = gr('device_id', 'int');
            if ($device_id) {
                $object_rec = SQLSelectOne("SELECT ID FROM objects WHERE TITLE LIKE '" . DBSafe($this->linked_object) . "'");
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

        if ($ajax == 1) {

            if ($op == 'objects') {
                $objects = $this->getAvailableObjects();
                $res = array(
                    'OBJECTS' => $objects,
                    'LATEST_OBJECT' => ''
                );
                header('Content-type:application/json');
                echo json_encode($res);
            }

            if ($op == 'properties') {
                $res = array();
                $properties = array();
                do {
                    if (!$object) break;
                    if ($object == 'AllScripts') break;
                    $obj = getObject($object);
                    if (isset($obj->device_id)) {
                        $res['DEVICE_ID'] = $obj->device_id;
                    }
                    if (!$obj) break;
                    $parent_properties = $obj->getParentProperties($obj->class_id, '', 1);
                    if ($parent_properties && is_array($parent_properties)) {
                        foreach ($parent_properties as $v) {
                            $properties[] = $v;
                        }
                    }
                    $tmp = SQLSelect("SELECT * FROM properties WHERE OBJECT_ID='" . (int)$obj->id . "'");
                    if ($tmp && is_array($tmp)) {
                        foreach ($tmp as $i) {
                            $properties[] = $i;
                        }
                    }
                } while (0);
                $res['PROPERTIES'] = $properties;
                header('Content-type:application/json');
                echo json_encode($res);
            }

            if ($op == 'methods') {
                $res = array();
                $properties = array();
                do {
                    if (!$object) break;
                    if ($object == 'AllScripts') {
                        $properties = SQLSelect("SELECT TITLE FROM scripts ORDER BY TITLE");
                        break;
                    }
                    $obj = getObject($object);
                    if (!$obj) break;
                    $parent_properties = $obj->getParentMethods($obj->class_id, '', 1);
                    if ($parent_properties && is_array($parent_properties)) {
                        foreach ($parent_properties as $v) {
                            if (!isset($seen[$v['TITLE']])) {
                                $properties[] = $v;
                                $seen[$v['TITLE']] = 1;
                            }
                        }
                    }
                    $tmp = SQLSelect("SELECT * FROM methods WHERE OBJECT_ID='" . (int)$obj->id . "'");
                    if ($tmp && is_array($tmp)) {
                        foreach ($tmp as $i) {
                            if (!isset($seen[$i['TITLE']])) {
                                $properties[] = $i;
                                $seen[$i['TITLE']] = 1;
                            }
                        }
                    }
                } while (0);
                $res['METHODS'] = $properties;
                header('Content-type:application/json');
                echo json_encode($res);
            }


            exit;
        }

        if ($this->object_field) {
            $objects = $this->getAvailableObjects();
            $list_result = $this->buildObjectsListMarkup($objects);
            $out['OBJECTS_LIST_RESULT'] = $list_result;
            $out['OBJECTS'] = $objects;
            $out['OBJECT_FIELD'] = $this->object_field;
            $out['OBJECT_VALUE'] = $this->getFieldValue($this->object_field);
        }

        if ($this->property_field) {
            $out['PROPERTY_FIELD'] = $this->property_field;
            $out['PROPERTY_VALUE'] = $this->getFieldValue($this->property_field);
        }

        if ($this->method_field) {
            $out['METHOD_FIELD'] = $this->method_field;
            $out['METHOD_VALUE'] = $this->getFieldValue($this->method_field);
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
     * FrontEnd
     *
     * Module frontend
     *
     * @access public
     */
    function usual(&$out)
    {
        $this->admin($out);
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
