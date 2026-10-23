<?php

declare(strict_types=1);

namespace MajorDoMo\Modules\AiAssistant;

use RuntimeException;

/**
 * Безопасная ошибка внешнего сервиса без токена, тела запроса и ответа.
 *
 * Изменения:
 * 2026-10-23 — машинный код и русское сообщение для диагностики в админке.
 */
final class ProviderFailure extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function status(int $status): self
    {
        return match (true) {
            $status >= 300 && $status < 400 => new self('redirect', 'API вернул перенаправление. Укажите конечный адрес API в настройках.'),
            $status === 401 || $status === 403 => new self('authentication', 'API отклонил токен или доступ к модели. Проверьте токен и права.'),
            $status === 404 => new self('not_found', 'API или модель не найдены. Проверьте базовый URL и идентификатор модели.'),
            $status === 429 => new self('rate_limit', 'Провайдер ограничил запросы или исчерпал квоту. Проверьте лимиты и повторите позже.'),
            $status === 408 || $status === 504 => new self('timeout', 'Провайдер не ответил вовремя. Проверьте его состояние и таймаут.'),
            $status >= 500 => new self('upstream', 'Ошибка сервера провайдера. Повторите запрос позже.'),
            default => new self('request_rejected', 'API отклонил запрос. Проверьте протокол и возможности модели.'),
        };
    }
}
