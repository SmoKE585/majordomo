<?php
// 2026-10-25 — кеш метаданных: версия на каждом чтении, ограничение памяти, без Redis/БД приложения.
namespace MajorDoMo\Core {
    final class Config { public static bool $enabled = true; public static function get($key) { return self::$enabled; } }
    final class RedisConnection {
        public function __construct(public object $client) {}
        public function key($suffix) { return 'fixture:' . $suffix; }
        public function requireConnection() { return $this->client; }
    }
}
namespace {
    putenv('APP_ENV=testing'); putenv('USE_REDIS=false');
    require dirname(__DIR__) . '/lib/core/Database.php';
    require dirname(__DIR__) . '/lib/core/MetadataCache.php';
    $redis = new class {
        public array $data = array(); public int $reads = 0;
        public function get($key) { $this->reads++; return $this->data[$key] ?? false; }
        public function set($key, $value, $ttl) { $this->data[$key] = $value; }
        public function incr($key) { return $this->data[$key] = (int)($this->data[$key] ?? 0) + 1; }
    };
    $cache = new MajorDoMo\Core\MetadataCache(new MajorDoMo\Core\Database(new stdClass()), new MajorDoMo\Core\RedisConnection($redis));
    $loads = 0; $checks = 0;
    $load = static function () use (&$loads) { $loads++; return array('ID' => 1, 'CODE' => 'fixture'); };
    function check($condition, $message) { if (!$condition) throw new RuntimeException($message); $GLOBALS['checks']++; }
    for ($i = 0; $i < 100; $i++) check($cache->get('method:1', $load)['ID'] === 1, 'Описание метода прочитано');
    check($loads === 1 && $redis->reads === 101, '100 чтений требуют 101 GET вместо 200, загрузчик вызван один раз');
    $redis->incr('fixture:meta:epoch');
    $cache->get('method:1', $load);
    check($loads === 2, 'Изменение версии другим процессом немедленно обновляет описание');
    $cache->invalidate();
    $cache->get('method:1', $load);
    check($loads === 3, 'Локальная инвалидация очищает оба уровня');
    for ($i = 0; $i < 600; $i++) $cache->get('item:' . $i, $load);
    check(count((new ReflectionProperty($cache, 'local'))->getValue($cache)) === 512, 'Память долгоживущего обработчика ограничена');
    MajorDoMo\Core\Config::$enabled = false;
    $before = $loads;
    $cache->get('method:1', $load); $cache->get('method:1', $load);
    check($loads === $before + 2, 'Без общей версии Redis метаданные не остаются в устаревшем локальном кеше');
    echo 'Metadata cache smoke: ' . $checks . " passed; fake Redis, no database connection.\n";
}
