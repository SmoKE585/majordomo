<?php
/** 2026-10-25 — настоящий TCP-брокер на loopback: закрытое соединение и PING/PONG отдельного издателя. */
putenv('APP_ENV=testing'); putenv('USE_REDIS=false');
function brokerFrame($stream): array
{
    $header = fread($stream, 1);
    if ($header === '' || $header === false) throw new RuntimeException('Нет кадра от тестового клиента');
    $length = 0; $multiplier = 1;
    do {
        $byte = fread($stream, 1);
        if ($byte === '' || $byte === false) throw new RuntimeException('Нет длины тестового кадра');
        $digit = ord($byte); $length += ($digit & 127) * $multiplier; $multiplier *= 128;
    } while ($digit & 128);
    $body = '';
    while (strlen($body) < $length) {
        $chunk = fread($stream, $length - strlen($body));
        if ($chunk === '' || $chunk === false) throw new RuntimeException('Оборван тестовый кадр');
        $body .= $chunk;
    }
    return array(ord($header), $body);
}
if (($argv[1] ?? '') === 'broker') {
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (!$server) throw new RuntimeException('Не удалось запустить тестовый брокер');
    echo stream_socket_get_name($server, false), PHP_EOL; flush();
    $first = stream_socket_accept($server, 5);
    if (!$first) throw new RuntimeException('Нет первого подключения');
    stream_set_timeout($first, 5);
    brokerFrame($first); fwrite($first, "\x20\x02\x00\x00");
    usleep(100000); fclose($first);
    echo "CLOSED\n"; flush();
    $peer = stream_socket_accept($server, 5);
    if (!$peer) throw new RuntimeException('Издатель не переподключился');
    stream_set_timeout($peer, 5);
    brokerFrame($peer); fwrite($peer, "\x20\x02\x00\x00");
    [$type, $body] = brokerFrame($peer);
    $topicLength = unpack('n', substr($body, 0, 2))[1];
    if (($type >> 4) !== 3 || substr($body, 2, $topicLength) !== 'fixture/cmd' || substr($body, 2 + $topicLength) !== '1') throw new RuntimeException('Команда не получена новым соединением');
    echo "PUBLISH\n"; flush();
    [$type] = brokerFrame($peer);
    if ($type !== 0xc0) throw new RuntimeException('Нет keepalive PING');
    fwrite($peer, "\xd0\x00"); echo "PING\n"; flush();
    brokerFrame($peer); // DISCONNECT
    fclose($peer); fclose($server); exit(0);
}
// Берём исходник локального пакета: Composer-ссылка Windows недоступна в Linux-контейнере.
require dirname(__DIR__) . '/packages/phpmqtt/src/phpMQTT.php';
require dirname(__DIR__) . '/lib/core.class.php';
require dirname(__DIR__) . '/modules/mqtt/MqttPublisher.php';
$fixture = sys_get_temp_dir() . '/mjd-publisher-' . bin2hex(random_bytes(5));
MajorDoMo\Core\Config::boot($fixture);
function DebMes(...$args) {}
$process = null; $publisher = null;
try {
    $process = proc_open(array(PHP_BINARY, __FILE__, 'broker'), array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')), $pipes);
    if (!is_resource($process)) throw new RuntimeException('Нет тестового процесса');
    fclose($pipes[0]);
    $address = trim(fgets($pipes[1]));
    $publisher = new MqttPublisher(array('MQTT_HOST'=>'127.0.0.1','MQTT_PORT'=>(int)substr(strrchr($address, ':'), 1)));
    if (!$publisher->connect() || trim(fgets($pipes[1])) !== 'CLOSED') throw new RuntimeException('Тестовый брокер не готов');
    if (!$publisher->publish('fixture/cmd','1') || trim(fgets($pipes[1])) !== 'PUBLISH') throw new RuntimeException('Первая команда после обрыва потеряна');
    $cycle = file_get_contents(dirname(__DIR__) . '/scripts/cycle_mqtt.php');
    $start = strpos($cycle, 'function procmsg(');
    eval(substr($cycle, $start, strpos($cycle, 'function mqttShouldStop()', $start) - $start));
    $mqtt = new class {
        public array $config = array();
        public array $received = array();
        public function processMessage($topic, $value, $ignoreStrip) { $this->received[] = array($topic, $value); }
    };
    procmsg('fixture/sensor', '1');
    procmsg('fixture/cmd', '0');
    procmsg('fixture/cmd', '1');
    procmsg('fixture/cmd', '1');
    if ($mqtt->received !== array(array('fixture/sensor', '1'), array('fixture/cmd', '0'), array('fixture/cmd', '1'))) {
        throw new RuntimeException('Эхо должно совпадать по топику и телу и отбрасываться только один раз');
    }
    $failed = new class('127.0.0.1', 0, 'fixture') extends Bluerhinos\phpMQTT {
        public function publish($topic, $content, $qos = 1, $retain = false) { return false; }
    };
    $receiver = new MqttEcho(ROOT . 'cms/cached/mqtt-echo.json');
    if ($receiver->publish($failed, 'fixture/cmd', '1', 0, 0) || $receiver->consume('fixture/cmd', '1')) {
        throw new RuntimeException('Неудачная публикация не должна подавлять входящее событие');
    }
    $client = (new ReflectionProperty($publisher, 'client'))->getValue($publisher);
    $client->keepalive = 1; $client->timesinceping = time() - 2;
    if (!$publisher->tick() || trim(fgets($pipes[1])) !== 'PING') throw new RuntimeException('Простой издателя не обслуживает keepalive');
    $publisher->tick(); $publisher->close();
    $errors = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    $code = proc_close($process); $process = null;
    if ($code !== 0) throw new RuntimeException('Ошибка тестового брокера: ' . $errors);
    echo "MQTT publisher smoke: 5 passed; real loopback TCP, no external broker.\n";
} finally {
    if ($publisher) $publisher->close();
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    foreach (array('/cms/cached/config.php', '/cms/cached/mqtt-echo.json', '/cms/cached/mqtt-echo.json.lock', '/cms/cached/mqtt-echo.json.tmp') as $file) {
        if (is_file($fixture . $file)) unlink($fixture . $file);
    }
    foreach (array('/cms/cached','/cms','') as $directory) if (is_dir($fixture . $directory)) rmdir($fixture . $directory);
}
