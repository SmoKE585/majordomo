<?php

/**
 * Одноразовые отметки собственных публикаций между MQTT-процессами.
 * Изменения: 2026-11-10 — эхо общего топика отсекается до доставки в привязки.
 */
final class MqttEcho
{
    private const WINDOW_SECONDS = 2;
    private const MAX_PENDING = 512;
    private int $nextWarning = 0;

    public function __construct(private string $file) {}

    public static function shared(): self
    {
        static $guard;
        return $guard ??= new self(ROOT . 'cms/cached/mqtt-echo.json');
    }

    public function publish(\Bluerhinos\phpMQTT $client, string $topic, string $payload, int $qos, int $retain): mixed
    {
        $fingerprint = self::fingerprint($topic, $payload);
        $token = '';
        // Отмечаем отправку до записи в сокет: брокер может вернуть пакет до выхода издателя из publish().
        $registered = $this->update(static function (array &$pending, float $now) use (&$token, $fingerprint): bool {
            $pending = array_filter($pending, static fn(array $entry) => $entry[1] > $now);
            if (count($pending) >= self::MAX_PENDING) unset($pending[array_key_first($pending)]);
            $token = bin2hex(random_bytes(8));
            $pending[$token] = array($fingerprint, $now + self::WINDOW_SECONDS);
            return true;
        });

        $result = false;
        try {
            $result = $client->publish($topic, $payload, $qos, $retain);
            return $result;
        } finally {
            if ($registered && !$result) {
                $this->update(static function (array &$pending) use ($token): bool {
                    if (!isset($pending[$token])) return false;
                    unset($pending[$token]);
                    return true;
                });
            }
        }
    }

    public function consume(string $topic, string $payload): bool
    {
        clearstatcache(true, $this->file);
        if (!is_file($this->file) || filemtime($this->file) < time() - self::WINDOW_SECONDS) return false;

        $fingerprint = self::fingerprint($topic, $payload);
        return $this->update(static function (array &$pending, float $now) use ($fingerprint): bool {
            foreach ($pending as $token => [$sent, $expires]) {
                if ($sent !== $fingerprint || $expires <= $now) continue;
                unset($pending[$token]);
                return true;
            }
            return false;
        });
    }

    private static function fingerprint(string $topic, string $payload): string
    {
        return hash('sha256', $topic . "\0" . $payload);
    }

    private function update(callable $change): bool
    {
        $lock = null;
        try {
            $lock = fopen($this->file . '.lock', 'c+b');
            if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Не удалось заблокировать журнал эха.');

            clearstatcache(true, $this->file);
            $raw = is_file($this->file) ? file_get_contents($this->file) : '';
            if ($raw === false) throw new RuntimeException('Не удалось прочитать журнал эха.');
            $pending = $raw === '' ? array() : json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
            if (!$change($pending, microtime(true))) return false;

            $encoded = json_encode($pending, JSON_THROW_ON_ERROR);
            $temporary = $this->file . '.tmp';
            if (file_put_contents($temporary, $encoded) !== strlen($encoded) || !rename($temporary, $this->file)) {
                throw new RuntimeException('Не удалось сохранить журнал эха.');
            }
            return true;
        } catch (Throwable $error) {
            // Сбой локального кеша не должен останавливать приём или отправку MQTT.
            if (time() >= $this->nextWarning) {
                $this->nextWarning = time() + 60;
                error_log('MajorDoMo MQTT: ' . $error->getMessage());
            }
            return false;
        } finally {
            if (is_resource($lock)) fclose($lock);
        }
    }
}
