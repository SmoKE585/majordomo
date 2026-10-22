<?php

/** System helpers. Public names and argument defaults are part of the module API. */

/**
 * Summary of addClass
 * @param mixed $class_name Class name
 * @param mixed $parent_class Parent class (default '')
 * @return mixed
 */
function addClass($class_name, $parent_class = '')
{
    if ($parent_class != '') {
        $parent_class_id = addClass($parent_class);
    } else {
        $parent_class_id = 0;
    }

    $sqlQuery = "SELECT ID
                  FROM classes
                 WHERE TITLE = '" . DBSafe($class_name) . "'";

    $class = SQLSelectOne($sqlQuery);

    if ($class['ID']) {
        return $class['ID'];
    } else {
        $class = array();

        $class['TITLE'] = $class_name;
        $class['PARENT_ID'] = (int)$parent_class_id;
        $class['ID'] = SQLInsert('classes', $class);
        return $class['ID'];
    }
}

/**
 * Summary of addClassMethod
 * @param mixed $class_name Class method
 * @param mixed $method_name Method name
 * @param mixed $code Code (default '')
 * @return mixed
 */
function addClassMethod($class_name, $method_name, $code = '', $key = '')
{
    $class_id = addClass($class_name);

    if ($class_id) {
        $sqlQuery = "SELECT * 
                     FROM methods
                    WHERE CLASS_ID = '" . $class_id . "'
                      AND TITLE = '" . DBSafe($method_name) . "'
                      AND OBJECT_ID = 0";

        $method = SQLSelectOne($sqlQuery);

        if ($key != '') {
            $injection_code = '/* begin injection of {' . $key . '} */' . "\n" . $code . "\n" . '/* end injection of {' . $key . '} */';
        } else {
            $injection_code = $code;
        }

        if (!$method['ID']) {
            $method = array();

            $method['CLASS_ID'] = $class_id;
            $method['OBJECT_ID'] = 0;
            $method['CODE'] = $injection_code;
            $method['TITLE'] = $method_name;
            $method['ID'] = SQLInsert('methods', $method);
        } else {
            if ($code != '' && $method['CODE'] != $injection_code && $method['CODE'] != $code && $key != '') {
                @$old_code = $method['CODE'];
                if (preg_match('/\/\* begin injection of {' . $key . '} \*\/(.*?)\/\* end injection of {' . $key . '} \*\//uis', $method['CODE'], $m)) {
                    $current_injection = trim($m[1]);
                    if ($current_injection != $code) {
                        $method['CODE'] = str_replace($m[0], $injection_code, $method['CODE']);
                    }
                } else {
                    $method['CODE'] .= "\n" . $injection_code;
                }

                //$method['CODE'] = $code;
                SQLUpdate('methods', $method);
            }

        }
    }
    return $method['ID'];
}

/**
 * Summary of addClassObject
 * @param mixed $class_name Class name
 * @param mixed $object_name Object name
 * @return mixed
 */
function addClassObject($class_name, $object_name, $system = '')
{
    $class_id = addClass($class_name);
    $sqlQuery = "SELECT ID
                  FROM objects
                 WHERE TITLE = '" . DBSafe($object_name) . "'";
    $object = SQLSelectOne($sqlQuery);
    if (isset($object['ID']))
        return $object['ID'];

    if ($system != '') {
        $sqlQuery = "SELECT ID
                  FROM objects
                 WHERE `SYSTEM` = '" . DBSafe($system) . "'";
        $object = SQLSelectOne($sqlQuery);
        if (isset($object['ID']))
            return $object['ID'];
    }

    $object = array();
    $object['TITLE'] = $object_name;
    $object['CLASS_ID'] = $class_id;
    $object['SYSTEM'] = $system . '';
    $object['ID'] = SQLInsert('objects', $object);
    return $object['ID'];

}

/**
 * Summary of addClassProperty
 * @param mixed $class_name Class name
 * @param mixed $property_name Property name
 * @param mixed $keep_history Flag keep history (default 0)
 * @return mixed
 */
