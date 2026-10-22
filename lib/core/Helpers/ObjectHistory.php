<?php

/** System helpers. Public names and argument defaults are part of the module API. */

function cleanUpPropertyHistory($property_id, $max_age_days)
{
    $total_removed = 0;
    $property = SQLSelectOne("SELECT * FROM properties WHERE ID=" . (int)$property_id);
    if (isset($property['ID'])) {
        $pvalues = SQLSelect("SELECT ID FROM pvalues WHERE PROPERTY_ID='" . (int)$property_id . "'");
        $total = count($pvalues);
        for ($i = 0; $i < $total; $i++) {
            $total_removed += cleanUpValueHistory($pvalues[$i]['ID'], $max_age_days, $property['DATA_TYPE']);
        }
    }
    return $total_removed;
}

function cleanUpValueHistory($value_id, $max_age_days, $data_type = 0)
{
    $total_removed = 0;

    if (defined('SEPARATE_HISTORY_STORAGE') && SEPARATE_HISTORY_STORAGE == 1) {
        $table_name = 'phistory_value_' . $value_id;
    } else {
        $table_name = 'phistory';
    }

    $start_tm = date('Y-m-d H:i:s', (time() - $max_age_days * 24 * 60 * 60));
    $qry = "VALUE_ID='" . $value_id . "' AND ADDED<('" . $start_tm . "')";

    if ($data_type == 5) {
        $values = SQLSelect("SELECT VALUE FROM $table_name WHERE $qry");
        $totalv = count($values);
        for ($iv = 0; $iv < $totalv; $iv++) {
            $file_path = ROOT . 'cms/images/' . $values[$iv]['VALUE'];
            if ($values[$iv]['VALUE'] != '' && file_exists($file_path)) {
                unlink($file_path);
            }
        }
        $total_removed = $totalv;
    }

    SQLExec("DELETE FROM $table_name WHERE $qry");
    return $total_removed;
}

/**
 * getHistory
 *
 * Return history data
 *
 * @access public
 */
function getHistory($varname, $start_time, $stop_time = 0)
{
    startMeasure('getHistory');
    if ($start_time <= 0) $start_time = (time() + $start_time);
    if ($stop_time <= 0) $stop_time = (time() + $stop_time);

    // Get hist val id
    $id = getHistoryValueId($varname);

    if (defined('SEPARATE_HISTORY_STORAGE') && SEPARATE_HISTORY_STORAGE == 1) {
        $table_name = createHistoryTable($id);
    } else {
        $table_name = 'phistory';
    }

    // Get data
    $data = SQLSelect("SELECT VALUE, ADDED FROM $table_name WHERE VALUE_ID='" . $id . "' AND ADDED>=('" . date('Y-m-d H:i:s', $start_time) . "') AND ADDED<=('" . date('Y-m-d H:i:s', $stop_time) . "') ORDER BY ADDED");

    endMeasure('getHistory');
    return $data;

}

/**
 * getHistoryAvg
 *
 * Return history data
 *
 * @access public
 */
