<?php

namespace Bluerhinos;

/*
 	phpMQTT
	A simple php class to connect/publish/subscribe to an MQTT broker

*/

/*
	Licence

	Copyright (c) 2010 Blue Rhinos Consulting | Andrew Milsted
	andrew@bluerhinos.co.uk | http://www.bluerhinos.co.uk

	Permission is hereby granted, free of charge, to any person obtaining a copy
	of this software and associated documentation files (the "Software"), to deal
	in the Software without restriction, including without limitation the rights
	to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
	copies of the Software, and to permit persons to whom the Software is
	furnished to do so, subject to the following conditions:

	The above copyright notice and this permission notice shall be included in
	all copies or substantial portions of the Software.

	THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
	IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
	FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
	AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
	LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
	OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
	THE SOFTWARE.
	
*/

/**
 * Локальная версия BlueRhinos phpMQTT с ограниченным приёмом пакетов.
 * Изменения: 2026-10-22 — перенесена в Composer-пакет без изменения протокола.
 */

class phpMQTT
{
    protected $socket;            /* holds the socket	*/
    protected $msgid = 1;            /* counter for message id */
    public $keepalive = 10;        /* default keepalive timmer */
    public $timesinceping;        /* host unix time, used to detect disconects */
    public $topics = [];    /* used to store currently subscribed topics */
    public $debug = false;        /* should output debug messages */
    public $address;            /* broker address */
    public $port;                /* broker port */
    public $clientid;            /* client id sent to brocker */
    public $will;                /* stores the will of the client */
    protected $username;            /* stores username */
    protected $password;            /* stores password */

    public $cafile;
    protected int $maxPacketBytes = 1048576;
    protected float $packetTimeout = 5.0;
    private ?int $incomingHeader = null;
    private ?int $incomingLength = null;
    private int $lengthBytes = 0;
    private int $lengthMultiplier = 1;
    private int $lengthValue = 0;
    private int $receivedBytes = 0;
    private string $incomingBody = '';
    private float $packetDeadline = 0.0;
    private bool $discardPacket = false;
    private int $lastReceivedAt = 0;

    public function setReadLimits(int $maxPacketBytes, float $packetTimeout): void
    {
        if ($maxPacketBytes < 1 || $maxPacketBytes > 268435455 || !is_finite($packetTimeout) || $packetTimeout <= 0) {
            throw new \InvalidArgumentException('Invalid MQTT receive limits');
        }
        $this->maxPacketBytes = $maxPacketBytes;
        $this->packetTimeout = $packetTimeout;
    }

    private function resetPacket(): void
    {
        $this->incomingHeader = $this->incomingLength = null;
        $this->lengthBytes = $this->lengthValue = $this->receivedBytes = 0;
        $this->lengthMultiplier = 1;
        $this->incomingBody = '';
        $this->packetDeadline = 0.0;
        $this->discardPacket = false;
    }

    private function failRead(string $message): bool
    {
        $this->_errorMessage($message);
        if (is_resource($this->socket)) fclose($this->socket);
        $this->socket = null;
        $this->resetPacket();
        return false;
    }

    private function checkKeepalive(): bool
    {
        if ($this->lastReceivedAt && $this->lastReceivedAt < time() - $this->keepalive * 2) return $this->failRead('MQTT broker keepalive timed out');
        if ($this->timesinceping < time() - $this->keepalive) return $this->ping();
        return true;
    }

    private function readAvailable(int $length, float $wait): string|false
    {
        if (!is_resource($this->socket)) return false;
        $read = array($this->socket);
        $write = $except = null;
        $wait = max(0.0, $wait);
        $seconds = (int)$wait;
        $ready = stream_select($read, $write, $except, $seconds, (int)(($wait - $seconds) * 1000000));
        if ($ready === false) return false;
        if ($ready === 0) return '';
        $chunk = fread($this->socket, min(8192, $length));
        if ($chunk === false || ($chunk === '' && feof($this->socket))) return false;
        return $chunk;
    }
    protected static $known_commands = [
        1 => 'CONNECT',
        2 => 'CONNACK',
        3 => 'PUBLISH',
        4 => 'PUBACK',
        5 => 'PUBREC',
        6 => 'PUBREL',
        7 => 'PUBCOMP',
        8 => 'SUBSCRIBE',
        9 => 'SUBACK',
        10 => 'UNSUBSCRIBE',
        11 => 'UNSUBACK',
        12 => 'PINGREQ',
        13 => 'PINGRESP',
        14 => 'DISCONNECT'
    ];

