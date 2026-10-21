<?php
/*
* @version 0.2 (auto-set)
*/

if ($this->owner->name == 'panel') {
    $out['CONTROLPANEL'] = 1;
}

$res = SQLSelect("SELECT * FROM locations ORDER BY PRIORITY DESC, TITLE");
$total = is_array($res) ? count($res) : 0;

$object_counts = array();
$count_rows = SQLSelect(
    "SELECT objects.LOCATION_ID, COUNT(*) AS CNT
       FROM objects
       LEFT JOIN classes ON classes.ID = objects.CLASS_ID
      WHERE objects.LOCATION_ID > 0
        AND (classes.TITLE IS NULL OR classes.TITLE != 'Rooms')
      GROUP BY objects.LOCATION_ID"
);
if (is_array($count_rows)) {
    foreach ($count_rows as $count_row) {
        $object_counts[(int)$count_row['LOCATION_ID']] = (int)$count_row['CNT'];
    }
}

$objects_total = 0;
$with_objects = 0;
$with_room = 0;

for ($i = 0; $i < $total; $i++) {
    $location_id = (int)$res[$i]['ID'];
    $objects_count = isset($object_counts[$location_id]) ? $object_counts[$location_id] : 0;

    $room = SQLSelectOne(
        "SELECT objects.ID, objects.TITLE
           FROM objects
           LEFT JOIN classes ON classes.ID = objects.CLASS_ID
          WHERE objects.LOCATION_ID = " . $location_id . "
            AND classes.TITLE = 'Rooms'
          ORDER BY objects.ID
          LIMIT 1"
    );

    $room_title = isset($room['TITLE']) ? (string)$room['TITLE'] : '';
    $room_id = isset($room['ID']) ? (int)$room['ID'] : 0;

    $res[$i]['OBJECTS_TOTAL'] = $objects_count;
    $res[$i]['ROOM_OBJECT'] = $room_title;
    $res[$i]['ROOM_ID'] = $room_id;
    $res[$i]['CAN_UP'] = $i > 0 ? 1 : 0;
    $res[$i]['CAN_DOWN'] = $i < ($total - 1) ? 1 : 0;

    $objects_total += $objects_count;
    if ($objects_count > 0) {
        $with_objects++;
    }
    if ($room_title !== '') {
        $with_room++;
    }

    foreach ($res[$i] as $field => $value) {
        if (is_scalar($value) && $field !== 'ID') {
            $res[$i][$field] = htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
        }
    }
}

$out['RESULT'] = $total ? $res : array();
$out['RESULT_TOTAL'] = $total;
$out['LOCATIONS_TOTAL'] = $total;
$out['LOCATIONS_OBJECTS_TOTAL'] = $objects_total;
$out['LOCATIONS_WITH_OBJECTS_TOTAL'] = $with_objects;
$out['LOCATIONS_WITHOUT_OBJECTS_TOTAL'] = max(0, $total - $with_objects);
$out['LOCATIONS_WITH_ROOM_TOTAL'] = $with_room;
$out['LOCATIONS_WITHOUT_ROOM_TOTAL'] = max(0, $total - $with_room);