function addClassProperty($class_name, $property_name, $keep_history = 0)
{
    $class_id = addClass($class_name);

    $sqlQuery = "SELECT ID
                  FROM properties
                 WHERE TITLE = '" . DBSafe($property_name) . "'
                   AND OBJECT_ID = 0
                   AND CLASS_ID  = '" . $class_id . "'";

    $prop = SQLSelectOne($sqlQuery);

    if (!isset($prop['ID']) || !$prop['ID']) {
        $prop = array();

        $prop['CLASS_ID'] = $class_id;
        $prop['TITLE'] = $property_name;
        $prop['KEEP_HISTORY'] = $keep_history;
        $prop['OBJECT_ID'] = 0;
        $prop['ID'] = SQLInsert('properties', $prop);
    }

    return $prop['ID'];
}

/**
 * Summary of callMethod
 * @param mixed $method_name Method name
 * @param mixed $params Params (default 0)
 * @return mixed
 */
function callMethod($method_name, $params = 0)
{
    // TODO: legacy compatibility facade.
    return \MajorDoMo\Core\Core::objects()->call($method_name, $params, false);
}

function callMethodSafe($method_name, $params = 0)
{
    // TODO: legacy compatibility facade.
    return \MajorDoMo\Core\Core::objects()->call($method_name, $params, true);
}

/**
 * Alias for callMethod
 * @param mixed $method_name Method name
 * @param mixed $params Params (default 0)
 * @return mixed
 */
function cm($method_name, $params = 0)
{
    return callMethod($method_name, $params);
}

function deleteObject($object_id)
{
    $object_rec = SQLSelectOne("SELECT ID FROM objects WHERE ID=" . (int)$object_id . " OR TITLE = '" . DBSafe($object_id) . "'");
    if (isset($object_rec['ID']) && $object_rec['ID']) {
        include_once(DIR_MODULES . 'objects/objects.class.php');
        $obj = new objects();
        $obj->delete_objects($object_rec['ID']);
    }
}

function getClassProperties($class_id, $def = '')
{

    global $cached_class_properties;
    if (!is_array($def) && isset($cached_class_properties[$class_id])) return $cached_class_properties[$class_id];

    $class = SQLSelectOne("SELECT ID, PARENT_ID FROM classes WHERE (ID='" . (int)$class_id . "' OR TITLE = '" . DBSafe($class_id) . "')");
    if (!isset($class['ID'])) {
        return array();
    }

    if (!is_array($def)) {
        $res = getClassPropertiesBatch((int)$class['ID']);
        $cached_class_properties[$class_id] = $res;
        return $res;
    }

    $properties = SQLSelect("SELECT properties.*, classes.TITLE AS CLASS_TITLE FROM properties LEFT JOIN classes ON properties.CLASS_ID=classes.ID WHERE CLASS_ID='" . $class['ID'] . "' AND OBJECT_ID=0");
    $res = $properties;
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
    if (isset($class['PARENT_ID'])) {
        $p_res = getClassProperties($class['PARENT_ID'], $def);
        if (isset($p_res[0]['ID'])) {
            foreach ($p_res as $k => $p) {
                if (!in_array($p['TITLE'], $def)) {
                    $res[] = $p;
                    $def[] = $p['TITLE'];
                }
            }
        }
    }
    $cached_class_properties[$class_id] = $res;
    return $res;
}

