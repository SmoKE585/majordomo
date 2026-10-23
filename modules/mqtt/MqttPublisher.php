<?php

use MajorDoMo\Core\Config;

/**
 * Долгоживущее соединение для исходящих команд MQTT.
 *
 * Отдельный процесс издателя нужен, чтобы готовая команда свету не ждала
 * разбора пачки датчиков и не открывала TCP-соединение на каждую публикацию.
 *
 * 2026-10-23 — добавлено для быстрого пути MQTT.
 */
final class MqttPublisher
{
    private ?Bluerhinos\phpMQTT $client = null;
    private float $lastErrorAt = 0.0;

    public function __construct(private array $config) {}

    public function connect(): bool
    {
        $this->close();
        $clientName = !empty($this->config['MQTT_CLIENT']) ? $this->config['MQTT_CLIENT'] : 'MajorDoMo MQTT';
        $clientName .= ' Publisher (#' . uniqid() . ')';
        $host = !empty($this->config['MQTT_HOST']) ? $this->config['MQTT_HOST'] : 'localhost';
        $port = !empty($this->config['MQTT_PORT']) ? (int)$this->config['MQTT_PORT'] : 1883;
        $username = !empty($this->config['MQTT_AUTH']) ? (string)($this->config['MQTT_USERNAME'] ?? '') : '';
        $password = !empty($this->config['MQTT_AUTH']) ? (string)($this->config['MQTT_PASSWORD'] ?? '') : '';
        $client = new Bluerhinos\phpMQTT($host, $port, $clientName);
        $client->setReadLimits(Config::int('MQTT_MAX_PACKET_BYTES'), Config::float('MQTT_PACKET_TIMEOUT'));
        if (!empty($this->config['MQTT_TLS_ENABLE'])) {
            $options = array('local_cert' => !empty($this->config['MQTT_TLS_CERTPATH']) ? $this->config['MQTT_TLS_CERTPATH'] : false);
            if (!empty($this->config['MQTT_TLS_IGNORE_CERT'])) {
                $options['verify_peer'] = false;
                $options['verify_peer_name'] = false;
                $options['allow_self_signed'] = true;
            }
            $context = stream_context_create(array('ssl' => $options));
            if (method_exists($client, 'setTlsContext')) {
                $client->setTlsContext($context);
            } elseif (property_exists($client, 'tlsContext')) {
                $client->tlsContext = $context;
            }
        }
        $connected = $username !== ''
            ? $client->connect(true, null, $username, $password)
            : $client->connect();
        if (!$connected) {
            $this->reportError('MQTT publisher cannot connect to ' . $host . ':' . $port);
            return false;
        }
        $this->client = $client;
        return true;
    }

    public function publish(string $topic, string $value, int $qos = 0, int $retain = 0): bool
    {
        if ($this->client === null && !$this->connect()) return false;
        $result = $this->client->publish($topic, $value, $qos, $retain);
        if ($result === false || $result === 0) {
            // Соединение могло закрыться между командами; следующий вызов
            // переподключится, а вызывающий вернёт сообщение в очередь.
            $this->reportError('MQTT publish failed for ' . $topic);
            $this->close();
            return false;
        }
        return true;
    }

    public function close(): void
    {
        if ($this->client !== null) {
            try { $this->client->close(); } catch (Throwable $ignored) {}
            $this->client = null;
        }
    }

    private function reportError(string $message): void
    {
        if (time() - $this->lastErrorAt < 60) return;
        $this->lastErrorAt = time();
        DebMes($message, 'mqtt_error');
    }

    public function __destruct()
    {
        $this->close();
    }
}