function getHistoryAvg($varname, $start_time, $stop_time = 0, $integral = false)
{
    startMeasure('getHistoryAvg');
    if ($start_time <= 0) {
        $start_time = (time() + $start_time);
        $latest_data = true;
    } else {
        $latest_data = false;
    }
    if ($stop_time <= 0) $stop_time = (time() + $stop_time);

    // Get hist val id
    $id = getHistoryValueId($varname);
    if (defined('SEPARATE_HISTORY_STORAGE') && SEPARATE_HISTORY_STORAGE == 1) {
        $table_name = createHistoryTable($id);
    } else {
        $table_name = 'phistory';
    }

    $data = [];
    if ($integral) { // Calculate average value using integral trapezoidal rule 
        // and interpolating start/stop values when no data exist on given timestamps
        // read history values from DB
        $firstValue = SQLSelectOne("SELECT VALUE, UNIX_TIMESTAMP(ADDED) AS ADDED FROM $table_name WHERE VALUE_ID='" . $id . "' AND ADDED<('" . date('Y-m-d H:i:s', $start_time) . "') AND ADDED>=('" . date('Y-m-d H:i:s', $start_time - 7 * 24 * 60 * 60) . "') ORDER BY ADDED DESC LIMIT 1");
        if (!isset($firstValue['VALUE'])) {
            $firstValue = SQLSelectOne("SELECT VALUE, UNIX_TIMESTAMP(ADDED) AS ADDED FROM $table_name WHERE VALUE_ID='" . $id . "' AND ADDED<('" . date('Y-m-d H:i:s', $start_time) . "') ORDER BY ADDED DESC LIMIT 1");
        }
        $values = SQLSelect("SELECT UNIX_TIMESTAMP(ADDED) AS ADDED, VALUE AS VALUE FROM $table_name " .
            "WHERE  VALUE != \"\" AND VALUE_ID='" . $id . "' AND ADDED>=('" . date('Y-m-d H:i:s', $start_time) . "') AND ADDED<=('" . date('Y-m-d H:i:s', $stop_time) . "') ORDER BY ADDED ASC");
        $lastValue = SQLSelectOne("SELECT VALUE, UNIX_TIMESTAMP(ADDED) AS ADDED FROM $table_name WHERE VALUE_ID='" . $id . "' AND ADDED>('" . date('Y-m-d H:i:s', $stop_time) . "') ORDER BY ADDED LIMIT 1");
        if (count($values) == 0) {
            $values = [$firstValue];
        }
        if (isset($firstValue['VALUE']) && intval($firstValue['ADDED']) < intval($values[0]['ADDED'])) {
            $values = array_merge([$firstValue], $values);
        }
        if (isset($lastValue['VALUE'])) {
            $values = array_merge($values, [$lastValue]);
        }

        // convert result to $points array with X as timestamp, and Y as float value
        $points = [];
        $valuesCount = count($values);
        for ($i = 0; $i < $valuesCount; $i++) {
            $timestamp = intval($values[$i]['ADDED']);
            $value = floatval($values[$i]['VALUE']);
            $points[] = ['X' => $timestamp, 'Y' => $value];
        }

        if ($valuesCount > 0) {
            // prepare virtual points for $start_time and $stop_time
            $virtualPointX1 = ['X' => $start_time, 'Y' => null];
            $virtualPointX2 = ['X' => $stop_time, 'Y' => null];

            // find points next to start_time and stop_time for interpolation
            $pointBeforeX1 = $pointBeforeX2 = $pointAfterX1 = $pointAfterX2 = null;
            foreach ($points as $point) {
                if ($point['X'] <= $start_time && ($pointBeforeX1 === null || $point['X'] > $pointBeforeX1['X'])) {
                    $pointBeforeX1 = $point;
                }
                if ($point['X'] <= $stop_time && ($pointBeforeX2 === null || $point['X'] > $pointBeforeX2['X'])) {
                    $pointBeforeX2 = $point;
                }
                if ($point['X'] >= $start_time && ($pointAfterX1 === null || $point['X'] < $pointAfterX1['X'])) {
                    $pointAfterX1 = $point;
                }
                if ($point['X'] >= $stop_time && ($pointAfterX2 === null || $point['X'] < $pointAfterX2['X'])) {
                    $pointAfterX2 = $point;
                }
            }
            if ($pointBeforeX1 === null) {
                $pointBeforeX1 = $pointAfterX1;
            }
            if ($pointAfterX2 === null) {
                $pointAfterX2 = $pointBeforeX2;
            }

            // interpolate virtual values Y1 and Y2
            if (!function_exists('getHistoryAvgLinearInterpolation')) {
                function getHistoryAvgLinearInterpolation($x, $x1, $y1, $x2, $y2)
                {
                    if ($x1 == $x2) {
                        return $y1;
                    }
                    return $y1 + (($x - $x1) / ($x2 - $x1)) * ($y2 - $y1);
                }
            }
            $virtualPointX1['Y'] = getHistoryAvgLinearInterpolation($start_time, $pointBeforeX1['X'], $pointBeforeX1['Y'], $pointAfterX1['X'], $pointAfterX1['Y']);
            $virtualPointX2['Y'] = getHistoryAvgLinearInterpolation($stop_time, $pointBeforeX2['X'], $pointBeforeX2['Y'], $pointAfterX2['X'], $pointAfterX2['Y']);

            // add virtual points into array
            $pointsWithVirtual = [];
            foreach ($points as $point) {
                $pointsWithVirtual[] = $point;
                if ($point === $pointBeforeX1) {
                    $pointsWithVirtual[] = $virtualPointX1;
                }
                if ($point === $pointBeforeX2) {
                    $pointsWithVirtual[] = $virtualPointX2;
                }
            }

            // find indicies of virtual points
            $indexX1 = array_search($virtualPointX1, $pointsWithVirtual);
            $indexX2 = array_search($virtualPointX2, $pointsWithVirtual);

            // calculate average value using Trapezoidal rule
            $areaUnderCurve = 0;
            for ($i = $indexX1 + 1; $i <= $indexX2; $i++) {
                $areaUnderCurve += ($pointsWithVirtual[$i]['X'] - $pointsWithVirtual[$i - 1]['X']) * (($pointsWithVirtual[$i]['Y'] + $pointsWithVirtual[$i - 1]['Y']) / 2);
            }
            $averageY = $areaUnderCurve / ($virtualPointX2['X'] - $virtualPointX1['X']);
            $data['VALUE'] = $averageY;
        }

    } else { // Simple average value based on all stored values between start and stop timestamps
        // Get data
        $data = SQLSelectOne("SELECT AVG(VALUE+0.0) AS VALUE FROM $table_name " .
            "WHERE  VALUE != \"\" AND VALUE_ID='" . $id . "' AND ADDED>=('" . date('Y-m-d H:i:s', $start_time) . "') AND ADDED<=('" . date('Y-m-d H:i:s', $stop_time) . "')");

        if (!isset($data['VALUE'])) {
            $data = SQLSelectOne("SELECT VALUE+0.0 FROM $table_name " .
                "WHERE  VALUE != \"\" AND VALUE_ID='" . $id . "' AND ADDED<('" . date('Y-m-d H:i:s', $start_time) . "') ORDER BY ADDED DESC LIMIT 1");
        }
    }
    endMeasure('getHistoryAvg');
    if (!isset($data['VALUE']) && $latest_data) return getGlobal($varname);
    if (!isset($data['VALUE'])) return false;

    return $data['VALUE'];
}

