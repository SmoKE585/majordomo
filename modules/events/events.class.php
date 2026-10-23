<?php
/**
 * Events
 *
 * Events
 *
 * @package MajorDoMo
 * @author Serge Dzheigalo <sergejey@gmail.com> https://majordomohome.com/
 * @version 0.2 (wizard, 15:03:07 [Mar 27, 2009])
 */
//
//
class events extends module
{
    /**
     * events
     *
     * Module class constructor
     *
     * @access private
     */
    function __construct()
    {
        $this->name = "events";
        $this->title = "<#LANG_MODULE_EVENTS#>";
        $this->module_category = "<#LANG_SECTION_SYSTEM#>";
        $this->checkInstalled();
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
        if (IsSet($this->id)) {
            $data["id"] = $this->id;
        }
        if (IsSet($this->view_mode)) {
            $data["view_mode"] = $this->view_mode;
        }
        if (IsSet($this->edit_mode)) {
            $data["edit_mode"] = $this->edit_mode;
        }
        if (IsSet($this->tab)) {
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
        global $session;
        $out = array();
        $this->action = 'admin';
        $this->admin($out);
        if (IsSet($this->owner->action)) {
            $out['PARENT_ACTION'] = $this->owner->action;
        }
        if (IsSet($this->owner->name)) {
            $out['PARENT_NAME'] = $this->owner->name;
        }
        $out['VIEW_MODE'] = $this->view_mode;
        $out['EDIT_MODE'] = $this->edit_mode;
        $out['MODE'] = $this->mode;
        $out['ACTION'] = $this->action;
        $out['TAB'] = $this->tab;
        if ($this->single_rec) {
            $out['SINGLE_REC'] = 1;
        }
        $this->data = $out;
        $p = new parser(DIR_TEMPLATES . $this->name . "/" . $this->name . ".html", $this->data, $this);
        $this->result = $p->result;
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
        if ($this->ajax && gr('getlatest')) {
            header('Content-Type: text/html; charset=utf-8');
            echo $this->renderEventsFeedHtml(12);
            exit;
        }
        if (isset($this->data_source) && !$_GET['data_source'] && !$_POST['data_source']) {
            $out['SET_DATASOURCE'] = 1;
        }

        $ok_msg = gr('ok_msg');
        if ($ok_msg != '') {
            $out['OK_MSG'] = $ok_msg;
        }

        if ($this->mode == 'update_settings') {

            $rec=SQLSelectOne("SELECT * FROM settings WHERE NAME='EVENTS_KEEP_HISTORY'");
            $rec['NAME']='EVENTS_KEEP_HISTORY';
            $rec['VALUE']=gr('keep_history','int');
            SQLInsertUpdate('settings',$rec);


            $rec=SQLSelectOne("SELECT * FROM settings WHERE NAME='EVENTS_MQTT_FORWARD'");
            $rec['NAME']='EVENTS_MQTT_FORWARD';
            $rec['VALUE']=gr('mqtt_forward','int');
            SQLInsertUpdate('settings',$rec);

            $rec=SQLSelectOne("SELECT * FROM settings WHERE NAME='EVENTS_MQTT_ROOT'");
            $rec['NAME']='EVENTS_MQTT_ROOT';
            $rec['VALUE']=gr('mqtt_root');
            SQLInsertUpdate('settings',$rec);


            $this->redirect("?ok_msg=" . urlencode(LANG_DATA_SAVED));
        }

        if ($this->data_source == 'system_events' || $this->view_mode == 'system_events') {
            $this->systemEvents($out);
            return;
        }

        if ($this->data_source == 'events' || $this->data_source == '') {
            if ($this->view_mode == '' || $this->view_mode == 'search_events') {
                $this->search_events($out);
            }
            if ($this->view_mode == 'edit_events') {
                $this->edit_events($out, $this->id);
            }
            if ($this->view_mode == 'delete_events') {
                $this->delete_events($this->id);
                $this->redirect("?");
            }
            if ($this->view_mode == 'multiple_events') {
                global $ids;
                if (is_array($ids)) {
                    $total_selected = count($ids);
                    global $delete;
                    for ($i = 0; $i < $total_selected; $i++) {
                        $id = $ids[$i];
                        if ($delete) {
                            // operation: DELETE
                            $this->delete_events($id);
                        }
                    }
                }
                $this->redirect("?");
            }
        }
    }




    /**
     * Recent events feed used by the admin page and its live AJAX refresh.
     *
     * The feed is rendered server-side so the page works without JavaScript and
     * the same markup is reused by /ajax/events.html?getlatest=1.
     */
    function renderEventsFeedHtml($limit = 12)
    {
        $limit = max(1, (int)$limit);
        $events = SQLSelect("SELECT ID, EVENT_NAME, EVENT_TYPE, DESCRIPTION, DETAILS, ADDED
            FROM events
            ORDER BY ADDED DESC, ID DESC
            LIMIT " . $limit);

        if (!isset($events[0]['ID'])) {
            return '<div class="md-empty md-empty--compact md-events-feed__empty">'
                . '<span class="md-empty__icon"><i class="ti ti-bell-off" aria-hidden="true"></i></span>'
                . '<div class="md-empty__title">Событий пока нет</div>'
                . '<p class="md-empty__text">События появятся здесь после вызова <code>registerEvent()</code> или отправки через API.</p>'
                . '</div>';
        }

        $html = '<ul class="md-events-feed__list">';
        foreach ($events as $event) {
            $event_type = strtolower(trim((string)$event['EVENT_TYPE']));
            $tone = 'neutral';
            $icon = 'activity';
            if ($event_type === 'system') {
                $tone = 'primary';
                $icon = 'bolt';
            } elseif ($event_type === 'message') {
                $tone = 'info';
                $icon = 'message';
            } elseif ($event_type === 'alarm' || $event_type === 'alert') {
                $tone = 'danger';
                $icon = 'bell-ringing';
            } elseif ($event_type === 'error') {
                $tone = 'danger';
                $icon = 'alert-triangle';
            }

            $name = trim((string)$event['EVENT_NAME']);
            if ($name === '') {
                $name = 'Событие #' . (int)$event['ID'];
            }
            $name_html = htmlspecialchars($name, ENT_QUOTES);
            $description = trim((string)$event['DESCRIPTION']);
            $description_html = htmlspecialchars($description, ENT_QUOTES);

            $details = trim((string)$event['DETAILS']);
            $details_html = '';
            if ($details !== '') {
                $decoded = json_decode($details, true);
                if (is_array($decoded)) {
                    $json_options = 0;
                    if (defined('JSON_UNESCAPED_UNICODE')) {
                        $json_options |= JSON_UNESCAPED_UNICODE;
                    }
                    if (defined('JSON_UNESCAPED_SLASHES')) {
                        $json_options |= JSON_UNESCAPED_SLASHES;
                    }
                    $encoded = json_encode($decoded, $json_options);
                    if (is_string($encoded) && $encoded !== '') {
                        $details = $encoded;
                    }
                }
                $details = preg_replace('/\s+/u', ' ', $details);
                if (mb_strlen($details, 'UTF-8') > 280) {
                    $details = mb_substr($details, 0, 280, 'UTF-8') . '…';
                }
                $details_html = '<code class="md-events-feed__details">' . htmlspecialchars($details, ENT_QUOTES) . '</code>';
            }

            $added_ts = strtotime((string)$event['ADDED']);
            $added_date = $added_ts ? date('d.m.Y', $added_ts) : (string)$event['ADDED'];
            $added_time = $added_ts ? date('H:i:s', $added_ts) : '';
            $added_iso = $added_ts ? date('c', $added_ts) : '';
            $url = ROOTHTML . 'panel/event/' . (int)$event['ID'] . '.html';

            $html .= '<li class="md-events-feed__item">';
            $html .= '<span class="md-events-feed__marker md-events-feed__marker--' . $tone . '"><i class="ti ti-' . $icon . '" aria-hidden="true"></i></span>';
            $html .= '<div class="md-events-feed__body">';
            $html .= '<div class="md-events-feed__head">';
            $html .= '<a class="md-events-feed__name" href="' . htmlspecialchars($url, ENT_QUOTES) . '">' . $name_html . '</a>';
            $html .= '<time class="md-events-feed__time" datetime="' . htmlspecialchars($added_iso, ENT_QUOTES) . '">' . htmlspecialchars($added_date . ' ' . $added_time, ENT_QUOTES) . '</time>';
            $html .= '</div>';
            if ($description_html !== '') {
                $html .= '<p class="md-events-feed__desc">' . $description_html . '</p>';
            }
            if ($details_html !== '') {
                $html .= '<div class="md-events-feed__payload">' . $details_html . '</div>';
            }
            $html .= '</div>';
            $html .= '</li>';
        }
        $html .= '</ul>';

        return $html;
    }

    function registerEvent($eventName, $details = '', $expire_in = 0)
    {
        $sqlQuery = "SELECT *
                  FROM events
                 WHERE EVENT_NAME = '" . DBSafe($eventName) . "'
                   AND EVENT_TYPE = 'system'
                 ORDER BY ID DESC
                 LIMIT 1";

        $rec = SQLSelectOne($sqlQuery);
        if (!is_array($rec)) {
            $rec = array();
        }

        $rec['EVENT_NAME'] = $eventName;
        $rec['EVENT_TYPE'] = 'system';
        if (!is_array($details)) {
            $rec['DETAILS'] = $details;
        } else {
            $rec['DETAILS'] = json_encode($details);
        }
        $rec['ADDED'] = date('Y-m-d H:i:s');
        if ($expire_in) {
            $rec['EXPIRE'] = date('Y-m-d H:i:s', time() + $expire_in * 24 * 60 * 60);
        } else {
            unset($rec['EXPIRE']);
        }
        $rec['PROCESSED'] = 1;

        if (!empty($rec['ID'])) {
            SQLUpdate('events', $rec);
        } else {
            $rec['ID'] = SQLInsert('events', $rec);
        }

        if (is_array($details)) {
            $params = $details;
        } else {
            $params = array();
        }
        $params['updated'] = time();

        foreach ($params as $k => $v) {
            $param_rec = SQLSelectOne("SELECT * FROM events_params WHERE EVENT_ID=" . $rec['ID'] . " AND TITLE LIKE '" . DBSafe($k) . "'");
            if (!is_array($param_rec)) {
                $param_rec = array();
            }
            $param_rec['TITLE'] = $k;
            $param_rec['VALUE'] = $v;
            $param_rec['UPDATED'] = date('Y-m-d H:i:s');
            $param_rec['EVENT_ID'] = $rec['ID'];
            if (!empty($param_rec['ID'])) {
                SQLUpdate('events_params', $param_rec);
            } else {
                $param_rec['ID'] = SQLInsert('events_params', $param_rec);
            }
            if (!empty($param_rec['LINKED_OBJECT']) && !empty($param_rec['LINKED_PROPERTY'])) {
                setGlobal($param_rec['LINKED_OBJECT'] . '.' . $param_rec['LINKED_PROPERTY'], $v);
            }
            if (!empty($param_rec['LINKED_OBJECT']) && !empty($param_rec['LINKED_METHOD'])) {
                $method_params = array();
                $method_params['VALUE'] = $v;
                $method_params['EVENT'] = $rec['TITLE'] ?? '';
                callMethodSafe($param_rec['LINKED_OBJECT'] . '.' . $param_rec['LINKED_METHOD'], $method_params);
            }
        }


        if (defined('SETTINGS_EVENTS_KEEP_HISTORY') && (int)SETTINGS_EVENTS_KEEP_HISTORY>0) {
            $this->clear_expired_events();
        }
        if (defined('SETTINGS_EVENTS_MQTT_FORWARD') && SETTINGS_EVENTS_MQTT_FORWARD) {
            $mqtt_root = SETTINGS_EVENTS_MQTT_ROOT;
            if (!$mqtt_root) {
                $mqtt_root='/events';
            }
            $path = $mqtt_root.'/'.$eventName;
            $path = str_replace('//','/',$path);
            if (is_array($details)) {
                $msg = json_encode($details);
            } else {
                $msg = $details;
            }
            callAPI('/api/module/mqtt','GET',array('publish'=>$path,'msg'=>$msg));
        }

        return $rec['ID'];

    }

    function clear_expired_events() {
        if (!defined('SETTINGS_EVENTS_KEEP_HISTORY') || (int)SETTINGS_EVENTS_KEEP_HISTORY==0) return false;
        $expired = SQLSelect("SELECT ID, EVENT_ID FROM events_params WHERE LINKED_OBJECT='' AND UPDATED<'".date('Y-m-d H:i:s',time()-SETTINGS_EVENTS_KEEP_HISTORY*24*60*60)."'");
        $total = count($expired);
        if (!$total) return;
        $event_ids=array(0);
        for($i=0;$i<$total;$i++) {
            SQLExec("DELETE FROM events_params WHERE ID=".$expired[$i]['ID']);
            $event_ids[]=$expired[$i]['EVENT_ID'];
        }
        $events=SQLSelect("SELECT events.ID, events_params.ID as PARAM_ID FROM events LEFT JOIN events_params ON events.ID=events_params.EVENT_ID WHERE events.DESCRIPTION='' AND events.ID IN (".implode(',',$event_ids).") AND IsNull(events_params.ID)");
        $total = count($events);
        if ($total>0) {
            for($i=0;$i<$total;$i++) {
                SQLExec("DELETE FROM events WHERE ID=".$events[$i]['ID']);
            }
        }
    }

    /**
     * events search
     *
     * @access public
     */
    /**
     * System event journal (`system_events`) rendered with the shared activity feed.
     */
    function systemEvents(&$out)
    {
        require_once(ROOT . 'lib/ui/MDJUI.php');
        $names = \MajorDoMo\Core\Events\SystemEvents::names();

        if (gr('clear') !== '') {
            $mode = (string)gr('clear');
            if ($mode === 'all') {
                SQLExec('DELETE FROM system_events');
            } else {
                $days = max(1, (int)$mode);
                SQLExec("DELETE FROM system_events WHERE CREATED<'" . date('Y-m-d H:i:s', time() - $days * 86400) . "'");
            }
            $this->redirect('?view_mode=system_events&ok_msg=' . urlencode('Журнал очищен'));
        }

        $eventFilter = strtoupper(trim((string)gr('event')));
        if (!in_array($eventFilter, $names, true)) {
            $eventFilter = '';
        }
        $onlyProblems = (int)gr('problems') === 1;
        $limit = (int)gr('limit');
        if ($limit < 20 || $limit > 500) {
            $limit = 100;
        }
        $offset = max(0, (int)gr('offset'));

        $where = array('1');
        if ($eventFilter !== '') {
            $where[] = "NAME='" . DBSafe($eventFilter) . "'";
        }
        if ($onlyProblems) {
            $where[] = "NAME IN ('" . \MajorDoMo\Core\Events\SystemEvents::SYSTEM_UPDATE_FAILED . "','" . \MajorDoMo\Core\Events\SystemEvents::SYSTEM_UPDATE_ABORTED . "')";
        }
        $whereSql = implode(' AND ', $where);

        $total = (int)(SQLSelectOne("SELECT COUNT(*) AS C FROM system_events WHERE $whereSql")['C'] ?? 0);
        $dayAgo = date('Y-m-d H:i:s', time() - 86400);
        $dayTotal = (int)(SQLSelectOne("SELECT COUNT(*) AS C FROM system_events WHERE CREATED>'" . $dayAgo . "'")['C'] ?? 0);
        $problemsTotal = (int)(SQLSelectOne("SELECT COUNT(*) AS C FROM system_events WHERE NAME IN ('" . \MajorDoMo\Core\Events\SystemEvents::SYSTEM_UPDATE_FAILED . "','" . \MajorDoMo\Core\Events\SystemEvents::SYSTEM_UPDATE_ABORTED . "')")['C'] ?? 0);
        $lastRow = SQLSelectOne('SELECT CREATED FROM system_events ORDER BY ID DESC LIMIT 1');

        $rows = SQLSelect("SELECT * FROM system_events WHERE $whereSql ORDER BY ID DESC LIMIT " . $offset . ',' . $limit);
        $items = array();
        foreach ($rows as $row) {
            $name = (string)$row['NAME'];
            $payload = json_decode((string)$row['PAYLOAD'], true);
            if (!is_array($payload)) {
                $payload = array();
            }
            $timestamp = strtotime((string)$row['CREATED']);
            if (!$timestamp) {
                $timestamp = time();
            }
            $items[] = array(
                'date' => $this->systemEventDateLabel($timestamp),
                'title' => \MajorDoMo\Core\Events\SystemEvents::title($name),
                'text' => $this->systemEventText($name, $payload),
                'time' => date('H:i', $timestamp),
                'datetime' => date('Y-m-d H:i:s', $timestamp),
                'icon' => $this->systemEventIcon($name),
                'tone' => $this->systemEventTone(\MajorDoMo\Core\Events\SystemEvents::severity($name)),
                'meta' => array_values(array_filter(array($name, (string)$row['SOURCE']))),
            );
        }

        $out['SYSTEM_EVENTS_FEED'] = MDJUI::activityFeed(array(
            'eyebrow' => 'Журнал',
            'title' => 'События системы',
            'subtitle' => 'Установка модулей, обновления, настройки и изменения циклов.',
            'icon' => 'activity',
            'items' => $items,
            'empty_title' => 'Событий пока нет',
            'empty_text' => 'Здесь появятся установка и удаление модулей, обновления системы, изменения настроек и циклов.',
        ));
        $out['SYSTEM_LOG_TOTAL'] = $total;
        $out['SYSTEM_LOG_DAY'] = $dayTotal;
        $out['SYSTEM_LOG_PROBLEMS'] = $problemsTotal;
        $out['SYSTEM_LOG_HAS_PROBLEMS'] = $problemsTotal > 0 ? 1 : 0;
        $out['SYSTEM_LOG_LAST'] = isset($lastRow['CREATED']) ? date('d.m.Y H:i', strtotime((string)$lastRow['CREATED'])) : '';
        $out['EVENT_FILTER'] = $eventFilter;
        $eventOptions = array('<option value="">Все события</option>');
        foreach ($names as $eventName) {
            $eventOptions[] = '<option value="' . MDJUI::e($eventName) . '"'
                . ($eventFilter === $eventName ? ' selected' : '') . '>'
                . MDJUI::e(\MajorDoMo\Core\Events\SystemEvents::title($eventName)) . '</option>';
        }
        $out['EVENT_FILTER_SELECT'] = implode('', $eventOptions);
        $out['ONLY_PROBLEMS'] = $onlyProblems ? 1 : 0;
        $out['LIMIT'] = $limit;
        $out['OFFSET'] = $offset;
        $out['PREV_OFFSET'] = max(0, $offset - $limit);
        $out['NEXT_OFFSET'] = $offset + $limit;
        $out['HAS_PREV'] = $offset > 0 ? 1 : 0;
        $out['HAS_NEXT'] = ($offset + $limit) < $total ? 1 : 0;
        $out['VIEW_MODE'] = 'system_events';
        $out['DATA_SOURCE'] = 'system_events';
    }

    private function systemEventDateLabel(int $timestamp): string
    {
        $today = date('Y-m-d');
        $date = date('Y-m-d', $timestamp);
        if ($date === $today) {
            return 'Сегодня';
        }
        if ($date === date('Y-m-d', time() - 86400)) {
            return 'Вчера';
        }
        return date('d.m.Y', $timestamp);
    }

    private function systemEventText(string $name, array $payload): string
    {
        switch ($name) {
            case \MajorDoMo\Core\Events\SystemEvents::AUTH_BRUTE_FORCE:
                $ip = (string)($payload['ip'] ?? '');
                // В старых записях адрес мог сохраниться пустым: «IP :» в
                // журнале выглядит как сбой вёрстки, а не как «неизвестен».
                return ($ip !== '' ? 'IP ' . $ip : 'IP не определён') . ': ' . (int)($payload['attempts'] ?? 0)
                    . ' неудачных попытки входа. Блокировка на 15 минут. Логин: ' . (string)($payload['login'] ?? '') . '.';
            case \MajorDoMo\Core\Events\SystemEvents::MODULE_INSTALLED:
                return 'Модуль ' . (string)($payload['module'] ?? '') . ' установлен.';
            case \MajorDoMo\Core\Events\SystemEvents::MODULE_REMOVED:
                $cycles = array_filter((array)($payload['cycles'] ?? array()));
                $text = 'Модуль ' . (string)($payload['module'] ?? '') . ' удалён.';
                if ($cycles !== array()) {
                    $text .= ' Циклы: ' . implode(', ', $cycles) . '.';
                }
                return $text;
            case \MajorDoMo\Core\Events\SystemEvents::MODULE_UPDATED:
                $from = (string)($payload['from'] ?? '');
                $to = (string)($payload['to'] ?? '');
                return 'Модуль ' . (string)($payload['module'] ?? '') . ' обновлён'
                    . ($to !== '' ? ' до ' . $to : '') . ($from !== '' ? ' (было ' . $from . ')' : '') . '.';
            case \MajorDoMo\Core\Events\SystemEvents::SYSTEM_UPDATE_STARTED:
            case \MajorDoMo\Core\Events\SystemEvents::SYSTEM_UPDATED:
                $from = (string)($payload['from'] ?? '');
                $to = (string)($payload['to'] ?? '');
                $branch = (string)($payload['branch'] ?? '');
                $parts = array();
                if ($from !== '' || $to !== '') {
                    $parts[] = ($from !== '' ? $from : '?') . ' → ' . ($to !== '' ? $to : '?');
                }
                if ($branch !== '') {
                    $parts[] = 'ветка ' . $branch;
                }
                return $parts === array() ? 'Обновление системы.' : 'Версия: ' . implode(', ', $parts) . '.';
            case \MajorDoMo\Core\Events\SystemEvents::SYSTEM_UPDATE_FAILED:
            case \MajorDoMo\Core\Events\SystemEvents::SYSTEM_UPDATE_ABORTED:
                $reason = trim((string)($payload['reason'] ?? ''));
                return $reason === '' ? 'Файлы не заменены.' : $reason;
            case \MajorDoMo\Core\Events\SystemEvents::SETTINGS_CHANGED:
                $keys = array_slice((array)($payload['keys'] ?? array()), 0, 12);
                $section = (string)($payload['section'] ?? '');
                return 'Раздел «' . $section . '»'
                    . ($keys !== array() ? ': ' . implode(', ', $keys) : '') . '.';
            case \MajorDoMo\Core\Events\SystemEvents::CYCLE_SCRIPT_ADDED:
                return 'Цикл ' . (string)($payload['cycle'] ?? '') . ' добавлен и запущен.';
            case \MajorDoMo\Core\Events\SystemEvents::CYCLE_SCRIPT_REMOVED:
                return 'Цикл ' . (string)($payload['cycle'] ?? '') . ' удалён, надзор остановлен.';
        }
        return '';
    }

    private function systemEventIcon(string $name): string
    {
        $icons = array(
            \MajorDoMo\Core\Events\SystemEvents::AUTH_BRUTE_FORCE => 'shield-lock',
            \MajorDoMo\Core\Events\SystemEvents::MODULE_INSTALLED => 'puzzle',
            \MajorDoMo\Core\Events\SystemEvents::MODULE_REMOVED => 'puzzle-off',
            \MajorDoMo\Core\Events\SystemEvents::MODULE_UPDATED => 'package',
            \MajorDoMo\Core\Events\SystemEvents::SYSTEM_INSTALLED => 'home-check',
            \MajorDoMo\Core\Events\SystemEvents::SYSTEM_UPDATE_STARTED => 'download',
            \MajorDoMo\Core\Events\SystemEvents::SYSTEM_UPDATED => 'refresh',
            \MajorDoMo\Core\Events\SystemEvents::SYSTEM_UPDATE_FAILED => 'alert-circle',
            \MajorDoMo\Core\Events\SystemEvents::SYSTEM_UPDATE_ABORTED => 'ban',
            \MajorDoMo\Core\Events\SystemEvents::SETTINGS_CHANGED => 'settings',
            \MajorDoMo\Core\Events\SystemEvents::CYCLE_SCRIPT_ADDED => 'rotate-clockwise',
            \MajorDoMo\Core\Events\SystemEvents::CYCLE_SCRIPT_REMOVED => 'rotate-rectangle',
        );
        return $icons[$name] ?? 'activity';
    }

    private function systemEventTone(string $severity): string
    {
        return array('error' => 'danger', 'warning' => 'warning', 'success' => 'success')[$severity] ?? 'info';
    }

    function search_events(&$out)
    {
        require(DIR_MODULES . $this->name . '/events_search.inc.php');
    }

    function pathToTree($array)
    {
        $tree = array();
        foreach ($array AS $item) {
            $pathIds = explode("/", ltrim($item["EVENT_NAME"], "/") . '/' . $item["ID"]);
            $current = &$tree;
            foreach ($pathIds AS $id) {
                if (!isset($current["CHILDS"][$id])) $current["CHILDS"][$id] = array();
                $current = &$current["CHILDS"][$id];
                if ($id == $item["ID"]) {
                    $current = $item;
                }
            }
        }
        //print_r($tree);exit;
        return ($this->childsToArray($tree['CHILDS']));
        //return $tree["CHILDS"];
    }

    function childsToArray($items)
    {
        $res = array();
        foreach ($items as $k => $v) {
            if (!$v['EVENT_NAME']) {
                $v['TITLE'] = $k;
            } else {
                $tmp = explode('/', $v['EVENT_NAME']);
                $v['TITLE'] = $tmp[count($tmp) - 1];
                $v['TITLE'] = $v['EVENT_NAME'];
            }
            if (isset($v['CHILDS'])) {
                $items = $this->childsToArray($v['CHILDS']);
                if (count($items) == 1) {
                    $v = $items[0];
                } else {
                    $v['ITEMS'] = $items;
                }
                unset($v['CHILDS']);
            }
            $res[] = $v;
        }
        return $res;
    }

    /**
     * events edit/add
     *
     * @access public
     */
    function edit_events(&$out, $id)
    {
        require(DIR_MODULES . $this->name . '/events_edit.inc.php');
    }

    /**
     * events delete record
     *
     * @access public
     */
    function delete_events($id)
    {
        $rec = SQLSelectOne("SELECT * FROM events WHERE ID='$id'");
        // some action for related tables
        SQLExec("DELETE FROM events_params WHERE EVENT_ID=" . $rec['ID']);
        SQLExec("DELETE FROM events WHERE ID='" . $rec['ID'] . "'");
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
        SQLExec("UPDATE project_modules SET HIDDEN=0 WHERE NAME LIKE '" . $this->name . "'");
    }

    /**
     * Uninstall
     *
     * Module uninstall routine
     *
     * @access public
     */
    function uninstall()
    {
        SQLDropTable('events');
        parent::uninstall();
    }

    /**
     * dbInstall
     *
     * Database installation routine
     *
     * @access private
     */
    function dbInstall($data)
    {
        /*
        events - Events
        */
        $data = <<<EOD

 events: ID int(10) unsigned NOT NULL auto_increment
 events: EVENT_NAME varchar(255) NOT NULL DEFAULT ''
 events: EVENT_TYPE char(10) NOT NULL DEFAULT ''
 events: TERMINAL_FROM varchar(255) NOT NULL DEFAULT ''
 events: TERMINAL_TO varchar(255) NOT NULL DEFAULT ''
 events: USER_FROM varchar(255) NOT NULL DEFAULT ''
 events: USER_TO varchar(255) NOT NULL DEFAULT ''
 events: WINDOW varchar(255) NOT NULL DEFAULT ''
 events: DETAILS text
 events: ADDED datetime
 events: EXPIRE datetime
 events: PROCESSED int(3) NOT NULL DEFAULT '0'
 events: DESCRIPTION varchar(255) NOT NULL DEFAULT ''
 
 events_params: ID int(10) unsigned NOT NULL auto_increment
 events_params: EVENT_ID int(10) unsigned NOT NULL DEFAULT '0'
 events_params: TITLE varchar(255) NOT NULL DEFAULT ''  
 events_params: VALUE varchar(255) NOT NULL DEFAULT ''
 events_params: UPDATED datetime
 events_params: LINKED_OBJECT varchar(255) NOT NULL DEFAULT '' 
 events_params: LINKED_PROPERTY varchar(255) NOT NULL DEFAULT '' 
 events_params: LINKED_METHOD varchar(255) NOT NULL DEFAULT '' 
 
EOD;
        parent::dbInstall($data);
    }
// --------------------------------------------------------------------
}

/*
*
* TW9kdWxlIGNyZWF0ZWQgTWFyIDI3LCAyMDA5IHVzaW5nIFNlcmdlIEouIHdpemFyZCAoQWN0aXZlVW5pdCBJbmMgd3d3LmFjdGl2ZXVuaXQuY29tKQ==
*
*/
?>
