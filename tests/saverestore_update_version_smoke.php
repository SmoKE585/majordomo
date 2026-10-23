<?php

/**
 * Изолированные проверки фиксации версии системного обновления.
 *
 * Не нужны рабочая БД, Redis и сеть: проверяются разбор ленты, ссылка на
 * архив коммита, отпечаток архива, pending-файл и запись версии в конфиг.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(2);
}

$project = dirname(__DIR__);
$fixture = sys_get_temp_dir() . '/mjd-saverestore-version-' . bin2hex(random_bytes(6));
$configRoot = $fixture . '/config';
$remove = static function (string $path) use (&$remove): void {
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: array() as $item) {
        if ($item === '.' || $item === '..') continue;
        $remove($path . DIRECTORY_SEPARATOR . $item);
    }
    @rmdir($path);
};

$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException('FAILED: ' . $message);
    }
    $checks++;
    echo 'PASS ' . $message . PHP_EOL;
}

if (!class_exists('module')) {
    #[AllowDynamicProperties]
    class module
    {
        public $name = '';
        public $title = '';
        public $module_category = '';
        public $config = array();

        public function checkInstalled(): void {}
    }
}
if (!function_exists('DebMes')) {
    function DebMes($message, $channel = '', $severity = ''): void
    {
        $GLOBALS['test_logs'][] = array($message, $channel, $severity);
    }
}
if (!function_exists('gr')) {
    function gr($key, $filter = '')
    {
        return $_REQUEST[$key] ?? '';
    }
}
if (!function_exists('setGlobal')) {
    function setGlobal($varname, $value, $no_linked = 0, $source = '')
    {
        $GLOBALS['test_globals'][$varname] = $value;
        return true;
    }
}

$server = null;
try {
    mkdir($fixture . '/cms/saverestore', 0777, true);
    mkdir($configRoot, 0777, true);
    define('ROOT', str_replace('\\', '/', $fixture) . '/');
    define('DOC_ROOT', $fixture);
    define('DIR_MODULES', $project . '/modules/');
    define('ROOTHTML', '/');

    require $project . '/lib/core.class.php';
    \MajorDoMo\Core\Config::boot($configRoot);
    // Только XML-помощники: полный bootstrap Helpers подключает реальные
    // setGlobal/DebMes и полез бы в БД. В тесте они заменены заглушками.
    require_once $project . '/lib/core/Helpers/Xml.php';
    require_once $project . '/modules/saverestore/saverestore.class.php';

    /**
     * Не трогаем project_modules: переопределяем только чтение/запись
     * конфига и сброс сетевого кэша.
     */
    class test_saverestore extends saverestore
    {
        public int $saved = 0;

        public function getConfig()
        {
            return $this->config;
        }

        public function saveConfig()
        {
            $this->saved++;
        }

        public function clearUpdateFeedCache()
        {
        }
    }

    $sv = new test_saverestore();

    // -- Разбор коммитов ---------------------------------------------------
    $sha = '8b7a49567c4112494f3d32ad292ae5c32bf8f90e';
    check($sv->normalizeCommitId('tag:github.com,2008:Grit::Commit/' . $sha) === $sha, 'GitHub Atom ID приводится к SHA');
    check($sv->normalizeCommitId('https://git.example/acme/repo/commit/' . $sha) === $sha, 'Gitea-ссылка приводится к SHA');
    check($sv->normalizeCommitId('  ' . strtoupper($sha) . '  ') === $sha, 'Короткий/верхний SHA нормализуется');
    check($sv->normalizeCommitId('') === '', 'Пустой коммит остаётся пустым');
    check($sv->normalizeUpdateBranch(' alpha ') === 'ALPHA', 'Ветка приводится к верхнему регистру');

    // -- Лента ------------------------------------------------------------
    $one = '<?xml version="1.0" encoding="UTF-8"?><feed xmlns="http://www.w3.org/2005/Atom">'
        . '<entry><id>tag:github.com,2008:Grit::Commit/' . $sha . '</id>'
        . '<updated>2026-10-23T10:00:00Z</updated><title>One</title>'
        . '<link href="https://example/commit/' . $sha . '"/></entry></feed>';
    $entries = $sv->parseUpdateFeedEntries($one);
    check(count($entries) === 1 && $sv->getUpdateEntryCommit($entries[0]) === $sha, 'Лента из одной записи разбирается');

    $two = str_replace(
        '</feed>',
        '<entry><id>tag:github.com,2008:Grit::Commit/14d4bd4f00000000000000000000000000000000</id>'
        . '<updated>2026-10-22T10:00:00Z</updated><title>Two</title></entry></feed>',
        $one
    );
    $entries = $sv->parseUpdateFeedEntries($two);
    check(count($entries) === 2 && $sv->getUpdateEntryCommit($entries[1]) === '14d4bd4f00000000000000000000000000000000', 'Лента из двух записей сохраняет порядок');
    check($sv->parseUpdateFeedEntries('<html>404</html>') === array(), 'HTML вместо ленты не считается лентой');

    // -- Ссылки на ленту и архив коммита ----------------------------------
    $github = 'https://github.com/acme/repo/archive/master.tar.gz';
    check($sv->getUpdateFeedURL($github) === 'https://github.com/acme/repo/commits/master.atom', 'GitHub: лента ветки');
    check(
        $sv->getArchiveURLForCommit($github, $sha) === 'https://github.com/acme/repo/archive/' . $sha . '.tar.gz',
        'GitHub: архив ровно одного коммита'
    );
    $githubRefs = 'https://github.com/acme/repo/archive/refs/heads/master.tar.gz';
    check($sv->getUpdateFeedURL($githubRefs) === 'https://github.com/acme/repo/commits/master.atom', 'GitHub refs/heads: лента ветки');
    check(
        $sv->getArchiveURLForCommit($githubRefs, $sha) === 'https://github.com/acme/repo/archive/' . $sha . '.tar.gz',
        'GitHub refs/heads: архив ровно одного коммита'
    );
    $githubTag = 'https://github.com/acme/repo/archive/refs/tags/v1.2.3.tar.gz';
    check($sv->getUpdateFeedURL($githubTag) === 'https://github.com/acme/repo/commits/v1.2.3.atom', 'GitHub refs/tags: лента тега');
    check(
        $sv->getArchiveURLForCommit($githubTag, $sha) === 'https://github.com/acme/repo/archive/' . $sha . '.tar.gz',
        'GitHub refs/tags: архив ровно одного коммита'
    );
    $gitlab = 'https://gitlab.com/acme/repo/-/archive/master/repo-master.tar.gz';
    check($sv->getUpdateFeedURL($gitlab) === 'https://gitlab.com/acme/repo/-/commits/master.atom', 'GitLab: лента ветки');
    check(
        $sv->getArchiveURLForCommit($gitlab, $sha) === 'https://gitlab.com/acme/repo/-/archive/' . $sha . '/repo-' . $sha . '.tar.gz',
        'GitLab: архив ровно одного коммита'
    );
    $gitea = 'https://git.example/acme/repo/archive/master.tar.gz';
    check(
        $sv->getArchiveURLForCommit($gitea, $sha) === 'https://git.example/acme/repo/archive/' . $sha . '.tar.gz',
        'Gitea: архив ровно одного коммита'
    );
    check($sv->getArchiveURLForCommit('https://example.com/update.tar.gz', $sha) === '', 'Неизвестный источник не выдаёт ложный архив коммита');

    // -- Pending-файл и отпечаток архива ----------------------------------
    $archive = $fixture . '/cms/saverestore/master.tgz';
    file_put_contents($archive, 'archive-bytes');
    $fingerprint = sha1_file($archive);
    $sv->writePendingUpdate(array(
        'status' => 'downloaded',
        'commit' => $sha,
        'branch' => 'master',
        'archive_sha1' => $fingerprint,
    ));
    $pending = $sv->readPendingUpdate();
    check(is_array($pending) && $pending['commit'] === $sha && $pending['branch'] === 'MASTER', 'Pending-файл читается и нормализуется');
    check($sv->archiveFingerprint($archive) === $fingerprint, 'Отпечаток архива считается');
    $target = $sv->resolveAppliedUpdateTarget(true, $archive);
    check(is_array($target) && $target['commit'] === $sha && $target['branch'] === 'MASTER', 'Совпавший архив даёт точный коммит');
    check($sv->resolveAppliedUpdateTarget(false, $archive) === null, 'Для не-системного восстановления версия не берётся');

    file_put_contents($archive, 'tampered');
    check($sv->resolveAppliedUpdateTarget(true, $archive) === null, 'Подменённый архив не даёт версию');
    check(!empty($GLOBALS['test_logs']), 'Подмена архива попадает в журнал');

    // -- Запись версии ----------------------------------------------------
    $sv->config = array('LATEST_UPDATED_ID' => 'old');
    $savedBefore = $sv->saved;
    check($sv->recordInstalledVersion($sha, 'master'), 'Версия записывается');
    check($sv->config['LATEST_UPDATED_ID'] === $sha, 'В конфиг попадает коммит из архива');
    check($sv->config['LATEST_CURR_BRANCH'] === 'MASTER', 'В конфиг попадает ветка');
    check(!empty($sv->config['LATEST_UPDATED_DATE']) && !empty($sv->config['LATEST_UPDATED_TS']), 'Дата фиксации хранится в конфиге');
    check($sv->saved === $savedBefore + 1, 'Конфиг сохраняется один раз');
    check(!is_file($sv->getPendingUpdateFile()), 'Успешная запись удаляет pending-файл');
    check(isset($GLOBALS['test_globals']['LatestUpdateId']) && $GLOBALS['test_globals']['LatestUpdateId'] === $sha, 'Версия публикуется в глобал для старых шаблонов');

    $sv->config = array('LATEST_UPDATED_ID' => 'old');
    check($sv->recordInstalledVersion('', 'master') === false, 'Пустой коммит не пишется');
    check($sv->config['LATEST_UPDATED_ID'] === 'old', 'Пустой коммит не трогает конфиг');

    // -- Восстановление после обрыва запроса ------------------------------
    $sv->config = array('LATEST_UPDATED_ID' => 'old');
    $sv->writePendingUpdate(array(
        'status' => 'applied',
        'commit' => $sha,
        'branch' => 'master',
        'applied_at' => date('c'),
    ));
    check($sv->reconcilePendingUpdate() === false, 'Свежий applied не перебивает идущий запрос');
    check($sv->config['LATEST_UPDATED_ID'] === 'old' && is_file($sv->getPendingUpdateFile()), 'Свежий applied остаётся на месте');

    $sv->writePendingUpdate(array(
        'status' => 'applied',
        'commit' => $sha,
        'branch' => 'master',
        'applied_at' => date('c', time() - 60),
    ));
    check($sv->reconcilePendingUpdate(), 'Статус applied восстанавливает версию');
    check($sv->config['LATEST_UPDATED_ID'] === $sha, 'Восстановление пишет тот же коммит');
    check(!is_file($sv->getPendingUpdateFile()), 'Восстановление убирает pending-файл');

    $sv->config = array('LATEST_UPDATED_ID' => 'old');
    $sv->writePendingUpdate(array(
        'status' => 'downloaded',
        'commit' => $sha,
        'branch' => 'master',
    ));
    check($sv->reconcilePendingUpdate() === false, 'Статус downloaded не применяется');
    check($sv->config['LATEST_UPDATED_ID'] === 'old', 'Неприменённый pending не меняет версию');

    $sv->config = array('LATEST_UPDATED_ID' => 'old');
    $sv->writePendingUpdate(array(
        'status' => 'applied',
        'commit' => $sha,
        'branch' => 'master',
        'applied_at' => date('c', time() - 8 * 24 * 60 * 60),
    ));
    check($sv->reconcilePendingUpdate() === false, 'Просроченный applied не применяется');
    check($sv->config['LATEST_UPDATED_ID'] === 'old', 'Просроченный pending не меняет версию');
    check(!is_file($sv->getPendingUpdateFile()), 'Просроченный pending удаляется');

    echo 'OK: ' . $checks . ' saverestore version checks' . PHP_EOL;
} finally {
    $remove($fixture);
}