function getHistoryAvgDay($varname, $start_time, $stop_time = 0)
{
    startMeasure('getHistoryAvgDay');
    if ($start_time <= 0) $start_time = (time() + $start_time);
    if ($stop_time <= 0) $stop_time = (time() + $stop_time);

    // Get hist val id
    $id = getHistoryValueId($varname);

    if (defined('SEPARATE_HISTORY_STORAGE') && SEPARATE_HISTORY_STORAGE == 1) {
        $table_name = createHistoryTable($id);
    } else {
        $table_name = 'phistory';
    }

    // Get data
    $data = SQLSelect("SELECT round(avg(VALUE),2) VALUE,  date(ADDED) ADDED FROM $table_name WHERE VALUE_ID='" . $id . "' AND ADDED>=('" . date('Y-m-d H:i:s', $start_time) . "') AND ADDED<=('" . date('Y-m-d H:i:s', $stop_time) . "') group by  date(ADDED) ORDER BY ADDED");

    endMeasure('getHistoryAvgDay');

    return $data;

}

/**
 * getHistoryCount
 *
 * Return history data
 *
 * @access public
 */
function getHistoryCount($varname, $start_time, $stop_time = 0)
{

    startMeasure('getHistoryCount');

    if ($start_time <= 0) $start_time = (time() + $start_time);
    if ($stop_time <= 0) $stop_time = (time() + $stop_time);

    // Get hist val id
    $id = getHistoryValueId($varname);
    if (defined('SEPARATE_HISTORY_STORAGE') && SEPARATE_HISTORY_STORAGE == 1) {
        $table_name = createHistoryTable($id);
    } else {
        $table_name = 'phistory';
    }
    // Get data
    $data = SQLSelectOne("SELECT COUNT(VALUE+0.0) AS VALUE FROM $table_name " .
        "WHERE VALUE != \"\" AND VALUE_ID='" . $id . "' AND ADDED>=('" . date('Y-m-d H:i:s', $start_time) . "') AND ADDED<=('" . date('Y-m-d H:i:s', $stop_time) . "')");

    endMeasure('getHistoryCount');

    if (!isset($data['VALUE']))
        return false;

    return $data['VALUE'];
}

