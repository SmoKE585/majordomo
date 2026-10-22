<?php

namespace MajorDoMo\Core\Api;

final class Request
{
    public readonly bool $legacy;
    public readonly array $segments;

    public function __construct(public readonly string $method, string $uri, public readonly array $query = array(), public readonly array $body = array(), string $root = '/')
    {
        $path = (string)parse_url($uri, PHP_URL_PATH);
        $prefix = rtrim($root, '/') . '/';
        if (!str_starts_with($path, $prefix)) throw new ApiException(404, 'not_found', 'Маршрут не найден.');
        $path = substr($path, strlen($prefix));
        if (!preg_match('#^api(?:\.php)?(?:/|$)#D', $path)) throw new ApiException(404, 'not_found', 'Маршрут не найден.');
        $path = preg_replace('#^api(?:\.php)?/?#', '', $path);
        if (strlen($path) > 2048 || preg_match('/%(?![a-f0-9]{2})/i', $path)) throw new ApiException(400, 'invalid_path', 'Некорректный путь.');
        $segments = $path === '' ? array() : explode('/', rtrim($path, '/'));
        $this->legacy = ($segments[0] ?? '') !== 'v1';
        if (!$this->legacy) array_shift($segments);
        $this->segments = array_map('rawurldecode', $segments);
        foreach ($this->segments as $segment) if ($segment === '' || preg_match('/[\x00-\x1f\x7f]/', $segment)) throw new ApiException(400, 'invalid_path', 'Некорректный путь.');
    }

    public static function decode(string $raw, string $contentType, bool $legacy, array $form = array()): array
    {
        if (strlen($raw) > 1048576) throw new ApiException(413, 'payload_too_large', 'Максимальный размер запроса — 1 МиБ.');
        $type = strtolower(trim(explode(';', $contentType)[0]));
        if ($legacy && in_array($type, array('application/x-www-form-urlencoded', 'multipart/form-data'), true)) return $form;
        if ($raw === '') return array();
        if (!$legacy && $type !== 'application/json') throw new ApiException(415, 'unsupported_media_type', 'Используйте Content-Type: application/json.');
        try { $value = json_decode($raw, false, 64, JSON_THROW_ON_ERROR); }
        catch (\JsonException $error) { throw new ApiException(400, 'invalid_json', 'Некорректный JSON.'); }
        if (!$value instanceof \stdClass && !($legacy && is_array($value))) throw new ApiException(422, 'validation_failed', 'Тело запроса должно быть JSON-объектом.');
        return json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    }

    public function params(): array { return array_replace($this->query, $this->body); }
}
