<?php

/**
 * Обновление и восстановление системы.
 * 2026-10-23 — загрузка архива вынесена в Core\Deploy\ArchiveDownloader:
 *              прогресс в журнале, таймауты и повтор через IPv4 вместо
 *              «повисшего» curl с единственным TIMEOUT=600.
 * 2026-10-23 — архив скачивается по конкретному коммиту, цель обновления
 *              сохраняется в update.pending.json, а версия восстанавливается
 *              после обрыва запроса; кэш ленты сбрасывается после установки.
 * 2026-10-22 — getUpdateStatus() для дашборда и /api/v1/panel/updates.
 */
class saverestore extends module
{
    /** Сколько секунд можно показывать закэшированную ленту обновлений. */
    const UPDATE_FEED_TTL = 1800;

    /** Файл с коммитом, который скачан и уже применён, но ещё не записан в конфиг. */
    const UPDATE_PENDING_FILE = 'update.pending.json';

    var $mode;
    var $view_mode;
    var $edit_mode;
    var $ajax;

    /** @var array|null Цель текущего обновления (коммит + отпечаток архива). */
    var $target_update = null;

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
        $this->action = 'admin';
        $this->admin($out);
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

    /**
     * Привести идентификатор коммита из Atom-ленты к чистому SHA.
     *
     * GitHub отдаёт `tag:github.com,2008:Grit::Commit/<sha>`, Gitea — ссылку
     * на `/commit/<sha>`. Раньше вырезался только GitHub-префикс, поэтому для
     * других хостингов в конфиг попадала целая ссылка и сравнение версий
     * больше никогда не совпадало.
     * 2026-10-23 — поддержаны оба формата и короткие SHA.
     */
    function normalizeCommitId($commit_id)
    {
        $commit_id = trim((string)$commit_id);
        if ($commit_id == '') {
            return '';
        }
        if (preg_match('#(?:Grit::Commit/|/commit/)([0-9a-f]{7,64})/?$#i', $commit_id, $match)) {
            return strtolower($match[1]);
        }
        if (preg_match('#^[0-9a-f]{7,64}$#i', $commit_id)) {
            return strtolower($commit_id);
        }
        return trim(preg_replace('/.+Commit\//is', '', $commit_id));
    }

    function normalizeUpdateBranch($branch)
    {
        return mb_strtoupper(trim((string)$branch));
    }

    /**
     * Разобрать Atom-ленту обновлений.
     *
     * Один общий разбор вместо трёх копий в admin(), getUpdateStatus() и
     * autoUpdateSystem(): раньше они по-разному переживали ленту из одной
     * записи и по-разному трактовали ошибки.
     * 2026-10-23 — вынесено в отдельный метод.
     */
    function parseUpdateFeedEntries($feed_xml)
    {
        if (!is_string($feed_xml) || trim($feed_xml) === '') {
            return array();
        }
        $tree = GetXMLTree($feed_xml);
        if (!is_array($tree)) {
            return array();
        }
        $data = XMLTreeToArray($tree);
        $entries = $data['feed']['entry'] ?? array();
        if (!is_array($entries) || $entries === array()) {
            return array();
        }
        // Единственная запись приходит не списком, а ассоциативным массивом.
        if (isset($entries['id'])) {
            $entries = array($entries);
        }
        return array_values($entries);
    }

    /**
     * Загрузить и разобрать ленту обновлений.
     *
     * $cache_ttl = 0 — запросить свежую ленту, не трогая кэш. Именно так
     * определяется версия, которую собираемся установить.
     * 2026-10-23 — добавлено.
     */
    function getUpdateFeedEntries($feed_url, $cache_ttl = 0)
    {
        if ($feed_url == '') {
            return array();
        }
        $options = array(
            CURLOPT_HTTPHEADER => array('Accept: application/xml')
        );
        // TTL=0 означает «не доверять кэшу», но ответ всё равно нужно
        // положить в кэш: иначе принудительная проверка не обновит данные
        // для следующих плановых проверок.
        $fetch_ttl = (int)$cache_ttl > 0 ? (int)$cache_ttl : 1;
        $feed = getURL($feed_url, $fetch_ttl, '', '', false, $options);
        if ($feed === false || $feed === '') {
            return array();
        }
        return $this->parseUpdateFeedEntries($feed);
    }

    function getUpdateEntryCommit($entry)
    {
        if (!is_array($entry)) {
            return '';
        }
        return $this->normalizeCommitId($entry['id']['textvalue'] ?? '');
    }

    function getUpdateEntryTimestamp($entry)
    {
        if (!is_array($entry) || empty($entry['updated']['textvalue'])) {
            return 0;
        }
        $timestamp = strtotime((string)$entry['updated']['textvalue']);
        return $timestamp !== false ? (int)$timestamp : 0;
    }

