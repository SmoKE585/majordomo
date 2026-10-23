<?php

/**
 * System helpers. Public names and argument defaults are part of the module API.
 *
 * 2026-10-23 — getURL()/postURL() используют Core\Http\HttpCache: Redis или
 * файл, условные запросы ETag/Last-Modified, ошибки и пустые ответы не
 * кэшируются, общий clearCache() чистит HTTP-кэш.
 */

use MajorDoMo\Core\Core;
use MajorDoMo\Core\Http\HttpCache;

/**
 * Путь к файловому кэшу конкретного GET-запроса.
 *
 * Ключ и раскладка каталога живут в HttpCache: getURL() может писать в Redis,
 * но диагностике и clearURLCache() всё равно нужен предсказуемый путь.
 * 2026-10-23 — переведено на общий HttpCache.
 *
 * @param mixed $url Url
 * @return string
 */
function getURLCacheFile($url)
{
    $root = defined('ROOT') ? ROOT : '';
    return HttpCache::filePath($root, HttpCache::key((string)$url));
}

/**
 * Сбросить кэш одного URL.
 *
 * Нужно, когда внешний ресурс уже изменился, а getURL() ещё отдаёт старую
 * копию. Точечный сброс дешевле, чем clearCache(), который чистит весь
 * HTTP-кэш вместе с остальными файлами cms/cached.
 * 2026-10-23 — добавлено для фиксации версии после обновления системы.
 *
 * @param mixed $url Url
 * @return void
 */
function clearURLCache($url)
{
    $key = HttpCache::key((string)$url);
    if (class_exists(Core::class)) {
        try {
            Core::httpCache()->forget($key);
            return;
        } catch (\Throwable $error) {
            // Ниже — прямое удаление файловой копии: кэш не должен мешать
            // обновлению из-за недоступного Redis.
        }
    }
    $file = getURLCacheFile($url);
    if (is_file($file)) {
        @unlink($file);
    }
}

/**
 * Summary of getURL
 * @param mixed $url Url
 * @param mixed $cache Cache (default 0)
 * @param mixed $username User name (default '')
 * @param mixed $password Password (default '')
 * @return mixed
 */
