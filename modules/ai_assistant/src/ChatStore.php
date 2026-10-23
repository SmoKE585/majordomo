<?php

declare(strict_types=1);

namespace MajorDoMo\Modules\AiAssistant;

use InvalidArgumentException;
use MajorDoMo\Core\Database;
use RuntimeException;

/**
 * Диалоги, сообщения и запуски; владелец всегда проверяется на сервере.
 *
 * Изменения:
 * 2026-10-23 — пульс запуска, восстановление состояния и немедленная отмена.
 * 2026-10-23 — история чата, состояния генерации и отмена без доверия браузеру.
 */
final class ChatStore
{
    public const ROLES = array('user', 'assistant', 'system');
    public const CONTEXT_TYPES = array('chat', 'editor');
    private const RUN_TIMEOUT = 90;
    private const MAX_MESSAGE = 200000;
    private const MAX_PROMPT = 16000;
    private const MAX_CONTEXT = 65536;

    public function __construct(private Database $db, private ?int $now = null) {}

    public function createDialog(int $userId, ?int $agentId, string $title, string $contextType, array $context): int
    {
        $this->owner($userId);
        if (!in_array($contextType, self::CONTEXT_TYPES, true)) throw new InvalidArgumentException('Неизвестный тип диалога.');
        $title = self::title($title);
        $contextJson = self::json($context, self::MAX_CONTEXT);
        $record = array(
            'USER_ID' => $userId,
            'AGENT_ID' => $agentId !== null && $agentId > 0 ? $agentId : null,
            'TITLE' => $title,
            'CONTEXT_TYPE' => $contextType,
            'CONTEXT' => $contextJson,
            'CREATED_AT' => $this->now(),
            'UPDATED_AT' => $this->now(),
            'DELETED' => 0,
        );
        // Необязательный агент оставляем SQL-NULL, а не пустую строку.
        if ($record['AGENT_ID'] === null) unset($record['AGENT_ID']);
        $id = $this->db->insert('ai_assistant_dialogs', $record);
        if ($id < 1) throw new RuntimeException('Не удалось создать диалог.');
        return $id;
    }

    public function listDialogs(int $userId, int $limit = 30): array
    {
        $this->owner($userId);
        $limit = max(1, min($limit, 100));
        return $this->db->select('SELECT d.ID,d.TITLE,d.CONTEXT_TYPE,d.AGENT_ID,d.CREATED_AT,d.UPDATED_AT,'
            . '(SELECT COUNT(*) FROM ai_assistant_messages m WHERE m.DIALOG_ID=d.ID) AS MESSAGES '
            . 'FROM ai_assistant_dialogs d WHERE d.USER_ID=' . $userId . ' AND d.DELETED=0 '
            . 'ORDER BY d.UPDATED_AT DESC,d.ID DESC LIMIT ' . $limit);
    }

    public function dialog(int $id, int $userId): array
    {
        $this->owner($userId);
        $record = $this->db->one('SELECT * FROM ai_assistant_dialogs WHERE ID=' . $id
            . ' AND USER_ID=' . $userId . ' AND DELETED=0');
        if ($record === null) throw new InvalidArgumentException('Диалог не найден.');
        $record['CONTEXT'] = json_decode($record['CONTEXT'], true, 64, JSON_THROW_ON_ERROR);
        return $record;
    }

    public function renameDialog(int $id, int $userId, string $title): void
    {
        $this->dialog($id, $userId);
        $this->db->requireExec('UPDATE ai_assistant_dialogs SET TITLE=\'' . $this->db->escape(self::title($title))
            . '\',UPDATED_AT=' . $this->now() . ' WHERE ID=' . $id . ' AND USER_ID=' . $userId);
    }

