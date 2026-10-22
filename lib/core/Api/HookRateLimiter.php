<?php

namespace MajorDoMo\Core\Api;

/**
 * Небольшой файловый лимитер для публичных webhook.
 *
 * Внешние сервисы обращаются к webhook с разных адресов, поэтому корзина
 * считается на сохранённый вход, а не на IP клиента. Файл блокируется на время
 * одного чтения/записи: лимит согласован между PHP-воркерами без Redis и без
 * записи в БД на каждый запрос.
 *
 * Изменения: 2026-10-22 — добавлен лимит частоты для /api/hooks/<secret>.
 */
final class HookRateLimiter
{
    public function __construct(
        private string $root,
        private int $limit = 120,
        private int $window = 60
    ) {
        $this->limit = max(1, $this->limit);
        $this->window = max(1, $this->window);
    }

    /** Returns 0 when the request is allowed, or Retry-After seconds. */
    public function hit(int $hookId, ?int $now = null): int
    {
        $now ??= time();
        return $this->locked(function (array &$state) use ($hookId, $now): int {
            foreach ($state as $key => $entry) {
                if (!is_array($entry) || (int)($entry['since'] ?? 0) <= $now - ($this->window * 2)) {
                    unset($state[$key]);
                }
            }
            if (count($state) >= 1000 && !isset($state[(string)$hookId])) {
                return $this->window;
            }
            $key = (string)$hookId;
            $entry = $state[$key] ?? array('since' => $now, 'count' => 0);
            if ((int)$entry['since'] <= $now - $this->window) {
                $entry = array('since' => $now, 'count' => 0);
            }
            if ((int)$entry['count'] >= $this->limit) {
                return max(1, (int)$entry['since'] + $this->window - $now);
            }
            $entry['count'] = (int)$entry['count'] + 1;
            $state[$key] = $entry;
            return 0;
        });
    }

    private function locked(callable $operation): mixed
    {
        $directory = rtrim($this->root, '/\\') . '/var/security';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Не удалось создать каталог защиты webhook.');
        }
        $file = fopen($directory . '/api-hooks.json', 'c+');
        if ($file === false) {
            throw new \RuntimeException('Не удалось открыть защиту webhook.');
        }
        try {
            if (!flock($file, LOCK_EX)) {
                throw new \RuntimeException('Не удалось заблокировать защиту webhook.');
            }
            $raw = stream_get_contents($file);
            if ($raw === false) {
                throw new \RuntimeException('Не удалось прочитать защиту webhook.');
            }
            $state = $raw === '' ? array() : json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($state)) {
                throw new \RuntimeException('Повреждено хранилище защиты webhook.');
            }
            $result = $operation($state);
            $encoded = json_encode($state, JSON_THROW_ON_ERROR);
            rewind($file);
            if (!ftruncate($file, 0) || fwrite($file, $encoded) !== strlen($encoded) || !fflush($file)) {
                throw new \RuntimeException('Не удалось сохранить защиту webhook.');
            }
            return $result;
        } finally {
            flock($file, LOCK_UN);
            fclose($file);
        }
    }
}
