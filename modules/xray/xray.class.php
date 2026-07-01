<?php
/**
 * Blank
 *
 * Blank
 *
 * @package project
 * @author Serge J. <info@atmatic.com>
 * @copyright http://www.activeunit.com/ (c)
 * @version 0.1 (wizard, 17:03:02 [Mar 04, 2010])
 */
//
//
class xray extends module
{
    /**
     * blank
     *
     * Module class constructor
     *
     * @access private
     */
    function __construct()
    {
        $this->name = "xray";
        $this->title = "X-Ray";
        $this->module_category = "<#LANG_SECTION_SYSTEM#>";
        $this->checkInstalled();
        $this->cycle = '';
    }

    /**
     * saveParams
     *
     * Saving module parameters
     *
     * @access public
     */
    function saveParams($data = 1)
    {
        $data = array();
        if (isset($this->id)) {
            $data["id"] = $this->id;
        }
        if (isset($this->view_mode)) {
            $data["view_mode"] = $this->view_mode;
        }
        if (isset($this->edit_mode)) {
            $data["edit_mode"] = $this->edit_mode;
        }
        if (isset($this->tab)) {
            $data["tab"] = $this->tab;
        }
        return parent::saveParams($data);
    }

    /**
     * getParams
     *
     * Getting module parameters from query string
     *
     * @access public
     */
    function getParams()
    {
        global $id;
        global $mode;
        global $view_mode;
        global $edit_mode;
        global $tab;
        if (isset($id)) {
            $this->id = $id;
        }
        if (isset($mode)) {
            $this->mode = $mode;
        }
        if (isset($view_mode)) {
            $this->view_mode = $view_mode;
        }
        if (isset($edit_mode)) {
            $this->edit_mode = $edit_mode;
        }
        if (isset($tab)) {
            $this->tab = $tab;
        }
        global $action;
        if (isset($action)) {
            $this->action = $action;
        }
    }

    /**
     * Run
     *
     * Description
     *
     * @access public
     */
    function run()
    {
        global $action;
        $out = array();
        if ($this->action == 'admin') {
            $this->admin($out);
        } elseif ($this->action == 'service') {
            $this->service_control($out);
        } elseif ($this->action == 'context' || $action == 'context') {
            $this->context($out);
        } else {
            $this->usual($out);
        }
        if (isset($this->owner->action)) {
            $out['PARENT_ACTION'] = $this->owner->action;
        }
        if (isset($this->owner->name)) {
            $out['PARENT_NAME'] = $this->owner->name;
        }
        $out['VIEW_MODE'] = $this->view_mode;
        $out['EDIT_MODE'] = $this->edit_mode;
        $out['MODE'] = $this->mode;
        $out['ACTION'] = $this->action;
        if ($this->single_rec) {
            $out['SINGLE_REC'] = 1;
        }
        $this->data = $out;
        $p = new parser(DIR_TEMPLATES . $this->name . "/" . $this->name . ".html", $this->data, $this);
        $this->result = $p->result;
    }