    public function setAgent(int $id, int $userId, int $agentId): void
    {
        $this->dialog($id, $userId);
        if ($agentId < 1) throw new InvalidArgumentException('Некорректный агент.');
        $this->db->requireExec('UPDATE ai_assistant_dialogs SET AGENT_ID=' . $agentId
            . ',UPDATED_AT=' . $this->now() . ' WHERE ID=' . $id . ' AND USER_ID=' . $userId);
    }

    /** Свежий черновик редактора заменяет прежний контекст того же диалога. */
    public function updateContext(int $id, int $userId, array $context): void
    {
        $this->dialog($id, $userId);
        $this->db->requireExec('UPDATE ai_assistant_dialogs SET CONTEXT=\''
            . $this->db->escape(self::json(self::context($context), self::MAX_CONTEXT))
            . '\',UPDATED_AT=' . $this->now() . ' WHERE ID=' . $id . ' AND USER_ID=' . $userId);
    }

    /** Мягкое удаление сохраняет историю запусков и не ломает ссылки из журнала. */
    public function deleteDialog(int $id, int $userId): void
    {
        $this->dialog($id, $userId);
        $this->db->requireExec('UPDATE ai_assistant_dialogs SET DELETED=1,UPDATED_AT=' . $this->now()
            . ' WHERE ID=' . $id . ' AND USER_ID=' . $userId);
        $this->db->requireExec("UPDATE ai_assistant_runs SET CANCEL_REQUESTED=1,STATUS='cancelled',UPDATED_AT="
            . $this->now() . " WHERE DIALOG_ID=" . $id . " AND STATUS='running'");
    }

    /** @return list<array{id:int,role:string,content:string,meta:array,created:int}> */
    public function messages(int $dialogId, int $userId, int $afterId = 0, int $limit = 200, bool $latest = false): array
    {
        $this->dialog($dialogId, $userId);
        $limit = max(1, min($limit, 500));
        $rows = $this->db->select('SELECT ID,ROLE,CONTENT,META,CREATED_AT FROM ai_assistant_messages WHERE DIALOG_ID='
            . $dialogId . ' AND ID>' . max(0, $afterId) . ' ORDER BY ID ' . ($latest ? 'DESC' : 'ASC') . ' LIMIT ' . $limit);
        if ($latest) $rows = array_reverse($rows);
        foreach ($rows as &$row) {
            $row['ID'] = (int)$row['ID'];
            $row['CREATED_AT'] = (int)$row['CREATED_AT'];
            $row['META'] = self::decodeMeta((string)$row['META']);
        }
        unset($row);
        return $rows;
    }

    public function appendMessage(int $dialogId, int $userId, string $role, string $content, array $meta = array()): int
    {
        $this->dialog($dialogId, $userId);
        if (!in_array($role, self::ROLES, true)) throw new InvalidArgumentException('Неизвестная роль сообщения.');
        if (!mb_check_encoding($content, 'UTF-8') || str_contains($content, "\0")) {
            throw new InvalidArgumentException('Сообщение должно быть текстом UTF-8.');
        }
        if (mb_strlen($content) > self::MAX_MESSAGE) throw new InvalidArgumentException('Сообщение слишком длинное.');
        $record = array(
            'DIALOG_ID' => $dialogId,
            'ROLE' => $role,
            'CONTENT' => $content,
            'META' => self::json(SensitiveData::deep($meta), self::MAX_CONTEXT),
            'CREATED_AT' => $this->now(),
        );
        $id = $this->db->insert('ai_assistant_messages', $record);
        if ($id < 1) throw new RuntimeException('Не удалось сохранить сообщение.');
        $this->db->requireExec('UPDATE ai_assistant_dialogs SET UPDATED_AT=' . $this->now() . ' WHERE ID=' . $dialogId);
        return $id;
    }