function getClassPropertiesBatch($class_id)
{
    $classes = SQLSelect("SELECT ID, PARENT_ID FROM classes");
    $classes_by_id = array();
    foreach ($classes as $class) {
        $classes_by_id[(int)$class['ID']] = $class;
    }

    $class_ids = array();
    $visited = array();
    $current_id = (int)$class_id;
    while ($current_id && isset($classes_by_id[$current_id])) {
        if (isset($visited[$current_id])) {
            break;
        }
        $visited[$current_id] = 1;
        $class_ids[] = $current_id;
        $current_id = (int)$classes_by_id[$current_id]['PARENT_ID'];
    }

    if (!isset($class_ids[0])) {
        return array();
    }

    $class_ids_safe = array();
    foreach ($class_ids as $id) {
        $class_ids_safe[] = (int)$id;
    }

    $properties = SQLSelect("SELECT properties.*, classes.TITLE AS CLASS_TITLE
                               FROM properties
                               LEFT JOIN classes ON properties.CLASS_ID=classes.ID
                              WHERE properties.CLASS_ID IN (" . implode(',', $class_ids_safe) . ")
                                AND properties.OBJECT_ID=0
                              ORDER BY FIELD(properties.CLASS_ID, " . implode(',', $class_ids_safe) . "), properties.ID");

    $res = array();
    $known_titles = array();
    foreach ($properties as $property) {
        $property_class_id = (int)$property['CLASS_ID'];
        if ($property_class_id === (int)$class_id) {
            $res[] = $property;
            $known_titles[$property['TITLE']] = 1;
            continue;
        }
        if (!isset($known_titles[$property['TITLE']])) {
            $res[] = $property;
            $known_titles[$property['TITLE']] = 1;
        }
    }
    return $res;
}

/**
 * Summary of getObjectClassTemplate
 * @param mixed $object_name Object name
 * @return mixed
 */
function getClassTemplate($class_id, $view = '')
{

    $can_cache = false;
    if (isset($_SERVER['REQUEST_URI'])) {
        $can_cache = true;
        global $class_templates_cached;
    }

    if ($can_cache && isset($class_templates_cached[$class_id . '_' . $view])) {
        return $class_templates_cached[$class_id . '_' . $view];
    }

    $class = SQLSelectOne("SELECT ID, TITLE, PARENT_ID FROM classes WHERE ID=" . $class_id);
    if (!isset($class['ID']) || !$class['ID']) {
        return '';
    }

    if ($view != '' && file_exists(DIR_TEMPLATES . 'classes/views/' . $class['TITLE'] . '_' . $view . '.html')) {
        $class_file_path = DIR_TEMPLATES . 'classes/views/' . $class['TITLE'] . '_' . $view . '.html';
        $alt_class_file_path = ROOT . 'templates_alt/classes/views/' . $class['TITLE'] . '_' . $view . '.html';
    } else {
        $class_file_path = DIR_TEMPLATES . 'classes/views/' . $class['TITLE'] . '.html';
        $alt_class_file_path = ROOT . 'templates_alt/classes/views/' . $class['TITLE'] . '.html';
    }
    if (file_exists($alt_class_file_path)) {
        $data = LoadFile($alt_class_file_path);
    } elseif (file_exists($class_file_path)) {
        $data = LoadFile($class_file_path);
    } elseif ($class['PARENT_ID']) {
        $data = getClassTemplate($class['PARENT_ID'], $view);
    } else {
        //$data='Template for ['.$class['TITLE'].'] not found';
        $data = '<b>%.object_title%</b>';
        $props = SQLSelect("SELECT ID,TITLE FROM properties WHERE CLASS_ID=" . $class['ID'] . " AND DATA_KEY=1 ORDER BY TITLE");
        if (!isset($props[0])) {
            $props = SQLSelect("SELECT ID,TITLE FROM properties WHERE CLASS_ID=" . $class['ID'] . " ORDER BY TITLE");
        }
        if (is_array($props)) {
            foreach ($props as $k => $v) {
                $data .= ' ' . $v['TITLE'] . ': %.' . $v['TITLE'] . '%';
            }
        }
    }

    if ($can_cache) {
        $class_templates_cached[$class_id . '_' . $view] = $data;
    }

    return $data;
}

/**
 * Summary of getGlobal
 * @param mixed $varname Variable name
 * @return mixed
 */
function getGlobal($varname)
    {
        // TODO: legacy compatibility facade; new code uses the service in lib/core/.
        return \MajorDoMo\Core\Core::objects()->value($varname);
    }

function getKeyData($object_id)
{
    startMeasure('getKeyData');
    $object_rec = SQLSelectOne("SELECT ID,TITLE,CLASS_ID FROM objects WHERE ID=" . (int)$object_id);
    if (!isset($object_rec['CLASS_ID'])) {
        endMeasure('getKeyData');
        return '';
    }
    $props = getClassProperties($object_rec['CLASS_ID']);
    $add_description = '';
    foreach ($props as $k => $v) {
        if ($v['DATA_KEY']) {
            $data = getGlobal($object_rec['TITLE'] . '.' . $v['TITLE']);
            if ($data != '') {
                $add_description .= $v['TITLE'] . ': ' . $data . '; ';
            }
        }
    }
    endMeasure('getKeyData');
    return $add_description;
}

/**
 * Summary of getObject
 * @param mixed $name Object name
 * @access public
 * @return int|objects
 */
function getObject($name)
    {
        // TODO: legacy compatibility facade; new code uses the service in lib/core/.
        return \MajorDoMo\Core\Core::objects()->get($name);
    }

/**
 * Summary of getObjectClassTemplate
 * @param mixed $object_name Object name
 * @return mixed
 */
function getObjectClassTemplate($object_name, $view = '')
{
    startMeasure('getObjectClassTemplate');
    startMeasure('getObject');
    if (isset($_SERVER['REQUEST_URI'])) {
        global $all_objects_cached;
        if (!isset($all_objects_cached)) {
            $objects = SQLSelect("SELECT * FROM objects");
            foreach ($objects as $object) {
                $all_objects_cached[strtolower($object['TITLE'])] = $object;
            }
        }
        $rec = isset($all_objects_cached[strtolower($object_name)]) ? $all_objects_cached[strtolower($object_name)] : array();
    } else {
        $sqlQuery = "SELECT objects.*
                     FROM objects
                    WHERE TITLE = '" . DBSafe($object_name) . "'";
        $rec = SQLSelectOne($sqlQuery);
    }
    //$object=getObject($object_name);
    $object = new stdClass();
    if (!isset($rec['ID']) || !$rec['ID']) {
        return '';
    }
    $object->id = $rec['ID'];
    $object->class_id = $rec['CLASS_ID'];
    $object->description = $rec['DESCRIPTION'];
    endMeasure('getObject');
    startMeasure('getClassTemplate');
    $data = getClassTemplate((int)$object->class_id, $view);
    endMeasure('getClassTemplate');
    $data = preg_replace('/<#ROOTHTML#>/uis', ROOTHTML, $data);
    $data = preg_replace('/%\.object_title%/uis', $object_name, $data);
    $data = preg_replace('/%\.object_id%/uis', $object->id, $data);
    $data = preg_replace('/%\.object_description%/uis', $object->description, $data);
    //$data=preg_replace('/%\.([\w\-]+?)%/uis', '%'.$object_name.'.\1'.'%', $data);
    //$data=preg_replace('/%\.(.+?)%/uis', '%'.$object_name.'.\1'.'%', $data);
    $data = preg_replace('/%\.([\w\_\d\-]+)/uis', '%' . $object_name . '.\1' . '', $data);
    endMeasure('getObjectClassTemplate');
    return $data;
}

/**
 * Summary of getObjectsByClass
 * @param mixed $class_name Class name
 * @return array|int
 */
function getObjectsByClass($class_name)
{
    $sqlQuery = "SELECT ID
                  FROM classes
                 WHERE (TITLE = '" . DBSafe(trim($class_name)) . "'
                        OR ID = " . (int)$class_name . "
                       )";

    $class_record = SQLSelectOne($sqlQuery);

    if (!$class_record['ID']) {
        return 0;
    }

    $classes = SQLSelect("SELECT ID, TITLE, PARENT_ID FROM classes");
    $children_by_parent = array();
    foreach ($classes as $class) {
        $parent_id = (int)$class['PARENT_ID'];
        if (!isset($children_by_parent[$parent_id])) {
            $children_by_parent[$parent_id] = array();
        }
        $children_by_parent[$parent_id][] = (int)$class['ID'];
    }

    $class_ids = getObjectsByClassCollectIds((int)$class_record['ID'], $children_by_parent);
    if (!isset($class_ids[0])) {
        return array();
    }

    $class_ids_safe = array();
    foreach ($class_ids as $class_id) {
        $class_ids_safe[] = (int)$class_id;
    }

    $objects_all = SQLSelect("SELECT ID, TITLE, CLASS_ID
                                FROM objects
                               WHERE CLASS_ID IN (" . implode(',', $class_ids_safe) . ")
                               ORDER BY FIELD(CLASS_ID, " . implode(',', $class_ids_safe) . "), ID");

    $objects = array();
    foreach ($objects_all as $object) {
        unset($object['CLASS_ID']);
        $objects[] = $object;
    }


    /*
    $total=count($objects);
    for($i=0;$i<$total;$i++) {
    $objects[$i]=getObject($objects[$i]['TITLE'])
    }
     */

    return $objects;
}

function getObjectsByClassCollectIds($class_id, $children_by_parent, $visited = array())
{
    if (isset($visited[$class_id])) {
        return array();
    }
    $visited[$class_id] = 1;
    $result = array((int)$class_id);
    if (isset($children_by_parent[$class_id])) {
        foreach ($children_by_parent[$class_id] as $child_id) {
            $child_ids = getObjectsByClassCollectIds((int)$child_id, $children_by_parent, $visited);
            foreach ($child_ids as $id) {
                $result[] = $id;
            }
        }
    }
    return $result;
}

/**
 * Summary of getObjectsByProperty
 * @param mixed $property_name Property name
 * @return array|int
 */
function getObjectsByProperty($property_name, $condition = '', $condition_value = '')
{
    if ($condition_value == '' && $condition != '') {
        $condition_value = $condition;
        $condition = '==';
    }

    $pValues = SQLSelect("SELECT objects.TITLE, pvalues.ID AS PVALUE_ID, pvalues.VALUE
                            FROM properties
                            LEFT JOIN pvalues ON pvalues.PROPERTY_ID = properties.ID
                            LEFT JOIN objects ON pvalues.OBJECT_ID = objects.ID
                           WHERE properties.TITLE = '" . DBSafe($property_name) . "'
                           ORDER BY properties.ID, pvalues.ID");
    $totalv = count($pValues);
    if (!$totalv) {
        return 0;
    }

    $found = array();
    for ($iv = 0; $iv < $totalv; $iv++) {
        if (!isset($pValues[$iv]['PVALUE_ID'])) {
            continue;
        }
        $v = $pValues[$iv]['VALUE'];
        if (!$condition) {
            $found[$pValues[$iv]['TITLE']] = 1;
        } elseif (($condition == '=' || $condition == '==') && ($v == $condition_value)) {
            $found[$pValues[$iv]['TITLE']] = 1;
        } elseif (($condition == '>=') && ($v >= $condition_value)) {
            $found[$pValues[$iv]['TITLE']] = 1;
        } elseif (($condition == '>') && ($v > $condition_value)) {
            $found[$pValues[$iv]['TITLE']] = 1;
        } elseif (($condition == '<=') && ($v <= $condition_value)) {
            $found[$pValues[$iv]['TITLE']] = 1;
        } elseif (($condition == '<') && ($v < $condition_value)) {
            $found[$pValues[$iv]['TITLE']] = 1;
        } elseif (($condition == '<>' || $condition == '!=') && ($v != $condition_value)) {
            $found[$pValues[$iv]['TITLE']] = 1;
        }
    }

    $res = array();
    foreach ($found as $k => $v) {
        $res[] = $k;
    }
    return $res;

}

function getRoomObjectByLocation($location_id, $auto_add = 0)
{
    $location_rec = SQLSelectOne("SELECT * FROM locations WHERE ID=" . (int)$location_id);
    $location_title = transliterate($location_rec['TITLE']);
    $location_title = preg_replace('/\W/', '', $location_title);
    if (!$location_title) {
        $location_title = 'Room' . $location_id;
    }
    $room_object = SQLSelectOne("SELECT * FROM objects WHERE TITLE = '" . DBSafe($location_title) . "'");
    if (isset($room_object['ID'])) return $room_object['TITLE'];

    $class_id = addClass("Rooms");
    $room_object = SQLSelectOne("SELECT * FROM objects WHERE LOCATION_ID=" . $location_id . " AND CLASS_ID=" . $class_id);
    if ($room_object['ID']) return $room_object['TITLE'];
    if ($auto_add) {
        $object_id = addClassObject("Rooms", $location_title);
        SQLExec("UPDATE objects SET LOCATION_ID=" . (int)$location_rec['ID'] . ", DESCRIPTION='" . DBSafe($location_rec['TITLE']) . "' WHERE ID=" . $object_id);
        return $location_title;
    } else {
        return '';
    }
}

function getUserObjectByTitle($user_id, $auto_add = 0)
{
    $user_rec = SQLSelectOne("SELECT * FROM users WHERE ID=" . (int)$user_id);
    $user_title = transliterate($user_rec['USERNAME']);
    $user_title = preg_replace('/\W/', '', $user_title);
    if (!$user_title) {
        $user_title = 'User' . $user_id;
    }
    $user_object = SQLSelectOne("SELECT * FROM objects WHERE (TITLE = '" . DBSafe($user_title) . "' OR (DESCRIPTION!='' AND DESCRIPTION = '" . DBSafe1($user_rec['NAME']) . "'))");
    if ($user_object['ID']) return $user_object['TITLE'];
    if ($auto_add) {
        $object_id = addClassObject("Users", $user_title);
        SQLExec("UPDATE objects SET DESCRIPTION='" . DBSafe($user_rec['NAME']) . "' WHERE ID=" . $object_id);
        return $user_title;
    } else {
        return '';
    }
}

/**
 * Summary of getValueIdByName
 * @param mixed $object_name Object name
 * @param mixed $property Property
 * @return int
 */
function getValueIdByName($object_name, $property)
{
    // TODO: legacy compatibility facade.
    return \MajorDoMo\Core\Core::objects()->valueId($object_name, $property);
}

/**
 * Alias for getGlobal
 * @param mixed $varname Variable name
 * @return mixed
 */
function gg($varname)
{
    return getGlobal($varname);
}

function injectObjectMethodCode($method_name, $key, $code)
{
    $tmp = explode('.', $method_name);
    if (isset($tmp[2])) {
        $object_name = $tmp[0] . '.' . $tmp[1];
        $varname = $tmp[2];
    } elseif (isset($tmp[1])) {
        $object_name = $tmp[0];
        $method_name = $tmp[1];
    } else {
        $object_name = 'ThisComputer';
    }
    $obj = getObject($object_name);

    if ($obj) {
        //return $obj->callMethod($method_name, $params);
        $id = $obj->getMethodByName($method_name, $obj->class_id, $obj->id);
        if ($id) {
            $method = SQLSelectOne("SELECT * FROM methods WHERE ID=" . (int)$id);
            if ($method['OBJECT_ID'] != $obj->id) {
                $method = array();
                $method['OBJECT_ID'] = $obj->id;
                $method['TITLE'] = $method_name;
                $method['ID'] = SQLInsert('methods', $method);
            }
            $injection_code = '/* begin injection of {' . $key . '} */' . "\n" . $code . "\n" . '/* end injection of {' . $key . '} */';
            @$old_code = $method['CODE'];
            if (preg_match('/\/\* begin injection of {' . $key . '} \*\/(.*?)\/\* end injection of {' . $key . '} \*\//uis', $method['CODE'], $m)) {
                $current_injection = trim($m[1]);
                if ($current_injection != $code) {
                    $method['CODE'] = str_replace($m[0], $injection_code, $method['CODE']);
                }
            } else {
                $method['CODE'] .= "\n" . $injection_code;
            }
            if ($method['CODE'] != $old_code) {
                SQLUpdate('methods', $method);
            }
            return 1;
        }
    } else {
        return 0;
    }
}

function objectClassChanged($object_id)
{

    include_once(DIR_MODULES . 'objects/objects.class.php');
    $obj = new objects();
    // class changed from $class_changed_from to $rec['CLASS_ID']
    $rec = SQLSelectOne("SELECT * FROM objects WHERE ID=" . (int)$object_id);
    // step 1. take all properties out of class
    $pvalues = SQLSelect("SELECT pvalues.*, properties.TITLE AS PROPERTY_TITLE FROM pvalues LEFT JOIN properties ON pvalues.PROPERTY_ID=properties.ID WHERE properties.CLASS_ID!=0 AND pvalues.OBJECT_ID='" . $rec['ID'] . "'");
    $total = count($pvalues);
    for ($i = 0; $i < $total; $i++) {
        $new_property = array();
        $new_property['TITLE'] = $pvalues[$i]['PROPERTY_TITLE'];
        $new_property['CLASS_ID'] = 0;
        $new_property['OBJECT_ID'] = $rec['ID'];
        //$new_property['VALUE']='';
        $new_property['ID'] = SQLInsert('properties', $new_property);
        // Retarget the key only: a full-row write would overwrite VALUE and
        // LINKED_MODULES changed since the SELECT above.
        SQLExec("UPDATE pvalues SET PROPERTY_ID=" . (int)$new_property['ID'] . " WHERE ID=" . (int)$pvalues[$i]['ID']);
    }
    // step 2. apply matched properties of new class
    $properties = $obj->getParentProperties($rec['CLASS_ID'], '', 1);
    $total = count($properties);
    $property_titles = array();
    for ($i = 0; $i < $total; $i++) {
        $property_titles[$properties[$i]['TITLE']] = 1;
    }

    $pvalues_by_title = array();
    if (count($property_titles)) {
        $titles_safe = array();
        foreach (array_keys($property_titles) as $title) {
            $titles_safe[] = "'" . DBSafe($title) . "'";
        }
        $object_properties = SQLSelect("SELECT pvalues.*, properties.TITLE AS PROPERTY_TITLE
                                          FROM pvalues
                                          LEFT JOIN properties ON pvalues.PROPERTY_ID=properties.ID
                                         WHERE properties.CLASS_ID=0
                                           AND pvalues.OBJECT_ID='" . (int)$rec['ID'] . "'
                                           AND properties.TITLE IN (" . implode(',', $titles_safe) . ")
                                         ORDER BY properties.ID");
        foreach ($object_properties as $object_property) {
            if (!isset($pvalues_by_title[$object_property['PROPERTY_TITLE']])) {
                $pvalues_by_title[$object_property['PROPERTY_TITLE']] = $object_property;
            }
        }
    }

    for ($i = 0; $i < $total; $i++) {
        if (isset($pvalues_by_title[$properties[$i]['TITLE']]['ID'])) {
            $pvalue = $pvalues_by_title[$properties[$i]['TITLE']];
            $old_prop = $pvalue['PROPERTY_ID'];
            SQLExec("UPDATE pvalues SET PROPERTY_ID=" . (int)$properties[$i]['ID'] . " WHERE ID=" . (int)$pvalue['ID']);
            SQLExec("DELETE FROM properties WHERE ID='" . (int)$old_prop . "'");
        }
    }
}

/**
 * Summary of processTitle
 * @param mixed $title Title
 * @param mixed $object Object (default 0)
 * @return mixed
 */
function processTitle($title, $object = 0)
{
    global $title_memory_cache;

    $key = $title;

    if (!$title) {
        return $title;
    }

    startMeasure('processTitle');

    $in_title = substr($title, 0, 100);

    //startMeasure('processTitle ['.$in_title.']');

    if ($in_title != '') {
        if (isset($_SERVER['REQUEST_METHOD']) && isset($title_memory_cache[$key])) {
            return $title_memory_cache[$key];
        }

        if (preg_match('/\[#.+?#\]/is', $title)) {
            startMeasure('processTitleJTemplate');
            if ($object) {
                $jTempl = new \MajorDoMo\Core\Template\JTemplate($title, $object->data, $object);
            } else {
                $jTempl = new \MajorDoMo\Core\Template\JTemplate($title, $data);
            }

            $title = $jTempl->result;
            endMeasure('processTitleJTemplate');
            // return $title;
        } else {

            $title = preg_replace('/%rand%/is', rand(), $title);
            $title = preg_replace('/%([\w\d\.]+?)\.([\w\d\.]+?)\|(\d+)%/uis', '%\1.\2%', $title);
            if (preg_match_all('/%([\w\d\.]+?)\.([\w\d\.]+?)%/uis', $title, $m)) {
                startMeasure('processTitleProperties');

                $total = count($m[0]);

                for ($i = 0; $i < $total; $i++) {
                    $title = str_replace($m[0][$i], getGlobal($m[1][$i] . '.' . $m[2][$i]), $title);
                }

                endMeasure('processTitleProperties');
            }
            if (preg_match_all('/%([\w\d\.]+?)\.([\w\d\.]+?)\|"(.+?)"%/uis', $title, $m)) {
                startMeasure('processTitlePropertiesReplace');

                $total = count($m[0]);

                for ($i = 0; $i < $total; $i++) {
                    $property_name = $m[1][$i] . '.' . $m[2][$i];
                    $data = getGlobal($property_name);
                    $descr = $m[3][$i];
                    $descr = preg_replace('#(?<!\\\)\;#', ";-;;-;", $descr);
                    $descr = preg_replace('#\\\;#', ";", $descr);
                    $tmp = explode(';-;;-;', $descr);
                    $totald = count($tmp);
                    $hsh = array();
                    if ($totald == 1) {
                        if ($data != '') {
                            $hsh[$data] = $descr;
                        } else {
                            $hsh[$data] = '';
                        }
                    } else {
                        for ($id = 0; $id < $totald; $id++) {
                            $item = trim($tmp[$id]);
                            if (preg_match('/(.*?)=(.+)/uis', $item, $md)) {
                                $search_value = $md[1];
                                if ($search_value == '') $search_value = '<empty>';
                                $search_replace = $md[2];
                            } else {
                                $search_value = $id . '';
                                $search_replace = $item;
                            }
                            $hsh[$search_value] = $search_replace;
                        }
                        if ($data == '' && isset($hsh['<empty>'])) {
                            $data = '<empty>';
                        } elseif ($data == '') {
                            $data = '0';
                        }
                    }
                    if (isset($hsh[$data])) {
                        $title = str_replace($m[0][$i], $hsh[$data], $title);
                    }
                }

                endMeasure('processTitlePropertiesReplace');
            }
            if (preg_match_all('/%([\w\d\.]+?)\.([\w\d\.]+?)\|(\w+?)%/uis', $title, $m)) {
                startMeasure('processTitlePropertiesFunction');
                $total = count($m[0]);
                for ($i = 0; $i < $total; $i++) {
                    $data = getGlobal($m[1][$i] . '.' . $m[2][$i]);
                    if (function_exists($m[3][$i])) {
                        $data = call_user_func($m[3][$i], $data);
                    }
                    $title = str_replace($m[0][$i], $data, $title);
                }
                endMeasure('processTitlePropertiesFunction');
            }
            if (preg_match_all('/%([\w\d\.]+?)%/is', $title, $m)) {
                $total = count($m[0]);

                for ($i = 0; $i < $total; $i++) {
                    if (preg_match('/^%\d/is', $m[0][$i])) {
                        continue; // dirty hack, sorry for that
                    }

                    $title = str_replace($m[0][$i], getGlobal($m[1][$i]), $title);
                }
            }

        }


        if (preg_match_all('/<#LANG_(\w+?)#>/is', $title, $m)) {
            $total = count($m[0]);

            for ($i = 0; $i < $total; $i++) {
                $title = str_replace($m[0][$i], constant('LANG_' . $m[1][$i]), $title);
            }
        }
        if (preg_match_all('/\&#060#LANG_(.+?)#\&#062/is', $title, $m)) {
            $total = count($m[0]);

            for ($i = 0; $i < $total; $i++) {
                $title = str_replace($m[0][$i], constant('LANG_' . $m[1][$i]), $title);
            }
        }
    }

    //endMeasure('processTitle ['.$in_title.']', 1);
    if (isset($_SERVER['REQUEST_METHOD'])) {
        $title_memory_cache[$key] = $title;
    }

    endMeasure('processTitle', 1);

    return $title;
}

function returnTypedValue($value)
{
    if (is_numeric($value) && preg_match('/^[\-\d\.]+$/', $value)) {
        if (strpos($value, '.') !== false) {
            return floatval($value);
        } elseif (!preg_match('/^0/', $value)) {
            return (int)$value;
        }
    }
    return $value;
}

/**
 * Alias for runScript
 * @param mixed $script_id Script ID
 * @param mixed $params Parameters
 * @return mixed
 */
function rs($script_id, $params = 0)
{
    return runScript($script_id, $params);
}

/**
 * Alias for callMethod
 * @param mixed $method_name Method name
 * @param mixed $params Params (default 0)
 * @return mixed
 */
function runMethod($method_name, $params = 0)
{
    return callMethod($method_name, $params);
}

/**
 * Summary of setGlobal
 * @param mixed $varname Variable name
 * @param mixed $value Value
 * @param mixed $no_linked No-Linked (default 0)
 * @return int
 */
function setGlobal($varname, $value, $no_linked = 0, $source = '')
    {
        // TODO: legacy compatibility facade; new code uses the service in lib/core/.
        return \MajorDoMo\Core\Core::objects()->setValue($varname, $value, $no_linked, $source);
    }

/**
 * Alias for setGlobal
 * @param mixed $varname Variable name
 * @param mixed $value Value
 * @param mixed $no_linked No-Linked (default 0)
 * @return int
 */
function sg($varname, $value, $no_linked = 0, $source = '')
{
    return setGlobal($varname, $value, $no_linked, $source);
}
