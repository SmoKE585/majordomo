<?php

/** RESP transport for test hosts without phpredis. All commands run on the real isolated Redis. */
class Redis
{
    public const OPT_READ_TIMEOUT = 3;
    private $socket;
    public function pconnect($host, $port, $timeout, $id = null) { $this->socket = fsockopen($host, $port, $errno, $error, $timeout); return (bool)$this->socket; }
    public function setOption($key, $value) { stream_set_timeout($this->socket, (int)ceil($value)); return true; }
    public function isConnected() { return is_resource($this->socket); }
    public function set($key, $value, $options = array()) {
        $args = array($key, $value);
        if (is_int($options)) $args = array_merge($args, array('EX', $options));
        else foreach ($options as $k => $v) {
            if (is_int($k)) $args[] = strtoupper($v);
            else { $args[] = strtoupper($k); $args[] = $v; }
        }
        return $this->command('SET', $args);
    }
    public function eval($script, $args, $numKeys) { return $this->command('EVAL', array_merge(array($script, $numKeys), $args)); }
    public function scan(&$cursor, $pattern, $count) {
        $result = $this->command('SCAN', array($cursor ?? 0, 'MATCH', $pattern, 'COUNT', $count));
        $cursor = (int)$result[0];
        return $result[1] ?: false;
    }
    public function hGetAll($key) { $raw = $this->command('HGETALL', array($key)); $result = array(); for ($i=0; $i<count($raw); $i+=2) $result[$raw[$i]]=$raw[$i+1]; return $result; }
    public function __call($name, $args) { return $this->command(strtoupper($name), $args); }
    private function command($name, $args) {
        $args = array_merge(array($name), $args);
        $wire = '*' . count($args) . "\r\n";
        foreach ($args as $arg) $wire .= '$' . strlen((string)$arg) . "\r\n" . $arg . "\r\n";
        $written = 0;
        while ($written < strlen($wire)) {
            $bytes = fwrite($this->socket, substr($wire, $written));
            if (!$bytes) throw new RuntimeException('Test Redis socket write failed');
            $written += $bytes;
        }
        return $this->read();
    }
    private function read() {
        $line = fgets($this->socket);
        if ($line === false) throw new RuntimeException('Test Redis socket closed');
        $kind = $line[0]; $value = substr($line, 1, -2);
        if ($kind === '-') throw new RuntimeException('Test Redis: ' . $value);
        if ($kind === '+') return $value === 'OK' ? true : $value;
        if ($kind === ':') return (int)$value;
        if ($kind === '$') {
            if ((int)$value < 0) return false;
            $result = ''; $length = (int)$value + 2;
            while (strlen($result) < $length) { $part = fread($this->socket, $length - strlen($result)); if ($part === '' || $part === false) throw new RuntimeException('Test Redis short response'); $result .= $part; }
            return substr($result, 0, -2);
        }
        if ($kind === '*') {
            $result = array(); for ($i = 0; $i < (int)$value; $i++) $result[] = $this->read(); return $result;
        }
        throw new RuntimeException('Unknown RESP reply');
    }
}
