<?php

use MajorDoMo\Core\RedisConnection;

/** Per-topic admission window; never retains MQTT payloads or uses MySQL. */
class MqttUnlinkedThrottle
{
    protected array $expires = array();
    private int $nextCleanup = 0;
    private int $nextWarning = 0;

    public function __construct(private RedisConnection $connection, private int $maxEntries = 65536) {}

    protected function now(): int
    {
        return time();
    }

    private function topicKey(string $topic, int $interval): string
    {
        return hash('sha256', $interval . ':' . $topic);
    }

    private function remember(string $key, int $expires): void
    {
        if (!isset($this->expires[$key]) && count($this->expires) >= $this->maxEntries) {
            unset($this->expires[array_key_first($this->expires)]);
        }
        $this->expires[$key] = $expires;
    }

    private function redisFailed(Throwable $error, int $now): void
    {
        $this->connection->discard();
        if ($now >= $this->nextWarning) {
            error_log('MajorDoMo MQTT: Redis throttle unavailable; using bounded cycle memory (' . get_class($error) . ')');
            $this->nextWarning = $now + 60;
        }
    }

    public function allow(string $topic, int $interval): bool
    {
        if ($interval <= 0) return true;
        $now = $this->now();
        if ($now >= $this->nextCleanup) {
            foreach ($this->expires as $key => $expires) {
                if ($expires <= $now) unset($this->expires[$key]);
            }
            $this->nextCleanup = $now + 60;
        }
        $key = $this->topicKey($topic, $interval);
        if (($this->expires[$key] ?? 0) > $now) return false;

        $redis = $this->connection->get();
        if ($redis) {
            try {
                if (!$redis->set($this->connection->key('mqtt:unlinked:') . $key, (string)($now + $interval), array('nx', 'ex' => $interval))) {
                    $ttl = $redis->ttl($this->connection->key('mqtt:unlinked:') . $key);
                    if ($ttl === -1) throw new RuntimeException('MQTT throttle key has no expiry');
                    if ($ttl >= 0) $this->remember($key, $now + max(1, $ttl));
                    return false;
                }
            } catch (Throwable $error) {
                $this->redisFailed($error, $now);
            }
        }
        $this->remember($key, $now + $interval);
        return true;
    }

    /** A failed SQL write must not hide the next message for a whole interval. */
    public function cancel(string $topic, int $interval): void
    {
        if ($interval <= 0) return;
        $key = $this->topicKey($topic, $interval);
        $expires = $this->expires[$key] ?? null;
        unset($this->expires[$key]);
        if ($expires === null) return;
        $redis = $this->connection->get();
        if (!$redis) return;
        try {
            $redis->eval("if redis.call('GET', KEYS[1]) == ARGV[1] then return redis.call('DEL', KEYS[1]) end return 0",
                array($this->connection->key('mqtt:unlinked:') . $key, (string)$expires), 1);
        } catch (Throwable $error) {
            $this->redisFailed($error, $this->now());
        }
    }
}