function getURL($url, $cache = 0, $username = '', $password = '', $background = false, $curl_options = 0)
{
    startMeasure('getURL');
    $cache = max(0, (int)$cache);
    $url = (string)$url;
    $http_cache = null;
    $cached = null;

    if ($cache > 0 && !$background && class_exists(Core::class)) {
        try {
            $http_cache = Core::httpCache();
            $cached = $http_cache->read(HttpCache::key($url));
        } catch (\Throwable $error) {
            $http_cache = null;
            $cached = null;
        }
        if ($cached !== null && (time() - (int)$cached['stored_at']) <= $cache) {
            endMeasure('getURL');
            return $cached['body'];
        }
    }

    $conditional_headers = array();
    if ($cached !== null) {
        if (!empty($cached['etag'])) {
            $conditional_headers[] = 'If-None-Match: ' . $cached['etag'];
        }
        if (!empty($cached['last_modified'])) {
            $conditional_headers[] = 'If-Modified-Since: ' . $cached['last_modified'];
        }
    }

    $result = false;
    $http_code = 0;
    $response_headers = array();

    try {
        //DebMes('Geturl started for '.$url. ' Source: ' .debug_backtrace()[1]['function'], 'geturl');
        startMeasure('curl_prepare');
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.9; rv:32.0) Gecko/20100101 Firefox/32.0');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10); // connection timeout
        curl_setopt($ch, CURLOPT_MAXREDIRS, 2);
        @curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 45);  // operation timeout 45 seconds
        if ($background) {
            curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
            curl_setopt($ch, CURLOPT_TIMEOUT_MS, 50);
        }

        if ($username != '' || $password != '') {
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            curl_setopt($ch, CURLOPT_USERPWD, $username . ":" . $password);
        }

        $url_parsed = parse_url($url);
        $host = (string)($url_parsed['host'] ?? '');

        $use_proxy = false;
        if (defined('USE_PROXY') && USE_PROXY != '') {
            $use_proxy = true;
        }

        if ($host == '127.0.0.1' || $host == 'localhost') {
            $use_proxy = false;
        } elseif ($host !== '' && defined('ROOT')) {
            // do not use cookie for local calls
            $tmpfname = ROOT . 'cms/cached/cookie_' . str_replace('.', '_', $host) . '.txt';
            curl_setopt($ch, CURLOPT_COOKIEJAR, $tmpfname);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $tmpfname);
        }


        if ($use_proxy && defined('HOME_NETWORK') && HOME_NETWORK != '') {
            $p = preg_quote(HOME_NETWORK);
            $p = str_replace('\*', '\d+?', $p);
            $p = str_replace(',', ' ', $p);
            $p = str_replace('  ', ' ', $p);
            $p = str_replace(' ', '|', $p);
            if (preg_match('/' . $p . '/is', $host)) {
                $use_proxy = false;
            }
        }

        if ($use_proxy) {
            curl_setopt($ch, CURLOPT_PROXY, USE_PROXY);
            if (defined('USE_PROXY_AUTH') && USE_PROXY_AUTH != '') {
                curl_setopt($ch, CURLOPT_PROXYUSERPWD, USE_PROXY_AUTH);
            }
        }

        if ($conditional_headers !== array()) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $conditional_headers);
        }
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($curl, $header) use (&$response_headers) {
            $length = strlen($header);
            $parts = explode(':', $header, 2);
            if (count($parts) === 2) {
                $response_headers[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return $length;
        });

        endMeasure('curl_prepare');
        startMeasure('curl_exec');

        if (is_array($curl_options)) {
            foreach ($curl_options as $k => $v) {
                if ($k === CURLOPT_HTTPHEADER && is_array($v) && $conditional_headers !== array()) {
                    $v = array_merge($conditional_headers, $v);
                }
                curl_setopt($ch, $k, $v);
            }
        }
        $result = curl_exec($ch);
        $http_code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        endMeasure('curl_exec');

        startMeasure('curl_post');
        if (!$background && curl_errno($ch)) {
            $errorInfo = curl_strerror(curl_errno($ch));
            $info = curl_getinfo($ch);
            $backtrace = debug_backtrace();
            $callSource = $backtrace[1]['function'];
            DebMes("GetURL to $url (source " . $callSource . ") finished with error: \n" . $errorInfo . "\n" . json_encode($info), 'geturl_error');
        }
        curl_close($ch);
        endMeasure('curl_post');


    } catch (\Throwable $e) {
        registerError('geturl', $url . ' ' . get_class($e) . ', ' . $e->getMessage());
    }

    // 304 означает «не изменилось»: тело берём из кэша и продлеваем запись.
    if ($http_code === 304 && $cached !== null) {
        $result = $cached['body'];
        if ($http_cache !== null && !$background) {
            try {
                $http_cache->touch(HttpCache::key($url));
            } catch (\Throwable $error) {
                // Тело уже получено; продление кэша — не критичная операция.
            }
        }
    } elseif (
        $cache > 0
        && !$background
        && $http_cache !== null
        && is_string($result)
        && $result !== ''
        && $http_code > 0
        && $http_code < 400
    ) {
        try {
            $http_cache->write(HttpCache::key($url), $result, array(
                'etag' => (string)($response_headers['etag'] ?? ''),
                'last_modified' => (string)($response_headers['last-modified'] ?? ''),
                'ttl' => $cache,
            ));
        } catch (\Throwable $error) {
            // Неудачная запись кэша не должна ломать сам HTTP-ответ.
        }
    } elseif ($cached !== null && !$background && $http_code >= 400 && $http_cache !== null) {
        // Ресурс ответил ошибкой на условный запрос: старая успешная копия
        // больше не актуальна и не должна жить до конца TTL.
        try {
            $http_cache->forget(HttpCache::key($url));
        } catch (\Throwable $error) {
            // Ошибка очистки не важнее самого HTTP-ответа.
        }
    }

    endMeasure('getURL');

    return $result;
}

function getURLBackground($url, $cache = 0, $username = '', $password = '')
{
    //DebMes("URL: ".$url,'debug1');
    getURL($url, $cache, $username, $password, true);
    return true;
}

/**
 * Summary of postURL
 * @param mixed $url Url
 * @param mixed $query query
 * @param mixed $cache Cache (default 0)
 * @param mixed $username User name (default '')
 * @param mixed $password Password (default '')
 * @return mixed
 */