    public function beginRun(int $dialogId, int $userId, ?int $agentId, string $prompt, string $requestId = ''): string
    {
        $this->dialog($dialogId, $userId);
        if (!mb_check_encoding($prompt, 'UTF-8') || count(preg_split('/\R/u', $prompt) ?: array()) > 200
            || mb_strlen($prompt) < 1 || mb_strlen($prompt) > self::MAX_PROMPT) {
            throw new InvalidArgumentException('Запрос должен содержать от 1 до ' . self::MAX_PROMPT . ' символов UTF-8.');
        }
        // Зависший запуск не должен навсегда блокировать продолжение диалога.
        $this->db->requireExec("UPDATE ai_assistant_runs SET STATUS='error',ERROR='Прервано: истекло время ожидания',UPDATED_AT="
            . $this->now() . " WHERE DIALOG_ID=" . $dialogId . " AND STATUS='running' AND UPDATED_AT<"
            . ($this->now() - self::RUN_TIMEOUT));
        if ($this->db->one("SELECT ID FROM ai_assistant_runs WHERE DIALOG_ID=" . $dialogId
            . " AND STATUS='running' LIMIT 1") !== null) {
            throw new RuntimeException('В диалоге уже идёт генерация. Дождитесь ответа или остановите её.');
        }
        if ($requestId !== '' && !preg_match('/^[a-f0-9]{32}$/D', $requestId)) throw new InvalidArgumentException('Некорректный идентификатор запроса.');
        if ($requestId !== '' && $this->db->one("SELECT ID FROM ai_assistant_runs WHERE ID='" . $requestId . "'") !== null) {
            throw new InvalidArgumentException('Идентификатор запроса уже использован. Повторная отправка заблокирована.');
        }
        $id = $requestId !== '' ? $requestId : bin2hex(random_bytes(16));
        $record = array(
            'ID' => $id,
            'DIALOG_ID' => $dialogId,
            'USER_ID' => $userId,
            'AGENT_ID' => $agentId !== null && $agentId > 0 ? $agentId : null,
            'STATUS' => 'running',
            'CANCEL_REQUESTED' => 0,
            'PROMPT' => $prompt,
            'ERROR' => '',
            'CREATED_AT' => $this->now(),
            'UPDATED_AT' => $this->now(),
        );
        if ($record['AGENT_ID'] === null) unset($record['AGENT_ID']);
        $this->db->insert('ai_assistant_runs', $record);
        // Атомарное занятие диалога: два одновременных POST не должны оба
        // пройти проверку running перед вставкой своего запуска.
        $this->db->requireExec("UPDATE ai_assistant_dialogs SET LAST_RUN='" . $id . "' WHERE ID=" . $dialogId
            . ' AND USER_ID=' . $userId . " AND DELETED=0 AND (LAST_RUN='' OR NOT EXISTS (SELECT ID FROM ai_assistant_runs"
            . " WHERE ID=ai_assistant_dialogs.LAST_RUN AND STATUS='running'))");
        if ($this->db->affectedRows() !== 1) {
            $this->finishRun($id, 'error', 'Диалог занят другим запуском.');
            throw new RuntimeException('В диалоге уже идёт генерация. Дождитесь ответа или остановите её.');
        }
        return $id;
    }

