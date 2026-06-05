<?php
/**
 * Market
 *
 * Market
 *
 * @package project
 * @author Serge J. <sergejey@gmail.com>
 * @copyright https://majordomohome.com/ (c)
 * @version 0.1 (wizard, 14:01:08 [Jan 11, 2014])
 */
//
//
class market extends module
{
    /**
     * market
     *
     * Module class constructor
     *
     * @access private
     */
    function __construct()
    {
        $this->name = "market";
        $this->title = "<#LANG_MODULE_MARKET#>";
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
    function saveParams($data = 0)
    {
        $p = array();
        if (isset($this->id)) {
            $p["id"] = $this->id;
        }
        if (isset($this->view_mode)) {
            $p["view_mode"] = $this->view_mode;
        }
        if (isset($this->edit_mode)) {
            $p["edit_mode"] = $this->edit_mode;
        }
        if (isset($this->tab)) {
            $p["tab"] = $this->tab;
        }
        return parent::saveParams($p);
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
        if ($this->action == 'admin') {
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
     * Title
     *
     * Description
     *
     * @access public
     */
    function checkPlugins(&$out)
    {

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
        $name = gr('name');
        if ($this->action == 'admin') {
            $mode = gr('mode');
            if (!$this->mode && $mode) {
                $this->mode = $mode;
            }
        }

        $op = gr('op');

        $this->can_be_updated = array();
        $this->can_be_updated_new = array();
        $this->have_updates = array();


        $err_msg = gr('err_msg');
        if ($err_msg) {
            $out['ERR_MSG'] = $err_msg;
        }
        $ok_msg = gr('ok_msg');
        if ($ok_msg) {
            $out['OK_MSG'] = $ok_msg;
        }

        if (is_dir(ROOT . 'cms/saverestore/temp')) {
            $out['CLEAR_FIRST'] = 1;
        }

        if ($this->mode == 'install_multiple') {
            $this->updateAll($this->selected_plugins);
        }

        if ($this->mode == 'save_custom_repository' && $name) {
            $this->saveCustomRepositoryUrl($name, gr('custom_url'));
        }


        if ($this->mode == 'update_all') {
            $this->updateAll($this->can_be_updated);
        }

        if ($this->mode == 'update_new') {
            $this->updateAll($this->can_be_updated_new);
        }

        if ($this->mode == 'install' && $this->url) {
            $this->getLatest($out, $this->url, $name, $this->version);
        }

        if ($this->mode == 'upload') {
            $this->upload($out);
        }

        if ($this->mode == 'uninstall' && $name) {
            $this->uninstallPlugin($name);
        }

        if ($this->mode == 'clear') {
            $this->removeTree(ROOT . 'cms/saverestore/temp');
            $this->redirect("?err_msg=" . urlencode($err_msg) . "&ok_msg=" . urlencode($ok_msg));
        }


        if ($this->mode == 'upload') {
            global $file;
            global $file_name;
            if (is_file($file) && $file != '') {
                //echo "Moving $file to ".ROOT.'cms/saverestore/'.$file_name;exit;
                $file_name = str_replace('.tar.gz', '.tgz', $file_name);
                copy($file, ROOT . 'cms/saverestore/' . $file_name);
                $this->redirect("?mode=iframe&mode2=uploaded&name=" . urlencode($file_name));
            } else {
                $this->redirect("?");
            }
        }

        if ($this->mode == 'iframe') {
            $mode2 = gr('mode2');
            $name = gr('name');
            $value = gr('value');

            $link = gr('link');
            $out['LINK'] = $link;
            $out['LINK_URL'] = urlencode($link);
            $out['INSTALL_URL'] = urlencode(gr('url'));
            $out['VERSION_URL'] = urlencode(gr('version'));
            $out['REPO_URL'] = urlencode(gr('repo_url'));

            global $names;

            if (isset($names) && is_array($names)) {
                $out['NAMES'] = urlencode(implode(',', $names));
            }
            $out['NAME'] = urlencode($name);

            $out['MODE2'] = $mode2;

            if ($mode2 == 'dontupdate' && $name) {
                if (!$value) {
                    $this->redirect(SERVER_URL . "/panel/market.html");
                }
                $this->dontupdate($name, $value);
            }

            return;
        }

        if ($this->ajax && $op == 'didyouknow') {
            $result = $this->marketRequest('op=didyouknow', 7 * 24 * 60 * 60);
            $data = json_decode($result, true);
            if ($data['BODY']) {
                echo nl2br(htmlspecialchars($data['BODY']));
                if ($data['LINK'] != '') {
                    echo "<br/><a href='" . $data['LINK'] . "' target='_blank'>" . LANG_DETAILS . "</a>";
                }
            }
            exit;
        }

        if ($this->ajax && $op == 'readNoty' && !empty($this->id)) {
            echo $this->readnotification($this->id);
            exit;
        }

        if ($this->ajax && $op == 'news') {
            $result = $this->marketRequest('op=news', 7 * 24 * 60 * 60);
            $data = json_decode($result, true);
            //echo json_encode($data);
            if (is_array($data)) {
                $total = count($data);
                echo '<ul class="list-group">';
                for ($i = 0; $i < 7; $i++) {
                    if ($i % 2 == 0) {
                        $bgColor = 'strip';
                    } else {
                        $bgColor = '';
                    }

                    if (substr($data[$i]['LINK'], 0, 39) == 'https://connect.smartliving.ru/profile/') {
                        $postType = '<i>Блог</i> <i class="glyphicon glyphicon-arrow-right" style="color: darkgray;font-size: 10pt;"></i>';
                    } else {
                        $postType = '<i>Новость</i> <i class="glyphicon glyphicon-arrow-right" style="color: darkgray;font-size: 10pt;"></i>';
                    }

                    if (isset($data[$i]['ADDED_TM']) && time() - 950400 <= $data[$i]['ADDED_TM']) {
                        $actualNews = 'background-color: #dff0d8;';
                        $actualNews_Label = '<span class="label label-success" style="margin-right: 10px;">New</span>';
                        $bgColor = '';
                    } else {
                        $actualNews = '';
                        $actualNews_Label = '';
                    }

                    if ($data[$i]['LINK'] != '') {
                        $linkDetail = "<a href='" . $data[$i]['LINK'] . "' target='_blank'>Читать полностью...</a>";
                    } else {
                        $linkDetail = '';
                    }

                    $addLinks = preg_replace('/(https?:\/\/[\w\d\-\/\.\?&=#]+)/', '<a href="$1" target=_blank>$1</a>', $data[$i]['BODY']);
                    if ($addLinks) {
                        $body = $addLinks;
                    } else {
                        $body = htmlspecialchars($data[$i]['BODY']);
                    }

                    echo '<li class="list-group-item ' . $bgColor . '" style="margin-bottom: 5px;' . $actualNews . '">';
                    if (isset($data[$i]['ADDED_TM'])) echo '<span class="badge">' . date('d.m.Y H:i:s', $data[$i]['ADDED_TM']) . '</span>';
                    echo '<div onclick="$(\'#news_title_' . $i . '\').toggle(\'slow\');" style="cursor:pointer;">' . $actualNews_Label . $postType . ' ' . htmlspecialchars($data[$i]['TITLE']) . '</div>';
                    echo '<div class="fullTextNewsClass" id="news_title_' . $i . '" style="display: none;margin-top: 10px;padding-top: 10px;border-top: 1px solid lightgray;"><blockquote style="border-left: 5px solid #4d96d3;">' . $body . ' ' . $linkDetail . '</blockquote></div>';
                    echo '</li>';

                }
                echo '</ul>';
            }
            exit;
        }

        if ($op == 'details') {
            include_once DIR_MODULES . 'market/details.inc.php';
            return;
        }

        if ($op == '') {
            $result = $this->marketRequest('op=categories', 120);
            $data = json_decode($result, true);
            if (SETTINGS_SITE_LANGUAGE == 'ru') {
                $title_field = 'CATEGORY_RU';
            } else {
                $title_field = 'CATEGORY_EN';
            }
            if (is_array($data[0])) {
                foreach ($data as $item) {
                    if (defined('LANG_MARKET_CATEGORY_' . strtoupper($item['CATEGORY_SYSTEM_NAME']))) {
                        $category_title = constant('LANG_MARKET_CATEGORY_' . strtoupper($item['CATEGORY_SYSTEM_NAME']));
                    } else {
                        $category_title = $item[$title_field];
                    }
                    $out['CATEGORIES'][] = array('ID' => $item['ID'], 'TITLE' => $category_title);
                }
            } else {
                $out['CATEGORIES'] = array();
            }
            array_unshift($out['CATEGORIES'], array('ID' => 'owned', 'TITLE' => LANG_MARKET_CATEGORY_OWNED));
            array_unshift($out['CATEGORIES'], array('ID' => 'updates', 'TITLE' => LANG_MARKET_CATEGORY_HAVE_UPDATES));
            array_unshift($out['CATEGORIES'], array('ID' => 'installed', 'TITLE' => LANG_MARKET_CATEGORY_INSTALLED));
            $out['CATEGORIES'][] = array('ID' => 'custom', 'TITLE' => 'Custom');
            return;
        }

        if (isset($this->category_id)) {
            $category_id = $this->category_id;
        } else {
            $category_id = gr('category_id');
        }
        $search = gr('search');

        $plugins = array();
        $missing = array();

        $data = new stdClass();
        if ($category_id == 'owned') {
            $params = 'own=1';
        } elseif ($category_id == 'all') {
            $params = 'all=1';
        } elseif ($category_id == 'custom') {
            $data->PLUGINS = array();
            $added_plugins = SQLSelect("SELECT MODULE_NAME FROM plugins");
            $modules_list = array_map('current', $added_plugins);
            $seen = array();
            $params = '';
            foreach ($modules_list as $module) {
                if ($module == 'control_modules') continue;
                if ($module == 'control_access') continue;
                if (!$seen[$module]) {
                    $params .= '&c[]=' . urlencode($module);
                }
                $seen[$module] = 1;
            }
        } elseif ($search) {
            $params = 'search=' . urlencode($search);
        } elseif (is_numeric($category_id)) {
            $params = 'category_id=' . $category_id;
        } else {
            //installed
            $modules_in_db = SQLSelect("SELECT NAME FROM project_modules");
            foreach ($modules_in_db as $module_in_db) {
                if (!is_dir(DIR_MODULES . $module_in_db['NAME'])) {
                    $missing[$module_in_db['NAME']] = 1;
                }
            }
            $modules_list = array_map('current', $modules_in_db);
            $seen = array();
            $params = '';
            foreach ($modules_list as $module) {
                if ($module == 'control_modules') continue;
                if ($module == 'control_access') continue;
                if (!isset($seen[$module])) {
                    $params .= '&m[]=' . urlencode($module);
                }
                $seen[$module] = 1;
            }
        }

        if ($params) {
            $result = $this->marketRequest($params);
            $data = json_decode($result);
        }

        if (!isset($data->PLUGINS) || !is_array($data->PLUGINS)) {
            $out['ERR'] = 1;
        } else {
            $this->can_be_updated = array();
            $this->can_be_updated_new = array();
            $plugin_names_seen = array();
            $total = count($data->PLUGINS);
            for ($i = 0; $i < $total; $i++) {
                $rec = (array)$data->PLUGINS[$i];
                $plugin_rec = SQLSelectOne("SELECT * FROM plugins WHERE MODULE_NAME LIKE '" . DBSafe($rec['MODULE_NAME']) . "'");
                if (is_dir(ROOT . 'modules/' . $rec['MODULE_NAME']) || (isset($plugin_rec['ID']) && isset($plugin_rec['IS_INSTALLED']) && $plugin_rec['IS_INSTALLED'])) {
                    $rec['EXISTS'] = 1;
                    if ($plugin_rec['ID']) {
                        $rec['INSTALLED_VERSION'] = $plugin_rec['CURRENT_VERSION'];
                    }
                    $ignore_rec = SQLSelectOne("SELECT * FROM ignore_updates WHERE `NAME` LIKE '" . DBSafe($rec['MODULE_NAME']) . "'");
                    if (isset($ignore_rec['ID'])) {
                        $rec['IGNORE_UPDATE'] = 1;
                    }
                }

                if (!$rec['REPOSITORY_URL']) {
                    $rec['REPOSITORY_URL'] = 'https://connect.smartliving.ru/market/?op=download&name=' . urlencode($rec['MODULE_NAME']) . "&serial=" . urlencode(gg('Serial'));
                }
                $rec = $this->applyCustomRepositoryUrl($rec);
                if ($this->shouldSkipPaidMarketPlugin($rec)) {
                    continue;
                }
                $rec = $this->applyRepositoryVersionMetadata($rec, (isset($rec['EXISTS']) || $rec['MODULE_NAME'] == $name));
                $rec['MODULE_NAME_ENCODED'] = urlencode($rec['MODULE_NAME']);
                $rec['REPOSITORY_URL_ENCODED'] = urlencode($rec['REPOSITORY_URL']);
                $rec['LATEST_VERSION_ENCODED'] = urlencode($rec['LATEST_VERSION']);
                if ($rec['MODULE_NAME'] == $name) {
                    $this->url = $rec['REPOSITORY_URL'];
                    $this->version = $rec['LATEST_VERSION'];
                }
                $plugin_names_seen[$rec['MODULE_NAME']] = 1;

                if ((isset($rec['EXISTS']) && !isset($rec['IGNORE_UPDATE'])) || isset($missing[$rec['MODULE_NAME']])) {
                    $this->can_be_updated[] = array('NAME' => $rec['MODULE_NAME'], 'URL' => $rec['REPOSITORY_URL'], 'VERSION' => $rec['LATEST_VERSION']);
                }

                if (isset($rec['EXISTS']) && $rec['INSTALLED_VERSION'] != $rec['LATEST_VERSION'] && $rec['LATEST_VERSION'] != '') {
                    $this->have_updates[] = $rec['MODULE_NAME'];
                    $this->can_be_updated_new[] = array('NAME' => $rec['MODULE_NAME'], 'URL' => $rec['REPOSITORY_URL'], 'VERSION' => $rec['LATEST_VERSION']);
                } elseif ($category_id == 'updates') {
                    continue;
                }
                $plugins[] = $rec;
            }

            $local_custom_plugins = $this->getLocalCustomRepositoryPlugins($category_id, $search, $plugin_names_seen);
            if (is_array($local_custom_plugins) && count($local_custom_plugins) > 0) {
                foreach ($local_custom_plugins as $rec) {
                    if ((isset($rec['EXISTS']) && !isset($rec['IGNORE_UPDATE'])) || isset($missing[$rec['MODULE_NAME']])) {
                        $this->can_be_updated[] = array('NAME' => $rec['MODULE_NAME'], 'URL' => $rec['REPOSITORY_URL'], 'VERSION' => $rec['LATEST_VERSION']);
                    }
                    if (isset($rec['EXISTS']) && $rec['INSTALLED_VERSION'] != $rec['LATEST_VERSION'] && $rec['LATEST_VERSION'] != '') {
                        $this->have_updates[] = $rec['MODULE_NAME'];
                        $this->can_be_updated_new[] = array('NAME' => $rec['MODULE_NAME'], 'URL' => $rec['REPOSITORY_URL'], 'VERSION' => $rec['LATEST_VERSION']);
                    } elseif ($category_id == 'updates') {
                        continue;
                    }
                    $plugins[] = $rec;
                }
            }

            if ($this->ajax && $_GET['op'] == 'check_updates') {
                $total = count($this->have_updates);
                if ($total > 0) {
                    echo json_encode(array('status' => '1', 'howUpdate' => $total));
                } else {
                    echo json_encode(array('status' => '0'));
                }
                exit;
            }
        }


        if (is_array($plugins) && count($plugins) > 0) {
            usort($plugins, function ($a, $b) {
                return strcmp($a['TITLE'], $b['TITLE']);
            });
            $out['PLUGINS'] = $plugins;
        }

        if ($this->ajax) {
            $p = new parser(DIR_TEMPLATES . $this->name . "/list.html", $out, $this);
            echo $p->result;
            exit;
        }

    }

    function marketRequest($details = '', $cache_timeout = 0)
    {
        $serial = getSystemSerial();
        if (IsWindowsOS()) {
            $os = 'Windows';
        } else {
            $os = trim(exec("uname -a"));
            if (!$os) {
                $os = trim(exec("sudo uname -a"));
                if (!$os) {
                    $os = 'Linux';
                }
            }
        }
        if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
            $locale = $_SERVER['HTTP_ACCEPT_LANGUAGE'];
        } else {
            $locale = '';
        }
        $data_url = 'https://connect.smartliving.ru/market/?lang=' . SETTINGS_SITE_LANGUAGE . "&serial=" . urlencode($serial) . "&locale=" . urlencode($locale) . "&os=" . urlencode($os) . "&" . $details;

        return getURL($data_url, $cache_timeout);
    }

    function saveCustomRepositoryUrl($name, $url)
    {
        $name = trim($name);
        $url = trim((string)$url);

        if ($name == '') {
            $this->redirect("?");
        }

        if ($url != '') {
            $url = $this->normalizeCustomRepositoryUrl($url);
            if (!$url) {
                $this->redirect("?err_msg=" . urlencode("Invalid custom repository URL"));
            }
        }

        $this->storeCustomRepositoryUrl($name, $url);

        $msg = $url != '' ? "Custom repository URL saved" : "Custom repository URL cleared";
        $this->redirect("?ok_msg=" . urlencode($msg));
    }

    function storeCustomRepositoryUrl($name, $url)
    {
        $name = trim((string)$name);
        if ($name == '') {
            return false;
        }
        $rec = SQLSelectOne("SELECT * FROM plugins WHERE MODULE_NAME LIKE '" . DBSafe($name) . "'");
        $rec['MODULE_NAME'] = $name;
        $rec['CUSTOM_REPOSITORY_URL'] = trim((string)$url);
        if (isset($rec['ID']) && $rec['ID']) {
            SQLUpdate('plugins', $rec);
        } else {
            SQLInsert('plugins', $rec);
        }
        return true;
    }

    function applyCustomRepositoryUrl($rec)
    {
        $custom_url = $this->getCustomRepositoryUrl($rec['MODULE_NAME']);
        if ($custom_url != '') {
            $rec['CUSTOM_REPOSITORY_URL'] = $custom_url;
            $rec['CUSTOM_REPOSITORY_URL_BASE64'] = base64_encode($custom_url);
            $rec['REPOSITORY_URL'] = $custom_url;
            $rec['CUSTOM_REPOSITORY_ACTIVE'] = 1;
        } else {
            $rec['CUSTOM_REPOSITORY_URL'] = '';
            $rec['CUSTOM_REPOSITORY_URL_BASE64'] = '';
        }
        return $rec;
    }

    function shouldSkipPaidMarketPlugin($rec)
    {
        if (!empty($rec['CUSTOM_REPOSITORY_ACTIVE'])) {
            return false;
        }
        $price = isset($rec['PRICE']) ? trim((string)$rec['PRICE']) : '';
        if ($price != '') {
            return true;
        }
        if (isset($rec['CAN_DOWNLOAD']) && (string)$rec['CAN_DOWNLOAD'] !== '1') {
            return true;
        }
        return false;
    }

    function getLocalCustomRepositoryPlugins($category_id, $search = '', $plugin_names_seen = array())
    {
        $result = array();

        if (!($search != '' || in_array($category_id, array('', 'installed', 'custom', 'updates')))) {
            return $result;
        }

        $custom_rows = SQLSelect("SELECT MODULE_NAME, CURRENT_VERSION, CUSTOM_REPOSITORY_URL, IS_INSTALLED FROM plugins WHERE CUSTOM_REPOSITORY_URL != ''");
        if (!is_array($custom_rows) || !count($custom_rows)) {
            return $result;
        }

        foreach ($custom_rows as $custom_row) {
            $module_name = trim($custom_row['MODULE_NAME']);
            if ($module_name == '' || isset($plugin_names_seen[$module_name])) {
                continue;
            }

            if ($search != '' && stripos($module_name, $search) === false) {
                continue;
            }

            $module_rec = SQLSelectOne("SELECT NAME, TITLE FROM project_modules WHERE NAME LIKE '" . DBSafe($module_name) . "'");
            $title = isset($module_rec['TITLE']) && $module_rec['TITLE'] != '' ? $module_rec['TITLE'] : $module_name;

            $rec = array(
                'MODULE_NAME' => $module_name,
                'TITLE' => $title,
                'DESCRIPTION_RU' => 'Локальный модуль с пользовательским Git/архив репозиторием.',
                'DESCRIPTION_EN' => 'Local module with custom Git/archive repository.',
                'AUTHOR' => '',
                'AUTHOR_URL' => '',
                'URL' => '',
                'CAN_DOWNLOAD' => '1',
                'EXISTS' => 1,
                'INSTALLED_VERSION' => isset($custom_row['CURRENT_VERSION']) ? $custom_row['CURRENT_VERSION'] : '',
                'REPOSITORY_URL' => $custom_row['CUSTOM_REPOSITORY_URL'],
                'LATEST_VERSION' => isset($custom_row['CURRENT_VERSION']) ? $custom_row['CURRENT_VERSION'] : '',
                'LATEST_VERSION_COMMENT' => '',
                'LATEST_VERSION_URL' => '',
                'CUSTOM_REPOSITORY_ACTIVE' => 1,
                'CUSTOM_REPOSITORY_URL' => $custom_row['CUSTOM_REPOSITORY_URL'],
                'CUSTOM_REPOSITORY_URL_BASE64' => base64_encode($custom_row['CUSTOM_REPOSITORY_URL'])
            );

            $ignore_rec = SQLSelectOne("SELECT * FROM ignore_updates WHERE `NAME` LIKE '" . DBSafe($module_name) . "'");
            if (isset($ignore_rec['ID'])) {
                $rec['IGNORE_UPDATE'] = 1;
            }

            $rec = $this->applyRepositoryVersionMetadata($rec, true);
            $rec['MODULE_NAME_ENCODED'] = urlencode($rec['MODULE_NAME']);
            $rec['REPOSITORY_URL_ENCODED'] = urlencode($rec['REPOSITORY_URL']);
            $rec['LATEST_VERSION_ENCODED'] = urlencode($rec['LATEST_VERSION']);
            $result[] = $rec;
        }

        return $result;
    }

    function applyRepositoryVersionMetadata($rec, $allow_remote_lookup = true)
    {
        if (!$allow_remote_lookup || empty($rec['REPOSITORY_URL'])) {
            return $rec;
        }

        $github_info = $this->getGithubRepositoryInfo($rec['REPOSITORY_URL']);
        if (!$github_info) {
            return $rec;
        }

        $latest_item = $this->fetchGithubLatestCommit($github_info['feed_url']);
        if (!$latest_item) {
            return $rec;
        }

        $rec['REPOSITORY_BRANCH'] = $github_info['ref'];
        $rec['LATEST_VERSION'] = $latest_item['version'];
        $rec['LATEST_VERSION_COMMENT'] = $latest_item['comment'];
        $rec['LATEST_VERSION_URL'] = $latest_item['url'];
        $rec['LATEST_VERSION_DATE'] = $latest_item['updated'];
        return $rec;
    }

    function getGithubRepositoryInfo($url)
    {
        $url = trim((string)$url);
        if ($url == '') {
            return false;
        }

        if (preg_match('/^https:\/\/github\.com\/([^\/]+)\/([^\/]+)\/archive\/([^\/?#]+)\.(tar\.gz|tgz)$/is', $url, $m)) {
            return array(
                'owner' => $m[1],
                'repo' => $m[2],
                'ref' => $m[3],
                'feed_url' => 'https://github.com/' . $m[1] . '/' . $m[2] . '/commits/' . $m[3] . '.atom'
            );
        }

        if (preg_match('/^https:\/\/github\.com\/([^\/]+)\/([^\/]+)\/archive\/refs\/heads\/([^\/?#]+)\.tar\.gz$/is', $url, $m)) {
            return array(
                'owner' => $m[1],
                'repo' => $m[2],
                'ref' => $m[3],
                'feed_url' => 'https://github.com/' . $m[1] . '/' . $m[2] . '/commits/' . $m[3] . '.atom'
            );
        }

        if (preg_match('/^https:\/\/github\.com\/([^\/]+)\/([^\/]+)\/archive\/refs\/tags\/([^\/?#]+)\.tar\.gz$/is', $url, $m)) {
            return array(
                'owner' => $m[1],
                'repo' => $m[2],
                'ref' => $m[3],
                'feed_url' => 'https://github.com/' . $m[1] . '/' . $m[2] . '/commits/' . $m[3] . '.atom'
            );
        }

        return false;
    }

    function fetchGithubLatestCommit($feed_url)
    {
        $options = array(
            CURLOPT_HTTPHEADER => array('Accept: application/xml')
        );
        $github_feed = getURL($feed_url, 5 * 60, '', '', false, $options);
        if ($github_feed == '') {
            return false;
        }
        $tmp = GetXMLTree($github_feed);
        if (!is_array($tmp)) {
            return false;
        }
        $items_data = XMLTreeToArray($tmp);
        $items = isset($items_data['feed']['entry']) ? $items_data['feed']['entry'] : false;
        if (!is_array($items) || !isset($items[0])) {
            return false;
        }

        $latest_item = $items[0];
        $commit_url = isset($latest_item['link']['href']) ? $latest_item['link']['href'] : '';
        $commit_sha = '';
        if ($commit_url != '' && preg_match('/\/commit\/([a-f0-9]+)/i', $commit_url, $m)) {
            $commit_sha = strtolower($m[1]);
        }
        if ($commit_sha == '' && isset($latest_item['id']['textvalue']) && preg_match('/Commit\/([a-f0-9]+)/i', $latest_item['id']['textvalue'], $m)) {
            $commit_sha = strtolower($m[1]);
        }
        if ($commit_sha == '') {
            return false;
        }

        $updated = isset($latest_item['updated']['textvalue']) ? strtotime($latest_item['updated']['textvalue']) : 0;
        $comment = isset($latest_item['title']['textvalue']) ? trim($latest_item['title']['textvalue']) : '';
        if ($updated) {
            $comment = trim($comment . ' [' . date('Y-m-d H:i:s', $updated) . ']');
        }

        return array(
            'version' => $commit_sha,
            'comment' => $comment,
            'url' => $commit_url,
            'updated' => $updated ? date('Y-m-d H:i:s', $updated) : ''
        );
    }

    function getRepositoryLatestVersion($url)
    {
        $github_info = $this->getGithubRepositoryInfo($url);
        if ($github_info) {
            $latest_item = $this->fetchGithubLatestCommit($github_info['feed_url']);
            if ($latest_item && !empty($latest_item['version'])) {
                return $latest_item['version'];
            }
        }
        return 'manual-' . date('YmdHis');
    }

    function getCustomRepositoryUrl($name)
    {
        $rec = SQLSelectOne("SELECT CUSTOM_REPOSITORY_URL FROM plugins WHERE MODULE_NAME LIKE '" . DBSafe($name) . "'");
        if (isset($rec['CUSTOM_REPOSITORY_URL'])) {
            return trim($rec['CUSTOM_REPOSITORY_URL']);
        }
        return '';
    }

    function normalizeCustomRepositoryUrl($url)
    {
        $url = trim($url);
        if (!preg_match('/^https?:\/\//is', $url)) {
            return '';
        }

        if (preg_match('/\.(tar\.gz|tgz)(\?.*)?$/is', $url)) {
            return $url;
        }

        if (preg_match('/^https:\/\/github\.com\/([^\/]+)\/([^\/]+)\/tree\/([^\/?#]+)\/?$/is', $url, $m)) {
            return 'https://github.com/' . $m[1] . '/' . $m[2] . '/archive/' . $m[3] . '.tar.gz';
        }

        if (preg_match('/^https:\/\/github\.com\/([^\/]+)\/([^\/]+)\/commit\/([^\/?#]+)\/?$/is', $url, $m)) {
            return 'https://github.com/' . $m[1] . '/' . $m[2] . '/archive/' . $m[3] . '.tar.gz';
        }

        if (preg_match('/^https:\/\/github\.com\/([^\/]+)\/([^\/]+)\/releases\/tag\/([^\/?#]+)\/?$/is', $url, $m)) {
            return 'https://github.com/' . $m[1] . '/' . $m[2] . '/archive/' . $m[3] . '.tar.gz';
        }

        if (preg_match('/^https:\/\/github\.com\/([^\/]+)\/([^\/?#]+?)(\.git)?\/?$/is', $url, $m)) {
            return 'https://github.com/' . $m[1] . '/' . $m[2] . '/archive/master.tar.gz';
        }

        return $url;
    }


    /**
     * Title
     *
     * Description
     *
     * @access public
     */
    function updateAll($can_be_updated, $frame = 0)
    {

        //$this->redirect("?mode=install&name=".$can_be_updated[0]."&list=".urlencode(implode(',', $can_be_updated)));
        set_time_limit(0);
        if (!is_dir(ROOT . 'cms/saverestore')) {
            umask(0);
            mkdir(ROOT . 'cms/saverestore', 0777);
        }

        if (!is_dir(ROOT . 'cms/saverestore/temp')) {
            umask(0);
            mkdir(ROOT . 'cms/saverestore/temp', 0777);
        }

        if (is_array($can_be_updated)) {
            foreach ($can_be_updated as $k => $v) {

                //$this->getLatest($out, $v['URL'], $v['NAME'], $v['VERSION']);
                $name = $v['NAME'];
                $version = $v['VERSION'];
                $url = $v['URL'];


                $filename = ROOT . 'cms/saverestore/' . $name . '.tgz';
                if (file_exists($filename)) {
                    unlink($filename);
                }
                $filename2 = ROOT . 'cms/saverestore/' . $name . '.tar';
                if (file_exists($filename2)) {
                    unlink($filename2);
                }

                if (!isset($url) || !$url) {
                    if ($frame) {
                        $this->echonow("No download URL available for $name ($version).<br/>");
                    }
                    continue;
                }


                $filename = $this->downloadPlugin($url, $filename, $frame);
                if (file_exists($filename)) {

                    $file = basename($filename);
                    DebMes("Installing/updating plugin $name ($version)", 'market');

                    chdir(ROOT . 'cms/saverestore/temp');

                    if ($frame) {
                        $this->echonow("Unpacking '$file' ...");
                    }

                    if (IsWindowsOS()) {
                        exec(DOC_ROOT . '/gunzip ../' . $file, $output, $res);
                        $result = exec(DOC_ROOT . '/tar xvf ../' . str_replace('.tgz', '.tar', $file), $output, $res);
                    } else {
                        $cmd = 'tar xzvf ../' . $file;
                        $result = exec($cmd, $output, $res);
                    }

                    if (!$result) {
                        $this->echonow("Unpack failed!<br/>", 'red');
                        continue;
                    }

                    $x = 0;
                    $latest_dir = '';
                    $latest_file = '';
                    $dir = opendir('./');
                    while (($filec = readdir($dir)) !== false) {
                        if ($filec == '.' || $filec == '..') {
                            continue;
                        }
                        if (is_Dir($filec)) {
                            $latest_dir = $filec;
                        } elseif (is_File($filec)) {
                            $latest_file = $filec;
                        }
                        $x++;
                    }

                    if ($x == 1 && $latest_dir) {
                        $folder = '/' . $latest_dir;
                    }

                    chdir('../../');

                    DebMes("Latest folder: $latest_dir", 'market');

                    if ($latest_dir == '') {
                        if ($frame) {
                            $this->echonow("ERROR<br/>", 'red');
                        }
                        DebMes("Error extracting $file", 'market');
                        continue;
                    }

                    if ($frame) {
                        $this->echonow("OK<br/>", 'green');
                    }


                    // UPDATING FILES DIRECTLY
                    if ($frame) {
                        $this->echonow("Updating files ...");
                    }

                    $files_list = $this->installUnpacketPlugin(ROOT . 'cms/saverestore/temp' . $folder, $name);
                    if ($files_list != '') {
                        SaveFile(ROOT . 'cms/modules_installed/' . $name . '.files', $files_list);
                    }

                    if ($frame) {
                        $this->echonow("OK<br/>", 'green');
                    }
                    $rec = SQLSelectOne("SELECT * FROM plugins WHERE MODULE_NAME LIKE '" . DBSafe($name) . "'");
                    $rec['MODULE_NAME'] = $name . '';
                    $rec['CURRENT_VERSION'] = $version . '';
                    $rec['IS_INSTALLED'] = 1;
                    $rec['LATEST_UPDATE'] = date('Y-m-d H:i:s');
                    if ($rec['ID']) {
                        SQLUpdate('plugins', $rec);
                    } else {
                        SQLInsert('plugins', $rec);
                    }
                    $this->checkIfCycleRestartRequired($name);

                } else {
                    if ($frame) {
                        $this->echonow("Download failed.<br/>", 'red');
                    }
                }
            }
        }
        $this->removeTree(ROOT . 'cms/saverestore/temp', $frame);

        $source = ROOT . 'modules';
        if ($dir = @opendir($source)) {
            while (($file = readdir($dir)) !== false) {
                $installed_filename = ROOT . "cms/modules_installed/" . $file . ".installed";
                if (file_exists($installed_filename) && Is_Dir($source . "/" . $file) && ($file != '.') && ($file != '..')) {
                    unlink($installed_filename);
                }
                $errorFile = ROOT . 'cms/modules_installed/' . $file . ".error";
                if (file_exists($errorFile)) {
                    unlink($errorFile);
                }

            }
        }

        if (file_exists(ROOT . "cms/modules_installed/control_modules.installed")) {
            unlink(ROOT . "cms/modules_installed/control_modules.installed");
        }

        if ($frame) {
            return ("Updates Installed!");
        } else {
            $this->redirect("?ok_msg=" . urlencode("Updates Installed!"));
        }

    }

    function installUnpacketPlugin($folder, $plugin_name)
    {
        $out = array();
        if (is_dir($folder . '/import')) {
            if (is_dir($folder . '/import/scripts')) {
                include_once(DIR_MODULES . 'scripts/scripts.class.php');
                $scripts_module = new scripts();
                $files_to_import = scandir($folder . '/import/scripts');
                if (is_array($files_to_import)) {
                    foreach ($files_to_import as $file) {
                        $filename = $folder . '/import/scripts/' . $file;
                        if (is_file($filename)) {
                            $scripts_module->import($out, $filename);
                        }
                    }
                }
            }
            if (is_dir($folder . '/import/classes')) {
                include_once(DIR_MODULES . 'classes/classes.class.php');
                $classes_module = new classes();
                $files_to_import = scandir($folder . '/import/classes');
                if (is_array($files_to_import)) {
                    foreach ($files_to_import as $file) {
                        $filename = $folder . '/import/classes/' . $file;
                        if (is_file($filename)) {
                            $classes_module->import_classes($filename, 1);
                        }
                    }
                }
            }
            $this->removeTree($folder . '/import');
        }
        if (file_exists($folder . '/install.php')) {
            require($folder . '/install.php');
            @unlink($folder . '/install.php');
        }
        $files = $this->copyTree($folder, ROOT, 1); // restore all files
        $this->removeTree($folder);
        return $files;
    }

    /**
     * Title
     *
     * Description
     *
     * @access public
     */
    function dontupdate($name, $value)
    {
        SQLExec("UPDATE plugins SET CURRENT_VERSION = '" . DBSafe($value) . "' WHERE MODULE_NAME = '" . DBSafe($name) . "' LIMIT 1");
        $this->redirect(SERVER_URL . "/panel/market.html");
    }

    function uninstallPlugin($name, $frame = 0)
    {
        if ($frame) {
            $this->echonow("Removing module '$name' from database ... ");
        }
        SQLExec("DELETE FROM plugins WHERE MODULE_NAME LIKE '" . DBSafe($name) . "'");
        if (is_dir(ROOT . 'modules/' . $name)) {
            include_once(ROOT . 'modules/' . $name . '/' . $name . '.class.php');
            SQLExec("DELETE FROM project_modules WHERE NAME LIKE '" . DBSafe($name) . "'");
            if ($frame) {
                $this->echonow(" OK<br/>", 'green');
            }
            $code = '$plugin = new ' . $name . ';$plugin->uninstall();';
            setEvalCode($code);
            eval($code);
            setEvalCode();
            $this->removeTree(ROOT . 'modules/' . $name);
            $this->removeTree(ROOT . 'templates/' . $name);
            if ($name == 'scheduler') {
                $cycle_name = ROOT . 'scripts/cycle_schedapp.php';
            } else {
                $cycle_name = ROOT . 'scripts/cycle_' . $name . '.php';
            }
            if (file_exists($cycle_name)) {
                @unlink($cycle_name);
            }
            removeMissingSubscribers();
        }

        $files_list_filename = ROOT . 'cms/modules_installed/' . $name . '.files';
        if (file_exists($files_list_filename)) {
            $files_list = LoadFile($files_list_filename);
            $files = explode("\n", $files_list);
            $total = count($files);
            for ($i = 0; $i < $total; $i++) {
                $filename = trim($files[$i]);
                if ($filename != '' && file_exists($filename)) {
                    @unlink($filename);
                }
            }
            @unlink($files_list_filename);
        }
        $ok_msg = 'Uninstalled';
        if ($frame) {
            $this->echonow(" Plugin uninstalled!<br/>", 'green');
        }

        if (!$frame) {
            $this->redirect("?err_msg=" . urlencode($err_msg) . "&ok_msg=" . urlencode($ok_msg));
        } else {
            return $ok_msg;
        }
    }

    function downloadPlugin($url, $filename, $frame = 0)
    {
        if (file_exists($filename)) {
            unlink($filename);
        }

        DebMes("Downloading plugin from $url", 'market');
        if ($frame) {
            $this->echonow("Downloading '" . $url . "' ... ");
        }

        $f = fopen($filename, 'wb');
        if ($f == FALSE) {
            if ($frame) {
                $this->echonow("Cannot open " . $filename . " for writing", "red");
                return 0;
            } else {
                $this->redirect("?err_msg=" . urlencode("Cannot open " . $filename . " for writing"));
            }
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_TIMEOUT, 600);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, TRUE);
        curl_setopt($ch, CURLOPT_FILE, $f);

        $incoming = curl_exec($ch);
        curl_close($ch);
        @fclose($f);

        if (filesize($filename) > 0) {
            if ($frame) {
                $this->echonow("OK<br/>", 'green');
            }
        } else {
            unlink($filename);
            if ($frame) {
                $this->echonow("Failed<br/>", 'red');
            }
        }

        return $filename;
    }

    function getLatest(&$out, $url, $name, $version, $frame = 0)
    {

        set_time_limit(0);

        if (!is_dir(ROOT . 'cms/saverestore')) {
            @umask(0);
            @mkdir(ROOT . 'cms/saverestore', 0777);
        }

        $filename = ROOT . 'cms/saverestore/' . $name . '.tgz';
        if (file_exists($filename)) {
            unlink($filename);
        }
        $filename2 = ROOT . 'cms/saverestore/' . $name . '.tar';
        if (file_exists($filename2)) {
            unlink($filename2);
        }

        $filename = $this->downloadPlugin($url, $filename, $frame);

        if (file_exists($filename)) {
            $this->removeTree(ROOT . 'cms/saverestore/temp', $frame);
            if ($frame) {
                return 1;
            } else {
                global $list;
                $this->redirect("?mode=upload&restore=" . urlencode($name . '.tgz') . "&folder=" . urlencode($name) . "&name=" . urlencode($name) . "&version=" . urlencode($version) . "&list=" . urlencode($list));
            }
        } else {
            if ($frame) {
                $this->echonow("Cannot download '" . $url . "'<br/>", "red");
                return 0;
            } else {
                $this->redirect("?err_msg=" . urlencode("Cannot download " . $url));
            }
        }
    }

    function upload(&$out, $frame = 0, $custom_repository_url = '')
    {
        set_time_limit(0);
        global $restore;
        global $file;
        global $file_name;
        global $folder;
        global $name;
        global $version;

        if (!$folder)
            $folder = IsWindowsOS() ? '/.' : '/';
        else
            $folder = '/' . $folder;

        if ($restore != '') {
            $file = $restore;
        } elseif ($file != '') {
            copy($file, ROOT . 'cms/saverestore/' . $file_name);
            $file = $file_name;
        }

        if (!$name) {
            $name = $file_name;
            $name = str_replace('.tgz', '', $name);
            $name = str_replace('.tar.gz', '', $name);
            $name = str_replace('.tar', '', $name);
            $name = strtolower($name);
        }

        umask(0);
        @mkdir(ROOT . 'cms/saverestore/temp', 0777);

        if ($file != '') { // && mkdir(ROOT.'cms/saverestore/temp', 0777)
            chdir(ROOT . 'cms/saverestore/temp');

            if ($frame) {
                $this->echonow("Unpacking '$file' ... ");
            }

            if (IsWindowsOS()) {
                // for windows only
                exec(DOC_ROOT . '/gunzip ../' . $file, $output, $res);
                $result = exec(DOC_ROOT . '/tar xvf ../' . str_replace('.tgz', '.tar', $file), $output, $res);
                if (is_file('../' . str_replace('.tgz', '.tar', $file))) {
                    unlink('../' . str_replace('.tgz', '.tar', $file));
                }
            } else {
                $cmd = 'tar xzvf ../' . $file;
                $result = exec($cmd, $output, $res);
            }

            if (!$result) {
                $this->echonow("Unpack failed!", 'red');
                return false;
            }

            if ($frame) {
                $this->echonow(" OK <br/>", 'green');
            }

            $x = 0;
            $dir = opendir('./');
            while (($filec = readdir($dir)) !== false) {
                if ($filec == '.' || $filec == '..') {
                    continue;
                }
                if (is_Dir($filec)) {
                    $latest_dir = $filec;
                } elseif (is_File($filec)) {
                    $latest_file = $filec;
                }
                $x++;
            }

            if ($x == 1 && $latest_dir) {
                $folder = '/' . $latest_dir;
            }
            $validation = $this->validateModulePackage(ROOT . 'cms/saverestore/temp' . $folder);
            if (!$validation['VALID']) {
                if ($frame) {
                    $this->echonow($validation['MESSAGE'] . "<br/>", 'red');
                }
                $this->removeTree(ROOT . 'cms/saverestore/temp');
                return false;
            }
            $folder = substr($validation['INSTALL_ROOT'], strlen(ROOT . 'cms/saverestore/temp'));
            $name = $validation['MODULE_NAME'];
            @unlink(ROOT . 'cms/saverestore/temp' . $folder . '/config.php');
            @unlink(ROOT . 'cms/saverestore/temp' . $folder . '/README.md');
            chdir('../../../');
            // UPDATING FILES DIRECTLY
            if ($frame) {
                $this->echonow("Updating files ... ");
            }
            $files_list = $this->installUnpacketPlugin(ROOT . 'cms/saverestore/temp' . $folder, $name);
            if ($files_list != '') {
                SaveFile(ROOT . 'cms/modules_installed/' . $name . '.files', $files_list);
            }

            $source = ROOT . 'modules';
            if ($dir = @opendir($source)) {
                while (($file = readdir($dir)) !== false) {
                    $installed_filename = ROOT . "cms/modules_installed/" . $file . ".installed";
                    if (file_exists($installed_filename) && Is_Dir($source . "/" . $file) && ($file != '.') && ($file != '..')) {
                        @unlink($installed_filename);
                    }
                }
            }
            if (file_exists(ROOT . "cms/modules_installed/control_modules.installed")) {
                unlink(ROOT . "cms/modules_installed/control_modules.installed");
            }
            $this->checkIfCycleRestartRequired($name);

            if ($frame) {
                $this->echonow(" OK <br/>", 'green');
            }


            DebMes("Installing/updating plugin $name ($version)", 'market');

            $rec = SQLSelectOne("SELECT * FROM plugins WHERE MODULE_NAME LIKE '" . DBSafe($name) . "'");
            $rec['MODULE_NAME'] = $name . '';
            $rec['CURRENT_VERSION'] = $version . '';
            $rec['IS_INSTALLED'] = 1;
            $rec['LATEST_UPDATE'] = date('Y-m-d H:i:s');
            if ($custom_repository_url != '') {
                $rec['CUSTOM_REPOSITORY_URL'] = $custom_repository_url;
            }
            if ($rec['ID']) {
                SQLUpdate('plugins', $rec);
            } else {
                SQLInsert('plugins', $rec);
            }

            if ($frame) {
                $this->echonow("Plugin '$name' ($version) installed.<br/>", 'green');
                return "Plugin '$name' ($version) installed.";
            } else {
                $this->redirect("?mode=clear&ok_msg=" . urlencode("Updates Installed!"));
            }
        }


    }

    function validateModulePackage($folder)
    {
        $folder = rtrim($folder, '/\\');
        if (!is_dir($folder)) {
            return array('VALID' => false, 'MESSAGE' => 'Package folder not found.');
        }

        $package_root = $this->detectPackageRoot($folder);
        $modules_dir = $package_root . '/modules';
        if (!is_dir($modules_dir)) {
            return array(
                'VALID' => false,
                'MESSAGE' => 'Архив не похож на модуль MajorDoMo: не найдена папка modules/<module_name>. Ожидается пакет с modules/<module>/<module>.class.php.'
            );
        }

        $module_candidates = array();
        $dir = opendir($modules_dir);
        while (($entry = readdir($dir)) !== false) {
            if ($entry == '.' || $entry == '..') {
                continue;
            }
            $module_path = $modules_dir . '/' . $entry;
            if (!is_dir($module_path)) {
                continue;
            }
            $class_file = $module_path . '/' . $entry . '.class.php';
            if (is_file($class_file) && preg_match('/^[a-z0-9_]+$/', $entry)) {
                $module_candidates[] = $entry;
            }
        }
        closedir($dir);

        if (count($module_candidates) === 0) {
            return array(
                'VALID' => false,
                'MESSAGE' => 'В архиве не найден основной файл модуля вида modules/<module>/<module>.class.php.'
            );
        }

        if (count($module_candidates) > 1) {
            return array(
                'VALID' => false,
                'MESSAGE' => 'В архиве найдено несколько модулей. Для ручной установки поддерживается один модуль на архив.'
            );
        }

        $module_name = $module_candidates[0];
        return array(
            'VALID' => true,
            'MODULE_NAME' => $module_name,
            'INSTALL_ROOT' => $package_root
        );
    }

    function detectPackageRoot($folder)
    {
        if (is_dir($folder . '/modules')) {
            return $folder;
        }

        $entries = array();
        $dir = opendir($folder);
        while (($entry = readdir($dir)) !== false) {
            if ($entry == '.' || $entry == '..') {
                continue;
            }
            $entries[] = $entry;
        }
        closedir($dir);

        if (count($entries) === 1 && is_dir($folder . '/' . $entries[0])) {
            return $folder . '/' . $entries[0];
        }
        return $folder;
    }

    function installFromRepositoryUrl(&$out, $repo_url, $frame = 0)
    {
        $repo_url = $this->normalizeCustomRepositoryUrl($repo_url);
        if (!$repo_url) {
            if ($frame) {
                $this->echonow("Invalid repository URL<br/>", 'red');
                return false;
            }
            $this->redirect("?err_msg=" . urlencode("Invalid repository URL"));
        }

        if (!is_dir(ROOT . 'cms/saverestore')) {
            @umask(0);
            @mkdir(ROOT . 'cms/saverestore', 0777);
        }

        $detected_version = $this->getRepositoryLatestVersion($repo_url);
        $filename = ROOT . 'cms/saverestore/repository_install_' . md5($repo_url) . '.tgz';
        if (file_exists($filename)) {
            unlink($filename);
        }

        $downloaded = $this->downloadPlugin($repo_url, $filename, $frame);
        if (!file_exists($downloaded)) {
            return false;
        }

        global $restore;
        global $name;
        global $version;
        global $folder;
        $restore = basename($downloaded);
        $name = '';
        $folder = '';
        $version = $detected_version;

        return $this->upload($out, $frame, $repo_url);
    }

    function checkIfCycleRestartRequired($plugin_name)
    {
        $files_list_filename = ROOT . 'cms/modules_installed/' . $plugin_name . '.files';
        if (!file_exists($files_list_filename)) return;
        $files_list = LoadFile($files_list_filename);
        $files = explode("\n", $files_list);
        $total = count($files);
        for ($i = 0; $i < $total; $i++) {
            $filename = trim($files[$i]);
            if (preg_match('/cycle_(.+?)\.php$/', $filename, $m)) {
                $service = 'cycle_' . $m[1];
                sg($service . 'Run', '');
                sg($service . 'Control', 'restart');
            }
        }
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

    /**
     * Install
     *
     * Module installation routine
     *
     * @access private
     */
    function install($data = '')
    {
        parent::install();
    }

    /**
     * removeTree
     *
     * remove directory tree
     *
     * @access public
     */
    function removeTree($destination, $frame = 0)
    {

        $res = 1;

        if (!Is_Dir($destination)) {
            return 0; // cannot create destination path
        }

        if ($frame) {
            $this->echonow("Removing dir $destination ... ");
        }


        if ($dir = @opendir($destination)) {
            while (($file = readdir($dir)) !== false) {
                if (Is_Dir($destination . "/" . $file) && ($file != '.') && ($file != '..')) {
                    $res = $this->removeTree($destination . "/" . $file);
                } elseif (Is_File($destination . "/" . $file)) {
                    $res = @unlink($destination . "/" . $file);
                }
            }
            closedir($dir);
            $res = @rmdir($destination);
        }

        if ($frame) {
            $this->echonow("OK<br/>", "green");
        }


        return $res;
    }


    /**
     * copyTree
     *
     * Copy source directory tree to destination directory
     *
     * @access public
     */
    function copyTree($source, $destination, $over = 0, $patterns = 0)
    {


        $files_list = '';

        $source = preg_replace("#/$#", "", $source);
        $destination = preg_replace("#/$#", "", $destination);

        if (!Is_Dir($source)) {
            return ''; // cannot create destination path
        }

        if (!Is_Dir($destination)) {
            if (!mkdir($destination, 0777, true)) {
                return ''; // cannot create destination path
            }
        }


        if ($dir = @opendir($source)) {
            while (($file = readdir($dir)) !== false) {
                if (Is_Dir($source . "/" . $file) && ($file != '.') && ($file != '..')) {
                    $files_list .= $this->copyTree($source . "/" . $file, $destination . "/" . $file, $over, $patterns);
                } elseif (Is_File($source . "/" . $file) && (!file_exists($destination . "/" . $file) || $over)) {
                    if (!is_array($patterns)) {
                        $ok_to_copy = 1;
                    } else {
                        $ok_to_copy = 0;
                        $total = count($patterns);
                        for ($i = 0; $i < $total; $i++) {
                            if (preg_match('/' . $patterns[$i] . '/is', $file)) {
                                $ok_to_copy = 1;
                            }
                        }
                    }
                    if ($ok_to_copy) {
                        if (copy($source . "/" . $file, $destination . "/" . $file)) {
                            $files_list .= $destination . "/" . $file . "\n";
                        }
                    }
                }
            }
            closedir($dir);
        }
        return $files_list;
    }

    function copyFile($source, $destination)
    {
        $tmp = explode('/', $destination);
        $total = count($tmp);
        if ($total > 0) {
            $d = $tmp[0];
            for ($i = 1; $i < ($total - 1); $i++) {
                $d .= '/' . $tmp[$i];
                if (!is_dir($d)) {
                    mkdir($d);
                }
            }
        }
        return copy($source, $destination);

    }

    function copyFiles($source, $destination, $over = 0, $patterns = 0)
    {

        $res = 1;

        if (!Is_Dir($source)) {
            return 0; // cannot create destination path
        }

        if (!Is_Dir($destination)) {
            if (!mkdir($destination)) {
                return 0; // cannot create destination path
            }
        }


        if ($dir = @opendir($source)) {
            while (($file = readdir($dir)) !== false) {
                if (Is_Dir($source . "/" . $file) && ($file != '.') && ($file != '..')) {
                    //$res=$this->copyTree($source."/".$file, $destination."/".$file, $over, $patterns);
                } elseif (Is_File($source . "/" . $file) && (!file_exists($destination . "/" . $file) || $over)) {
                    if (!is_array($patterns)) {
                        $ok_to_copy = 1;
                    } else {
                        $ok_to_copy = 0;
                        $total = count($patterns);
                        for ($i = 0; $i < $total; $i++) {
                            if (preg_match('/' . $patterns[$i] . '/is', $file)) {
                                $ok_to_copy = 1;
                            }
                        }
                    }
                    if ($ok_to_copy) {
                        $res = copy($source . "/" . $file, $destination . "/" . $file);
                    }
                }
            }
            closedir($dir);
        }
        return $res;
    }

    function echonow($msg, $color = '')
    {
        DebMes(strip_tags($msg), 'market');
        if ($color) {
            echo '<font color="' . $color . '">';
        }
        echo $msg;
        if ($color) {
            echo '</font>';
        }
        echo "<script language='javascript'>window.scrollTo(0,document.body.scrollHeight);</script>";
        echo str_repeat(' ', 16 * 1024);
        flush();
        ob_flush();
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
        SQLDropTable('plugins');
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
        plugins - Plugins
        */
        $data = <<<EOD
 plugins: ID int(10) unsigned NOT NULL auto_increment
 plugins: TITLE varchar(255) NOT NULL DEFAULT ''
 plugins: MODULE_NAME varchar(255) NOT NULL DEFAULT ''
 plugins: REPOSITORY_URL char(255) NOT NULL DEFAULT ''
 plugins: CUSTOM_REPOSITORY_URL varchar(1024) NOT NULL DEFAULT ''
 plugins: AUTHOR varchar(255) NOT NULL DEFAULT ''
 plugins: SUPPORT_URL char(255) NOT NULL DEFAULT ''
 plugins: DESCRIPTION_RU text
 plugins: DESCRIPTION_EN text
 plugins: CURRENT_VERSION varchar(255) NOT NULL DEFAULT ''
 plugins: LATEST_VERSION varchar(255) NOT NULL DEFAULT ''
 plugins: IS_INSTALLED int(3) NOT NULL DEFAULT '0'
 plugins: WHATSNEW text
 plugins: LATEST_UPDATE datetime
EOD;
        parent::dbInstall($data);
    }
// --------------------------------------------------------------------
}
/*
*
* TW9kdWxlIGNyZWF0ZWQgSmFuIDExLCAyMDE0IHVzaW5nIFNlcmdlIEouIHdpemFyZCAoQWN0aXZlVW5pdCBJbmMgd3d3LmFjdGl2ZXVuaXQuY29tKQ==
*
*/
