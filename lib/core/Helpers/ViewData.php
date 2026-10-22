<?php

/** System helpers. Public names and argument defaults are part of the module API. */

/**
 * Summary of colorizeArray
 * @param mixed $ar Array
 * @param mixed $every Step (default 2)
 * @return void
 */
function colorizeArray(&$ar, $every = 2)
{
    $arCnt = count($ar);

    for ($i = 0; $i < $arCnt; $i++) {
        if (($i + 1) % $every == 0) {
            $ar[$i]["NEW_COLOR"] = 1;
        }
    }
}

/**
 * Summary of multipleArraySelect
 * @param mixed $in In
 * @param mixed $ar Ar
 * @param mixed $field Field (defaul 'SELECTED')
 * @return void
 */
function multipleArraySelect($in, &$ar, $field = "SELECTED")
{
    $arCnt = count($ar);

    // support for multiple select form elements
    if ((count($in) == 0) || ($arCnt == 0))
        return;

    for ($i = 0; $i < $arCnt; $i++) {
        if (in_array($ar[$i]['TITLE'], $in))
            $ar[$i][$field] = 1;
    }
}

/**
 * making hash-table array from plain array
 * @param mixed $title Title
 * @param mixed $ar Input array
 * @param mixed $out Output array
 * @return void
 */
function outArray($title, $ar, &$out)
{
    $arCnt = count($ar);

    for ($i = 0; $i < $arCnt; $i++) {
        $rec = array();

        $rec['NUM'] = $i + 1;
        $rec['TITLE'] = $ar[$i];
        $out[$title][] = $rec;
    }

    if ($arCnt > 1) {
        if ($arCnt % 2 == 0) {
            $out[$title][(ceil($arCnt) / 2) - 1]['HALF'] = 1;
        } else {
            $out[$title][(int)($arCnt / 2)]['HALF'] = 1;
        }
    }
}

/**
 * Summary of outHash
 * @param mixed $var Var
 * @param mixed $hash Hash
 * @return void
 */
function outHash($var, &$hash)
{
    // merge hash keys and values
    if (is_array($var)) {
        foreach ($var as $k => $v) {
            $hash[$k] = $v;
        }
    }
}

/**
 * Paging
 *
 * This function used to split array into pages and creates some additional output:<br>
 * $out['PAGE'] - current page (int) \n
 * $out['CURRENT_PAGE'] - current page (int) \n
 * $out['NEXTPAGE'] - next page (mixed) \n
 * $out['PREVPAGE'] - previous page (mixed) \n
 * $out['TOTAL'] - array count \n
 * $out['TOTAL_PAGES'] - pages created \n
 * $out['ON_PAGE'] - items per page
 *
 * @access public
 *
 * @param mixed $data Array to split on pages
 * @param int $onPage Items per page
 * @param mixed $out Output hash
 * @return void
 */
function paging(&$data, $onPage, &$out)
{
    // split array using current value of $page parameter
    global $page;

    if (!isset($page))
        $page = 1;

    if (!$onPage)
        $onPage = 30;

    $total_data = count($data);
    $tatal_pages = 0;

    if ($page == "last")
        $page = ceil($total_data / $onPage);

    $out['PAGE'] = $page;
    $from = ((int)$page - 1) * (int)$onPage;
    $selPage = 9999;
    $pages = array();

    for ($i = 0, $j = 1; $i < $total_data; $i += $onPage, $j++) {
        $pages[$j - 1]["NUM"] = (int)($i / $onPage) + 1;
        $tatal_pages++;

        if ($selPage == ($j - 1)) {
            $out["NEXTPAGE"] = $pages[$j - 1];
            $pages[$j - 1]['NEXTPAGE'] = 1;
        }

        if (($from >= $i) && ($from < ($i + $onPage))) {
            $pages[$j - 1]['SELECTED'] = 1;

            if ($j >= 2) {
                $pages[$j - 2]['PREVPAGE'] = 1;

                $out["PREVPAGE"] = $pages[$j - 2];
            }

            $selPage = $j;
        }
    }

    if (count($pages) > 1)
        $out["PAGES"] = $pages;

    $total = $total_data;

    $out["TOTAL"] = $total;
    $out['TOTAL_PAGES'] = $tatal_pages;
    $out['CURRENT_PAGE'] = $page;
    $out['ON_PAGE'] = $onPage;

    $data = array_splice($data, $from, $onPage);
}