    function normalizeCycleTitle($cycle)
    {
        $cycle = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$cycle);
        if ($cycle == '') {
            return '';
        }
        if (strpos($cycle, 'cycle_') !== 0) {
            $cycle = 'cycle_' . $cycle;
        }
        return $cycle;
    }

    function ensureCycleRuntimeTables()
    {
        SQLExec('CREATE TABLE IF NOT EXISTS `cached_cycles` (`TITLE` char(100) NOT NULL,`VALUE` char(255) NOT NULL,PRIMARY KEY (`TITLE`)) ENGINE=MEMORY DEFAULT CHARSET=utf8;');
        SQLExec('CREATE TABLE IF NOT EXISTS `cached_cycle_logs` (`ID` int(10) unsigned NOT NULL AUTO_INCREMENT,`CYCLE` char(100) NOT NULL,`ADDED` int(10) unsigned NOT NULL,`MESSAGE` varchar(1024) NOT NULL,PRIMARY KEY (`ID`),KEY `CYCLE_ADDED` (`CYCLE`,`ADDED`)) ENGINE=MEMORY DEFAULT CHARSET=utf8;');
    }

    function addCycleRuntimeLog($cycleTitle, $message)
    {
        $message = trim((string)$message);
        if ($cycleTitle == '' || $message == '') {
            return;
        }
        $this->ensureCycleRuntimeTables();
        $rec = array(
            'CYCLE' => $cycleTitle,
            'ADDED' => time(),
            'MESSAGE' => substr($message, 0, 1000),
        );
        SQLInsert('cached_cycle_logs', $rec);
    }

    function queueCycleCommand($cycleTitle, $command)
    {
        $this->ensureCycleRuntimeTables();
        if ($command == 'start') {
            sg($cycleTitle . 'Run', '');
            sg($cycleTitle . 'Control', 'start');
            saveCycleToCache($cycleTitle . 'Status', 'starting');
        } elseif ($command == 'stop') {
            sg($cycleTitle . 'Control', 'stop');
            saveCycleToCache($cycleTitle . 'Status', 'stopping');
        } elseif ($command == 'restart') {
            sg($cycleTitle . 'Run', '');
            sg($cycleTitle . 'Control', 'restart');
            saveCycleToCache($cycleTitle . 'Status', 'stopping');
        } else {
            return;
        }
        saveCycleToCache($cycleTitle . 'StatusUpdated', time());
        saveCycleToCache($cycleTitle . 'StatusDetails', 'Command queued in XRAY: ' . $command);
        $this->addCycleRuntimeLog($cycleTitle, 'XRAY command queued: ' . $command);
    }

    function getCycleLogPath()
    {
        if (defined('SETTINGS_SYSTEM_DEBMES_PATH') && SETTINGS_SYSTEM_DEBMES_PATH != '') {
            return SETTINGS_SYSTEM_DEBMES_PATH;
        }
        if (defined('LOG_DIRECTORY') && LOG_DIRECTORY != '') {
            return LOG_DIRECTORY;
        }
        return ROOT . 'cms/debmes';
    }

    function isCycleFileLog($filename)
    {
        return (bool)preg_match('/^log_\d{4}-\d{2}-\d{2}-cycle_.+?\.php\.txt$/i', basename((string)$filename));
    }

    function getCycleFileLogLines($cycleTitle, $limit = 80)
    {
        $path = $this->getCycleLogPath();
        if (!is_dir($path)) {
            return array();
        }
        $files = glob(rtrim($path, '/\\') . DIRECTORY_SEPARATOR . 'log_*-'.$cycleTitle.'.php.txt');
        if (!is_array($files) || !count($files)) {
            return array();
        }
        usort($files, function ($a, $b) {
            return filemtime($b) - filemtime($a);
        });
        $file = $files[0];
        if (!is_file($file)) {
            return array();
        }
        $size = filesize($file);
        $readSize = min((int)$size, 131072);
        $fh = @fopen($file, 'rb');
        if (!$fh) {
            return array();
        }
        if ($size > $readSize) {
            fseek($fh, -$readSize, SEEK_END);
        }
        $content = stream_get_contents($fh);
        fclose($fh);
        if ($content === false || $content === '') {
            return array();
        }
        $rawLines = preg_split('/\r\n|\r|\n/', $content);
        $rawLines = array_slice($rawLines, -1 * (int)$limit);
        $lines = array();
        $fileTime = filemtime($file);
        foreach ($rawLines as $line) {
            $line = trim((string)$line);
            if ($line == '') {
                continue;
            }
            $lineTime = $this->extractLogLineTimestamp($line, $fileTime);
            $lines[] = array(
                '_TS'  => $lineTime,
                'ADDED' => date('H:i:s', $lineTime),
                'MESSAGE' => htmlspecialchars('[file] ' . $line),
            );
        }
        return $lines;
    }

    function extractLogLineTimestamp($line, $fallbackTime)
    {
        // PHP error log format: [01-Jul-2026 14:30:00 UTC]
        if (preg_match('/^\[(\d{2}-[A-Z][a-z]{2}-\d{4} \d{2}:\d{2}:\d{2}(?: [A-Z]{3})?)\]/', $line, $m)) {
            $ts = strtotime($m[1]);
            if ($ts !== false && $ts > 0) {
                return $ts;
            }
        }
        // ISO-like format at start: [2026-07-01 14:30:00] or 2026-07-01 14:30:00
        if (preg_match('/^\[?(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]?/', $line, $m)) {
            $ts = strtotime($m[1]);
            if ($ts !== false && $ts > 0) {
                return $ts;
            }
        }
        // Time-only at start: 14:30:00
        if (preg_match('/^(\d{2}:\d{2}:\d{2})/', $line, $m)) {
            $ts = strtotime($m[1]);
            if ($ts !== false && $ts > 0) {
                return $ts;
            }
        }
        return $fallbackTime;
    }

    function reverseResponseList(&$response)
    {
        if (isset($response['LIST']) && is_array($response['LIST'])) {
            $response['LIST'] = array_reverse($response['LIST']);
        } else {
            $response['LIST'] = array();
        }
    }

    function service_control(&$out)
    {

        if (!$this->cycle) {
            $this->cycle = gr('cycle');
        }
        $service = $this->normalizeCycleTitle($this->cycle);
        $out['CYCLE'] = $service;
        $op = gr('op');
        $ajax = gr('ajax');
        if ($ajax) {
            $result = array('cycle' => $service);
            $this->ensureCycleRuntimeTables();

            if ($service != '' && ($op == 'start' || $op == 'stop' || $op == 'restart')) {
                $this->queueCycleCommand($service, $op);
            }

            header("HTTP/1.0 200 OK\n");
            header('Content-Type: application/json; charset=utf-8');

            $updated = gg($service . 'Run');
            $control = gg($service . 'Control');
            $status = checkCycleFromCache($service . 'Status');
            $statusUpdated = (int)checkCycleFromCache($service . 'StatusUpdated');
            $cycleHangTimeout = 10 * 60;
            if ((int)$updated > 0 && (time() - (int)$updated) < $cycleHangTimeout) {
                $status = 'running';
            } elseif ((int)$updated > 0) {
                $status = 'hang';
            } elseif ($status == 'starting' && $statusUpdated > 0 && (time() - $statusUpdated) < $cycleHangTimeout) {
                $status = 'starting';
            } elseif ($status == 'stopping' && $statusUpdated > 0 && (time() - $statusUpdated) < $cycleHangTimeout) {
                $status = 'stopping';
            } else {
                $status = 'stopped';
            }
            $result['UPDATED'] = $updated;
            $result['STATUS'] = $status;
            if ((time() - (int)$updated < $cycleHangTimeout)) {
                $result['ONLINE'] = 1;
                $result['BODY'] = '<font color="green">ONLINE</font>';
            } else {
                $result['ONLINE'] = 0;
                $result['BODY'] = '<font color="red">OFFLINE</font>';
            }
            if ($updated != '') {
                $result['BODY'] .= ' (' . date('Y-m-d H:i:s', $updated) . ')';
            }
            if ($control != '') {
                $result['BODY'] .= ' ' . $control;
            }
            $result['BODY'] .= ' status: ' . htmlspecialchars($status);


            echo json_encode($result);
            exit;
        }
    }

    /**
     * Title
     *
     * Description
     *
     * @access public
     */
    function context(&$out)
    {
        global $ajax;
        if ($ajax) {
            header("HTTP/1.0 200 OK\n");
            header('Content-Type: text/html; charset=utf-8');
            global $op;
            if ($op == 'process') {
                $keyword = gr('keyword');
                $body = gr('body');
                $type = gr('type');
                $found = array();
                $keywords = array();
                $keys = array();
                //processing keywords
                if ($keyword) {
                    $keys[$keyword] = $type;
                }

                if ($body != '') {
                    if (preg_match_all('/runScript\([\'"](.+?)[\'"]/is', $body, $m)) {
                        $total = count($m[0]);
                        for ($i = 0; $i < $total; $i++) {
                            $keys[$m[1][$i]] = 'script';
                        }
                    }
                    if (preg_match_all('/setGlobal\([\'"](.+?)\.(.+?)[\'"]/is', $body, $m)) {
                        $total = count($m[0]);
                        for ($i = 0; $i < $total; $i++) {
                            $keys[$m[1][$i]] = 'object';
                            $keys[$m[1][$i] . '.' . $m[2][$i]] = 'property';
                        }
                    }
                    if (preg_match_all('/getGlobal\([\'"](.+?)\.(.+?)[\'"]/is', $body, $m)) {
                        $total = count($m[0]);
                        for ($i = 0; $i < $total; $i++) {
                            $keys[$m[1][$i]] = 'object';
                            $keys[$m[1][$i] . '.' . $m[2][$i]] = 'property';
                        }
                    }
                    if (preg_match_all('/sg\([\'"](.+?)\.(.+?)[\'"]/is', $body, $m)) {
                        $total = count($m[0]);
                        for ($i = 0; $i < $total; $i++) {
                            $keys[$m[1][$i]] = 'object';
                            $keys[$m[1][$i] . '.' . $m[2][$i]] = 'property';
                        }
                    }
                    if (preg_match_all('/gg\([\'"](.+?)\.(.+?)[\'"]/is', $body, $m)) {
                        $total = count($m[0]);
                        for ($i = 0; $i < $total; $i++) {
                            $keys[$m[1][$i]] = 'object';
                            $keys[$m[1][$i] . '.' . $m[2][$i]] = 'property';
                        }
                    }
                    if (preg_match_all('/callMethod\([\'"](.+?)\.(.+?)[\'"]/is', $body, $m)) {
                        $total = count($m[0]);
                        for ($i = 0; $i < $total; $i++) {
                            $keys[$m[1][$i]] = 'object';
                            $keys[$m[1][$i] . '.' . $m[2][$i]] = 'method';
                        }
                    }
                    if (preg_match_all('/cm\([\'"](.+?)\.(.+?)[\'"]/is', $body, $m)) {
                        $total = count($m[0]);
                        for ($i = 0; $i < $total; $i++) {
                            $keys[$m[1][$i]] = 'object';
                            $keys[$m[1][$i] . '.' . $m[2][$i]] = 'method';
                        }
                    }
                }

                //print_r($keys);echo "<br>";

                foreach ($keys as $k => $v) {
                    if ($v == 'script') {
                        $keywords["runscript(\"" . $k . "\""] = $k;
                        $keywords["runscript('" . $k . "'"] = $k;
                    }
                    if ($v == 'object') {
                        $keywords["setGlobal(\"" . $k . "."] = $k;
                        $keywords["setGlobal('" . $k . "."] = $k;
                        $keywords["sg(\"" . $k . "."] = $k;
                        $keywords["sg('" . $k . "."] = $k;
                    }
                    if ($v == 'property') {
                        $keywords["setGlobal(\"" . $k] = $k;
                        $keywords["setGlobal('" . $k] = $k;
                        $keywords["sg(\"" . $k] = $k;
                        $keywords["sg('" . $k] = $k;
                        $tmp = explode('.', $k);
                        $keywords["->setProperty('" . $tmp[1]] = $tmp[1];
                        $keywords["->setProperty(\"" . $tmp[1]] = $tmp[1];
                    }
                    if ($v == 'method') {
                        $keywords["callMethod(\"" . $k] = $k;
                        $keywords["callMethod('" . $k] = $k;
                        $keywords["cm(\"" . $k] = $k;
                        $keywords["cm('" . $k] = $k;
                        $tmp = explode('.', $k);
                        if (isset($tmp[1])) {
                            $keywords["->callMethod('" . $tmp[1]] = $tmp[1];
                            $keywords["->callMethod(\"" . $tmp[1]] = $tmp[1];
                        }
                    }
                }

                //print_r($keywords);echo "<br>";
                $mdl = new module();

                //processing body for keywords
                //...
                //processing keywords
                foreach ($keywords as $k => $v) {
                    //scripts
                    $scripts = SQLSelect("SELECT ID, TITLE FROM scripts WHERE (CODE LIKE '%" . DBSafe($k) . "%' OR TITLE LIKE '" . DBSafe($v) . "')");
                    $total = count($scripts);
                    for ($i = 0; $i < $total; $i++) {
                        if (!$found['script' . $scripts[$i]['ID']]) {
                            $rec = array();
                            $rec['TYPE'] = 'script';
                            $rec['TITLE'] = $scripts[$i]['TITLE'];
                            $rec['LINK'] = ROOTHTML . 'admin.php?action=scripts&md=scripts&inst=adm&view_mode=edit_scripts&id=' . $scripts[$i]['ID'];
                            $found['script' . $scripts[$i]['ID']] = $rec;
                        }
                    }
                    //objects
                    $objects = SQLSelect("SELECT ID, TITLE, CLASS_ID FROM objects WHERE (TITLE LIKE '" . DBSafe($v) . "')");
                    $total = count($objects);
                    for ($i = 0; $i < $total; $i++) {
                        if (!$found['object' . $scripts[$i]['ID']]) {
                            $rec = array();
                            $rec['TYPE'] = 'object';
                            $rec['TITLE'] = $objects[$i]['TITLE'];
                            $rec['LINK'] = "?(panel:{action=classes}classes:{view_mode=edit_classes,instance=adm,tab=objects,id=" . $objects[$i]['CLASS_ID'] . "})&md=objects&view_mode=edit_objects&id=" . $objects[$i]['ID'];
                            $result = $mdl->parseLinks("<a href=\"" . $rec['LINK'] . "\">");
                            if (preg_match('/\?pd=.+"/', $result, $m)) {
                                $rec['LINK'] = ROOTHTML . 'admin.php' . $m[0];
                            }
                            $found['object' . $objects[$i]['ID']] = $rec;
                        }
                    }
                    //methods
                    $methods = SQLSelect("SELECT methods.ID, methods.TITLE, classes.TITLE AS CLASS, objects.TITLE AS OBJECT, methods.CLASS_ID, methods.OBJECT_ID FROM methods LEFT JOIN classes ON methods.CLASS_ID=classes.ID LEFT JOIN objects ON methods.OBJECT_ID=objects.ID WHERE (methods.CODE LIKE '%" . DBSafe($k) . "%' OR methods.TITLE LIKE '" . DBSafe($v) . "')");
                    $total = count($methods);
                    for ($i = 0; $i < $total; $i++) {
                        if (!isset($found['method' . $methods[$i]['ID']])) {
                            $rec = array();
                            $rec['TYPE'] = 'method';
                            $rec['TITLE'] = $methods[$i]['TITLE'];
                            if ($methods[$i]['OBJECT_ID']) {
                                $rec['LINK'] = "?(panel:{action=classes}classes:{view_mode=edit_classes,instance=adm,tab=objects,id=" . $methods[$i]['CLASS_ID'] . "})&md=objects&view_mode=edit_objects&id=" . $methods[$i]['OBJECT_ID'] . "&tab=methods&overwrite=1&method_id=" . $methods[$i]['ID'];
                                $rec['TITLE'] = $methods[$i]['OBJECT'] . '.' . $rec['TITLE'];
                            } else {
                                $rec['LINK'] = "?(panel:{action=classes}classes:{view_mode=edit_classes,instance=adm,tab=methods,id=" . $methods[$i]['CLASS_ID'] . "})&md=methods&view_mode=edit_methods&id=" . $methods[$i]['ID'];
                                $rec['TITLE'] = $methods[$i]['CLASS'] . ' (class).' . $rec['TITLE'];
                            }
                            $result = $mdl->parseLinks("<a href=\"" . $rec['LINK'] . "\">");
                            if (preg_match('/\?pd=.+"/', $result, $m)) {
                                $rec['LINK'] = ROOTHTML . 'admin.php' . $m[0];
                            }
                            $found['method' . $methods[$i]['ID']] = $rec;
                        }
                    }
                    //properties
                    $properties = SQLSelect("SELECT properties.ID, properties.TITLE, classes.TITLE AS CLASS, objects.TITLE AS OBJECT, properties.CLASS_ID, properties.OBJECT_ID FROM properties LEFT JOIN classes ON properties.CLASS_ID=classes.ID LEFT JOIN objects ON properties.OBJECT_ID=objects.ID WHERE (properties.TITLE LIKE '" . DBSafe($v) . "')");
                    $total = count($properties);
                    for ($i = 0; $i < $total; $i++) {
                        if (!$found['property' . $properties[$i]['ID'] . '_' . $properties[$i]['OBJECT_ID']]) {
                            $rec = array();
                            $rec['TYPE'] = 'property';
                            $rec['TITLE'] = $properties[$i]['TITLE'];
                            if ($properties[$i]['OBJECT_ID']) {
                                $rec['LINK'] = "?(panel:{action=classes}classes:{view_mode=edit_classes,instance=adm,tab=objects,id=" . $properties[$i]['CLASS_ID'] . "})&md=objects&view_mode=edit_objects&id=" . $properties[$i]['OBJECT_ID'] . "&tab=properties";
                                $rec['TITLE'] = $properties[$i]['OBJECT'] . '.' . $rec['TITLE'];
                            } else {
                                $rec['LINK'] = "?(panel:{action=classes}classes:{view_mode=edit_classes,instance=adm,tab=properties,id=" . $properties[$i]['CLASS_ID'] . "})&md=properties&view_mode=edit_properties&id=" . $properties[$i]['ID'];
                                $rec['TITLE'] = $properties[$i]['CLASS'] . ' (class).' . $rec['TITLE'];
                            }
                            $result = $mdl->parseLinks("<a href=\"" . $rec['LINK'] . "\">");
                            if (preg_match('/\?pd=.+"/', $result, $m)) {
                                $rec['LINK'] = ROOTHTML . 'admin.php' . $m[0];
                            }
                            $found['property' . $properties[$i]['ID'] . '_' . $properties[$i]['OBJECT_ID']] = $rec;
                        }
                    }
                    //properties
                    $pvalues = SQLSelect("SELECT pvalues.ID, objects.TITLE AS OBJECT, properties.ID AS PROPERTY_ID, properties.TITLE, properties.CLASS_ID, pvalues.OBJECT_ID FROM pvalues LEFT JOIN properties ON pvalues.PROPERTY_ID=properties.ID LEFT JOIN objects ON pvalues.OBJECT_ID=objects.ID WHERE (properties.TITLE LIKE '" . DBSafe($v) . "')");
                    //print_r($pvalues);
                    $total = count($pvalues);
                    for ($i = 0; $i < $total; $i++) {
                        if (!$found['property' . $pvalues[$i]['PROPERTY_ID'] . '_' . $pvalues[$i]['OBJECT_ID']]) {
                            $rec = array();
                            $rec['TYPE'] = 'property';
                            $rec['TITLE'] = $pvalues[$i]['OBJECT'] . '.' . $pvalues[$i]['TITLE'];
                            $rec['LINK'] = "?(panel:{action=classes}classes:{view_mode=edit_classes,instance=adm,tab=objects,id=" . $pvalues[$i]['CLASS_ID'] . "})&md=objects&view_mode=edit_objects&id=" . $pvalues[$i]['OBJECT_ID'] . "&tab=properties";
                            $result = $mdl->parseLinks("<a href=\"" . $rec['LINK'] . "\">");
                            if (preg_match('/\?pd=.+"/', $result, $m)) {
                                $rec['LINK'] = ROOTHTML . 'admin.php' . $m[0];
                            }
                            $found['property' . $pvalues[$i]['PROPERTY_ID'] . '_' . $pvalues[$i]['OBJECT_ID']] = $rec;
                        }
                    }
                    //menu items
                    //timers
                    //scene elements
                    //web-vars
                }

                foreach ($found as $k => $v) {
                    echo '<a href="' . $v['LINK'] . '" target=_blank>' . $v['TYPE'] . ': ' . $v['TITLE'] . '</a><br/>';
                }

                //print_r($found);
            }
            exit;
        }
        if ($this->keyword) {
            $out['KEYWORD'] = $this->keyword;
        }
        if ($this->code_id) {
            $out['CODE_ID'] = $this->code_id;
        }
        if ($this->type) {
            $out['TYPE'] = $this->type;
        }
    }

    /**
     * BackEnd
     *
     * Module backend
     *
     * @access public
     */
    function admin(&$out)
    {
        $ajax = gr('ajax');

        $out['FILTER'] = gr('filter');
        $out['LINES'] = gr('limit');
        $qry = "";

        if ($this->view_mode == 'services') {
            $this->ensureCycleRuntimeTables();
            $cmd = gr('cmd');
            $service = $this->normalizeCycleTitle(gr('service'));
            if (($cmd == 'start' || $cmd == 'stop' || $cmd == 'restart') && $service != '') {
                $this->queueCycleCommand($service, $cmd);
                /*
               } elseif ($cmd=='switch_restart' && $service!='') {
                if (gg($service.'AutoRestart')) {
                 sg($service.'AutoRestart',0);
                } else {
                 sg($service.'AutoRestart',1);
                }
               } elseif ($cmd=='switch_disabled' && $service!='') {
                if (gg($service.'Disabled')) {
                 sg($service.'Disabled',0);
                } else {
                 sg($service.'Disabled',1);
                }
                */
            }

            if ($cmd != '') {
                $this->redirect(ROOTHTML . "panel/xray.html?view_mode=" . $this->view_mode);
            }

        }
        if ($this->view_mode == 'timers') {
            $cmd = gr('cmd');
            $timer = gr('timer');
            if ($cmd == 'stop' && $timer != '') {
                clearScheduledJob($timer);
            }
        }
        if ($this->view_mode == 'database') {
            $analyze = gr('analyze');
            if ($analyze != '') {
                $result = SQLSelectOne("ANALYZE TABLE " . $analyze . ";");
                foreach ($result as $k => $v) {
                    $out['RESULT'] .= $k . ': ' . $v . "\n";
                }
            }
            $repair = gr('repair');
            if ($repair != '') {
                $result = SQLSelectOne("REPAIR TABLE " . $repair . ";");
                foreach ($result as $k => $v) {
                    $out['RESULT'] .= $k . ': ' . $v . "\n";
                }
            }
            $optimize = gr('optimize');
            if ($optimize != '') {
                $result = SQLSelectOne("OPTIMIZE TABLE " . $optimize . ";");
                foreach ($result as $k => $v) {
                    $out['RESULT'] .= $k . ': ' . $v . "\n";
                }
            }
        }

        if ($this->view_mode == '') {

            if (defined('SETTINGS_SYSTEM_DEBMES_PATH') && SETTINGS_SYSTEM_DEBMES_PATH != '') {
                $path = SETTINGS_SYSTEM_DEBMES_PATH;
            } elseif (defined('LOG_DIRECTORY') && LOG_DIRECTORY != '') {
                $path = LOG_DIRECTORY;
            } else {
                $path = ROOT . 'cms/debmes';
            }
            $path_real = realpath($path);

            $download_file = (string)gr('download_file');
            if ($download_file != '' && $path_real !== false) {
                $download_file = str_replace('\\', '/', $download_file);
                $download_file = ltrim($download_file, '/');
                if (strpos($download_file, "\0") === false) {
                    $requested_file = realpath($path_real . '/' . $download_file);
                    if ($requested_file !== false) {
                        $base_path = rtrim(str_replace('\\', '/', $path_real), '/') . '/';
                        $requested_path = str_replace('\\', '/', $requested_file);
                        if (strpos($requested_path, $base_path) === 0 && is_file($requested_file)) {
                            header('Content-type: text/plain');
                            header('Content-Disposition: attachment; filename="' . basename($requested_file) . '"');
                            readfile($requested_file);
                            exit;
                        }
                    }
                }
            }

            getDirTree($path, $files);
            usort($files, function ($a, $b) {
                return $b['TM'] > $a['TM'];
            });
            $total = count($files);
            $filteredFiles = array();
            for ($i = 0; $i < $total; $i++) {
                if ($this->isCycleFileLog($files[$i]['FILENAME'])) {
                    continue;
                }
                $files[$i]['TITLE'] = str_replace($path . '/', '', $files[$i]['FILENAME']);
                $files[$i]['BASENAME'] = basename($files[$i]['FILENAME']);
                $files[$i]['PASSED'] = getPassedText($files[$i]['TM']);
                $files[$i]['SIZE'] = filesize($files[$i]['FILENAME']);
                if ($files[$i]['SIZE'] > 1024 * 1024) {
                    $files[$i]['SIZE'] = round($files[$i]['SIZE'] / 1024 / 1024, 2) . ' MB';
                } else {
                    $files[$i]['SIZE'] = round($files[$i]['SIZE'] / 1024, 2) . ' KB';
                }
                $filteredFiles[] = $files[$i];
            }
            $files = $filteredFiles;
            $out['FILES'] = $files;
            $selected = gr('files');
            if (!is_array($selected)) {
                $selected = array(date('Y-m-d') . '/debug.log');
            }
            $total_selected_files = 0;
            $out['TODAY_FILES'] = array();
            foreach ($out['FILES'] as &$item) {
                if (in_array($item['TITLE'], $selected)) {
                    $total_selected_files++;
                    $item['SELECTED'] = 1;
                }
                if (date('Y-m-d', $item['TM']) == date('Y-m-d')) {
                    $out['TODAY_FILES'][]=$item;
                }
            }
            unset($item);

            // Fallback: if requested/default files are absent, select the latest available log.
            if ($total_selected_files == 0 && count($out['FILES'])) {
                $out['FILES'][0]['SELECTED'] = 1;
                $total_selected_files = 1;
            }

            usort($out['TODAY_FILES'], function ($a, $b) {
                return strcmp($a['BASENAME'], $b['BASENAME']);
            });

            $out['FILES_TOTAL'] = count($out['FILES']);
            $out['TODAY_FILES_TOTAL'] = count($out['TODAY_FILES']);
            $out['SELECTED_FILES_TOTAL'] = $total_selected_files;

        }

        if ($ajax) {
            $op = gr('op');
            $filter = gr('filter');
            if ($op == 'clearlog') {
                header("HTTP/1.0 200 OK\n");
                header('Content-Type: application/json; charset=utf-8');

                if (defined('SETTINGS_SYSTEM_DEBMES_PATH') && SETTINGS_SYSTEM_DEBMES_PATH != '') {
                    $path = SETTINGS_SYSTEM_DEBMES_PATH;
                } elseif (defined('LOG_DIRECTORY') && LOG_DIRECTORY != '') {
                    $path = LOG_DIRECTORY;
                } else {
                    $path = ROOT . 'cms/debmes';
                }

                $path_real = realpath($path);
                $selected = gr('files');
                if (!is_array($selected) || !count($selected)) {
                    echo json_encode(array('STATUS' => 'ERROR', 'MESSAGE' => 'No files selected'));
                    exit;
                }
                if ($path_real === false) {
                    echo json_encode(array('STATUS' => 'ERROR', 'MESSAGE' => 'Invalid log path'));
                    exit;
                }

                $base_path = str_replace('\\', '/', $path_real);
                $base_path = rtrim($base_path, '/');
                $cleared = 0;
                foreach ($selected as $file) {
                    $file = (string)$file;
                    $file = str_replace('\\', '/', $file);
                    $file = ltrim($file, '/');
                    if ($file === '' || strpos($file, "\0") !== false) {
                        continue;
                    }
                    $target = realpath($path_real . '/' . $file);
                    if ($target === false || !is_file($target)) {
                        continue;
                    }
                    $target_path = str_replace('\\', '/', $target);
                    if (strpos($target_path, $base_path . '/') !== 0 && $target_path !== $base_path) {
                        continue;
                    }
                    if (@file_put_contents($target, '') !== false) {
                        $cleared++;
                    }
                }

                if ($cleared > 0) {
                    echo json_encode(array('STATUS' => 'OK', 'CLEARED' => $cleared));
                } else {
                    echo json_encode(array('STATUS' => 'ERROR', 'MESSAGE' => 'No files cleared'));
                }
                exit;
            }
            if ($op == 'cyclelog') {
                header("HTTP/1.0 200 OK\n");
                header('Content-Type: application/json; charset=utf-8');
                $cycle = gr('cycle');
                $cycle = $this->normalizeCycleTitle($cycle);
                if ($cycle == '') {
                    echo json_encode(array('STATUS' => 'ERROR', 'MESSAGE' => 'Empty cycle name'));
                    exit;
                }
                $logCyclesEnabled = (defined('LOG_CYCLES') && LOG_CYCLES == '1') ? 1 : 0;
                $this->ensureCycleRuntimeTables();
                $res = SQLSelect("SELECT * FROM cached_cycle_logs WHERE CYCLE='" . DBSafe($cycle) . "' ORDER BY ID DESC LIMIT 80");
                $res = array_reverse($res);
                $lines = array();
                $total = count($res);
                for ($i = 0; $i < $total; $i++) {
                    $ts = (int)$res[$i]['ADDED'];
                    $lines[] = array(
                        '_TS'    => $ts,
                        'ADDED'  => date('H:i:s', $ts),
                        'MESSAGE' => htmlspecialchars($res[$i]['MESSAGE']),
                    );
                }
                $fileLines = $logCyclesEnabled ? $this->getCycleFileLogLines($cycle, 80) : array();
                if (count($fileLines)) {
                    $fileTime = 0;
                    if (!empty($fileLines[0]['_TS'])) {
                        $fileTime = $fileLines[0]['_TS'];
                    }
                    $lines[] = array(
                        '_TS'    => $fileTime ? $fileTime : time(),
                        'ADDED'  => date('H:i:s', $fileTime ? $fileTime : time()),
                        'MESSAGE' => htmlspecialchars('--- latest file log tail ---'),
                    );
                    $lines = array_merge($lines, $fileLines);
                    $lines = array_slice($lines, -120);
                }
                // Sort all lines by raw timestamp for correct chronological order
                usort($lines, function ($a, $b) {
                    $ta = isset($a['_TS']) ? (int)$a['_TS'] : 0;
                    $tb = isset($b['_TS']) ? (int)$b['_TS'] : 0;
                    if ($ta == $tb) {
                        return 0;
                    }
                    return ($ta < $tb) ? -1 : 1;
                });
                // Strip internal _TS field before sending to client
                $cleanLines = array();
                foreach ($lines as $l) {
                    $cleanLines[] = array(
                        'ADDED'   => $l['ADDED'],
                        'MESSAGE' => $l['MESSAGE'],
                    );
                }
                echo json_encode(array('STATUS' => 'OK', 'CYCLE' => $cycle, 'LOG_CYCLES_ENABLED' => $logCyclesEnabled, 'LINES' => $cleanLines));
                exit;
            }
            if ($op == 'clearcyclelog') {
                header("HTTP/1.0 200 OK\n");
                header('Content-Type: application/json; charset=utf-8');
                $cycle = gr('cycle');
                $cycle = $this->normalizeCycleTitle($cycle);
                if ($cycle == '') {
                    echo json_encode(array('STATUS' => 'ERROR', 'MESSAGE' => 'Empty cycle name'));
                    exit;
                }
                $this->ensureCycleRuntimeTables();
                SQLExec("DELETE FROM cached_cycle_logs WHERE CYCLE='" . DBSafe($cycle) . "'");
                echo json_encode(array('STATUS' => 'OK', 'CYCLE' => $cycle, 'CLEARED' => 1));
                exit;
            }
            if ($op == 'getcontent') {
                header("HTTP/1.0 200 OK\n");
                header('Content-Type: application/json; charset=utf-8');
                if ($this->view_mode == 'properties') {
                    $qry = "1";
                    if ($filter) {
                        $qry .= " AND (objects.TITLE LIKE '%" . DBSafe($filter) . "%' OR properties.TITLE LIKE '%" . DBSafe($filter) . "%' OR objects.DESCRIPTION LIKE '%" . DBSafe($filter) . "%')";
                    }
                    $res = SQLSelect("SELECT pvalues.*, objects.TITLE as OBJECT, objects.DESCRIPTION as OBJECT_DESCRIPTION, properties.TITLE as PROPERTY, properties.DESCRIPTION FROM pvalues LEFT JOIN objects ON pvalues.OBJECT_ID=objects.ID LEFT JOIN properties ON pvalues.PROPERTY_ID=properties.ID WHERE $qry ORDER BY pvalues.UPDATED DESC");
                    $total = count($res);
                    $responce = [];
                    $responce['MODE'] = 'properties';
                    $responce['TOTAL'] = $total;
                    for ($i = 0; $i < $total; $i++) {
                        $responce['LIST'][$i]['NAME'] = $res[$i]['OBJECT'] . '.' . $res[$i]['PROPERTY'];
                        if ($res[$i]['OBJECT_DESCRIPTION'] != '') {
                            $responce['LIST'][$i]['DESC'] = $res[$i]['OBJECT_DESCRIPTION'];
                        } else {
                            $responce['LIST'][$i]['DESC'] = '';
                        }

                        $responce['LIST'][$i]['VALUE'] = htmlspecialchars($res[$i]['VALUE']);
                        $responce['LIST'][$i]['UPDATE'] = $res[$i]['UPDATED'];
                        $responce['LIST'][$i]['SOURCE'] = $res[$i]['SOURCE'];
                    }

                    $this->reverseResponseList($responce);

                    echo json_encode($responce);
                }

                if ($this->view_mode == '') {

                    header("HTTP/1.0 200 OK\n");
                    header('Content-Type: application/json; charset=utf-8');
                    $limit = $out['LINES'];
                    $filter = $out['FILTER'];
                    if (!$limit) {
                        $limit = 50;
                    }

                    $files = $out['FILES'];

                    if (defined('SETTINGS_SYSTEM_DEBMES_PATH') && SETTINGS_SYSTEM_DEBMES_PATH != '') {
                        $path = SETTINGS_SYSTEM_DEBMES_PATH;
                    } elseif (defined('LOG_DIRECTORY') && LOG_DIRECTORY != '') {
                        $path = LOG_DIRECTORY;
                    } else {
                        $path = ROOT . 'cms/debmes';
                    }


                    $result = array();

                    foreach ($files as $file_item) {
                        if (isset($file_item['SELECTED'])) {
                            $file = $file_item['TITLE'];
                            if ($this->isCycleFileLog($file)) {
                                continue;
                            }
                            $filename = $path . '/' . $file;
                            if (file_exists($filename)) {
                                $data = LoadFile($filename);
                            } else {
                                $data = '';
                            }
                            $res_lines = array();
                            $lines = explode("\n", $data);
                            $lines = array_slice($lines, -1 * ($limit), $limit);
                            $total = count($lines);
                            $added = 0;
                            for ($i = 0; $i < $total; $i++) {
                                if (trim($lines[$i]) == '') {
                                    continue;
                                }
                                if ($filter && preg_match('/' . preg_quote($filter) . '/is', $lines[$i])) {
                                    $res_lines[] = htmlspecialchars($lines[$i]);
                                    $added++;
                                } elseif (!$filter) {
                                    if (!preg_match('/^\d+:\d+:\d+ [\d\.]+/is', $lines[$i]) && $added > 0) {
                                        $res_lines[$added - 1] .= "\n" . htmlspecialchars($lines[$i]);
                                    } else {
                                        $line = htmlspecialchars($lines[$i]);
                                        if ($total_selected_files > 1) {
                                            $fname = '<small>(' . $file . ')</small>';
                                        } else {
                                            $fname = '';
                                        }
                                        if (preg_match('/^(\d+:\d+:\d+ \d+)/is', $line)) {
                                            $line = preg_replace('/^(\d+:\d+:\d+ [\d\.]+)/is', '<b>\1</b> ' . $fname, $line);
                                        }
                                        $res_lines[] = $line;
                                        $added++;
                                    }
                                }
                                if ($added >= $limit) {
                                    break;
                                }
                            }
                            if (!$filter) {
                                foreach ($res_lines as $line) {
                                    if (preg_match('/<b>(\d+?:\d+?:\d+?) ([\d\.]+)<\\/b>/uis', $line, $m)) {
                                        $tm = strtotime(date('Y-m-d', filemtime($filename)) . ' ' . $m[1]) + (float)$m[1];
                                        $result[] = array('TM' => $tm + (float)$m[2], 'CONTENT' => $line);
                                    }
                                }
                            } else {
                                $tm = 0;
                                foreach ($res_lines as $line) {
                                    $result[] = array('TM' => $tm, 'CONTENT' => $line);
                                    $tm++;
                                }
                            }
                        }
                    }
                    usort($result, function ($a, $b) {
                        if ($a['TM'] == $b['TM']) return 0;
                        if ($a['TM'] < $b['TM']) return -1;
                        return 1;
                    });
                    $res_lines = array();
                    foreach ($result as $item) {
                        $res_lines[] = $item['CONTENT'];
                    }

                    $total = count($res_lines);
                    for ($i = 0; $i < $total; $i++) {
                        $line = $res_lines[$i];
                        $line = str_replace('Warning:', '<font color="#b8860b">Warning:</font>', $line);
                        $res_lines[$i] = nl2br($line);
                    }

                    $res_lines = array_reverse($res_lines);

                    echo json_encode(array('MODE' => 'logs', 'CONTENT' => implode("<br/>", $res_lines)));

                }

                if ($this->view_mode == 'performance') {
                    $qry = "1";
                    if ($filter) {
                        $qry .= " AND (OPERATION LIKE '%" . DBSafe($filter) . "%')";
                    }
                    $time_start = date('Y-m-d H:i:s', time() - 60);
                    $res = SQLSelect("SELECT OPERATION, SUM(COUNTER) as TOTAL, SUM(TIMEUSED) as TIME_TOTAL FROM performance_log WHERE ADDED>='" . $time_start . "' AND $qry GROUP BY OPERATION ORDER BY TIME_TOTAL DESC ");//methods.OBJECT_ID<>0
                    $total = count($res);
                    $responce = [];
                    $responce['MODE'] = 'performance';
                    $responce['TOTAL'] = $total;

                    for ($i = 0; $i < $total; $i++) {
                        $responce['LIST'][$i]['OPERATION'] = htmlspecialchars($res[$i]['OPERATION']);
                        $responce['LIST'][$i]['COUNTER'] = $res[$i]['TOTAL'];
                        $responce['LIST'][$i]['TIME'] = number_format($res[$i]['TIME_TOTAL'], 2);
                        $responce['LIST'][$i]['AVTIME'] = number_format($res[$i]['TIME_TOTAL'] / $res[$i]['TOTAL'], 2);
                    }


                    $this->reverseResponseList($responce);

                    echo json_encode($responce);

                    SQLExec("DELETE FROM performance_log WHERE ADDED<'" . date('Y-m-d H:i:s', time() - 60 * 60) . "'");
                }

                if ($this->view_mode == 'methods') {
                    $qry = "1";
                    if ($filter) {
                        $qry .= " AND (objects.TITLE LIKE '%" . DBSafe($filter) . "%' OR methods.TITLE LIKE '%" . DBSafe($filter) . "%' OR methods.DESCRIPTION LIKE '%" . DBSafe($filter) . "%' OR methods.EXECUTED_PARAMS LIKE '%" . DBSafe($filter) . "%')";
                    }
                    $res = SQLSelect("SELECT methods.*, objects.TITLE as OBJECT, objects.DESCRIPTION as OBJECT_DESCRIPTION, methods.DESCRIPTION FROM methods LEFT JOIN objects ON methods.OBJECT_ID=objects.ID WHERE $qry ORDER BY methods.EXECUTED DESC");//methods.OBJECT_ID<>0
                    $total = count($res);

                    $responce = [];
                    $responce['MODE'] = 'methods';
                    $responce['TOTAL'] = $total;

                    for ($i = 0; $i < $total; $i++) {
                        if (isset($res[$i]['EXECUTED_PARAMS']) && $res[$i]['EXECUTED_PARAMS'] != '') {
                            $tmp = json_decode($res[$i]['EXECUTED_PARAMS'], true);
                            if (isset($tmp['ORIGINAL_OBJECT_TITLE']) && !isset($res[$i]['OBJECT'])) {
                                $res[$i]['OBJECT'] = $tmp['ORIGINAL_OBJECT_TITLE'];
                                $res[$i]['EXECUTED_PARAMS'] = serialize($tmp);
                            }
                        }

                        $responce['LIST'][$i]['METHOD'] = $res[$i]['OBJECT'] . '.' . $res[$i]['TITLE'];
                        if ($res[$i]['DESCRIPTION']) {
                            $responce['LIST'][$i]['DESC'] = $res[$i]['DESCRIPTION'];
                        } else {
                            $responce['LIST'][$i]['DESC'] = '';
                        }
                        if (isset($res[$i]['EXECUTED_PARAMS'])) {
                            $responce['LIST'][$i]['PARAMS'] = htmlspecialchars(str_replace(',"', ', "', $res[$i]['EXECUTED_PARAMS']));
                        }
                        $responce['LIST'][$i]['EXECUTED'] = $res[$i]['EXECUTED'];
                        $responce['LIST'][$i]['SOURCE'] = $res[$i]['EXECUTED_SRC'];
                    }

                    $this->reverseResponseList($responce);

                    echo json_encode($responce);
                }

                if ($this->view_mode == 'scripts') {
                    $qry = "1";
                    if ($filter) {
                        $qry .= " AND (scripts.TITLE LIKE '%" . DBSafe($filter) . "%' OR scripts.DESCRIPTION LIKE '%" . DBSafe($filter) . "%')";
                    }
                    $res = SQLSelect("SELECT scripts.* FROM scripts WHERE $qry ORDER BY scripts.EXECUTED DESC");
                    $total = count($res);

                    $responce = [];
                    $responce['MODE'] = 'scripts';
                    $responce['TOTAL'] = $total;

                    for ($i = 0; $i < $total; $i++) {
                        if (!empty($res[$i]['EXECUTED'])) {
                            $responce['LIST'][$i]['ID'] = $res[$i]['ID'];
                            $responce['LIST'][$i]['SCRIPT'] = $res[$i]['TITLE'];
                            if ($res[$i]['DESCRIPTION']) {
                                $responce['LIST'][$i]['DESC'] = $res[$i]['DESCRIPTION'];
                            } else {
                                $responce['LIST'][$i]['DESC'] = '';
                            }

                            $responce['LIST'][$i]['PARAMS'] = str_replace(';', '; ', htmlspecialchars($res[$i]['EXECUTED_PARAMS']));
                            $responce['LIST'][$i]['EXECUTED'] = $res[$i]['EXECUTED'];
                            $responce['LIST'][$i]['SOURCE'] = $res[$i]['EXECUTED_SRC'];
                        }
                    }

                    $this->reverseResponseList($responce);

                    echo json_encode($responce);

                }

                if ($this->view_mode == 'services') {
                    $qry = "OBJECT_ID=" . getObject('Computer.ThisComputer')->id . " AND TITLE LIKE 'cycle%Run'";
                    $res = SQLSelect("SELECT properties.* FROM properties WHERE $qry ORDER BY TITLE");
                    $total = count($res);

                    $seen = array();
                    for ($i = 0; $i < $total; $i++) {
                        $title = $res[$i]['TITLE'];
                        $title = preg_replace('/Run$/', '', $title);
                        $seen[$title] = 1;
                    }

                    $path = ROOT . 'scripts';
                    $files = array();
                    if ($handle = opendir($path)) {
                        $files = array();
                        while (false !== ($entry = readdir($handle))) {
                            if (preg_match('/^cycle/is', $entry)) {
                                $title = preg_replace('/\.php$/', '', $entry);
                                if (!isset($seen[$title])) {
                                    $res[] = array('TITLE' => $title . 'Run');
                                }
                            }
                        }
                    }


                    $total = count($res);

                    $responce = [];
                    $responce['MODE'] = 'services';
                    $responce['TOTAL'] = $total;
                    $responce['TOTAL_ALIVE'] = 0;
                    $cycleHangTimeout = 10 * 60;
                    $onDisabled = ' - ';
                    $onEnable = ' - ';

                    for ($i = 0; $i < $total; $i++) {
                        $responce['LIST'][$i]['TITLE'] = preg_replace('/Run$/', '', $res[$i]['TITLE']);

                        $url = ROOTHTML . 'panel/xray.html?view_mode=services&service=' . urlencode($responce['LIST'][$i]['TITLE']);

                        $tm = (int)getGlobal($responce['LIST'][$i]['TITLE'] . 'Run');
                        $runtimeStatus = checkCycleFromCache($responce['LIST'][$i]['TITLE'] . 'Status');
                        $runtimeStatusUpdated = (int)checkCycleFromCache($responce['LIST'][$i]['TITLE'] . 'StatusUpdated');
                        if ($tm > 0 && (time() - $tm) < $cycleHangTimeout) {
                            $runtimeStatus = 'running';
                        } elseif ($tm > 0) {
                            $runtimeStatus = 'hang';
                        } elseif ($runtimeStatus == 'starting' && $runtimeStatusUpdated > 0 && (time() - $runtimeStatusUpdated) < $cycleHangTimeout) {
                            $runtimeStatus = 'starting';
                        } elseif ($runtimeStatus == 'stopping' && $runtimeStatusUpdated > 0 && (time() - $runtimeStatusUpdated) < $cycleHangTimeout) {
                            $runtimeStatus = 'stopping';
                        } else {
                            $runtimeStatus = 'stopped';
                        }
                        $responce['LIST'][$i]['STATUS'] = $runtimeStatus;
                        $responce['LIST'][$i]['STATUS_DETAILS'] = htmlspecialchars((string)checkCycleFromCache($responce['LIST'][$i]['TITLE'] . 'StatusDetails'));
                        $responce['LIST'][$i]['LOG_LINK'] = $responce['LIST'][$i]['TITLE'];
                        if ($tm > 0) {
                            if ((time() - $tm) < $cycleHangTimeout) {
                                $responce['LIST'][$i]['WAIT'] = 0;
                            } else {
                                $responce['LIST'][$i]['WAIT'] = 1;
                            }
                            $responce['LIST'][$i]['UPDATE'] = date('d.m.Y H:i:s', $tm);

                            $responce['LIST'][$i]['CNT_STOP'] = $url . '&cmd=stop';
                            $responce['LIST'][$i]['CNT_RESTART'] = $url . '&cmd=restart';
                            $responce['LIST'][$i]['ALIVE'] = 1;
                            $responce['TOTAL_ALIVE']++;
                        } else {
                            $responce['LIST'][$i]['UPDATE'] = '';
                            $responce['LIST'][$i]['ALIVE'] = 0;
                            $responce['LIST'][$i]['CNT_START'] = $url . '&cmd=start';
                            $onDisabled .= $responce['LIST'][$i]['TITLE'] . ', ';
                        }
                    }

                    $this->reverseResponseList($responce);

                    if ($this->mode == 'chart') {
                        $chart = array(array(
                            'cycle' => $responce['TOTAL_ALIVE'],
                            'title' => LANG_XRAY_WORKING,
                            'color' => '#5cb85c',
                            'cyclename' => LANG_XRAY_WORKING . ' - ' . $responce['TOTAL_ALIVE'] . ' ' . LANG_XRAY_WORKING_CYCLE,
                        ),
                            array(
                                'cycle' => ($responce['TOTAL'] - $responce['TOTAL_ALIVE']),
                                'title' => LANG_XRAY_DO_WORKING,
                                'color' => '#d9534f',
                                'cyclename' => LANG_XRAY_DO_WORKING . ' ' . substr($onDisabled, 0, -2),
                            ));
                        echo json_encode($chart);
                    } else {
                        echo json_encode($responce);
                    }
                }

                if ($this->view_mode == 'timers') {
                    $qry = "1";
                    if ($filter) {
                        $qry .= " AND (jobs.TITLE LIKE '%" . DBSafe($filter) . "%')";
                    }
                    $res = SQLSelect("SELECT jobs.* FROM jobs WHERE EXPIRED!=1 AND PROCESSED!=1 AND $qry ORDER BY jobs.RUNTIME");
                    $total = count($res);

                    $responce = [];
                    $responce['MODE'] = 'timers';
                    $responce['TOTAL'] = $total;

                    for ($i = 0; $i < $total; $i++) {
                        $responce['LIST'][$i]['TITLE'] = $res[$i]['TITLE'];
                        $responce['LIST'][$i]['COMMAND'] = htmlspecialchars($res[$i]['COMMANDS']);

                        $responce['LIST'][$i]['SCHEDULED'] = $res[$i]['RUNTIME'];
                        $url = ROOTHTML . 'panel/xray.html?view_mode=timers&timer=' . urlencode($res[$i]['TITLE']) . '&cmd=stop';
                        $responce['LIST'][$i]['STOP_LINK'] = $url;
                    }

                    $this->reverseResponseList($responce);

                    echo json_encode($responce);

                }

                if ($this->view_mode == 'dead') {
                    $qry = '';
                    if ($filter != '') {
                        $qry .= " AND ((objects.TITLE LIKE '%" . DBSafe($filter) . "%')" . " or (objects.DESCRIPTION LIKE '%" . DBSafe($filter) . "%'))";
                    }
                    $pRecs = SQLSelect("SELECT ID FROM properties WHERE TITLE = 'alive'");
                    $total = count($pRecs);
                    if (!$total) {
                        echo json_encode(array('MODE' => 'dead', 'TOTAL' => 0, 'LIST' => array()));
                        return;
                    }
                    $found = array();
                    for ($i = 0; $i < $total; $i++) {
                        $pValues = SQLSelect("SELECT objects.TITLE, VALUE, UPDATED , objects.DESCRIPTION,  locations.TITLE LOCATIONTITLE   FROM locations,pvalues LEFT JOIN objects ON pvalues.OBJECT_ID=objects.ID WHERE PROPERTY_ID='" . $pRecs[$i]['ID'] . "' AND LOCATION_ID=locations.ID  " . $qry . " ORDER BY UPDATED");
                        $totalv = count($pValues);
                        for ($iv = 0; $iv < $totalv; $iv++) {
                            $v = $pValues[$iv]['VALUE'];

                            if ($v == '0') {
                                $found[] = array("TITLE" => $pValues[$iv]['TITLE'], 'UPDATED' => $pValues[$iv]['UPDATED'], 'DESCRIPTION' => $pValues[$iv]['DESCRIPTION'], 'LOCATIONTITLE' => $pValues[$iv]['LOCATIONTITLE']);
                            }

                        }
                    }
                    $res = $found;

                    $total = count($res);

                    $responce = [];
                    $responce['MODE'] = 'dead';
                    $responce['TOTAL'] = $total;
                    //echo ' <a href="' . ROOTHTML . 'panel/linkedobject.html?op=redirect&object=' . $res[$i]['TITLE'] . '&sub=properties"  target="_blank"  title="Open object">' . $res[$i]['TITLE'] . '</a>';

                    for ($i = 0; $i < $total; $i++) {
                        $responce['LIST'][$i]['TITLE'] = $res[$i]['TITLE'];
                        $responce['LIST'][$i]['DESCRIPTION'] = htmlspecialchars($res[$i]['DESCRIPTION']);

                        $responce['LIST'][$i]['LOCATIONTITLE'] = htmlspecialchars($res[$i]['LOCATIONTITLE']);
                        $responce['LIST'][$i]['UPDATED'] = htmlspecialchars($res[$i]['UPDATED']);
                    }

                    $this->reverseResponseList($responce);

                    echo json_encode($responce);
                }

                if ($this->view_mode == 'events') {
                    $qry = "1";
                    if ($filter) {
                        $qry .= " AND (events.EVENT_NAME LIKE '%" . DBSafe($filter) . "%')";
                    }
                    $res = SQLSelect("SELECT events.* FROM events WHERE $qry ORDER BY events.ADDED DESC LIMIT 30");
                    $total = count($res);

                    $responce = [];
                    $responce['MODE'] = 'events';
                    $responce['TOTAL'] = $total;

                    for ($i = 0; $i < $total; $i++) {
                        $responce['LIST'][$i]['EVENT'] = $res[$i]['EVENT_NAME'];
                        $responce['LIST'][$i]['DETAILS'] = htmlspecialchars($res[$i]['DETAILS']);

                        $responce['LIST'][$i]['ADDED'] = $res[$i]['ADDED'];
                    }

                    $this->reverseResponseList($responce);

                    echo json_encode($responce);
                }

                if ($this->view_mode == 'database') {

                    $tables = SQLSelect("SHOW TABLE STATUS;");
                    if (!$tables) {
                        echo json_encode(array('MODE' => 'database', 'STATUS' => 0,));
                    } else {
                        usort($tables, function ($a, $b) {
                            if ($a['Rows'] == $b['Rows']) return 0;
                            if ($a['Rows'] > $b['Rows']) return -1;
                            return 1;
                        });


                        $responce = [];
                        $responce['MODE'] = 'database';
                        $responce['STATUS'] = 1;

                        $i = 0;
                        foreach ($tables as $table) {
                            if ($filter != '' && !preg_match('/' . preg_quote($filter) . '/is', $table['Name'])) continue;
                            $responce['LIST'][$i]['NAME'] = $table['Name'];
                            $responce['LIST'][$i]['ENGINE'] = $table['Engine'];

                            $responce['LIST'][$i]['ROWS'] = $table['Rows'];
                            $responce['LIST'][$i]['UPDATE_TIME'] = $table['Update_time'];

                            $responce['LIST'][$i]['BTN_ANALYZE'] = ROOTHTML . "panel/xray.html?view_mode=database&analyze=" . urlencode($table['Name']);
                            $responce['LIST'][$i]['BTN_OPTIMIZE'] = ROOTHTML . "panel/xray.html?view_mode=database&optimize=" . urlencode($table['Name']);
                            $responce['LIST'][$i]['BTN_REPAIR'] = ROOTHTML . "panel/xray.html?view_mode=database&repair=" . urlencode($table['Name']);
                            $i++;
                        }

                        $this->reverseResponseList($responce);
                        $responce['TOTAL'] = $i;


                        if ($this->mode == 'chart') {
                            $arrayDB = array_slice(array_reverse($responce['LIST']), 0, 7);
                            echo json_encode($arrayDB);
                        } else if ($this->mode == 'showdbload') {
                            $DBstat_PerSecType = 'main';
                            $DBstat_Connections = '';
                            $connectionStats = array(
                                'running' => 0,
                                'connected' => 0,
                                'cached' => 0,
                                'created' => 0,
                                'max' => 0,
                            );

                            if (isset($GLOBALS['db']->dbh->stat)) {
                                $DBstat = $GLOBALS['db']->dbh->stat;
                                $DBstat = explode('  ', $DBstat);
                                $DBstat_PerSec = isset($DBstat[7]) ? (float)preg_replace('/[^0-9.]/', '', $DBstat[7]) : 0;
                            } else $DBstat_PerSec = 0;

                            $select = SQLSelect("SHOW GLOBAL STATUS");
                            $array_sum = [
                                    1 => 'Com_select',
                                    2 => 'Com_replace',
                                    3 => 'Com_update',
                                    4 => 'Com_delete',
                                    5 => 'Com_set_option',
                                    6 => 'Com_insert',
                                    7 => 'Com_truncate',
                                    8 => 'Com_show_table_status',
                                    9 => 'Com_show_fields',
                                    10 => 'Com_create_table',
                                    11 => 'Com_change_db',
                                    12 => 'Com_show_create_table',
                                    13 => 'Com_show_triggers',
                                    14 => 'Com_check',
                                    15 => 'Com_show_keys',
                                    16 => 'Com_show_variables',
                                    17 => 'Com_show_tables',
                                    18 => 'Com_alter_table',
                                    19 => 'Com_show_master_status',
                                    20 => 'Com_show_slave_status',
                                    21 => 'Com_show_status',
                                    22 => 'Com_flush',
                                    23 => 'Com_unlock_tables',
                                    24 => 'Com_lock_tables',
                                    25 => 'Com_optimize',
                                    26 => 'Com_show_grants',
                                    27 => 'Com_show_binlogs',
                                    28 => 'Com_drop_table',
                            ];

                            $totalSum = 0;
                            $uptime = 0;

                            foreach ($select as $key => $value) {
                                if ($value['Variable_name'] == 'Threads_running') {
                                    $connectionStats['running'] = (int)$value['Value'];
                                }
                                if ($value['Variable_name'] == 'Threads_connected') {
                                    $connectionStats['connected'] = (int)$value['Value'];
                                }
                                if ($value['Variable_name'] == 'Threads_cached') {
                                    $connectionStats['cached'] = (int)$value['Value'];
                                }
                                if ($value['Variable_name'] == 'Threads_created') {
                                    $connectionStats['created'] = (int)$value['Value'];
                                }
                                foreach ($array_sum as $comName) {
                                    if ($value['Variable_name'] == $comName) {
                                        $totalSum = $totalSum + $value['Value'];
                                        continue;
                                    }
                                    if ($value['Variable_name'] == 'Uptime') {
                                        $uptime = $value['Value'];
                                    }
                                }
                            }

                            if (round($DBstat_PerSec) == 0) {
                                $connectionStats['max'] = (int)current(SQLSelectOne("select @@max_connections"));

                                $DBstat_PerSec = $uptime > 0 ? $totalSum / $uptime : 0;

                                $DBstat_PerSecType = 'rezerv';
                            }

                            if (!$connectionStats['max']) {
                                $connectionStats['max'] = (int)current(SQLSelectOne("select @@max_connections"));
                            }

                            if ($DBstat_Connections == '') {
                                $DBstat_Connections = 'cached: ' . $connectionStats['cached'] . '; '
                                    . 'connected: ' . $connectionStats['connected'] . '; '
                                    . 'created: ' . $connectionStats['created'] . '; '
                                    . 'running: ' . $connectionStats['running'] . '; '
                                    . 'max: ' . $connectionStats['max'];
                            }

                            $requestsSecond = round($DBstat_PerSec);
                            $requestsMinute = round($DBstat_PerSec * 60);
                            $requestsHour = round($DBstat_PerSec * 60 * 60);
                            $connectionUsage = $connectionStats['max'] > 0 ? round($connectionStats['connected'] / $connectionStats['max'] * 100, 1) : 0;
                            $level = 'ok';
                            $statusText = 'Нагрузка в норме';

                            if ($requestsSecond >= 250 || $connectionUsage >= 80 || $connectionStats['running'] >= 20) {
                                $level = 'danger';
                                $statusText = 'Высокая нагрузка';
                            } elseif ($requestsSecond >= 170 || $connectionUsage >= 60 || $connectionStats['running'] >= 10) {
                                $level = 'warning';
                                $statusText = 'Повышенная нагрузка';
                            }

                            echo json_encode(array(
                                'MODE' => 'dbload',
                                'STATUS' => 1,
                                'second' => $requestsSecond,
                                'minute' => $requestsMinute,
                                'hour' => $requestsHour,
                                'hours' => $requestsHour,
                                'connections' => $DBstat_Connections,
                                'connections_data' => $connectionStats,
                                'connection_usage_percent' => $connectionUsage,
                                'type' => $DBstat_PerSecType,
                                'level' => $level,
                                'status_text' => $statusText,
                                'updated_at' => date('Y-m-d H:i:s'),
                            ));
                        } else {
                            echo json_encode($responce);
                        }
                    }
                }

                exit;
            }

        }
        $out['FILTER'] = gr('filter'); //


    }

    /**
     * FrontEnd
     *
     * Module frontend
     *
     * @access public
     */
    function usual(&$out)
    {
        $this->admin($out);
    }

    function dbInstall($data)
    {
        // watchfolders - Watchfolders

        $data = <<<EOD
 performance_log: ID int(10) unsigned NOT NULL auto_increment
 performance_log: OPERATION varchar(255) NOT NULL DEFAULT ''
 performance_log: COUNTER int(10) NOT NULL DEFAULT '0'
 performance_log: TIMEUSED float NOT NULL DEFAULT '0'
 performance_log: SOURCE char(10) NOT NULL DEFAULT ''
 performance_log: ADDED datetime

EOD;
        parent::dbInstall($data);
    }

    /**
     * Install
     *
     * Module installation routine
     *
     * @access private
     */
    function install($parent_name = "")
    {
        parent::install($parent_name);
    }
// --------------------------------------------------------------------
}

/*
*
* TW9kdWxlIGNyZWF0ZWQgTWFyIDA0LCAyMDEwIHVzaW5nIFNlcmdlIEouIHdpemFyZCAoQWN0aXZlVW5pdCBJbmMgd3d3LmFjdGl2ZXVuaXQuY29tKQ==
*
*/
?>
