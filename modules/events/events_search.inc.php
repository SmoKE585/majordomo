<?php
/*
* @version 0.2 (redesigned admin UI)
*/

global $session;
if ($this->owner->name == 'panel') {
    $out['CONTROLPANEL'] = 1;
}

$qry = "1";
// QUERY READY
global $save_qry;
if ($save_qry) {
    $qry = $session->get('events_qry');
} else {
    $session->set('events_qry', $qry);
}
if (!$qry) {
    $qry = "1";
}

// FIELDS ORDER
global $sortby;
if (!$sortby) {
    $sortby = $session->get('events_sort');
} else {
    if ($session->get('events_sort') == $sortby) {
        if (Is_Integer(strpos($sortby, ' DESC'))) {
            $sortby = str_replace(' DESC', '', $sortby);
        } else {
            $sortby = $sortby . " DESC";
        }
    }
    $session->set('events_sort', $sortby);
}
if (!$sortby) {
    $sortby = "ID DESC";
}
$out['SORTBY'] = $sortby;

$res = SQLSelect("SELECT * FROM events WHERE $qry ORDER BY EVENT_NAME");

if (isset($_GET['clear_notlinked'])) {
    $total = count($res);
    for ($i = 0; $i < $total; $i++) {
        $objects = SQLSelect("SELECT * FROM events_params WHERE EVENT_ID=" . (int)$res[$i]['ID'] . " AND LINKED_OBJECT!=''");
        if (!isset($objects[0]['ID']) && $res[$i]['DESCRIPTION'] == '') {
            $this->delete_events($res[$i]['ID']);
        }
    }
    $this->redirect('?');
}

if (isset($res[0]['ID'])) {
    $total = count($res);
    for ($i = 0; $i < $total; $i++) {
        $added_ts = strtotime((string)$res[$i]['ADDED']);
        $res[$i]['ADDED_FORMATTED'] = $added_ts ? date('d.m.Y H:i', $added_ts) : (string)$res[$i]['ADDED'];
        $res[$i]['ADDED_ISO'] = $added_ts ? date('c', $added_ts) : '';
        if (!empty($res[$i]['EXPIRE']) && $res[$i]['EXPIRE'] != '0000-00-00 00:00:00') {
            $expire_ts = strtotime((string)$res[$i]['EXPIRE']);
            $res[$i]['EXPIRE_FORMATTED'] = $expire_ts ? date('d.m.Y H:i', $expire_ts) : (string)$res[$i]['EXPIRE'];
        } else {
            $res[$i]['EXPIRE_FORMATTED'] = '';
        }

        $objects = SQLSelect("SELECT * FROM events_params WHERE EVENT_ID=" . (int)$res[$i]['ID'] . " AND LINKED_OBJECT!=''");
        $objects_total = count($objects);
        $objects_titles = array();
        for ($io = 0; $io < $objects_total; $io++) {
            $object_title = $objects[$io]['LINKED_OBJECT'];
            if ($objects[$io]['LINKED_PROPERTY'] != '') {
                $object_title .= '.' . $objects[$io]['LINKED_PROPERTY'];
            } elseif ($objects[$io]['LINKED_METHOD'] != '') {
                $object_title .= '.' . $objects[$io]['LINKED_METHOD'] . '()';
            }
            $objects_titles[] = $object_title;
            $res[$i]['OBJECTS'] .= $objects[$io]['LINKED_OBJECT'] . '.' . $objects[$io]['LINKED_PROPERTY'] . ';';
        }
        $res[$i]['OBJECTS_TITLE'] = implode(', ', $objects_titles);
        $res[$i]['OBJECTS_TOTAL'] = $objects_total;
    }

    $out['ITEMS'] = $this->pathToTree($res);
    $out['RESULT'] = $res;
}

$system_events = SQLSelect("SELECT * FROM events WHERE EVENT_TYPE='system' ORDER BY EVENT_NAME");
$system_events_total = count($system_events);
for ($i = 0; $i < $system_events_total; $i++) {
    $added_ts = strtotime((string)$system_events[$i]['ADDED']);
    $system_events[$i]['ADDED_FORMATTED'] = $added_ts ? date('d.m.Y H:i', $added_ts) : (string)$system_events[$i]['ADDED'];
    $system_events[$i]['ADDED_ISO'] = $added_ts ? date('c', $added_ts) : '';

    $params = SQLSelect("SELECT * FROM events_params WHERE EVENT_ID=" . (int)$system_events[$i]['ID'] . " ORDER BY TITLE");
    $params_total = count($params);
    $param_titles = array();
    $object_titles = array();
    for ($p = 0; $p < $params_total; $p++) {
        $param_titles[] = $params[$p]['TITLE'];
        if ($params[$p]['LINKED_OBJECT'] != '') {
            $object_title = $params[$p]['LINKED_OBJECT'];
            if ($params[$p]['LINKED_PROPERTY'] != '') {
                $object_title .= '.' . $params[$p]['LINKED_PROPERTY'];
            } elseif ($params[$p]['LINKED_METHOD'] != '') {
                $object_title .= '.' . $params[$p]['LINKED_METHOD'] . '()';
            }
            $object_titles[] = $object_title;
        }
    }
    $system_events[$i]['PARAMS_TOTAL'] = $params_total;
    $system_events[$i]['PARAMS_PREVIEW'] = implode(', ', array_slice($param_titles, 0, 3));
    $system_events[$i]['HAS_MORE_PARAMS'] = $params_total > 3 ? 1 : 0;
    $system_events[$i]['OBJECTS_TITLE'] = implode(', ', $object_titles);
    $system_events[$i]['HAS_OBJECTS'] = isset($object_titles[0]) ? 1 : 0;
    $system_events[$i]['EVENT_NAME_HTML'] = htmlspecialchars((string)$system_events[$i]['EVENT_NAME'], ENT_QUOTES);
    $system_events[$i]['DESCRIPTION_HTML'] = htmlspecialchars((string)$system_events[$i]['DESCRIPTION'], ENT_QUOTES);
    $system_events[$i]['OBJECTS_TITLE_HTML'] = htmlspecialchars((string)$system_events[$i]['OBJECTS_TITLE'], ENT_QUOTES);
    $system_events[$i]['PARAMS_PREVIEW_HTML'] = htmlspecialchars((string)$system_events[$i]['PARAMS_PREVIEW'], ENT_QUOTES);
}
$out['SYSTEM_EVENTS'] = $system_events;
$out['SYSTEM_EVENTS_TOTAL'] = $system_events_total;

$events_count = SQLSelectOne("SELECT COUNT(*) AS TOTAL, MAX(ADDED) AS LAST_ADDED FROM events");
$params_count = SQLSelectOne("SELECT COUNT(*) AS TOTAL FROM events_params");
$out['EVENTS_TOTAL'] = isset($events_count['TOTAL']) ? (int)$events_count['TOTAL'] : 0;
$out['EVENTS_PARAMS_TOTAL'] = isset($params_count['TOTAL']) ? (int)$params_count['TOTAL'] : 0;
$last_event_ts = !empty($events_count['LAST_ADDED']) ? strtotime((string)$events_count['LAST_ADDED']) : false;
$out['LAST_EVENT_FORMATTED'] = $last_event_ts ? date('d.m.Y H:i', $last_event_ts) : '';
$out['LAST_EVENT_ISO'] = $last_event_ts ? date('c', $last_event_ts) : '';

$out['RECENT_EVENTS_HTML'] = $this->renderEventsFeedHtml(12);
