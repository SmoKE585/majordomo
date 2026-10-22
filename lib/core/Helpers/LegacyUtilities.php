<?php

/** System helpers. Public names and argument defaults are part of the module API. */

/**
 * Summary of mysort_array
 * @param mixed $ar    Array
 * @param mixed $field Sort field (default 'TITLE')
 * @return mixed
 */
function mysort_array($ar, $field = "TITLE")
{
    usort($ar, static function ($left, $right) use ($field) {
        return strcmp($left[$field], $right[$field]);
    });
    return $ar;
}

/**
 * Summary of utf2win
 * @param mixed $in String in utf-8
 * @return string
 */
function utf2win($in)
{
   return iconv('utf-8', 'windows-1251', $in);
}

/**
 * Summary of win2utf
 * @param mixed $in Sting in cp1251
 * @return string
 */
function win2utf($in)
{
   return iconv('windows-1251', 'utf-8', $in);
}