function postURL($url, $query = array(), $cache = 0, $username = '', $password = '', $background = false)
{
    startMeasure('postURL');
    // DebMes($url,'urls');
    $cache = max(0, (int)$cache);
    $url = (string)$url;
    $http_cache = null;
    // POST кэшируется отдельно от GET: один и тот же адрес с разными данными
    // не должен отдавать чужой ответ.
    $cache_key = HttpCache::key($url, 'POST', is_array($query) ? http_build_query($query) : (string)$query);

    if ($cache > 0 && !$background && class_exists(Core::class)) {
        try {
            $http_cache = Core::httpCache();
            $cached = $http_cache->read($cache_key);
            if ($cached !== null && (time() - (int)$cached['stored_at']) <= $cache) {
                endMeasure('postURL');
                return $cached['body'];
            }
        } catch (\Throwable $error) {
            $http_cache = null;
        }
    }

    $result = false;
    $http_code = 0;

    try {
        //DebMes('Geturl started for '.$url. ' Source: ' .debug_backtrace()[1]['function'], 'geturl');
        startMeasure('curl_prepare');
        $ch = curl_init();
        @curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $query);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.9; rv:32.0) Gecko/20100101 Firefox/32.0');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10); // connection timeout
        curl_setopt($ch, CURLOPT_MAXREDIRS, 2);
        @curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 45);  // operation timeout 45 seconds

        if ($background) {
            curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
            curl_setopt($ch, CURLOPT_TIMEOUT_MS, 300);
        }

        if ($username != '' || $password != '') {
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            curl_setopt($ch, CURLOPT_USERPWD, $username . ":" . $password);
        }

        $url_parsed = parse_url($url);
        $host = (string)($url_parsed['host'] ?? '');

        $use_proxy = false;
        if (defined('USE_PROXY') && USE_PROXY != '') {
            $use_proxy = true;
        }

        if ($host == '127.0.0.1' || $host == 'localhost') {
            $use_proxy = false;
        }

        if ($use_proxy && defined('HOME_NETWORK') && HOME_NETWORK != '') {
            $p = preg_quote(HOME_NETWORK);
            $p = str_replace('\*', '\d+?', $p);
            $p = str_replace(',', ' ', $p);
            $p = str_replace('  ', ' ', $p);
            $p = str_replace(' ', '|', $p);
            if (preg_match('/' . $p . '/is', $host)) {
                $use_proxy = false;
            }
        }

        if ($use_proxy) {
            curl_setopt($ch, CURLOPT_PROXY, USE_PROXY);
            if (defined('USE_PROXY_AUTH') && USE_PROXY_AUTH != '') {
                curl_setopt($ch, CURLOPT_PROXYUSERPWD, USE_PROXY_AUTH);
            }
        }

        if (defined('ROOT')) {
            $tmpfname = ROOT . 'cms/cached/cookie.txt';
            curl_setopt($ch, CURLOPT_COOKIEJAR, $tmpfname);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $tmpfname);
        }

        endMeasure('curl_prepare');
        startMeasure('curl_exec');
        $result = curl_exec($ch);
        $http_code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        endMeasure('curl_exec');


        startMeasure('curl_post');
        if (!$background && curl_errno($ch)) {
            $errorInfo = curl_error($ch);
            $info = curl_getinfo($ch);
            $backtrace = debug_backtrace();
            $callSource = $backtrace[1]['function'];
            DebMes("GetURL to $url (source " . $callSource . ") finished with error: \n" . $errorInfo . "\n" . json_encode($info), 'geturl_error');
        }
        curl_close($ch);
        endMeasure('curl_post');


    } catch (\Throwable $e) {
        registerError('geturl', $url . ' ' . get_class($e) . ', ' . $e->getMessage());
    }

    if (
        $cache > 0
        && !$background
        && $http_cache !== null
        && is_string($result)
        && $result !== ''
        && $http_code > 0
        && $http_code < 400
    ) {
        try {
            $http_cache->write($cache_key, $result, array('ttl' => $cache));
        } catch (\Throwable $error) {
            // Кэш не должен влиять на результат POST.
        }
    }

    endMeasure('postURL');

    return $result;
}

function postURLBackground($url, $query = array(), $cache = 0, $username = '', $password = '')
{
    //DebMes("URL: ".$url,'debug1');
    postURL($url, $query, $cache, $username, $password, true);
}