/**
 * getHistoryMax
 *
 * Return history data
 *
 * @access public
 */
function getHistoryMax($varname, $start_time, $stop_time = 0)
{
    startMeasure('getHistoryMax');
    if ($start_time <= 0) {
        $start_time = (time() + $start_time);
        $latest_data = true;
    } else {
        $latest_data = false;
    }
    if ($stop_time <= 0) $stop_time = (time() + $stop_time);

    // Get hist val id
    $id = getHistoryValueId($varname);
    if (defined('SEPARATE_HISTORY_STORAGE') && SEPARATE_HISTORY_STORAGE == 1) {
        $table_name = createHistoryTable($id);
    } else {
        $table_name = 'phistory';
    }
    // Get data
    $data = SQLSelectOne("SELECT MAX(VALUE+0.0) AS VALUE FROM $table_name " .
        "WHERE VALUE != \"\" AND  VALUE_ID='" . $id . "' AND ADDED>=('" . date('Y-m-d H:i:s', $start_time) . "') AND ADDED<=('" . date('Y-m-d H:i:s', $stop_time) . "')");

    endMeasure('getHistoryMax');
    if (!isset($data['VALUE']) && $latest_data) return getGlobal($varname);
    if (!isset($data['VALUE'])) return false;

    return $data['VALUE'];
}

/**
 * getHistoryMin
 *
 * Return history data
 *
 * @access public
 */
function getHistoryMin($varname, $start_time, $stop_time = 0)
{
    startMeasure('getHistoryMin');
    if ($start_time <= 0) {
        $start_time = (time() + $start_time);
        $latest_data = true;
    } else {
        $latest_data = false;
    }
    if ($stop_time <= 0) $stop_time = (time() + $stop_time);

    // Get hist val id
    $id = getHistoryValueId($varname);

    if (defined('SEPARATE_HISTORY_STORAGE') && SEPARATE_HISTORY_STORAGE == 1) {
        $table_name = createHistoryTable($id);
    } else {
        $table_name = 'phistory';
    }

    // Get data
    $data = SQLSelectOne("SELECT MIN(VALUE+0.0) AS VALUE FROM $table_name " .
        "WHERE VALUE != \"\" AND VALUE_ID='" . $id . "' AND ADDED>=('" . date('Y-m-d H:i:s', $start_time) . "') AND ADDED<=('" . date('Y-m-d H:i:s', $stop_time) . "')");

    endMeasure('getHistoryMin');

    if (!isset($data['VALUE']) && $latest_data) return getGlobal($varname);
    if (!isset($data['VALUE'])) return false;

    return $data['VALUE'];
}

/**
 * getHistorySum
 *
 * Return history data
 *
 * @access public
 */
function getHistorySum($varname, $start_time, $stop_time = 0)
{
    startMeasure('getHistorySum');
    if ($start_time <= 0) $start_time = (time() + $start_time);
    if ($stop_time <= 0) $stop_time = (time() + $stop_time);

    // Get hist val id
    $id = getHistoryValueId($varname);
    if (defined('SEPARATE_HISTORY_STORAGE') && SEPARATE_HISTORY_STORAGE == 1) {
        $table_name = createHistoryTable($id);
    } else {
        $table_name = 'phistory';
    }
    // Get data
    $data = SQLSelectOne("SELECT SUM(VALUE+0.0) AS VALUE FROM $table_name " .
        "WHERE  VALUE != \"\" AND VALUE_ID='" . $id . "' AND ADDED>=('" . date('Y-m-d H:i:s', $start_time) . "') AND ADDED<=('" . date('Y-m-d H:i:s', $stop_time) . "')");

    endMeasure('getHistorySum');

    if (!isset($data['VALUE']))
        return false;

    return $data['VALUE'];
}

