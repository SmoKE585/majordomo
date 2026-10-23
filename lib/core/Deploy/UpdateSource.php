<?php

namespace MajorDoMo\Core\Deploy;

use RuntimeException;

/**
 * Источник системного обновления: лента коммитов, ветка и архив одного коммита.
 *
 * Правила разбора ссылок повторяют веб-обновление (модуль saverestore): архив
 * скачивается строго по коммиту из Atom-ленты хостинга, иначе записанная
 * версия расходится с содержимым файлов. Отдельный класс нужен, чтобы
 * CLI-деплой не угадывал адреса и не тянул legacy-модуль с его HTTP-контекстом.
 *
 * 2026-10-23 — добавлено для `php majordomo deploy:update` и deploy.sh.
 */
final class UpdateSource
{
    private string $updateUrl;
    private bool $verifyTls;
    private int $timeout;

    public function __construct(string $updateUrl, bool $verifyTls = true, int $timeout = 60)
    {
        $this->updateUrl = trim($updateUrl);
        $this->verifyTls = $verifyTls;
        $this->timeout = max(5, $timeout);
    }

    public function updateUrl(): string
    {
        return $this->updateUrl;
    }

    public function feedUrl(): string
    {
        return self::feedUrlFor($this->updateUrl);
    }

    public function branch(): string
    {
        return self::branchFor($this->updateUrl);
    }

    /**
     * Цель обновления: последний коммит ленты и архив ровно этого коммита.
     *
     * @return array{commit:string,branch:string,updated_at:string,updated_ts:int,update_url:string,feed_url:string,archive_url:string}
     */
    public function latest(): array
    {
        if ($this->updateUrl === '') {
            throw new RuntimeException('Не задан источник обновления: заполните GIT_URL в .env или передайте --url.');
        }
        $feedUrl = $this->feedUrl();
        if ($feedUrl === '') {
            throw new RuntimeException('Не удалось построить ссылку на ленту коммитов из ' . $this->updateUrl);
        }

        $body = $this->fetch($feedUrl);
        if ($body === '') {
            throw new RuntimeException(
                'Лента коммитов недоступна: ' . $feedUrl
                . '. Проверьте GIT_URL/MASTER_UPDATE_URL и доступ сервера к хостингу.'
            );
        }
        $entries = self::parseEntries($body);
        if ($entries === array()) {
            throw new RuntimeException('Лента коммитов пуста или имеет неизвестный формат: ' . $feedUrl);
        }

        $latest = $entries[0];
        $commit = (string)$latest['commit'];
        $archiveUrl = self::archiveUrlFor($this->updateUrl, $commit);
        if ($commit === '' || $archiveUrl === '') {
            throw new RuntimeException(
                'Источник не отдаёт архив по конкретному коммиту. Обновление не начато, '
                . 'иначе в конфиг попала бы неизвестная версия.'
            );
        }

        return array(
            'commit' => $commit,
            'branch' => $this->branch() !== '' ? $this->branch() : 'MASTER',
            'updated_at' => (string)$latest['updated_at'],
            'updated_ts' => (int)$latest['updated_ts'],
            'update_url' => $this->updateUrl,
            'feed_url' => $feedUrl,
            'archive_url' => $archiveUrl,
        );
    }

    /**
     * Разобрать Atom-ленту в список коммитов, от новых к старым.
     *
     * @return array<int,array{commit:string,updated_at:string,updated_ts:int}>
     */
    public static function parseEntries(string $xml): array
    {
        if ($xml === '' || !preg_match_all('#<entry\b[^>]*>(.*?)</entry>#is', $xml, $matches)) {
            return array();
        }
        $entries = array();
        foreach ($matches[1] as $body) {
            $commit = '';
            if (preg_match('#<id\b[^>]*>(.*?)</id>#is', $body, $found)) {
                $commit = self::normalizeCommit(html_entity_decode(trim($found[1]), ENT_QUOTES | ENT_XML1, 'UTF-8'));
            }
            if ($commit === '' && preg_match('#<link\b[^>]*href="([^"]+)"#i', $body, $found)) {
                // Gitea и часть форков отдают коммит только ссылкой.
                $commit = self::normalizeCommit(html_entity_decode(trim($found[1]), ENT_QUOTES | ENT_XML1, 'UTF-8'));
            }
            if ($commit === '') {
                continue;
            }
            $updated = '';
            if (preg_match('#<updated\b[^>]*>([^<]+)</updated>#i', $body, $found)) {
                $updated = trim($found[1]);
            }
            $timestamp = $updated !== '' ? strtotime($updated) : false;
            $entries[] = array(
                'commit' => $commit,
                'updated_at' => $updated,
                'updated_ts' => $timestamp !== false ? (int)$timestamp : 0,
            );
        }
        return $entries;
    }

