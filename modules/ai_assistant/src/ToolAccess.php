<?php

declare(strict_types=1);

namespace MajorDoMo\Modules\AiAssistant;

use Closure;
use MajorDoMo\Core\Auth\Authentication;
use MajorDoMo\Core\Core;
use RuntimeException;

/**
 * Привязанные сервером права и бюджет одного запуска, независимые от текста модели.
 *
 * Изменения:
 * 2026-10-23 — неограниченные вызовы при нулевом бюджете; отзыв прав и таймаут сохранены.
 * 2026-10-23 — повторная проверка сессии, агента, модуля и отмены на каждом Tool.
 */
final class ToolAccess
{
    private int $calls = 0;
    private float $started;
    private int $maximumCalls;
    private int $timeout;

    public function __construct(
        private ModuleState $state,
        private AgentCatalog $agents,
        private int $agentId,
        private int $userId,
        private ?Closure $currentUser = null,
        private ?Closure $cancelled = null,
        private ?Closure $clock = null
    ) {
        $this->started = $this->now();
        $agent = $this->snapshot();
        $this->maximumCalls = (int)$agent['MAX_STEPS'];
        $this->timeout = (int)$agent['TIMEOUT'];
    }

    public function snapshot(): array
    {
        $currentId = $this->currentUser !== null ? ($this->currentUser)() : $this->currentAdministrator();
        if ($this->userId < 1 || !is_int($currentId) || $currentId !== $this->userId) {
            throw new RuntimeException('Сессия администратора изменилась или завершена. Вызов инструмента запрещён.');
        }
        $this->state->requireEnabled();
        if ($this->cancelled !== null && ($this->cancelled)()) throw new RuntimeException('Запрос отменён.');
        $agent = $this->agents->get($this->agentId);
        if ((int)$agent['ENABLED'] !== 1) throw new RuntimeException('Агент выключен. Вызов инструмента запрещён.');
        return $agent;
    }

    /** Возвращает свежие настройки; бюджет нельзя увеличить правкой агента во время запуска. */
    public function begin(string $tool): array
    {
        $agent = $this->snapshot();
        if (!in_array($tool, AgentCatalog::TOOLS, true) || !in_array($tool, $agent['TOOLS'], true)) {
            throw new RuntimeException('У агента нет разрешения на этот инструмент.');
        }
        $configured = (int)$agent['MAX_STEPS'];
        $maximum = $this->maximumCalls === 0 ? $configured
            : ($configured === 0 ? $this->maximumCalls : min($this->maximumCalls, $configured));
        if ($maximum > 0 && $this->calls >= $maximum) throw new RuntimeException('Исчерпан лимит вызовов инструментов.');
        $this->checkTime($agent);
        $this->calls++;
        return $agent;
    }

    /** Результат не выдаётся, если отмена или отзыв права произошли во время чтения. */
    public function finish(string $tool): array
    {
        $agent = $this->snapshot();
        if (!in_array($tool, $agent['TOOLS'], true)) throw new RuntimeException('Разрешение на инструмент отозвано.');
        $this->checkTime($agent);
        return $agent;
    }

    public function calls(): int { return $this->calls; }

    private function currentAdministrator(): int
    {
        $session = Core::session();
        if ($session->user() === null) return 0;
        // Проверяется текущая роль и stamp, а не только сохранённый ID сессии.
        return (int)((new Authentication($session, Core::users()))->current()['id'] ?? 0);
    }

    private function checkTime(array $agent): void
    {
        if ($this->now() - $this->started >= min($this->timeout, (int)$agent['TIMEOUT'])) throw new RuntimeException('Истёк лимит времени запроса.');
    }

    private function now(): float { return $this->clock !== null ? (float)($this->clock)() : hrtime(true) / 1e9; }
}
