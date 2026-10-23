<?php

namespace MajorDoMo\Core;


/**
 * Сетевое сопоставление и определение адреса клиента за reverse proxy.
 *
 * Изменения: 2026-10-22 — loopback всегда считается доверенным прокси, чтобы
 * схема «nginx → 127.0.0.1:80» работала без дополнительной настройки.
 * Изменения: 2026-10-23 — clientIp() честно возвращает пустую строку, когда
 * виден только прокси: такой адрес нельзя превращать в общий ключ блокировки
 * (из-за него блокировались сразу все клиенты). Прямой loopback-запрос без
 * X-Forwarded-For остаётся собой, а IPv4-в-IPv6 приводится к IPv4.
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

    /**
     * Адрес исходного клиента для журнала, блокировок и аудита.
     *
     * Пустая строка означает «источник неизвестен»: запрос пришёл от
     * доверенного прокси, но тот не передал пригодный X-Forwarded-For.
     * Это не ошибка вызывающего кода, а сигнал «адрес не определить»:
     * такой запрос нельзя приравнивать к общему адресу всех остальных.
     */
    public function clientIp($peer, $forwarded = '', $trusted_proxies = '')
    {
        $trusted_proxies = self::trustedProxies($trusted_proxies);
        $peer = self::normalizeIp((string)$peer);
        if ($peer === '') return '';
        // Заголовок подделывается одним запросом, поэтому адрес соединения
        // важнее: X-Forwarded-For читаем только от доверенного прокси.
        if (!$this->inNetworks($peer, $trusted_proxies)) return $peer;
        $forwarded = trim((string)$forwarded);
        // Прямой loopback-запрос (локальный сервер разработки, CLI-проверка)
        // сам является клиентом. Внешний доверенный прокси без заголовка —
        // неоднозначен, и придумывать за него адрес нельзя.
        if ($forwarded === '') return $this->inNetworks($peer, self::LOOPBACK_PROXIES) ? $peer : '';
        if (strlen($forwarded) > 4096) return '';
        foreach (array_reverse(explode(',', $forwarded)) as $candidate) {
            $candidate = self::normalizeIp($candidate);
            // Нечитаемое звено делает недостоверной всю цепочку.
            if ($candidate === '') return '';
            if (!$this->inNetworks($candidate, $trusted_proxies)) return $candidate;
        }
        // В цепочке только прокси: адреса клиента в запросе нет.
        return '';
    }

    /** Приводит IPv4-в-IPv6 к IPv4; мусор превращает в пустую строку. */
    private static function normalizeIp(string $ip): string
    {
        $ip = trim($ip);
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) return '';
        $lower = strtolower($ip);
        if (str_starts_with($lower, '::ffff:') && filter_var(substr($ip, 7), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return substr($ip, 7);
        }
        return $ip;
    }
}
