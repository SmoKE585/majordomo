<?php

require_once dirname(__DIR__, 2) . '/3rdparty/phpmqtt/phpMQTT.php';

/** Optional module subscribers share the MQTT cycle, without owning a client. */
final class MqttIntegrations
{
    private array $modules = array();

    public function __construct()
    {
        $setting = SQLSelectOne("SELECT VALUE FROM settings WHERE NAME='HOOK_EVENT_MQTT_MESSAGE'");
        $registry = json_decode($setting['VALUE'] ?? '{}', true, 64, JSON_THROW_ON_ERROR);
        foreach (array_keys(is_array($registry) ? $registry : array()) as $name) {
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $name) || !isModuleInstalled($name)) continue;
            $file = DIR_MODULES . $name . '/' . $name . '.class.php';
            if (!is_file($file)) continue;
            require_once $file;
            $module = new $name();
            if (!method_exists($module, 'getMqttSubscriptions') || !method_exists($module, 'processSubscription')) continue;
            $this->modules[$name] = $module->getMqttSubscriptions();
        }
    }

    public function subscriptions(): array
    {
        return array_values(array_unique(array_merge(array(), ...array_values($this->modules))));
    }

    public static function matches(string $filter, string $topic): bool
    {
        return \Bluerhinos\phpMQTT::topicMatches($filter, $topic);
    }

    public function receive(string $topic, string $payload): void
    {
        foreach ($this->modules as $name => $filters) {
            foreach ($filters as $filter) {
                if (!self::matches($filter, $topic)) continue;
                $operation = array('target' => $name, 'path' => '/mqtt/module/' . $name,
                    'params' => array('event' => 'MQTT_MESSAGE', 'details' => array('topic' => $topic, 'payload' => $payload)));
                if (!\MajorDoMo\Core\Core::queue()->enqueue('core_runtime', 'moduleevent', json_encode($operation, JSON_THROW_ON_ERROR), false, 3600)) {
                    throw new RuntimeException('MQTT module event was not queued: ' . $name);
                }
                break;
            }
        }
    }
}
