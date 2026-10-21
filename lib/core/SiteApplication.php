<?php

namespace MajorDoMo\Core;

/**
 * Public site entry point (public/index.php and non-panel routes from nf.php).
 */
final class SiteApplication extends Application
{
    public function run(): void
    {
        $this->bootConfiguration();

        startMeasure('prepare');
        $this->bootLibraries();

        include_once(DIR_MODULES . "application.class.php");

        global $session, $md, $fake_doc, $filterblock;
        $this->startSession('prj');

        startMeasure('load_settings');
        $this->bootSettings();
        endMeasure('load_settings');

        if (!file_exists(ROOT . 'cms/modules_installed/control_modules.installed')) {
            include_once(DIR_MODULES . "control_modules/control_modules.class.php");
            $ctl = new \control_modules();
        }

        $app = new \application();

        $md = gr('md');
        if ($md != $app->name) {
            $app->restoreParams();
        } else {
            $app->getParams();
        }

        if ($app->action != '' && $app->action != 'docs') {
            $fake_doc = '';
        }

        // Unknown frontend paths: nginx falls back to index.php, so render a real 404
        // instead of silently redirecting to the admin dashboard.
        $requestPath = parse_url(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH);
        $rootIndex = rtrim(ROOTHTML, '/') . '/index.php';
        if ($app->action == '' && !isset($fake_doc) && $requestPath !== '/'
            && $requestPath !== $rootIndex && $requestPath !== '/index.php') {
            mdj_render_404(array(
                'root' => ROOTHTML,
                'area' => 'site',
                'path' => isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '',
            ));
        }

        $result = $app->run();
        if ($result) {
            $result = str_replace("nf.php", "index.php", $result);
            require($this->root . '/lib/utils/postprocess_general.inc.php');
            require($this->root . '/lib/utils/postprocess_subscriptions.inc.php');
            require($this->root . '/lib/utils/postprocess_result.inc.php');
        }
        endMeasure('prepare');

        startMeasure('final_echo');

        if (!headers_sent()) {
            header("HTTP/1.0 200 OK\n");
            header('Content-Type: text/html; charset=utf-8');
            header('Access-Control-Allow-Origin: *');
            if (!ob_get_length()) {
                if (!ob_start("ob_gzhandler")) {
                    ob_start();
                }
            }
        }

        if ($result) {
            $this->echoBig($result);
        }

        endMeasure('final_echo', 1);

        $cache_filename = null;
        $cached_result = null;
        if (isset($cache_filename) && $cache_filename != '' && $cached_result == '') {
            SaveFile(ROOT . 'cms/cached/' . $cache_filename, $result);
        }

        $session->save();

        global $wsClient;
        if (isset($wsClient) && $wsClient) {
            $wsClient->disconnect();
        }

        endMeasure('TOTAL');
        performanceReport();

        ob_end_flush();
    }

    /**
     * Echo a large string in chunks without materializing a second copy.
     */
    private function echoBig(string $string, int $bufferSize = 8192): void
    {
        $chars = strlen($string) - 1;
        for ($start = 0; $start <= $chars; $start += $bufferSize) {
            echo substr($string, $start, $bufferSize);
        }
    }
}
