<?php

namespace MajorDoMo\Core;

/**
 * Admin entry point (public/admin.php and /panel/... through nf.php).
 *
 * The behavioral contract is intentionally the same as the old procedural
 * admin.php: same order of config/loader/settings, same part_load handling,
 * same language placeholder replacement and the same output buffering.
 */
final class AdminApplication extends Application
{
    public function run(): void
    {
        if (!defined('ALLOW_RUNNING_WITH_ERRORS')) {
            define('ALLOW_RUNNING_WITH_ERRORS', 1);
        }

        $this->bootConfiguration();

        // A fresh installation must not expose the panel (or module AJAX)
        // before the setup wizard has written .env and the install marker.
        \MajorDoMo\Core\Install\InstallGate::redirectIfRequired($this->root);

        require_once $this->root . '/lib/admin_router.php';

        // Legacy admin URLs carry the whole navigation state in `pd`. Redirect
        // GET requests to the equivalent pretty URL when the round-trip is lossless.
        if (mdj_admin_should_canonicalize_request()) {
            $requestUri = (string)$_SERVER['REQUEST_URI'];
            $prettyUrl = mdj_admin_pretty_url($requestUri);
            if ($prettyUrl !== $requestUri) {
                header('Location: ' . $prettyUrl, true, 302);
                exit;
            }
        }

        startMeasure('loader');
        $this->bootLibraries();
        endMeasure('loader');

        $this->bootSettings();

        if (isset($_GET['part_load']) && checkFromCache('reload:' . md5($_SERVER['REQUEST_URI']))) {
            $res = array();
            $res['TITLE'] = '';
            $res['CONTENT'] = '';
            $res['NEED_RELOAD'] = 1;
            echo json_encode($res);
            exit;
        }

        include_once(DIR_MODULES . "panel.class.php");

        global $session, $md, $action, $fake_doc, $filterblock;
        $this->startSession('prj');

        include_once(DIR_MODULES . "control_modules/control_modules.class.php");
        $cl = new \control_modules();
        $app = new \panel();

        $md = gr('md');
        if ($md == 'scripts' && gr('view_mode') == 'run_script_ajax') {
            $_GET['action'] = 'scripts';
            $_REQUEST['action'] = 'scripts';
            $action = 'scripts';
        }
        if ($md != $app->name) {
            $app->restoreParams();
        } else {
            $app->getParams();
        }

        startMeasure('apprun');
        $result = $app->run();
        endMeasure('apprun');

        startMeasure('part2');

        if (isset($filterblock) and $filterblock != '') {
            $blockPattern = '/<!-- begin_data \[' . $filterblock . '\] -->(.*?)<!-- end_data \[' . $filterblock . '\] -->/is';
            preg_match($blockPattern, $result, $match);
            $result = $match[1];
        }

        startMeasure('languageConstants');
        if (preg_match_all('/&\#060\#LANG_(.+?)\#&\#062/is', $result, $matches)) {
            $total = count($matches[0]);
            for ($i = 0; $i < $total; $i++) {
                if (defined('LANG_' . $matches[1][$i])) {
                    $result = str_replace($matches[0][$i], constant('LANG_' . $matches[1][$i]), $result);
                } else {
                    echo "'" . $matches[1][$i] . "'=>'',<br />";
                }
            }
        }
        endMeasure('languageConstants');

        $result = str_replace("nf.php", "admin.php", $result);

        startMeasure('postProcessGeneral');
        require($this->root . '/lib/utils/postprocess_general.inc.php');
        endMeasure('postProcessGeneral');

        startMeasure('postProcessSubscriptions');
        require($this->root . '/lib/utils/postprocess_subscriptions.inc.php');
        //require($this->root . '/lib/utils/postprocess_result.inc.php');
        endMeasure('postProcessSubscriptions');
        endMeasure('part2');

        if ((defined('ENABLE_PANEL_ACCELERATION') && ENABLE_PANEL_ACCELERATION)) {
            startMeasure('accelerationProcess');
            $result = preg_replace('/href="(\/admin\.php.+?)">/is', 'href="\1" onclick="return partLoad(this.href);">', $result);
            endMeasure('accelerationProcess');
        }

        if (isset($_GET['part_load'])) {
            $res = array();
            $res['TITLE'] = '';
            $res['CONTENT'] = '';
            $res['NEED_RELOAD'] = 1;

            $cut_begin = '<div id="partLoadContent">';
            $cut_begin_index = mb_strpos($result, $cut_begin);
            $cut_end = '</div><!--partloadend-->';
            $cut_end_index = mb_strpos($result, $cut_end);

            if (is_integer($cut_begin_index) && is_integer($cut_end_index)) {
                $cut_begin_index += mb_strlen($cut_begin) + 2;
                $res['CONTENT'] = mb_substr($result, $cut_begin_index, ($cut_end_index - $cut_begin_index));
                $res['NEED_RELOAD'] = 0;
                if (headers_sent()
                    || is_integer(mb_strpos($res['CONTENT'], '$(document).ready'))
                    || is_integer(mb_strpos($res['CONTENT'], '$(function('))) {
                    $res['CONTENT'] = '';
                    $res['NEED_RELOAD'] = 1;
                }
                if (preg_match('/<title>(.+?)<\/title>/is', $result, $m)) {
                    $res['TITLE'] = $m[1];
                }
            } else {
                $res['CONTENT'] = '';
                $res['NEED_RELOAD'] = 1;
            }

            $result = json_encode($res);
            if (is_integer(mb_strpos($result, '"CONTENT":null')) && !$res['NEED_RELOAD']) {
                $res['CONTENT'] = '';
                $res['NEED_RELOAD'] = 1;
                $result = json_encode($res);
            }

            if ($res['NEED_RELOAD']) {
                saveToCache('reload:' . md5($_SERVER['REQUEST_URI']), 1);
            }

            header("HTTP/1.0 200 OK\n");
            header('Content-Type: application/json; charset=utf-8');
            $session->save();
            echo $result;
            exit;
        }

        startMeasure('echoall');

        if (isset($_GET['dynids']) and is_array($_GET['dynids'])) {
            $data = array();
            foreach ($_GET['dynids'] as $data_id) {
                $safe_data_id = preg_quote((string)$data_id, '/');
                if (preg_match('/id="' . $safe_data_id . '">(.+?)<!--\/dynamic_content-->/uis', $result, $m)) {
                    $data['blocks'][] = array('name' => $data_id, 'content' => $m[1]);
                }
            }
            header("Content-type: application/json");
            echo json_encode($data);
            exit;
        }

        if (!headers_sent()) {
            if (http_response_code() !== 404) {
                header("HTTP/1.0 200 OK\n");
            }
            header('Content-Type: text/html; charset=utf-8');
            if (!ob_get_length()) {
                if (!ob_start("ob_gzhandler")) {
                    ob_start();
                }
            }
        }

        echo $result;
        endMeasure('echoall');

        $session->save();

        endMeasure('TOTAL');
        performanceReport();

        ob_end_flush();
    }
}
