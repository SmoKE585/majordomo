<?php

namespace MajorDoMo\Core;


/**
 * Сетевое сопоставление и определение адреса клиента за reverse proxy.
 *
 * Изменения: 2026-10-22 — loopback всегда считается доверенным прокси, чтобы
 * схема «nginx → 127.0.0.1:80» работала без дополнительной настройки.
 */
final class NetworkAccess
{
    /** Локальный reverse proxy по определению не может быть внешним клиентом. */
    public const LOOPBACK_PROXIES = '127.0.0.1/32,::1/128';

    /** Добавляет loopback к настроенным прокси, не заменяя пользовательский список. */
    public static function trustedProxies($configured = ''): string
    {
        $configured = trim((string)$configured);
        return $configured === ''
            ? self::LOOPBACK_PROXIES
            : self::LOOPBACK_PROXIES . ',' . $configured;
    }

    public function matches($ip, $network)
    {
        $ip = trim((string)$ip);
        $network = trim((string)$network);
        if (filter_var($ip, FILTER_VALIDATE_IP) === false || $network === '') return false;
        if (strpos($network, '*') !== false) {
            return (bool)preg_match('/^' . str_replace('\\*', '\\d{1,3}', preg_quote($network, '/')) . '$/D', $ip);
        }
        $parts = explode('/', $network, 2);
        $address = inet_pton($parts[0]);
        $packed = inet_pton($ip);
        if ($address === false || $packed === false || strlen($address) !== strlen($packed)) return false;
        $bits = isset($parts[1]) && ctype_digit($parts[1]) ? (int)$parts[1] : strlen($packed) * 8;
        if (isset($parts[1]) && !ctype_digit($parts[1])) return false;
        if ($bits < 0 || $bits > strlen($packed) * 8) return false;
        $bytes = intdiv($bits, 8);
        if (substr($address, 0, $bytes) !== substr($packed, 0, $bytes)) return false;
        return $bits % 8 === 0 || ((ord($address[$bytes]) ^ ord($packed[$bytes])) & (255 << (8 - $bits % 8))) === 0;
    }

    public function inNetworks($ip, $networks)
    {
        foreach (preg_split('/[\s,]+/', trim((string)$networks), -1, PREG_SPLIT_NO_EMPTY) as $network) {
            if ($this->matches($ip, $network)) return true;
        }
        return false;
    }

    public function clientIp($peer, $forwarded = '', $trusted_proxies = '')
    {
        $trusted_proxies = self::trustedProxies($trusted_proxies);
        if (!$this->inNetworks($peer, $trusted_proxies)) return $peer;
        $chain = array_reverse(explode(',', (string)$forwarded));
        foreach ($chain as $candidate) {
            $candidate = trim($candidate);
            if (filter_var($candidate, FILTER_VALIDATE_IP) === false) return '';

            if (!$this->inNetworks($candidate, $trusted_proxies)) return $candidate;
        }
        return $peer;
    }
}
