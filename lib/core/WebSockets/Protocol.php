<?php

namespace MajorDoMo\Core\WebSockets;

/** Read-only protocol. Only the trusted queue consumer calls publish()/event(). */
final class Protocol
{
    private array $peers = array();
    private int $sequence = 0;
    private string $epoch;

    public function __construct(private \Closure $pages, private \Closure $values, private int $maxSubscriptions = 4096,
        private int $maxPending = 4096, private int $maxPendingBytes = 8388608, private int $maxFrame = 1048576,
        private int $maxRequests = 20000, private ?\Closure $renderObject = null)
    {
        $this->epoch = bin2hex(random_bytes(8));
    }

    public function connect(string $id, \Closure $send, \Closure $close, bool $snapshot = true): void
    {
        $peer = $this->peers[$id] = new Peer($send, $close);
        $this->send($peer, 'ready', array('READ_ONLY' => true, 'SUBSCRIPTIONS' => array('*'), 'SNAPSHOT' => $snapshot));
        if ($snapshot) $this->startSnapshot($peer);
    }

    public function disconnect(string $id): void { unset($this->peers[$id]); }
    public function count(): int { return count($this->peers); }

    private function startSnapshot(Peer $peer): void
    {
        $peer->snapshot = ($this->pages)();
        $peer->pending = array();
        $peer->pendingBytes = 0;
        $this->send($peer, 'snapshot_start', array());
    }

    /** One page per connection per tick; a slow receiver never blocks other peers. */
    public function tick(): void
    {
        foreach ($this->peers as $peer) {
            if ($peer->snapshot === null) continue;
            if ($peer->snapshot->valid()) {
                $rows = array_values(array_filter($peer->snapshot->current(), fn($row) => $peer->watches($row)));
                $this->rows($peer, $rows, true);
                $peer->snapshot->next();
            } else {
                // Re-read invalidations: a queue entry may predate a newer snapshot row.
                $rows = $this->refresh(array_values($peer->pending));
                $peer->snapshot = null;
                $peer->pending = array();
                $peer->pendingBytes = 0;
                $this->rows($peer, $rows);
                $this->send($peer, 'snapshot_end', array());
            }
        }
    }

    public function receive(string $id, string $message): void
    {
        $peer = $this->peers[$id] ?? null;
        if ($peer === null) return;
        if (time() >= $peer->window + 60) { $peer->window = time(); $peer->requests = 0; }
        if (++$peer->requests > $this->maxRequests) { ($peer->close)(1008, 'rate_limit'); return; }
        $peer->lastActivity = time();
        $request = array();
        try {
            if (strlen($message) > $this->maxFrame) throw new \InvalidArgumentException('Сообщение слишком большое.');
            $request = json_decode($message, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($request) || !is_string($request['action'] ?? null) || !is_array($request['data'] ?? array())) throw new \InvalidArgumentException('Ожидается объект {action,data}.');
            $data = $request['data'] ?? array();
            $action = strtolower($request['action']);
            if (in_array($action, array('subscribe', 'unsubscribe'), true)) {
                $this->subscription($peer, $data, $action === 'unsubscribe');
                $this->send($peer, $action === 'subscribe' ? 'subscribed' : 'unsubscribed', array('SUCCESS' => 1) + $data);
            } elseif (in_array($action, array('getproperty', 'getproperties', 'getvalues'), true)) {
                $names = $this->names($data['PROPERTIES'] ?? $data['PROPERTY'] ?? '');
                if (!$names || in_array('*', $names, true)) throw new \InvalidArgumentException('Укажите свойства или объект. Для полного снимка используйте Snapshot.');
                $rows = ($this->values)($names);
                $this->send($peer, 'property_values', array('SUCCESS' => 1, 'DATA' => $rows, 'REQUEST_ID' => $data['REQUEST_ID'] ?? null));
            } elseif ($action === 'snapshot') {
                if ($peer->snapshot !== null) throw new \InvalidArgumentException('Снимок уже передаётся.');
                $this->startSnapshot($peer);
            } elseif ($action === 'ping') {
                $this->send($peer, 'pong', array('REQUEST_ID' => $data['REQUEST_ID'] ?? null));
            } elseif ($action === 'status') {
                $this->send($peer, 'status', array('READ_ONLY' => true, 'CLIENTS' => $this->count()));
            } else {
                $this->send($peer, 'error', array('SUCCESS' => 0, 'CODE' => 'read_only', 'ERROR' => 'WebSocket доступен только для чтения.', 'REQUEST_ID' => $data['REQUEST_ID'] ?? null));
            }
        } catch (\JsonException|\InvalidArgumentException $error) {
            $this->send($peer, 'error', array('SUCCESS' => 0, 'CODE' => 'invalid_request', 'ERROR' => $error->getMessage()));
        }
    }

