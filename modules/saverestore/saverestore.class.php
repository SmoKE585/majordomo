<?php

class saverestore extends module
{
    var $mode;
    var $view_mode;
    var $edit_mode;
    var $ajax;

    /**
     * saverestore
     *
     * Module class constructor
     *
     * @access private
     */
    function __construct()
    {
        $this->name = "saverestore";
        $this->title = "<#LANG_MODULE_SAVERESTORE#>";
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
        if (isset ($this->single_rec) && $this->single_rec) {
            $out['SINGLE_REC'] = true;
        } else {
            $out['SINGLE_REC'] = false;
        }
        $this->data = $out;
        $p = new parser(DIR_TEMPLATES . $this->name . "/" . $this->name . ".html", $this->data, $this);
        $this->result = $p->result;
    }


    function parse_size($size)
    {
        $unit = preg_replace('/[^bkmgtpezy]/i', '', $size); // Remove the non-unit characters from the size.
        $size = preg_replace('/[^0-9\.]/', '', $size); // Remove the non-numeric characters from the size.
        if ($unit) {
            // Find the position of the unit in the ordered string which is the power of magnitude to multiply a kilobyte by.
            return round($size * pow(1024, stripos('bkmgtpezy', $unit[0])));
        } else {
            return round($size);
        }
    }

    function normalizeCommitId($commit_id)
    {
        $commit_id = trim((string)$commit_id);
        if ($commit_id == '') {
            return '';
        }
        return preg_replace('/.+Commit\//is', '', $commit_id);
    }

    function normalizeUpdateBranch($branch)
    {
        return mb_strtoupper(trim((string)$branch));
    }

    function readLatestUpdateInfo($update_url)
    {
        $result = array(
            'LATEST_ID' => '',
            'UPDATE_CURR_BRANCH' => $this->normalizeUpdateBranch($this->getUpdateBranch($update_url))
        );

        $github_feed_url = $this->getUpdateFeedURL($update_url);
        if ($github_feed_url == '') {
            return $result;
        }

        $options = array(
            CURLOPT_HTTPHEADER => array('Accept: application/xml')
        );
        $github_feed = getURL($github_feed_url, 0, '', '', false, $options);
        if ($github_feed == '') {
            return $result;
        }

        $tmp = GetXMLTree($github_feed);
        if (!is_array($tmp)) {
            return $result;
        }

        $data = XMLTreeToArray($tmp);
        if (!isset($data['feed']['entry']) || !is_array($data['feed']['entry']) || !isset($data['feed']['entry'][0])) {
            return $result;
        }

        $result['LATEST_ID'] = $this->normalizeCommitId($data['feed']['entry'][0]['id']['textvalue']);
        return $result;
    }

    function removeBundledConfigFiles($path)
    {
        if (!is_dir($path)) {
            return;
        }

        $config_file = rtrim($path, DIRECTORY_SEPARATOR . '/') . DIRECTORY_SEPARATOR . 'config.php';
        if (file_exists($config_file)) {
            @unlink($config_file);
        }
    }

    function removeBundledConnectFiles($path)
    {
        if (!is_dir($path)) {
            return;
        }

        $path = rtrim($path, DIRECTORY_SEPARATOR . '/');
        $connect_module = $path . DIRECTORY_SEPARATOR . 'modules' . DIRECTORY_SEPARATOR . 'connect';
        if (is_dir($connect_module)) {
            removeTree($connect_module);
        }

        $connect_template = $path . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'connect';
        if (is_dir($connect_template)) {
            removeTree($connect_template);
        }

        $connect_cycle = $path . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'cycle_connect.php';
        if (file_exists($connect_cycle)) {
            @unlink($connect_cycle);
        }
    }

    function cleanupLegacyConnectConfigNoise($config_file)
    {
        if (!file_exists($config_file)) {
            return;
        }

        $content = LoadFile($config_file);
        $clean_content = preg_replace('/^[ \t]*[\'"]MODULE_CONNECT[\'"][ \t]*=>[ \t]*[\'"][\'"][ \t]*,[ \t]*(?:\r?\n)?/m', '', $content);
        if ($clean_content !== $content) {
            SaveFile($config_file, $clean_content);
        }
    }

    function getSystemUpdateManifestFile()
    {
        return DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/saverestore/system_update_manifest.json';
    }

    function getSaveRestoreDirectory()
    {
        return DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/saverestore';
    }

    function getBackupStorageDirectory()
    {
        if (defined('SETTINGS_BACKUP_PATH') && SETTINGS_BACKUP_PATH != '' && is_dir(SETTINGS_BACKUP_PATH)) {
            return rtrim(SETTINGS_BACKUP_PATH, DIRECTORY_SEPARATOR . '/');
        }

        return DOC_ROOT . DIRECTORY_SEPARATOR . 'backup';
    }

    function isPathWithinRoot($path, $root)
    {
        $real_path = realpath($path);
        $real_root = realpath($root);
        if ($real_path === false || $real_root === false) {
            return false;
        }

        return $real_path === $real_root || strpos($real_path, $real_root . DIRECTORY_SEPARATOR) === 0;
    }

    function canWritePath($path)
    {
        if ($path == '') {
            return false;
        }

        if (file_exists($path)) {
            return is_writable($path);
        }

        $parent = dirname($path);
        while ($parent && $parent !== dirname($parent)) {
            if (file_exists($parent)) {
                return is_dir($parent) && is_writable($parent);
            }
            $parent = dirname($parent);
        }

        return false;
    }

    function getRelativeDocRootPath($path)
    {
        $normalized_path = str_replace('\\', '/', (string)$path);
        $normalized_root = rtrim(str_replace('\\', '/', DOC_ROOT), '/');
        if (strpos($normalized_path, $normalized_root . '/') === 0) {
            return substr($normalized_path, strlen($normalized_root) + 1);
        }
        if ($normalized_path === $normalized_root) {
            return '.';
        }
        return $normalized_path;
    }

    function isTarAvailable()
    {
        static $result = null;
        if ($result !== null) {
            return $result;
        }

        $output = array();
        $exit_code = 1;
        @exec('tar --version 2>&1', $output, $exit_code);
        $result = ($exit_code === 0);
        return $result;
    }

    function runShellCommand($command, &$output = array(), &$exit_code = 0)
    {
        $output = array();
        $exit_code = 0;
        $result = @exec($command . ' 2>&1', $output, $exit_code);
        return $result;
    }

    function extractTarArchive($archive_path, $target_dir, &$error_message = '')
    {
        $archive_path = (string)$archive_path;
        $target_dir = (string)$target_dir;
        if (!$this->isTarAvailable()) {
            $error_message = 'tar command is not available';
            return false;
        }

        $lower_archive = mb_strtolower($archive_path);
        if (preg_match('/\.(tgz|tar\.gz)$/', $lower_archive)) {
            $command = 'tar -xzf ' . escapeshellarg($archive_path) . ' -C ' . escapeshellarg($target_dir);
        } elseif (preg_match('/\.tar$/', $lower_archive)) {
            $command = 'tar -xf ' . escapeshellarg($archive_path) . ' -C ' . escapeshellarg($target_dir);
        } else {
            $error_message = 'unsupported archive format';
            return false;
        }

        $output = array();
        $exit_code = 1;
        $this->runShellCommand($command, $output, $exit_code);
        if ($exit_code !== 0) {
            $error_message = trim(implode("\n", $output));
            return false;
        }

        return true;
    }

    function createTarArchiveFromDirectory($source_dir, $archive_path, &$error_message = '')
    {
        $source_dir = rtrim((string)$source_dir, DIRECTORY_SEPARATOR . '/');
        $archive_path = (string)$archive_path;
        if (!$this->isTarAvailable()) {
            $error_message = 'tar command is not available';
            return false;
        }

        $command = 'tar -czf ' . escapeshellarg($archive_path) . ' -C ' . escapeshellarg($source_dir) . ' .';
        $output = array();
        $exit_code = 1;
        $this->runShellCommand($command, $output, $exit_code);
        if ($exit_code !== 0) {
            $error_message = trim(implode("\n", $output));
            return false;
        }

        return true;
    }

    function collectSystemUpdatePreparationIssues()
    {
        $issues = array();

        if ($this->getUpdateURL() == '') {
            $issues[] = 'Не задан URL архива обновления.';
        }
        if (!function_exists('curl_init')) {
            $issues[] = 'Расширение cURL недоступно.';
        }
        if (!function_exists('exec')) {
            $issues[] = 'Функция exec() недоступна, распаковка архива не сможет выполниться.';
        }
        if (!$this->isTarAvailable()) {
            $issues[] = 'Команда tar недоступна, распаковка и упаковка архивов не смогут выполниться.';
        }

        $paths = array(
            DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/saverestore' => 'Каталог cms/saverestore недоступен для записи.',
            DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/saverestore/temp' => 'Временный каталог cms/saverestore/temp недоступен для создания или записи.',
            DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/modules_installed' => 'Каталог cms/modules_installed недоступен для записи.',
            DOC_ROOT . DIRECTORY_SEPARATOR . 'database_backup' => 'Каталог database_backup недоступен для записи.',
        );

        foreach ($paths as $path => $message) {
            if (!$this->canWritePath($path)) {
                $issues[] = $message . ' [' . $this->getRelativeDocRootPath($path) . ']';
            }
        }

        return $issues;
    }

    function collectSystemUpdateTargetIssues($update_root, $limit = 50)
    {
        $issues = array();
        $update_root = rtrim((string)$update_root, DIRECTORY_SEPARATOR . '/');
        if ($update_root == '' || !is_dir($update_root)) {
            $issues[] = 'Не найдена распакованная директория обновления.';
            return $issues;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($update_root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            if (count($issues) >= $limit) {
                break;
            }

            if (!$item->isFile()) {
                continue;
            }

            $source_path = $item->getPathname();
            $relative_path = substr($source_path, strlen($update_root) + 1);
            $relative_path = str_replace('\\', '/', $relative_path);
            if ($this->isProtectedSystemUpdatePath($relative_path)) {
                continue;
            }

            $target_path = DOC_ROOT . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative_path);
            if (file_exists($target_path)) {
                if (!is_writable($target_path)) {
                    $issues[] = 'Нет прав на перезапись файла [' . $relative_path . ']';
                }
            } else {
                $target_dir = dirname($target_path);
                if (!$this->canWritePath($target_dir)) {
                    $issues[] = 'Нет прав на создание файла [' . $relative_path . ']';
                }
            }
        }

        return $issues;
    }

    function resolveRestoreSourcePath($restore)
    {
        $restore = trim((string)$restore);
        if ($restore == '') {
            return '';
        }

        $normalized_restore = ltrim(str_replace(array('/', '\\'), DIRECTORY_SEPARATOR, $restore), DIRECTORY_SEPARATOR);
        $roots = array($this->getSaveRestoreDirectory(), $this->getBackupStorageDirectory());
        $candidates = array($restore);

        foreach ($roots as $root) {
            if ($root != '') {
                $candidates[] = rtrim($root, DIRECTORY_SEPARATOR . '/') . DIRECTORY_SEPARATOR . $normalized_restore;
            }
        }

        foreach ($candidates as $candidate) {
            $real_candidate = realpath($candidate);
            if ($real_candidate === false) {
                continue;
            }

            foreach ($roots as $root) {
                if ($root != '' && $this->isPathWithinRoot($real_candidate, $root)) {
                    return $real_candidate;
                }
            }
        }

        return '';
    }

    function storeUploadedRestoreFile()
    {
        if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
            return array('path' => '', 'name' => '', 'error' => '');
        }

