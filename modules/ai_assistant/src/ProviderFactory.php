<?php

declare(strict_types=1);

namespace MajorDoMo\Modules\AiAssistant;

use Closure;
use NeuronAI\HttpClient\Curl\CurlHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\StoppableHttpClient;
use NeuronAI\Providers\OpenAILike;
use NeuronAI\RAG\Embeddings\OpenAILikeEmbeddings;

/**
 * Создаёт провайдеры Neuron из проверенных серверных настроек.
 *
 * Изменения:
 * 2026-10-23 — подмена HTTP-транспорта для изолированных тестов и локальных шлюзов.
 * 2026-10-23 — остановка потокового ответа без разрыва соединения.
 * 2026-10-23 — Chat Completions и эмбеддинги с явным таймаутом, без редиректов.
 */
final class ProviderFactory
{
    public function __construct(private ModelCatalog $catalog, private ?HttpClientInterface $http = null) {}

    public function chat(int $modelId, bool $tools = false, ?Closure $shouldStop = null): OpenAILike
    {
        $connection = $this->catalog->connection($modelId, $tools ? 'tools' : 'chat');
        $model = $connection['model'];
        $provider = $connection['provider'];
        $parameters = $model['PARAMETERS'] + array('max_tokens' => (int)$model['MAX_OUTPUT']);
        $client = $this->client($provider, $connection['token'] === '');
        // StoppableHttpClient оставляет уже полученный текст полноценным ответом
        // с причиной stopped, а не обрывает поток ошибкой.
        if ($shouldStop !== null) $client = new StoppableHttpClient($client, $shouldStop);
        return new OpenAILike($provider['BASE_URL'], $connection['token'], $model['MODEL'], $parameters, false, $client);
    }

    public function embeddings(int $modelId): OpenAILikeEmbeddings
    {
        $connection = $this->catalog->connection($modelId, 'embeddings');
        return new OpenAILikeEmbeddings($connection['provider']['BASE_URL'], $connection['token'],
            $connection['model']['MODEL'], null, $this->client($connection['provider'], $connection['token'] === ''));
    }

    private function client(array $provider, bool $withoutAuthorization): ProviderClient
    {
        $timeout = (int)$provider['TIMEOUT'];
        if ($this->http !== null) {
            return new ProviderClient($this->http, $provider['BASE_URL'], $withoutAuthorization);
        }
        $client = new CurlHttpClient(timeout: $timeout, connectTimeout: min(10, $timeout),
            curlOptions: array(CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0));
        $client->onResponse(static function (\NeuronAI\HttpClient\HttpResponse $response): void {
            if (!$response->isSuccessful()) throw ProviderFailure::status($response->statusCode);
        });
        return new ProviderClient($client, $provider['BASE_URL'], $withoutAuthorization);
    }
}
