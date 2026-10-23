<?php

/**
 * Изолированные проверки источника обновления и CLI-деплоя.
 *
 * Сети и рабочей БД не требуются: проверяются разбор ссылок, Atom-ленты,
 * состояние версии и обязательные проверки окружения на временном каталоге.
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(2);
}

require dirname(__DIR__) . '/lib/core.class.php';

use MajorDoMo\Core\Config;
use MajorDoMo\Core\Deploy\SystemUpdate;
use MajorDoMo\Core\Deploy\UpdateSource;

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

$root = sys_get_temp_dir() . '/mjd-deploy-update-' . bin2hex(random_bytes(6));
$remove = static function (string $path) use (&$remove): void {
    if (is_link($path) || is_file($path)) {
        unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: array() as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $remove($path . '/' . $item);
    }
    rmdir($path);
};
$write = static function (string $path, string $contents): void {
    $directory = dirname($path);
    if (!is_dir($directory)) {
        mkdir($directory, 0777, true);
    }
    file_put_contents($path, $contents);
};

try {
    foreach (array('cms/cached', 'cms/deploy', 'cms/saverestore', 'cms/cycles', 'database_backup', 'public/assets', 'vendor') as $directory) {
        mkdir($root . '/' . $directory, 0777, true);
    }
    $write($root . '/composer.json', '{}');
    $write($root . '/composer.lock', '{}');
    $write($root . '/.env', "GIT_URL=https://github.com/example/majordomo\n");
    $write($root . '/bootstrap.php', "<?php\n");
    $write($root . '/majordomo', "<?php\n");

    // Конфигурация читается один раз на процесс, поэтому .env пишется заранее.
    putenv('DEPLOY_ASSETS=never');
    putenv('DEPLOY_RESTART=0');
    Config::boot($root);

    // ── Разбор идентификаторов коммита ──────────────────────────────────────
    $sha = '0123456789abcdef0123456789abcdef01234567';
    check(
        UpdateSource::normalizeCommit('tag:github.com,2008:Grit::Commit/' . $sha) === $sha,
        'GitHub: коммит из tag:Grit::Commit'
    );
    check(
        UpdateSource::normalizeCommit('https://gitea.local/user/repo/commit/' . $sha) === $sha,
        'Gitea: коммит из ссылки /commit/'
    );
    check(
        UpdateSource::normalizeCommit('tag:gitlab.com,2026:commit/' . $sha) === $sha,
        'GitLab: коммит из tag:...:commit/'
    );
    check(UpdateSource::normalizeCommit(strtoupper($sha)) === $sha, 'Короткий SHA приводится к нижнему регистру');
    check(UpdateSource::normalizeCommit('') === '', 'Пустой идентификатор остаётся пустым');

    // ── Ссылки источника ────────────────────────────────────────────────────
    $github = 'https://github.com/example/majordomo';
    $githubArchive = $github . '/archive/master.tar.gz';
    check(
        UpdateSource::feedUrlFor($githubArchive) === $github . '/commits/master.atom',
        'GitHub: ссылка на ленту коммитов'
    );
    check(UpdateSource::branchFor($githubArchive) === 'master', 'GitHub: ветка из ссылки архива');
    check(
        UpdateSource::archiveUrlFor($githubArchive, $sha) === $github . '/archive/' . $sha . '.tar.gz',
        'GitHub: архив строго по коммиту'
    );

    $gitlabArchive = 'https://gitlab.example.com/group/majordomo/-/archive/master/majordomo-master.tar.gz';
    check(
        UpdateSource::feedUrlFor($gitlabArchive) === 'https://gitlab.example.com/group/majordomo/-/commits/master.atom',
        'GitLab: ссылка на ленту коммитов'
    );
    check(
        UpdateSource::archiveUrlFor($gitlabArchive, $sha) === 'https://gitlab.example.com/group/majordomo/-/archive/' . $sha . '/majordomo-' . $sha . '.tar.gz',
        'GitLab: архив подменяется на коммит'
    );
    check(
        UpdateSource::feedUrlFor($github . '/archive/refs/heads/beta.tar.gz') === $github . '/commits/beta.atom',
        'Ветка из refs/heads попадает в ленту'
    );
    check(
        UpdateSource::archiveUrlFor('https://example.com/update.tar.gz', $sha) === '',
        'Источник без версии в ссылке не даёт привязать архив к коммиту'
    );

    // ── Atom-лента ──────────────────────────────────────────────────────────
    $feed = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<feed xmlns="http://www.w3.org/2005/Atom">'
        . '<entry><id>tag:github.com,2008:Grit::Commit/' . $sha . '</id><updated>2026-10-23T10:00:00Z</updated></entry>'
        . '<entry><id>tag:github.com,2008:Grit::Commit/ffffffffffffffffffffffffffffffffffffffff</id><updated>2026-10-20T10:00:00Z</updated></entry>'
        . '</feed>';
    $entries = UpdateSource::parseEntries($feed);
    check(count($entries) === 2, 'Лента разбирается на записи');
    check($entries[0]['commit'] === $sha, 'Первая запись ленты — самый свежий коммит');
    check(UpdateSource::parseEntries('не xml') === array(), 'Мусор вместо ленты не ломает разбор');

    // ── Источник обновления из .env ──────────────────────────────────────────
    $update = new SystemUpdate($root);
    check(
        $update->updateUrl() === $github . '/archive/master.tar.gz',
        'Источник берётся из GIT_URL в .env'
    );
    check($update->updateUrlSource() === '.env', 'Источник по умолчанию помечен как .env');
    $branchUpdate = new SystemUpdate($root, array('branch' => 'alpha'));
    check(
        $branchUpdate->updateUrl() === $github . '/archive/alpha.tar.gz',
        'Опция --branch подменяет ветку'
    );
    check(
        str_contains($branchUpdate->updateUrlSource(), 'ветка'),
        'Подмена ветки отмечается в источнике'
    );
    $urlUpdate = new SystemUpdate($root, array('url' => $githubArchive));
    check($urlUpdate->updateUrl() === $githubArchive, 'Опция --url важнее .env');
    check($urlUpdate->updateUrlSource() === 'аргумент --url', 'Источник --url помечается отдельно');

    // ── Установленная версия ────────────────────────────────────────────────
    check($update->installedVersion() === '', 'Без данных версия неизвестна');
    $write($root . '/cms/saverestore/update.pending.json', json_encode(array(
        'status' => 'applied',
        'commit' => strtoupper($sha),
        'branch' => 'MASTER',
    ), JSON_UNESCAPED_SLASHES));
    check($update->installedVersion() === $sha, 'Pending со статусом applied задаёт установленную версию');
    $write($root . '/cms/saverestore/update.pending.json', json_encode(array('status' => 'downloaded', 'commit' => $sha)));
    $write($root . '/cms/deploy/last.json', json_encode(array('status' => 'success', 'to' => $sha)));
    check($update->installedVersion() === $sha, 'Состояние успешного деплоя задаёт установленную версию');
    $write($root . '/cms/saverestore/update.pending.json', json_encode(array('status' => 'failed', 'commit' => $sha)));
    $write($root . '/cms/deploy/last.json', json_encode(array('status' => 'failed', 'to' => '')));
    check($update->installedVersion() === '', 'Неудачные записи версию не подменяют');

    // ── Скачивание архива ───────────────────────────────────────────────────
    $downloader = new \MajorDoMo\Core\Deploy\ArchiveDownloader($root);
    $broken = $downloader->download('http://127.0.0.1:1/archive.tgz', $root . '/cms/saverestore/test.tgz');
    check($broken['ok'] === false && $broken['error'] !== '', 'Загрузчик возвращает ошибку, а не зависает');
    check(
        !is_file($root . '/cms/saverestore/test.tgz') && !is_file($root . '/cms/saverestore/test.tgz.part'),
        'После неудачной загрузки не остаётся недокачанного файла'
    );

    // ── Проверки окружения ──────────────────────────────────────────────────
    $checkList = $update->checks();
    $byTitle = array();
    foreach ($checkList as $item) {
        $byTitle[$item['title']] = $item;
    }
    check(isset($byTitle['Источник обновления']), 'Проверки содержат источник обновления');
    check($byTitle['Источник обновления']['ok'] === true, 'Источник обновления признан настроенным');
    check($byTitle['Источник обновления']['fatal'] === true, 'Источник обновления — обязательная проверка');
    check(isset($byTitle['PHP CLI']) && $byTitle['PHP CLI']['ok'] === true, 'PHP CLI проходит проверку');
    check(isset($byTitle['Команда tar']), 'Проверки содержат команду tar');
    $checksCount = count($checkList);
    check($checksCount > 5, 'Проверки окружения не пустые');
    foreach ($checkList as $item) {
        if (!empty($item['fatal']) && empty($item['ok'])) {
            echo 'INFO фатальная проверка: ' . $item['title'] . ' — ' . $item['details'] . PHP_EOL;
        }
    }

    echo 'Update source smoke tests passed: ' . $checks . ' checks.' . PHP_EOL;
} finally {
    $remove($root);
}