    /**
     * Привести идентификатор коммита к чистому SHA.
     *
     * GitHub отдаёт `tag:github.com,2008:Grit::Commit/<sha>`, Gitea — ссылку на
     * `/commit/<sha>`, GitLab — `tag:...:commit/<sha>`. Все варианты сводятся к
     * нижнему регистру SHA, чтобы сравнение версий не зависело от хостинга.
     */
    public static function normalizeCommit(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (preg_match('#(?:Grit::Commit/|/commit/)([0-9a-f]{7,64})/?$#i', $value, $match)) {
            return strtolower($match[1]);
        }
        if (preg_match('#^[0-9a-f]{7,64}$#i', $value)) {
            return strtolower($value);
        }
        if (preg_match('#([0-9a-f]{7,64})/?$#i', $value, $match)) {
            return strtolower($match[1]);
        }
        return strtolower(trim((string)preg_replace('/.+Commit\//is', '', $value)));
    }

    /**
     * Ссылка на Atom-ленту коммитов по ссылке архива.
     *
     * GitHub: /archive/master.tar.gz → /commits/master.atom
     * GitLab: /-/archive/master/master.tar.gz → /-/commits/master.atom
     */
    public static function feedUrlFor(string $updateUrl): string
    {
        $updateUrl = trim($updateUrl);
        if ($updateUrl === '') {
            return '';
        }
        $path = parse_url($updateUrl, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            $path = $updateUrl;
        }
        if (preg_match('#/-/archive/([^/]+)/[^/]+\.tar\.gz$#i', $path, $match)) {
            return str_replace($match[0], '/-/commits/' . $match[1] . '.atom', $updateUrl);
        }
        if (preg_match('#/archive/(?:refs/(?:heads|tags)/)?([^/]+)\.tar\.gz$#i', $path, $match)) {
            return (string)preg_replace(
                '#/archive/(?:refs/(?:heads|tags)/)?[^/]+\.tar\.gz#i',
                '/commits/' . $match[1] . '.atom',
                $updateUrl
            );
        }
        return str_replace(array('/archive/', '.tar.gz'), array('/commits/', '.atom'), $updateUrl);
    }

    /** Ветка из ссылки архива: /archive/master.tar.gz → master. */
    public static function branchFor(string $updateUrl): string
    {
        $updateUrl = trim($updateUrl);
        if ($updateUrl === '') {
            return '';
        }
        $path = parse_url($updateUrl, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            $path = $updateUrl;
        }
        if (preg_match('#/-/archive/([^/]+)/[^/]+\.tar\.gz$#i', $path, $match)) {
            return $match[1];
        }
        if (preg_match('#/archive/(?:refs/(?:heads|tags)/)?([^/]+)\.tar\.gz$#i', $path, $match)) {
            return $match[1];
        }
        return str_replace('.tar.gz', '', basename($path));
    }

    /**
     * Ссылка на архив ровно одного коммита.
     *
     * Пустая строка означает «источник не умеет отдавать архив по коммиту»:
     * в этом случае обновление начинать нельзя.
     */
    public static function archiveUrlFor(string $updateUrl, string $commit): string
    {
        $commit = trim($commit);
        $updateUrl = trim($updateUrl);
        if ($commit === '' || $updateUrl === '') {
            return '';
        }
        $path = parse_url($updateUrl, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            $path = $updateUrl;
        }
        if (preg_match('#/-/archive/([^/]+)/([^/]+)\.tar\.gz$#i', $path, $match)) {
            $archiveName = str_replace($match[1], $commit, $match[2]);
            if ($archiveName === $match[2]) {
                $archiveName = $commit;
            }
            return str_replace($match[0], '/-/archive/' . $commit . '/' . $archiveName . '.tar.gz', $updateUrl);
        }
        if (preg_match('#/archive/(?:refs/(?:heads|tags)/)?[^/]+\.tar\.gz#i', $path, $match)) {
            return str_replace($match[0], '/archive/' . $commit . '.tar.gz', $updateUrl);
        }
        return '';
    }

    /** Забрать тело ответа. Пустая строка означает сетевую ошибку. */
    private function fetch(string $url): string
    {
        if (function_exists('curl_init')) {
            $handle = curl_init($url);
            if ($handle === false) {
                return '';
            }
            curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($handle, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($handle, CURLOPT_MAXREDIRS, 5);
            curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, 15);
            curl_setopt($handle, CURLOPT_TIMEOUT, $this->timeout);
            curl_setopt($handle, CURLOPT_USERAGENT, 'MajorDoMoDeploy/1.0');
            curl_setopt($handle, CURLOPT_HTTPHEADER, array('Accept: application/atom+xml, application/xml, text/xml'));
            curl_setopt($handle, CURLOPT_SSL_VERIFYPEER, $this->verifyTls);
            curl_setopt($handle, CURLOPT_SSL_VERIFYHOST, $this->verifyTls ? 2 : 0);
            $body = curl_exec($handle);
            $code = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            curl_close($handle);
            if (!is_string($body) || $body === '' || $code >= 400) {
                return '';
            }
            return $body;
        }

        $context = stream_context_create(array(
            'http' => array(
                'timeout' => $this->timeout,
                'follow_location' => 1,
                'ignore_errors' => false,
                'header' => "Accept: application/atom+xml, application/xml, text/xml\r\nUser-Agent: MajorDoMoDeploy/1.0\r\n",
            ),
            'ssl' => array(
                'verify_peer' => $this->verifyTls,
                'verify_peer_name' => $this->verifyTls,
            ),
        ));
        $body = @file_get_contents($url, false, $context);
        return is_string($body) ? $body : '';
    }
}