/**
 * getHistoryValue
 *
 * Return history value
 *
 * @access public
 */
function getHistoryValue($varname, $time, $nerest = false)
{

    startMeasure('getHistoryValue');

    $time = (int)$time;
    if ($time <= 0) $time = (time() + $time);

    // Get hist val id
    $id = getHistoryValueId($varname);

    if (defined('SEPARATE_HISTORY_STORAGE') && SEPARATE_HISTORY_STORAGE == 1) {
        $table_name = createHistoryTable($id);
    } else {
        $table_name = 'phistory';
    }

    // Get val before
    $val1 = SQLSelectOne("SELECT VALUE, UNIX_TIMESTAMP(ADDED) AS ADDED FROM $table_name WHERE VALUE_ID='" . $id . "' AND ADDED<=('" . date('Y-m-d H:i:s', $time) . "') AND ADDED>=('" . date('Y-m-d H:i:s', $time - 7 * 24 * 60 * 60) . "') ORDER BY ADDED DESC LIMIT 1");
    if (!isset($val1['VALUE']))
        $val1 = SQLSelectOne("SELECT VALUE, UNIX_TIMESTAMP(ADDED) AS ADDED FROM $table_name WHERE VALUE_ID='" . $id . "' AND ADDED<=('" . date('Y-m-d H:i:s', $time) . "') ORDER BY ADDED DESC LIMIT 1");

    // Get val after
    $val2 = SQLSelectOne("SELECT VALUE, UNIX_TIMESTAMP(ADDED) AS ADDED FROM $table_name WHERE VALUE_ID='" . $id . "' AND ADDED>=('" . date('Y-m-d H:i:s', $time) . "') ORDER BY ADDED LIMIT 1");

    endMeasure('getHistoryValue');

    // Not found values
    if ((!isset($val1['VALUE'])) && (!isset($val2['VALUE'])))
        return false;

    // Only before
    if (isset($val1['VALUE']) && (!isset($val2['VALUE'])))
        return $val1['VALUE'];

    // Only after
    if (!isset($val1['VALUE']) && isset($val2['VALUE']))
        return $val2['VALUE'];

    // Nerest
    if ($nerest) {
        if (($time - (int)$val1['ADDED']) < ((int)$val2['ADDED'] - $time))
            return $val1['VALUE'];
        else
            return $val2['VALUE'];
    } // Interpolation
    else {
        if ((int)$val2['ADDED'] - (int)$val1['ADDED'] == 0)
            return $val1['VALUE'];
        else
            return (int)$val1['VALUE'] + ((int)$val2['VALUE'] - (int)$val1['VALUE']) * ($time - (int)$val1['ADDED']) / ((int)$val2['ADDED'] - (int)$val1['ADDED']);
    }
}

/**
 * getHistoryValueId
 *
 * Return history value id
 *
 * @access public
 */
function getHistoryValueId($varname)
{

    startMeasure('getHistoryValueId');

    $tmp = explode('.', $varname);

    if (isset($tmp[2])) {
        $object_name = $tmp[0] . '.' . $tmp[1];
        $varname = $tmp[2];
    } elseif (isset($tmp[1])) {
        $object_name = $tmp[0];
        $varname = $tmp[1];
    } else
        $object_name = 'ThisComputer';

    // Get object
    $obj = getObject($object_name);
    if (!$obj) {
        endMeasure('getHistoryValueId');
        return false;
    }

    // Get property
    $prop_id = $obj->getPropertyByName($varname, $obj->class_id, $obj->id);
    if ($prop_id == false) return false;

    $rec = SQLSelectOne("SELECT * FROM pvalues WHERE PROPERTY_ID='" . (int)$prop_id . "' AND OBJECT_ID='" . (int)$obj->id . "'");

    endMeasure('getHistoryValue');

    if (!isset($rec['ID']) || !$rec['ID'])
        return false;

    return $rec['ID'];
}
