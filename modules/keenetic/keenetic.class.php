<?php
/**
 * Keenetic — MajorDoMo module.
 *
 * Interfaces Keenetic internet centres with MajorDoMo:
 *  - router availability and firmware updates;
 *  - connected devices, their status and object bindings;
 *  - connections (VPN, PPPoE, ...) and DNS records;
 *  - per-device and per-connection PHP handlers.
 *
 * The module is admin-UI first: all pages use the MajorDoMo design system
 * (`md-*`). Page assets live in `public/assets/modules/keenetic/`.
 *
 * @package MajorDoMo
 * @author  Wizard <sergejey@gmail.com>
 * @version 0.5
 */

require_once __DIR__ . '/keenetic_helpers.inc.php';

class keenetic extends module
{
    const VERSION = '0.5';

    /** @var bool Debug logging to DebMes (`LOG_DEBMES`). */
    public $debug = false;

    /** @var bool Schema check is done once per request. */
    private static $schemaChecked = false;

    public function __construct()
    {
        $this->name = 'keenetic';
        $this->title = 'Keenetic';
        $this->module_category = '<#LANG_SECTION_DEVICES#>';
        $this->checkInstalled();
        $this->getConfig();
        $this->debug = !empty($this->config['LOG_DEBMES']);
    }

    /**
     * Saving module parameters.
     *
     * @param array|int $data
     * @return string
     */
    public function saveParams($data = 1)
    {
        $p = array();
        if (isset($this->id)) {
            $p['id'] = $this->id;
        }
        if (isset($this->view_mode)) {
            $p['view_mode'] = $this->view_mode;
        }
        if (isset($this->edit_mode)) {
            $p['edit_mode'] = $this->edit_mode;
        }
        if (isset($this->data_source)) {
            $p['data_source'] = $this->data_source;
        }
        if (isset($this->tab)) {
            $p['tab'] = $this->tab;
        }
        return parent::saveParams($p);
    }

    /**
     * Getting module parameters from the query string.
     *
     * @return void
     */
    public function getParams()
    {
        global $id, $mode, $view_mode, $edit_mode, $data_source, $tab;
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
        if (isset($data_source)) {
            $this->data_source = $data_source;
        }
        if (isset($tab)) {
            $this->tab = $tab;
        }
    }

    /**
     * Run the module.
     *
     * @return void
     */
    public function run()
    {
        $out = array();
        if ($this->action === 'admin') {
            $this->admin($out);
        } else {
            $this->usual($out);
        }
        if (isset($this->owner->action)) {
            $out['PARENT_ACTION'] = $this->owner->action;
        }
        if (isset($this->owner->name)) {
            $out['PARENT_NAME'] = $this->owner->name;
        }
        $out['VIEW_MODE'] = (string)$this->view_mode;
        $out['EDIT_MODE'] = $this->edit_mode;
        $out['MODE'] = $this->mode;
        $out['ACTION'] = $this->action;
        $out['DATA_SOURCE'] = 'keenetic_routers';
        $out['TAB'] = array_key_exists('TAB', $out) ? $out['TAB'] : (string)$this->tab;
        $this->data = $out;

        $p = new parser(DIR_TEMPLATES . $this->name . '/' . $this->name . '.html', $this->data, $this);
        $this->result = $p->result;
    }

    /**
     * Backend entry point.
     *
     * @param array $out
     * @return void
     */
    public function admin(&$out)
    {
        $this->getConfig();
        $this->ensureSchema();

        $out['DATA_SOURCE'] = 'keenetic_routers';
        $out['KEENETIC_VERSION'] = self::VERSION;
        $out['LOG_DEBMES'] = !empty($this->config['LOG_DEBMES']) ? 1 : 0;
        $out['NOTIFY_FIRMWARE'] = $this->notifyEnabled() ? 1 : 0;
        $out['CHECK_FIRMWARE'] = $this->firmwareCheckEnabled() ? 1 : 0;
        $out['TAB'] = (string)$this->tab;
        $this->applyFlash($out);

        $view = (string)$this->view_mode;
        if ($view === '') {
            $view = 'search_keenetic_routers';
        }

        // Legacy data_source values are accepted but no longer change routing.
        if ($view === 'update_settings' || $view === 'settings') {
            $this->handleSettings($out);
            return;
        }

        switch ($view) {
            case 'search_keenetic_routers':
                $this->searchKeeneticRouters($out);
                return;

            case 'edit_keenetic_routers':
                $this->editKeeneticRouter($out);
                return;

            case 'devices':
                $this->listKeeneticDevices($out);
                return;

            case 'info_keenetic_devices':
                $this->infoKeeneticDevice($out);
                return;

            case 'show_help':
                $out['HELP_MODE'] = 1;
                return;

            case 'update_firmware':
                $this->updateFirmwareAction((int)$this->id, $this->backUrl());
                return;

            case 'reboot_router':
                $this->rebootRouterAction((int)$this->id, $this->backUrl());
                return;

            case 'refresh_router':
                $this->refreshRouterAction((int)$this->id, $this->backUrl());
                return;

            case 'save_config':
                $this->saveConfigAction((int)$this->id, $this->backUrl());
                return;

            case 'toggle_track':
                $this->toggleTrackAction((int)$this->id, $this->backUrl());
                return;

            case 'wol_device':
                $this->wolDeviceAction((int)$this->id, $this->backUrl());
                return;

            case 'register_device':
                $this->registerDeviceAction((int)$this->id, $this->backUrl());
                return;

            case 'delete_device':
                $this->deleteDeviceAction((int)$this->id, $this->backUrl());
                return;

            case 'delete_keenetic_routers':
                $this->deleteRouterAction((int)$this->id);
                return;

            case 'toggle_connection':
                $this->toggleConnectionJson();
                return;
        }

        $out['ERR'] = 1;
        $out['ERR_ALERT'] = 'Неизвестный раздел модуля.';
    }

