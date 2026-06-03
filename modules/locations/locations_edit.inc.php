<?php
/*
* @version 0.1 (auto-set)
*/

if ($this->owner->name == 'panel') {
    $out['CONTROLPANEL'] = 1;
}
$table_name = 'locations';
$rec = SQLSelectOne("SELECT * FROM $table_name WHERE ID='" . (int)$id . "'");
if ($rec['ID']) {
    $locationObject = getRoomObjectByLocation($rec['ID'], 1);
    $out['LINKED_OBJECT']=$locationObject;
}

if ($this->tab == '') {
    if ($rec['ID']) {
    }

    if ($this->mode == 'update') {
        $ok = 1;
        //updating 'Title' (varchar, required)
        global $title;
        $rec['TITLE'] = $title;

        global $priority;
        $rec['PRIORITY'] = (int)$priority;

        if ($rec['TITLE'] == '') {
            $out['ERR_TITLE'] = 1;
            $ok = 0;
        }
        //UPDATING RECORD
        if ($ok) {
            if ($rec['ID']) {
                SQLUpdate($table_name, $rec); // update
            } else {
                $new_rec = 1;
                $rec['ID'] = SQLInsert($table_name, $rec); // adding new record
            }
            $out['OK'] = 1;
        } else {
            $out['ERR'] = 1;
        }
    }
}

if ($this->tab == 'logic' && $rec['ID']) {
    $method_name = gr('method');
    $object = getObject($locationObject);

    $methods = $object->getParentMethods($object->class_id, '', 1);
    $total = count($methods);

    if (!$method_name) {
        $method_name = $methods[0]['TITLE'];
    }
    $out['METHOD'] = $method_name;


    for ($i = 0; $i < $total; $i++) {
        if ($methods[$i]['TITLE'] == $out['METHOD']) {
            $methods[$i]['SELECTED'] = 1;
        }
        if ($methods[$i]['DESCRIPTION'] != '') {
            $methods[$i]['DESCRIPTION'] = $methods[$i]['TITLE'] . ' - ' . $methods[$i]['DESCRIPTION'];
        } else {
            $methods[$i]['DESCRIPTION'] = $methods[$i]['TITLE'];
        }
    }
    $out['METHODS'] = $methods;

    $method_id = $object->getMethodByName($method_name, $object->class_id, $object->id);


    $method_rec = SQLSelectOne("SELECT * FROM methods WHERE ID=" . (int)$method_id);

    if ($method_rec['OBJECT_ID'] != $object->id) {
        $method_rec = array();
        $method_rec['OBJECT_ID'] = $object->id;
        $method_rec['TITLE'] = $method_name;
        $method_rec['CALL_PARENT'] = 1;
        $method_rec['ID'] = SQLInsert('methods', $method_rec);
    }


    if (defined('SETTINGS_CODEEDITOR_TURNONSETTINGS')) {
        $out['SETTINGS_CODEEDITOR_TURNONSETTINGS'] = SETTINGS_CODEEDITOR_TURNONSETTINGS;
        $out['SETTINGS_CODEEDITOR_UPTOLINE'] = SETTINGS_CODEEDITOR_UPTOLINE;
        $out['SETTINGS_CODEEDITOR_SHOWERROR'] = SETTINGS_CODEEDITOR_SHOWERROR;
    }

        if ($this->mode == 'update') {
            $code = gr('code');
            $old_code = $method_rec['CODE'];
            $method_rec['CODE'] = $code;

            $ok = 1;
            if ($method_rec['CODE'] != '') {
                $errorDetails = code_syntax_error_details($method_rec['CODE']);

                if ($errorDetails) {
                    $out['ERR_LINE'] = (int)$errorDetails['line'];
                    $out['ERR_CODE'] = 1;
                    $out['ERRORS'] = $errorDetails['message'];
                    $out['ERR_FULL'] = $errorDetails['full'];
                    $out['ERR_OLD_CODE'] = $old_code;
                    $ok = 0;
                }
            }
        if ($ok) {
            SQLUpdate('methods', $method_rec);
            $out['OK'] = 1;
        } else {
            $out['ERR'] = 1;
        }
    }
    $out['CODE'] = htmlspecialchars($method_rec['CODE']);
    $out['OBJECT_ID'] = $method_rec['OBJECT_ID'];

    $parent_method_id = $object->getMethodByName($method_name, $object->class_id, 0);
    if ($parent_method_id) {
        $out['METHOD_ID'] = $parent_method_id;
    } else {
        $out['METHOD_ID'] = $method_rec['ID'];
    }

}

if (is_array($rec)) {
    foreach ($rec as $k => $v) {
        if (!is_array($v)) {
            $rec[$k] = htmlspecialchars($v);
        }
    }
}
outHash($rec, $out);