    private function names(mixed $value): array
    {
        if (is_string($value)) $value = explode(',', $value, $this->maxSubscriptions + 1);
        if (!is_array($value) || count($value) > $this->maxSubscriptions) throw new \InvalidArgumentException('Превышен лимит подписок.');
        $names = array();
        foreach ($value as $name) {
            if (!is_string($name) || strlen($name) > 1024 || preg_match('/[\x00-\x1f\x7f]/', $name)) throw new \InvalidArgumentException('Недопустимое имя подписки.');
            $name = mb_strtolower(trim($name), 'UTF-8');
            if ($name !== '') $names[$name] = true;
        }
        return array_map('strval', array_keys($names));
    }

    private function subscription(Peer $peer, array $data, bool $remove): void
    {
        $type = $data['TYPE'] ?? 'properties';
        if (!in_array($type, array('properties', 'events', 'objects'), true)) throw new \InvalidArgumentException('Неизвестный тип подписки.');
        $field = match ($type) { 'events' => 'EVENTS', 'objects' => 'OBJECT_ID', default => 'PROPERTIES' };
        $names = $this->names($data[$field] ?? '');
        if (!$names && !$remove && $type === 'properties') $names = array('*');
        $updated = ($type === 'objects' || !empty($data['REPLACE'])) ? array() : $peer->{$type};
        if ($remove && !$names) $updated = array();
        foreach ($names as $name) {
            if ($type === 'objects' && (!ctype_digit($name) || (int)$name < 1)) throw new \InvalidArgumentException('Недопустимый ID объекта.');
            if ($remove) unset($updated[$name]); else $updated[$name] = true;
        }
        $count = count($updated);
        foreach (array('properties', 'events', 'objects') as $other) if ($other !== $type) $count += count($peer->{$other});
        if ($count > $this->maxSubscriptions) throw new \InvalidArgumentException('Превышен лимит подписок.');
        $peer->{$type} = $updated;
        if (!$remove && $type === 'properties' && !empty($data['SNAPSHOT']) && $peer->snapshot === null) $this->startSnapshot($peer);
    }

    private function refresh(array $rows): array
    {
        $result = array();
        foreach ($rows as $row) $result[mb_strtolower($row['PROPERTY'], 'UTF-8')] = $row;
        foreach (($this->values)(array_column($rows, 'PROPERTY')) as $row) $result[mb_strtolower($row['PROPERTY'], 'UTF-8')] = $row;
        return array_values($result);
    }

    public function publish(array $rows): void
    {
        if (!$rows) return;
        $rows = $this->refresh($rows);
        ++$this->sequence;
        foreach ($this->peers as $peer) {
            $selected = array_values(array_filter($rows, fn($row) => $peer->watches($row)));
            if ($peer->snapshot !== null) {
                foreach ($selected as $row) {
                    $key = mb_strtolower($row['PROPERTY'], 'UTF-8');
                    $peer->pendingBytes -= isset($peer->pending[$key]) ? strlen(json_encode($peer->pending[$key], JSON_THROW_ON_ERROR)) : 0;
                    $peer->pending[$key] = $row;
                    $peer->pendingBytes += strlen(json_encode($row, JSON_THROW_ON_ERROR));
                }
                if (count($peer->pending) > $this->maxPending || $peer->pendingBytes > $this->maxPendingBytes) ($peer->close)(1013, 'resync_required');
            } else $this->rows($peer, $selected);
            if ($this->renderObject !== null) {
                $ids = array_unique(array_column($rows, 'OBJECT_ID'));
                $objects = array();
                foreach ($ids as $id) if (isset($peer->objects[(string)$id])) $objects[] = array('OBJECT_ID' => $id, 'DATA' => ($this->renderObject)((int)$id));
                if ($objects) $this->send($peer, 'objects', array('DATA' => $objects));
            }
        }
    }

    public function event(string $name, mixed $value): void
    {
        ++$this->sequence;
        foreach ($this->peers as $peer) if (isset($peer->events[mb_strtolower($name, 'UTF-8')])) {
            $this->send($peer, 'events', array('EVENT_DATA' => array('NAME' => $name, 'VALUE' => $value)));
        }
    }

    private function rows(Peer $peer, array $rows, bool $snapshot = false): void
    {
        $batch = array();
        $bytes = 0;
        foreach ($rows as $row) {
            $size = strlen(json_encode($row, JSON_THROW_ON_ERROR));
            if ($batch && $bytes + $size > min(65536, (int)($this->maxFrame / 4))) { $this->send($peer, 'properties', $batch, $snapshot); $batch = array(); $bytes = 0; }
            $batch[] = $row;
            $bytes += $size;
        }
        if ($batch) $this->send($peer, 'properties', $batch, $snapshot);
    }

    private function send(Peer $peer, string $action, array $data, bool $snapshot = false): void
    {
        // Keep data as a JSON string for existing jQuery and native page handlers.
        $payload = json_encode(array('action' => $action, 'data' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'epoch' => $this->epoch, 'sequence' => $this->sequence, 'snapshot' => $snapshot), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (strlen($payload) > $this->maxFrame) { ($peer->close)(1009, 'message_too_large'); return; }
        ($peer->send)($payload);
    }
}
