<?php
/*
* @version 0.2 (auto-set)
*/

if ($this->owner->name == 'panel') {
    $out['CONTROLPANEL'] = 1;
}

$table_name = 'objects';
$rec = SQLSelectOne("SELECT * FROM $table_name WHERE ID='$id'");

if (isset($rec['TITLE'])) {
}

$class_changed_from = '';
if ($this->mode == 'update') {
    $ok = 1;
    // step: default
    if ($this->tab == '') {
        //updating 'TITLE' (varchar, required)

        $rec['TITLE'] = gr('title', 'trim');
        $rec['TITLE'] = str_replace(' ', '', $rec['TITLE']);

        $tmp = SQLSelectOne("SELECT ID FROM objects WHERE TITLE LIKE '" . DBSafe($rec['TITLE']) . "' AND ID!=" . (int)$rec['ID']);
        if (isset($tmp['ID'])) {
            $rec['TITLE'] = '';
        }

        if ($rec['TITLE'] == '') {
            $out['ERR_TITLE'] = 1;
            $ok = 0;
        }
        //updating 'Class' (select, required)
        global $class_id;
        if ($rec['CLASS_ID'] && $class_id != $rec['CLASS_ID']) {
            $class_changed_from = $rec['CLASS_ID'];
        }
        $rec['CLASS_ID'] = $class_id;
        if (!$rec['CLASS_ID']) {
            $out['ERR_CLASS_ID'] = 1;
            $ok = 0;
        }
        //updating 'Description' (text)
        global $description;
        $rec['DESCRIPTION'] = $description;
        //updating 'Location' (select)
        global $location_id;
        $rec['LOCATION_ID'] = (int)$location_id;

        global $keep_history;
        $rec['KEEP_HISTORY'] = (int)$keep_history;


    }
    // step: properties
    if ($this->tab == 'properties') {
    }
    // step: methods
    if ($this->tab == 'methods') {
    }
    // step: history
    if ($this->tab == 'history') {
    }
    //UPDATING RECORD
    if ($ok) {
        if ($rec['ID']) {
            SQLUpdate($table_name, $rec); // update

            if ($class_changed_from) {
                objectClassChanged($rec['ID']);
            }

        } else {
            $new_rec = 1;
            $rec['ID'] = SQLInsert($table_name, $rec); // adding new record
        }
        clearCacheData();
        $out['OK'] = 1;
    } else {
        $out['ERR'] = 1;
    }
}
// step: default
if ($this->tab == '') {
    //options for 'Class' (select)
    $tmp = SQLSelect("SELECT ID, TITLE FROM classes ORDER BY TITLE");
    $classes_total = count($tmp);
    for ($classes_i = 0; $classes_i < $classes_total; $classes_i++) {
        $class_id_opt[$tmp[$classes_i]['ID']] = $tmp[$classes_i]['TITLE'];
    }
    if (isset($rec['CLASS_ID'])) {
        for ($i = 0; $i < $classes_total; $i++) {
            if ($rec['CLASS_ID'] == $tmp[$i]['ID']) $tmp[$i]['SELECTED'] = 1;
        }
    }
    $out['CLASS_ID_OPTIONS'] = $tmp;
    //options for 'Location' (select)
    $tmp = SQLSelect("SELECT ID, TITLE FROM locations ORDER BY TITLE");
    $locations_total = count($tmp);
    for ($locations_i = 0; $locations_i < $locations_total; $locations_i++) {
        $location_id_opt[$tmp[$locations_i]['ID']] = $tmp[$locations_i]['TITLE'];
    }
    if (isset($rec['LOCATION_ID'])) {
        for ($i = 0; $i < $locations_total; $i++) {
            if ($rec['LOCATION_ID'] == $tmp[$i]['ID']) $tmp[$i]['SELECTED'] = 1;
        }
    }
    $out['LOCATION_ID_OPTIONS'] = $tmp;
}
// step: properties
if ($this->tab == 'properties') {

    global $delete_prop;
    if ($delete_prop) {
        $pr = SQLSelectOne("SELECT * FROM properties WHERE ID='" . $delete_prop . "'");
        if ($pr['ID']) {
            $value = SQLSelectOne("SELECT * FROM pvalues WHERE PROPERTY_ID='" . $delete_prop . "' AND OBJECT_ID='" . $rec['ID'] . "'");
            if (!empty($value['ID'])) {
                cleanUpValueHistory($value['ID'], 0, $pr['DATA_TYPE']);
                SQLExec("DELETE FROM pvalues WHERE PROPERTY_ID='" . $delete_prop . "' AND OBJECT_ID='" . $rec['ID'] . "'");
            }
            if (!$pr['CLASS_ID']) {
                SQLExec("DELETE FROM properties WHERE ID='" . $delete_prop . "' AND OBJECT_ID='" . $rec['ID'] . "'");
            }
        }
        clearCacheData();
    }

    if ($this->mode == 'update') {
        clearCacheData();
        $new_property = gr('new_property', 'trim');
        $new_property = str_replace(' ', '', $new_property);
		$new_description = gr('new_description', 'trim');
		$new_history = gr('new_history', 'trim');
		$onchange = gr('onchange', 'trim');
        $new_value = gr('new_value');
		$prop_id = gr('prop_id');
		
		$tmp = SQLSelectOne("SELECT * FROM properties WHERE ID='" . $prop_id . "'");
        if ($new_property != '') {
            $tmp['TITLE'] = $new_property;
            $tmp['OBJECT_ID'] = $rec['ID'];
            $tmp['DESCRIPTION'] = $new_description;
            $tmp['KEEP_HISTORY'] = !empty($new_history) ? $new_history : 0;
            $tmp['ONCHANGE'] = $onchange;
			if(empty($tmp['ID'])){
				//проверяем, есть ли свойство в объекте с таким же именем
				$prop = SQLSelectOne("SELECT * FROM properties WHERE OBJECT_ID='" . $rec['ID'] . "' AND TITLE='" . $new_property . "'");
				if(empty($prop['ID'])){
					$tmp['ID'] = SQLInsert('properties', $tmp);
					if ($new_value != '') {
						setGlobal($rec['TITLE'] . '.' . $new_property, $new_value);
					}
				}
			}else{
				SQLUpdate('properties', $tmp);
				if (getGlobal($rec['TITLE'] . '.' . $new_property) != $new_value) {
					setGlobal($rec['TITLE'] . '.' . $new_property, $new_value);
				}
			}
		}
    }


    include_once(DIR_MODULES . 'classes/classes.class.php');
    $cl = new classes();
    $props = $cl->getParentProperties($rec['CLASS_ID'], '', 1);

    $my_props = SQLSelect("SELECT * FROM properties WHERE OBJECT_ID='" . $rec['ID'] . "'");
    if (isset($my_props[0]['ID'])) {
        foreach ($my_props as $p) {
            $props[] = $p;
        }
    }
	$methods = $cl->getParentMethods($rec['CLASS_ID'], '', 1);
    $total = count($methods);
    for ($i = 0; $i < $total; $i++) {
        $my_meth = SQLSelectOne("SELECT ID FROM methods WHERE OBJECT_ID='" . $rec['ID'] . "' AND TITLE LIKE '" . DBSafe($methods[$i]['TITLE']) . "'");
        $obj_name = SQLSelectOne("SELECT TITLE FROM `objects` WHERE ID = {$rec['ID']}");
        $methods[$i]['OBJECT_TITLE'] = $obj_name['TITLE'];
        if (isset($my_meth['ID'])) {
            $methods[$i]['CUSTOMIZED'] = 1;
        }
    }
    $out['METHODS'] = $methods;

    $total = count($props);
    //print_R($props);exit;
    for ($i = 0; $i < $total; $i++) {
        if (!$props[$i]['KEEP_HISTORY'] && $rec['KEEP_HISTORY'] > 0) {
            $props[$i]['KEEP_HISTORY'] = $rec['KEEP_HISTORY'];
        }
        $value = SQLSelectOne("SELECT * FROM pvalues WHERE PROPERTY_ID='" . $props[$i]['ID'] . "' AND OBJECT_ID='" . $rec['ID'] . "'");
        if ($this->mode == 'update') {
            global ${"value" . $props[$i]['ID']};
            if (isset(${"value" . $props[$i]['ID']})) {
                setGlobal($rec['TITLE'] . "." . $props[$i]['TITLE'], ${"value" . $props[$i]['ID']});
            }
        }
        $props[$i]['VALUE'] = isset($value['VALUE']) ? $value['VALUE'] : '';
        $props[$i]['VALUE_HTML'] = htmlspecialchars($props[$i]['VALUE']);
        $props[$i]['SOURCE'] = isset($value['SOURCE']) ? $value['SOURCE'] : '';
        $props[$i]['SOURCE_HTML'] = htmlspecialchars($props[$i]['SOURCE']);
        $props[$i]['UPDATED'] = isset($value['UPDATED']) ? date('d.m.Y H:i:s', strtotime($value['UPDATED'])) : '';

        $value['LINKED_MODULES'] = isset($value['LINKED_MODULES']) ? explode(',', $value['LINKED_MODULES']) : false;
        $props[$i]['LINKED_MODULES'] = '';
        if (is_array($value['LINKED_MODULES'])) {
            foreach ($value['LINKED_MODULES'] as $prop_link) {
                if (!$prop_link) break;
                $props[$i]['LINKED_MODULES'] .= '<a class="md-object-property-card__linked-module" href="?(panel:{action=' . $prop_link . '})&md=' . $prop_link . '&go_linked_object=' . urlencode($rec['TITLE']) . '&go_linked_property=' . urlencode($props[$i]['TITLE']) . '">' . $prop_link . '</a>';
            }
        }
		
        //Добавим к неклассовым свойствам возможные к привязке методы и исключим из списка уже привязанный метод
        if($props[$i]['CLASS_ID'] == 0){
            $props[$i]['METHODS'] = $methods;
            if(!empty($props[$i]['ONCHANGE'])){
                $counter = count($methods);
                for($m=0; $m < $counter; $m++){
                    if($props[$i]['METHODS'][$m]['TITLE'] == $props[$i]['ONCHANGE']){
                        $delete_method = $m;
                    }
                }
                array_splice($props[$i]['METHODS'], $delete_method, true);
            }
        }
    }
    if ($this->mode == 'update') {
        $this->redirect("?view_mode=" . $this->view_mode . "&id=" . $rec['ID'] . "&tab=" . $this->tab);
    }

    $propsCount = is_array($props) ? count($props) : 0;
    $customPropsCount = is_array($my_props) ? count($my_props) : 0;
    $out['PROPERTIES'] = $props;
    $out['CLASS_PROPERTIES'] = array();
    $out['OBJECT_PROPERTIES'] = array();
    if (is_array($props)) {
        foreach ($props as $propertyItem) {
            if ((int)$propertyItem['CLASS_ID'] > 0) {
                $out['CLASS_PROPERTIES'][] = $propertyItem;
            } else {
                $out['OBJECT_PROPERTIES'][] = $propertyItem;
            }
        }
    }
    $out['PROPERTIES_TOTAL'] = $propsCount;
    $out['CUSTOM_PROPERTIES_TOTAL'] = $customPropsCount;
    $out['CLASS_PROPERTIES_TOTAL'] = max(0, $propsCount - $customPropsCount);
}
// step: methods
if ($this->tab == 'methods') {


    global $overwrite;
    global $delete_meth;

    $codeEditorSettings = array(
        'AUTOCLOSEQUOTES' => 1,
        'WRAPLINES' => 0,
        'SHOWERROR' => 0,
        'UPTOLINE' => 0,
        'THEME' => 'codemirror',
        'MIXLINE' => 20,
        'SHOWLINE' => 20,
        'TURNONSETTINGS' => 0,
        'AUTOSAVE' => 0
    );
    foreach ($codeEditorSettings as $setting => $default) {
        $constantName = 'SETTINGS_CODEEDITOR_' . $setting;
        $out[$constantName] = defined($constantName) ? constant($constantName) : $default;
    }

    if ($delete_meth) {
        $method = SQLSelectOne("SELECT * FROM methods WHERE ID='" . (int)$delete_meth . "'");
        $my_meth = SQLSelectOne("SELECT * FROM methods WHERE OBJECT_ID='" . $rec['ID'] . "' AND TITLE LIKE '" . DBSafe($method['TITLE']) . "'");
        SQLExec("DELETE FROM methods WHERE OBJECT_ID='" . $rec['ID'] . "' AND TITLE LIKE '" . DBSafe($method['TITLE']) . "'");
    }

    if ($overwrite) {
        global $method_id;
        global $code_editor_mode;
        $method = SQLSelectOne("SELECT * FROM methods WHERE ID='" . (int)$method_id . "'");

        if ($method['OBJECT_ID']) {
            $obj = SQLSelectOne("SELECT ID, CLASS_ID FROM objects WHERE ID='" . $method['OBJECT_ID'] . "'");
            $method = SQLSelectOne("SELECT * FROM methods WHERE TITLE LIKE '" . $method['TITLE'] . "' AND CLASS_ID='" . $obj['CLASS_ID'] . "'");
        }

        $out['METHOD_CLASS_ID'] = $method['CLASS_ID'];
        $tmp = SQLSelectOne("SELECT * FROM classes WHERE ID='" . $method['CLASS_ID'] . "'");
        $out['METHOD_CLASS_TITLE'] = $tmp['TITLE'];
        $out['METHOD_TITLE'] = $method['TITLE'];
        $out['METHOD_TITLE_URL'] = urlencode($method['TITLE']);
        $out['OBJECT_TITLE'] = $rec['TITLE'];
        $out['OBJECT_TITLE_URL'] = urlencode($rec['TITLE']);
        $out['METHOD_ID'] = $method['ID'];
        $my_meth = SQLSelectOne("SELECT * FROM methods WHERE OBJECT_ID='" . $rec['ID'] . "' AND TITLE LIKE '" . DBSafe($method['TITLE']) . "'");

        if ($this->mode == 'update') {
            $ok = 1;
            global $code;
            global $call_parent;
            global $run_type;

            $old_code = $my_meth['CODE'];
            $my_meth['CODE'] = $code;
            $code_editor_mode = gr('code_editor_mode');

            $my_meth['CALL_PARENT'] = $call_parent;
            $my_meth['TITLE'] = $method['TITLE'];
            $my_meth['OBJECT_ID'] = $rec['ID'];

            if ($run_type == 'script') {
                global $script_id;
                $my_meth['SCRIPT_ID'] = $script_id;
            } else {
                $my_meth['SCRIPT_ID'] = 0;
            }

            if ($run_type == 'code' && $my_meth['CODE'] != '') {
                //echo $content;
                $errorDetails = code_syntax_error_details($my_meth['CODE'], $code_editor_mode);

                if ($errorDetails) {
                    $out['ERR_LINE'] = (int)$errorDetails['line'];
                    $out['ERR_CODE'] = 1;
                    $out['ERRORS'] = $errorDetails['message'];
                    $out['ERR_FULL'] = $errorDetails['full'];
                    $ok = 0;
                    $out['OK'] = $ok;
                    $out['ERR_OLD_CODE'] = $old_code;
                    $out['ERR_OLD_CODE_B64'] = base64_encode((string)$old_code);
                }
                $out['CODE'] = $my_meth['CODE'];
            }

            if ($ok) {
                if ($my_meth['ID']) {
                    SQLUpdate('methods', $my_meth);
                } else {
                    $my_meth['ID'] = SQLInsert('methods', $my_meth);
                }
                $out['OK'] = 1;

            }
        }
        if (!$my_meth['ID']) {
            $out['CALL_PARENT'] = 1;
        } else {
            $out['CODE'] = htmlspecialchars($my_meth['CODE']);
            $out['SCRIPT_ID'] = ($my_meth['SCRIPT_ID']);
            $out['CALL_PARENT'] = (int)($my_meth['CALL_PARENT']);
        }
        $out['OVERWRITE'] = 1;
        $out['CODE_EDITOR_MODE'] = normalize_code_editor_mode(isset($code_editor_mode) ? $code_editor_mode : '') ?: ((defined('PYTHON_PATH') && isset($my_meth['CODE']) && isItPythonCode($my_meth['CODE'])) ? 'python' : 'php');
        $out['CODE_EDITOR_VALIDATE'] = in_array($out['CODE_EDITOR_MODE'], array('php', 'python'), true) ? 1 : 0;
    }

    include_once(DIR_MODULES . 'classes/classes.class.php');
    $cl = new classes();
    $methods = $cl->getParentMethods($rec['CLASS_ID'], '', 1);
    $obj_name = SQLSelectOne("SELECT TITLE FROM `objects` WHERE ID = {$rec['ID']}");
    $total = count($methods);
    for ($i = 0; $i < $total; $i++) {
        $my_meth = SQLSelectOne("SELECT ID, CODE, SCRIPT_ID FROM methods WHERE OBJECT_ID='" . $rec['ID'] . "' AND TITLE LIKE '" . DBSafe($methods[$i]['TITLE']) . "'");
        $methods[$i]['OBJECT_TITLE'] = $obj_name['TITLE'];

        $hasClassImplementation = (trim((string)$methods[$i]['CODE']) !== '' || (int)$methods[$i]['SCRIPT_ID'] > 0);
        $hasObjectImplementation = (!empty($my_meth['ID']) && (trim((string)$my_meth['CODE']) !== '' || (int)$my_meth['SCRIPT_ID'] > 0));

        if (isset($my_meth['ID'])) {
            $methods[$i]['CUSTOMIZED'] = 1;
        }

        if ($hasObjectImplementation) {
            $methods[$i]['METHOD_USAGE_LABEL'] = 'Дополнен';
            $methods[$i]['METHOD_USAGE_CLASS'] = 'md-admin-status-badge--danger';
        } elseif ($hasClassImplementation) {
            $methods[$i]['METHOD_USAGE_LABEL'] = 'Классовый';
            $methods[$i]['METHOD_USAGE_CLASS'] = '';
        } else {
            $methods[$i]['METHOD_USAGE_LABEL'] = 'Не используется';
            $methods[$i]['METHOD_USAGE_CLASS'] = 'md-admin-status-badge--muted';
        }
    }
    $out['METHODS'] = $methods;
    $out['METHODS_TOTAL'] = is_array($methods) ? count($methods) : 0;

}
// step: history
if ($this->tab == 'history') {
}
if (is_array($rec)) {
    foreach ($rec as $k => $v) {
        if (!is_array($v)) {
            $rec[$k] = htmlspecialchars($v);
        }
    }
}
outHash($rec, $out);

if (!empty($rec['CLASS_ID'])) {
    $classRec = SQLSelectOne("SELECT ID, TITLE FROM classes WHERE ID=" . (int)$rec['CLASS_ID']);
    if (!empty($classRec['ID'])) {
        $out['CLASS_TITLE'] = htmlspecialchars($classRec['TITLE']);
    }
}

if (!empty($rec['LOCATION_ID'])) {
    $locationRec = SQLSelectOne("SELECT ID, TITLE FROM locations WHERE ID=" . (int)$rec['LOCATION_ID']);
    if (!empty($locationRec['ID'])) {
        $out['LOCATION_TITLE'] = htmlspecialchars($locationRec['TITLE']);
    }
}

if (!isset($rec['ID']) && isset($this->class_id)) {
    $out['CLASS_ID'] = $this->class_id;
}

$out['SCRIPTS'] = SQLSelect("SELECT ID, TITLE FROM scripts ORDER BY TITLE");

if (isset($out['TITLE']) && isset($this->owner) && isset($this->owner->owner) && isset($this->owner->owner->data)) {
    $this->owner->owner->data['TITLE'] = $out['TITLE'];
}
