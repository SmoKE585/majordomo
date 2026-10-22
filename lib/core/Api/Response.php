<?php

namespace MajorDoMo\Core\Api;

final class Response
{
    public function __construct(public readonly string $requestId, private float $started) {}

    public function success(mixed $data, bool $legacy = false, ?Request $request = null): array
    {
        if ($legacy) {
            $data = (array)$data;
            $data['request'] = array('url' => '/api/' . implode('/', $request?->segments ?? array()), 'params' => $request?->params() ?? array());
            foreach (array('token', 'api_key', 'authorization', 'password') as $key) unset($data['request']['params'][$key]);
            return $data + array('passed' => round(microtime(true) - $this->started, 4), 'timestamp' => time());
        }
        return array('ok' => true, 'data' => $data, 'meta' => $this->meta());
    }

    public function failure(ApiException $error, bool $legacy = false): array
    {
        if ($legacy) return array('result' => false, 'error' => $error->getMessage(), 'error_code' => $error->errorCode, 'timestamp' => time(), 'request_id' => $this->requestId);
        return array('ok' => false, 'error' => array('code' => $error->errorCode, 'message' => $error->getMessage(), 'details' => $error->details ?: new \stdClass()), 'meta' => $this->meta());
    }

    public function emit(mixed $payload, int $status = 200, array $headers = array()): void
    {
        try { $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); }
        catch (\JsonException $error) {
            $status = 500;
            $json = json_encode($this->failure(new ApiException(500, 'internal_error', 'Результат операции не может быть представлен в JSON.')), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        http_response_code($status);
        header_remove('Location'); header_remove('Access-Control-Allow-Origin');
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header('X-Request-ID: ' . $this->requestId);
        foreach ($headers as $name => $value) header($name . ': ' . $value);
        echo $json;
    }

    private function meta(): array { return array('request_id' => $this->requestId, 'timestamp' => time(), 'duration_ms' => round((microtime(true) - $this->started) * 1000, 2)); }
}
