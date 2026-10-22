<?php
/*
* @version 0.1 (wizard)
*/
global $session;
if ($this->owner->name == 'panel') {
    $out['CONTROLPANEL'] = 1;
}
$qry = "1";
// search filters
//searching 'TITLE' (varchar)

$recently = gr('recently_updated', 'int');
if ($recently) {
    $this->redirect("?view_mode=edit_scripts&id=" . $recently);
}

global $title;
if ($title != '') {
    $qry .= " AND (scripts.TITLE LIKE '%" . DBSafe($title) . "%' OR scripts.DESCRIPTION LIKE '%" . DBSafe($title) . "%' OR scripts.CODE LIKE '%" . DBSafe($title) . "%')";
    $out['TITLE'] = $title;
    $out['ALL_OPEN'] = 1;
}
// QUERY READY
global $save_qry;
if ($save_qry) {
    $qry = $session->get('scripts_qry');
} else {
    $session->set('scripts_qry', $qry);
}
if (!$qry) $qry = "1";
// FIELDS ORDER
global $sortby;
if (!$sortby) {
    $sortby = $session->has('scripts_sort') ? $session->get('scripts_sort') : '';
} else {
    if ($session->has('scripts_sort') ? $session->get('scripts_sort') : '' == $sortby) {
        if (Is_Integer(strpos($sortby, ' DESC'))) {
            $sortby = str_replace(' DESC', '', $sortby);
        } else {
            $sortby = $sortby . " DESC";
        }
    }
    $session->set('scripts_sort', $sortby);
}
$sortby = "script_categories.TITLE, scripts.TITLE";
$out['SORTBY'] = $sortby;
$out['TOTAL_CATEGORIES'] = 0;
// SEARCH RESULTS
//echo $qry;exit;
$res = SQLSelect("SELECT scripts.*, script_categories.TITLE as CATEGORY FROM scripts LEFT JOIN script_categories ON scripts.CATEGORY_ID=script_categories.ID WHERE $qry ORDER BY $sortby");
$old_category = '';
if ($res[0]['ID']) {
    $total = count($res);
    $out['RESULT_TOTAL'] = $total;
    for ($i = 0; $i < $total; $i++) {
        if (!$res[$i]['CATEGORY']) {
            $res[$i]['CATEGORY'] = LANG_OTHER;
        }
        $res[$i]['SEARCH_TEXT'] = htmlspecialchars(
            $res[$i]['TITLE'] . ' ' . $res[$i]['DESCRIPTION'] . ' ' . $res[$i]['CATEGORY'],
            ENT_QUOTES,
            'UTF-8'
        );
        $res[$i]['DESCRIPTION'] = nl2br(htmlspecialchars($res[$i]['DESCRIPTION']));

        $executed_tm = strtotime($res[$i]['EXECUTED']);
        if ($executed_tm > 0) {
            $res[$i]['EXECUTED_PASSED'] = getPassedText($executed_tm);
        }
        $updated_tm = strtotime($res[$i]['UPDATED']);
        if ($updated_tm > 0) {
            $res[$i]['UPDATED_PASSED'] = getPassedText($updated_tm);
        }

        if ($res[$i]['CATEGORY'] != $old_category) {
            $out['TOTAL_CATEGORIES']++;
            $old_category = $res[$i]['CATEGORY'];
            $res[$i]['NEW_CATEGORY'] = 1;
        }

        if ($i == $total - 1) {
            $res[$i]['LAST'] = 1;
        }

    }
    $res[0]['FIRST'] = 1;
    $out['RESULT'] = $res;
}


$recently_updated = SQLSelect("SELECT ID, TITLE, UPDATED FROM scripts ORDER BY UPDATED DESC, ID DESC LIMIT 20");
if ($recently_updated[0]['ID']) {
    $total = count($recently_updated);
    for ($i = 0; $i < $total; $i++) {
        if ($recently_updated[$i]['UPDATED']) {
            $recently_updated[$i]['PASSED'] = getPassedText(strtotime($recently_updated[$i]['UPDATED']));
        } else {
            $recently_updated[$i]['PASSED'] = '...';
        }
    }
    $out['RECENTLY_UPDATED'] = $recently_updated;
}

$out['CATEGORIES'] = SQLSelect("SELECT ID, TITLE FROM script_categories ORDER BY TITLE");

if (!isset($out['RESULT_TOTAL'])) {
    $out['RESULT_TOTAL'] = 0;
}
if (!isset($out['TOTAL_CATEGORIES'])) {
    $out['TOTAL_CATEGORIES'] = 0;
}

$scripts_total = SQLSelectOne("SELECT COUNT(*) AS TOTAL FROM scripts");
$out['TOTAL_SCRIPTS'] = isset($scripts_total['TOTAL']) ? (int)$scripts_total['TOTAL'] : 0;
$categories_total = SQLSelectOne("SELECT COUNT(*) AS TOTAL FROM script_categories");
$out['TOTAL_CATEGORIES_ALL'] = isset($categories_total['TOTAL']) ? (int)$categories_total['TOTAL'] : 0;
$scheduled_total = SQLSelectOne("SELECT COUNT(*) AS TOTAL FROM scripts WHERE RUN_PERIODICALLY=1");
$out['TOTAL_SCHEDULED'] = isset($scheduled_total['TOTAL']) ? (int)$scheduled_total['TOTAL'] : 0;
$executed_total = SQLSelectOne("SELECT COUNT(*) AS TOTAL FROM scripts WHERE EXECUTED >= '1000-01-01 00:00:00'");
$out['TOTAL_EXECUTED'] = isset($executed_total['TOTAL']) ? (int)$executed_total['TOTAL'] : 0;
$uncategorized_total = SQLSelectOne("SELECT COUNT(*) AS TOTAL FROM scripts WHERE CATEGORY_ID=0 OR CATEGORY_ID IS NULL");
$out['TOTAL_UNCATEGORIZED'] = isset($uncategorized_total['TOTAL']) ? (int)$uncategorized_total['TOTAL'] : 0;
