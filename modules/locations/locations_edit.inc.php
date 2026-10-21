<?php
/*
* @version 0.2 (auto-set)
*/

$codeEditorSettings = array(
    'AUTOCLOSEQUOTES' => 1,
    'WRAPLINES' => 0,
    'SHOWERROR' => 0,
    'UPTOLINE' => 0,
    'THEME' => 'auto',
    'MIXLINE' => 20,
    'SHOWLINE' => 20,
    'TURNONSETTINGS' => 0,
    'AUTOSAVE' => 0,
);
foreach ($codeEditorSettings as $setting => $default) {
    $constantName = 'SETTINGS_CODEEDITOR_' . $setting;
    $value = defined($constantName) ? constant($constantName) : null;
    $out[$constantName] = ($value === null || $value === '') ? $default : $value;
}
$out['CODE_EDITOR_MODE'] = 'php';

if ($this->owner->name == 'panel') {
    $out['CONTROLPANEL'] = 1;
}

$table_name = 'locations';
$rec = SQLSelectOne("SELECT * FROM $table_name WHERE ID='" . (int)$id . "'");
if (!is_array($rec)) {
    $rec = array();
}
$rec += array('ID' => '', 'TITLE' => '', 'PRIORITY' => 0);

$locationObject = '';
$refreshRoomInfo = function ($location_id) use (&$out, &$locationObject) {
    $location_id = (int)$location_id;
    $out['OBJECTS_TOTAL'] = 0;
    $out['ROOM_OBJECT_ID'] = 0;
    $out['LINKED_OBJECT'] = '';
    $locationObject = '';
    if ($location_id <= 0) {
        return;
    }
    $locationObject = getRoomObjectByLocation($location_id, 1);
    $out['LINKED_OBJECT'] = htmlspecialchars((string)$locationObject, ENT_QUOTES, 'UTF-8');
    if ($locationObject !== '') {
        $room = SQLSelectOne("SELECT ID FROM objects WHERE TITLE='" . DBSafe($locationObject) . "'");
        if (isset($room['ID'])) {
            $out['ROOM_OBJECT_ID'] = (int)$room['ID'];
        }
    }
    $count = SQLSelectOne(
        "SELECT COUNT(*) AS CNT
           FROM objects
           LEFT JOIN classes ON classes.ID = objects.CLASS_ID
          WHERE objects.LOCATION_ID = " . $location_id . "
            AND (classes.TITLE IS NULL OR classes.TITLE != 'Rooms')"
    );
    $out['OBJECTS_TOTAL'] = isset($count['CNT']) ? (int)$count['CNT'] : 0;
};
$refreshRoomInfo($rec['ID']);

if ($this->tab == '') {
    if ($this->mode == 'update') {
        $ok = 1;
        $rec['TITLE'] = trim((string)gr('title'));
        $rec['PRIORITY'] = (int)gr('priority');

        if ($rec['TITLE'] === '') {
            $out['ERR_TITLE'] = 1;
            $ok = 0;
        }

        if ($ok) {
            if ($rec['ID']) {
                SQLUpdate($table_name, $rec);
            } else {
                $rec['ID'] = SQLInsert($table_name, $rec);
            }
            $out['OK'] = 1;
            $refreshRoomInfo($rec['ID']);
        } else {
            $out['ERR'] = 1;
        }
    }
}

if ($this->tab == 'logic' && $rec['ID']) {
    $out['NO_METHODS'] = 0;
    $object = $locationObject !== '' ? getObject($locationObject) : null;

    if (!$object) {
        $out['NO_METHODS'] = 1;
    } else {
        $method_name = gr('method');
        $methods = $object->getParentMethods($object->class_id, '', 1);
        $total = is_array($methods) ? count($methods) : 0;

        if (!$total) {
            $out['NO_METHODS'] = 1;
        } else {
            if (!$method_name) {
                $method_name = $methods[0]['TITLE'];
            }
            $out['METHOD'] = htmlspecialchars((string)$method_name, ENT_QUOTES, 'UTF-8');

            for ($i = 0; $i < $total; $i++) {
                if ($methods[$i]['TITLE'] == $method_name) {
                    $methods[$i]['SELECTED'] = 1;
                }
                if ($methods[$i]['DESCRIPTION'] != '') {
                    $methods[$i]['DESCRIPTION'] = $methods[$i]['TITLE'] . ' - ' . $methods[$i]['DESCRIPTION'];
                } else {
                    $methods[$i]['DESCRIPTION'] = $methods[$i]['TITLE'];
                }
                $methods[$i]['TITLE'] = htmlspecialchars((string)$methods[$i]['TITLE'], ENT_QUOTES, 'UTF-8');
                $methods[$i]['DESCRIPTION'] = htmlspecialchars((string)$methods[$i]['DESCRIPTION'], ENT_QUOTES, 'UTF-8');
            }
            $out['METHODS'] = $methods;

            $method_id = $object->getMethodByName($method_name, $object->class_id, $object->id);
            $method_rec = SQLSelectOne("SELECT * FROM methods WHERE ID=" . (int)$method_id);
            if (!is_array($method_rec)) {
                $method_rec = array();
            }

            if (!isset($method_rec['OBJECT_ID']) || $method_rec['OBJECT_ID'] != $object->id) {
                $method_rec = array(
                    'OBJECT_ID' => $object->id,
                    'TITLE' => $method_name,
                    'CALL_PARENT' => 1,
                );
                $method_rec['ID'] = SQLInsert('methods', $method_rec);
            }

            if ($this->mode == 'update') {
                $old_code = isset($method_rec['CODE']) ? (string)$method_rec['CODE'] : '';
                $method_rec['CODE'] = (string)gr('code');
                $ok = 1;

                if ($method_rec['CODE'] !== '') {
                    $errorDetails = code_syntax_error_details($method_rec['CODE']);
                    if ($errorDetails) {
                        $out['ERR_LINE'] = (int)$errorDetails['line'];
                        $out['ERR_CODE'] = 1;
                        $out['ERRORS'] = htmlspecialchars((string)$errorDetails['message'], ENT_QUOTES, 'UTF-8');
                        $full = (string)$errorDetails['full'];
                        if (defined('ROOT') && ROOT !== '') {
                            $full = str_replace(ROOT, '', $full);
                        }
                        $out['ERR_FULL'] = htmlspecialchars($full, ENT_QUOTES, 'UTF-8');
                        $out['ERR_OLD_CODE'] = $old_code;
                        $out['ERR_OLD_CODE_B64'] = base64_encode($old_code);
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

            $out['CODE'] = htmlspecialchars(isset($method_rec['CODE']) ? (string)$method_rec['CODE'] : '', ENT_QUOTES, 'UTF-8');
            $out['OBJECT_ID'] = isset($method_rec['OBJECT_ID']) ? (int)$method_rec['OBJECT_ID'] : (int)$object->id;

            $parent_method_id = $object->getMethodByName($method_name, $object->class_id, 0);
            if ($parent_method_id) {
                $out['METHOD_ID'] = $parent_method_id;
            } else {
                $out['METHOD_ID'] = isset($method_rec['ID']) ? (int)$method_rec['ID'] : 0;
            }
            $out['CODE_EDITOR_KEY'] = 'location_logic_' . (int)$rec['ID'] . '_' . (int)$out['METHOD_ID'];
        }
    }
}

if (is_array($rec)) {
    foreach ($rec as $k => $v) {
        if (!is_array($v)) {
            $rec[$k] = htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        }
    }
}
outHash($rec, $out);
