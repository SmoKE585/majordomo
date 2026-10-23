<?php

declare(strict_types=1);

namespace MajorDoMo\Modules\AiAssistant;

use JsonException;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\Curl\CurlHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;

/**
 * Проверяет отдельный Qdrant через API; локальная установка сервиса не обязательна.
 *
 * Изменения:
 * 2026-10-23 — коллекции, точки и поиск для staging-индекса знаний.
 * 2026-10-23 — диагностика доступа без редиректов, передачи документов и утечки ключа.
 */
final class QdrantClient
{
    public function __construct(private ?HttpClientInterface $http = null) {}

    public function probe(array $connection): array
    {
        if (($connection['url'] ?? '') === '') {
            return self::unavailable('not_configured', 'Qdrant не подключён. Установите отдельный сервис Qdrant и укажите адрес его API в настройках базы знаний. Обычные агенты и текстовый поиск доступны без него.');
        }
        $url = Endpoint::normalize($connection['url']);
        $timeout = ModelCatalog::integer(array('TIMEOUT' => $connection['timeout']), 'TIMEOUT', 1, 30);
        $client = $this->http ?? new CurlHttpClient(timeout: $timeout, connectTimeout: min(5, $timeout),
            curlOptions: array(CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0));
        $headers = array('Accept' => 'application/json');
        if ($connection['token'] !== '') $headers['api-key'] = $connection['token'];
        $request = HttpRequest::get($url . '/collections', $headers);
        $request->timeout = $timeout;
        try {
            $response = $client->request($request);
        } catch (HttpException $failure) {
            $safe = ProviderClient::safeFailure($failure);
            return self::failure($safe->reason);
        }
        if (!$response->isSuccessful()) return self::failure(ProviderFailure::status($response->statusCode)->reason);
        if (strlen($response->body) > 1024 * 1024) return self::failure('invalid_response');
        try {
            $data = json_decode($response->body, false, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $failure) {
            return self::failure('invalid_response');
        }
        $collections = $data->result->collections ?? null;
        if (($data->status ?? null) !== 'ok' || !is_array($collections) || !array_is_list($collections)) return self::failure('invalid_response');
        foreach ($collections as $collection) {
            if (!is_object($collection) || !is_string($collection->name ?? null) || $collection->name === '') return self::failure('invalid_response');
        }
        // Чужие имена коллекций общей установки не нужны интерфейсу агента.
        return array('available' => true, 'reason' => 'ready', 'hint' => 'Qdrant доступен.');
    }

    private static function failure(string $reason): array
    {
        $hints = array(
            'authentication' => 'Qdrant отклонил ключ. Проверьте API-ключ и права доступа к коллекциям.',
            'timeout' => 'Qdrant не ответил вовремя. Проверьте доступность сервиса и таймаут.',
            'redirect' => 'API Qdrant вернул перенаправление. Укажите конечный адрес API; перенаправления запрещены.',
            'invalid_response' => 'Ответ не соответствует API Qdrant. Проверьте адрес сервиса и настройки прокси.',
            'network' => 'Не удалось соединиться с Qdrant. Проверьте, что сервис запущен, а адрес и порт API доступны.',
        );
        return self::unavailable($reason, $hints[$reason] ?? 'Qdrant отклонил запрос. Проверьте адрес API, права ключа и состояние сервиса.');
    }

    /** Создать коллекцию под конкретную модель эмбеддингов и её размерность. */
    public function createCollection(array $connection, string $collection, int $dimension): void
    {
        if ($dimension < 1 || $dimension > 65536) throw new ProviderFailure('invalid_vector', 'Модель вернула вектор недопустимой размерности.');
        $this->send($connection, HttpRequest::put($this->uri($connection, '/collections/' . rawurlencode($collection)),
            array('vectors' => array('size' => $dimension, 'distance' => 'Cosine'))), 16 * 1024 * 1024);
    }

    /** @param list<array{id:string,vector:list<float>,payload:array}> $points */
    public function upsert(array $connection, string $collection, array $points): void
    {
        if ($points === array()) return;
        $this->send($connection, HttpRequest::put(
            $this->uri($connection, '/collections/' . rawurlencode($collection) . '/points?wait=true'),
            array('points' => $points)), 16 * 1024 * 1024);
    }

    /** @return list<array{score:float,payload:array}> */
    public function search(array $connection, string $collection, array $vector, int $limit, string $installKey, array $sources): array
    {
        $must = array(array('key' => 'install', 'match' => array('value' => $installKey)));
        $audience = array();
        if (in_array('core', $sources, true)) $audience[] = 'core';
        if (in_array('modules', $sources, true)) $audience[] = 'modules';
        if ($audience !== array()) $must[] = array('key' => 'audience', 'match' => array('any' => $audience));
        $data = $this->send($connection, HttpRequest::post(
            $this->uri($connection, '/collections/' . rawurlencode($collection) . '/points/search'),
            array('vector' => array_values($vector), 'limit' => max(1, min(20, $limit)),
                'with_payload' => true, 'filter' => array('must' => $must))), 4 * 1024 * 1024);
        $result = $data['result'] ?? null;
        if (!is_array($result) || !array_is_list($result)) throw new ProviderFailure('invalid_response', 'Qdrant вернул результат поиска неизвестного формата.');
        $items = array();
        foreach ($result as $row) {
            $payload = is_array($row['payload'] ?? null) ? $row['payload'] : array();
            if (($payload['install'] ?? '') !== $installKey) continue;
            $items[] = array('score' => (float)($row['score'] ?? 0), 'payload' => $payload);
        }
        return $items;
    }

    public function deleteCollection(array $connection, string $collection): void
    {
        try {
            $this->send($connection, HttpRequest::delete($this->uri($connection, '/collections/' . rawurlencode($collection))), 1024 * 1024);
        } catch (ProviderFailure $failure) {
            // Удаление старой коллекции не должно отменять уже готовый новый индекс.
            if ($failure->reason !== 'not_found') throw $failure;
        }
    }

    public function collectionInfo(array $connection, string $collection): array
    {
        $data = $this->send($connection, HttpRequest::get($this->uri($connection, '/collections/' . rawurlencode($collection))), 1024 * 1024);
        $result = $data['result'] ?? null;
        if (!is_array($result)) throw new ProviderFailure('invalid_response', 'Qdrant вернул сведения о коллекции неизвестного формата.');
        return $result;
    }

    private function uri(array $connection, string $path): string
    {
        return Endpoint::normalize((string)$connection['url']) . $path;
    }

    /** @return array<string,mixed> */
    private function send(array $connection, HttpRequest $request, int $maximum): array
    {
        if (($connection['url'] ?? '') === '') throw new ProviderFailure('not_configured', 'Qdrant не подключён.');
        $timeout = ModelCatalog::integer(array('TIMEOUT' => $connection['timeout'] ?? 5), 'TIMEOUT', 1, 30);
        $client = $this->http ?? new CurlHttpClient(timeout: $timeout, connectTimeout: min(5, $timeout),
            curlOptions: array(CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0));
        $request = $request->withHeaders(array('Accept' => 'application/json', 'Content-Type' => 'application/json'));
        if (($connection['token'] ?? '') !== '') $request = $request->withHeaders(array('api-key' => (string)$connection['token']));
        $request->timeout = $timeout;
        try {
            $response = $client->request($request);
        } catch (HttpException $failure) {
            $safe = ProviderClient::safeFailure($failure);
            throw new ProviderFailure($safe->reason, $safe->getMessage());
        }
        if (!$response->isSuccessful()) {
            $safe = ProviderFailure::status($response->statusCode);
            throw new ProviderFailure($safe->reason, $safe->getMessage());
        }
        if (strlen($response->body) > $maximum) throw new ProviderFailure('invalid_response', 'Ответ Qdrant превышает допустимый размер.');
        try {
            $data = json_decode($response->body, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ProviderFailure('invalid_response', 'Qdrant вернул некорректный JSON.');
        }
        if (!is_array($data) || ($data['status'] ?? null) !== 'ok') {
            throw new ProviderFailure('invalid_response', 'Qdrant вернул ответ неизвестного формата.');
        }
        return $data;
    }

    private static function unavailable(string $reason, string $hint): array
    {
        return array('available' => false, 'reason' => $reason, 'hint' => $hint . ' Создание и запуск агентов с RAG недоступны.');
    }
}
