<?php

/** System helpers. Public names and argument defaults are part of the module API. */

/**
 * Summary of checkBadwords
 * @param mixed $s String
 * @param mixed $replace Replace (default 1)
 * @return mixed
 */
function checkBadwords($s, $replace = 1)
{
    global $badwords;

    if (!isset($badwords)) {
        $tmp = SQLSelect("SELECT TITLE FROM badwords");
        $total = count($tmp);

        for ($i = 0; $i < $total; $i++) {
            $badwords[] = strtolower($tmp[$i]['TITLE']);
        }
    }

    $total = count($badwords);

    for ($i = 0; $i < $total; $i++) {
        $badwords[$i] = str_replace('*', '\w+', $badwords[$i]);

        if (preg_match('/\W' . $badwords[$i] . '\W/is', $s)
            || preg_match('/\W' . $badwords[$i] . '$/is', $s)
            || preg_match('/^' . $badwords[$i] . '\W/is', $s)
            || preg_match('/^' . $badwords[$i] . '$/is', $s)
        ) {
            if ($replace) {
                $s = preg_replace('/^' . $badwords[$i] . '$/is', ' ... ', $s);
                $s = preg_replace('/^' . $badwords[$i] . '\W/is', ' ... ', $s);
                $s = preg_replace('/\W' . $badwords[$i] . '\W/is', ' ... ', $s);
                $s = preg_replace('/\W' . $badwords[$i] . '$/is', ' ... ', $s);
            } else {
                return 1;
            }
        }
    }

    if ($replace) {
        return $s;
    } else {
        return 0;
    }
}

/**
 * Summary of checkEmail
 * @param mixed $email Email
 * @return bool
 */
function checkEmail($email)
{
    $pattern = "/^([_a-z0-9-]+)(\.[_a-z0-9-]+)*@([a-z0-9-]+)(\.[a-z0-9-]+)*(\.[a-z]{2,4})$/";

    if (preg_match($pattern, strtolower($email)))
        return true;

    return false;
}

/**
 * Summary of checkGeneral
 * @param mixed $field Field
 * @return int
 */
function checkGeneral($field)
{
    // checking valid general field
    return (strlen($field) >= 2) ? 1 : 0;
}

/**
 * Summary of checkPassword
 * @param mixed $psw Password
 * @return int
 */
function checkPassword($psw)
{
    // checking valid password field
    return (strlen($psw) >= 4) ? 1 : 0;
}

/**
 * Summary of genPassword
 * @param mixed $len Length (default 5)
 * @return string
 */
function genPassword($len = 5)
{
    $len = (int)$len;
    if ($len < 0) {
        throw new InvalidArgumentException('Password length must not be negative');
    }
    $alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
    $last = strlen($alphabet) - 1;
    $password = '';
    for ($i = 0; $i < $len; $i++) {
        $password .= $alphabet[random_int(0, $last)];
    }
    return $password;
}
