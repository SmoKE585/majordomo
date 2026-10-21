<?php
/*
* @version 0.2 (auto-set)
*/

global $session;

if ($this->owner->name == 'panel') {
    $out['CONTROLPANEL'] = 1;
}

$qry = "1";
// SEARCH RESULTS
$res = SQLSelect("SELECT * FROM users WHERE $qry ORDER BY NAME");

$total = is_array($res) ? count($res) : 0;
$admin_total = 0;
$password_total = 0;
$linked_total = 0;
$default_total = 0;
$default_username = '';

for ($i = 0; $i < $total; $i++) {
    // An empty password is stored as a hash of an empty string; treat it as "no password".
    if ($res[$i]['PASSWORD'] != '' && hash('sha512', '') == $res[$i]['PASSWORD']) {
        $res[$i]['PASSWORD'] = '';
    }

    $password_set = ($res[$i]['PASSWORD'] !== '');
    $is_admin = ((int)$res[$i]['IS_ADMIN'] === 1);
    $is_default = ((int)$res[$i]['IS_DEFAULT'] === 1);
    $linked_object = trim((string)$res[$i]['LINKED_OBJECT']);

    if ($is_admin) {
        $admin_total++;
    }
    if ($password_set) {
        $password_total++;
    }
    if ($linked_object !== '') {
        $linked_total++;
    }
    if ($is_default) {
        $default_total++;
        if ($default_username === '') {
            $default_username = (string)$res[$i]['USERNAME'];
        }
    }

    $initials_source = trim((string)$res[$i]['NAME']);
    if ($initials_source === '') {
        $initials_source = trim((string)$res[$i]['USERNAME']);
    }
    $res[$i]['INITIAL'] = $initials_source !== ''
        ? mb_strtoupper(mb_substr($initials_source, 0, 1, 'UTF-8'), 'UTF-8')
        : '?';
    $res[$i]['PASSWORD_SET'] = $password_set ? 1 : 0;
    $res[$i]['ROLE'] = $is_admin ? 'admin' : 'user';

    // Do not pass password hashes to the template.
    unset($res[$i]['PASSWORD']);

    foreach ($res[$i] as $field => $value) {
        if (is_scalar($value) && !in_array($field, array('ID', 'PASSWORD_SET'), true)) {
            $res[$i][$field] = htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
        }
    }
}

$out['RESULT'] = $total ? $res : array();
$out['RESULT_TOTAL'] = $total;
$out['USERS_TOTAL'] = $total;
$out['USERS_ADMIN_TOTAL'] = $admin_total;
$out['USERS_PASSWORD_TOTAL'] = $password_total;
$out['USERS_LINKED_TOTAL'] = $linked_total;
$out['USERS_DEFAULT_TOTAL'] = $default_total;
$out['DEFAULT_USERNAME'] = htmlspecialchars($default_username, ENT_QUOTES, 'UTF-8');
