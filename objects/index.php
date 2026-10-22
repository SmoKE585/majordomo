<?php

/**
 * Object handler project script
 *
 * @package MajorDoMo
 * @author Serge Dzheigalo <sergejey@gmail.com> https://majordomohome.com/
 * @version 1.0
 */

list($usec, $sec) = explode(" ", microtime());
$script_started_time = ((float)$usec + (float)$sec);

Define('ALLOW_RUNNING_WITH_ERRORS', 1);

//Define('MASTER_HOST', 'homenetserver.jbk'); // uncomment to use master host
if (defined('MASTER_URL') && MASTER_URL != '') {
    // redirecting request master URL
    if ($argv[1] != '') {
        $url = 'http://' . MASTER_HOST . '/objects/?source=remote&op=m';
        $total = count($argv);
        for ($i = 2; $i < $total; $i++) {
            if (preg_match('/^(.+?):(.*?)$/is', $argv[$i], $matches))
                $url .= '&' . $matches[1] . '=' . urlencode(trim(win2utf($matches[2])));
        }
    } else {
        $url = 'http://' . MASTER_HOST . (isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/');
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, 5000);
    curl_setopt($ch, CURLOPT_TIMEOUT, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT_MS, 5000);
    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

    $data = curl_exec($ch);
    curl_close($ch);

    exit;
}

// NORMAL HANDLER
chdir(dirname(__FILE__) . '/..');

include_once("./config.php");

// A fresh installation has no settings/objects yet. Keep direct object
// endpoints behind the setup gate as well; CLI invocations are skipped inside.
\MajorDoMo\Core\Install\InstallGate::redirectIfRequired(dirname(__FILE__) . '/..', true);

include_once("./lib/loader.php");

startMeasure('TOTAL'); // start calculation of execution time

include_once(DIR_MODULES . "application.class.php");
include_once("./load_settings.php");


$commandLine = 0;

if (isset($argv[1]) && $argv[1] != '') {
    $commandLine = 1;
    if (preg_match('/^(.+?)\.(.+?)$/is', $argv[1], $matches)) {
        $op = "m";
        $object = $matches[1];
        $m = $matches[2];
    }

    $total = count($argv);

    for ($i = 1; $i < $total; $i++) {
        if (preg_match('/^(.+?)[:=](.*?)$/is', $argv[$i], $matches)) {
            $matchesParsed = trim(win2utf($matches[2]));
            $_GET[$matches[1]] = $matchesParsed;
            ${$matches[1]} = $matchesParsed;
        } else {
            $_GET['other_params'][] = $argv[$i];
            $other_params[] = $argv[$i];
        }
    }
}

if (isset($_SERVER['REQUEST_URI']) && preg_match('/\/\?(\w+)\.(\w+)/', $_SERVER['REQUEST_URI'], $matches)) {
    $_GET['op'] = 'm';
    $_GET['object'] = $matches[1];
    $_GET['m'] = $matches[2];
}

$request = '';
foreach ($_GET as $k => $v) {
    $request .= '&' . $k . '=' . $v;
}

if (!$request && $commandLine) {
    $request = implode(' ', $argv);
}

//echo "object: $object op: $op m: $m status: $status ";exit;
if (!isset($commandLine)) {
    ignore_user_abort(1);
    header('Content-Type: text/html; charset=utf-8');
}

$module = gr('module');
if ($module != '') {
    http_response_code(410);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('error' => 'module_frontend_removed', 'message' => 'Используйте /api/v1/module/{name}.'));
    exit;
}

$job = gr('job');
$object = gr('object');
$method = gr('method');
$script = gr('script');

$v = gr('v');
$p = gr('p');
$m = gr('m');

if ($object != '') {
    $obj = getObject($object);

    if ($obj) {
        //DebMes("object [".$object."] FOUND");
        if ($op == 'get') {
            header('Content-Type: text/plain; charset=utf-8');
            $value = $obj->getProperty($p);
            echo $value;
        }

        if ($op == 'set') {
            $obj->setProperty($p, $v);
            echo "OK";
        }

        if ($op == 'm') {
            $params = array();
            foreach ($_GET as $k => $v) {
                $params[$k] = ${$k};
            }

            //DebMes("Calling method: ".$m.' '.serialize($params));
            //print_r($params);
            $obj->callMethod($m, $params);
        }
    } else {
        DebMes("object [" . $object . "] not found");
    }
} elseif ($job != '') {
    $result = \MajorDoMo\Core\Core::scheduler()->execute((int)$job, (string)gr('claim_token'));
    if (empty($result['success'])) {
        http_response_code(500);
        echo 'ERROR';
    } else echo 'OK';
} elseif ($method != '') {
    $method = str_replace('%', '', $method);
    callMethod($method, $_REQUEST);
} elseif (gr('sayReply')) {
    sayReply(gr('ph'), gr('level'), gr('replyto'));
} elseif (gr('say')) {
    say(gr('ph'), gr('level'), gr('member_id'), gr('source'));
} elseif (gr('sayTo')) {
    sayTo(gr('ph'), gr('level'), gr('destination'));
} elseif (gr('processSubscriptions')) {
    processSubscriptions(gr('event'), json_decode(gr('params'), true));
} elseif (gr('processSubscriptionsOutput')) {
    $output = processSubscriptions(gr('event'), json_decode(gr('params'), true), true);
    if ($output) {
        echo $output;
    }
} elseif ($script != '') {
    runScript($script, $_REQUEST);
}

endMeasure('TOTAL'); // end calculation of execution time
//performanceReport(); // print performance report

// ob_end_flush();