    /**
     * phpMQTT constructor.
     *
     * @param $address
     * @param $port
     * @param $clientid
     * @param null $cafile
     */
    public function __construct($address, $port, $clientid, $cafile = null)
    {
        $this->broker($address, $port, $clientid, $cafile);
    }

    /**
     * Sets the broker details
     *
     * @param $address
     * @param $port
     * @param $clientid
     * @param null $cafile
     */
    public function broker($address, $port, $clientid, $cafile = null)
    {
        $this->address = $address;
        $this->port = $port;
        $this->clientid = $clientid;
        $this->cafile = $cafile;
    }

    /**
     * Will try and connect, if fails it will sleep 10s and try again, this will enable the script to recover from a network outage
     *
     * @param bool $clean - should the client send a clean session flag
     * @param null $will
     * @param null $username
     * @param null $password
     *
     * @return bool
     */
    public function connect_auto($clean = true, $will = null, $username = null, $password = null): bool
    {
        while ($this->connect($clean, $will, $username, $password) === false) {
            sleep(10);
        }
        return true;
    }

    /**
     * @param bool $clean - should the client send a clean session flag
     * @param null $will
     * @param null $username
     * @param null $password
     *
     * @return bool
     */
    public function connect($clean = true, $will = null, $username = null, $password = null): bool
    {
        if (is_resource($this->socket)) fclose($this->socket);
        $this->resetPacket();
        if ($will) {
            $this->will = $will;
        }
        if ($username) {
            $this->username = $username;
        }
        if ($password) {
            $this->password = $password;
        }

        if ($this->cafile) {
            $socketContext = stream_context_create(
                [
                    'ssl' => [
                        'verify_peer_name' => true,
                        'cafile' => $this->cafile
                    ]
                ]
            );
            $this->socket = stream_socket_client('tls://' . $this->address . ':' . $this->port, $errno, $errstr, 60, STREAM_CLIENT_CONNECT, $socketContext);
        } else {
            $this->socket = stream_socket_client('tcp://' . $this->address . ':' . $this->port, $errno, $errstr, 60, STREAM_CLIENT_CONNECT);
        }

        if (!$this->socket) {
            $this->_errorMessage("stream_socket_create() $errno, $errstr");
            return false;
        }

        stream_set_timeout($this->socket, 30);
        stream_set_blocking($this->socket, 0);

        $i = 0;
        $buffer = '';

        $buffer .= chr(0x00);
        $i++; // Length MSB
        $buffer .= chr(0x04);
        $i++; // Length LSB
        $buffer .= chr(0x4d);
        $i++; // M
        $buffer .= chr(0x51);
        $i++; // Q
        $buffer .= chr(0x54);
        $i++; // T
        $buffer .= chr(0x54);
        $i++; // T
        $buffer .= chr(0x04);
        $i++; // // Protocol Level

        //No Will
        $var = 0;
        if ($clean) {
            $var += 2;
        }

        //Add will info to header
        if ($this->will !== null) {
            $var += 4; // Set will flag
            $var += ($this->will['qos'] << 3); //Set will qos
            if ($this->will['retain']) {
                $var += 32;
            } //Set will retain
        }

        if ($this->username !== null) {
            $var += 128;
        }    //Add username to header
        if ($this->password !== null) {
            $var += 64;
        }    //Add password to header

        $buffer .= chr($var);
        $i++;

        //Keep alive
        $buffer .= chr($this->keepalive >> 8);
        $i++;
        $buffer .= chr($this->keepalive & 0xff);
        $i++;

        $buffer .= $this->strwritestring($this->clientid, $i);

        //Adding will to payload
        if ($this->will !== null) {
            $buffer .= $this->strwritestring($this->will['topic'], $i);
            $buffer .= $this->strwritestring($this->will['content'], $i);
        }

        if ($this->username !== null) {
            $buffer .= $this->strwritestring($this->username, $i);
        }
        if ($this->password !== null) {
            $buffer .= $this->strwritestring($this->password, $i);
        }

        $head = chr(0x10);

        while ($i > 0) {
            $encodedByte = $i % 128;
            $i /= 128;
            $i = (int)$i;
            if ($i > 0) {
                $encodedByte |= 128;
            }
            $head .= chr($encodedByte);
        }

        if ($this->_fwrite($head . $buffer) === false) return false;

        $string = $this->read(4);

        if ($string === false || strlen($string) !== 4) return false;
        if ($string[0] === chr(0x20) && $string[1] === chr(2) && $string[3] === chr(0)) {
            $this->_debugMessage('Connected to Broker');
        } else {
            return $this->failRead(
                sprintf(
                    "Connection failed! (Error: 0x%02x 0x%02x)\n",
                    ord($string[0]),
                    ord($string[3])
                )
            );
        }

        $this->timesinceping = time();
        $this->lastReceivedAt = time();

        return true;
    }

