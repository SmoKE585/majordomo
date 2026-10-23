<?php

declare(strict_types=1);

namespace MajorDoMo\Modules\AiAssistant;

use MajorDoMo\Core\{Database, EntityCreator};
use RuntimeException;

/**
 * Неизменяемые предложения Neuron и однократное решение владельца диалога.
 * Изменения:
 * 2026-10-23 — подтверждение с повторной проверкой прав, срока и защиты от двойного клика.
 */
final class ActionRequests
{
    public function __construct(private Database $db, private ChatStore $chat, private AgentCatalog $agents, private ModuleState $state) {}

    public function propose(int $dialogId, int $userId, int $agentId, array $inputs): array
    {
        $this->chat->dialog($dialogId, $userId);
        $data = EntityCreator::validate($inputs);
        $row = array('DIALOG_ID' => $dialogId, 'USER_ID' => $userId, 'AGENT_ID' => $agentId,
            'PAYLOAD' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'STATUS' => 'pending', 'CREATED_AT' => time(), 'RESULT' => '');
        $row['ID'] = $this->db->insert('ai_assistant_actions', $row);
        return $this->publicRow($row);
    }

    public function forDialog(int $dialogId, int $userId): array
    {
        $this->chat->dialog($dialogId, $userId);
        return array_map($this->publicRow(...), $this->db->select('SELECT * FROM ai_assistant_actions WHERE DIALOG_ID='
            . $dialogId . ' AND USER_ID=' . $userId . ' ORDER BY ID DESC LIMIT 50'));
    }

    public function decide(int $id, int $userId, bool $approve, EntityCreator $creator): array
    {
        $row = $this->db->one('SELECT * FROM ai_assistant_actions WHERE ID=' . $id . ' AND USER_ID=' . $userId);
        if ($row === null) throw new RuntimeException('Предложение не найдено.');
        $this->chat->dialog((int)$row['DIALOG_ID'], $userId);
        $this->state->requireEnabled();
        $agent = $this->agents->get((int)$row['AGENT_ID']);
        if ((int)$agent['ENABLED'] !== 1 || !in_array('create_entity', $agent['TOOLS'], true)) {
            throw new RuntimeException('Разрешение на создание отозвано.');
        }
        if ($row['STATUS'] !== 'pending') return $this->publicRow($row);
        if (time() - (int)$row['CREATED_AT'] > 86400) throw new RuntimeException('Предложение устарело. Попросите агента подготовить новое.');
        $status = $approve ? 'applying' : 'rejected';
        $this->db->requireExec("UPDATE ai_assistant_actions SET STATUS='" . $status . "' WHERE ID=" . $id . " AND STATUS='pending'");
        if ($this->db->affectedRows() !== 1) throw new RuntimeException('Решение по предложению уже обрабатывается.');
        $data = json_decode($row['PAYLOAD'], true, 16, JSON_THROW_ON_ERROR);
        $result = 'Создание отменено пользователем.';
        if ($approve) {
            register_shutdown_function(function () use ($id): void {
                try {
                    $this->db->requireExec("UPDATE ai_assistant_actions SET STATUS='failed',RESULT='Выполнение прервано сервером. Проверьте наличие сущности перед новым предложением.' WHERE ID=" . $id . " AND STATUS='applying'");
                } catch (\Throwable $failure) { error_log('MajorDoMo AI action shutdown: ' . get_class($failure)); }
            });
            try {
                $created = $creator->create($data);
                $status = 'created';
                $result = 'Создано: ' . $data['name'] . ' (ID ' . $created['id'] . '). Код не запускался.';
                if ($created['warning'] !== '') $result .= ' ' . $created['warning'];
            } catch (\Throwable $failure) {
                $status = 'failed';
                $result = SensitiveData::text($failure->getMessage());
            }
        }
        $row['STATUS'] = $status;
        $row['RESULT'] = $result;
        $this->db->update('ai_assistant_actions', array('ID' => $id, 'STATUS' => $status, 'RESULT' => $result));
        $this->chat->appendMessage((int)$row['DIALOG_ID'], $userId, 'assistant', $result, array('action_id' => $id));
        return $this->publicRow($row);
    }

    private function publicRow(array $row): array
    {
        require_once dirname(__DIR__, 3) . '/lib/ui/MDJUI.php';
        $data = json_decode($row['PAYLOAD'], true, 16, JSON_THROW_ON_ERROR);
        $action = array('id' => (int)$row['ID'], 'status' => $row['STATUS'], 'result' => $row['RESULT'],
            'data' => $data, 'code_html' => $data['code'] !== '' ? \MDJUI::codeSample(array('language' => 'php', 'code' => $data['code'])) : '');
        ob_start();
        require dirname(__DIR__, 3) . '/templates/ai_assistant/_action.php';
        $action['html'] = (string)ob_get_clean();
        return $action;
    }
}
