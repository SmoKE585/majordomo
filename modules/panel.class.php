<?php
/*
* @version 0.1 (auto-set)
*/

/**
 * Оболочка админки: навигация, уведомления и содержимое модулей.
 *
 * Изменения:
 * 2026-10-22 — удалена иконка неиспользуемого модуля DurationInput.
 *
 * @author Serge Dzheigalo <jey@unit.local>
 * @package project
 */
class panel extends module
{

    var $action;
    var $print;
    var $ajax;

// --------------------------------------------------------------------
    function __construct()
    {
        $this->name = "panel";
    }

// --------------------------------------------------------------------
    function saveParams($data = 1)
    {
        $data = array();
        $data["action"] = $this->action;
        $data["print"] = $this->print;
        return parent::saveParams($data);
    }

// --------------------------------------------------------------------
    function getParams()
    {
        global $action;
        $this->action = $action;
    }

// --------------------------------------------------------------------
    function run()
    {
        global $session;
        $session = \MajorDoMo\Core\Core::session();

        Define('ALTERNATIVE_TEMPLATES', 'templates_alt');

        if (isset($_COOKIE['theme'])) {
            $out['PANEL_THEME'] = $_COOKIE['theme'];
        } else {
            $out['PANEL_THEME'] = 'light';
        }

        if (gr('toggleLeftPanel')) {
            if (gg('HideLeftPanelAdmin')) {
                sg('HideLeftPanelAdmin', 0);
            } else {
                sg('HideLeftPanelAdmin', 1);
            }

            $uri = str_replace('toggleLeftPanel=1', '', $_SERVER['REQUEST_URI']);
            $this->redirect($uri);
        }
        $out['HIDE_LEFT_PANEL'] = gg('HideLeftPanelAdmin');

        global $action;
        $out['TAB'] = gr('tab');

        if (defined('NO_DATABASE_CONNECTION')) {
            if (!$action) $action = 'saverestore';
            $this->print = 1;
        }

        if (!$this->action && $action) {
            $this->action = $action;
        }

        if ($this->action) {
            $out['TITLE'] = $this->action . ' (' . LANG_CONTROL_PANEL . ')';
            //Узнаем название модуля
            $result = SQLSelectOne("SELECT * FROM `project_modules` WHERE NAME = '" . $this->action . "'");
            if ($result) {
                $out['NAV_MODULE_NAME'] = $result["TITLE"];
                $out['NAV_MODULE_CAT'] = $result["CATEGORY"];
                $out['TITLE'] = $result["TITLE"] . ' | ' . $result["CATEGORY"] . ' | ' . LANG_CONTROL_PANEL;
            }
            if (defined('PROJECT_TITLE') && PROJECT_TITLE != 'MajordomoSL') {
                $out['TITLE'] = PROJECT_TITLE . ' | ' . $out['TITLE'];
            }
        } else {
            $out['TITLE'] = PROJECT_TITLE.' - '.LANG_CONTROL_PANEL;
        }

        $out['SETTINGS_SITE_LANGUAGE'] = SETTINGS_SITE_LANGUAGE;

        $out['AUTH_CSRF'] = $session->csrfToken();
        $out['AUTH_USERNAME'] = $session->user()['username'] ?? '';

        global $ajax_panel;
        if ($ajax_panel) {
            include_once(DIR_MODULES . 'inc_panel_ajax.php');
        }

        if ($this->print || (isset($_GET['print']) && $_GET['print'])) {
            $this->print = 1;
            $out['PRINT'] = 1;
        }

        $out["TODAY"] = date('l, F d, Y');
        $flash_notifications = mdjConsumeFlashNotifications('admin');
        $request_flash_notifications = mdjCollectRequestFlashNotifications('admin');
        if (!empty($request_flash_notifications)) {
            $flash_notifications = array_merge($flash_notifications, $request_flash_notifications);
            $out['FLASH_SANITIZE_URL'] = 1;
        }
        $out['FLASH_NOTIFICATIONS_JSON'] = json_encode($flash_notifications, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);


        // Register the knowledge base once on existing installations. New
        // modules normally install on first access, but this section is
        // content-driven and users should see it in the menu right away.
        if (!defined('NO_DATABASE_CONNECTION') && !isModuleInstalled('knowledge_base')) {
            $knowledgeBaseModule = DIR_MODULES . 'knowledge_base/knowledge_base.class.php';
            if (is_file($knowledgeBaseModule)) {
                require_once($knowledgeBaseModule);
                if (class_exists('knowledge_base', false)) {
                    new knowledge_base();
                }
            }
        }

        if (!defined('NO_DATABASE_CONNECTION') && !isModuleInstalled('zigbee')) {
            require_once DIR_MODULES . 'zigbee/zigbee.class.php';
            new zigbee();
        }

        if (!defined('NO_DATABASE_CONNECTION') && !isModuleInstalled('modes')) {
            require_once DIR_MODULES . 'modes/modes.class.php';
            new modes();
        }

        if (!defined('NO_DATABASE_CONNECTION') && !isModuleInstalled('api')) {
            require_once DIR_MODULES . 'api/api.class.php';
            new api();
        }

        $requestedAction = isset($this->action) && is_scalar($this->action) ? trim((string)$this->action) : '';
        $requestedModuleValue = gr('md');
        $requestedModule = is_scalar($requestedModuleValue) ? trim((string)$requestedModuleValue) : '';
        $moduleMissing = false;
        if (isset($this->action) && !is_scalar($this->action)) {
            $moduleMissing = true;
        }
        if (isset($_REQUEST['md']) && !is_scalar($_REQUEST['md'])) {
            $moduleMissing = true;
        }
        if ($requestedAction !== '' && !mdj_admin_module_exists($requestedAction)) {
            $moduleMissing = true;
        }
        if ($requestedModule !== '' && $requestedModule !== 'panel' && !mdj_admin_module_exists($requestedModule)) {
            $moduleMissing = true;
        }

        if ($moduleMissing) {
            http_response_code(404);
            $out['NOT_FOUND'] = 1;
            $out['NOT_FOUND_PATH'] = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '';
            $out['ACTION'] = '';
            $this->action = '';
        }

        $this->registerPanelActivityLog();

        if ($_SERVER['REQUEST_METHOD'] == 'POST') {
            clearCache(0);
        }

        $sqlQuery = "SELECT *
              FROM project_modules
             WHERE (`HIDDEN`='0' OR NAME='control_modules')
             ORDER BY FIELD(CATEGORY, '<#LANG_SECTION_OBJECTS#>', '<#LANG_SECTION_APPLICATIONS#>',
                            '<#LANG_SECTION_SETTINGS#>', '<#LANG_SECTION_SYSTEM#>', '<#LANG_SECTION_HELP#>'),
                      FIELD(`NAME`,'classes','settings','system_errors','xray','saverestore','market') DESC,
                      `PRIORITY`, `TITLE`";

        $modules = SQLSelect($sqlQuery);

        $sidebarIcons = array(
            'modes' => 'ti-adjustments-horizontal',
            'panel' => 'ti-home',
            'objects' => 'ti-box-seam',
            'classes' => 'ti-hierarchy',
            'scripts' => 'ti-code',
            'settings' => 'ti-adjustments-horizontal',
            'locations' => 'ti-map-pin',
            'users' => 'ti-users',
            'market' => 'ti-shopping-bag',
            'saverestore' => 'ti-repeat',
            'xray' => 'ti-activity',
            'system_errors' => 'ti-alert-triangle',
            'actions_log' => 'ti-history',
            'events' => 'ti-bell',
            'control_modules' => 'ti-layout-grid',
            'methods' => 'ti-braces',
            'properties' => 'ti-list-check',
            'history' => 'ti-history',
            'pvalues' => 'ti-chart-bar',
            'jobs' => 'ti-calendar-check',
            'linkedobject' => 'ti-link',
            'mqtt' => 'ti-broadcast',
            'zigbee' => 'ti-access-point',
            'pinghosts' => 'ti-server',
            'yadevices' => 'ti-device-speaker',
            'yandexhome' => 'ti-home-bolt',
            'blockly_code' => 'ti-puzzle',
            'shoutbox' => 'ti-messages',
            'ui_kit' => 'ti-palette',
            'knowledge_base' => 'ti-book',
            'api' => 'ti-api'
        );

        $old_cat = 'some_never_should_be_category_name';
        $last_allow = 0;
        $modulesCnt = count($modules);

        $notifications = array();
        if (SQLTableExists('module_notifications')) {
            $getNotifications = SQLSelect("select * from module_notifications WHERE IS_READ = 0 ORDER BY ADDED DESC");
            foreach ($getNotifications as $notification) {
                $notifications[$notification['MODULE_NAME']][] = $notification;
            }
        }

        for ($i = 0; $i < $modulesCnt; $i++) {
            if ($modules[$i]['NAME'] == $this->action) {
                $modules[$i]['SELECTED'] = 1;
            }
            $modules[$i]['CATEGORY_ID'] = substr(md5($modules[$i]['CATEGORY']), 0, 4);
            if ($modules[$i]['CATEGORY'] != $old_cat) {
                $modules[$i]['NEW_CATEGORY'] = 1;
                $old_cat = $modules[$i]['CATEGORY'];
                if ($i > 0) {
                    //echo $last_allow."<br>";
                    $modules[$last_allow]['LAST_IN_CATEGORY'] = 1;
                }
            }

            if (isset($acc) && is_object($acc) && !$acc->checkAccess($modules[$i]['NAME'])) {
                $modules[$i]['DENIED'] = 1;
            } else {
                $last_allow = $i;
            }

            if (isset($notifications[$modules[$i]["NAME"]])) {
                $modules[$i]["NOTIFICATIONS"] = $notifications[$modules[$i]["NAME"]];
                $modules[$i]["NOTIFICATIONS_COUNT"] = count($modules[$i]["NOTIFICATIONS"]);
                $modules[$i]["NOTIFICATIONS_TYPE"] = $modules[$i]["NOTIFICATIONS"][0]['TYPE'];
                $notyForJs = array();
                foreach ($modules[$i]["NOTIFICATIONS"] as $noty) {
                    $notyType = $noty['TYPE'];
                    if ($notyType == 'default') {
                        $notyType = 'info';
                    }
                    $notyForJs[] = array(
                        'id'   => (int)$noty['ID'],
                        'type' => $notyType,
                        'msg'  => $noty['MESSAGE'],
                        'time' => date('d.m.Y H:i:s', strtotime($noty['ADDED']))
                    );
                }
                $modules[$i]["NOTIFICATIONS_JSON"] = htmlspecialchars(
                    json_encode($notyForJs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ENT_QUOTES, 'UTF-8'
                );
            } else {
                $modules[$i]["NOTIFICATIONS_COUNT"] = 0;
            }

            if (file_exists(ROOT . 'public/assets/img/modules/' . $modules[$i]['NAME'] . '.png')) {
                $modules[$i]['ICON_SM'] = ROOTHTML . 'assets/img/modules/' . $modules[$i]['NAME'] . '.png';
            } else {
                $modules[$i]['ICON_SM'] = ROOTHTML . 'assets/img/modules/default.png';
            }
            if (isset($sidebarIcons[$modules[$i]['NAME']])) {
                $modules[$i]['ICON_BI'] = $sidebarIcons[$modules[$i]['NAME']];
            }
        }
        $modules[$last_allow]['LAST_IN_CATEGORY'] = 1;
        $out["SUB_MODULES"] = $modules;

        if ($this->action && isset($notifications[$this->action])) {
            $total = count($notifications[$this->action]);
            $notificationIcons = array(
                'info' => 'info-circle',
                'success' => 'circle-check',
                'warning' => 'alert-triangle',
                'danger' => 'alert-circle',
            );
            $moduleTitle = isset($out['NAV_MODULE_NAME']) && $out['NAV_MODULE_NAME'] !== ''
                ? $out['NAV_MODULE_NAME']
                : $this->action;
            for ($i = 0; $i < $total; $i++) {
                $notification = $notifications[$this->action][$i];
                $timestamp = strtotime((string)$notification['ADDED']);

                $type = strtolower((string)$notification['TYPE']);
                if ($type === 'default') {
                    $type = 'info';
                }
                if (!isset($notificationIcons[$type])) {
                    $type = 'info';
                }

                $notifications[$this->action][$i]['TYPE'] = $type;
                $notifications[$this->action][$i]['ICON'] = $notificationIcons[$type];
                $notifications[$this->action][$i]['ROLE'] = in_array($type, array('warning', 'danger'), true)
                    ? 'alert'
                    : 'status';
                $notifications[$this->action][$i]['MODULE_TITLE'] = htmlspecialchars(
                    (string)$moduleTitle,
                    ENT_QUOTES,
                    'UTF-8'
                );
                $notifications[$this->action][$i]['ADDED_ISO'] = $timestamp ? date('c', $timestamp) : '';
                $notifications[$this->action][$i]['ADDED'] = $timestamp
                    ? date('d.m.Y H:i:s', $timestamp)
                    : (string)$notification['ADDED'];
            }
            $out['MODULE_NOTIFICATIONS'] = $notifications[$this->action];
        }

        if (is_dir(DIR_MODULES . 'app_tdwiki')) {
            $out['APP_TDWIKI_INSTALLED'] = 1;
        }
        if (is_dir(DIR_MODULES . 'optimizer')) {
            $out['OPTIMIZER_INSTALLED'] = 1;
        }

        $out["ACTION"] = $this->action;
        if (!isset($out['TITLE'])) {
            $out['TITLE'] = LANG_CONTROL_PANEL;
        }

        global $system_errors_detected;
        if (isset($system_errors_detected) && is_array($system_errors_detected)) {
            $out['RUNNING_WITH_ERRORS'] = 1;
            foreach ($system_errors_detected as $body) {
                $out['SYSTEM_ERRORS'][] = array('BODY' => $body);
            }
        }

        if (!empty($out['NOT_FOUND'])) {
            require_once(ROOT . 'lib/ui/MDJUI.php');
            $out['TITLE'] = 'Страница не найдена | ' . PROJECT_TITLE;
            $out['NOT_FOUND_HTML'] = MDJUI::emptyState(array(
                'icon' => 'error-404',
                'title' => 'Страница не найдена',
                'text' => 'Такого раздела нет или он был удалён. Проверьте ссылку или вернитесь в панель управления.',
                'action' => MDJUI::button('На главную', array('href' => rtrim(ROOTHTML, '/') . '/panel/', 'icon' => 'home'))
                    . MDJUI::button('К классам', array('href' => rtrim(ROOTHTML, '/') . '/panel/classes.html', 'variant' => 'secondary', 'icon' => 'hierarchy')),
                'class' => 'md-error-state',
            ));
        }

        $this->data = $out;
        $p = new parser(DIR_TEMPLATES . $this->name . ".html", $this->data, $this);
        return $p->result;

    }

    function registerPanelActivityLog()
    {
        global $session;
        global $ajax_panel;

        if (!isModuleInstalled('actions_log')) {
            return;
        }

        if (defined('NO_DATABASE_CONNECTION')) {
            return;
        }

        if (isset($ajax_panel) && $ajax_panel) {
            return;
        }

        $request_method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string)$_SERVER['REQUEST_METHOD']) : 'GET';
        $module_name = $this->action ? (string)$this->action : 'dashboard';
        $view_mode = trim(gr('view_mode'));
        $record_context = array(
            'source' => 'admin',
            'module' => $module_name,
            'view_mode' => $view_mode,
            'object_type' => 'module',
            'object_id' => gr('id'),
            'object_title' => $module_name,
            'request_method' => $request_method,
            'result' => 'ok',
            'url' => isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : ''
        );

