<?php

/**
 * Изолированные проверки HTTP-кэша и условных запросов.
 *
 * Поднимает php -S на 127.0.0.1 со случайным портом. Рабочей БД, Redis и
 * внешней сети не требует.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(2);
}

$project = dirname(__DIR__);
$fixture = sys_get_temp_dir() . '/mjd-http-cache-' . bin2hex(random_bytes(6));
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

$server = null;
$pipes = array();
try {
    mkdir($fixture . '/cms/cached', 0777, true);
    mkdir($configRoot, 0777, true);

    define('ROOT', str_replace('\\', '/', $fixture) . '/');
    define('DOC_ROOT', $fixture);
    putenv('USE_REDIS=false');

    require $project . '/lib/core.class.php';
    \MajorDoMo\Core\Config::boot($configRoot);
    require_once $project . '/lib/core/Helpers/bootstrap.php';

    $router = <<<'PHP'
<?php
$stateFile = __DIR__ . '/state.json';
$state = json_decode((string)@file_get_contents($stateFile), true);
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
file_put_contents(__DIR__ . '/requests.log', $path . "\n", FILE_APPEND | LOCK_EX);
if ($path === '/fail') {
    http_response_code(500);
    echo 'failed response';
    exit;
}
if ($path === '/flaky') {
    if (!empty($state['flaky_fail'])) {
        http_response_code(500);
        echo 'flaky failure';
        exit;
    }
    header('Content-Type: text/plain; charset=utf-8');
    echo 'flaky ok';
    exit;
}
if ($path !== '/feed') {
    http_response_code(404);
    echo 'not found';
    exit;
}
$etag = '"v' . (int)($state['v'] ?? 1) . '"';
if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
    http_response_code(304);
    header('ETag: ' . $etag);
    exit;
}
header('Content-Type: application/xml; charset=utf-8');
header('ETag: ' . $etag);
echo (string)($state['body'] ?? '');
PHP;
    file_put_contents($fixture . '/router.php', $router);
    file_put_contents($fixture . '/state.json', json_encode(array('v' => 1, 'body' => '<feed>one</feed>')));
    file_put_contents($fixture . '/requests.log', '');

    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($socket === false) {
        throw new RuntimeException('Cannot allocate a local port: ' . $errstr);
    }
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $port = (int)substr((string)$address, strrpos((string)$address, ':') + 1);
    if ($port <= 0) {
        throw new RuntimeException('Cannot detect the local port');
    }

    $command = array(PHP_BINARY, '-S', '127.0.0.1:' . $port, $fixture . '/router.php');
    file_put_contents($fixture . '/server.in', '');
    $descriptors = array(
        0 => array('file', $fixture . '/server.in', 'r'),
        1 => array('file', $fixture . '/server.out', 'a'),
        2 => array('file', $fixture . '/server.err', 'a'),
    );
    $server = proc_open($command, $descriptors, $pipes, $fixture);
    if (!is_resource($server)) {
        throw new RuntimeException('Cannot start the local PHP server');
    }
    $ready = false;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $connection = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        if (is_resource($connection)) {
            fclose($connection);
            $ready = true;
            break;
        }
        usleep(100000);
    }
    if (!$ready) {
        throw new RuntimeException('The local PHP server did not start');
    }

    $url = 'http://127.0.0.1:' . $port . '/feed';
    $key = \MajorDoMo\Core\Http\HttpCache::key($url);
    $cache = \MajorDoMo\Core\Core::httpCache();
    $countRequests = static function () use ($fixture): int {
        $log = @file($fixture . '/requests.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        return is_array($log) ? count($log) : 0;
    };
    $ageCache = static function (string $cacheKey): void {
        $file = \MajorDoMo\Core\Http\HttpCache::filePath(ROOT, $cacheKey);
        $data = json_decode((string)file_get_contents($file), true);
        $data['stored_at'] = time() - 3600;
        file_put_contents($file, json_encode($data));
    };

    $cache->forget($key);
    check($cache->read($key) === null, 'Запись удаляется перед проверкой');

    $first = getURL($url, 60);
    check($first === '<feed>one</feed>' && $countRequests() === 1, 'Первый GET скачивает ответ');
    check($cache->read($key) !== null, 'Ответ попадает в кэш');

    $second = getURL($url, 60);
    check($second === $first && $countRequests() === 1, 'Свежий кэш не ходит в сеть повторно');

    $ageCache($key);
    $third = getURL($url, 60);
    check($third === '<feed>one</feed>' && $countRequests() === 2, 'Истёкший кэш делает условный запрос и получает 304');
    $entry = $cache->read($key);
    check($entry !== null && (time() - (int)$entry['stored_at']) < 5, '304 продлевает время записи');

    file_put_contents($fixture . '/state.json', json_encode(array('v' => 2, 'body' => '<feed>two</feed>')));
    $ageCache($key);
    $fourth = getURL($url, 60);
    check($fourth === '<feed>two</feed>' && $countRequests() === 3, 'Изменённый ответ обновляет кэш');

    $failUrl = 'http://127.0.0.1:' . $port . '/fail';
    $failKey = \MajorDoMo\Core\Http\HttpCache::key($failUrl);
    $cache->forget($failKey);
    $before = $countRequests();
    $failedFirst = getURL($failUrl, 60);
    $failedSecond = getURL($failUrl, 60);
    check($failedFirst === 'failed response' && $failedSecond === 'failed response', 'Ошибочный ответ возвращается вызывающему');
    check($cache->read($failKey) === null, 'Ответ 5xx не кэшируется');
    check($countRequests() === $before + 2, 'Повторный запрос к 5xx снова идёт в сеть');

    $flakyUrl = 'http://127.0.0.1:' . $port . '/flaky';
    $flakyKey = \MajorDoMo\Core\Http\HttpCache::key($flakyUrl);
    $cache->forget($flakyKey);
    file_put_contents($fixture . '/state.json', json_encode(array('v' => 2, 'body' => '<feed>two</feed>', 'flaky_fail' => false)));
    $before = $countRequests();
    check(getURL($flakyUrl, 60) === 'flaky ok' && $cache->read($flakyKey) !== null, 'Успешный ответ сохраняется для ревалидации');
    $ageCache($flakyKey);
    file_put_contents($fixture . '/state.json', json_encode(array('v' => 2, 'body' => '<feed>two</feed>', 'flaky_fail' => true)));
    check(getURL($flakyUrl, 60) === 'flaky failure', 'Ошибка при ревалидации возвращается вызывающему');
    check($cache->read($flakyKey) === null, 'Ошибка при ревалидации удаляет устаревшую успешную копию');
    getURL($flakyUrl, 60);
    check($countRequests() === $before + 3, 'После ошибки запрос снова идёт в сеть целиком');

    check(
        \MajorDoMo\Core\Http\HttpCache::key($url, 'POST', 'a=1') !== \MajorDoMo\Core\Http\HttpCache::key($url, 'POST', 'a=2'),
        'Ключ POST учитывает тело запроса'
    );

    clearURLCache($url);
    check($cache->read($key) === null && !is_file(\MajorDoMo\Core\Http\HttpCache::filePath(ROOT, $key)), 'clearURLCache удаляет запись');

    $cache->write($key, 'after-clear', array('etag' => '"x"'));
    clearCache(0);
    check($cache->read($key) === null, 'Общая очистка кэша удаляет HTTP-записи');

    echo 'OK: ' . $checks . ' HTTP cache checks' . PHP_EOL;
} finally {
    if (is_resource($server)) {
        @proc_terminate($server);
        @proc_close($server);
    }
    $remove($fixture);
}
