<?php

declare(strict_types=1);

namespace MajorDoMo\Modules\AiAssistant;

use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\HttpClient\StreamInterface;

/**
 * Граница HTTP провайдера: запрет перенаправлений и безопасные ошибки.
 *
 * Изменения:
 * 2026-10-23 — проверка адреса и структуры ответа без утечки содержимого ошибок.
 */
final class ProviderClient implements HttpClientInterface
{
    public function __construct(private HttpClientInterface $client, private string $baseUrl, private bool $withoutAuthorization = false) {}

    public function request(HttpRequest $request): HttpResponse
    {
        $request = $this->prepare($request);
        try {
            $response = $this->client->request($request);
        } catch (HttpException $failure) {
            throw self::safeFailure($failure);
        }
        if (!$response->isSuccessful()) throw ProviderFailure::status($response->statusCode);
        if (strlen($response->body) > 8 * 1024 * 1024) throw new ProviderFailure('response_too_large', 'Ответ API превышает допустимый размер.');
        try {
            $data = $response->json();
        } catch (\JsonException $failure) {
            throw new ProviderFailure('invalid_response', 'API вернул некорректный JSON. Проверьте формат и адрес API.');
        }
        if (str_ends_with($request->uri, '/chat/completions')) {
            $message = $data['choices'][0]['message'] ?? null;
            if (!is_array($message) || !array_key_exists('content', $message)
                || (!is_string($message['content']) && $message['content'] !== null)
                || !is_string($data['choices'][0]['finish_reason'] ?? null)) {
                throw new ProviderFailure('invalid_response', 'Ответ API не соответствует Chat Completions.');
            }
            if (isset($message['tool_calls'])) {
                if (!is_array($message['tool_calls']) || !array_is_list($message['tool_calls'])) {
                    throw new ProviderFailure('invalid_response', 'API вернул некорректный список вызовов Tools.');
                }
                foreach ($message['tool_calls'] as $call) {
                    if (!is_array($call) || !is_string($call['id'] ?? null)
                        || !is_string($call['function']['name'] ?? null) || !is_string($call['function']['arguments'] ?? null)) {
                        throw new ProviderFailure('invalid_response', 'API вернул некорректный вызов Tools.');
                    }
                }
            }
        } elseif (str_ends_with($request->uri, '/embeddings')) {
            if (!is_array($data['data'] ?? null) || !array_is_list($data['data']) || $data['data'] === array()) {
                throw new ProviderFailure('invalid_response', 'API не вернул эмбеддинги.');
            }
            foreach ($data['data'] as $item) {
                $vector = is_array($item) ? ($item['embedding'] ?? null) : null;
                if (!is_array($vector) || !array_is_list($vector) || $vector === array()) {
                    throw new ProviderFailure('invalid_response', 'API вернул некорректный вектор.');
                }
                foreach ($vector as $value) {
                    if ((!is_int($value) && !is_float($value)) || !is_finite((float)$value)) {
                        throw new ProviderFailure('invalid_response', 'API вернул некорректные значения вектора.');
                    }
                }
            }
        }
        return $response;
    }

    public function stream(HttpRequest $request): StreamInterface
    {
        $request = $this->prepare($request);
        try {
            return $this->client->stream($request);
        } catch (HttpException $failure) {
            throw self::safeFailure($failure);
        }
    }

    private function prepare(HttpRequest $request): HttpRequest
    {
        $base = Endpoint::normalize($this->baseUrl) . '/';
        if (!str_starts_with($request->uri, $base)) throw new ProviderFailure('invalid_endpoint', 'Запрос вышел за пределы настроенного API.');
        if ($this->withoutAuthorization) {
            $request = clone $request;
            foreach (array_keys($request->headers) as $name) {
                if (strtolower($name) === 'authorization') unset($request->headers[$name]);
            }
        }
        return $request;
    }

    public static function safeFailure(HttpException $failure): ProviderFailure
    {
        if ($failure->response !== null) return ProviderFailure::status($failure->response->statusCode);
        if (str_contains($failure->getMessage(), 'refused a redirect')) return ProviderFailure::status(302);
        if (str_contains(strtolower($failure->getMessage()), 'timed out') || str_contains(strtolower($failure->getMessage()), 'timeout')) {
            return new ProviderFailure('timeout', 'API не ответил вовремя. Проверьте соединение и таймаут.');
        }
        // Исходное исключение хранит заголовок Authorization и полный ответ.
        // Не прикладываем его как previous, иначе эти данные попадут в системный журнал.
        return new ProviderFailure('network', 'Не удалось соединиться с API. Проверьте адрес и доступность сервера.');
    }
}
