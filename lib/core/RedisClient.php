<?php

namespace MajorDoMo\Core;

/** Discard broken sockets so a long-running worker can reconnect on its next attempt. */
final class RedisClient
{
    public function __construct(private RedisConnection $connection) {}

    public function __call(string $method, array $arguments): mixed
    {
        $client = $this->connection->get();
        if (!$client) throw new \RuntimeException('Redis is unavailable; operation was not accepted');
        try {
            return $client->$method(...$arguments);
        } catch (\Throwable $e) {
            $this->connection->discard();
            throw new \RuntimeException('Redis operation failed; connection will be retried', 0, $e);
        }
    }

    public function scan(?int &$cursor, string $pattern, int $count): array|false
    {
        $client = $this->connection->get();
        if (!$client) throw new \RuntimeException('Redis is unavailable');
        try {
            return $client->scan($cursor, $pattern, $count);
        } catch (\Throwable $e) {
            $this->connection->discard();
            throw new \RuntimeException('Redis scan failed; connection will be retried', 0, $e);
        }
    }
}