        $upload = $_FILES['file'];
        if (!isset($upload['error']) || $upload['error'] == UPLOAD_ERR_NO_FILE) {
            return array('path' => '', 'name' => '', 'error' => '');
        }

        if ($upload['error'] != UPLOAD_ERR_OK || !isset($upload['tmp_name']) || !is_uploaded_file($upload['tmp_name'])) {
            return array('path' => '', 'name' => '', 'error' => 'UPLOAD_ERROR');
        }

        $original_name = isset($upload['name']) ? basename((string)$upload['name']) : '';
        $safe_name = preg_replace('/[^A-Za-z0-9._-]+/', '_', $original_name);
        if ($safe_name == '' || $safe_name == '.' || $safe_name == '..') {
            $safe_name = 'restore_' . date('Ymd_His') . '.tgz';
        }

        $destination = $this->getSaveRestoreDirectory() . DIRECTORY_SEPARATOR . $safe_name;
        if (!move_uploaded_file($upload['tmp_name'], $destination)) {
            return array('path' => '', 'name' => '', 'error' => 'MOVE_ERROR');
        }

        return array('path' => $destination, 'name' => $safe_name, 'error' => '');
    }

    function normalizeManifestPath($path)
    {
        return str_replace('\\', '/', $path);
    }

    function isProtectedSystemUpdatePath($relative_path)
    {
        $relative_path = $this->normalizeManifestPath($relative_path);
        if ($relative_path == '' || preg_match('#(^|/)\.\.(/|$)#', $relative_path)) {
            return true;
        }
        if ($relative_path == 'config.php' || preg_match('#(^|/)config\.php$#i', $relative_path)) {
            return true;
        }
        if (preg_match('#^cms(/|$)#i', $relative_path)) {
            return true;
        }
        if (preg_match('#^(backup|database_backup)(/|$)#i', $relative_path)) {
            return true;
        }
        return false;
    }

    function getObsoleteSystemUpdatePaths()
    {
        return array(
            'directories' => array(
                'modules/dashboard',
                'modules/dateselect',
                'modules/layouts',
                'modules/myblocks',
                'modules/security_rules',
                'modules/shoutrooms',
                'modules/soundfiles',
                'modules/terminals',
                'modules/textfiles',
                'modules/thumb',
                'templates/dashboard',
                'modules/commands',
                'modules/patterns',
                'modules/plans',
                'modules/scenes',
                'modules/devices',
                'templates/dateselect',
                'templates/layouts',
                'templates/myblocks',
                'templates/security_rules',
                'templates/shoutrooms',
                'templates/soundfiles',
                'templates/terminals',
                'templates/textfiles',
                'templates/thumb',
                'templates/commands',
                'templates/patterns',
                'templates/plans',
                'templates/scenes',
                'templates/devices',
                'cms/scenes',
            ),
            'files' => array(
                'templates/scenes.html',
                'css/devices.css',
                'js/easySlider1.7.js',
                'img/modules/commands.png',
                'img/modules/patterns.png',
                'img/modules/plans.png',
                'img/modules/scenes.png',
                'img/modules/devices.png',
                'img/modules/layouts.png',
                'img/modules/terminals.png',
                'img/modules/textfiles.png',
            ),
            'patterns' => array(
                'templates/classes/views/S*.html',
            ),
        );
    }

    function getObsoleteSystemModules()
    {
        return array(
            'dashboard',
            'dateselect',
            'layouts',
            'myblocks',
            'security_rules',
            'shoutrooms',
            'soundfiles',
            'terminals',
            'textfiles',
            'thumb',
        );
    }

    function getSafeSystemUpdateTargetPath($relative_path)
    {
        $relative_path = $this->normalizeManifestPath($relative_path);
        if ($relative_path == '' || preg_match('#(^|/)\.\.(/|$)#', $relative_path)) {
            return false;
        }

        $target = DOC_ROOT . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative_path);
        $real_target = realpath($target);
        $real_root = realpath(DOC_ROOT);
        if ($real_target === false || $real_root === false) {
            return false;
        }
        if ($real_target !== $real_root && strpos($real_target, $real_root . DIRECTORY_SEPARATOR) !== 0) {
            return false;
        }

        return $real_target;
    }

    function removeObsoleteSystemUpdatePaths($iframe = 0)
    {
        $paths = $this->getObsoleteSystemUpdatePaths();

        foreach ($paths['files'] as $relative_path) {
            $real_target = $this->getSafeSystemUpdateTargetPath($relative_path);
            if ($real_target === false || !is_file($real_target)) {
                continue;
            }
            @unlink($real_target);
            DebMes('Removed obsolete system file: ' . $relative_path, 'restore');
            if ($iframe) {
                echonow('<div><i style="font-size: 7pt;" class="glyphicon glyphicon-usd"></i> Removed obsolete system file ' . htmlspecialchars($relative_path) . '</div>');
            }
        }

        foreach ($paths['patterns'] as $relative_pattern) {
            $relative_pattern = $this->normalizeManifestPath($relative_pattern);
            if ($relative_pattern == '' || preg_match('#(^|/)\.\.(/|$)#', $relative_pattern)) {
                continue;
            }
            $pattern = DOC_ROOT . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative_pattern);
            $files = glob($pattern);
            if (!is_array($files)) {
                continue;
            }
            foreach ($files as $file) {
                $relative_path = str_replace('\\', '/', substr($file, strlen(DOC_ROOT) + 1));
                $real_target = $this->getSafeSystemUpdateTargetPath($relative_path);
                if ($real_target === false || !is_file($real_target)) {
                    continue;
                }
                @unlink($real_target);
                DebMes('Removed obsolete system file: ' . $relative_path, 'restore');
                if ($iframe) {
                    echonow('<div><i style="font-size: 7pt;" class="glyphicon glyphicon-usd"></i> Removed obsolete system file ' . htmlspecialchars($relative_path) . '</div>');
                }
            }
        }

        foreach ($paths['directories'] as $relative_path) {
            $real_target = $this->getSafeSystemUpdateTargetPath($relative_path);
            if ($real_target === false || !is_dir($real_target)) {
                continue;
            }
            removeTree($real_target);
            DebMes('Removed obsolete system directory: ' . $relative_path, 'restore');
            if ($iframe) {
                echonow('<div><i style="font-size: 7pt;" class="glyphicon glyphicon-usd"></i> Removed obsolete system directory ' . htmlspecialchars($relative_path) . '</div>');
            }
        }
    }

    function buildSystemUpdateManifest($root_path, $base_path = '', &$result = array())
    {
        $root_path = rtrim($root_path, DIRECTORY_SEPARATOR . '/');
        $scan_path = $base_path == '' ? $root_path : $root_path . DIRECTORY_SEPARATOR . $base_path;
        if (!is_dir($scan_path)) {
            return $result;
        }

        $items = scandir($scan_path);
        foreach ($items as $item) {
            if ($item == '.' || $item == '..') {
                continue;
            }
            $relative_path = $base_path == '' ? $item : $base_path . '/' . $item;
            $relative_path = $this->normalizeManifestPath($relative_path);
            if ($this->isProtectedSystemUpdatePath($relative_path)) {
                continue;
            }

            $full_path = $scan_path . DIRECTORY_SEPARATOR . $item;
            if (is_dir($full_path)) {
                $this->buildSystemUpdateManifest($root_path, $relative_path, $result);
            } elseif (is_file($full_path)) {
                $result[] = $relative_path;
            }
        }

        return $result;
    }

    function loadSystemUpdateManifest()
    {
        $manifest_file = $this->getSystemUpdateManifestFile();
        if (!file_exists($manifest_file)) {
            return array();
        }

        $data = json_decode(LoadFile($manifest_file), true);
        if (!is_array($data)) {
            return array();
        }

        return $data;
    }

    function saveSystemUpdateManifest($files)
    {
        sort($files);
        SaveFile($this->getSystemUpdateManifestFile(), json_encode(array_values($files), JSON_PRETTY_PRINT));
    }

    function removeFilesDeletedFromSystemUpdate($old_manifest, $new_manifest, $iframe = 0)
    {
        if (!is_array($old_manifest) || count($old_manifest) == 0) {
            return;
        }

        $new_lookup = array_fill_keys($new_manifest, 1);
        foreach ($old_manifest as $relative_path) {
            $relative_path = $this->normalizeManifestPath($relative_path);
            if (isset($new_lookup[$relative_path]) || $this->isProtectedSystemUpdatePath($relative_path)) {
                continue;
            }

            $target = DOC_ROOT . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative_path);
            $real_target = realpath($target);
            $real_root = realpath(DOC_ROOT);
            if ($real_target === false || $real_root === false || strpos($real_target, $real_root) !== 0 || !is_file($real_target)) {
                continue;
            }

            @unlink($real_target);
            DebMes('Removed file deleted from system update: ' . $relative_path, 'restore');
            if ($iframe) {
                echonow('<div><i style="font-size: 7pt;" class="glyphicon glyphicon-usd"></i> Removed deleted system file ' . htmlspecialchars($relative_path) . '</div>');
            }
        }
    }

    function removeObsoleteModuleInstallMarkers($iframe = 0)
    {
        $modules = $this->getObsoleteSystemModules();
        foreach ($modules as $module) {
            foreach (array('.installed', '.error') as $suffix) {
                $marker = DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/modules_installed/' . $module . $suffix;
                if (!file_exists($marker)) {
                    continue;
                }
                @unlink($marker);
                DebMes('Removed obsolete module marker: cms/modules_installed/' . $module . $suffix, 'restore');
                if ($iframe) {
                    echonow('<div><i style="font-size: 7pt;" class="glyphicon glyphicon-usd"></i> Removed obsolete module marker ' . htmlspecialchars('cms/modules_installed/' . $module . $suffix) . '</div>');
                }
            }
        }
    }

    function cleanupObsoleteModuleDatabaseRows($iframe = 0)
    {
        $modules = $this->getObsoleteSystemModules();
        if (!is_array($modules) || count($modules) == 0) {
            return;
        }

        $quoted_modules = array();
        foreach ($modules as $module) {
            $quoted_modules[] = "'" . DBSafe($module) . "'";
        }
        $in_list = implode(',', $quoted_modules);

        SQLExec("DELETE FROM project_modules WHERE NAME IN (" . $in_list . ")");
        SQLExec("DELETE FROM ignore_updates WHERE NAME IN (" . $in_list . ")");

        $users = SQLSelect("SELECT ID, ACCESS FROM admin_users");
        $total_users = count($users);
        $changed_users = 0;
        for ($i = 0; $i < $total_users; $i++) {
            $access = trim((string)$users[$i]['ACCESS']);
            if ($access == '') {
                continue;
            }

            $parts = array_filter(array_map('trim', explode(',', $access)), 'strlen');
            $filtered = array();
            $changed = false;
            foreach ($parts as $part) {
                if (in_array($part, $modules, true)) {
                    $changed = true;
                    continue;
                }
                $filtered[] = $part;
            }

            if ($changed) {
                $new_access = implode(',', $filtered);
                SQLExec("UPDATE admin_users SET ACCESS='" . DBSafe($new_access) . "' WHERE ID=" . (int)$users[$i]['ID']);
                DebMes('Removed obsolete module access from admin user ID ' . (int)$users[$i]['ID'], 'restore');
                $changed_users++;
            }
        }

        if ($iframe && $changed_users > 0) {
            echonow('<div><i style="font-size: 7pt;" class="glyphicon glyphicon-usd"></i> Updated admin user access for obsolete modules</div>');
        }
    }

    function cleanupObsoleteSystemModules($iframe = 0)
    {
        $this->removeObsoleteModuleInstallMarkers($iframe);
        $this->cleanupObsoleteModuleDatabaseRows($iframe);
    }

    function backupDatabaseBeforeSystemUpdate($iframe = 0)
    {
        $backup_dir = DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/saverestore';
        if (!is_dir($backup_dir)) {
            @mkdir($backup_dir, 0777, true);
        }

        $filename = $backup_dir . DIRECTORY_SEPARATOR . 'db_before_update_' . date('Y-m-d__H-i-s') . '.sql';
        if ($iframe) {
            echonow('<div><i style="font-size: 7pt;" class="glyphicon glyphicon-usd"></i> Creating mandatory database backup...</div>');
        }

        $result = $this->backupdatabase($filename);
        if ($iframe) {
            echonow('<div><i style="font-size: 7pt;" class="glyphicon glyphicon-usd"></i> Database backup ' . ($result ? 'OK' : 'failed') . '</div>', $result ? 'green' : 'red');
        }

        return $result;
    }

    function stopCyclesBeforeSystemUpdate($iframe = 0)
    {
        setRebootRequired('system_update');
        $cycles = array('cycle_main', 'cycle_execs', 'cycle_scheduler', 'cycle_states', 'cycle_ping', 'cycle_phistory', 'cycle_wscache', 'cycle_websockets');
        foreach ($cycles as $cycle) {
            sg($cycle . 'Control', 'stop');
            sg('ThisComputer.' . $cycle . 'Run', '');
            sg($cycle . 'Run', '');
        }

        if ($iframe) {
            echonow('<div><i style="font-size: 7pt;" class="glyphicon glyphicon-usd"></i> Waiting for cycles to stop...</div>');
        }
        sleep(12);
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


        $err_msg = gr('err_msg');
        if ($err_msg) {
            $out['ERR_MSG'] = $err_msg;
        }
        $ok_msg = gr('ok_msg');
        if ($ok_msg) {
            $out['OK_MSG'] = $ok_msg;
        }


        $max_size = 0;
        $post_max_size = $this->parse_size(ini_get('post_max_size'));
        if ($post_max_size > 0) {
            $max_size = $post_max_size;
        }
        $upload_max = $this->parse_size(ini_get('upload_max_filesize'));
        if ($upload_max > 0 && $upload_max < $max_size) {
            $max_size = $upload_max;
        }
        $out['MAX_SIZE'] = round($max_size / 1024 / 1024, 2) . ' Mb';

        if (gr('mode') == 'force_update' && $this->action == 'admin') {
            unset($_REQUEST['mode']);
            $this->autoUpdateSystem();
        }

        if (gr('mode') == 'auto_update_settings' && $this->action == 'admin') {
            $this->getConfig();

            $this->config['MASTER_UPDATE_URL'] = gr('set_update_url');
            $this->config['UPDATE_AUTO'] = gr('update_auto');
            $this->config['UPDATE_AUTO_DELAY'] = gr('update_auto_delay');
            $this->config['UPDATE_AUTO_TIME'] = gr('update_auto_time');
            $this->config['UPDATE_AUTO_PLUGINS'] = gr('update_auto_plugins');

            if ($this->config['UPDATE_AUTO']) {
                subscribeToEvent($this->name, 'HOURLY');
            } else {
                unsubscribeFromEvent($this->name, 'HOURLY');
            }
            $this->saveConfig();
            $this->redirect("?ok_msg=" . urlencode(LANG_DATA_SAVED));
        }

        $this->getConfig();
        $force_check = (int)gr('check_now') === 1;
        $out['FORCE_CHECK'] = $force_check ? 1 : 0;

        if (is_dir(DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/saverestore/temp')) {
            $out['CLEAR_FIRST'] = 1;
        } else {
            $out['CLEAR_FIRST'] = 0;
        }

        $update_url = $this->getUpdateURL();
        $out['UPDATE_URL'] = $update_url;
        $out['PROJECT_COMMIT_URL'] = defined('PROJECT_COMMIT_URL') ? PROJECT_COMMIT_URL : '';
        $out['GIT_URL_CONFIGURED'] = defined('GIT_URL') && GIT_URL != '' ? 1 : 0;
        $out['GIT_MASTER_UPDATE_URL'] = $out['GIT_URL_CONFIGURED'] ? rtrim(GIT_URL, '/') . '/archive/master.tar.gz' : '';
        $out['GIT_ALPHA_UPDATE_URL'] = $out['GIT_URL_CONFIGURED'] ? rtrim(GIT_URL, '/') . '/archive/alpha.tar.gz' : '';

        $out['UPDATE_AUTO'] = $this->config['UPDATE_AUTO'];
        $out['UPDATE_AUTO_DELAY'] = $this->config['UPDATE_AUTO_DELAY'];
        $out['UPDATE_AUTO_TIME'] = $this->config['UPDATE_AUTO_TIME'];
        $out['UPDATE_AUTO_PLUGINS'] = $this->config['UPDATE_AUTO_PLUGINS'];

        $aditional_git_urls = gr('aditional_git_urls');
        $out['ADITIONAL_GIT_URLS'] = array();
        if (is_array($aditional_git_urls)) {
            foreach ($aditional_git_urls as $url => $title) {
                $tmp = array();
                $tmp['URL'] = $url;
                $tmp['TITLE'] = $title;
                $tmp['SELECTED'] = $out['UPDATE_URL'] == $url ? 'selected' : '';
                $out['ADITIONAL_GIT_URLS'][] = $tmp;
            }
        }

        $github_feed_url = $this->getUpdateFeedURL($update_url);

        $op = isset($_GET['op']) ? $_GET['op'] : '';
        if ($force_check) {
            $cache_timeout = 0;
        } elseif ($op == 'check_updates') {
            $cache_timeout = 3 * 24 * 60 * 60;
        } else {
            $cache_timeout = 30 * 60;
        }

        $options = array(
            CURLOPT_HTTPHEADER => array('Accept: application/xml')
        );
        $github_feed = '';
        if ($github_feed_url != '') {
            $github_feed = getURL($github_feed_url, $cache_timeout, '', '', false, $options);
        }
        if ($github_feed != '') {
            $tmp = GetXMLTree($github_feed);
            if (is_array($tmp)) {
                $data = XMLTreeToArray($tmp);
                $items = $data['feed']['entry'];
            } else {
                $github_feed = getURL($github_feed_url, 0, '', '', false, $options);
                $items = false;
            }
            if (is_array($items)) {
                $total = count($items);
                if ($total) {
                    $iteration = 0;
                    $current_latest_id = isset($this->config['LATEST_UPDATED_ID']) ? $this->normalizeCommitId($this->config['LATEST_UPDATED_ID']) : '';
                    // echo '<pre>';
                    // var_dump($items);
                    // die();
                    foreach ($items as $key => $value) {
                        $itm = array();

                        $itm['ID'] = $this->normalizeCommitId($value['id']['textvalue']);
                        $itm['MYVERSION'] = ($itm['ID'] == $current_latest_id) ? 1 : 0;
                        $itm['TITLE'] = trim($value['title']['textvalue']);
                        $itm['AUTHOR'] = $value['author']['name']['textvalue'];
                        $itm['LINK'] = $value['link']['href'];
                        $itm['LINK_URL'] = urlencode($itm['LINK']);
                        $itm['UPDATED'] = strtotime($value['updated']['textvalue']);
                        $itm['UPDATE_TEXT'] = date('d.m.Y H:i', $itm['UPDATED']);
                        $itm['DESC_UPDATE'] = '';
                        if (isset($value['content']['textvalue'])) {
                            $content = preg_split('/\\r\\n?|\\n/', $value['content']['textvalue']);
                            if (isset($content[3])) {
                                $itm['DESC_UPDATE'] = strip_tags($content[3]);
                            }
                        }

                        $itm['MYVERSION'] = ($itm['ID'] == $current_latest_id) ? 1 : 0;
                        $out['UPDATES'][] = $itm;
                        $iteration++;

                        if ($iteration >= 5) {
                            break;
                        }
                    }

                    $out['LATEST_ID'] = $out['UPDATES'][0]['ID'];

                    $out['LATEST_UPDATED_ID'] = $current_latest_id;
                    $out['LATEST_UPDATED_ID_SLICE'] = mb_strtoupper(substr($out['LATEST_UPDATED_ID'], 0, 7));
                    $out['LATEST_UPDATED_TIME'] = gg('LatestUpdateTimestamp');

                    $out['UPDATE_CURR_BRANCH'] = $this->normalizeUpdateBranch($this->getUpdateBranch($update_url));
                    $out['LATEST_CURR_BRANCH'] = isset($this->config['LATEST_CURR_BRANCH']) ? $this->normalizeUpdateBranch($this->config['LATEST_CURR_BRANCH']) : '';
                    if ($out['LATEST_CURR_BRANCH'] == '' && $out['LATEST_UPDATED_ID'] != '') {
                        $out['LATEST_CURR_BRANCH'] = $out['UPDATE_CURR_BRANCH'];
                    }

                    if ($out['LATEST_ID'] != '' && $out['LATEST_ID'] == $out['LATEST_UPDATED_ID'] && $out['LATEST_CURR_BRANCH'] == $out['UPDATE_CURR_BRANCH']) {
                        $out['NO_NEED_TO_UPDATE'] = 1;
                    }
                    $op = isset($_GET['op']) ? $_GET['op'] : '';
                    if ($this->ajax && $op == 'check_updates') {
                        if (!isset($out['NO_NEED_TO_UPDATE'])) {
                            echo json_encode(array('needUpdate' => '1', 'currBranch' => $out['LATEST_CURR_BRANCH'], 'current_version' => $out['LATEST_UPDATED_ID'], 'commitUrl' => $out['PROJECT_COMMIT_URL']));
                        } else {
                            echo json_encode(array('needUpdate' => '0', 'currBranch' => $out['LATEST_CURR_BRANCH'], 'current_version' => $out['LATEST_UPDATED_ID'], 'commitUrl' => $out['PROJECT_COMMIT_URL']));
                        }
                        exit;
                    }
                    //print_r($out['UPDATES']);
                    //exit;
                }
            }
        }


        if ($this->mode == 'savedetails') {

            global $ftp_host;
            global $ftp_username;
            global $ftp_password;
            global $ftp_folder;
            global $ftp_clear;


            if ($ftp_clear) {
                $this->config['FTP_USERNAME'] = '';
                $this->config['FTP_PASSWORD'] = '';
                $this->saveConfig();
                $this->redirect("?");
            }

            $out['FTP_HOST'] = $ftp_host;
            $out['FTP_USERNAME'] = $ftp_username;
            $out['FTP_PASSWORD'] = $ftp_password;
            $out['FTP_FOLDER'] = $ftp_folder;


            $conn_id = @ftp_connect($ftp_host);
            if ($conn_id) {

                $login_result = @ftp_login($conn_id, $ftp_username, $ftp_password);
                if ($login_result) {
                    $systyp = ftp_systype($conn_id);

                    if (!preg_match('/\/$/', $ftp_folder)) {
                        $ftp_folder .= '/';
                    }

                    if (@ftp_chdir($conn_id, $ftp_folder . 'cms/saverestore')) {
                        $this->config['FTP_HOST'] = $ftp_host;
                        $this->config['FTP_USERNAME'] = $ftp_username;
                        $this->config['FTP_PASSWORD'] = $ftp_password;
                        $this->config['FTP_FOLDER'] = $ftp_folder;
                        $this->saveConfig();
                        $this->redirect("?");
                    } else {
                        $out['FTP_ERR'] = 'Incorrect folder (' . $ftp_folder . ')';
                    }
                } else {
                    $out['FTP_ERR'] = 'Incorrect username/password';
                }

                ftp_close($conn_id);

            } else {
                $out['FTP_ERR'] = 'Cannot connect to host (' . $ftp_host . ')';
            }

        }

        if ($this->mode != 'savedetails') {
            if (isset($this->config['FTP_HOST'])) $out['FTP_HOST'] = $this->config['FTP_HOST'];
            if (isset($this->config['FTP_USERNAME'])) $out['FTP_USERNAME'] = $this->config['FTP_USERNAME'];
            if (isset($this->config['FTP_PASSWORD'])) $out['FTP_PASSWORD'] = $this->config['FTP_PASSWORD'];
            if (isset($this->config['FTP_FOLDER'])) $out['FTP_FOLDER'] = $this->config['FTP_FOLDER'];
        }

// if ($this->mode=='' || $this->mode=='upload' || $this->mode=='savedetails') {
        $method = 'ftp';
        if (function_exists('getmyuid') && function_exists('fileowner')) {
            $temp_file = tempnam("./cms/saverestore/", "FOO");
            if (file_exists($temp_file)) {
                $method = 'direct';
                unlink($temp_file);
            }
        }
        $out['METHOD'] = $method;
        $this->method = $method;
// }

        if ($this->mode == 'clear') {
            set_time_limit(0);
            removeTree(DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/saverestore/temp');
            @unlink(DOC_ROOT . DIRECTORY_SEPARATOR . "cms/modules_installed/control_modules.installed");
            $with_extensions = gr('with_extensions');
            $with_backup = gr('with_backup');
            if ($with_extensions) {
                $this->redirect("?(panel:{action=market})&md=market&mode=update_new");
            }
            $this->redirect("?err_msg=" . urlencode($err_msg) . "&ok_msg=" . urlencode($ok_msg));
        }


        if ($this->mode == 'checksubmit') {
            $this->checkSubmit($out);
        }

        if ($this->mode == 'uploadupdates') {
            $this->uploadUpdates($out);
        }

        if ($this->mode == 'checkupdates') {
            $this->checkupdatesSVN($out);
        }

        if ($this->mode == 'downloadupdates') {
            $this->downloadupdatesSVN($out);
        }

        if ($this->mode == 'checkapps') {
            $this->checkApps($out);
        }

        if ($this->mode == 'downloadapps') {
            $this->downloadApps($out);
        }


        if ($this->mode == 'upload') {
            $this->upload($out);
        }
        if ($this->mode == 'dump') {
            $this->dump($out);
            $this->redirect("?mode=clear");
        }

        if ($this->mode == 'delete') {
            $file = gr('file');
            if ($file != '') {
                $delete_target = $this->resolveRestoreSourcePath($file);
                if ($delete_target == '') {
                    $this->redirect("?err_msg=" . urlencode('Invalid backup path'));
                }

                if (is_dir($delete_target)) {
                    removeTree($delete_target);
                } elseif (is_file($delete_target)) {
                    @unlink($delete_target);
                }
            }

            $this->redirect("?");
        }

        if ($this->mode == 'getlatest') {
            $this->getLatest($out);
        }

        if ($this->mode == 'getlatest_iframe') {

            $with_extensions = gr('with_extensions');
            $with_backup = gr('with_backup');

            $out['WITH_EXTENSIONS'] = $with_extensions;
            $out['WITH_BACKUP'] = $with_backup;


            global $backup;
            $out['BACKUP'] = $backup;
            global $data;
            $out['DATA'] = $data;
            global $code;
            $out['CODE'] = $code;
            global $save_files;
            $out['SAVE_FILES'] = $save_files;
            global $design;
            $out['DESIGN'] = $design;
        }

        $source = $this->getSaveRestoreDirectory();
        $backups_dir = $this->getBackupStorageDirectory();
        $items = array();

        if (is_dir($source)) {
            $local_files = glob($source . DIRECTORY_SEPARATOR . '*');
            if (is_array($local_files)) {
                foreach ($local_files as $file) {
                    $items[] = array(
                        'path' => $file,
                        'is_local' => 1
                    );
                }
            }
        }

        if (is_dir($backups_dir)) {
            $backup_files = glob(rtrim($backups_dir, DIRECTORY_SEPARATOR . '/') . DIRECTORY_SEPARATOR . '*');
            if (is_array($backup_files)) {
                foreach ($backup_files as $file) {
                    $items[] = array(
                        'path' => $file,
                        'is_local' => 0
                    );
                }
            }
        }

        usort($items, function ($a, $b) {
            $left_time = file_exists($a['path']) ? (int)filemtime($a['path']) : 0;
            $right_time = file_exists($b['path']) ? (int)filemtime($b['path']) : 0;
            if ($left_time == $right_time) {
                return strcmp(basename((string)$a['path']), basename((string)$b['path']));
            }
            return $right_time <=> $left_time;
        });

        $out['FILES'] = array();
        foreach ($items as $index => $item) {
            $file = $item['path'];
            if (!file_exists($file)) {
                continue;
            }

            $tmp = array();
            $tmp['ID'] = $index;
            $tmp['FILENAME'] = $item['is_local'] ? basename($file) : $file;
            $tmp['FILENAME_URL'] = urlencode($tmp['FILENAME']);
            $tmp['TITLE'] = basename($file);
            $tmp['IS_LOCAL'] = $item['is_local'];
            $tmp['SOURCE_TITLE'] = $item['is_local'] ? 'Локальный архив модуля' : 'Внешнее хранилище';
            $tmp['UPDATED'] = date('Y-m-d H:i:s', filemtime($file));
            $tmp['DOWNLOAD_URL'] = '';

            if (is_file($file)) {
                $tmp['FILESIZE'] = number_format((filesize($file) / 1024 / 1024), 2);
                if ($item['is_local']) {
                    $tmp['DOWNLOAD_URL'] = ROOTHTML . 'cms/saverestore/' . rawurlencode(basename($file));
                }
            } else {
                $tmp['FILESIZE'] = '';
            }

            $out['FILES'][] = $tmp;
        }


    }


    function getUpdateURL($link = '')
    {
        $this->getConfig();

        if ($this->config['MASTER_UPDATE_URL'] != '') {
            $update_url = $this->config['MASTER_UPDATE_URL'];
        } elseif (defined('MASTER_UPDATE_URL') && MASTER_UPDATE_URL != '') {
            $update_url = MASTER_UPDATE_URL;
        } elseif (defined('GIT_URL') && GIT_URL != '') {
            $update_url = rtrim(GIT_URL, '/') . '/archive/master.tar.gz';
        } else {
            $update_url = '';
        }
        return $update_url;
    }

    function getUpdateFeedURL($update_url)
    {
        $url_info = parse_url($update_url);
        $path = isset($url_info['path']) ? $url_info['path'] : $update_url;

        if (preg_match('#/-/archive/([^/]+)/[^/]+\.tar\.gz$#i', $path, $m)) {
            return str_replace($m[0], '/-/commits/' . $m[1] . '.atom', $update_url);
        }

        if (preg_match('#/archive/(?:refs/heads/)?([^/]+)\.tar\.gz$#i', $path, $m)) {
            return preg_replace('#/archive/(?:refs/heads/)?[^/]+\.tar\.gz#i', '/commits/' . $m[1] . '.atom', $update_url);
        }

        return str_replace(array('/archive/', '.tar.gz'), array('/commits/', '.atom'), $update_url);
    }

    function getUpdateBranch($update_url)
    {
        $url_info = parse_url($update_url);
        $path = isset($url_info['path']) ? $url_info['path'] : $update_url;

        if (preg_match('#/-/archive/([^/]+)/[^/]+\.tar\.gz$#i', $path, $m)) {
            return $m[1];
        }

        if (preg_match('#/archive/(?:refs/heads/)?([^/]+)\.tar\.gz$#i', $path, $m)) {
            return $m[1];
        }

        return str_replace('.tar.gz', '', basename($path));
    }

    function getArchiveURLForCommit($update_url, $commit)
    {
        $url_info = parse_url($update_url);
        $path = isset($url_info['path']) ? $url_info['path'] : $update_url;

        if (preg_match('#/-/archive/([^/]+)/([^/]+)\.tar\.gz$#i', $path, $m)) {
            $archive_name = str_replace($m[1], $commit, $m[2]);
            if ($archive_name == $m[2]) {
                $archive_name = $commit;
            }
            return str_replace($m[0], '/-/archive/' . $commit . '/' . $archive_name . '.tar.gz', $update_url);
        }

        if (preg_match('#/archive/(?:refs/heads/)?[^/]+\.tar\.gz#i', $path, $m)) {
            return str_replace($m[0], '/archive/' . $commit . '.tar.gz', $update_url);
        }

        return $update_url;
    }

    /**
     * Title
     *
     * Description
     *
     * @access public
     */
    function getLatest(&$out, $iframe = 0, $with_backup = 1, $link = '')
    {
        $url = $this->getUpdateURL();
        $this->url = $url;

        set_time_limit(0);

        $preflight_issues = $this->collectSystemUpdatePreparationIssues();
        if (count($preflight_issues) > 0) {
            $message = 'System update preflight failed: ' . implode(' | ', $preflight_issues);
            DebMes($message, 'restore');
            if ($iframe) {
                foreach ($preflight_issues as $issue) {
                    echonow('<div><i style="font-size: 7pt;" class="glyphicon glyphicon-remove-sign"></i> ' . htmlspecialchars($issue) . '</div>', 'red');
                }
                return 0;
            }
            $this->redirect("?err_msg=" . urlencode($preflight_issues[0]));
        }

        if (!is_dir(DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/saverestore')) {
            @umask(0);
            @mkdir(DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/saverestore', 0777);
        }

        if (!$this->backupDatabaseBeforeSystemUpdate($iframe)) {
            if ($iframe) {
                echonow('<div><i style="font-size: 7pt;" class="glyphicon glyphicon-usd"></i> Cannot create mandatory database backup. Update stopped.</div>', 'red');
                return 0;
            }
            $this->redirect("?err_msg=" . urlencode("Cannot create mandatory database backup"));
        }

        $filename = DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/saverestore/master.tgz';

        @unlink(DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/saverestore/master.tgz');
        @unlink(DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/saverestore/master.tar');

        $f = fopen($filename, 'wb');
        if ($f == FALSE) {
            $this->redirect("?err_msg=" . urlencode("Cannot open " . $filename . " for writing"));
        }

        if ($iframe) {
            echonow('<div><i style="font-size: 7pt;" class="glyphicon glyphicon-chevron-right"></i> ' . LANG_UPDATEARHIVE_DONE . ' ' . $url . '</div>');
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_TIMEOUT, 600);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, TRUE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_FILE, $f);
        $incoming = curl_exec($ch);

        curl_close($ch);
        @fclose($f);

        if (file_exists($filename)) {

            if ($iframe) {
                echonow('<div><i style="font-size: 7pt;" class="glyphicon glyphicon-usd"></i> ' . LANG_UPDATEBACKUP_DONE . '</div>');
            }


            if ($with_backup) {
                global $code;
                global $data;
                global $design;
                $code = 1;
                $data = 1; //fix
                $design = 1;
                $out['BACKUP'] = 1;
                $this->dump($out, $iframe);
            }
            removeTree(DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/saverestore/temp', $iframe);

            if (!$iframe) {
                $with_extensions = gr('with_extensions');
                $with_backup = gr('with_backup');
                $this->redirect("?mode=upload&restore=" . urlencode('master.tgz') . "&with_extensions=" . $with_extensions . "&with_backup=" . $with_backup);
            } else {
                return 1;
            }

        } else {

            if ($iframe) {
                echonow('<div><i style="font-size: 7pt;" class="glyphicon glyphicon-usd"></i> ' . LANG_UPDATEBACKUP_ERROR_DOWNLOAD . '</div>', 'red');
                exit;
            } else {
                $this->redirect("?err_msg=" . urlencode(LANG_UPDATEBACKUP_ERROR_DOWNLOAD . ' ' . $url));
            }
        }
    }


    /**
     * Title
     *
     * Description
     *
     * @access public
     */
    function uploadUpdates(&$out)
    {
        global $to_submit;
        global $pack_folders;


        $total = count($to_submit);

        umask(0);

        $copied_dirs = array();

        if (mkdir(DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/saverestore/temp', 0777)) {
            for ($i = 0; $i < $total; $i++) {
                $this->copyFile(ROOT . $to_submit[$i], DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/saverestore/temp/' . $to_submit[$i]);
                if (is_array($pack_folders) && in_array($to_submit[$i], $pack_folders) && !$copied_dirs[dirname(DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/saverestore/temp/' . $to_submit[$i])]) {
                    copyTree(dirname(ROOT . $to_submit[$i]), dirname(DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/saverestore/temp/' . $to_submit[$i]));
                    $copied_dirs[dirname(DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/saverestore/temp/' . $to_submit[$i])] = 1;
                }
                if (file_exists(dirname(DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/saverestore/temp/' . $to_submit[$i]) . '/installed')) {
                    @unlink(dirname(DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/saverestore/temp/' . $to_submit[$i]) . '/installed');
                }
            }
        }

        // packing into tar.gz
        $tar_name = 'submit_' . date('Y-m-d__H-i-s') . '.tgz';

        chdir(DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/saverestore/temp');
        exec('tar cvzf ../' . $tar_name . ' .');
        chdir('../../../');
        removeTree(DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/saverestore/temp');

        // sending to remote server

        $repository_url = UPDATER_URL;

        if (defined('UPDATES_REPOSITORY_NAME')) {
            $repository_name = UPDATES_REPOSITORY_NAME;
        } else {
            $repository_name = 'default';
        }

        $to_send = array();
        global $name;
        $to_send['NAME'] = $name;
        setCookie('SUBMIT_NAME', $name, 0, '/');

        global $email;
        $to_send['EMAIL'] = $email;
        setCookie('SUBMIT_EMAIL', $email, 0, '/');

        global $description;
        $to_send['DESCRIPTION'] = $description;
        $to_send['FILES'] = $to_submit;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $repository_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 600);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');

        $post = array(
            "file" => "@" . DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/saverestore/' . $tar_name,
            "mode" => "upload_updates",
            "repository" => $repository_name,
            "host" => (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost'),
            "data" => serialize($to_send)
        );
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);

        //curl_setopt($ch, CURLOPT_POSTFIELDS, "mode=upload_updates&repository=".$repository_name."&host=".(isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost')."&data=".$to_send);

        $incoming = curl_exec($ch);

        curl_close($ch);

        $result = unserialize($incoming);

        $ok_msg = 'Error sending files to repository! ';//.$incoming
        if ($result['MESSAGE']) {
            $ok_msg = $result['MESSAGE'];
        }


        if ($result['STATUS'] == 'OK') {
            $with_extensions = gr('with_extensions');
            $with_backup = gr('with_backup');
            $this->redirect("?mode=clear&ok_msg=" . urlencode($ok_msg) . "&with_extensions=" . $with_extensions . "&with_backup=" . $with_backup);
        } else {
            $this->redirect("?mode=clear&err_msg=" . urlencode($ok_msg));
        }

        //exit;


    }

    /**
     * Title
     *
     * Description
     *
     * @access public
     */
    function checkSubmit(&$out)
    {

        $res1 = $this->checkEFiles('.', 0);
        $res2 = $this->checkEFiles('./modules', 1);
        $res3 = $this->checkEFiles('./templates', 1);
        $res4 = $this->checkEFiles('./lib', 0);

        $res = array_merge($res1, $res2, $res3, $res4);

        $to_send = serialize($res);

        $repository_url = UPDATER_URL;

        if (defined('UPDATES_REPOSITORY_NAME')) {
            $repository_name = UPDATES_REPOSITORY_NAME;
        } else {
            $repository_name = 'default';
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $repository_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 600);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');

        curl_setopt($ch, CURLOPT_POSTFIELDS, "mode=check_submit&repository=" . $repository_name . "&host=" . (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost') . "&data=" . $to_send);

        $incoming = curl_exec($ch);

        curl_close($ch);

        //echo $incoming;exit;


        $result = unserialize($incoming);

        //echo $repository_url;
        //echo($result);exit;

        if ($result['STATUS'] != 'OK') {
            $out['ERROR_CHECK'] = 1;
            if ($result['MESSAGE']) {
                $out['ERROR_MESSAGE'] = $result['MESSAGE'];
            } else {
                $out['ERROR_MESSAGE'] = 'Cannot connect to updates server';
            }
        } else {
            $out['OK_CHECKSUBMIT'] = 1;

            //print_r($result['TO_SUBMIT']);exit;

            if (is_array($result['TO_SUBMIT'])) {
                foreach ($result['TO_SUBMIT'] as $f => $v) {
                    $tmp = array('FILE' => $f, 'VERSION' => $v, 'L_VERSION' => $res[$f]);
                    if (preg_match('/\/modules\/.+\/.+/is', $f) || preg_match('/\/templates\/.+\/.+/is', $f)) {
                        $tmp['PACK_FOLDER'] = 1;
                    }
                    $out['TO_SUBMIT'][] = $tmp;
                }
            } else {
                $out['NO_SUBMIT'] = 1;
            }
        }

        $out['NAME'] = $_COOKIE['SUBMIT_NAME'];
        $out['EMAIL'] = $_COOKIE['SUBMIT_EMAIL'];

    }


    /**
     * Title
     *
     * Description
     *
     * @access public
     */
    function downloadUpdates(&$out)
    {
        global $to_update;

        $repository_url = UPDATER_URL;

        if (defined('UPDATES_REPOSITORY_NAME')) {
            $repository_name = UPDATES_REPOSITORY_NAME;
        } else {
            $repository_name = 'default';
        }

        // preparing update

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $repository_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 600);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, "mode=prepare&repository=" . $repository_name . "&host=" . (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost') . "&data=" . serialize($to_update));
        $incoming = curl_exec($ch);
        curl_close($ch);

        $res = unserialize($incoming);

        if ($res['STATUS'] == 'OK' && $res['DOWNLOAD_FILE'] != '') {
            // downloading update
            $filename = DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/saverestore/' . $res['DOWNLOAD_FILE'];
            $f = fopen($filename, 'wb');
            if ($f == FALSE) {
                //print "File not opened<br>";
                //exit;
                $this->redirect("?err_msg=" . urlencode("Cannot open " . $filename . " for writing"));
            }
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $repository_url);
            curl_setopt($ch, CURLOPT_TIMEOUT, 600);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
            curl_setopt($ch, CURLOPT_FILE, $f);

            curl_setopt($ch, CURLOPT_POSTFIELDS, "mode=download&repository=" . $repository_name . "&host=" . (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost') . "&file=" . $res['DOWNLOAD_FILE']);

            $incoming = curl_exec($ch);

            curl_close($ch);
            @fclose($f);

            if (file_exists($filename) && filesize($filename) > 0) {
                // backing up current code version
                global $code;
                global $data;
                $code = 1;
                $data = 1;
                $out['BACKUP'] = 1;
                $this->dump($out);
                removeTree(DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/saverestore/temp');
                // installing update
                $this->redirect("?mode=upload&restore=" . urlencode($res['DOWNLOAD_FILE']));
            } else {
                $this->redirect("?err_msg=" . urlencode("Error downloading update"));
            }
        }

        exit;

    }

    /**
     * Title
     *
     * Description
     *
     * @access public
     */
    function downloadApps(&$out)
    {
        global $to_install;


        $repository_url = UPDATER_URL;

        if (defined('UPDATES_REPOSITORY_NAME')) {
            $repository_name = UPDATES_REPOSITORY_NAME;
        } else {
            $repository_name = 'default';
        }

        // preparing update

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $repository_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 600);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, "mode=prepareapps&repository=" . $repository_name . "&host=" . (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost') . "&data=" . serialize($to_install));
        $incoming = curl_exec($ch);
        curl_close($ch);

        //echo $incoming;exit;

        $res = unserialize($incoming);

        if ($res['STATUS'] == 'OK' && $res['DOWNLOAD_FILE'] != '') {
            // downloading update
            $filename = DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/saverestore/' . $res['DOWNLOAD_FILE'];
            $f = fopen($filename, 'wb');
            if ($f == FALSE) {
                //print "File not opened<br>";
                //exit;
                $this->redirect("?err_msg=" . urlencode("Cannot open " . $filename . " for writing"));
            }
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $repository_url);
            curl_setopt($ch, CURLOPT_TIMEOUT, 600);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
            curl_setopt($ch, CURLOPT_FILE, $f);

            curl_setopt($ch, CURLOPT_POSTFIELDS, "mode=download&repository=" . $repository_name . "&host=" . (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost') . "&file=" . $res['DOWNLOAD_FILE']);

            $incoming = curl_exec($ch);

            curl_close($ch);
            @fclose($f);

            if (file_exists($filename) && filesize($filename) > 0) {
                // backing up current code version
                global $code;
                global $data;
                $code = 1;
                $data = 1;
                $out['BACKUP'] = 1;
                $this->dump($out);
                removeTree(DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/saverestore/temp');
                // installing update
                $this->redirect("?mode=upload&restore=" . urlencode($res['DOWNLOAD_FILE']));
            } else {
                $this->redirect("?err_msg=" . urlencode("Error downloading update"));
            }
        }

        exit;

    }


    /**
     * Title
     *
     * Description
     *
     * @access public
     */
    function checkApps(&$out)
    {

        $res = array();
        $d = './modules';
        if ($dir = @opendir($d)) {
            while (($file = readdir($dir)) !== false) {
                if (is_dir($d . '/' . $file)
                    && ($file != '..')
                    && ($file != '.')
                    && ($file != 'control_access')
                    && ($file != 'control_modules')
                ) {
                    $res[] = $file;
                }
            }
        }

        $to_send = serialize($res);

        $repository_url = UPDATER_URL;

        if (defined('UPDATES_REPOSITORY_NAME')) {
            $repository_name = UPDATES_REPOSITORY_NAME;
        } else {
            $repository_name = 'default';
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $repository_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 600);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');

        curl_setopt($ch, CURLOPT_POSTFIELDS, "mode=checkapps&repository=" . $repository_name . "&host=" . (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost') . "&data=" . $to_send);

        $incoming = curl_exec($ch);

        curl_close($ch);

        //echo $incoming;exit;

        $result = unserialize($incoming);

        if ($result['STATUS'] != 'OK') {

            if ($result['MESSAGE']) {
                $out['ERROR_MESSAGE'] = $result['MESSAGE'];
            } else {
                $out['ERROR_MESSAGE'] = 'Cannot connect to updates server';
            }
            $this->redirect("?err_msg=" . urlencode($out['ERROR_MESSAGE']));

        } else {
            $out['OK_BROWSE'] = 1;
            if (is_array($result['TO_INSTALL'])) {
                $out['TO_INSTALL'] = $result['TO_INSTALL'];
                /*
                foreach($result['TO_UPDATE'] as $f=>$v) {
                 $out['TO_INSTALL'][]=array('FILE'=>$f, 'VERSION'=>$v);
                }
                */
            } else {
                $out['NO_MODULES'] = 1;
            }
        }

    }


    /**
     * Title
     *
     * Description
     *
     * @access public
     */
    function downloadUpdatesSVN(&$out)
    {

        global $code;
        global $data;
        $code = 1;
        $data = 1;
        $out['BACKUP'] = 1;
        $this->dump($out);
        removeTree(DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/saverestore/temp');

        include_once DIR_MODULES . 'saverestore/phpsvnclient.php';
        $url = 'http://majordomo-sl.googlecode.com/svn/';
        $phpsvnclient = new phpsvnclient($url);
        set_time_limit(0);
        global $to_update;

        $total = count($to_update);
        for ($i = 0; $i < $total; $i++) {
            $path = 'trunk/' . $to_update[$i];
            $file_content = $phpsvnclient->getFile($path);
            if (!is_dir(dirname(ROOT . $to_update[$i]))) {
                @mkdir(dirname(ROOT . $to_update[$i]), 0777);
            }
            @SaveFile(ROOT . $to_update[$i], $file_content);
            if (file_exists(dirname(ROOT . $to_update[$i]) . '/installed')) {
                @unlink(dirname(ROOT . $to_update[$i]) . '/installed');
            }
        }

        $this->redirect("?ok_msg=" . urlencode('Files have been updated!'));

    }

    /**
     * Title
     *
     * Description
     *
     * @access public
     */
    function checkUpdatesSVN(&$out)
    {
        include_once DIR_MODULES . 'saverestore/phpsvnclient.php';

        $url = 'http://majordomo-sl.googlecode.com/svn/';

        $phpsvnclient = new phpsvnclient($url);

        set_time_limit(0);
        //$phpsvnclient->createOrUpdateWorkingCopy('trunk/', ROOT.'cms/saverestore/temp', true);

        $cached_name = DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/saverestore/svn_tree.txt';
        if (!file_exists($cached_name) || (time() - filemtime($cached_name) > 8 * 60 * 60)) {
            $directory_tree = $phpsvnclient->getDirectoryTree('/trunk/');
            SaveFile($cached_name, serialize($directory_tree));
        } else {
            $directory_tree = unserialize(LoadFile($cached_name));
        }

        $updated = array();
        $total = count($directory_tree);
        for ($i = 0; $i < $total; $i++) {
            $item = $directory_tree[$i];
            if ($item['type'] != 'file' || $item['path'] == 'trunk/config.php') {
                continue;
            }
            $filename = str_replace('trunk/', ROOT, $item['path']);
            @$fsize = filesize($filename);
            $r_rfsize = $item['size'];
            if ($fsize != $r_rfsize || !file_exists($filename)) {
                $updated[] = $item;
            }

        }


        $out['OK_CHECK'] = 1;
        if (!$updated[0]) {
            $out['NO_UPDATES'] = 1;
        } else {
            foreach ($updated as $item) {
                $item['path'] = str_replace('trunk/', '', $item['path']);
                $out['TO_UPDATE'][] = array('FILE' => $item['path'], 'VERSION' => $item['version'] . ' (' . $item['last-mod'] . ')');
            }
        }

    }

    /**
     * Title
     *
     * Description
     *
     * @access public
     */
    function checkUpdates(&$out)
    {

        $res1 = $this->checkEFiles('.', 0);
        $res2 = $this->checkEFiles('./modules', 1);
        $res3 = $this->checkEFiles('./templates', 1);
        $res4 = $this->checkEFiles('./lib', 0);

        $res = array_merge($res1, $res2, $res3, $res4);

        $to_send = serialize($res);

        $repository_url = UPDATER_URL;

        if (defined('UPDATES_REPOSITORY_NAME')) {
            $repository_name = UPDATES_REPOSITORY_NAME;
        } else {
            $repository_name = 'default';
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $repository_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 600);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');

        curl_setopt($ch, CURLOPT_POSTFIELDS, "mode=check&repository=" . $repository_name . "&host=" . (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost') . "&data=" . $to_send);

        $incoming = curl_exec($ch);

        curl_close($ch);

        //echo $incoming;exit;


        $result = unserialize($incoming);

        //echo $repository_url;
        //echo($result);exit;

        if ($result['STATUS'] != 'OK') {
            $out['ERROR_CHECK'] = 1;
            if ($result['MESSAGE']) {
                $out['ERROR_MESSAGE'] = $result['MESSAGE'];
            } else {
                $out['ERROR_MESSAGE'] = 'Cannot connect to updates server';
            }
        } else {
            $out['OK_CHECK'] = 1;
            if (is_array($result['TO_UPDATE'])) {
                foreach ($result['TO_UPDATE'] as $f => $v) {
                    $out['TO_UPDATE'][] = array('FILE' => $f, 'VERSION' => $v);
                }
            } else {
                $out['NO_UPDATES'] = 1;
            }
        }


        //exec('curl ...')

    }

    /**
     * Title
     *
     * Description
     *
     * @access public
     */
    function checkEFiles($d, $max_level = 0, $level = 0)
    {


        $res = array();

        if (!is_dir($d)) {
            return $res;
        }

        if ($dir = @opendir($d)) {
            while (($file = readdir($dir)) !== false) {
                if (Is_Dir($d . "/" . $file) && ($file != '.') && ($file != '..')) {
                    //echo "<br>Dir ".$d."/".$file;
                    if ($level < $max_level) {
                        $res2 = $this->checkEFiles($d . "/" . $file, $max_level, ($level + 1));
                        if (is_array($res2)) {
                            $res = array_merge($res, $res2);
                        }
                    }
                } elseif (Is_File($d . "/" . $file) &&
                    (preg_match('/\.php$/', $file) || preg_match('/\.css$/', $file) || preg_match('/\.html$/', $file) || preg_match('/\.js$/', $file))
                ) {

                    if ($file == 'config.php') {
                        continue;
                    }

                    //echo "<br>".$d.'/'.$file;
                    $version = '';
                    $content = LoadFile($d . '/' . $file);
                    if (preg_match('/@version (.+?)\n/is', $content, $m)) {
                        $version = trim($m[1]);
                        //echo "<br>".$d.'/'.$file.' - '.$version;
                    } elseif (preg_match('/\.class\.php$/is', $file)) {
                        // echo "<br>".$d.'/'.$file.' - '.'unknown';
                        //$version='unknown';
                    }

                    if ($version != '') {
                        $res[$d . '/' . $file] = $version;
                    }

                }

            }
            closedir($dir);
        }
        return $res;

    }

    /**
     * Title
     *
     * Description
     *
     * @access public
     */
    function extractVersion($s)
    {
        $o_version = preg_replace('/\(.+/', '', $s);
        $o_version = preg_replace('/[^\d]/', '', $o_version);
        $o_version = (float)substr($o_version, 0, 1) . '.' . substr($o_version, 1, strlen($o_version) - 1);
        return $o_version;
    }

    /**
     * Title
     *
     * Description
     *
     * @access public
     */
    function isNewer($o_version, $r_version)
    {

        $o_version = $this->extractVersion($o_version);
        $r_version = $this->extractVersion($r_version);

        //$r_version+=0.1; // just for testing
        //echo $o_version.' to '.$r_version."<br>";

        if ($o_version < $r_version) {
            return 1;
        }

        return 0;
    }

    /**
     * Title
     *
     * Description
     *
     * @access public
     */
    function upload(&$out, $iframe = 0)
    {
        set_time_limit(0);
        global $restore;
        global $file;
        global $file_name;
        global $folder;
        $restore_path = '';

        $with_extensions = gr('with_extensions');
        $with_backup = gr('with_backup');

        if (!$folder)
            $folder = IsWindowsOS() ? '/.' : '/';
        else
            $folder = '/' . $folder;

        $uploaded_restore = $this->storeUploadedRestoreFile();
        if ($restore != '') {
            $resolved_restore = $this->resolveRestoreSourcePath($restore);
            if ($resolved_restore == '') {
                if ($iframe) {
                    echonow('Invalid restore source', 'red');
                    return false;
                }
                $this->redirect("?err_msg=" . urlencode('Invalid restore source'));
            }
            if (is_dir($resolved_restore)) {
                $file = $resolved_restore;
                $file_name = basename($resolved_restore);
                $restore_path = $resolved_restore;
            } else {
                $local_restore_path = $resolved_restore;
                if (!$this->isPathWithinRoot($resolved_restore, $this->getSaveRestoreDirectory())) {
                    $local_restore_path = $this->getSaveRestoreDirectory() . DIRECTORY_SEPARATOR . basename($resolved_restore);
                    @copy($resolved_restore, $local_restore_path);
                }
                $file = basename($local_restore_path);
                $file_name = basename($local_restore_path);
                $restore_path = $local_restore_path;
            }
        } elseif ($uploaded_restore['path'] != '') {
            $file = basename($uploaded_restore['path']);
            $file_name = $uploaded_restore['name'];
            $restore_path = $uploaded_restore['path'];
        } elseif ($uploaded_restore['error'] != '') {
            if ($iframe) {
                echonow('Upload failed', 'red');
                return false;
            }
            $this->redirect("?err_msg=" . urlencode('Upload failed'));
        } elseif ($file != '' && $file_name != '') {
            $restore_path = $this->getSaveRestoreDirectory() . DIRECTORY_SEPARATOR . $file_name;
            move_uploaded_file($file, $restore_path);
            $file = $file_name;
        }

        if ($iframe) {
            echonow('<div><i style="font-size: 7pt;" class="glyphicon glyphicon-usd"></i> ' . LANG_UPDATEBACKUP_APPLY_UPDATE . '</div>');
        }

        if ($restore_path == '' && $file != '' && !is_dir($file)) {
            $restore_path = $this->getSaveRestoreDirectory() . DIRECTORY_SEPARATOR . $file;
        }

        if ($file != '' && preg_match('/\.sql$/', $file_name) && $restore_path != '' && file_exists($restore_path)) {
            // restore database only
            if ($iframe) {
                echonow('<div><i style="font-size: 7pt;" class="glyphicon glyphicon-usd"></i> ' . LANG_UPDATEBACKUP_RESTORE_DB_FOR . ' ' . $file . '</div>');
            }
            $this->restoredatabase($restore_path);
            if ($iframe) {
                echonow('<div><i style="font-size: 7pt;" class="glyphicon glyphicon-usd"></i> ' . LANG_UPDATEBACKUP_DONE . '</div>');
            }
            if ($iframe) {
                return 1;
            } else {
                $this->redirect("?mode=clear&ok_msg=" . urlencode(LANG_UPDATEBACKUP_RESTORE_DB_DONE));
            }
        } elseif ($restore_path != '' && is_dir($restore_path)) {
            if ($iframe) {
                echonow('<div><i style="font-size: 7pt;" class="glyphicon glyphicon-usd"></i> ' . LANG_UPDATEBACKUP_UNPACKEGE_FROM_TO . ' ' . $restore_path . ' - ' . ROOT . '</div>');
            }
            copyTree($restore_path, ROOT, 1); // restore all files
            if ($iframe) {
                echonow('<div><i style="font-size: 7pt;" class="glyphicon glyphicon-usd"></i> ' . LANG_UPDATEBACKUP_DONE . '</div>');
            }
            $db_filename = $restore_path . '/' . DB_NAME . ".sql";
            if (file_exists($db_filename)) {
                if ($iframe) {
                    echonow('<div><i style="font-size: 7pt;" class="glyphicon glyphicon-usd"></i> ' . LANG_UPDATEBACKUP_RESTORE_DB_FOR . ' ' . $db_filename . '</div>');
                }
                $this->restoredatabase($db_filename);
                if ($iframe) {
                    echonow('<div><i style="font-size: 7pt;" class="glyphicon glyphicon-usd"></i> ' . LANG_UPDATEBACKUP_DONE . '</div>');
                }
            }
            if ($iframe) {
                return 1;
            } else {
                $this->redirect("?mode=clear&ok_msg=" . urlencode(LANG_UPDATEBACKUP_RESTORE_DB_DONE));
            }
        } elseif ($file != '') {

            DebMes("Trying to unpack $file", "restore");
            $is_system_update = ($file_name == 'master.tgz' || (isset($out['LATEST_ID']) && $out['LATEST_ID'] != ''));

            logAction('system_restore', $file);
            // unpack archive
            umask(0);
            @mkdir(DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/saverestore/temp', 0777);
            chdir(DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/saverestore/temp');
            if ($iframe) {
                echonow('<div><i style="font-size: 7pt;" class="glyphicon glyphicon-usd"></i> ' . LANG_UPDATEBACKUP_UNPACKEGE . ' ' . $file . '</div>');
            }
            $archive_path = $restore_path != '' && is_file($restore_path)
                ? $restore_path
                : $this->getSaveRestoreDirectory() . DIRECTORY_SEPARATOR . $file;
            $error_message = '';
            $result = $this->extractTarArchive($archive_path, DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/saverestore/temp', $error_message);

            if (!$result) {
                echonow("Unpack failed", 'red');
                if ($error_message != '') {
                    echonow(htmlspecialchars($error_message), 'red');
                }
                DebMes("Unpack failed for " . $archive_path . ': ' . $error_message, "restore");
                return false;
            }


            if (file_exists(DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/saverestore/temp/index.php') || file_exists(DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/saverestore/temp/dump.sql')) {
                $folder = '/.';
            } else {
                $UpdatesDir = scandir(DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/saverestore/temp', 1);
                $folder = DIRECTORY_SEPARATOR . $UpdatesDir[0];
                if (!is_dir(DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/saverestore/temp' . $folder)) {
                    echonow("Unpack failed!", 'red');
                    return false;
                }
            }
            DebMes("Restore folder: $folder", "restore");

            if ($iframe) {
                echonow('<div><i style="font-size: 7pt;" class="glyphicon glyphicon-usd"></i> ' . LANG_UPDATEBACKUP_DONE . '</div>');
            }

            $update_root = DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/saverestore/temp' . $folder;
            $this->removeBundledConfigFiles($update_root);
            $this->removeBundledConnectFiles($update_root);
            $old_manifest = array();
            $new_manifest = array();
            if ($is_system_update) {
                $old_manifest = $this->loadSystemUpdateManifest();
                $new_manifest = $this->buildSystemUpdateManifest($update_root);
                $apply_issues = $this->collectSystemUpdateTargetIssues($update_root);
                if (count($apply_issues) > 0) {
                    foreach ($apply_issues as $issue) {
                        if ($iframe) {
                            echonow('<div><i style="font-size: 7pt;" class="glyphicon glyphicon-remove-sign"></i> ' . htmlspecialchars($issue) . '</div>', 'red');
                        }
                    }
                    DebMes('System update apply preflight failed: ' . implode(' | ', $apply_issues), 'restore');
                    return false;
                }
            }

            if (file_exists(DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/saverestore/temp' . $folder . '/config.php')) {
                if ($iframe) {
                    echonow('<div><i style="font-size: 7pt;" class="glyphicon glyphicon-usd"></i> ' . LANG_UPDATEBACKUP_DISABLED . ' config.php</div>');
                }
                @unlink(DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/saverestore/temp' . $folder . '/config.php');
                if ($iframe) {
                    echonow('<div><i style="font-size: 7pt;" class="glyphicon glyphicon-usd"></i> ' . LANG_UPDATEBACKUP_DONE . '</div>');
                }
            }

            if (file_exists(DOC_ROOT . DIRECTORY_SEPARATOR . '/scripts/cycle_db_save.php') &&
                file_exists(DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/saverestore/temp' . $folder . '/scripts/periodical_db_save.php')
            ) {
                if ($iframe) {
                    echonow('<div><i style="font-size: 7pt;" class="glyphicon glyphicon-usd"></i> ' . LANG_UPDATEBACKUP_PATCHING . ' periodical_db_save.php...</div>');
                }
                @rename(DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/saverestore/temp' . $folder . '/scripts/periodical_db_save.php', DOC_ROOT . DIRECTORY_SEPARATOR . '/scripts/cycle_db_save.php');
                if ($iframe) {
                    echonow('<div><i style="font-size: 7pt;" class="glyphicon glyphicon-usd"></i> ' . LANG_UPDATEBACKUP_DONE . '</div>');
                }

            }

            if ($iframe) {
                echonow('<div><i style="font-size: 7pt;" class="glyphicon glyphicon-usd"></i> ' . LANG_UPDATEBACKUP_CHECK_MODULE_UPDATE . '</div>');
            }

            chdir('../../../');
            $ignores = SQLSelect("SELECT * FROM ignore_updates ORDER BY NAME");
            $total = count($ignores);
            for ($i = 0; $i < $total; $i++) {
                $name = $ignores[$i]['NAME'];
                if (is_dir(DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/saverestore/temp' . $folder . '/modules/' . $name)) {
                    removeTree(DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/saverestore/temp' . $folder . '/modules/' . $name);
                }
                if (is_dir(DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/saverestore/temp' . $folder . '/templates/' . $name)) {
                    removeTree(DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/saverestore/temp' . $folder . '/templates/' . $name);
                }
            }

            if ($iframe) {
                echonow('<div><i style="font-size: 7pt;" class="glyphicon glyphicon-usd"></i> ' . LANG_UPDATEBACKUP_DONE . '</div>');
            }

            if ($iframe) {
                echonow('<div><i style="font-size: 7pt;" class="glyphicon glyphicon-usd"></i> ' . LANG_UPDATEBACKUP_APPLY_CHANGES . ' ' . DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/saverestore/temp' . $folder . " to " . DOC_ROOT . DIRECTORY_SEPARATOR . '</div>');
            }

            $this->stopCyclesBeforeSystemUpdate($iframe);
            if ($is_system_update) {
                $this->removeFilesDeletedFromSystemUpdate($old_manifest, $new_manifest, $iframe);
                $this->removeObsoleteSystemUpdatePaths($iframe);
            }

            // UPDATING FILES DIRECTLY Исправлено верно на док_руут - потому что функция копиТрее не воспринимает других слешей 
            copyTree($update_root, DOC_ROOT, 1);
            if ($is_system_update) {
                $this->cleanupObsoleteSystemModules($iframe);
            }
            $this->cleanupLegacyConnectConfigNoise(DOC_ROOT . DIRECTORY_SEPARATOR . 'config.php');
            if ($is_system_update) {
                $this->saveSystemUpdateManifest($new_manifest);
            }

            if ($iframe) {
                echonow('<div><i style="font-size: 7pt;" class="glyphicon glyphicon-usd"></i> ' . LANG_UPDATEBACKUP_DONE . '</div>');
            }

            if (file_exists(DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/saverestore/temp' . $folder . '/dump.sql')) {
                // data restore
                if ($iframe) {
                    echonow('<div><i style="font-size: 7pt;" class="glyphicon glyphicon-usd"></i> ' . LANG_UPDATEBACKUP_RESTORE_DB . '</div>');
                }
                $result = $this->restoredatabase(DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/saverestore/temp' . $folder . '/dump.sql');
                if ($iframe) {
                    echonow('<div><i style="font-size: 7pt;" class="glyphicon glyphicon-usd"></i> ' . LANG_UPDATEBACKUP_DONE . '</div>');
                }
            }

            if ($is_system_update) {
                $latest_id = isset($out['LATEST_ID']) ? $this->normalizeCommitId($out['LATEST_ID']) : '';
                $update_branch = isset($out['UPDATE_CURR_BRANCH']) ? $this->normalizeUpdateBranch($out['UPDATE_CURR_BRANCH']) : '';
                if ($latest_id == '' || $update_branch == '') {
                    $update_info = $this->readLatestUpdateInfo(isset($this->url) ? $this->url : $this->getUpdateURL());
                    if ($latest_id == '' && $update_info['LATEST_ID'] != '') {
                        $latest_id = $update_info['LATEST_ID'];
                    }
                    if ($update_branch == '' && $update_info['UPDATE_CURR_BRANCH'] != '') {
                        $update_branch = $update_info['UPDATE_CURR_BRANCH'];
                    }
                }

                if ($latest_id != '') {
                    $this->config['LATEST_UPDATED_ID'] = $latest_id;
                    $this->config['LATEST_CURR_BRANCH'] = $update_branch;
                    $this->saveConfig();
                    setGlobal('LatestUpdateId', $latest_id);
                    setGlobal('LatestUpdateBranch', $update_branch);
                    setGlobal('LatestUpdateTimestamp', date('d.m.Y H:i:s'));
                } else {
                    DebMes('Cannot detect latest update commit after applying archive.', 'restore');
                }
            }


            if ($iframe) {
                echonow('<div><i style="font-size: 7pt;" class="glyphicon glyphicon-usd"></i> ' . LANG_UPDATEBACKUP_DONE . '</div>');
            }

            if ($iframe) {
                return 1;
            } else {
                setRebootRequired('updated');
                $this->redirect("?mode=clear&ok_msg=" . urlencode("Updates Installed!") . "&with_extensions=" . $with_extensions . "&with_backup=" . $with_backup);
            }

        }

    }

    /**
     * Title
     *
     * Description
     *
     * @access public
     */
    function dump(&$out, $iframe = 0)
    {
        if ($iframe) {
            echonow('<div><i style="font-size: 7pt;" class="glyphicon glyphicon-chevron-right"></i> ' . LANG_UPDATEBACKUP_REQUEST_BACKUP_CREATE . '</div>');
        }


        if (mkdir(DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/saverestore/temp', 0777)) {
            // DESIGN
            global $design;
            if ($design) {

                if ($iframe) {
                    echonow('<div><i style="font-size: 7pt;" class="glyphicon glyphicon-usd"></i> ' . LANG_UPDATEBACKUP_SAVE_DESIGN . '</div>');
                }

                $tar_name .= 'design_';
                copyTree(DOC_ROOT . DIRECTORY_SEPARATOR . 'templates', DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/saverestore/temp/templates');
                copyTree(DOC_ROOT . DIRECTORY_SEPARATOR . 'img', DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/saverestore/temp/img');
                copyTree(DOC_ROOT . DIRECTORY_SEPARATOR . 'js', DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/saverestore/temp/js');


                $pt = array('\.css');
                copyFiles(ROOT, DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/saverestore/temp', 0, $pt);

                $pt = array('\.swf');
                copyFiles(ROOT, DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/saverestore/temp', 0, $pt);

                $pt = array('\.htc');
                copyFiles(ROOT, DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/saverestore/temp', 0, $pt);

                if ($iframe) {
                    echonow('<div><i style="font-size: 7pt;" class="glyphicon glyphicon-usd"></i> ' . LANG_UPDATEBACKUP_DONE . '</div>');
                }


            }

            // CODE
            global $code;
            if ($code) {

                if ($iframe) {
                    echonow('<div><i style="font-size: 7pt;" class="glyphicon glyphicon-usd"></i> ' . LANG_UPDATEBACKUP_SAVE_CODE . '</div>');
                }


                $tar_name .= 'code_';

                copyTree(DOC_ROOT . DIRECTORY_SEPARATOR . 'lib', DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/saverestore/temp/lib');
                copyTree(DOC_ROOT . DIRECTORY_SEPARATOR . 'modules', DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/saverestore/temp/modules');
                copyTree(DOC_ROOT . DIRECTORY_SEPARATOR . 'scripts', DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/saverestore/temp/scripts');
                copyTree(DOC_ROOT . DIRECTORY_SEPARATOR . 'languages', DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/saverestore/temp/languages');

                $pt = array('\.php');
                copyFiles(ROOT, DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/saverestore/temp', 0, $pt);
                @unlink(DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/saverestore/temp/config.php');

                copyTree(DOC_ROOT . DIRECTORY_SEPARATOR . 'forum', DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/saverestore/temp/forum');
                @unlink(DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/saverestore/temp/forum/config.php');

                if (!$design) {
                    copyTree(DOC_ROOT . DIRECTORY_SEPARATOR . 'js', DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/saverestore/temp/js');
                    copyTree(DOC_ROOT . DIRECTORY_SEPARATOR . 'templates', DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/saverestore/temp/templates');
                }

                if ($iframe) {
                    echonow('<div><i style="font-size: 7pt;" class="glyphicon glyphicon-usd"></i> ' . LANG_UPDATEBACKUP_DONE . '</div>');
                }


            }

            // DATA
            global $data;
            if ($data) {
                if ($iframe) {
                    echonow('<div><i style="font-size: 7pt;" class="glyphicon glyphicon-usd"></i> ' . LANG_UPDATEBACKUP_SAVE_DATA . '</div>');
                }
                $tar_name .= 'data_';
                $this->backupdatabase(DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/saverestore/temp/dump.sql');
                if ($iframe) {
                    echonow('<div><i style="font-size: 7pt;" class="glyphicon glyphicon-usd"></i> ' . LANG_UPDATEBACKUP_DONE . '</div>');
                }
            }

            // FILES
            global $save_files;
            if ($save_files) {
                if ($iframe) {
                    echonow('<div><i style="font-size: 7pt;" class="glyphicon glyphicon-usd"></i> ' . LANG_UPDATEBACKUP_SAVE_FILES . '</div>');
                }
                $tar_name .= 'files_';

                $cms_dirs = scandir(DOC_ROOT . DIRECTORY_SEPARATOR . 'cms');
                foreach ($cms_dirs as $d) {
                    if ($d == '.' ||
                        $d == '..' ||
                        $d == 'cached' ||
                        $d == 'debmes' ||
                        $d == 'saverestore'
                    ) continue;
                    copyTree(DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/' . $d, DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/saverestore/temp/cms/' . $d);
                }
                if ($iframe) {
                    echonow('<div><i style="font-size: 7pt;" class="glyphicon glyphicon-usd"></i> ' . LANG_UPDATEBACKUP_DONE . '</div>');
                }
            }


            // packing into tar.gz
            $tar_name .= date('Y-m-d__H-i-s');
            $tar_name .= '.tgz';

            if (isset($out['BACKUP']))
                $tar_name = 'backup_' . $tar_name;

            if ($iframe) {
                echonow('<div><i style="font-size: 7pt;" class="glyphicon glyphicon-usd"></i> ' . LANG_UPDATEBACKUP_BACKUP_PACKEGE_TO . ' <b>' . $tar_name . '</b></div>');
            }

            $archive_path = DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/saverestore' . DIRECTORY_SEPARATOR . $tar_name;
            $error_message = '';
            $result = $this->createTarArchiveFromDirectory(DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/saverestore/temp', $archive_path, $error_message);
            if (!$result) {
                DebMes('Backup archive creation failed: ' . $error_message, 'restore');
                if ($iframe) {
                    echonow('<div><i style="font-size: 7pt;" class="glyphicon glyphicon-remove-sign"></i> ' . htmlspecialchars($error_message) . '</div>', 'red');
                }
                return false;
            }

            if ($iframe) {
                echonow('<div><i style="font-size: 7pt;" class="glyphicon glyphicon-usd"></i> ' . LANG_UPDATEBACKUP_DONE . '</div>');
            }


            if (defined('SETTINGS_BACKUP_PATH') && SETTINGS_BACKUP_PATH != '' && file_exists(DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/saverestore/' . $tar_name)) {
                if ($iframe) {
                    echonow('<div><i style="font-size: 7pt;" class="glyphicon glyphicon-usd"></i> ' . LANG_UPDATEBACKUP_COPY_TO . ' ' . $dest . $tar_name . '</div>');
                }
                $dest = SETTINGS_BACKUP_PATH;
                @copy(DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/saverestore/' . $tar_name, $dest . $tar_name);
                if ($iframe) {
                    echonow('<div><i style="font-size: 7pt;" class="glyphicon glyphicon-usd"></i> ' . LANG_UPDATEBACKUP_DONE . '</div>');
                }
            }


        }
        return $tar_name;
    }

    /**
     * Title
     *
     * Description
     *
     * @access public
     */
    function restoredatabase($filename)
    {
        if (SQLRestoreDBDump($filename)) {
            $files_to_remove = array(
                ROOT . 'database_backup/db.sql',
                ROOT . 'database_backup/db.sql.prev',
                ROOT . 'database_backup/db_history.sql',
                ROOT . 'database_backup/db_history.sql.prev'
            );
            foreach ($files_to_remove as $file) {
                if (file_exists($file)) {
                    DebMes("Removing current db state file: " . $file, "restore");
                    unlink($file);
                }
            }
            DebMes("DB restored", "restore");
            SQLExec("DELETE FROM cached_values");
            setGlobal('cycle_mainRun', time());
            return true;
        } else {
            DebMes("Failed to restore DB:\n" . implode("\n", $output), "restore");
            return false;
        }
    }

    /**
     * Title
     *
     * Description
     *
     * @access public
     */
    function backupdatabase($filename)
    {
        return SQLMakeDBDump($filename);
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

    /*
    */

    function ftpget($conn_id, $local_file, $remote_file, $mode)
    {
        global $lset_dirs;
        $l_dir = dirname($local_file);
        if (!isset($lset_dirs[$l_dir])) {
            //  echo "zz";
            if (!is_dir($l_dir)) {
                $this->lmkdir($l_dir);
            }
            $lset_dirs[$l_dir] = 1;
        }
        $res = ftp_get($conn_id, $local_file, $remote_file, $mode);
        return $res;
    }

    function ftpmkdir($conn_id, $ftp_dir)
    {
        global $set_dirs;

        $tmp = explode('/', $ftp_dir);
        $res_dir = $tmp[0];
        $tmpCnt = count($tmp);

        for ($i = 1; $i < $tmpCnt; $i++) {
            $res_dir .= '/' . $tmp[$i];

            if (!isset($set_dirs[$res_dir])) {
                $set_dirs[$res_dir] = 1;

                if (!@ftp_chdir($conn_id, $res_dir)) {
                    ftp_mkdir($conn_id, $res_dir);
                }
            }
        }
    }

    function ftpdelete($conn_id, $filename)
    {
        $res = ftp_delete($conn_id, $filename);
        return $res;
    }


    function ftpput($conn_id, $remote_file, $local_file, $mode)
    {
        global $set_dirs;
        $ftp_dir = dirname($remote_file);
        if (!isset($set_dirs[$ftp_dir])) {
            if (!@ftp_chdir($conn_id, $ftp_dir)) {
                $this->ftpmkdir($conn_id, $ftp_dir);
            }
            $set_dirs[$ftp_dir] = 1;
        }
        $res = ftp_put($conn_id, $remote_file, $local_file, $mode);
        return $res;
    }

    function autoUpdateSystem()
    {
        $this->getConfig();
        $delay = $this->config['UPDATE_AUTO_DELAY'];
        if (!$delay) $delay = 1;
        DebMes("Starting auto update ($delay)", 'auto_update');

        $update_url = $this->getUpdateURL();

        $github_feed_url = $this->getUpdateFeedURL($update_url);
        $github_feed = '';
        if ($github_feed_url != '') {
            $github_feed = getURL($github_feed_url, 30 * 60);
        }

        if ($github_feed != '') {
            $tmp = GetXMLTree($github_feed);
            if (is_array($tmp)) {
                $data = XMLTreeToArray($tmp);
                $items = $data['feed']['entry'];
            } else {
                $items = false;
            }
            if (is_array($items)) {
                $latest_id = $this->normalizeCommitId($items[0]['id']['textvalue']);
                $latest_tm = strtotime($items[0]['updated']['textvalue']);
                //$latest_id = 'force_new_id';
                $current_latest_id = isset($this->config['LATEST_UPDATED_ID']) ? $this->normalizeCommitId($this->config['LATEST_UPDATED_ID']) : '';
                if ($latest_id && ($latest_id == $current_latest_id)) {
                    DebMes("Already updated to the latest version ($latest_id)", 'auto_update');
                    return 0;
                } else {
                    DebMes("Need to update to $latest_id on top of " . $current_latest_id, 'auto_update');
                }
                $current_delay = round((time() - $latest_tm) / (24 * 60 * 60), 2);
                if ($latest_tm && $current_delay < $delay) {
                    DebMes("Update is too fresh ($current_delay vs $delay)", 'auto_update');
                    return 0;
                }
                // ok, downloading update
                set_time_limit(0);
                // updating main system
                logAction('system_update', 'Auto-update');
                $out = array();
                $res = $this->admin($out);
                DebMes("Getting latest version and making backup", 'auto_update');
                $res = $this->getLatest($out, 1);
                global $restore;
                global $folder;
                $restore = 'master.tgz';
                $folder = '';
                DebMes("Applying update from " . basename($this->url), 'auto_update');
                $res = $this->upload($out, 1);
                removeTree(DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/saverestore/temp', 1);
                // now downloading updates for modules
                if ($this->config['UPDATE_AUTO_PLUGINS']) {
                    DebMes("Getting updates for modules", 'auto_update');
                    global $mode;
                    $mode = '';
                    $_GET['op'] = 'iframe';
                    $out = array();
                    include_once(DIR_MODULES . "market/market.class.php");
                    $mkt = new market();
                    $mkt->category_id = 'all';
                    $mkt->admin($out);
                    logAction('market_update', 'Auto-update');
                    $res = $mkt->updateAll($mkt->can_be_updated_new, 1);
                    if ($res) {
                        $mkt->removeTree(DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/saverestore/temp', 1);
                    }
                }
                DebMes("Update installed, need to reboot", 'auto_update');
                setRebootRequired('auto_update');
            }
        }
    }

    function processSubscription($event_name, $details = '')
    {
        if ($event_name == 'HOURLY') {
            $this->getConfig();
            if ($this->config['UPDATE_AUTO'] && (int)date('H') == $this->config['UPDATE_AUTO_TIME']) {
                DebMes("Processing auto update", 'auto_update');
                $this->autoUpdateSystem();
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
    function install($parent_name = "")
    {
        if (!Is_Dir(DOC_ROOT . DIRECTORY_SEPARATOR . "cms/saverestore")) {
            mkdir(DOC_ROOT . DIRECTORY_SEPARATOR . "cms/saverestore", 0777);
        }
        parent::install($parent_name);
    }
// --------------------------------------------------------------------
}

/*
*
* TW9kdWxlIGNyZWF0ZWQgU2VwIDE2LCAyMDA4IHVzaW5nIFNlcmdlIEouIHdpemFyZCAoQWN0aXZlVW5pdCBJbmMgd3d3LmFjdGl2ZXVuaXQuY29tKQ==
*
*/
?>