    public function run(string $id, int $userId): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $id)) throw new InvalidArgumentException('Некорректный идентификатор запуска.');
        $record = $this->db->one('SELECT * FROM ai_assistant_runs WHERE ID=\'' . $this->db->escape($id)
            . '\' AND USER_ID=' . $userId);
        if ($record === null) throw new InvalidArgumentException('Запуск не найден.');
        $record['CANCEL_REQUESTED'] = (int)$record['CANCEL_REQUESTED'];
        return $record;
    }

    public function requestCancel(string $id, int $userId): void
    {
        $this->run($id, $userId);
        $this->db->requireExec("UPDATE ai_assistant_runs SET STATUS='cancelled',CANCEL_REQUESTED=1,UPDATED_AT=" . $this->now()
            . " WHERE ID='" . $this->db->escape($id) . "' AND USER_ID=" . $userId . " AND STATUS='running'");
    }

    /** Свежесть запуска обновляется и во время ожидания ответа провайдера. */
    public function heartbeat(string $id): void
    {
        $this->db->requireExec('UPDATE ai_assistant_runs SET UPDATED_AT=' . $this->now()
            . " WHERE ID='" . $this->db->escape($id) . "' AND STATUS='running'");
    }

    /** Состояние доступно после перезагрузки страницы и при потерянном SSE. */
    public function latestRun(int $dialogId, int $userId): ?array
    {
        $dialog = $this->dialog($dialogId, $userId);
        $last = (string)($dialog['LAST_RUN'] ?? '');
        $row = $last !== '' ? $this->run($last, $userId) : $this->db->one('SELECT * FROM ai_assistant_runs WHERE DIALOG_ID=' . $dialogId
            . ' AND USER_ID=' . $userId . " ORDER BY CASE WHEN STATUS='running' THEN 0 ELSE 1 END,CREATED_AT DESC,ID DESC LIMIT 1");
        if ($row !== null && $row['STATUS'] === 'running' && (int)$row['UPDATED_AT'] < $this->now() - self::RUN_TIMEOUT) {
            $this->finishRun((string)$row['ID'], 'error', 'Соединение прервано: запуск больше не отвечает. Можно отправить запрос снова.');
            $row = $this->run((string)$row['ID'], $userId);
        }
        return $row;
    }

    public function isCancelled(string $id): bool
    {
        $record = $this->db->one("SELECT CANCEL_REQUESTED FROM ai_assistant_runs WHERE ID='"
            . $this->db->escape($id) . "' AND STATUS='running'");
        return $record === null || (int)$record['CANCEL_REQUESTED'] === 1;
    }

    public function finishRun(string $id, string $status, string $error = ''): void
    {
        if (!in_array($status, array('done', 'error', 'cancelled'), true)) throw new InvalidArgumentException('Неизвестный статус запуска.');
        $message = mb_substr(SensitiveData::text($error), 0, 480);
        $this->db->requireExec('UPDATE ai_assistant_runs SET STATUS=\'' . $this->db->escape($status)
            . '\',ERROR=\'' . $this->db->escape($message) . '\',UPDATED_AT=' . $this->now()
            . " WHERE ID='" . $this->db->escape($id) . "' AND STATUS='running'");
    }

    /** Контекст редактора проверяется повторно: браузер не задаёт права и код. */
    public static function context(array $context): array
    {
        $allowed = array('object', 'class', 'method', 'script', 'language', 'selection', 'code', 'tab');
        $clean = array();
        foreach ($context as $key => $value) {
            if (!is_string($key) || !in_array($key, $allowed, true)) continue;
            if (is_array($value)) {
                if ($key === 'selection') {
                    $clean[$key] = is_string($value['text'] ?? null) ? mb_substr($value['text'], 0, 12000) : '';
                    continue;
                }
                $clean[$key] = array_values(array_filter(array_map(static fn($item): string =>
                    is_scalar($item) ? mb_substr(trim((string)$item), 0, 190) : '', $value)));
            } elseif (is_scalar($value)) {
                $clean[$key] = mb_substr(trim((string)$value), 0, $key === 'code' ? 60000 : 190);
            }
        }
        return $clean;
    }

    private function owner(int $userId): void
    {
        if ($userId < 1) throw new InvalidArgumentException('Владелец диалога не определён.');
    }

    private function now(): int
    {
        return $this->now ?? time();
    }

    private static function title(string $title): string
    {
        $title = trim(preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $title) ?? '');
        if ($title === '') $title = 'Новый диалог';
        return mb_substr($title, 0, 120);
    }

    private static function json(array $value, int $maximum): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (strlen($json) > $maximum) throw new InvalidArgumentException('Служебные данные диалога слишком большие.');
        return $json;
    }

    private static function decodeMeta(string $meta): array
    {
        try {
            $value = json_decode($meta, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return array();
        }
        return is_array($value) ? $value : array();
    }
}
