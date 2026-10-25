<?php
/** 2026-10-25 — повторные команды, флаг ONLY_NEW_VALUE и отказ отправки; SQLite без рабочих сервисов. */
require __DIR__ . '/mqtt_throttle_smoke.php';
function DBSafe($value) { return str_replace("'", "''", (string)$value); }
foreach (array('QOS','RETAIN','WRITE_TYPE','READONLY') as $column) $pdo->exec('ALTER TABLE mqtt ADD COLUMN ' . $column . ' INTEGER DEFAULT 0');
$pdo->exec("INSERT INTO mqtt(PATH,PATH_WRITE,VALUE,LINKED_OBJECT,LINKED_PROPERTY,ONLY_NEW_VALUE,QOS,RETAIN)
    VALUES ('outgoing/state','outgoing/cmd','0','OutgoingLight','status',1,1,1)");
$commands = array();
function addToOperationsQueue($topic, $name, $value = '', $unique = false, $ttl = 60)
{
    $GLOBALS['commands'][] = array($topic, $name, json_decode($value, true), $unique);
    return count($GLOBALS['commands']);
}
$sender = new class extends mqtt {
    public function __construct() { $this->config = array('MQTT_WRITE_METHOD'=>2, 'DEBUG_MODE'=>0); }
    public function getConfig() { return $this->config; }
};
$before = $checks;
foreach (array(1,1,0,1) as $value) $sender->propertySetHandle('OutgoingLight', 'status', $value);
check(array_column(array_column($commands, 2), 'v') === array(1,1,0,1), 'Каждая команда сохранена, включая повторную единицу');
check(array_column($commands, 3) === array(false,false,false,false), 'Исходящие команды не склеиваются флагом unique');
check(array_column($commands, 1) === array_fill(0,4,'outgoing/cmd'), 'Команды идут в PATH_WRITE');
check($commands[0][2]['q'] === 1 && $commands[0][2]['r'] === 1, 'Настройки QoS и retain сохраняются');

$failed = new class extends mqtt {
    public int $attempts = 0;
    public $result = 0;
    public function __construct() { $this->config = array('DEBUG_MODE'=>0); }
    public function getConfig() { return $this->config; }
    public function mqttPublish($topic,$value,$qos=0,$retain=0,$write_type=0) { $this->attempts++; return $this->result; }
};
foreach (array(0,false) as $result) {
    $failed->result = $result;
    $pdo->exec("UPDATE mqtt SET VALUE='0' WHERE PATH='outgoing/state'");
    for ($i = 0; $i < 2; $i++) {
        check($failed->propertySetHandle('OutgoingLight','status',1) === false, 'Отказ публикации возвращается воркеру');
        check($pdo->query("SELECT VALUE FROM mqtt WHERE PATH='outgoing/state'")->fetchColumn() === '0', 'Неотправленная команда не подтверждает значение MQTT');
    }
}
check($failed->attempts === 4, 'После отказа повторная команда снова вызывает публикацию');
echo 'MQTT outgoing smoke: ' . ($checks - $before) . " passed; SQLite, no external connections.\n";