    /**
     * Reads in so many bytes
     *
     * @param int $int
     * @param bool $nb
     *
     * @return false|string
     */
    public function read($int = 8192, $nb = false)
    {
        if ($int < 1) return '';
        if ($int > $this->maxPacketBytes) return $this->failRead('MQTT read exceeds packet size limit');
        if ($nb) return $this->readAvailable((int)$int, 0.0);
        $string = '';
        $deadline = microtime(true) + $this->packetTimeout;
        while (strlen($string) < $int) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) return $this->failRead('MQTT read timeout');
            $chunk = $this->readAvailable((int)$int - strlen($string), $remaining);
            if ($chunk === false) return $this->failRead('MQTT connection closed during read');
            $string .= $chunk;
        }

        return $string;
    }

    /**
     * Subscribes to a topic, wait for message and return it
     *
     * @param $topic
     * @param $qos
     *
     * @return string
     */
    public function subscribeAndWaitForMessage($topic, $qos): string
    {
        $this->subscribe(
            [
                $topic => [
                    'qos' => $qos,
                    'function' => '__direct_return_message__'
                ]
            ]
        );

        do {
            $return = $this->proc();
        } while ($return === true);

        return $return;
    }

    /**
     * subscribes to topics
     *
     * @param $topics
     * @param int $qos
     */
    public function subscribe($topics, $qos = 0)
    {
        $i = 0;
        $buffer = '';
        $id = $this->msgid;
        $buffer .= chr($id >> 8);
        $i++;
        $buffer .= chr($id % 256);
        $i++;

        foreach ($topics as $key => $topic) {
            $buffer .= $this->strwritestring($key, $i);
            $buffer .= chr($topic['qos']);
            $i++;
            $this->topics[$key] = $topic;
        }

        $cmd = 0x82;
        //$qos
        $cmd += ($qos << 1);

        $head = chr($cmd);
        $head .= $this->setmsglength($i);
        // SUBACK and retained messages share the stream. Let proc() consume
        // them in order instead of mistaking a retained PUBLISH for SUBACK.
        return $this->_fwrite($head . $buffer) !== false;
    }

    /**
     * Sends a keep alive ping
     */
    public function ping()
    {
        $head = chr(0xc0);
        $head .= chr(0x00);
        if ($this->_fwrite($head) === false) return false;
        $this->timesinceping = time();
        $this->_debugMessage('ping sent');
        return true;
    }

    /**
     *  sends a proper disconnect cmd
     */
    public function disconnect()
    {
        $head = ' ';
        $head[0] = chr(0xe0);
        $head[1] = chr(0x00);
        if (is_resource($this->socket)) return $this->_fwrite($head);
        return false;
    }

    /**
     * Sends a proper disconnect, then closes the socket
     */
    public function close()
    {
        if (is_resource($this->socket)) {
            $this->disconnect();
            if (is_resource($this->socket)) fclose($this->socket);
        }
        $this->socket = null;
        $this->resetPacket();
    }

    /**
     * Publishes $content on a $topic
     *
     * @param $topic
     * @param $content
     * @param int $qos
     * @param bool $retain
     */
    public function publish($topic, $content, $qos = 1, $retain = false)
    {
        $i = 0;
        $buffer = '';

        $buffer .= $this->strwritestring($topic, $i);

        if ($qos) {
            $id = $this->msgid++;
            $buffer .= chr($id >> 8);
            $i++;
            $buffer .= chr($id % 256);
            $i++;
        }

        $buffer .= $content;
        $i += strlen($content);

        $head = ' ';
        $cmd = 0x30;
        if ($qos) {
            $cmd += $qos << 1;
        }
        if (empty($retain) === false) {
            ++$cmd;
        }

        $head[0] = chr($cmd);
        $head .= $this->setmsglength($i);

        return $this->_fwrite($head . $buffer);
    }

    /**
     * Writes a string to the socket
     *
     * @param $buffer
     *
     * @return bool|int
     */
    protected function _fwrite($buffer)
    {
        $buffer_length = strlen($buffer);
        $deadline = microtime(true) + $this->packetTimeout;
        for ($written = 0; $written < $buffer_length;) {
            if (!is_resource($this->socket) || microtime(true) >= $deadline) return $this->failRead('MQTT write timeout or closed connection');
            $read = $except = null;
            $write = array($this->socket);
            $wait = $deadline - microtime(true);
            $seconds = (int)max(0, $wait);
            $ready = stream_select($read, $write, $except, $seconds, max(0, (int)(($wait - $seconds) * 1000000)));
            if ($ready === false) return $this->failRead('Cannot wait for MQTT socket write');
            if ($ready === 0) continue;
            $fwrite = fwrite($this->socket, substr($buffer, $written, 8192));
            if ($fwrite === false || $fwrite === 0) return $this->failRead('MQTT socket write failed or made no progress');
            $written += $fwrite;
        }
        return $buffer_length;
    }

    /**
     * Processes a received topic
     *
     * @param $msg
     *
     * @retrun bool|string
     */
    public static function topicMatches(string $filter, string $topic): bool
    {
        $levels = explode('/', $filter);
        $parts = explode('/', $topic);
        if (str_starts_with($topic, '$') && in_array($levels[0], array('#', '+'), true)) return false;
        foreach ($levels as $i => $level) {
            if ($level === '#') return $i === count($levels) - 1;
            if (!array_key_exists($i, $parts) || ($level !== '+' && $level !== $parts[$i])) return false;
        }
        return count($levels) === count($parts);
    }

    public function message($msg)
    {
        $tlen = (ord($msg[0]) << 8) + ord($msg[1]);
        $topic = substr($msg, 2, $tlen);
        $msg = substr($msg, ($tlen + 2));
        $found = false;
        $dispatched = array();
        foreach ($this->topics as $key => $top) {
            if (self::topicMatches($key, $topic)) {
                $found = true;

                if ($top['function'] === '__direct_return_message__') {
                    return $msg;
                }

                if (is_callable($top['function'])) {
                    // Overlapping subscriptions must not duplicate one physical
                    // packet for the same callback. Identical later packets
                    // remain independent events (e.g. repeated motion).
                    if (in_array($top['function'], $dispatched, true)) continue;
                    $dispatched[] = $top['function'];
                    call_user_func($top['function'], $topic, $msg);
                } else {
                    $this->_errorMessage('Message received on topic ' . $topic . ' but function is not callable.');
                }
            }
        }

        if ($found === false) {
            $this->_debugMessage('msg received but no match in subscriptions');
        }

        return $found;
    }

    /**
     * The processing loop for an "always on" client
     * set true when you are doing other stuff in the loop good for
     * watching something else at the same time
     *
     * @param bool $loop
     *
     * @return bool | string
     */
    public function proc(bool $loop = true)
    {
        if (!is_resource($this->socket)) return false;
        // Waits wake immediately on data. A partial packet yields to the cycle
        // at least every 100ms, so heartbeats and outgoing commands still run.
        $iterationDeadline = microtime(true) + ($loop ? 0.1 : 0.0);
        do {
            $now = microtime(true);
            if ($this->packetDeadline > 0 && $now >= $this->packetDeadline) return $this->failRead('Incomplete MQTT packet timed out');
            $wanted = $this->incomingLength === null ? 1 : $this->incomingLength - $this->receivedBytes;
            $wait = max(0.0, $iterationDeadline - $now);
            if ($this->packetDeadline > 0) $wait = min($wait, max(0.0, $this->packetDeadline - $now));
            $chunk = $this->readAvailable($wanted, $wait);
            if ($chunk === false) return $this->failRead('MQTT connection closed during packet');
            if ($chunk === '') break;

            if ($this->incomingHeader === null) {
                $this->incomingHeader = ord($chunk);
                $type = $this->incomingHeader >> 4;
                if ($type < 1 || $type > 14) return $this->failRead('Invalid MQTT packet type');
                $this->packetDeadline = microtime(true) + $this->packetTimeout;
                continue;
            }
            if ($this->incomingLength === null) {
                $digit = ord($chunk);
                $this->lengthValue += ($digit & 127) * $this->lengthMultiplier;
                $this->lengthBytes++;
                if (($digit & 128) !== 0) {
                    if ($this->lengthBytes >= 4) return $this->failRead('Invalid MQTT Remaining Length');
                    $this->lengthMultiplier *= 128;
                    continue;
                }
                $this->incomingLength = $this->lengthValue;
                if ($this->incomingLength > $this->maxPacketBytes) {
                    // QoS 0 sensor traffic can be drained without allocating
                    // the body or reconnecting forever to a retained message.
                    if (($this->incomingHeader & 6) !== 0) return $this->failRead('Oversized MQTT packet requiring acknowledgement');
                    $this->discardPacket = true;
                    $this->_errorMessage('MQTT packet exceeds ' . $this->maxPacketBytes . ' bytes; discarding ' . $this->incomingLength . ' bytes');
                }
            } else {
                $this->receivedBytes += strlen($chunk);
                if (!$this->discardPacket) $this->incomingBody .= $chunk;
            }

            if ($this->incomingLength === $this->receivedBytes) {
                $header = $this->incomingHeader;
                $body = $this->incomingBody;
                $discarded = $this->discardPacket;
                $this->resetPacket();
                $this->lastReceivedAt = time();
                $result = true;
                if (!$discarded && ($header >> 4) === 3) {
                    if (strlen($body) < 2) return $this->failRead('Truncated MQTT PUBLISH');
                    $topicLength = (ord($body[0]) << 8) + ord($body[1]);
                    $qos = ($header >> 1) & 3;
                    if ($topicLength < 1 || strlen($body) < 2 + $topicLength + ($qos ? 2 : 0) || $qos > 1) return $this->failRead('Invalid or unsupported MQTT PUBLISH');
                    $packetId = $qos ? substr($body, 2 + $topicLength, 2) : '';
                    if ($qos) $body = substr($body, 0, 2 + $topicLength) . substr($body, 4 + $topicLength);
                    $return = $this->message($body);
                    if ($qos && $this->_fwrite(chr(0x40) . chr(2) . $packetId) === false) return false;
                    if (!is_bool($return)) $result = $return;
                }
                return $this->checkKeepalive() ? $result : false;
            }
        } while (!$loop || microtime(true) < $iterationDeadline);

        if ($this->packetDeadline > 0 && microtime(true) >= $this->packetDeadline) return $this->failRead('Incomplete MQTT packet timed out');
        return $this->checkKeepalive();
    }

    /**
     * Gets the length of a msg, (and increments $i)
     *
     * @param $msg
     * @param $i
     *
     * @return float|int
     */
    protected function getmsglength(&$msg, &$i)
    {
        $multiplier = 1;
        $value = 0;
        do {
            $digit = ord($msg[$i]);
            $value += ($digit & 127) * $multiplier;
            $multiplier *= 128;
            $i++;
        } while (($digit & 128) !== 0);

        return $value;
    }

    /**
     * @param $len
     *
     * @return string
     */
    protected function setmsglength($len): string
    {
        $string = '';
        do {
            $digit = $len % 128;
            $len >>= 7;
            // if there are more digits to encode, set the top bit of this digit
            if ($len > 0) {
                $digit |= 0x80;
            }
            $string .= chr($digit);
        } while ($len > 0);
        return $string;
    }

    /**
     * @param $str
     * @param $i
     *
     * @return string
     */
    protected function strwritestring($str, &$i): string
    {
        $len = strlen($str);
        $msb = $len >> 8;
        $lsb = $len % 256;
        $ret = chr($msb);
        $ret .= chr($lsb);
        $ret .= $str;
        $i += ($len + 2);
        return $ret;
    }

    /**
     * Prints a sting out character by character
     *
     * @param $string
     */
    public function printstr($string)
    {
        $strlen = strlen($string);
        for ($j = 0; $j < $strlen; $j++) {
            $num = ord($string[$j]);
            if ($num > 31) {
                $chr = $string[$j];
            } else {
                $chr = ' ';
            }
            printf("%4d: %08b : 0x%02x : %s \n", $j, $num, $num, $chr);
        }
    }

    /**
     * @param string $message
     */
    protected function _debugMessage(string $message)
    {
        if ($this->debug === true) {
            echo date('r: ') . $message . PHP_EOL;
        }
    }

    /**
     * @param string $message
     */
    protected function _errorMessage(string $message)
    {
        if (function_exists('DebMes')) {
            \DebMes($message, 'mqtt_error');
        } else {
            error_log('MQTT: ' . $message);
        }
    }
}