    /**
     * Frontend entry point.
     *
     * The module has no public pages. Legacy AJAX endpoints keep working:
     * `/ajax/keenetic.html?changeconn=1&id=...` toggles a connection.
     *
     * @param array $out
     * @return void
     */
    public function usual(&$out)
    {
        if (gr('changeconn') || (string)gr('view_mode') === 'toggle_connection') {
            $this->toggleConnectionJson();
            return;
        }
        if (!$this->isAdminRequest()) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Forbidden';
            exit;
        }
        $this->admin($out);
    }

    /**
     * Public API used by scripts and `callAPI`.
     *
     * @param array $params id|address, path, data, save
     * @return array|false
     */
    public function api($params)
    {
        $id = isset($params['id']) ? trim((string)$params['id']) : '';
        if ($id === '') {
            return false;
        }

        if (ctype_digit($id)) {
            $router = SQLSelectOne('SELECT * FROM keenetic_routers WHERE ID=' . (int)$id);
        } else {
            $router = SQLSelectOne('SELECT * FROM keenetic_routers WHERE ADDRESS="' . DBSafe($id) . '"');
        }
        if (empty($router['ID'])) {
            return false;
        }

        $path = isset($params['path']) ? trim((string)$params['path']) : '';
        if ($path !== '' && !preg_match('#^[A-Za-z0-9_./-]+$#', $path)) {
            return false;
        }
        $path = trim($path, '/');
        if (strpos($path, '..') !== false) {
            return false;
        }

        $data = $params['data'] ?? '';
        if (is_array($data)) {
            $data = json_encode($data, JSON_UNESCAPED_UNICODE);
        }
        $save = !empty($params['save']);

        return $this->getdata($router, $path, (string)$data, $save);
    }

    // ---------------------------------------------------------------------
    // Legacy public method wrappers
    // ---------------------------------------------------------------------

    /**
     * Legacy entry point kept for code that called the old module methods.
     *
     * @param array $out
     * @return void
     */
    public function search_keenetic_routers(&$out)
    {
        $this->searchKeeneticRouters($out);
    }

    /**
     * Legacy entry point kept for code that called the old module methods.
     *
     * @param array $out
     * @param int $id
     * @return void
     */
    public function edit_keenetic_routers(&$out, $id)
    {
        $this->id = $id;
        $this->editKeeneticRouter($out);
    }

    /**
     * Legacy entry point kept for code that called the old module methods.
     *
     * @param array $out
     * @param int $id
     * @return void
     */
    public function info_keenetic_devices(&$out, $id)
    {
        $this->id = $id;
        $this->infoKeeneticDevice($out);
    }

    /**
     * Legacy entry point kept for code that called the old module methods.
     *
     * @param int $id
     * @return void
     */
    public function delete_keenetic_routers($id)
    {
        $this->deleteKeeneticRouter($id);
    }

    // ---------------------------------------------------------------------
    // Settings
    // ---------------------------------------------------------------------

    /**
     * Module settings page.
     *
     * @param array $out
     * @return void
     */
    private function handleSettings(&$out)
    {
        if ($this->mode === 'update') {
            $this->config['LOG_DEBMES'] = gr('log_debmes') ? 1 : 0;
            $this->config['NOTIFY_FIRMWARE'] = gr('notify_firmware') ? 1 : 0;
            $this->config['CHECK_FIRMWARE'] = gr('check_firmware') ? 1 : 0;
            $this->saveConfig();
            $this->debug = !empty($this->config['LOG_DEBMES']);
            $out['LOG_DEBMES'] = $this->debug ? 1 : 0;
            $out['NOTIFY_FIRMWARE'] = $this->notifyEnabled() ? 1 : 0;
            $out['CHECK_FIRMWARE'] = $this->firmwareCheckEnabled() ? 1 : 0;
            $out['OK'] = 1;
            setGlobal('cycle_keeneticControl', 'restart');
        }

        $installed = $this->tablesInstalled();
        $out['HAS_TABLES'] = $installed ? 1 : 0;
        if ($installed) {
            $stats = $this->buildRouterStats();
            $out['STATS_ROUTERS'] = $stats['routers_total'];
            $out['STATS_DEVICES'] = $stats['devices_total'];
            $out['STATS_TRACKED'] = $stats['devices_tracked'];
            $out['STATS_CONNECTIONS'] = $stats['connections_total'];

            $dbRouters = SQLSelectOne('SELECT COUNT(*) AS CNT FROM keenetic_routers');
            $dbDevices = SQLSelectOne('SELECT COUNT(*) AS CNT FROM keenetic_devices');
            $dbConnections = SQLSelectOne('SELECT COUNT(*) AS CNT FROM keenetic_connections');
            $out['DB_ROUTERS'] = (int)($dbRouters['CNT'] ?? 0);
            $out['DB_DEVICES'] = (int)($dbDevices['CNT'] ?? 0);
            $out['DB_CONNECTIONS'] = (int)($dbConnections['CNT'] ?? 0);
        } else {
            $out['STATS_ROUTERS'] = 0;
            $out['STATS_DEVICES'] = 0;
            $out['STATS_TRACKED'] = 0;
            $out['STATS_CONNECTIONS'] = 0;
            $out['DB_ROUTERS'] = 0;
            $out['DB_DEVICES'] = 0;
            $out['DB_CONNECTIONS'] = 0;
        }
        $out['TAB'] = 'settings';
    }

    /**
     * Flash messages from `?ok=` / `?err=` query params.
     *
     * @param array $out
     * @return void
     */
    private function applyFlash(&$out)
    {
        $okMap = array(
            'saved' => 'Роутер сохранён.',
            'settings' => 'Настройки модуля сохранены.',
            'deleted' => 'Роутер удалён вместе с устройствами и привязками.',
            'reboot' => 'Команда перезагрузки отправлена роутеру.',
            'firmware' => 'Установка обновления прошивки запущена.',
            'config' => 'Конфигурация роутера сохранена в его памяти.',
            'refresh' => 'Данные роутера обновлены.',
            'wol' => 'Пакет Wake-on-LAN отправлен.',
            'register' => 'Устройство зарегистрировано на роутере.',
            'device_deleted' => 'Устройство удалено.',
            'tracked' => 'Отслеживание включено: объект создан, метод track будет вызываться при опросе.',
            'untracked' => 'Отслеживание отключено, объект удалён.',
            'dns_added' => 'DNS-запись добавлена.',
            'dns_deleted' => 'DNS-запись удалена.',
            'conn_saved' => 'Привязки подключений и обработчик сохранены.',
            'connected' => 'Подключение включено.',
            'disconnected' => 'Подключение выключено.',
        );
        $errMap = array(
            'auth' => 'Не удалось авторизоваться на роутере. Проверьте адрес, логин и пароль.',
            'probe' => 'Роутер доступен, но не ответил на служебный запрос. Проверьте прошивку и права пользователя.',
            'firmware' => 'Роутер не принял команду обновления прошивки.',
            'reboot' => 'Роутер не принял команду перезагрузки.',
            'config' => 'Не удалось сохранить конфигурацию на роутере.',
            'refresh' => 'Не удалось получить данные от роутера.',
            'device' => 'Устройство не найдено.',
            'register' => 'Не удалось зарегистрировать устройство на роутере.',
            'delete' => 'Не удалось удалить запись.',
            'track_exists' => 'Объект с таким именем уже существует в системе. Переименуйте устройство на роутере или отключите отслеживание.',
            'track' => 'Не удалось изменить отслеживание устройства.',
            'dns' => 'Не удалось изменить DNS-запись. Проверьте домен и IP-адрес.',
            'connection' => 'Не удалось переключить подключение.',
        );

        $ok = (string)gr('ok');
        if ($ok !== '' && isset($okMap[$ok])) {
            $out['FLASH_OK'] = $okMap[$ok];
        }
        $err = (string)gr('err');
        if ($err !== '' && isset($errMap[$err])) {
            $out['FLASH_ERR'] = $errMap[$err];
        }
    }

    private function notifyEnabled()
    {
        return !isset($this->config['NOTIFY_FIRMWARE']) || !empty($this->config['NOTIFY_FIRMWARE']);
    }

    private function firmwareCheckEnabled()
    {
        return !isset($this->config['CHECK_FIRMWARE']) || !empty($this->config['CHECK_FIRMWARE']);
    }

    // ---------------------------------------------------------------------
    // Routers list
    // ---------------------------------------------------------------------

    /**
     * Routers list with KPI counters.
     *
     * @param array $out
     * @return void
     */
    private function searchKeeneticRouters(&$out)
    {
        $stats = $this->buildRouterStats();
        $routers = SQLSelect('SELECT * FROM keenetic_routers ORDER BY TITLE');
        $result = array();
        if (is_array($routers)) {
            foreach ($routers as $router) {
                $result[] = $this->prepareRouterRow($router, $stats);
            }
        }

        $out['RESULT'] = $result;
        $out['ROUTERS_TOTAL'] = $stats['routers_total'];
        $out['ROUTERS_ONLINE'] = $stats['routers_online'];
        $out['ROUTERS_OFFLINE'] = $stats['routers_offline'];
        $out['INTERNET_ONLINE'] = $stats['internet_online'];
        $out['INTERNET_OFFLINE'] = $stats['internet_offline'];
        $out['UPDATES_TOTAL'] = $stats['updates'];
        $out['DEVICES_TOTAL'] = $stats['devices_total'];
        $out['DEVICES_ONLINE'] = $stats['devices_online'];
        $out['DEVICES_TRACKED'] = $stats['devices_tracked'];
        $out['CONNECTIONS_TOTAL'] = $stats['connections_total'];
        $out['CONNECTIONS_UP'] = $stats['connections_up'];
        $out['TAB'] = '';
    }

    /**
     * Shared counters for all pages.
     *
     * @return array<string,int>
     */
    private function buildRouterStats()
    {
        $stats = array(
            'routers_total' => 0,
            'routers_online' => 0,
            'routers_offline' => 0,
            'internet_online' => 0,
            'internet_offline' => 0,
            'updates' => 0,
            'devices_total' => 0,
            'devices_online' => 0,
            'devices_tracked' => 0,
            'connections_total' => 0,
            'connections_up' => 0,
        );
        $perRouter = array();

        $routers = SQLSelect('SELECT * FROM keenetic_routers');
        if (is_array($routers)) {
            foreach ($routers as $router) {
                $id = (int)$router['ID'];
                $stats['routers_total']++;
                $perRouter[$id] = array(
                    'devices_total' => 0,
                    'devices_online' => 0,
                    'devices_tracked' => 0,
                    'connections_total' => 0,
                    'connections_up' => 0,
                );
                if ((int)$router['STATUS'] === 1) {
                    $stats['routers_online']++;
                    if ((int)$router['INET_STATUS'] === 1) {
                        $stats['internet_online']++;
                    } else {
                        $stats['internet_offline']++;
                    }
                } else {
                    $stats['routers_offline']++;
                }
                if (keenetic_firmware_update($router)) {
                    $stats['updates']++;
                }
            }
        }

        $devices = SQLSelect('SELECT ROUTER_ID, STATUS, TRACK FROM keenetic_devices');
        if (is_array($devices)) {
            foreach ($devices as $device) {
                $id = (int)$device['ROUTER_ID'];
                if (!isset($perRouter[$id])) {
                    continue;
                }
                $stats['devices_total']++;
                $perRouter[$id]['devices_total']++;
                if ((int)$device['STATUS'] === 1) {
                    $stats['devices_online']++;
                    $perRouter[$id]['devices_online']++;
                }
                if ((int)$device['TRACK'] === 1) {
                    $stats['devices_tracked']++;
                    $perRouter[$id]['devices_tracked']++;
                }
            }
        }

        $connections = SQLSelect('SELECT ROUTER_ID, STATUS FROM keenetic_connections');
        if (is_array($connections)) {
            foreach ($connections as $connection) {
                $id = (int)$connection['ROUTER_ID'];
                if (!isset($perRouter[$id])) {
                    continue;
                }
                $stats['connections_total']++;
                $perRouter[$id]['connections_total']++;
                if ((int)$connection['STATUS'] === 1) {
                    $stats['connections_up']++;
                    $perRouter[$id]['connections_up']++;
                }
            }
        }

        $stats['per_router'] = $perRouter;
        return $stats;
    }

    /**
     * Prepare one router row for the table.
     *
     * @param array $router
     * @param array $stats
     * @return array
     */
    private function prepareRouterRow($router, $stats)
    {
        $id = (int)($router['ID'] ?? 0);
        $routerStats = $stats['per_router'][$id] ?? array(
            'devices_total' => 0,
            'devices_online' => 0,
            'devices_tracked' => 0,
            'connections_total' => 0,
            'connections_up' => 0,
        );

        $status = keenetic_router_state($router['STATUS'] ?? 0);
        $internet = keenetic_internet_state($router);
        $update = keenetic_firmware_update($router);
        $href = keenetic_safe_url($router['HREF_FW'] ?? '');
        $addressHref = keenetic_address_href($router['ADDRESS'] ?? '');

        $row = array(
            'ID' => $id,
            'TITLE' => (string)($router['TITLE'] ?? ''),
            'ADDRESS' => (string)($router['ADDRESS'] ?? ''),
            'ADDRESS_HREF' => $addressHref,
            'MODEL' => (string)($router['MODEL'] ?? ''),
            'FIRMWARE' => (string)($router['FIRMWARE'] ?? ''),
            'NEW_FIRMWARE' => (string)($router['NEW_FIRMWARE'] ?? ''),
            'SERIAL' => (string)($router['SERIAL'] ?? ''),
            'HREF_FW' => $href,
            'STATUS' => (int)($router['STATUS'] ?? 0),
            'STATUS_STATE' => $status['state'],
            'STATUS_LABEL' => $status['label'],
            'INET_STATUS' => (int)($router['INET_STATUS'] ?? 0),
            'INET_STATE' => $internet['state'],
            'INET_LABEL' => $internet['label'],
            'UPDATE' => $update ? 1 : 0,
            'NO_UPDATE' => !empty($router['NO_UPDATE']) ? 1 : 0,
            'MWS' => !empty($router['MWS']) ? 1 : 0,
            'REQ_PERIOD' => (int)($router['REQ_PERIOD'] ?? 0),
            'DEVICES_TOTAL' => $routerStats['devices_total'],
            'DEVICES_ONLINE' => $routerStats['devices_online'],
            'DEVICES_TRACKED' => $routerStats['devices_tracked'],
            'CONNECTIONS_TOTAL' => $routerStats['connections_total'],
            'CONNECTIONS_UP' => $routerStats['connections_up'],
            'UPDATED' => (string)($router['UPDATED'] ?? ''),
            'UPDATED_HUMAN' => keenetic_relative_time($router['UPDATED'] ?? ''),
            'UPDATED_TITLE' => keenetic_datetime($router['UPDATED'] ?? ''),
        );
        $row['ROW_STATE'] = $row['STATUS'] === 1 ? 'online' : 'offline';
        $row['ROW_UPDATE'] = $update ? '1' : '0';
        $row['SEARCH'] = implode(' ', array_filter(array(
            $row['TITLE'],
            $row['ADDRESS'],
            $row['MODEL'],
            $row['FIRMWARE'],
            $row['NEW_FIRMWARE'],
            $row['STATUS_LABEL'],
            $row['INET_LABEL'],
        )));

        return keenetic_escape_row($row);
    }

    // ---------------------------------------------------------------------
    // Router card
    // ---------------------------------------------------------------------

    /**
     * Router card: overview, settings, devices, connections, DNS.
     *
     * @param array $out
     * @return void
     */
    private function editKeeneticRouter(&$out)
    {
        $id = (int)($this->id ?? 0);
        $router = $id ? SQLSelectOne('SELECT * FROM keenetic_routers WHERE ID=' . $id) : array();

        if ($id && empty($router['ID'])) {
            $out['ERR'] = 1;
            $out['ERR_ALERT'] = 'Роутер не найден.';
            return;
        }

        $tab = (string)$this->tab;
        if (!in_array($tab, array('', 'settings', 'data', 'cnct', 'dns'), true)) {
            $tab = '';
        }

        if ($this->mode === 'update') {
            if ($tab === '' || $tab === 'settings') {
                $savedId = 0;
                $ok = $this->saveRouter($out, $id, $savedId);
                if ($ok && $savedId) {
                    $this->redirect('?view_mode=edit_keenetic_routers&id=' . $savedId . '&tab=settings&ok=saved');
                }
            } elseif ($tab === 'cnct') {
                $this->saveConnectionsTab($out, $id);
                $router = SQLSelectOne('SELECT * FROM keenetic_routers WHERE ID=' . $id);
            } elseif ($tab === 'dns') {
                $this->saveDnsTab($out, $id);
                $router = SQLSelectOne('SELECT * FROM keenetic_routers WHERE ID=' . $id);
            }
        }

        // DNS record removal is a GET action from the table.
        $deleteDomain = trim((string)gr('delete_domain'));
        if ($deleteDomain !== '' && $id) {
            if ($this->deleteDnsRecord($router, $deleteDomain)) {
                $this->redirect('?view_mode=edit_keenetic_routers&id=' . $id . '&tab=dns&ok=dns_deleted');
            }
            $this->redirect('?view_mode=edit_keenetic_routers&id=' . $id . '&tab=dns&err=dns');
        }

        if (!$id) {
            $this->renderNewRouter($out);
            return;
        }

        $stats = $this->buildRouterStats();
        $routerStats = $stats['per_router'][$id] ?? array(
            'devices_total' => 0,
            'devices_online' => 0,
            'devices_tracked' => 0,
            'connections_total' => 0,
            'connections_up' => 0,
        );

        $prepared = $this->prepareRouterRow($router, $stats);
        foreach ($prepared as $key => $value) {
            $out[$key] = $value;
        }

        $form = isset($out['_FORM']) && is_array($out['_FORM']) ? $out['_FORM'] : array();
        $field = static function ($key, $fallback) use ($form) {
            return array_key_exists($key, $form) ? $form[$key] : $fallback;
        };

        $out['STATUS_LABEL'] = keenetic_router_state($router['STATUS'] ?? 0)['label'];
        $out['INET_LABEL'] = keenetic_internet_state($router)['label'];
        $out['INET_STATE'] = keenetic_internet_state($router)['state'];
        $out['PASSWORD_SET'] = !empty($router['PASSWORD']) ? 1 : 0;
        $out['PASSWORD'] = '';
        $out['NO_UPDATE'] = (int)$field('NO_UPDATE', !empty($router['NO_UPDATE']) ? 1 : 0);
        $out['AUTO_REBOOT'] = (int)$field('AUTO_REBOOT', (int)($router['AUTO_REBOOT'] ?? 0));
        $out['LOGIN'] = keenetic_h((string)$field('LOGIN', (string)($router['LOGIN'] ?? '')));
        $out['ADDRESS'] = keenetic_h((string)$field('ADDRESS', (string)($router['ADDRESS'] ?? '')));
        $out['TITLE'] = keenetic_h((string)$field('TITLE', (string)($router['TITLE'] ?? '')));
        $out['MODEL'] = keenetic_h($router['MODEL'] ?? '');
        $out['FIRMWARE'] = keenetic_h($router['FIRMWARE'] ?? '');
        $out['NEW_FIRMWARE'] = keenetic_h($router['NEW_FIRMWARE'] ?? '');
        $out['SERIAL'] = keenetic_h($router['SERIAL'] ?? '');
        $out['REQ_PERIOD'] = (int)$field('REQ_PERIOD', (int)($router['REQ_PERIOD'] ?? 0));
        $out['UPDATED_HUMAN'] = keenetic_h(keenetic_relative_time($router['UPDATED'] ?? ''));
        $out['UPDATED_TITLE'] = keenetic_h(keenetic_datetime($router['UPDATED'] ?? ''));
        $out['HREF_FW'] = keenetic_h(keenetic_safe_url($router['HREF_FW'] ?? ''));
        $out['UPDATE'] = keenetic_firmware_update($router) ? 1 : 0;
        $this->tab = $tab;

        $out['DEVICES_TOTAL'] = $routerStats['devices_total'];
        $out['DEVICES_ONLINE'] = $routerStats['devices_online'];
        $out['DEVICES_TRACKED'] = $routerStats['devices_tracked'];
        $out['CONNECTIONS_TOTAL'] = $routerStats['connections_total'];
        $out['CONNECTIONS_UP'] = $routerStats['connections_up'];

        if ($tab === '' || $tab === 'settings') {
            $internet = $this->getInternetDevice($id);
            $out['INTERNET_EXISTS'] = $internet ? 1 : 0;
            $out['INTERNET_IP'] = $internet['IP'] ?? '—';
            $out['INTERNET_STATUS_TEXT'] = $internet['STATUS_TEXT'] ?? 'Нет данных';
            $out['INTERNET_STATUS_STATE'] = $internet['STATUS_STATE'] ?? 'idle';
            $out['INTERNET_UPDATED_HUMAN'] = $internet['UPDATED_HUMAN'] ?? '—';
            $out['INTERNET_LOG_HTML'] = $internet['LOG_HTML'] ?? '';
            $out['TAB'] = $tab;
            return;
        }

        if ($tab === 'data') {
            $out['DEVICES'] = $this->getRouterDevices($id);
            $out['TAB'] = 'data';
            return;
        }

        if ($tab === 'cnct') {
            $out['CONNECTIONS'] = $this->getRouterConnections($id);
            $out['CNCT_SCRIPT'] = keenetic_h($router['CNCT_SCRIPT'] ?? '');
            if (!empty($out['ERR_CODE']) && isset($out['_CNCT_SCRIPT'])) {
                $out['CNCT_SCRIPT'] = keenetic_h($out['_CNCT_SCRIPT']);
            }
            $this->prepareCodeEditor($out, 'keenetic_cnct_' . $id);
            $out['TAB'] = 'cnct';
            return;
        }

        if ($tab === 'dns') {
            $dns = $this->fetchDnsRecords($router);
            $out['DNS'] = $dns['records'];
            $out['DNS_TOTAL'] = count($dns['records']);
            $out['DNS_ERR'] = $dns['error'] ? 1 : 0;
            $out['TAB'] = 'dns';
        }
    }

    /**
     * "New router" page: only the settings form.
     *
     * @param array $out
     * @return void
     */
    private function renderNewRouter(&$out)
    {
        $out['TITLE'] = keenetic_h((string)gr('title'));
        $out['ADDRESS'] = keenetic_h((string)gr('address'));
        $out['LOGIN'] = keenetic_h((string)gr('login'));
        $out['PASSWORD'] = '';
        $out['PASSWORD_SET'] = 0;
        $out['REQ_PERIOD'] = gr('period') !== null ? max(0, (int)gr('period')) : 5;
        $out['AUTO_REBOOT'] = gr('reboot') !== null ? max(0, (int)gr('reboot')) : 0;
        $out['NO_UPDATE'] = gr('no_update') ? 1 : 0;
        $out['TAB'] = 'settings';
        $out['IS_NEW'] = 1;
    }

    /**
     * Validate and save a router. Returns true on success.
     *
     * @param array $out
     * @param int $id
     * @param int $savedId
     * @return bool
     */
    private function saveRouter(&$out, $id, &$savedId)
    {
        $isNew = !$id;
        $rec = $isNew ? array() : SQLSelectOne('SELECT * FROM keenetic_routers WHERE ID=' . (int)$id);
        if (!$isNew && empty($rec['ID'])) {
            $out['ERR'] = 1;
            $out['ERR_ALERT'] = 'Роутер не найден.';
            return false;
        }

        $title = trim((string)gr('title'));
        $address = $this->normalizeAddress((string)gr('address'));
        $login = trim((string)gr('login'));
        $passwordInput = (string)gr('password');
        $period = (int)gr('period');
        $autoReboot = (int)gr('reboot');
        $noUpdate = gr('no_update') ? 1 : 0;

        $errors = array();
        if ($title === '') {
            $errors[] = 'Укажите название роутера.';
        } elseif (mb_strlen($title) > 100) {
            $errors[] = 'Название не должно быть длиннее 100 символов.';
        }
        if ($address === '') {
            $errors[] = 'Укажите IP-адрес или KeenDNS-имя роутера.';
        } elseif (!$this->isValidAddress($address)) {
            $errors[] = 'Адрес должен быть IP-адресом или доменным именем, можно с портом.';
        }
        if ($login === '') {
            $errors[] = 'Укажите имя пользователя.';
        }
        if ($isNew && $passwordInput === '') {
            $errors[] = 'Укажите пароль.';
        }
        if ($period < 0) {
            $period = 0;
        }
        if ($period > 86400) {
            $period = 86400;
        }
        if ($autoReboot < 0) {
            $autoReboot = 0;
        }
        if ($autoReboot > 86400) {
            $autoReboot = 86400;
        }

        $duplicate = SQLSelectOne('SELECT ID FROM keenetic_routers WHERE TITLE="' . DBSafe($title) . '"');
        if (!empty($duplicate['ID']) && (int)$duplicate['ID'] !== (int)$id) {
            $errors[] = 'Роутер с именем «' . $title . '» уже существует.';
        }

        $encrypted = $passwordInput !== '' ? $this->encryptPassword($passwordInput) : (string)($rec['PASSWORD'] ?? '');

        // Keep submitted values visible when validation fails.
        $rec['TITLE'] = $title;
        $rec['ADDRESS'] = $address;
        $rec['LOGIN'] = $login;
        $rec['REQ_PERIOD'] = $period;
        $rec['AUTO_REBOOT'] = $autoReboot;
        $rec['NO_UPDATE'] = $noUpdate;
        $out['_FORM'] = array(
            'TITLE' => $title,
            'ADDRESS' => $address,
            'LOGIN' => $login,
            'REQ_PERIOD' => $period,
            'AUTO_REBOOT' => $autoReboot,
            'NO_UPDATE' => $noUpdate,
        );

        if ($errors) {
            $out['ERR'] = 1;
            $out['ERR_ALERT'] = keenetic_h(implode(' ', $errors));
            return false;
        }

        $cookies = $this->auth($address, $login, $encrypted);
        if (!$cookies) {
            $out['ERR'] = 1;
            $out['ERR_ALERT'] = 'Не удалось авторизоваться на роутере. Проверьте адрес, логин и пароль.';
            return false;
        }

        $probeRouter = array(
            'ADDRESS' => $address,
            'LOGIN' => $login,
            'PASSWORD' => $encrypted,
            'COOKIES' => $cookies,
            'ID' => (int)$id,
        );
        $probe = $this->getdata($probeRouter, 'show', '{"version":{},"identification":{},"internet":{"status":{}},"system":{}}');
        if (!$probe) {
            $out['ERR'] = 1;
            $out['ERR_ALERT'] = 'Роутер доступен, но не ответил на служебный запрос. Проверьте пользователя и прошивку.';
            return false;
        }

        $model = (string)($probe['version']['model'] ?? '');
        if ($model === 'Keenetic') {
            $model = (string)($probe['version']['device'] ?? '');
        }
        $release = (string)($probe['version']['release'] ?? '');
        $components = array_map('trim', explode(',', (string)($probe['version']['ndw']['components'] ?? '')));

        $rec['COOKIES'] = $cookies;
        $rec['MODEL'] = $model;
        $rec['FIRMWARE'] = $release;
        $rec['NEW_FIRMWARE'] = $release;
        $rec['HREF_FW'] = '';
        $rec['SERIAL'] = (string)($probe['identification']['serial'] ?? '');
        $rec['STATUS'] = 1;
        $rec['INET_STATUS'] = !empty($probe['internet']['status']['internet']) ? 1 : 0;
        $rec['MWS'] = in_array('mws', $components, true) ? 1 : 0;
        $rec['UPDATED'] = date('Y-m-d H:i:s');
        $rec['PASSWORD'] = $encrypted;
        $rec['LOGIN'] = $login;
        $rec['ADDRESS'] = $address;

        if ($isNew) {
            unset($rec['ID']);
        }

        if ($isNew) {
            if (!$this->classExists($title)) {
                addClass($title, 'Keenetic');
            }
            $rec['ID'] = SQLInsert('keenetic_routers', $rec);
            $savedId = (int)$rec['ID'];
            $this->createInternetDevice($rec);
            $this->WriteLog('Добавлен роутер ' . $title . ' (' . $address . ').');
        } else {
            SQLUpdate('keenetic_routers', $rec);
            $savedId = (int)$rec['ID'];
            $this->WriteLog('Обновлены настройки роутера ' . $title . '.');
        }

        setGlobal('cycle_keeneticControl', 'restart');
        return true;
    }

    /**
     * Create the "Интернет" pseudo-device for a router.
     *
     * @param array $router
     * @return void
     */
    private function createInternetDevice($router)
    {
        $existing = SQLSelectOne(
            'SELECT * FROM keenetic_devices WHERE MAC="0.0.0.0.0.0" AND ROUTER_ID=' . (int)$router['ID']
        );
        if (!empty($existing['ID'])) {
            return;
        }
        $inet = array(
            'TITLE' => 'Интернет',
            'MAC' => '0.0.0.0.0.0',
            'IP' => '0.0.0.0',
            'STATUS' => (int)($router['INET_STATUS'] ?? 0),
            'TYPE_CONNECT' => 0,
            'REGISTERED' => 1,
            'ROUTER_ID' => (int)$router['ID'],
            'SCRIPT' => "if(!\$status){ // интернет пропал\n\n}\nelse if(\$status == 1){ // интернет есть, основное подключение\n\n}\nelse if(\$status > 1){ // интернет есть, резервное подключение \$status-1\n\n}",
            'UPDATED' => date('Y-m-d H:i:s'),
        );
        SQLInsert('keenetic_devices', $inet);
    }

    // ---------------------------------------------------------------------
    // Router tabs: devices, connections, DNS
    // ---------------------------------------------------------------------

    /**
     * Devices of one router.
     *
     * @param int $routerId
     * @return array
     */
    private function getRouterDevices($routerId)
    {
        $rows = SQLSelect('SELECT * FROM keenetic_devices WHERE ROUTER_ID=' . (int)$routerId . ' ORDER BY TITLE');
        $result = array();
        if (!is_array($rows)) {
            return $result;
        }
        foreach ($rows as $row) {
            $result[] = $this->prepareDeviceRow($row);
        }
        return $result;
    }

    /**
     * Prepare one device row for a table.
     *
     * @param array $device
     * @param string $routerTitle
     * @return array
     */
    private function prepareDeviceRow($device, $routerTitle = '')
    {
        $kind = keenetic_device_kind($device['TYPE_CONNECT'] ?? 0);
        $online = (int)($device['STATUS'] ?? 0) === 1;
        $isInternet = (string)($device['MAC'] ?? '') === '0.0.0.0.0.0';

        $row = array(
            'ID' => (int)($device['ID'] ?? 0),
            'ROUTER_ID' => (int)($device['ROUTER_ID'] ?? 0),
            'TITLE' => (string)($device['TITLE'] ?? ''),
            'ROUTER_TITLE' => (string)$routerTitle,
            'MAC' => (string)($device['MAC'] ?? ''),
            'IP' => (string)($device['IP'] ?? ''),
            'IP_HREF' => keenetic_is_ip((string)($device['IP'] ?? '')) ? 'http://' . $device['IP'] : '',
            'STATUS' => $online ? 1 : 0,
            'STATUS_STATE' => $online ? 'online' : 'offline',
            'STATUS_LABEL' => $online ? 'В сети' : 'Не в сети',
            'TYPE_LABEL' => $kind['label'],
            'TYPE_ICON' => $kind['icon'],
            'TYPE_TONE' => $kind['tone'],
            'REGISTERED' => (int)($device['REGISTERED'] ?? 0),
            'REGISTERED_LABEL' => (int)($device['REGISTERED'] ?? 0) === 1 ? 'Зарегистрировано' : 'Не зарегистрировано',
            'TRACK' => (int)($device['TRACK'] ?? 0),
            'TRACK_STATE' => (int)($device['TRACK'] ?? 0) === 1 ? 'online' : 'idle',
            'TRACK_LABEL' => (int)($device['TRACK'] ?? 0) === 1 ? 'Отслеживается' : 'Без отслеживания',
            'BINDING' => keenetic_binding_label(
                $device['LINKED_OBJECT'] ?? '',
                $device['LINKED_PROPERTY'] ?? '',
                $device['LINKED_METHOD'] ?? ''
            ),
            'LINKED_OBJECT' => (string)($device['LINKED_OBJECT'] ?? ''),
            'LINKED_PROPERTY' => (string)($device['LINKED_PROPERTY'] ?? ''),
            'LINKED_METHOD' => (string)($device['LINKED_METHOD'] ?? ''),
            'UPDATED' => (string)($device['UPDATED'] ?? ''),
            'UPDATED_HUMAN' => keenetic_relative_time($device['UPDATED'] ?? ''),
            'UPDATED_TITLE' => keenetic_datetime($device['UPDATED'] ?? ''),
            'IS_INTERNET' => $isInternet ? 1 : 0,
        );
        $row['ROW_STATE'] = $online ? 'online' : 'offline';
        $row['ROW_TYPE'] = (int)($device['TYPE_CONNECT'] ?? 0) === 1 ? 'wifi' : 'lan';
        $row['ROW_TRACK'] = $row['TRACK'] ? '1' : '0';
        $row['SEARCH'] = implode(' ', array_filter(array(
            $row['TITLE'],
            $row['MAC'],
            $row['IP'],
            $row['TYPE_LABEL'],
            $row['BINDING'],
            $row['STATUS_LABEL'],
            $row['REGISTERED_LABEL'],
            $row['TRACK_LABEL'],
        )));

        return keenetic_escape_row($row);
    }

    /**
     * Connections of one router.
     *
     * @param int $routerId
     * @return array
     */
    private function getRouterConnections($routerId)
    {
        $rows = SQLSelect('SELECT * FROM keenetic_connections WHERE ROUTER_ID=' . (int)$routerId . ' ORDER BY TITLE');
        $result = array();
        if (!is_array($rows)) {
            return $result;
        }
        foreach ($rows as $row) {
            $state = keenetic_connection_state($row);
            $row['ADMIN_STATE'] = $state['admin_state'];
            $row['ADMIN_LABEL'] = $state['admin_label'];
            $row['LINK_STATE'] = $state['link_state'];
            $row['LINK_LABEL'] = $state['link_label'];

            // The linkedobject component reads its initial values from
            // $GLOBALS[<field name>]; the field names are built from the row ID.
            $GLOBALS['linked_object' . (int)$row['ID']] = (string)($row['LINKED_OBJECT'] ?? '');
            $GLOBALS['linked_property' . (int)$row['ID']] = (string)($row['LINKED_PROPERTY'] ?? '');
            $GLOBALS['linked_method' . (int)$row['ID']] = (string)($row['LINKED_METHOD'] ?? '');

            $row['BINDING'] = keenetic_binding_label(
                $row['LINKED_OBJECT'] ?? '',
                $row['LINKED_PROPERTY'] ?? '',
                $row['LINKED_METHOD'] ?? ''
            );
            $row['UPDATED_HUMAN'] = keenetic_relative_time($row['UPDATED'] ?? '');
            $row['UPDATED_TITLE'] = keenetic_datetime($row['UPDATED'] ?? '');
            $row['SEARCH'] = implode(' ', array_filter(array(
                $row['TITLE'],
                $row['TYPE'],
                $row['ADDRESS'],
                $row['ADMIN_LABEL'],
                $row['LINK_LABEL'],
                $row['BINDING'],
            )));
            $result[] = keenetic_escape_row($row);
        }
        return $result;
    }

    /**
     * Save connection bindings and the connection handler script.
     *
     * @param array $out
     * @param int $routerId
     * @return void
     */
    private function saveConnectionsTab(&$out, $routerId)
    {
        $router = SQLSelectOne('SELECT * FROM keenetic_routers WHERE ID=' . (int)$routerId);
        if (empty($router['ID'])) {
            return;
        }

        $rows = SQLSelect('SELECT * FROM keenetic_connections WHERE ROUTER_ID=' . (int)$routerId);
        $saved = 0;
        foreach ((array)$rows as $row) {
            $cid = (int)$row['ID'];
            $oldObject = (string)($row['LINKED_OBJECT'] ?? '');
            $oldProperty = (string)($row['LINKED_PROPERTY'] ?? '');
            $oldMethod = (string)($row['LINKED_METHOD'] ?? '');

            $newObject = trim((string)($_POST['linked_object' . $cid] ?? ''));
            $newProperty = trim((string)($_POST['linked_property' . $cid] ?? ''));
            $newMethod = trim((string)($_POST['linked_method' . $cid] ?? ''));

            if ($newObject !== '' && $newProperty === '' && $newMethod === '') {
                $newObject = '';
            }

            if ($oldObject !== $newObject || $oldProperty !== $newProperty) {
                if ($oldObject !== '' && $oldProperty !== '') {
                    removeLinkedProperty($oldObject, $oldProperty, $this->name);
                }
                if ($newObject !== '' && $newProperty !== '') {
                    addLinkedProperty($newObject, $newProperty, $this->name);
                }
            }

            $row['LINKED_OBJECT'] = $newObject;
            $row['LINKED_PROPERTY'] = $newProperty;
            $row['LINKED_METHOD'] = $newMethod;

            if ($newObject !== '' && $newProperty !== '') {
                setGlobal($newObject . '.' . $newProperty, (int)$row['STATUS']);
            }

            if ($row['LINKED_OBJECT'] !== $oldObject
                || $row['LINKED_PROPERTY'] !== $oldProperty
                || $row['LINKED_METHOD'] !== $oldMethod) {
                $row['UPDATED'] = date('Y-m-d H:i:s');
                SQLUpdate('keenetic_connections', $row);
                $saved++;
            }
        }

        $code = (string)gr('cnct_script');
        if ($code !== (string)($router['CNCT_SCRIPT'] ?? '')) {
            $error = $this->getUserCodeError($code);
            if ($error !== '') {
                $out['ERR_CODE'] = 1;
                $out['ERR_LINE'] = preg_replace('/[^0-9]/', '', $error);
                $out['ERRORS'] = keenetic_h($error);
                $out['_CNCT_SCRIPT'] = $code;
            } else {
                $router['CNCT_SCRIPT'] = $code;
                $router['UPDATED'] = date('Y-m-d H:i:s');
                SQLUpdate('keenetic_routers', $router);
                $saved++;
            }
        }

        if ($saved > 0 && empty($out['ERR_CODE'])) {
            $this->redirect('?view_mode=edit_keenetic_routers&id=' . (int)$routerId . '&tab=cnct&ok=conn_saved');
        }
    }

    /**
     * Add a DNS record from the DNS tab.
     *
     * @param array $out
     * @param int $routerId
     * @return void
     */
    private function saveDnsTab(&$out, $routerId)
    {
        $router = SQLSelectOne('SELECT * FROM keenetic_routers WHERE ID=' . (int)$routerId);
        if (empty($router['ID'])) {
            return;
        }
        $domain = trim((string)gr('title_new'));
        $ip = trim((string)gr('ip_new'));
        if ($domain === '' && $ip === '') {
            return;
        }
        if (!$this->isValidDnsName($domain) || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            $out['ERR'] = 1;
            $out['ERR_ALERT'] = 'Укажите корректное доменное имя и IPv4-адрес.';
            return;
        }

        $response = $this->getdata(
            $router,
            'ip/host',
            json_encode(array('domain' => $domain, 'address' => $ip), JSON_UNESCAPED_UNICODE),
            true
        );
        if (!$response) {
            $out['ERR'] = 1;
            $out['ERR_ALERT'] = 'Роутер не принял DNS-запись.';
            return;
        }
        if (($response['status'][0]['status'] ?? '') === 'error') {
            $out['ERR'] = 1;
            $out['ERR_ALERT'] = 'Роутер отклонил DNS-запись. Проверьте адрес.';
            return;
        }
        $this->WriteLog('DNS: добавлен ' . $domain . ' -> ' . $ip);
        $this->redirect('?view_mode=edit_keenetic_routers&id=' . (int)$routerId . '&tab=dns&ok=dns_added');
    }

    /**
     * Delete a DNS record.
     *
     * @param array $router
     * @param string $domain
     * @return bool
     */
    private function deleteDnsRecord($router, $domain)
    {
        if (!$this->isValidDnsName($domain)) {
            return false;
        }
        $response = $this->getdata(
            $router,
            'ip/host',
            json_encode(array('domain' => $domain, 'no' => true), JSON_UNESCAPED_UNICODE),
            true
        );
        if (!$response) {
            return false;
        }
        $this->WriteLog('DNS: удалён ' . $domain);
        return true;
    }

    /**
     * Read DNS records from the running configuration.
     *
     * @param array $router
     * @return array{records:array,error:bool}
     */
    private function fetchDnsRecords($router)
    {
        $data = $this->getdata($router, '', '{"show":{"running-config":{}}}');
        $records = array();
        $error = false;
        if (!$data) {
            return array('records' => $records, 'error' => true);
        }
        $lines = $data['show']['running-config']['message'] ?? array();
        foreach ((array)$lines as $line) {
            $line = (string)$line;
            if (strpos($line, 'ip host') === false) {
                continue;
            }
            $parts = preg_split('/\s+/', trim(str_replace('ip host ', '', $line)));
            if (!empty($parts[0])) {
                $records[] = array(
                    'ROUTER_ID' => (int)$router['ID'],
                    'TITLE' => keenetic_h($parts[0]),
                    'IP' => keenetic_h($parts[1] ?? ''),
                );
            }
        }
        return array('records' => $records, 'error' => $error);
    }

    // ---------------------------------------------------------------------
    // Devices list and device card
    // ---------------------------------------------------------------------

    /**
     * All devices across all routers.
     *
     * @param array $out
     * @return void
     */
    private function listKeeneticDevices(&$out)
    {
        $routerId = (int)gr('router_id');
        $where = $routerId > 0 ? ' WHERE d.ROUTER_ID=' . $routerId : '';
        $rows = SQLSelect(
            'SELECT d.*, r.TITLE AS ROUTER_TITLE FROM keenetic_devices d'
            . ' LEFT JOIN keenetic_routers r ON r.ID=d.ROUTER_ID'
            . $where
            . ' ORDER BY r.TITLE, d.TITLE'
        );

        $result = array();
        $online = 0;
        $tracked = 0;
        $wifi = 0;
        foreach ((array)$rows as $row) {
            $prepared = $this->prepareDeviceRow($row, (string)($row['ROUTER_TITLE'] ?? ''));
            $result[] = $prepared;
            if ((int)$prepared['STATUS'] === 1) {
                $online++;
            }
            if ((int)$prepared['TRACK'] === 1) {
                $tracked++;
            }
            if ($prepared['ROW_TYPE'] === 'wifi') {
                $wifi++;
            }
        }

        $out['RESULT'] = $result;
        $out['DEVICES_TOTAL'] = count($result);
        $out['DEVICES_ONLINE'] = $online;
        $out['DEVICES_TRACKED'] = $tracked;
        $out['DEVICES_WIFI'] = $wifi;
        $out['FILTER_ROUTER_ID'] = $routerId;
        $out['ROUTER_OPTIONS'] = $this->getRouterOptions($routerId);
        $out['TAB'] = 'devices';
    }

    /**
     * Router select options (pre-escaped).
     *
     * @param int $selected
     * @return array
     */
    private function getRouterOptions($selected = 0)
    {
        $rows = SQLSelect('SELECT ID, TITLE FROM keenetic_routers ORDER BY TITLE');
        $options = array();
        foreach ((array)$rows as $row) {
            $options[] = array(
                'ID' => (int)$row['ID'],
                'TITLE' => keenetic_h($row['TITLE']),
                'SELECTED' => (int)$row['ID'] === (int)$selected ? 1 : 0,
            );
        }
        return $options;
    }

    /**
     * The "Интернет" pseudo-device with a readable state and log.
     *
     * @param int $routerId
     * @return array
     */
    private function getInternetDevice($routerId)
    {
        $inet = SQLSelectOne(
            'SELECT * FROM keenetic_devices WHERE MAC="0.0.0.0.0.0" AND ROUTER_ID=' . (int)$routerId
        );
        if (empty($inet['ID'])) {
            return array();
        }
        $status = (int)($inet['STATUS'] ?? 0);
        if ($status === 0) {
            $statusText = 'Интернет отсутствует';
            $statusState = 'error';
        } elseif ($status === 1) {
            $statusText = 'Есть, основной канал';
            $statusState = 'online';
        } else {
            $statusText = 'Есть, резервный канал ' . ($status - 1);
            $statusState = 'warning';
        }
        return array(
            'ID' => (int)$inet['ID'],
            'IP' => keenetic_h($inet['IP'] ?? ''),
            'STATUS_TEXT' => $statusText,
            'STATUS_STATE' => $statusState,
            'UPDATED_HUMAN' => keenetic_h(keenetic_relative_time($inet['UPDATED'] ?? '')),
            'UPDATED_TITLE' => keenetic_h(keenetic_datetime($inet['UPDATED'] ?? '')),
            'LOG_HTML' => !empty($inet['LOG']) ? nl2br(keenetic_h($inet['LOG'])) : '',
        );
    }

    /**
     * Device card: live data, binding, tracking, script, log.
     *
     * @param array $out
     * @return void
     */
    private function infoKeeneticDevice(&$out)
    {
        $id = (int)($this->id ?? 0);
        $device = $id ? SQLSelectOne('SELECT * FROM keenetic_devices WHERE ID=' . $id) : array();
        if (empty($device['ID'])) {
            $out['ERR'] = 1;
            $out['ERR_ALERT'] = 'Устройство не найдено.';
            return;
        }
        $router = SQLSelectOne('SELECT * FROM keenetic_routers WHERE ID=' . (int)$device['ROUTER_ID']);
        if (empty($router['ID'])) {
            $out['ERR'] = 1;
            $out['ERR_ALERT'] = 'Роутер устройства не найден.';
            return;
        }

        $tab = (string)$this->tab;
        if (!in_array($tab, array('', 'code', 'log'), true)) {
            $tab = '';
        }
        $this->tab = $tab;

        $submittedScript = null;
        $deviceForm = array();
        if ($this->mode === 'update') {
            $form = (string)gr('form');
            if ($form === 'code') {
                $submittedScript = (string)gr('code');
                $this->saveDeviceScript($out, $device);
                if (empty($out['ERR_CODE'])) {
                    $submittedScript = null;
                }
            } else {
                $this->saveDeviceOverview($out, $device, $router);
                if (!empty($out['_DEVICE_FORM'])) {
                    $deviceForm = $out['_DEVICE_FORM'];
                }
            }
            $device = SQLSelectOne('SELECT * FROM keenetic_devices WHERE ID=' . $id);
            if (empty($device['ID'])) {
                $out['ERR'] = 1;
                $out['ERR_ALERT'] = 'Устройство было удалено.';
                return;
            }
        }

        $live = array();
        $liveError = '';
        if ((string)$device['MAC'] === '0.0.0.0.0.0') {
            $interface = $this->getdata($router, 'show/interface');
            if ($interface) {
                $wan = $this->backupWAN($interface['show']['interface'] ?? array());
                $live = array(
                    'UPTIME' => (int)($wan['UPTIME'] ?? 0),
                    'WAN_NAME' => (string)($wan['NAME'] ?? ''),
                    'WAN_IP' => (string)($wan['IP'] ?? ''),
                    'WAN_STATE' => (int)($wan['STATE'] ?? 0),
                    'WAN' => (int)($wan['WAN'] ?? 0),
                );
            } else {
                $liveError = 'Роутер не отдал состояние интерфейсов.';
            }
        } else {
            $payload = array(
                'show' => array(
                    'ip' => array('hotspot' => array('mac' => (string)$device['MAC'])),
                    'interface' => (object)array(),
                ),
            );
            if (!empty($router['MWS'])) {
                $payload['show']['mws'] = array('member' => (object)array());
            }
            $data = $this->getdata($router, '', json_encode($payload, JSON_UNESCAPED_UNICODE));
            if ($data) {
                $hosts = $this->normalizeRows($data['show']['ip']['hotspot']['host'] ?? array());
                $host = $hosts[0] ?? array();
                if ($host) {
                    $interfaces = !empty($router['MWS']) ? ($data['show']['mws']['member'] ?? array()) : array();
                    $wifies = $data['show']['interface'] ?? array();
                    $live = $this->parse_data($router, $host, $interfaces, $wifies);
                    $live['IP'] = (string)($host['ip'] ?? '');
                    $live['LINK'] = (string)($host['link'] ?? '');
                } else {
                    $liveError = 'Роутер не вернул данные по этому MAC-адресу.';
                }
            } else {
                $liveError = 'Не удалось получить данные от роутера.';
            }
        }

        $row = $this->prepareDeviceRow($device, (string)$router['TITLE']);
        foreach ($row as $key => $value) {
            $out[$key] = $value;
        }
        $out['ROUTER_ID'] = (int)$router['ID'];
        $out['ROUTER_TITLE_OUT'] = keenetic_h($router['TITLE']);
        $out['ROUTER_ADDRESS'] = keenetic_h($router['ADDRESS']);
        $out['HOSTNAME'] = keenetic_h($live['HOSTNAME'] ?? '');
        $out['TITLE'] = keenetic_h($deviceForm['TITLE'] ?? ($device['TITLE'] ?? ''));
        $out['MAC'] = keenetic_h($device['MAC'] ?? '');
        $out['IP'] = keenetic_h($live['IP'] ?? $device['IP'] ?? '');
        $out['IP_HREF'] = keenetic_is_ip((string)($live['IP'] ?? $device['IP'] ?? ''))
            ? 'http://' . ($live['IP'] ?? $device['IP'])
            : '';
        $out['TRACK'] = (int)($device['TRACK'] ?? 0);
        $out['TRACK_LABEL'] = (int)($device['TRACK'] ?? 0) === 1 ? 'Отслеживается' : 'Не отслеживается';
        $out['TRACK_STATE'] = (int)($device['TRACK'] ?? 0) === 1 ? 'online' : 'idle';
        $out['REGISTERED'] = (int)($device['REGISTERED'] ?? 0);
        $out['REGISTERED_LABEL'] = (int)($device['REGISTERED'] ?? 0) === 1 ? 'Зарегистрировано' : 'Не зарегистрировано';
        $out['LINKED_OBJECT'] = keenetic_h($deviceForm['LINKED_OBJECT'] ?? ($device['LINKED_OBJECT'] ?? ''));
        $out['LINKED_PROPERTY'] = keenetic_h($deviceForm['LINKED_PROPERTY'] ?? ($device['LINKED_PROPERTY'] ?? ''));
        $out['LINKED_METHOD'] = keenetic_h($deviceForm['LINKED_METHOD'] ?? ($device['LINKED_METHOD'] ?? ''));

        // The linkedobject component reads initial values from globals.
        $GLOBALS['linked_object'] = (string)($deviceForm['LINKED_OBJECT'] ?? ($device['LINKED_OBJECT'] ?? ''));
        $GLOBALS['linked_property'] = (string)($deviceForm['LINKED_PROPERTY'] ?? ($device['LINKED_PROPERTY'] ?? ''));
        $GLOBALS['linked_method'] = (string)($deviceForm['LINKED_METHOD'] ?? ($device['LINKED_METHOD'] ?? ''));
        $out['SCRIPT'] = keenetic_h($submittedScript !== null ? $submittedScript : ($device['SCRIPT'] ?? ''));
        $out['LOG_HTML'] = '';
        if (!empty($device['LOG'])) {
            $out['LOG_HTML'] = nl2br(keenetic_h($device['LOG']));
        }
        $out['LIVE_ERROR'] = $liveError !== '' ? keenetic_h($liveError) : '';
        $out['IS_INTERNET'] = (string)$device['MAC'] === '0.0.0.0.0.0' ? 1 : 0;

        $uptime = (int)($live['UPTIME'] ?? 0);
        $out['UPTIME_HUMAN'] = keenetic_h(keenetic_uptime_human($uptime));
        $out['RXBYTES_HUMAN'] = keenetic_h(keenetic_traffic_human($live['RXBYTES'] ?? 0));
        $out['TXBYTES_HUMAN'] = keenetic_h(keenetic_traffic_human($live['TXBYTES'] ?? 0));
        $out['WAN_NAME'] = keenetic_h($live['WAN_NAME'] ?? '');
        $out['WAN_IP'] = keenetic_h($live['WAN_IP'] ?? '');
        $out['WAN_STATE'] = (int)($live['WAN_STATE'] ?? 0);
        $out['WAN_STATE_LABEL'] = (int)($live['WAN_STATE'] ?? 0) === 1
            ? ($live['WAN'] ? 'Резервный канал ' . ((int)$live['WAN'] + 1) : 'Основной канал')
            : 'Нет подключения';
        $out['WIFI_ROUTER'] = keenetic_h($live['ROUTER'] ?? '');
        $out['WIFI_MODE'] = keenetic_h($live['WIFI_MODE'] ?? '');
        $out['WIFI_NET'] = keenetic_h($live['NET'] ?? '');
        $out['WIFI_FREQ'] = keenetic_h($live['FREQ'] ?? '');
        $out['RSSI'] = (int)($live['RSSI'] ?? 0);
        $signal = keenetic_signal_meta($out['RSSI']);
        $out['SIGNAL_PERCENT'] = $signal['percent'];
        $out['SIGNAL_TONE'] = $signal['tone'];
        $out['SIGNAL_LABEL'] = $signal['label'];

        $out['TAB'] = $tab;
        if ($tab === 'code') {
            $this->prepareCodeEditor($out, 'keenetic_device_' . $id);
            $out['CODE_SCRIPT'] = $out['SCRIPT'];
        }
        $out['ACTIVE_TAB'] = $tab;
    }

    /**
     * Save device name and object binding.
     *
     * @param array $out
     * @param array $device
     * @param array $router
     * @return void
     */
    private function saveDeviceOverview(&$out, $device, $router)
    {
        $title = trim((string)gr('title'));
        $newObject = trim((string)gr('linked_object'));
        $newProperty = trim((string)gr('linked_property'));
        $newMethod = trim((string)gr('linked_method'));
        $out['_DEVICE_FORM'] = array(
            'TITLE' => $title,
            'LINKED_OBJECT' => $newObject,
            'LINKED_PROPERTY' => $newProperty,
            'LINKED_METHOD' => $newMethod,
        );

        if ($title === '') {
            $out['ERR'] = 1;
            $out['ERR_ALERT'] = 'Название устройства не может быть пустым.';
            return;
        }
        if (mb_strlen($title) > 100) {
            $out['ERR'] = 1;
            $out['ERR_ALERT'] = 'Название устройства не должно быть длиннее 100 символов.';
            return;
        }
        if ((string)$device['MAC'] === '0.0.0.0.0.0' && $title !== 'Интернет') {
            $out['ERR'] = 1;
            $out['ERR_ALERT'] = 'Служебную запись «Интернет» нельзя переименовать.';
            return;
        }

        $duplicate = SQLSelectOne(
            'SELECT ID FROM keenetic_devices WHERE ROUTER_ID=' . (int)$device['ROUTER_ID']
            . ' AND TITLE="' . DBSafe($title) . '" AND ID<>' . (int)$device['ID']
        );
        if (!empty($duplicate['ID'])) {
            $out['ERR'] = 1;
            $out['ERR_ALERT'] = 'Устройство с таким именем уже есть на этом роутере.';
            return;
        }

        $oldTitle = (string)$device['TITLE'];
        $oldObject = (string)($device['LINKED_OBJECT'] ?? '');
        $oldProperty = (string)($device['LINKED_PROPERTY'] ?? '');

        if ($newObject !== '' && $newProperty === '' && $newMethod === '') {
            $newObject = '';
        }

        if ($oldObject !== $newObject || $oldProperty !== $newProperty) {
            if ($oldObject !== '' && $oldProperty !== '') {
                removeLinkedProperty($oldObject, $oldProperty, $this->name);
            }
            if ($newObject !== '' && $newProperty !== '') {
                addLinkedProperty($newObject, $newProperty, $this->name);
            }
        }

        $device['TITLE'] = $title;
        $device['LINKED_OBJECT'] = $newObject;
        $device['LINKED_PROPERTY'] = $newProperty;
        $device['LINKED_METHOD'] = $newMethod;
        $device['UPDATED'] = date('Y-m-d H:i:s');
        SQLUpdate('keenetic_devices', $device);

        if ($newObject !== '' && $newProperty !== '') {
            setGlobal($newObject . '.' . $newProperty, (int)$device['STATUS']);
        }

        if ($oldTitle !== $title && (string)$device['MAC'] !== '0.0.0.0.0.0') {
            $response = $this->getdata(
                $router,
                'known/host',
                json_encode(array('mac' => (string)$device['MAC'], 'name' => $title), JSON_UNESCAPED_UNICODE),
                true
            );
            if (!$response) {
                $out['WARN'] = 1;
                $out['WARN_ALERT'] = 'Имя сохранено в MajorDoMo, но роутер не подтвердил переименование.';
            }
        }

        $out['OK'] = 1;
    }

    /**
     * Save the device handler script.
     *
     * @param array $out
     * @param array $device
     * @return void
     */
    private function saveDeviceScript(&$out, $device)
    {
        $oldCode = (string)($device['SCRIPT'] ?? '');
        $code = (string)gr('code');
        $error = $this->getUserCodeError($code);
        if ($error !== '') {
            $out['ERR_CODE'] = 1;
            $out['ERR_LINE'] = preg_replace('/[^0-9]/', '', $error);
            $out['ERRORS'] = keenetic_h($error);
            $out['ERR_OLD_CODE_B64'] = base64_encode($oldCode);
            return;
        }

        $device['SCRIPT'] = $code;
        $device['UPDATED'] = date('Y-m-d H:i:s');
        SQLUpdate('keenetic_devices', $device);
        $out['OK'] = 1;
    }

    // ---------------------------------------------------------------------
    // Actions
    // ---------------------------------------------------------------------

    /**
     * Back URL for GET actions.
     *
     * @return array{router:int,tab:string,from:string}
     */
    private function backUrl()
    {
        return array(
            'router' => (int)gr('router_id'),
            'tab' => preg_replace('/[^a-z]/', '', (string)gr('tab')),
            'from' => (string)gr('from') === 'devices' ? 'devices' : '',
        );
    }

    /**
     * Redirect back to the router tab or the devices list.
     *
     * @param array $back
     * @param string $ok
     * @param string $err
     * @return void
     */
    private function redirectBack($back, $ok = '', $err = '')
    {
        $params = array();
        if ($back['from'] === 'devices') {
            $params[] = 'view_mode=devices';
            if ($back['router'] > 0) {
                $params[] = 'router_id=' . $back['router'];
            }
        } elseif ($back['router'] > 0) {
            $params[] = 'view_mode=edit_keenetic_routers';
            $params[] = 'id=' . $back['router'];
            if ($back['tab'] !== '') {
                $params[] = 'tab=' . $back['tab'];
            }
        } else {
            $params[] = 'view_mode=search_keenetic_routers';
        }
        if ($ok !== '') {
            $params[] = 'ok=' . $ok;
        }
        if ($err !== '') {
            $params[] = 'err=' . $err;
        }
        $this->redirect('?' . implode('&', $params));
    }

    private function updateFirmwareAction($routerId, $back)
    {
        $ok = $this->firmwareUpdate($routerId);
        $this->redirectBack($back, $ok ? 'firmware' : '', $ok ? '' : 'firmware');
    }

    private function rebootRouterAction($routerId, $back)
    {
        $ok = $this->reboot($routerId);
        $this->redirectBack($back, $ok ? 'reboot' : '', $ok ? '' : 'reboot');
    }

    private function saveConfigAction($routerId, $back)
    {
        $ok = $this->saveRouterConfiguration($routerId);
        $this->redirectBack($back, $ok ? 'config' : '', $ok ? '' : 'config');
    }

    private function refreshRouterAction($routerId, $back)
    {
        $router = SQLSelectOne('SELECT * FROM keenetic_routers WHERE ID=' . (int)$routerId);
        $ok = !empty($router['ID']) && $this->pollRouter($router);
        $this->redirectBack($back, $ok ? 'refresh' : '', $ok ? '' : 'refresh');
    }

    private function wolDeviceAction($deviceId, $back)
    {
        $device = SQLSelectOne('SELECT * FROM keenetic_devices WHERE ID=' . (int)$deviceId);
        $ok = !empty($device['MAC']) && $this->wol((string)$device['MAC']);
        $this->redirectBack($back, $ok ? 'wol' : '', $ok ? '' : 'device');
    }

    private function registerDeviceAction($deviceId, $back)
    {
        $device = SQLSelectOne('SELECT * FROM keenetic_devices WHERE ID=' . (int)$deviceId);
        if (empty($device['ID'])) {
            $this->redirectBack($back, '', 'device');
        }
        $router = SQLSelectOne('SELECT * FROM keenetic_routers WHERE ID=' . (int)$device['ROUTER_ID']);
        $response = $this->getdata(
            $router,
            'known/host',
            json_encode(array('mac' => (string)$device['MAC'], 'name' => (string)$device['TITLE']), JSON_UNESCAPED_UNICODE),
            true
        );
        if (!$response) {
            $this->redirectBack($back, '', 'register');
        }
        $device['REGISTERED'] = 1;
        $device['UPDATED'] = date('Y-m-d H:i:s');
        SQLUpdate('keenetic_devices', $device);
        $this->redirectBack($back, 'register');
    }

    private function deleteDeviceAction($deviceId, $back)
    {
        $device = SQLSelectOne('SELECT * FROM keenetic_devices WHERE ID=' . (int)$deviceId);
        if (empty($device['ID'])) {
            $this->redirectBack($back, '', 'device');
        }
        if ((string)$device['TITLE'] === 'Интернет') {
            $this->redirectBack($back, '', 'delete');
        }
        $router = SQLSelectOne('SELECT * FROM keenetic_routers WHERE ID=' . (int)$device['ROUTER_ID']);
        if (!empty($router['ID'])) {
            $this->getdata(
                $router,
                'known/host',
                json_encode(array('mac' => (string)$device['MAC'], 'no' => true), JSON_UNESCAPED_UNICODE),
                true
            );
        }
        SQLExec('DELETE FROM keenetic_devices WHERE ID=' . (int)$device['ID']);
        $this->WriteLog('Устройство ' . $device['TITLE'] . ' (MAC ' . $device['MAC'] . ') удалено.');
        $this->redirectBack($back, 'device_deleted');
    }

    private function toggleTrackAction($deviceId, $back)
    {
        $device = SQLSelectOne('SELECT * FROM keenetic_devices WHERE ID=' . (int)$deviceId);
        if (empty($device['ID'])) {
            $this->redirectBack($back, '', 'device');
        }
        if ((string)$device['MAC'] === '0.0.0.0.0.0') {
            $this->redirectBack($back, '', 'track');
        }
        $router = SQLSelectOne('SELECT * FROM keenetic_routers WHERE ID=' . (int)$device['ROUTER_ID']);
        if (empty($router['ID'])) {
            $this->redirectBack($back, '', 'device');
        }

        if ((int)$device['TRACK'] === 1) {
            $this->deleteObjectByName((string)$device['TITLE']);
            $device['TRACK'] = 0;
            SQLUpdate('keenetic_devices', $device);
            $this->redirectBack($back, 'untracked');
        }

        if (getObject((string)$device['TITLE'])) {
            $this->redirectBack($back, '', 'track_exists');
        }
        if (!$this->classExists((string)$router['TITLE'])) {
            addClass((string)$router['TITLE'], 'Keenetic');
        }
        addClassObject((string)$router['TITLE'], (string)$device['TITLE']);
        $device['TRACK'] = 1;
        SQLUpdate('keenetic_devices', $device);
        $this->redirectBack($back, 'tracked');
    }

    private function deleteRouterAction($routerId)
    {
        $this->deleteKeeneticRouter($routerId);
        $this->redirect('?view_mode=search_keenetic_routers&ok=deleted');
    }

    /**
     * Toggle a connection and return JSON. Requires an authorized admin session:
     * the legacy /ajax route is a public entry point.
     *
     * @return void
     */
    private function toggleConnectionJson()
    {
        header('Content-Type: application/json; charset=utf-8');
        if (!$this->isAdminRequest()) {
            http_response_code(403);
            echo json_encode(array('error' => 'forbidden'));
            exit;
        }
        $id = (int)gr('id');
        $connection = $id ? SQLSelectOne('SELECT * FROM keenetic_connections WHERE ID=' . $id) : array();
        if (empty($connection['ID'])) {
            http_response_code(404);
            echo json_encode(array('error' => 'not_found'));
            exit;
        }
        $state = $this->changeConnection($id, 2);
        if ($state === null) {
            http_response_code(502);
            echo json_encode(array('error' => 'router'));
            exit;
        }
        $connection = SQLSelectOne('SELECT * FROM keenetic_connections WHERE ID=' . $id);
        $meta = keenetic_connection_state($connection);
        echo json_encode(array(
            'state' => (int)$connection['STATE'],
            'status' => (int)$connection['STATUS'],
            'admin_label' => $meta['admin_label'],
            'link_label' => $meta['link_label'],
        ));
        exit;
    }

    /**
     * Whether the current request belongs to an authorized admin session.
     *
     * @return bool
     */
    private function isAdminRequest()
    {
        global $session;
        if (is_object($session) && (!empty($session->data['AUTHORIZED'])
            || !empty($session->data['USER_ID'])
            || !empty($session->data['USER_NAME']))) {
            return true;
        }
        if (!empty($_SESSION['AUTHORIZED']) || !empty($_SESSION['USER_ID'])) {
            return true;
        }
        return defined('NO_DATABASE_CONNECTION');
    }

    /**
     * Delete a router with all related rows and linked properties.
     *
     * @param int $routerId
     * @return void
     */
    private function deleteKeeneticRouter($routerId)
    {
        $router = SQLSelectOne('SELECT * FROM keenetic_routers WHERE ID=' . (int)$routerId);
        if (empty($router['ID'])) {
            return;
        }

        $devices = SQLSelect('SELECT * FROM keenetic_devices WHERE ROUTER_ID=' . (int)$routerId);
        foreach ((array)$devices as $device) {
            if (!empty($device['LINKED_OBJECT']) && !empty($device['LINKED_PROPERTY'])) {
                removeLinkedProperty($device['LINKED_OBJECT'], $device['LINKED_PROPERTY'], $this->name);
            }
        }
        $connections = SQLSelect('SELECT * FROM keenetic_connections WHERE ROUTER_ID=' . (int)$routerId);
        foreach ((array)$connections as $connection) {
            if (!empty($connection['LINKED_OBJECT']) && !empty($connection['LINKED_PROPERTY'])) {
                removeLinkedProperty($connection['LINKED_OBJECT'], $connection['LINKED_PROPERTY'], $this->name);
            }
        }

        ClearTimeOut('KeeneticReboot' . (int)$routerId);
        SQLExec('DELETE FROM keenetic_devices WHERE ROUTER_ID=' . (int)$routerId);
        SQLExec('DELETE FROM keenetic_connections WHERE ROUTER_ID=' . (int)$routerId);
        SQLExec('DELETE FROM keenetic_routers WHERE ID=' . (int)$routerId);
        $this->deleteClassByName((string)$router['TITLE']);
        $this->WriteLog('Роутер ' . $router['TITLE'] . ' удалён.');
    }

    // ---------------------------------------------------------------------
    // Keenetic API helpers
    // ---------------------------------------------------------------------

    /**
     * Send an RCI request to a router.
     *
     * @param array $router
     * @param string $path
     * @param string|array $data
     * @param bool $save save the configuration on the router after the command
     * @return array|false
     */
    public function getdata($router, $path = '', $data = '', $save = false)
    {
        if (empty($router['ADDRESS'])) {
            return false;
        }
        $address = preg_replace('/[\x00-\x20\x7f]+/', '', (string)$router['ADDRESS']);
        if ($address === '') {
            return false;
        }
        $login = (string)($router['LOGIN'] ?? '');
        $password = (string)($router['PASSWORD'] ?? '');
        $cookies = (string)($router['COOKIES'] ?? '');
        $scheme = keenetic_is_ip($address) ? 'http://' : 'https://';
        $path = trim((string)$path, '/');
        $url = $scheme . $address . '/rci/' . $path;

        if (is_array($data)) {
            $data = json_encode($data, JSON_UNESCAPED_UNICODE);
        }
        $data = (string)$data;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_COOKIE, $cookies);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_TIMEOUT, 12);
        curl_setopt($ch, CURLOPT_USERAGENT, 'MajorDoMo-Keenetic/' . self::VERSION);
        if ($data !== '') {
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json;charset=UTF-8'));
        }
        $html = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if (!$html) {
            $this->WriteLog('Нет ответа от ' . $address . ' (' . $path . ').');
            return false;
        }

        if ($httpCode === 401 || $httpCode === 403) {
            $cookies = $this->auth($address, $login, $password);
            if (!$cookies) {
                return false;
            }
            if (!empty($router['ID'])) {
                $stored = SQLSelectOne('SELECT * FROM keenetic_routers WHERE ID=' . (int)$router['ID']);
            } else {
                $stored = SQLSelectOne('SELECT * FROM keenetic_routers WHERE ADDRESS="' . DBSafe($address) . '"');
            }
            if (!empty($stored['ID'])) {
                $stored['COOKIES'] = $cookies;
                $stored['UPDATED'] = date('Y-m-d H:i:s');
                SQLUpdate('keenetic_routers', $stored);
                $router = $stored;
            } else {
                $router['COOKIES'] = $cookies;
            }
            return $this->getdata($router, $path, $data, $save);
        }

        if ($httpCode !== 200) {
            $this->WriteLog('Ошибка запроса к ' . $address . ' (' . $path . '): HTTP ' . $httpCode . '.');
            return false;
        }

        $decoded = json_decode((string)$html, true);
        if (!is_array($decoded)) {
            $this->WriteLog('Некорректный JSON от ' . $address . ' для ' . $path . '.');
            return false;
        }

        if ($save) {
            $response = $this->getdata($router, 'system/configuration/save', '{}');
            if (($response['status'][0]['message'] ?? '') === 'saving configuration...') {
                $this->WriteLog('Конфигурация ' . $address . ' сохранена.');
            }
        }

        return $decoded;
    }

    /**
     * Authenticate against a Keenetic router.
     *
     * @param string $ip
     * @param string $login
     * @param string $password stored (encrypted) password
     * @return string|false cookies string
     */
    public function auth($ip, $login, $password)
    {
        $ip = preg_replace('/[\x00-\x20\x7f]+/', '', (string)$ip);
        if ($ip === '') {
            return false;
        }
        $scheme = keenetic_is_ip($ip) ? 'http://' : 'https://';
        $plainPassword = $this->decryptPassword((string)$password);

        $ch = curl_init($scheme . $ip . '/auth');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_TIMEOUT, 12);
        curl_setopt($ch, CURLOPT_USERAGENT, 'MajorDoMo-Keenetic/' . self::VERSION);
        $response = curl_exec($ch);
        if (!$response) {
            curl_close($ch);
            $this->WriteLog('Auth: нет ответа от ' . $ip . '.');
            return false;
        }

        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $header = substr((string)$response, 0, $headerSize);
        curl_close($ch);

        $cookies = $this->extractCookies($header);
        if ($cookies === '') {
            $this->WriteLog('Auth: роутер ' . $ip . ' не выдал cookie.');
            return false;
        }

        $challenge = $this->extractHeader($header, 'X-NDM-Challenge');
        $realm = $this->extractHeader($header, 'X-NDM-Realm');
        if ($challenge === '' || $realm === '') {
            $this->WriteLog('Auth: роутер ' . $ip . ' не выдал challenge.');
            return false;
        }

        $hash = hash('sha256', $challenge . md5($login . ':' . $realm . ':' . $plainPassword));
        $payload = json_encode(array('login' => $login, 'password' => $hash), JSON_UNESCAPED_UNICODE);

        $ch = curl_init($scheme . $ip . '/auth');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_TIMEOUT, 12);
        curl_setopt($ch, CURLOPT_USERAGENT, 'MajorDoMo-Keenetic/' . self::VERSION);
        curl_setopt($ch, CURLOPT_COOKIE, $cookies);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json;charset=UTF-8'));
        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            $this->WriteLog('Auth: ошибка авторизации на ' . $ip . ' (HTTP ' . $httpCode . ').');
            return false;
        }

        $newCookies = $this->extractCookies(substr((string)$response, 0, $headerSize));
        return $newCookies !== '' ? $newCookies : $cookies;
    }

    /**
     * Extract all Set-Cookie values from response headers.
     *
     * @param string $header
     * @return string
     */
    private function extractCookies($header)
    {
        preg_match_all('/^Set-Cookie:\s*([^;\r\n]+)/mi', (string)$header, $matches);
        $cookies = array();
        foreach ((array)($matches[1] ?? array()) as $cookie) {
            $cookie = trim($cookie);
            if ($cookie !== '') {
                $cookies[] = $cookie;
            }
        }
        return implode('; ', array_unique($cookies));
    }

    /**
     * Case-insensitive response header lookup.
     *
     * @param string $header
     * @param string $name
     * @return string
     */
    private function extractHeader($header, $name)
    {
        foreach (preg_split('/\r\n|\n|\r/', (string)$header) as $line) {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2 && strcasecmp(trim($parts[0]), $name) === 0) {
                return trim($parts[1]);
            }
        }
        return '';
    }

    /**
     * Send an arbitrary RCI command.
     *
     * @param int|string $id
     * @param string $data
     * @param bool $save
     * @return array|false
     */
    public function command($id, $data, $save = false)
    {
        $router = $this->findRouter($id);
        if (!$router) {
            return false;
        }
        return $this->getdata($router, '', $data, $save);
    }

    /**
     * Enable/disable a connection.
     *
     * @param int|string $id connection ID or title
     * @param int $state 0 - down, 1 - up, 2 - toggle
     * @return bool|null new state or null on failure
     */
    public function changeConnection($id, $state)
    {
        if (is_numeric($id)) {
            $connection = SQLSelectOne('SELECT * FROM keenetic_connections WHERE ID=' . (int)$id);
        } else {
            $connection = SQLSelectOne('SELECT * FROM keenetic_connections WHERE TITLE="' . DBSafe($id) . '"');
        }
        if (empty($connection['ID'])) {
            return null;
        }
        $router = SQLSelectOne('SELECT * FROM keenetic_routers WHERE ID=' . (int)$connection['ROUTER_ID']);
        if (empty($router['ID'])) {
            return null;
        }

        $state = (int)$state;
        $disable = true;
        if ($state === 1 || ($state === 2 && (int)$connection['STATE'] === 0)) {
            $disable = false;
        }

        $payload = array(
            'interface' => array(
                $connection['CONN_ID'] => array(
                    'up' => array('no' => $disable),
                ),
            ),
        );
        $response = $this->getdata($router, '', json_encode($payload, JSON_UNESCAPED_UNICODE), true);
        if (!$response) {
            return null;
        }

        $message = (string)($response['interface'][$connection['CONN_ID']]['up']['status'][0]['message'] ?? '');
        $status = !$disable;
        if (stripos($message, 'interface is up') !== false) {
            $status = true;
        } elseif (stripos($message, 'interface is down') !== false) {
            $status = false;
        }

        $connection['STATE'] = $status ? 1 : 0;
        if (!$status) {
            // The router will report the link state on the next poll; until
            // then a disabled connection cannot be up.
            $connection['STATUS'] = 0;
        }
        $connection['UPDATED'] = date('Y-m-d H:i:s');
        SQLUpdate('keenetic_connections', $connection);
        return $status;
    }

    /**
     * Reboot a router.
     *
     * @param int|string $id
     * @return bool
     */
    public function reboot($id)
    {
        $router = $this->findRouter($id);
        if (!$router) {
            return false;
        }
        $response = $this->getdata($router, 'system/reboot', '{}');
        if (!$response) {
            return false;
        }
        $this->WriteLog('Роутер ' . $router['TITLE'] . ' перезагружается.');
        return true;
    }

    /**
     * Install a downloaded firmware update.
     *
     * @param int|string $id
     * @return bool
     */
    public function firmwareUpdate($id)
    {
        $router = $this->findRouter($id);
        if (!$router) {
            return false;
        }
        $response = $this->getdata($router, 'components/commit', '{}');
        if (!$response) {
            return false;
        }
        $this->WriteLog('Установка прошивки на ' . $router['TITLE'] . '.');
        return true;
    }

    /**
     * Save the running configuration to the router's memory.
     *
     * @param int|string $id
     * @return bool
     */
    public function saveRouterConfiguration($id)
    {
        $router = $this->findRouter($id);
        if (!$router) {
            return false;
        }
        return (bool)$this->getdata($router, 'system/configuration/save', '{}');
    }

    /**
     * Send a Wake-on-LAN packet through the router.
     *
     * @param string $macOrTitle
     * @return bool
     */
    public function wol($macOrTitle)
    {
        $value = (string)$macOrTitle;
        if (count(explode(':', $value)) !== 6) {
            $devices = SQLSelect('SELECT * FROM keenetic_devices WHERE TITLE="' . DBSafe($value) . '"');
        } else {
            $devices = SQLSelect('SELECT * FROM keenetic_devices WHERE MAC="' . DBSafe($value) . '"');
        }
        if (!is_array($devices) || !$devices) {
            return false;
        }
        $sent = false;
        foreach ($devices as $device) {
            $router = SQLSelectOne('SELECT * FROM keenetic_routers WHERE ID=' . (int)$device['ROUTER_ID']);
            if (empty($router['ID'])) {
                continue;
            }
            $response = $this->getdata(
                $router,
                'ip/hotspot/wake',
                json_encode(array('mac' => (string)$device['MAC']), JSON_UNESCAPED_UNICODE)
            );
            if ($response) {
                $sent = true;
            }
        }
        return $sent;
    }

    /**
     * Find a router by ID or address.
     *
     * @param int|string $id
     * @return array|false
     */
    private function findRouter($id)
    {
        if (is_numeric($id)) {
            $router = SQLSelectOne('SELECT * FROM keenetic_routers WHERE ID=' . (int)$id);
        } else {
            $router = SQLSelectOne('SELECT * FROM keenetic_routers WHERE ADDRESS="' . DBSafe($id) . '"');
        }
        return empty($router['ID']) ? false : $router;
    }

    // ---------------------------------------------------------------------
    // Polling
    // ---------------------------------------------------------------------

    /**
     * One polling pass over all routers.
     *
     * @return void
     */
    public function processCycle()
    {
        $this->ensureSchema();
        $routers = SQLSelect('SELECT * FROM keenetic_routers');
        if (!is_array($routers)) {
            return;
        }
        foreach ($routers as $router) {
            $period = (int)($router['REQ_PERIOD'] ?? 0);
            if ($period <= 0) {
                continue;
            }
            if (time() - (int)($router['REQ_UPDATE'] ?? 0) < $period) {
                continue;
            }
            try {
                $this->pollRouter($router);
            } catch (Throwable $e) {
                $this->WriteLog('Ошибка опроса ' . $router['TITLE'] . ': ' . $e->getMessage());
                registerError('Keenetic', 'Poll error for ' . $router['TITLE'] . ': ' . $e->getMessage());
            }
            usleep(100000);
        }
    }

    /**
     * Poll one router and update routers, devices and connections.
     *
     * @param array $router
     * @return bool
     */
    public function pollRouter($router)
    {
        if (empty($router['ID']) || empty($router['ADDRESS'])) {
            return false;
        }

        $routerId = (int)$router['ID'];
        $updateRouter = false;

        $payload = array(
            'show' => array(
                'system' => (object)array(),
                'version' => (object)array(),
                'identification' => (object)array(),
                'ip' => array('hotspot' => (object)array()),
                'internet' => array('status' => (object)array()),
                'interface' => (object)array(),
            ),
        );
        if (!empty($router['MWS'])) {
            $payload['show']['mws'] = array('member' => (object)array());
        }

        $data = $this->getdata($router, '', json_encode($payload, JSON_UNESCAPED_UNICODE));
        if (!$data) {
            if ((int)($router['STATUS'] ?? 0) === 1) {
                $router['STATUS'] = 0;
                $router['INET_STATUS'] = 0;
                $updateRouter = true;
                $this->WriteLog('Роутер ' . $router['TITLE'] . ' недоступен.');
            }
            if ($updateRouter) {
                $router['UPDATED'] = date('Y-m-d H:i:s');
            }
            unset($router['COOKIES']);
            $router['REQ_UPDATE'] = time();
            SQLUpdate('keenetic_routers', $router);
            return false;
        }

        if ((int)($router['STATUS'] ?? 0) !== 1) {
            $router['STATUS'] = 1;
            $updateRouter = true;
            $this->WriteLog('Роутер ' . $router['TITLE'] . ' доступен.');
        }

        $components = array_map('trim', explode(',', (string)($data['show']['version']['ndw']['components'] ?? '')));
        $mws = in_array('mws', $components, true) ? 1 : 0;
        if ((int)($router['MWS'] ?? 0) !== $mws) {
            $router['MWS'] = $mws;
            $updateRouter = true;
        }

        $release = trim((string)($data['show']['version']['release'] ?? ''));
        if ($release !== '' && (string)($router['FIRMWARE'] ?? '') !== $release) {
            $router['FIRMWARE'] = $release;
            $router['NEW_FIRMWARE'] = $release;
            $router['HREF_FW'] = '';
            $updateRouter = true;
            $this->WriteLog('Прошивка на ' . $router['TITLE'] . ' обновлена до ' . $release . '.');
            if ($this->notifyEnabled()) {
                $this->sendNotification('Прошивка на ' . $router['TITLE'] . ' обновлена до версии ' . $release . '.', 'info');
            }
        }

        $this->syncInternet($router, $data, $updateRouter);
        $this->syncConnections($router, $data);
        $this->syncDevices($router, $data);

        if ($updateRouter) {
            $router['UPDATED'] = date('Y-m-d H:i:s');
        }
        unset($router['COOKIES']);
        $router['REQ_UPDATE'] = time();
        SQLUpdate('keenetic_routers', $router);

        return true;
    }

    /**
     * Internet status and active WAN channel.
     *
     * @param array $router modified in place
     * @param array $data
     * @param bool $updateRouter modified in place
     * @return void
     */
    private function syncInternet(&$router, $data, &$updateRouter)
    {
        $routerId = (int)$router['ID'];
        $uptime = (int)($data['show']['system']['uptime'] ?? 0);
        if ($uptime <= 180) {
            return;
        }

        $inet = SQLSelectOne(
            'SELECT * FROM keenetic_devices WHERE MAC="0.0.0.0.0.0" AND ROUTER_ID=' . $routerId
        );
        if (empty($inet['ID'])) {
            $this->createInternetDevice($router);
            $inet = SQLSelectOne(
                'SELECT * FROM keenetic_devices WHERE MAC="0.0.0.0.0.0" AND ROUTER_ID=' . $routerId
            );
        }
        if (empty($inet['ID'])) {
            return;
        }

        $internetStatus = !empty($data['show']['internet']['status']['internet']) ? 1 : 0;
        $log = '';
        $changed = false;

        if ((int)($router['INET_STATUS'] ?? 0) !== $internetStatus) {
            if ($internetStatus) {
                $log = 'восстановлено.';
                ClearTimeOut('KeeneticReboot' . $routerId);
                ClearTimeOut('KeeneticReboot');
            } else {
                $seconds = (int)($router['AUTO_REBOOT'] ?? 0);
                if ($seconds > 0) {
                    $log = 'потеряно. Таймер перезагрузки на ' . $seconds . ' секунд активирован.';
                    setTimeOut(
                        'KeeneticReboot' . $routerId,
                        'include_once(DIR_MODULES . "keenetic/keenetic.class.php");'
                        . '$keenetic_module = new keenetic();'
                        . '$keenetic_module->reboot(' . $routerId . ');',
                        $seconds
                    );
                } else {
                    $log = 'потеряно.';
                }
            }
            $router['INET_STATUS'] = $internetStatus;
            $inet['STATUS'] = $internetStatus;
            $updateRouter = true;
            $changed = true;
        }

        if ((int)($router['INET_STATUS'] ?? 0) === 1) {
            $state = $this->backupWAN($data['show']['interface'] ?? array());
            if (!empty($state['STATE'])) {
                if ((string)($inet['IP'] ?? '') !== (string)($state['IP'] ?? '')) {
                    $inet['IP'] = (string)($state['IP'] ?? '');
                    $log .= ($log !== '' ? ' ' : '') . 'IP-адрес: ' . $inet['IP'] . '.';
                    $changed = true;
                }
                $wanStatus = (int)$state['WAN'] + 1;
                if ((int)($inet['STATUS'] ?? 0) !== $wanStatus) {
                    if ((int)$state['WAN'] > 0) {
                        $log .= ($log !== '' ? ' ' : '') . 'Включён резервный канал ' . $wanStatus . '.';
                    } else {
                        $log .= ($log !== '' ? ' ' : '') . 'Включён основной канал.';
                    }
                    $inet['STATUS'] = $wanStatus;
                    $changed = true;
                }
            }
        }

        if (!$changed) {
            return;
        }

        $inet['LOG'] = date('Y-m-d H:i:s') . ' Соединение с интернетом ' . $log . "\n" . ($inet['LOG'] ?? '');
        $inet['LOG'] = $this->trimLog($inet['LOG']);
        $inet['UPDATED'] = date('Y-m-d H:i:s');
        SQLUpdate('keenetic_devices', $inet);
        $this->setProperty($inet, (int)$inet['STATUS']);
        $this->WriteLog('Соединение с интернетом ' . $log);
        $this->executeUserCode(
            (string)($inet['SCRIPT'] ?? ''),
            array('status' => (int)$inet['STATUS'], 'router' => $router, 'inet' => $inet),
            'интернет-соединения'
        );
    }

    /**
     * Sync router interfaces with the `keenetic_connections` table.
     *
     * @param array $router
     * @param array $data
     * @return void
     */
    private function syncConnections($router, $data)
    {
        $routerId = (int)$router['ID'];
        $rows = SQLSelect('SELECT * FROM keenetic_connections WHERE ROUTER_ID=' . $routerId);
        $interfaces = array();
        $skipTypes = array('GigabitEthernet', 'FastEthernet', 'Port', 'Vlan', 'AccessPoint', 'WifiMaster', 'WifiStation', 'Bridge');

        foreach ($this->normalizeRows($data['show']['interface'] ?? array()) as $interface) {
            $id = (string)($interface['id'] ?? '');
            if ($id === '' || in_array((string)($interface['type'] ?? ''), $skipTypes, true)) {
                continue;
            }
            $interfaces[$id] = $interface;
        }
        if (!$interfaces) {
            // Do not delete every connection because one poll returned no
            // interfaces (router still booting, RCI error, etc.).
            return;
        }

        foreach ((array)$rows as $connection) {
            $connId = (string)$connection['CONN_ID'];
            if (!isset($interfaces[$connId])) {
                if (!empty($connection['LINKED_OBJECT']) && !empty($connection['LINKED_PROPERTY'])) {
                    removeLinkedProperty($connection['LINKED_OBJECT'], $connection['LINKED_PROPERTY'], $this->name);
                }
                SQLExec('DELETE FROM keenetic_connections WHERE ID=' . (int)$connection['ID']);
                continue;
            }

            $interface = $interfaces[$connId];
            $changed = false;

            $title = (string)($interface['description'] ?? '');
            if ((string)$connection['TITLE'] !== $title) {
                $connection['TITLE'] = $title;
                $changed = true;
            }
            $address = (string)($interface['address'] ?? '');
            if ((string)$connection['ADDRESS'] !== $address) {
                $connection['ADDRESS'] = $address;
                $changed = true;
            }
            $type = (string)($interface['type'] ?? '');
            if ((string)$connection['TYPE'] !== $type) {
                $connection['TYPE'] = $type;
                $changed = true;
            }
            $adminState = ($interface['state'] ?? '') === 'up' ? 1 : 0;
            if ((int)$connection['STATE'] !== $adminState) {
                $connection['STATE'] = $adminState;
                $changed = true;
            }
            $linkState = ($interface['connected'] ?? '') === 'yes' ? 1 : 0;
            if ((int)$connection['STATUS'] !== $linkState) {
                $connection['STATUS'] = $linkState;
                $this->setProperty($connection, $linkState);
                $this->executeUserCode(
                    (string)($router['CNCT_SCRIPT'] ?? ''),
                    array(
                        'name' => $connection['TITLE'],
                        'state' => $linkState,
                        'connection' => $connection,
                        'router' => $router,
                    ),
                    'подключения ' . $connection['TITLE']
                );
                $changed = true;
            }

            if ($changed) {
                $connection['UPDATED'] = date('Y-m-d H:i:s');
                SQLUpdate('keenetic_connections', $connection);
            }
            unset($interfaces[$connId]);
        }

        foreach ($interfaces as $interface) {
            $record = array(
                'ROUTER_ID' => $routerId,
                'CONN_ID' => (string)$interface['id'],
                'TITLE' => (string)($interface['description'] ?? ''),
                'STATUS' => ($interface['connected'] ?? '') === 'yes' ? 1 : 0,
                'STATE' => ($interface['state'] ?? '') === 'up' ? 1 : 0,
                'ADDRESS' => (string)($interface['address'] ?? ''),
                'TYPE' => (string)($interface['type'] ?? ''),
                'UPDATED' => date('Y-m-d H:i:s'),
            );
            SQLInsert('keenetic_connections', $record);
        }
    }

    /**
     * Sync hotspot hosts with the `keenetic_devices` table.
     *
     * @param array $router
     * @param array $data
     * @return void
     */
    private function syncDevices($router, $data)
    {
        $routerId = (int)$router['ID'];
        $hosts = $this->normalizeRows($data['show']['ip']['hotspot']['host'] ?? array());
        if (!$hosts) {
            // An empty list is indistinguishable from a failed request; keep the
            // current device list instead of deleting everything.
            $this->WriteLog('Роутер ' . $router['TITLE'] . ' вернул пустой список устройств — синхронизация пропущена.');
            return;
        }

        $hostMap = array();
        foreach ($hosts as $host) {
            $mac = (string)($host['mac'] ?? '');
            if ($mac === '') {
                continue;
            }
            if (($host['name'] ?? '') === '') {
                $host['name'] = $host['hostname'] ?? $mac;
            }
            if (!isset($host['link'])) {
                $host['link'] = 0;
            } elseif ($host['link'] === 'up') {
                $host['link'] = 1;
            } elseif ($host['link'] === 'down') {
                $host['link'] = 0;
            }
            if (($host['ip'] ?? '') === '0.0.0.0') {
                $host['link'] = 0;
            }
            $hostMap[$mac] = $host;
        }
        if (!$hostMap) {
            return;
        }

        $devices = SQLSelect('SELECT * FROM keenetic_devices WHERE ROUTER_ID=' . $routerId);
        $interfaces = !empty($router['MWS']) ? ($data['show']['mws']['member'] ?? array()) : array();
        $wifies = $data['show']['interface'] ?? array();

        foreach ((array)$devices as $device) {
            $mac = (string)$device['MAC'];
            if (!isset($hostMap[$mac])) {
                if ((string)$device['TITLE'] === 'Интернет') {
                    continue;
                }
                if (!empty($device['LINKED_OBJECT'])) {
                    continue;
                }
                SQLExec('DELETE FROM keenetic_devices WHERE ID=' . (int)$device['ID']);
                $this->WriteLog('Устройство ' . $device['TITLE'] . ', MAC ' . $mac . ', удалено с ' . $router['TITLE'] . '.');
                continue;
            }

            $host = $hostMap[$mac];
            $changed = false;
            $log = '';

            if ((int)$device['TRACK'] === 1) {
                $info = $this->parse_data($router, $host, $interfaces, $wifies);
                if (getObject((string)$device['TITLE'])) {
                    callMethodSafe($device['TITLE'] . '.track', $info);
                }
            }

            $newIp = (string)($host['ip'] ?? '');
            if ((string)$device['IP'] !== $newIp && $newIp !== '0.0.0.0') {
                $device['IP'] = $newIp;
                $log = ': IP изменён на ' . $newIp . '.';
                $changed = true;
            }

            $newTitle = (string)($host['name'] ?? '');
            if ($newTitle !== '' && (string)$device['TITLE'] !== $newTitle && (string)$device['TITLE'] !== 'Интернет') {
                $device['TITLE'] = $newTitle;
                $log .= ($log !== '' ? ' ' : '') . 'Имя изменено на ' . $newTitle . '.';
                if ((int)$device['TRACK'] === 1) {
                    $log .= ' Объект отслеживания сохранил прежнее имя.';
                }
                $changed = true;
            }

            $registered = (int)($host['registered'] ?? 0);
            if ((int)$device['REGISTERED'] !== $registered) {
                $device['REGISTERED'] = $registered;
                $log .= ($log !== '' ? ' ' : '') . ($registered ? 'Зарегистрировано на роутере.' : 'Регистрация с роутера удалена.');
                $changed = true;
            }

            $link = (int)($host['link'] ?? 0);
            if ((int)$device['STATUS'] !== $link) {
                if ($link === 1) {
                    $device['TYPE_CONNECT'] = (isset($host['ap']) || isset($host['mws'])) ? 1 : 0;
                } else {
                    // Wired devices occasionally disappear from the aggregate list for
                    // one poll; verify the single host before marking it offline.
                    if ((int)$device['TYPE_CONNECT'] === 0) {
                        $check = $this->getdata(
                            $router,
                            '',
                            json_encode(array('show' => array('ip' => array('hotspot' => array('mac' => $mac))))), JSON_UNESCAPED_UNICODE
                        );
                        if ($check) {
                            $rows = $this->normalizeRows($check['show']['ip']['hotspot']['host'] ?? array());
                            $single = $rows[0] ?? array();
                            if (($single['link'] ?? '') === 'up') {
                                unset($hostMap[$mac]);
                                continue;
                            }
                        }
                    }
                }
                $device['STATUS'] = $link;
                $this->setProperty($device, $link, $host);
                $this->executeUserCode(
                    (string)($device['SCRIPT'] ?? ''),
                    array('status' => $link, 'device' => $host, 'router' => $router),
                    'устройства ' . $device['TITLE']
                );
                $log .= ($log !== '' ? ' ' : '') . ($link ? 'В сети.' : 'Не в сети.');
                $changed = true;
            }

            if ($changed) {
                $device['LOG'] = date('Y-m-d H:i:s') . ' Устройство ' . $device['TITLE'] . ' ' . $log . "\n" . ($device['LOG'] ?? '');
                $device['LOG'] = $this->trimLog($device['LOG']);
                $device['UPDATED'] = date('Y-m-d H:i:s');
                SQLUpdate('keenetic_devices', $device);
            }
            unset($hostMap[$mac]);
        }

        foreach ($hostMap as $host) {
            $new = array(
                'TITLE' => (string)($host['name'] ?? $host['mac']),
                'MAC' => (string)$host['mac'],
                'IP' => (string)($host['ip'] ?? ''),
                'STATUS' => (int)($host['link'] ?? 0),
                'REGISTERED' => (int)($host['registered'] ?? 0),
                'TYPE_CONNECT' => (isset($host['ap']) || isset($host['mws'])) ? 1 : 0,
                'ROUTER_ID' => $routerId,
                'SCRIPT' => "if(\$status){ // устройство появилось в сети\n\n}\nelse{ // устройство отключилось от сети\n\n}",
                'UPDATED' => date('Y-m-d H:i:s'),
            );
            SQLInsert('keenetic_devices', $new);
            $this->WriteLog('Устройство ' . $new['TITLE'] . ', MAC ' . $new['MAC'] . ', добавлено на ' . $router['TITLE'] . '.');
        }
    }

    /**
     * Keep the last 30 lines of a device/internet log.
     *
     * @param string $log
     * @return string
     */
    private function trimLog($log)
    {
        $log = (string)$log;
        $lines = explode("\n", $log);
        if (count($lines) > 30) {
            $lines = array_slice($lines, 0, 30);
        }
        return implode("\n", $lines);
    }

    /**
     * Hourly firmware check.
     *
     * @param string $event
     * @param mixed $details
     * @return void
     */
    public function processSubscription($event, $details = '')
    {
        $this->getConfig();
        if ($event !== 'HOURLY' || !$this->firmwareCheckEnabled()) {
            return;
        }

        $routers = SQLSelect('SELECT * FROM keenetic_routers');
        foreach ((array)$routers as $router) {
            if (!empty($router['NO_UPDATE'])) {
                continue;
            }
            if (!(int)$router['STATUS'] || !(int)$router['INET_STATUS']) {
                continue;
            }

            $firmware = $this->getdata($router, 'components/list', '{}');
            $version = trim((string)($firmware['firmware']['version'] ?? ''));
            if ($version === '') {
                if (!timeOutExists('KeeneticWaitUpdate')) {
                    setTimeOut(
                        'KeeneticWaitUpdate',
                        'include_once(DIR_MODULES . "keenetic/keenetic.class.php");'
                        . '$keenetic_module = new keenetic();'
                        . '$keenetic_module->processSubscription("HOURLY");',
                        10
                    );
                }
                continue;
            }
            if ($version === (string)($router['NEW_FIRMWARE'] ?? '')) {
                continue;
            }

            $router['NEW_FIRMWARE'] = $version;
            $link = $this->getdata(
                $router,
                'webhelp/release-notes',
                json_encode(array('version' => $version, 'locale' => 'ru'), JSON_UNESCAPED_UNICODE)
            );
            $href = (string)($link['webhelp']['ru'][0]['href'] ?? '');
            if ($href === '') {
                $link = $this->getdata($router, 'webhelp/release-notes');
                $href = (string)($link['webhelp']['ru'][0]['href'] ?? '');
            }
            $router['HREF_FW'] = keenetic_safe_url($href);
            SQLUpdate('keenetic_routers', $router);
            $this->WriteLog('Новая версия прошивки: ' . $version);

            if ($this->notifyEnabled()) {
                $this->sendNotification(
                    'Новая версия прошивки для ' . $router['TITLE'] . ': ' . $version,
                    'danger'
                );
            }
        }
    }

    // ---------------------------------------------------------------------
    // Object helpers
    // ---------------------------------------------------------------------

    /**
     * Handle a change of a bound object property.
     *
     * Kept for MajorDoMo's linked-property contract: writing 0/1 to the bound
     * connection property enables or disables the connection.
     *
     * @param string $object
     * @param string $property
     * @param mixed $value
     * @return void
     */
    public function propertySetHandle($object, $property, $value)
    {
        $this->getConfig();
        $rows = SQLSelect(
            'SELECT ID FROM keenetic_connections'
            . ' WHERE LINKED_OBJECT="' . DBSafe($object) . '"'
            . ' AND LINKED_PROPERTY="' . DBSafe($property) . '"'
        );
        foreach ((array)$rows as $row) {
            $this->changeConnection((int)$row['ID'], (int)$value);
        }
    }

    /**
     * Write a device/connection state to the bound property and method.
     *
     * @param array $device
     * @param int $value
     * @param array $params
     * @return void
     */
    public function setProperty($device, $value, $params = array())
    {
        if (!empty($device['LINKED_OBJECT']) && !empty($device['LINKED_PROPERTY'])) {
            setGlobal(
                $device['LINKED_OBJECT'] . '.' . $device['LINKED_PROPERTY'],
                $value,
                array($this->name => 1),
                $this->name
            );
        }
        if (!empty($device['LINKED_OBJECT']) && !empty($device['LINKED_METHOD'])) {
            $params['VALUE'] = $value;
            callMethodSafe($device['LINKED_OBJECT'] . '.' . $device['LINKED_METHOD'], $params);
        }
    }

    /**
     * Run a user-provided handler with error reporting.
     *
     * @param string $code
     * @param array $vars
     * @param string $context
     * @return bool
     */
    public function executeUserCode($code, $vars = array(), $context = '')
    {
        if (trim((string)$code) === '') {
            return true;
        }
        $error = $this->getUserCodeError($code);
        if ($error !== '') {
            $this->WriteLog('Ошибка в коде (' . $context . '): ' . $error);
            registerError(
                'Keenetic',
                'Syntax error in code' . ($context ? ' (' . $context . ')' : '') . ': ' . $error
            );
            return false;
        }
        extract($vars, EXTR_SKIP);
        try {
            eval($code);
            return true;
        } catch (Throwable $e) {
            $this->WriteLog('Ошибка выполнения кода (' . $context . '): ' . $e->getMessage());
            registerError(
                'Keenetic',
                'Runtime error in code' . ($context ? ' (' . $context . ')' : '') . ': ' . $e->getMessage()
            );
            return false;
        }
    }

    /**
     * Check PHP syntax of a user handler.
     *
     * @param string $code
     * @return string error message or an empty string
     */
    public function getUserCodeError($code)
    {
        $code = (string)$code;
        if (trim($code) === '' || !function_exists('php_syntax_error')) {
            return '';
        }
        $errors = php_syntax_error($code);
        if (!$errors) {
            return '';
        }
        $line = preg_replace('/[^0-9]/', '', substr(stristr((string)$errors, 'php on line ') ?: '', 0, 18));
        $parts = explode('Parse error: ', htmlspecialchars(strip_tags(nl2br((string)$errors))));
        if (!isset($parts[1])) {
            return trim(strip_tags((string)$errors));
        }
        $parts = explode('Errors parsing', $parts[1]);
        $parts = explode(' in ', $parts[0]);
        return trim($parts[0]) . ($line !== '' ? ' on line ' . $line : '');
    }

    /**
     * Global admin search integration.
     *
     * @param string $data
     * @return array
     */
    public function findData($data)
    {
        $search = trim((string)$data);
        if ($search === '') {
            return array();
        }
        $root = defined('ROOTHTML') ? ROOTHTML : '/';
        $base = $root . 'panel/keenetic.html';
        $result = array();

        $routers = SQLSelect(
            'SELECT ID, TITLE, MODEL, ADDRESS FROM keenetic_routers'
            . ' WHERE TITLE LIKE "%' . DBSafe($search) . '%"'
            . ' OR MODEL LIKE "%' . DBSafe($search) . '%"'
            . ' OR ADDRESS LIKE "%' . DBSafe($search) . '%"'
            . ' ORDER BY TITLE'
        );
        foreach ((array)$routers as $router) {
            $result[] = '<span class="md-badge md-badge--info">роутер</span> '
                . '<a href="' . keenetic_h($base . '?view_mode=edit_keenetic_routers&id=' . (int)$router['ID']) . '">'
                . keenetic_h($router['TITLE']) . '</a>'
                . ($router['MODEL'] !== '' ? ' <small class="text-muted">' . keenetic_h($router['MODEL']) . '</small>' : '');
        }

        $devices = SQLSelect(
            'SELECT d.ID, d.TITLE, d.IP, d.MAC, r.TITLE AS ROUTER_TITLE FROM keenetic_devices d'
            . ' LEFT JOIN keenetic_routers r ON r.ID=d.ROUTER_ID'
            . ' WHERE d.TITLE LIKE "%' . DBSafe($search) . '%"'
            . ' OR d.MAC LIKE "%' . DBSafe($search) . '%"'
            . ' OR d.IP LIKE "%' . DBSafe($search) . '%"'
            . ' ORDER BY d.TITLE'
        );
        foreach ((array)$devices as $device) {
            $result[] = '<span class="md-badge md-badge--outline">' . keenetic_h((string)$device['ROUTER_TITLE']) . '</span> '
                . '<span class="md-badge md-badge--primary">устройство</span> '
                . '<a href="' . keenetic_h($base . '?view_mode=info_keenetic_devices&id=' . (int)$device['ID']) . '">'
                . keenetic_h($device['TITLE']) . '</a>'
                . ($device['IP'] !== '' ? ' <small class="text-muted">' . keenetic_h($device['IP']) . '</small>' : '');
        }

        $connections = SQLSelect(
            'SELECT c.ID, c.TITLE, c.ADDRESS, c.TYPE, c.ROUTER_ID, r.TITLE AS ROUTER_TITLE FROM keenetic_connections c'
            . ' LEFT JOIN keenetic_routers r ON r.ID=c.ROUTER_ID'
            . ' WHERE c.TITLE LIKE "%' . DBSafe($search) . '%"'
            . ' OR c.ADDRESS LIKE "%' . DBSafe($search) . '%"'
            . ' OR c.TYPE LIKE "%' . DBSafe($search) . '%"'
            . ' ORDER BY c.TITLE'
        );
        foreach ((array)$connections as $connection) {
            $result[] = '<span class="md-badge md-badge--outline">' . keenetic_h((string)$connection['ROUTER_TITLE']) . '</span> '
                . '<span class="md-badge md-badge--info">подключение</span> '
                . '<a href="' . keenetic_h($base . '?view_mode=edit_keenetic_routers&id=' . (int)$connection['ROUTER_ID'] . '&tab=cnct') . '">'
                . keenetic_h($connection['TITLE']) . '</a>'
                . ($connection['ADDRESS'] !== '' ? ' <small class="text-muted">' . keenetic_h($connection['ADDRESS']) . '</small>' : '');
        }

        return $result;
    }

    // ---------------------------------------------------------------------
    // Utilities
    // ---------------------------------------------------------------------

    /**
     * Normalise the router address: drop the scheme, path and trailing slash.
     *
     * @param string $address
     * @return string
     */
    private function normalizeAddress($address)
    {
        $address = trim((string)$address);
        $address = preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', $address);
        $slash = strpos($address, '/');
        if ($slash !== false) {
            $address = substr($address, 0, $slash);
        }
        return rtrim($address, '/');
    }

    /**
     * Validate an address (IPv4 or DNS name, optional port).
     *
     * @param string $address
     * @return bool
     */
    private function isValidAddress($address)
    {
        if (keenetic_is_ip($address)) {
            return true;
        }
        return (bool)preg_match(
            '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?::\d{1,5})?$/i',
            trim((string)$address)
        );
    }

    /**
     * Validate a DNS zone name.
     *
     * @param string $name
     * @return bool
     */
    private function isValidDnsName($name)
    {
        return (bool)preg_match(
            '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/i',
            trim((string)$name)
        );
    }

    /**
     * Convert a scalar RCI row into a list.
     *
     * @param mixed $rows
     * @return array
     */
    public function normalizeRows($rows)
    {
        if (!is_array($rows) || !$rows) {
            return array();
        }
        if (isset($rows['id']) || isset($rows['mac']) || isset($rows['name']) || isset($rows['description'])) {
            return array($rows);
        }
        return $rows;
    }

    /**
     * Human-readable uptime of a router.
     *
     * @param int $seconds
     * @return string
     */
    public function seconds2times($seconds)
    {
        $seconds = max(0, (int)$seconds);
        $times = array();
        $countZero = false;
        $periods = array(60, 3600, 86400, 31536000);
        for ($i = 3; $i >= 0; $i--) {
            $period = (int)floor($seconds / $periods[$i]);
            if ($period > 0 || ($period === 0 && $countZero)) {
                $times[$i + 1] = $period;
                $seconds -= $period * $periods[$i];
                $countZero = true;
            }
        }
        $times[0] = $seconds;
        return $times;
    }

    /**
     * Parse hotspot host data into the `track` method payload.
     *
     * @param array $router
     * @param array $host
     * @param array $interfaces
     * @param array $wifies
     * @return array
     */
    public function parse_data($router, $host, $interfaces, $wifies)
    {
        $rec = array();
        $rec['UPTIME'] = (int)($host['uptime'] ?? 0);
        $rec['HOSTNAME'] = (string)($host['hostname'] ?? '');
        $rec['RXBYTES'] = (int)($host['rxbytes'] ?? 0);
        $rec['TXBYTES'] = (int)($host['txbytes'] ?? 0);
        $cidWifi = '';

        if (isset($host['mws'])) {
            $interfaceMap = array();
            foreach ($this->normalizeRows($interfaces) as $value) {
                if (isset($value['cid'])) {
                    $interfaceMap[$value['cid']] = (string)($value['known-host'] ?? '');
                }
            }
            $cidWifi = (string)($host['mws']['ap'] ?? '');
            $rec['ROUTER'] = $interfaceMap[$host['mws']['cid'] ?? ''] ?? '';
            $rec['WIFI_MODE'] = (string)($host['mws']['mode'] ?? '');
            foreach ((array)($host['mws']['_11'] ?? array()) as $mode) {
                $rec['WIFI_MODE'] .= '/' . $mode;
            }
            $rec['WIFI_MODE'] .= ' ' . ((int)($host['mws']['txss'] ?? 0) === 1 ? '1x1' : '2x2')
                . ' ' . ($host['mws']['ht'] ?? '') . 'МГц ' . ($host['mws']['txrate'] ?? '') . 'Мбит';
            $rec['RSSI'] = (int)($host['mws']['rssi'] ?? 0);
        } elseif (isset($host['ap'])) {
            $cidWifi = (string)$host['ap'];
            $rec['ROUTER'] = (string)($router['MODEL'] ?? '');
            $rec['WIFI_MODE'] = (string)($host['mode'] ?? '');
            foreach ((array)($host['_11'] ?? array()) as $mode) {
                $rec['WIFI_MODE'] .= '/' . $mode;
            }
            $rec['WIFI_MODE'] .= ' ' . ((int)($host['txss'] ?? 0) === 1 ? '1x1' : '2x2')
                . ' ' . ($host['ht'] ?? '') . 'МГц ' . ($host['txrate'] ?? '') . 'Мбит';
            $rec['RSSI'] = (int)($host['rssi'] ?? 0);
        }

        foreach ($this->normalizeRows($wifies) as $iface) {
            if ($cidWifi !== '' && $cidWifi === (string)($iface['id'] ?? '')) {
                $parts = explode('/', (string)$iface['id']);
                $rec['FREQ'] = (int)substr((string)end($parts), -1) === 1 ? '5ГГц' : '2.4ГГц';
                $rec['NET'] = (string)($iface['ssid'] ?? '');
            }
        }

        return $rec;
    }

    /**
     * Detect the active WAN interface.
     *
     * @param array $interfaces
     * @return array{STATE:int,NAME?:string,UPTIME?:int,IP?:string,WAN?:int}
     */
    public function backupWAN($interfaces)
    {
        $result = array('STATE' => 0);
        $global = array();
        foreach ($this->normalizeRows($interfaces) as $iface) {
            if (($iface['global'] ?? '') === 'true') {
                $global[] = $iface;
            }
        }
        if (!$global) {
            return $result;
        }
        usort($global, function ($a, $b) {
            return (int)($b['priority'] ?? 0) <=> (int)($a['priority'] ?? 0);
        });
        foreach ($global as $index => $iface) {
            if (($iface['connected'] ?? '') !== 'yes' || empty($iface['defaultgw'])) {
                continue;
            }
            $result['STATE'] = 1;
            $result['NAME'] = (string)($iface['description'] ?? '');
            $result['UPTIME'] = (int)($iface['uptime'] ?? 0);
            $result['IP'] = (string)($iface['address'] ?? '');
            $result['WAN'] = $index;
            break;
        }
        return $result;
    }

    /**
     * Debug logging.
     *
     * @param string $message
     * @return void
     */
    public function WriteLog($message)
    {
        if ($this->debug) {
            DebMes($message, $this->name);
        }
    }

    /**
     * IPv4 check (kept for backward compatibility).
     *
     * @param string $address
     * @return bool
     */
    public function isIP($address)
    {
        return keenetic_is_ip($address);
    }

    /**
     * Prepare CodeMirror attributes for a template.
     *
     * @param array $out
     * @param string $key
     * @return void
     */
    private function prepareCodeEditor(&$out, $key)
    {
        $out['CODE_EDITOR_KEY'] = $key;
        $out['CODE_EDITOR_MODE'] = 'php';
        $out['CODE_EDITOR_THEME'] = defined('SETTINGS_CODEEDITOR_THEME') ? SETTINGS_CODEEDITOR_THEME : 'auto';
        $out['SETTINGS_CODEEDITOR_THEME'] = $out['CODE_EDITOR_THEME'];
        $out['SETTINGS_CODEEDITOR_AUTOCLOSEQUOTES'] = defined('SETTINGS_CODEEDITOR_AUTOCLOSEQUOTES') ? SETTINGS_CODEEDITOR_AUTOCLOSEQUOTES : 1;
        $out['SETTINGS_CODEEDITOR_WRAPLINES'] = defined('SETTINGS_CODEEDITOR_WRAPLINES') ? SETTINGS_CODEEDITOR_WRAPLINES : 0;
        $out['SETTINGS_CODEEDITOR_MIXLINE'] = defined('SETTINGS_CODEEDITOR_MIXLINE') ? SETTINGS_CODEEDITOR_MIXLINE : 12;
        $out['SETTINGS_CODEEDITOR_SHOWLINE'] = defined('SETTINGS_CODEEDITOR_SHOWLINE') ? SETTINGS_CODEEDITOR_SHOWLINE : 24;
        $out['SETTINGS_CODEEDITOR_SHOWERROR'] = defined('SETTINGS_CODEEDITOR_SHOWERROR') ? SETTINGS_CODEEDITOR_SHOWERROR : 1;
        $out['SETTINGS_CODEEDITOR_TURNONSETTINGS'] = defined('SETTINGS_CODEEDITOR_TURNONSETTINGS') ? SETTINGS_CODEEDITOR_TURNONSETTINGS : 1;
    }

    // ---------------------------------------------------------------------
    // Schema
    // ---------------------------------------------------------------------

    /**
     * Add columns introduced after the first release. Runs once per request.
     *
     * @return void
     */
    public function ensureSchema()
    {
        if (self::$schemaChecked) {
            return;
        }
        self::$schemaChecked = true;
        if (!$this->tablesInstalled()) {
            return;
        }
        $this->ensureColumn('keenetic_routers', 'HREF_FW', "TEXT", 'NEW_FIRMWARE');
        $this->ensureColumn('keenetic_routers', 'NO_UPDATE', 'TINYINT(1) NOT NULL DEFAULT 0', 'HREF_FW');
        // Older releases used short columns: longer model names, firmware
        // versions and multiple auth cookies were silently truncated.
        $this->ensureColumnWidth('keenetic_routers', 'COOKIES', 255, "VARCHAR(255) NULL DEFAULT ''");
        $this->ensureColumnWidth('keenetic_routers', 'PASSWORD', 255, "VARCHAR(255) NOT NULL DEFAULT ''");
        $this->ensureColumnWidth('keenetic_routers', 'MODEL', 40, "VARCHAR(40) NOT NULL DEFAULT ''");
        $this->ensureColumnWidth('keenetic_routers', 'FIRMWARE', 40, "VARCHAR(40) NOT NULL DEFAULT ''");
        $this->ensureColumnWidth('keenetic_routers', 'NEW_FIRMWARE', 40, "VARCHAR(40) NOT NULL DEFAULT ''");
        $this->ensureColumnWidth('keenetic_routers', 'SERIAL', 40, "VARCHAR(40) NOT NULL DEFAULT ''");
        $this->ensureColumnWidth('keenetic_connections', 'ADDRESS', 64, "VARCHAR(64) NOT NULL DEFAULT ''");
        // Small unsigned columns overflowed for periods above 65535 seconds.
        $this->ensureIntColumn('keenetic_routers', 'REQ_PERIOD', 'INT UNSIGNED NOT NULL DEFAULT 5');
        $this->ensureIntColumn('keenetic_routers', 'AUTO_REBOOT', 'INT UNSIGNED NOT NULL DEFAULT 0');
        // Migrate the old "HREF_FW = 1 means disabled" sentinel.
        SQLExec("UPDATE keenetic_routers SET NO_UPDATE=1, HREF_FW='' WHERE HREF_FW='1' AND NO_UPDATE=0");
    }

    /**
     * Whether the module tables exist.
     *
     * @return bool
     */
    private function tablesInstalled()
    {
        $table = SQLSelectOne("SHOW TABLES LIKE 'keenetic_routers'");
        return !empty($table);
    }

    /**
     * Add a column when it is missing.
     *
     * @param string $table
     * @param string $column
     * @param string $definition
     * @param string $after
     * @return void
     */
    private function ensureColumn($table, $column, $definition, $after = '')
    {
        $exists = SQLSelectOne(
            "SHOW COLUMNS FROM `" . DBSafe($table) . "` LIKE '" . DBSafe($column) . "'"
        );
        if (!empty($exists['Field'])) {
            return;
        }
        $sql = 'ALTER TABLE `' . $table . '` ADD `' . $column . '` ' . $definition;
        if ($after !== '') {
            $sql .= ' AFTER `' . $after . '`';
        }
        SQLExec($sql);
        $this->WriteLog('Схема: добавлена колонка ' . $table . '.' . $column . '.');
    }

    /**
     * Widen a VARCHAR column when an older release created it too narrow.
     *
     * @param string $table
     * @param string $column
     * @param int $width
     * @param string $definition full column definition for MODIFY
     * @return void
     */
    private function ensureColumnWidth($table, $column, $width, $definition)
    {
        $exists = SQLSelectOne(
            "SHOW COLUMNS FROM `" . DBSafe($table) . "` LIKE '" . DBSafe($column) . "'"
        );
        if (empty($exists['Type'])) {
            return;
        }
        if (!preg_match('/^varchar\((\d+)\)/i', (string)$exists['Type'], $match)) {
            return;
        }
        if ((int)$match[1] >= (int)$width) {
            return;
        }
        SQLExec('ALTER TABLE `' . $table . '` MODIFY `' . $column . '` ' . $definition);
        $this->WriteLog('Схема: расширена колонка ' . $table . '.' . $column . ' до ' . $width . '.');
    }

    /**
     * Convert a legacy SMALLINT column to INT when needed.
     *
     * @param string $table
     * @param string $column
     * @param string $definition
     * @return void
     */
    private function ensureIntColumn($table, $column, $definition)
    {
        $exists = SQLSelectOne(
            "SHOW COLUMNS FROM `" . DBSafe($table) . "` LIKE '" . DBSafe($column) . "'"
        );
        if (empty($exists['Type']) || stripos((string)$exists['Type'], 'smallint') === false) {
            return;
        }
        SQLExec('ALTER TABLE `' . $table . '` MODIFY `' . $column . '` ' . $definition);
        $this->WriteLog('Схема: колонка ' . $table . '.' . $column . ' расширена до INT.');
    }

    // ---------------------------------------------------------------------
    // Install / uninstall
    // ---------------------------------------------------------------------

    /**
     * Module installation routine.
     *
     * @param string $data
     * @return void
     */
    public function install($data = '')
    {
        subscribeToEvent($this->name, 'HOURLY');
        if (!$this->classExists('Keenetic')) {
            addClass('Keenetic');
        }
        // addClassMethod() is idempotent: it only inserts a missing method and
        // never overwrites a method already edited by the user.
        $code = '$this->setProperty("uptime", $params["UPTIME"]);
$this->setProperty("rxbytes", $params["RXBYTES"]);
$this->setProperty("txbytes", $params["TXBYTES"]);
$this->setProperty("router", $params["ROUTER"]);
$this->setProperty("net", $params["NET"]);
$this->setProperty("frequency", $params["FREQ"]);
$this->setProperty("mode", $params["WIFI_MODE"]);
$this->setProperty("rssi", $params["RSSI"]);';
        addClassMethod('Keenetic', 'track', $code);
        foreach (array('uptime', 'rxbytes', 'txbytes', 'router', 'net', 'frequency', 'mode', 'rssi') as $property) {
            addClassProperty('Keenetic', $property);
        }
        parent::install();
    }

    /**
     * Module uninstall routine.
     *
     * @return void
     */
    public function uninstall()
    {
        unsubscribeFromEvent($this->name, 'HOURLY');
        $routers = SQLSelect('SELECT ID FROM keenetic_routers');
        foreach ((array)$routers as $router) {
            $this->deleteKeeneticRouter((int)$router['ID']);
        }
        SQLExec('DROP TABLE IF EXISTS keenetic_routers');
        SQLExec('DROP TABLE IF EXISTS keenetic_devices');
        SQLExec('DROP TABLE IF EXISTS keenetic_connections');
        $this->deleteClassByName('Keenetic');
        parent::uninstall();
    }

    /**
     * Database installation routine.
     *
     * @param string $data
     * @return void
     */
    public function dbInstall($data)
    {
        $data = <<<EOD
 keenetic_routers: ID int unsigned NOT NULL auto_increment
 keenetic_routers: TITLE varchar(100) NOT NULL DEFAULT ''
 keenetic_routers: ADDRESS varchar(50) NOT NULL DEFAULT ''
 keenetic_routers: MODEL varchar(40) NOT NULL DEFAULT ''
 keenetic_routers: LOGIN varchar(100) NOT NULL DEFAULT ''
 keenetic_routers: PASSWORD varchar(255) NOT NULL DEFAULT ''
 keenetic_routers: COOKIES varchar(255) NULL DEFAULT ''
 keenetic_routers: FIRMWARE varchar(40) NOT NULL DEFAULT ''
 keenetic_routers: NEW_FIRMWARE varchar(40) NOT NULL DEFAULT ''
 keenetic_routers: HREF_FW text
 keenetic_routers: NO_UPDATE boolean NOT NULL DEFAULT 0
 keenetic_routers: SERIAL varchar(40) NOT NULL DEFAULT ''
 keenetic_routers: MWS boolean NOT NULL DEFAULT 0
 keenetic_routers: STATUS boolean NOT NULL DEFAULT 0
 keenetic_routers: INET_STATUS boolean NOT NULL DEFAULT 0
 keenetic_routers: AUTO_REBOOT int unsigned NOT NULL DEFAULT 0
 keenetic_routers: UPDATED datetime
 keenetic_routers: REQ_PERIOD int unsigned NOT NULL DEFAULT 5
 keenetic_routers: REQ_UPDATE int unsigned NOT NULL DEFAULT 0
 keenetic_routers: CNCT_SCRIPT text
 
 keenetic_devices: ID int unsigned NOT NULL auto_increment
 keenetic_devices: ROUTER_ID int NOT NULL DEFAULT '0'
 keenetic_devices: TITLE varchar(100) NOT NULL DEFAULT ''
 keenetic_devices: MAC varchar(20) NOT NULL DEFAULT ''
 keenetic_devices: IP varchar(20) NOT NULL DEFAULT ''
 keenetic_devices: STATUS boolean NOT NULL DEFAULT 0
 keenetic_devices: TYPE_CONNECT varchar(10) NOT NULL DEFAULT ''
 keenetic_devices: REGISTERED boolean NOT NULL DEFAULT 0
 keenetic_devices: TRACK boolean NOT NULL DEFAULT 0
 keenetic_devices: LOG text
 keenetic_devices: SCRIPT text
 keenetic_devices: LINKED_OBJECT varchar(100) NOT NULL DEFAULT ''
 keenetic_devices: LINKED_PROPERTY varchar(100) NOT NULL DEFAULT ''
 keenetic_devices: LINKED_METHOD varchar(100) NOT NULL DEFAULT ''
 keenetic_devices: UPDATED datetime
 
 keenetic_connections: ID int unsigned NOT NULL auto_increment
 keenetic_connections: ROUTER_ID int NOT NULL DEFAULT '0'
 keenetic_connections: CONN_ID varchar(100) NOT NULL DEFAULT ''
 keenetic_connections: TITLE varchar(100) NOT NULL DEFAULT ''
 keenetic_connections: STATE boolean NOT NULL DEFAULT 0
 keenetic_connections: STATUS boolean NOT NULL DEFAULT 0
 keenetic_connections: ADDRESS varchar(64) NOT NULL DEFAULT ''
 keenetic_connections: TYPE varchar(50) NOT NULL DEFAULT ''
 keenetic_connections: LINKED_OBJECT varchar(100) NOT NULL DEFAULT ''
 keenetic_connections: LINKED_PROPERTY varchar(100) NOT NULL DEFAULT ''
 keenetic_connections: LINKED_METHOD varchar(100) NOT NULL DEFAULT ''
 keenetic_connections: UPDATED datetime
EOD;
        parent::dbInstall($data);
    }

    // ---------------------------------------------------------------------
    // Object utilities
    // ---------------------------------------------------------------------

    /**
     * Whether a class exists.
     *
     * @param string $name
     * @return bool
     */
    private function classExists($name)
    {
        $name = trim((string)$name);
        if ($name === '') {
            return false;
        }
        $row = SQLSelectOne('SELECT ID FROM classes WHERE TITLE="' . DBSafe($name) . '"');
        return !empty($row['ID']);
    }

    /**
     * Delete an object by name.
     *
     * @param string $name
     * @return void
     */
    public function delete_object($name)
    {
        $this->deleteObjectByName((string)$name);
    }

    /**
     * Delete an object by name.
     *
     * @param string $name
     * @return void
     */
    private function deleteObjectByName($name)
    {
        $object = getObject((string)$name);
        if (isset($object)) {
            include_once DIR_MODULES . 'objects/objects.class.php';
            $objects = new objects();
            $objects->delete_objects($object->id);
        }
    }

    /**
     * Delete a class by name.
     *
     * @param string $name
     * @return void
     */
    public function delete_class($name)
    {
        $this->deleteClassByName((string)$name);
    }

    /**
     * Delete a class by name.
     *
     * @param string $name
     * @return void
     */
    private function deleteClassByName($name)
    {
        $row = SQLSelectOne('SELECT ID FROM classes WHERE TITLE="' . DBSafe((string)$name) . '"');
        if (!empty($row['ID'])) {
            include_once DIR_MODULES . 'classes/classes.class.php';
            $classes = new classes();
            $classes->delete_classes($row['ID']);
        }
    }

    /**
     * Encrypt a router password for storage.
     *
     * The legacy `dsCrypt` alphabet does not cover every printable character,
     * so base64 is encrypted on top of it. The `b64:` prefix keeps passwords
     * saved by older releases readable.
     *
     * @param string $plain
     * @return string
     */
    public function encryptPassword($plain)
    {
        $plain = (string)$plain;
        if ($plain === '') {
            return '';
        }
        $encoded = rtrim(base64_encode($plain), '=');
        return 'b64:' . $this->dsCrypt($encoded);
    }

    /**
     * Decrypt a stored router password (old and new formats).
     *
     * @param string $stored
     * @return string
     */
    public function decryptPassword($stored)
    {
        $stored = (string)$stored;
        if ($stored === '') {
            return '';
        }
        if (strpos($stored, 'b64:') === 0) {
            $plain = base64_decode($this->dsCrypt(substr($stored, 4), true), true);
            return $plain === false ? '' : $plain;
        }
        return $this->dsCrypt($stored, true);
    }

    /**
     * Encrypt/decrypt a password with the legacy reversible cipher.
     *
     * Keenetic requires the plaintext password to build the auth challenge, so
     * a one-way hash cannot be used here. The value is obfuscated in the DB.
     *
     * @param string $input
     * @param bool $decrypt
     * @return string
     */
    public function dsCrypt($input, $decrypt = false)
    {
        $o = $s1 = $s2 = array();
        $basea = array('?', '(', '@', ';', '$', '#', ']', '&', '*');
        $basea = array_merge($basea, range('a', 'z'), range('A', 'Z'), range(0, 9));
        $basea = array_merge($basea, array('!', ')', '_', '+', '|', '%', '/', '[', '.', ' '));
        $dimension = 9;
        for ($i = 0; $i < $dimension; $i++) {
            for ($j = 0; $j < $dimension; $j++) {
                $s1[$i][$j] = $basea[$i * $dimension + $j];
                $s2[$i][$j] = str_rot13($basea[($dimension * $dimension - 1) - ($i * $dimension + $j)]);
            }
        }
        unset($basea);
        $input = (string)$input;
        $m = floor(strlen($input) / 2) * 2;
        $symbl = $m == strlen($input) ? '' : $input[strlen($input) - 1];
        $al = array();
        for ($ii = 0; $ii < $m; $ii += 2) {
            $symb1 = $symbn1 = strval($input[$ii]);
            $symb2 = $symbn2 = strval($input[$ii + 1]);
            $a1 = $a2 = array();
            for ($i = 0; $i < $dimension; $i++) {
                for ($j = 0; $j < $dimension; $j++) {
                    if ($decrypt) {
                        if ($symb1 === strval($s2[$i][$j])) {
                            $a1 = array($i, $j);
                        }
                        if ($symb2 === strval($s1[$i][$j])) {
                            $a2 = array($i, $j);
                        }
                        if (!empty($symbl) && $symbl === strval($s2[$i][$j])) {
                            $al = array($i, $j);
                        }
                    } else {
                        if ($symb1 === strval($s1[$i][$j])) {
                            $a1 = array($i, $j);
                        }
                        if ($symb2 === strval($s2[$i][$j])) {
                            $a2 = array($i, $j);
                        }
                        if (!empty($symbl) && $symbl === strval($s1[$i][$j])) {
                            $al = array($i, $j);
                        }
                    }
                }
            }
            if (sizeof($a1) && sizeof($a2)) {
                $symbn1 = $decrypt ? $s1[$a1[0]][$a2[1]] : $s2[$a1[0]][$a2[1]];
                $symbn2 = $decrypt ? $s2[$a2[0]][$a1[1]] : $s1[$a2[0]][$a1[1]];
            }
            $o[] = $symbn1 . $symbn2;
        }
        if (!empty($symbl) && sizeof($al)) {
            $o[] = $decrypt ? $s1[$al[1]][$al[0]] : $s2[$al[1]][$al[0]];
        }
        return implode('', $o);
    }
}