        if ($request_method == 'GET') {
            $route_key = $module_name . '|' . $view_mode . '|' . (string)gr('id') . '|' . $record_context['url'];
            $last_route = $session->has('ACTIONS_LOG_LAST_ROUTE') ? (string)$session->get('ACTIONS_LOG_LAST_ROUTE') : '';
            $last_route_ts = $session->has('ACTIONS_LOG_LAST_ROUTE_TS') ? (int)$session->get('ACTIONS_LOG_LAST_ROUTE_TS') : 0;
            if ($route_key != $last_route || (time() - $last_route_ts) > 10) {
                logAction('control_panel_navigation', $module_name, $record_context);
                $session->set('ACTIONS_LOG_LAST_ROUTE', $route_key);
                $session->set('ACTIONS_LOG_LAST_ROUTE_TS', time());
            }
        } elseif ($request_method == 'POST') {
            $post_fields = array_keys($_POST);
            $safe_fields = array();
            $total = count($post_fields);
            for ($i = 0; $i < $total; $i++) {
                $field_name = (string)$post_fields[$i];
                if (!preg_match('/pass|password|token|secret|auth|cookie|session|private|apikey|api_key|key/i', $field_name)) {
                    $safe_fields[] = $field_name;
                }
            }
            $record_context['payload'] = array(
                'post_fields' => $safe_fields,
                'post_fields_total' => $total
            );
            logAction('control_panel_submit', $module_name, $record_context);
        }
    }

// --------------------------------------------------------------------
}