    /**
     * Определить коммит и ветку, на которые указывает источник обновлений.
     *
     * Это единственное место, где версия берётся из ленты. Дальше архив
     * скачивается именно по этому коммиту, поэтому записанная версия всегда
     * совпадает с содержимым установленных файлов.
     * 2026-10-23 — добавлено вместо readLatestUpdateInfo().
     */
    function resolveUpdateTarget($cache_ttl = 0)
    {
        $update_url = $this->getUpdateURL();
        $result = array(
            'commit' => '',
            'branch' => $this->normalizeUpdateBranch($this->getUpdateBranch($update_url)),
            'updated_ts' => 0,
            'update_url' => $update_url,
            'feed_url' => $this->getUpdateFeedURL($update_url),
        );
        if ($result['feed_url'] == '') {
            return $result;
        }
        $entries = $this->getUpdateFeedEntries($result['feed_url'], $cache_ttl);
        if ($entries === array()) {
            return $result;
        }
        $latest = reset($entries);
        $result['commit'] = $this->getUpdateEntryCommit($latest);
        $result['updated_ts'] = $this->getUpdateEntryTimestamp($latest);
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
        foreach (array('.env', '.env.local') as $envFile) {
            $env_path = rtrim($path, DIRECTORY_SEPARATOR . '/') . DIRECTORY_SEPARATOR . $envFile;
            if (file_exists($env_path)) {
                @unlink($env_path);
            }
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

        // Remove legacy Connect images
        $connect_images = array(
            $path . DIRECTORY_SEPARATOR . 'img' . DIRECTORY_SEPARATOR . 'connect_back_block.png',
            $path . DIRECTORY_SEPARATOR . 'img' . DIRECTORY_SEPARATOR . 'modules' . DIRECTORY_SEPARATOR . 'connect.png',
        );
        foreach ($connect_images as $img) {
            if (file_exists($img)) {
                @unlink($img);
            }
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
            DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/deploy' => 'Каталог cms/deploy недоступен для записи.',
            DOC_ROOT . DIRECTORY_SEPARATOR . 'database_backup' => 'Каталог database_backup недоступен для записи.',
        );

        foreach ($paths as $path => $message) {
            if (!$this->canWritePath($path)) {
                $issues[] = $message . ' [' . $this->getRelativeDocRootPath($path) . ']';
            }
        }

        try {
            $deployment = new \MajorDoMo\Core\Deploy\Deployment(DOC_ROOT);
            foreach ($deployment->preflight() as $check) {
                if (!empty($check['fatal']) && empty($check['ok'])) {
                    $issues[] = 'Деплой: ' . $check['title'] . ' — ' . $check['details'];
                }
            }
        } catch (\Throwable $error) {
            $issues[] = 'Деплой: не удалось проверить окружение — ' . $error->getMessage();
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
        $basename = basename($relative_path);
        if ($basename === '.env' || $basename === '.env.local' || preg_match('/^\.env\..+\.local$/', $basename)) {
            return true;
        }
        if (preg_match('#^cms(/|$)#i', $relative_path)) {
            return true;
        }
        if (preg_match('#^(backup|database_backup|vendor|node_modules)(/|$)#i', $relative_path)) {
            return true;
        }
        if (preg_match('#^\.git(/|$)#i', $relative_path)) {
            return true;
        }
        return false;
    }

    function removeObsoleteSystemUpdatePaths($iframe = 0)
    {
        // Пути выведенных из проекта файлов и каталогов живут в
        // deploy/removals.json: их можно review'ить и они не зависят от того,
        // что сейчас лежит у пользователя в кастомных папках.
        $removals = new \MajorDoMo\Core\Deploy\FileRemovals(DOC_ROOT);
        $result = $removals->apply(function (string $kind, string $path) use ($iframe): void {
            DebMes('Removed obsolete system ' . $kind . ': ' . $path, 'restore');
            if ($iframe) {
                echonow('<div><i style="font-size: 7pt;" class="glyphicon glyphicon-usd"></i> Removed obsolete system ' . htmlspecialchars($kind) . ' ' . htmlspecialchars($path) . '</div>');
            }
        });
        DebMes('System update removals: ' . json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'restore');
        return $result;
    }

    function getObsoleteSystemModules()
    {
        return array(
            'connect',
            'control_access',
            'dashboard',
            'dateselect',
            'layouts',
            'master',
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

        $removals = new \MajorDoMo\Core\Deploy\FileRemovals(DOC_ROOT);
        $new_lookup = array_fill_keys($new_manifest, 1);
        foreach ($old_manifest as $relative_path) {
            $relative_path = $this->normalizeManifestPath($relative_path);
            if (isset($new_lookup[$relative_path]) || $this->isProtectedSystemUpdatePath($relative_path)) {
                continue;
            }

            try {
                $removed = $removals->removePath($relative_path, false, function (string $kind, string $path) use ($iframe): void {
                    DebMes('Removed file deleted from system update: ' . $path, 'restore');
                    if ($iframe) {
                        echonow('<div><i style="font-size: 7pt;" class="glyphicon glyphicon-usd"></i> Removed deleted system file ' . htmlspecialchars($path) . '</div>');
                    }
                });
            } catch (\Throwable $error) {
                // Повреждённая запись манифеста не должна мешать обновлению
                // остальных файлов, но обязана попасть в журнал.
                DebMes('Cannot remove file deleted from system update ' . $relative_path . ': ' . $error->getMessage(), 'restore', 'warning');
                continue;
            }
            if (!$removed) {
                DebMes('File deleted from system update is already absent: ' . $relative_path, 'restore');
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
    }

    function cleanupObsoleteSystemModules($iframe = 0)
    {
        $this->removeObsoleteModuleInstallMarkers($iframe);
        $this->cleanupObsoleteModuleDatabaseRows($iframe);
        // Миграции выполняет Deployment после composer/npm, чтобы новый код и
        // зависимости уже были на диске.
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
        $maintenance = new \MajorDoMo\Core\Cycles\CycleMaintenance(DOC_ROOT);
        $script = basename($_SERVER['SCRIPT_FILENAME'] ?? '');
        $ownerCycle = PHP_SAPI === 'cli' && preg_match('/^cycle_[A-Za-z0-9_]+\.php$/D', $script)
            ? basename($script, '.php') : '';
        if (PHP_SAPI === 'cli' && $script === 'core_worker.php') $ownerCycle = 'cycle_core';
        $maintenance->begin($ownerCycle);
        try {
            if (!$maintenance->supervisorRunning()) {
                // No supervisor means no child processes to quiesce. Waiting for
                // an acknowledgement here would block every update on an
                // installation where cycle.php is managed manually or is stopped.
                DebMes('System update: cycle supervisor is not running, no pause needed', 'restore');
                if ($iframe) {
                    echonow('<div><i style="font-size: 7pt;" class="glyphicon glyphicon-usd"></i> Фоновые процессы не запущены (cycle.php offline) — останавливать нечего.</div>');
                }
                return $maintenance;
            }
            if ($iframe) {
                echonow('<div><i style="font-size: 7pt;" class="glyphicon glyphicon-usd"></i> Останавливаю циклы перед заменой файлов...</div>');
            }
            $maintenance->waitUntilReady(
                \MajorDoMo\Core\Cycles\CycleMaintenance::READY_TIMEOUT,
                function (int $elapsed, array $blocked) use ($iframe) {
                    if (!$iframe) return;
                    $message = 'Ждём остановки фоновых процессов: ' . $elapsed . ' с';
                    if ($blocked !== array()) {
                        $message .= '. Не завершаются: ' . implode(', ', $blocked);
                    }
                    echonow('<div><i style="font-size: 7pt;" class="glyphicon glyphicon-usd"></i> ' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '...</div>');
                }
            );
            DebMes('System update: cycles stopped, applying update files', 'restore');
            if ($iframe) {
                echonow('<div>Циклы остановлены. Применяю файлы обновления...</div>');
            }
            return $maintenance;
        } catch (\Throwable $error) {
            $maintenance->abort($error->getMessage());
            throw $error;
        }
    }

    function getPendingUpdateFile()
    {
        return DOC_ROOT . DIRECTORY_SEPARATOR . 'cms' . DIRECTORY_SEPARATOR . 'saverestore' . DIRECTORY_SEPARATOR . self::UPDATE_PENDING_FILE;
    }

    /**
     * Прочитать цель обновления, скачанную предыдущим запросом.
     *
     * Нужно, чтобы `mode=getlatest` → редирект → `mode=upload` не терял
     * коммит и не подменял его свежей (но уже другой) лентой.
     * 2026-10-23 — добавлено.
     */
    function readPendingUpdate()
    {
        $file = $this->getPendingUpdateFile();
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode((string)@file_get_contents($file), true);
        if (!is_array($data)) {
            return null;
        }
        $data['commit'] = $this->normalizeCommitId($data['commit'] ?? '');
        $data['branch'] = $this->normalizeUpdateBranch($data['branch'] ?? '');
        return $data;
    }

    function writePendingUpdate(array $data)
    {
        $file = $this->getPendingUpdateFile();
        $directory = dirname($file);
        if (!is_dir($directory)) {
            @mkdir($directory, 0777, true);
        }
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }
        // Атомарная замена: параллельный запрос не должен прочитать половину
        // JSON и решить, что цели обновления нет.
        $temporary = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($temporary, $json, LOCK_EX) === false) {
            return false;
        }
        if (!@rename($temporary, $file)) {
            @unlink($temporary);
            return false;
        }
        return true;
    }

    function updatePendingUpdate(array $changes)
    {
        $data = $this->readPendingUpdate();
        if ($data === null) {
            return false;
        }
        $data = array_merge($data, $changes);
        $data['updated_at'] = date('c');
        return $this->writePendingUpdate($data);
    }

    function clearPendingUpdate()
    {
        $file = $this->getPendingUpdateFile();
        if (is_file($file)) {
            @unlink($file);
        }
    }

    function archiveFingerprint($file)
    {
        if (!is_string($file) || $file === '' || !is_file($file)) {
            return '';
        }
        $hash = @sha1_file($file);
        return is_string($hash) ? $hash : '';
    }

    /**
     * Взять коммит, который относится именно к применяемому архиву.
     *
     * Отпечаток архива защищает от чужого/устаревшего pending-файла: если
     * master.tgz подменили, версию не записываем, а не угадываем.
     * 2026-10-23 — добавлено.
     */
    function resolveAppliedUpdateTarget($is_system_update, $archive_path)
    {
        if (!$is_system_update) {
            return null;
        }
        $candidate = is_array($this->target_update) ? $this->target_update : $this->readPendingUpdate();
        if (!is_array($candidate)) {
            return null;
        }
        $commit = $this->normalizeCommitId($candidate['commit'] ?? '');
        if ($commit === '') {
            return null;
        }
        $expected = (string)($candidate['archive_sha1'] ?? '');
        $actual = $this->archiveFingerprint($archive_path);
        if ($expected === '' || $actual === '' || !hash_equals($expected, $actual)) {
            DebMes('System update: archive fingerprint does not match the pending target, installed version will not be recorded', 'restore', 'warning');
            return null;
        }
        return array(
            'commit' => $commit,
            'branch' => $this->normalizeUpdateBranch($candidate['branch'] ?? ''),
            'archive_sha1' => $actual,
        );
    }

    /**
     * Сбросить кэш ленты обновлений.
     *
     * После успешной установки старый ответ getURL() ещё до TTL утверждает,
     * что последний коммит — прежний. Тогда UI честно установленной версии
     * снова показывает «есть обновление».
     * 2026-10-23 — добавлено.
     */
    function clearUpdateFeedCache()
    {
        $feed_url = $this->getUpdateFeedURL($this->getUpdateURL());
        if ($feed_url != '' && function_exists('clearURLCache')) {
            clearURLCache($feed_url);
        }
    }

    /**
     * Записать установленную версию в конфиг модуля.
     *
     * Единственное место записи LATEST_UPDATED_ID. Значение приходит из
     * скачанного архива (resolveUpdateTarget в getLatest), а не из повторно
     * прочитанной ленты.
     * 2026-10-23 — вынесено из commitSystemUpdate(), добавлена дата в конфиг.
     */
    function recordInstalledVersion($commit, $branch)
    {
        $latest_id = $this->normalizeCommitId($commit);
        if ($latest_id == '') {
            DebMes('System update: installed commit is unknown, version is not recorded', 'restore', 'error');
            return false;
        }
        $update_branch = $this->normalizeUpdateBranch($branch);
        $timestamp = date('d.m.Y H:i:s');

        try {
            $this->getConfig();
            $this->config['LATEST_UPDATED_ID'] = $latest_id;
            $this->config['LATEST_CURR_BRANCH'] = $update_branch;
            $this->config['LATEST_UPDATED_DATE'] = $timestamp;
            $this->config['LATEST_UPDATED_TS'] = time();
            $this->saveConfig();
        } catch (\Throwable $error) {
            DebMes('Cannot save latest update version: ' . $error->getMessage(), 'restore', 'error');
            return false;
        }

        // Кэш ленты и pending-файл — после успешной записи в БД: если запись
        // упала, следующий запрос повторит её по pending-файлу.
        try {
            $this->clearUpdateFeedCache();
        } catch (\Throwable $error) {
            DebMes('Cannot clear update feed cache: ' . $error->getMessage(), 'restore', 'warning');
        }
        $this->clearPendingUpdate();

        try {
            setGlobal('LatestUpdateId', $latest_id);
            setGlobal('LatestUpdateBranch', $update_branch);
            setGlobal('LatestUpdateTimestamp', $timestamp);
        } catch (\Throwable $error) {
            // Глобалы — только для старых шаблонов; дата уже есть в конфиге.
            DebMes('Cannot publish latest update globals: ' . $error->getMessage(), 'restore', 'warning');
        }

        DebMes('System update: installed version recorded as ' . $update_branch . '_' . $latest_id, 'restore');
        return true;
    }

    /**
     * Зафиксировать версию только после успешной установки и перезапуска.
     * Вызывается из upload() после restart/reload, а не в середине пайплайна.
     * 2026-10-23 — больше не читает ленту: коммит уже пришёл из архива.
     */
    function commitSystemUpdate($update_from, $update_to, $update_branch, $deployment)
    {
        $recorded = $this->recordInstalledVersion($update_to, $update_branch);
        $latest_id = $this->normalizeCommitId($update_to);
        $branch = $this->normalizeUpdateBranch($update_branch);

        try {
            \MajorDoMo\Core\Events\SystemEvents::systemUpdated($update_from, $latest_id !== '' ? $latest_id : $update_to, $branch);
        } catch (\Throwable $error) {
            DebMes('Cannot emit system updated event: ' . $error->getMessage(), 'restore', 'warning');
        }

        if ($deployment !== null) {
            try {
                $deployment->writeState(array(
                    'status' => 'success',
                    'from' => $update_from,
                    'to' => $latest_id !== '' ? $latest_id : $update_to,
                    'branch' => $branch,
                ));
            } catch (\Throwable $error) {
                DebMes('Cannot write deploy state: ' . $error->getMessage(), 'restore', 'warning');
            }
        }

        return $recorded;
    }

    /**
     * Восстановить запись версии, если предыдущий запрос умер между заменой
     * файлов и сохранением конфига (например, во время restart службы).
     *
     * Действует только на pending-файл со статусом applied: до этого файлы
     * ещё не применены, после — версия уже записана.
     * 2026-10-23 — добавлено.
     */
    function reconcilePendingUpdate()
    {
        $pending = $this->readPendingUpdate();
        if (!is_array($pending) || ($pending['status'] ?? '') !== 'applied') {
            return false;
        }
        $applied_at = strtotime((string)($pending['applied_at'] ?? ''));
        // Недавний applied принадлежит ещё идущему запросу: он сам запишет
        // версию после рестарта. Восстанавливаем только «зависшие» записи.
        if ($applied_at > 0 && (time() - $applied_at) < 30) {
            return false;
        }
        if ($applied_at > 0 && (time() - $applied_at) > 7 * 24 * 60 * 60) {
            DebMes('System update: stale applied target discarded', 'restore', 'warning');
            $this->clearPendingUpdate();
            return false;
        }
        $recorded = $this->recordInstalledVersion($pending['commit'] ?? '', $pending['branch'] ?? '');
        if ($recorded) {
            DebMes('System update: installed version restored from the pending target', 'restore');
        }
        return $recorded;
    }

    /**
     * Автообновление выполняется внутри дочернего процесса cycle.php. Ему
     * нельзя вызывать systemctl restart: systemd остановит всю cgroup вместе с
     * самим updater'ом. Такой процесс завершает паузу и просит reload.
     */
    function isCycleProcess()
    {
        if (PHP_SAPI !== 'cli') return false;
        $script = basename((string)($_SERVER['SCRIPT_FILENAME'] ?? ($GLOBALS['argv'][0] ?? '')));
        return (bool)preg_match('/^cycle_[A-Za-z0-9_]+\.php$/D', $script) || $script === 'core_worker.php';
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
        // Дашборд проверяет обновления отдельным AJAX; страница здесь не нужна.
        if (gr('ajax') && gr('op') === 'check_updates') {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            echo json_encode($this->getUpdateStatus((int)gr('check_now') === 1));
            exit;
        }

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
        // Если прошлый запрос умер после замены файлов, версия уже есть в
        // pending-файле — записываем её до того, как строить страницу.
        $this->reconcilePendingUpdate();
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

        // Текущая версия показывается независимо от доступности ленты: это
        // локальные данные, а не результат сетевого запроса.
        $out['UPDATE_CURR_BRANCH'] = $this->normalizeUpdateBranch($this->getUpdateBranch($update_url));
        $out['LATEST_UPDATED_ID'] = isset($this->config['LATEST_UPDATED_ID']) ? $this->normalizeCommitId($this->config['LATEST_UPDATED_ID']) : '';
        $out['LATEST_UPDATED_ID_SLICE'] = mb_strtoupper(substr($out['LATEST_UPDATED_ID'], 0, 7));
        $out['LATEST_CURR_BRANCH'] = isset($this->config['LATEST_CURR_BRANCH']) ? $this->normalizeUpdateBranch($this->config['LATEST_CURR_BRANCH']) : '';
        if ($out['LATEST_CURR_BRANCH'] == '' && $out['LATEST_UPDATED_ID'] != '') {
            $out['LATEST_CURR_BRANCH'] = $out['UPDATE_CURR_BRANCH'];
        }
        $out['LATEST_UPDATED_TIME'] = gg('LatestUpdateTimestamp');
        if ($out['LATEST_UPDATED_TIME'] == '' && !empty($this->config['LATEST_UPDATED_DATE'])) {
            $out['LATEST_UPDATED_TIME'] = $this->config['LATEST_UPDATED_DATE'];
        }

        $github_feed_url = $this->getUpdateFeedURL($update_url);
        $op = isset($_GET['op']) ? $_GET['op'] : '';
        $cache_timeout = $force_check ? 0 : self::UPDATE_FEED_TTL;
        $items = $this->getUpdateFeedEntries($github_feed_url, $cache_timeout);

        $out['UPDATES'] = array();
        if ($items !== array()) {
            $iteration = 0;
            foreach ($items as $value) {
                $itm = array();
                $itm['ID'] = $this->getUpdateEntryCommit($value);
                $itm['MYVERSION'] = ($itm['ID'] == $out['LATEST_UPDATED_ID']) ? 1 : 0;
                $itm['TITLE'] = trim((string)($value['title']['textvalue'] ?? ''));
                $itm['AUTHOR'] = (string)($value['author']['name']['textvalue'] ?? '');
                $itm['LINK'] = (string)($value['link']['href'] ?? '');
                $itm['LINK_URL'] = urlencode($itm['LINK']);
                $itm['UPDATED'] = $this->getUpdateEntryTimestamp($value);
                $itm['UPDATE_TEXT'] = $itm['UPDATED'] > 0 ? date('d.m.Y H:i', $itm['UPDATED']) : '';
                $itm['DESC_UPDATE'] = '';
                if (isset($value['content']['textvalue'])) {
                    $content = preg_split('/\\r\\n?|\\n/', $value['content']['textvalue']);
                    if (isset($content[3])) {
                        $itm['DESC_UPDATE'] = strip_tags($content[3]);
                    }
                }

                $out['UPDATES'][] = $itm;
                $iteration++;
                if ($iteration >= 5) {
                    break;
                }
            }

            $out['LATEST_ID'] = $out['UPDATES'][0]['ID'] ?? '';
            if ($out['LATEST_ID'] != '' && $out['LATEST_ID'] == $out['LATEST_UPDATED_ID'] && $out['LATEST_CURR_BRANCH'] == $out['UPDATE_CURR_BRANCH']) {
                $out['NO_NEED_TO_UPDATE'] = 1;
            }
            if ($this->ajax && $op == 'check_updates') {
                echo json_encode(array(
                    'needUpdate' => isset($out['NO_NEED_TO_UPDATE']) ? '0' : '1',
                    'currBranch' => $out['LATEST_CURR_BRANCH'],
                    'current_version' => $out['LATEST_UPDATED_ID'],
                    'commitUrl' => $out['PROJECT_COMMIT_URL']
                ));
                exit;
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


    /**
     * Проверка обновлений для дашборда и служебного API панели.
     * 2026-10-22 — один расчёт вместо echo+exit в admin(); legacy AJAX
     * check_updates теперь тоже вызывает этот метод.
     */
    function getUpdateStatus($force = false)
    {
        $this->getConfig();
        // Пропущенная запись версии (обрыв запроса во время рестарта)
        // восстанавливается здесь, до сравнения с лентой.
        $this->reconcilePendingUpdate();
        $this->getConfig();
        $update_url = $this->getUpdateURL();
        $commit_url = defined('PROJECT_COMMIT_URL') ? PROJECT_COMMIT_URL : '';
        $current_branch = $this->normalizeUpdateBranch($this->config['LATEST_CURR_BRANCH'] ?? '');
        $update_branch = $this->normalizeUpdateBranch($this->getUpdateBranch($update_url));
        $current_version = $this->normalizeCommitId($this->config['LATEST_UPDATED_ID'] ?? '');
        if ($current_branch === '' && $current_version !== '') {
            $current_branch = $update_branch;
        }
        $result = array(
            'needUpdate' => '0',
            'currBranch' => $current_branch,
            'current_version' => $current_version,
            'latest_version' => '',
            'commitUrl' => $commit_url,
        );
        if ($update_url === '') {
            return $result;
        }
        $feed_url = $this->getUpdateFeedURL($update_url);
        if ($feed_url === '') {
            return $result;
        }
        $entries = $this->getUpdateFeedEntries($feed_url, $force ? 0 : self::UPDATE_FEED_TTL);
        if ($entries === array()) {
            return $result;
        }
        $latest_version = $this->getUpdateEntryCommit(reset($entries));
        if ($latest_version === '') {
            return $result;
        }
        $result['latest_version'] = $latest_version;
        $result['needUpdate'] = ($latest_version === $current_version && $current_branch === $update_branch) ? '0' : '1';
        return $result;
    }

    function getUpdateURL($link = '')
    {
        $this->getConfig();

        if (($this->config['MASTER_UPDATE_URL'] ?? '') != '') {
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

        if (preg_match('#/archive/(?:refs/(?:heads|tags)/)?([^/]+)\.tar\.gz$#i', $path, $m)) {
            return preg_replace('#/archive/(?:refs/(?:heads|tags)/)?[^/]+\.tar\.gz#i', '/commits/' . $m[1] . '.atom', $update_url);
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

        if (preg_match('#/archive/(?:refs/(?:heads|tags)/)?([^/]+)\.tar\.gz$#i', $path, $m)) {
            return $m[1];
        }

        return str_replace('.tar.gz', '', basename($path));
    }

    /**
     * Ссылка на архив ровно одного коммита.
     *
     * Скачивать ветку целиком нельзя: пока идёт запрос, ветка уже может
     * уехать вперёд, и записанный из ленты коммит перестаёт соответствовать
     * установленным файлам. Пустая строка означает «источник не умеет
     * отдавать архив по коммиту» — тогда обновление начинать нельзя.
     * 2026-10-23 — вместо возврата исходного URL.
     */
    function getArchiveURLForCommit($update_url, $commit)
    {
        $commit = trim((string)$commit);
        if ($commit === '') {
            return '';
        }
        $url_info = parse_url($update_url);
        $path = isset($url_info['path']) ? $url_info['path'] : $update_url;

        if (preg_match('#/-/archive/([^/]+)/([^/]+)\.tar\.gz$#i', $path, $m)) {
            $archive_name = str_replace($m[1], $commit, $m[2]);
            if ($archive_name == $m[2]) {
                $archive_name = $commit;
            }
            return str_replace($m[0], '/-/archive/' . $commit . '/' . $archive_name . '.tar.gz', $update_url);
        }

        if (preg_match('#/archive/(?:refs/(?:heads|tags)/)?[^/]+\.tar\.gz#i', $path, $m)) {
            return str_replace($m[0], '/archive/' . $commit . '.tar.gz', $update_url);
        }

        return '';
    }

    /**
     * Строка прогресса загрузки архива.
     *
     * Вызывается из колбэка curl прямо во время передачи. Поэтому здесь нет
     * 16 КБ паддинга и DebMes, как в echonow(): на каждом шаге это заметно
     * тормозило саму загрузку. Пятьсот байт и flush достаточно, потому что
     * воркер отдаётся с X-Accel-Buffering: no.
     */
    function outputProgress($text)
    {
        echo '<div><i style="font-size: 7pt;" class="glyphicon glyphicon-usd"></i> ' . htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8') . '</div>';
        echo str_repeat(' ', 512);
        flush();
        @ob_flush();
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
            DebMes($message, 'restore', 'error');
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

        // Сначала фиксируем точный коммит, который собираемся установить:
        // архив скачивается по нему, поэтому записанная версия не может
        // разойтись с содержимым файлов.
        $target = $this->resolveUpdateTarget(0);
        if ($target['commit'] === '') {
            // Свежая лента недоступна — пробуем кэш, но только чтобы не
            // терять обновление из-за временного сбоя сети.
            $target = $this->resolveUpdateTarget(self::UPDATE_FEED_TTL);
        }
        $download_url = $target['commit'] !== '' ? $this->getArchiveURLForCommit($url, $target['commit']) : '';
        if ($target['commit'] === '' || $download_url === '') {
            $message = $target['commit'] === ''
                ? 'Не удалось прочитать ленту коммитов: проверьте GIT_URL/MASTER_UPDATE_URL и доступность хостинга. Обновление не начато.'
                : 'Источник обновлений не отдаёт архив по конкретному коммиту. Обновление не начато, чтобы не записывать неизвестную версию.';
            DebMes('System update: ' . $message, 'restore', 'error');
            if ($iframe) {
                echonow('<div><i style="font-size: 7pt;" class="glyphicon glyphicon-remove-sign"></i> ' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</div>', 'red');
                return 0;
            }
            $this->redirect("?err_msg=" . urlencode($message));
        }
        $target['download_url'] = $download_url;
        $target['branch'] = $this->normalizeUpdateBranch($target['branch']);
        DebMes('System update: target ' . $target['branch'] . '_' . $target['commit'] . ' (' . $download_url . ')', 'restore');
        if ($iframe) {
            // Показываем выбранный коммит в журнале: так сразу видно, что
            // скачивается именно он, а не «последняя ветка» из кэша.
            echonow('<div><i style="font-size: 7pt;" class="glyphicon glyphicon-usd"></i> Коммит: '
                . htmlspecialchars(substr($target['commit'], 0, 7), ENT_QUOTES, 'UTF-8')
                . ' · ветка ' . htmlspecialchars($target['branch'], ENT_QUOTES, 'UTF-8') . '</div>');
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

        if ($iframe) {
            echonow('<div><i style="font-size: 7pt;" class="glyphicon glyphicon-chevron-right"></i> ' . LANG_UPDATEARHIVE_DONE . ' ' . $download_url . '</div>');
        }

        // Загрузчик общий с `deploy:update`: предел на подключение, обрыв при
        // простое, повтор через IPv4 и прогресс в журнале. Раньше «голый» curl
        // висел до системного таймаута, а окно показывало «Скачиваем архив»
        // без единой новой строки — снаружи это выглядело как зависание.
        $downloader = new \MajorDoMo\Core\Deploy\ArchiveDownloader(DOC_ROOT);
        $reported_at = 0;
        $reported_percent = -10;
        $download = $downloader->download(
            $download_url,
            $filename,
            function (int $downloaded, int $total, int $percent) use ($iframe, &$reported_at, &$reported_percent): void {
                if (!$iframe) {
                    return;
                }
                $now = time();
                if ($percent >= 0 && $percent < $reported_percent + 5 && $now - $reported_at < 3) {
                    return;
                }
                $reported_percent = max($reported_percent, $percent);
                $reported_at = $now;
                $text = number_format($downloaded / 1048576, 1, ',', ' ') . ' МБ';
                if ($total > 0) {
                    $text .= ' из ' . number_format($total / 1048576, 1, ',', ' ') . ' МБ (' . $percent . '%)';
                }
                $this->outputProgress('Скачано ' . $text);
            }
        );

        if ($download['ok']) {
            if ($download['insecure']) {
                echonow('<div><i style="font-size: 7pt;" class="glyphicon glyphicon-warning-sign"></i> Сертификат TLS не проверен: в системе нет корневых сертификатов</div>', 'orange');
            }
            if ($download['attempts'] > 1) {
                echonow('<div><i style="font-size: 7pt;" class="glyphicon glyphicon-warning-sign"></i> Загрузка удалась с попытки ' . (int)$download['attempts'] . '</div>', 'orange');
            }
            // Скорость видна отдельно от времени в колбэке прогресса: так сразу
            // понятно, тормозит сеть или вывод в браузер.
            $stats = is_array($download['stats'] ?? null) ? $download['stats'] : array();
            echonow('<div>Загрузка: ' . number_format((float)($stats['speed'] ?? 0) / 1048576, 2, ',', ' ') . ' МБ/с'
                . ', соединение ' . number_format((float)($stats['connect_time'] ?? 0), 2, ',', ' ') . ' с'
                . ', прогресс ' . number_format(((int)($stats['callback_ms'] ?? 0)) / 1000, 2, ',', ' ') . ' с'
                . (!empty($stats['ip']) ? ', ' . htmlspecialchars((string)$stats['ip']) : '')
                . (!empty($stats['http_version']) ? ', HTTP/' . htmlspecialchars((string)$stats['http_version']) : '')
                . '</div>');
            if ((string)($download['proxy'] ?? '') !== '') {
                echonow('<div>Прокси из окружения: ' . htmlspecialchars((string)$download['proxy']) . ' — curl идёт через него</div>', 'orange');
            }

            // Отпечаток и pending-файл появляются до замены файлов: если
            // запрос оборвётся во время рестарта, версию можно будет
            // восстановить, а подменённый master.tgz — отвергнуть.
            $target['archive_sha1'] = $this->archiveFingerprint($filename);
            $this->target_update = $target;
            $out['LATEST_ID'] = $target['commit'];
            $out['UPDATE_CURR_BRANCH'] = $target['branch'];
            $pending_written = $this->writePendingUpdate(array(
                'status' => 'downloaded',
                'commit' => $target['commit'],
                'branch' => $target['branch'],
                'update_url' => $url,
                'download_url' => $download_url,
                'archive' => basename($filename),
                'archive_sha1' => $target['archive_sha1'],
                'created_at' => date('c'),
            ));
            if (!$pending_written) {
                DebMes('System update: cannot persist the pending target in ' . $this->getPendingUpdateFile(), 'restore', 'warning');
            }
            DebMes('System update: archive pinned to ' . $target['commit'], 'restore');

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

            @unlink($filename);
            $message = 'Не удалось скачать архив: ' . $download['error'];
            $host = (string)parse_url($download_url, PHP_URL_HOST);
            DebMes('System update: archive download failed: ' . $message . ' (' . $download_url . ')', 'restore', 'error');
            if ($iframe) {
                echonow('<div><i style="font-size: 7pt;" class="glyphicon glyphicon-remove-sign"></i> ' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</div>', 'red');
                if ($host !== '') {
                    echonow('<div>Проверьте, что сервер открывает ' . htmlspecialchars($host, ENT_QUOTES, 'UTF-8') . ' (IPv4/IPv6, прокси, таймаут php-fpm), и повторите.</div>', 'red');
                }
                return 0;
            } else {
                $this->redirect("?err_msg=" . urlencode($message . ' ' . $download_url));
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
            if ($item['type'] != 'file' || $item['path'] == 'trunk/config.php' || $item['path'] == 'trunk/.env' || $item['path'] == 'trunk/.env.local') {
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
            // Коммит берём из pending-файла и сверяем с отпечатком архива:
            // это тот же архив, который скачал getLatest(), а не любой
            // master.tgz, подложенный в каталог.
            $update_target = $this->resolveAppliedUpdateTarget($is_system_update, $archive_path);
            $error_message = '';
            $result = $this->extractTarArchive($archive_path, DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/saverestore/temp', $error_message);

            if (!$result) {
                echonow("Unpack failed", 'red');
                if ($error_message != '') {
                    echonow(htmlspecialchars($error_message), 'red');
                }
                DebMes("Unpack failed for " . $archive_path . ': ' . $error_message, "restore", 'error');
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
            if ($is_system_update && (!is_dir($update_root . '/lib') || !is_file($update_root . '/bootstrap.php'))) {
                // Чужое дерево поверх установки: панель могла скачать архив другой
                // ветки (например, legacy master вместо текущей alpha). Замена
                // файлов не начинается, иначе docroot и public/ смешаются.
                $legacy_layout = !is_dir($update_root . '/public') && is_file($update_root . '/index.php');
                $message = $legacy_layout
                    ? 'Архив обновления — старая раскладка MajorDoMo (docroot в корне, без public/ и bootstrap.php).'
                    : 'Архив обновления не похож на MajorDoMo: нет lib/ и bootstrap.php.';
                $message .= ' Проверьте выбранную ветку в «Источник обновления»: она должна указывать на текущий проект.';
                DebMes('System update: archive layout mismatch (' . $folder . ')', 'restore', 'error');
                if ($iframe) {
                    echonow('<div><i style="font-size: 7pt;" class="glyphicon glyphicon-remove-sign"></i> ' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</div>', 'red');
                }
                return false;
            }
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
                    DebMes('System update apply preflight failed: ' . implode(' | ', $apply_issues), 'restore', 'error');
                    return false;
                }
            }

            foreach (array('config.php', '.env', '.env.local') as $protectedFile) {
                if (!file_exists(DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/saverestore/temp' . $folder . '/' . $protectedFile)) {
                    continue;
                }
                if ($iframe) {
                    echonow('<div><i style="font-size: 7pt;" class="glyphicon glyphicon-usd"></i> ' . LANG_UPDATEBACKUP_DISABLED . ' ' . $protectedFile . '</div>');
                }
                @unlink(DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/saverestore/temp' . $folder . '/' . $protectedFile);
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

            $is_cycle_update = $this->isCycleProcess();
            $deployment = null;
            $maintenance = null;
            $cycleManager = null;
            $deployLog = function (string $message, string $kind = 'info') use ($iframe): void {
                $channel = $kind === 'error' ? 'error' : ($kind === 'warning' ? 'warning' : '');
                DebMes('System update: ' . $message, 'restore', $channel);
                if ($iframe) {
                    $color = $kind === 'error' ? 'red' : ($kind === 'success' ? 'green' : ($kind === 'warning' ? 'orange' : ''));
                    echonow('<div' . ($color !== '' ? ' style="color:' . $color . '"' : '') . '><i style="font-size: 7pt;" class="glyphicon glyphicon-usd"></i> ' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</div>');
                }
            };

            if ($is_system_update) {
                $deployment = new \MajorDoMo\Core\Deploy\Deployment(DOC_ROOT);
                $preflight_failed = array();
                foreach ($deployment->preflight() as $check) {
                    if (!empty($check['fatal']) && empty($check['ok'])) $preflight_failed[] = $check['title'] . ': ' . $check['details'];
                }
                if ($preflight_failed !== array()) {
                    foreach ($preflight_failed as $issue) $deployLog('Проверка деплоя не пройдена — ' . $issue, 'error');
                    if ($iframe) echonow('<div class="text-danger">Обновление не начато: зависимости не установятся.</div>');
                    return false;
                }
                $maintenance = new \MajorDoMo\Core\Maintenance\MaintenanceMode(DOC_ROOT);
                try {
                    $maintenance->enable('system_update', 3600, $is_cycle_update ? 'cycle' : 'web', array(
                        'message' => 'Идёт обновление системы. Сайт скоро вернётся.',
                        'deploy_id' => bin2hex(random_bytes(8)),
                    ));
                } catch (\Throwable $error) {
                    $deployLog('Не удалось включить режим обслуживания: ' . $error->getMessage(), 'error');
                    return false;
                }
                $cycleManager = new \MajorDoMo\Core\Cycles\CycleManager(DOC_ROOT);
                $deployLog('Режим обслуживания включён', 'info');
            }

            $update_from = '';
            $update_to = '';
            $update_branch = '';
            if ($is_system_update) {
                $update_from = $this->normalizeCommitId($this->config['LATEST_UPDATED_ID'] ?? '');
                $update_to = $update_target !== null ? $update_target['commit'] : '';
                $update_branch = $update_target !== null ? $update_target['branch'] : '';
                if ($update_branch === '') {
                    $update_branch = $this->normalizeUpdateBranch($this->getUpdateBranch($this->getUpdateURL()));
                }
                if ($update_to === '') {
                    DebMes('System update: installed commit is unknown, version will not be recorded', 'restore', 'warning');
                }
                try {
                    \MajorDoMo\Core\Events\SystemEvents::systemUpdateStarted($update_from, $update_to);
                } catch (\Throwable $error) {
                    DebMes('Cannot emit system update event: ' . $error->getMessage(), 'restore', 'warning');
                }
            }

            try {
                $cycleUpdate = $this->stopCyclesBeforeSystemUpdate($iframe);
            } catch (\Throwable $error) {
                DebMes('System update pause failed: ' . $error->getMessage(), 'restore', 'error');
                if ($maintenance !== null) $maintenance->disable();
                if ($iframe) echonow(htmlspecialchars($error->getMessage(), ENT_QUOTES, 'UTF-8'), 'red');
                if ($is_system_update) {
                    try {
                        \MajorDoMo\Core\Events\SystemEvents::systemUpdateAborted($error->getMessage());
                    } catch (\Throwable $eventError) {
                        DebMes('Cannot emit system update abort: ' . $eventError->getMessage(), 'restore', 'warning');
                    }
                }
                return false;
            }
            $updateError = null;
            try {
                if ($is_system_update) {
                    $this->removeFilesDeletedFromSystemUpdate($old_manifest, $new_manifest, $iframe);
                    $this->removeObsoleteSystemUpdatePaths($iframe);
                }

                copyTree($update_root, DOC_ROOT, 1);
                // PHP-FPM может держать старый opcache после замены файлов.
                // Текущий запрос уже загрузил классы, но следующие — новые.
                if (function_exists('opcache_reset')) {
                    @opcache_reset();
                }
                clearstatcache(true);
                if ($is_system_update) {
                    // Файлы уже новые: зависимости и миграции обязательны до
                    // снятия паузы, иначе циклы стартуют со старым vendor.
                    $deployment->composerInstall($deployLog);
                    $deployment->verifyAutoload($deployLog);
                    $deployment->buildAssets($deployLog);
                    $deployment->runMigrations($deployLog);
                    $this->cleanupObsoleteSystemModules($iframe);
                    $this->saveSystemUpdateManifest($new_manifest);
                }

                if ($iframe) {
                    echonow('<div><i style="font-size: 7pt;" class="glyphicon glyphicon-usd"></i> ' . LANG_UPDATEBACKUP_DONE . '</div>');
                }

                if (file_exists(DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/saverestore/temp' . $folder . '/dump.sql')) {
                    if ($iframe) {
                        echonow('<div><i style="font-size: 7pt;" class="glyphicon glyphicon-usd"></i> ' . LANG_UPDATEBACKUP_RESTORE_DB . '</div>');
                    }
                    $result = $this->restoredatabase(DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/saverestore/temp' . $folder . '/dump.sql');
                    if ($iframe) {
                        echonow('<div><i style="font-size: 7pt;" class="glyphicon glyphicon-usd"></i> ' . LANG_UPDATEBACKUP_DONE . '</div>');
                    }
                }

                if ($is_system_update && $update_target !== null) {
                    // Файлы и зависимости уже на месте. Если запрос оборвётся
                    // во время рестарта, следующий заход восстановит версию
                    // из этого статуса.
                    $this->updatePendingUpdate(array('status' => 'applied', 'applied_at' => date('c')));
                }

                if ($iframe) {
                    echonow('<div><i style="font-size: 7pt;" class="glyphicon glyphicon-usd"></i> ' . LANG_UPDATEBACKUP_DONE . '</div>');
                }
            } catch (\Throwable $error) {
                $updateError = $error;
                DebMes('System update apply failed: ' . $error->getMessage(), 'restore', 'error');
                if ($is_system_update && $deployment !== null) {
                    try {
                        $deployment->writeState(array('status' => 'failed', 'error' => $error->getMessage(), 'from' => $update_from, 'to' => $update_to));
                    } catch (\Throwable $stateError) {
                        DebMes('Cannot write deploy state: ' . $stateError->getMessage(), 'restore', 'warning');
                    }
                }
                if ($iframe) echonow(htmlspecialchars($error->getMessage(), ENT_QUOTES, 'UTF-8'), 'red');
                if ($is_system_update) {
                    try {
                        \MajorDoMo\Core\Events\SystemEvents::systemUpdateFailed($error->getMessage());
                    } catch (\Throwable $eventError) {
                        DebMes('Cannot emit system update failure: ' . $eventError->getMessage(), 'restore', 'warning');
                    }
                }
                return false;
            } finally {
                if ($updateError !== null) {
                    if ($is_system_update) {
                        if ($update_target !== null) {
                            $this->updatePendingUpdate(array(
                                'status' => 'failed',
                                'error' => $updateError->getMessage(),
                                'failed_at' => date('c'),
                            ));
                        }
                        if ($maintenance !== null) {
                            try { $maintenance->markFailed($updateError->getMessage()); }
                            catch (\Throwable $maintenanceError) { DebMes('Cannot keep maintenance mode: ' . $maintenanceError->getMessage(), 'restore', 'error'); }
                        }
                        if ($cycleManager !== null) {
                            try { $cycleManager->hold('deploy_failed'); }
                            catch (\Throwable $holdError) { DebMes('Cannot hold cycles after failed deploy: ' . $holdError->getMessage(), 'restore', 'error'); }
                        }
                    }
                    // Files may already be replaced: release the pause, but the
                    // hold marker prevents cycles from starting on broken code.
                    $cycleUpdate->abort($updateError->getMessage());
                } else {
                    if ($is_system_update && $cycleManager !== null) $cycleManager->releaseHold();
                    $cycleUpdate->finish();
                }
            }

            if ($is_system_update) {
                if ($is_cycle_update) {
                    // Внутри cycle.php нельзя restart'ить свою cgroup.
                    // Версию фиксируем только после установки зависимостей,
                    // миграций и подготовки reload — не в середине процесса.
                    $this->commitSystemUpdate($update_from, $update_to, $update_branch, $deployment);
                    if ($cycleManager !== null) $cycleManager->requestReload();
                    if ($maintenance !== null) $maintenance->disable();
                } else {
                    $restart = $deployment->restartService($deployLog);
                    if (!$restart['ok']) {
                        $message = 'Система обновлена, но служба не перезапустилась: '
                            . trim((string)($restart['err'] ?: $restart['out']));
                        if ($update_target !== null) {
                            $this->updatePendingUpdate(array(
                                'status' => 'failed',
                                'error' => $message,
                                'failed_at' => date('c'),
                            ));
                        }
                        if ($maintenance !== null) {
                            try { $maintenance->markFailed($message); }
                            catch (\Throwable $error) { DebMes('Cannot keep maintenance mode: ' . $error->getMessage(), 'restore', 'error'); }
                        }
                        if ($cycleManager !== null) {
                            try { $cycleManager->hold('deploy_failed'); }
                            catch (\Throwable $error) { DebMes('Cannot hold cycles: ' . $error->getMessage(), 'restore', 'error'); }
                        }
                        try {
                            \MajorDoMo\Core\Events\SystemEvents::systemUpdateFailed($message);
                        } catch (\Throwable $error) {
                            DebMes('Cannot emit system update failure: ' . $error->getMessage(), 'restore', 'warning');
                        }
                        if ($iframe) {
                            echonow(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'), 'red');
                            return false;
                        }
                        $this->redirect("?err_msg=" . urlencode($message));
                    }
                    // Служба уже перезапущена: только теперь фиксируем версию.
                    $this->commitSystemUpdate($update_from, $update_to, $update_branch, $deployment);
                    if ($maintenance !== null) $maintenance->disable();
                }
            }
            if ($iframe) {
                return 1;
            } else {
                if (!$is_system_update) setRebootRequired('updated');
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
                @unlink(DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/saverestore/temp/.env');
                @unlink(DOC_ROOT . DIRECTORY_SEPARATOR . 'cms/saverestore/temp/.env.local');

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
                DebMes('Backup archive creation failed: ' . $error_message, 'restore', 'error');
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
            DebMes("Failed to restore DB:\n" . implode("\n", $output), "restore", 'error');
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

        $target = $this->resolveUpdateTarget(self::UPDATE_FEED_TTL);
        if ($target['commit'] == '') {
            DebMes('Auto-update: cannot read the update feed, skipped', 'auto_update', 'warning');
            return 0;
        }

        $latest_id = $target['commit'];
        $latest_tm = $target['updated_ts'];
        $this->reconcilePendingUpdate();
        $this->getConfig();
        $current_latest_id = isset($this->config['LATEST_UPDATED_ID']) ? $this->normalizeCommitId($this->config['LATEST_UPDATED_ID']) : '';
        if ($latest_id == $current_latest_id) {
            DebMes("Already updated to the latest version ($latest_id)", 'auto_update');
            return 0;
        }
        DebMes("Need to update to $latest_id on top of " . $current_latest_id, 'auto_update');

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
        if (!$res) {
            DebMes('System update download failed', 'auto_update', 'error');
            return false;
        }
        // getLatest() берёт свежую ленту и мог выбрать коммит новее того, по
        // которому считалась задержка. Проверяем свежий коммит ещё раз, чтобы
        // автообновление не установило только что опубликованную версию.
        $fresh_tm = is_array($this->target_update) ? (int)($this->target_update['updated_ts'] ?? 0) : 0;
        if ($fresh_tm > 0) {
            $fresh_delay = round((time() - $fresh_tm) / (24 * 60 * 60), 2);
            if ($fresh_delay < $delay) {
                DebMes("Fresh target is too recent ($fresh_delay vs $delay), auto-update postponed", 'auto_update');
                return 0;
            }
        }
        global $restore;
        global $folder;
        $restore = 'master.tgz';
        $folder = '';
        $applied_from = is_array($this->target_update) ? (string)($this->target_update['download_url'] ?? '') : '';
        DebMes("Applying update from " . ($applied_from !== '' ? $applied_from : basename($this->url)), 'auto_update');
        $res = $this->upload($out, 1);
        if (!$res) {
            DebMes('System update was not applied', 'auto_update', 'error');
            return false;
        }
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
        // Перезапуск/reload выполняет upload(): система уже знает,
        // запущен ли updater внутри cycle.php или в веб-запросе.
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
