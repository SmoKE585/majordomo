<?php

namespace MajorDoMo\Core\WebSockets;

final class Peer
{
    public array $properties = array('*' => true);
    public array $events = array();
    public array $objects = array();
    public ?\Generator $snapshot = null;
    public array $pending = array();
    public int $pendingBytes = 0;
    public int $requests = 0;
    public int $window;
    public int $lastActivity;

    public function __construct(public readonly \Closure $send, public readonly \Closure $close)
    {
        $this->window = $this->lastActivity = time();
    }

    public function watches(array $row): bool
    {
        $name = mb_strtolower($row['PROPERTY'], 'UTF-8');
        return isset($this->properties['*']) || isset($this->properties[$name])
            || isset($this->properties[explode('.', $name, 2)[0]]);
    }
}
