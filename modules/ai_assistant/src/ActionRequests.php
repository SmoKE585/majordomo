<?php

declare(strict_types=1);

namespace MajorDoMo\Modules\AiAssistant;

use MajorDoMo\Core\{Database, EntityCreator};
use RuntimeException;

/**
 * Неизменяемые предложения Neuron и однократное решение владельца диалога.
 * Изменения:
 * 2026-11-11 — короткий предпросмотр длинного кода без вложенной прокрутки.
 * 2026-11-11 — пакет предложений, исходная версия правки и история решений.
 * 2026-10-23 — подтверждение с повторной проверкой прав, срока и защиты от двойного клика.
 */
final class ActionRequests
{
    public function __construct(private Database $db, private ChatStore $chat, private AgentCatalog $agents, private ModuleState $state) {}

    public function propose(int $dialogId, int $userId, int $agentId, array $inputs): array
    {
        $this->chat->dialog($dialogId, $userId);
        $data = EntityCreator::validate($inputs);
        $data = $this->withSnapshot($data, !array_key_exists('description', $inputs));
        return $this->store($dialogId, $userId, $agentId, $data);
    }

    /** Проверяем весь пакет до записи: не оставляем половину предложений. */
    public function proposeBatch(int $dialogId, int $userId, int $agentId, array $inputs): array
    {
        $this->chat->dialog($dialogId, $userId);
        if (!array_key_exists('changes', $inputs)) return array($this->propose($dialogId, $userId, $agentId, $inputs));
        if (count($inputs) !== 1 || !is_array($inputs['changes']) || !array_is_list($inputs['changes'])
            || count($inputs['changes']) < 1 || count($inputs['changes']) > 30) throw new RuntimeException('Передайте только changes: от 1 до 30 действий.');
        $data = array();
        foreach ($inputs['changes'] as $change) {
            if (!is_array($change)) throw new RuntimeException('Каждое действие должно быть объектом параметров.');
            $data[] = $this->withSnapshot(EntityCreator::validate($change), !array_key_exists('description', $change));
        }
        $this->db->requireExec('BEGIN', true);
        try {
            $actions = array();
            foreach ($data as $change) $actions[] = $this->store($dialogId, $userId, $agentId, $change);
            $this->db->requireExec('COMMIT', true);
            return $actions;
        } catch (\Throwable $failure) {
            $this->db->requireExec('ROLLBACK', true);
            throw $failure;
        }
    }

    private function withSnapshot(array $data, bool $keepDescription): array
    {
        if ($data['operation'] === 'update') {
            $data['_expected'] = EntityCreator::methodSnapshot($this->db, $data);
            if ($keepDescription) $data['description'] = $data['_expected']['DESCRIPTION'];
        }
        return $data;
    }

    private function store(int $dialogId, int $userId, int $agentId, array $data): array
    {
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
            . $dialogId . ' AND USER_ID=' . $userId . " AND STATUS IN ('pending','applying') ORDER BY ID ASC"));
    }

    public function decide(int $id, int $userId, bool $approve, EntityCreator $creator): array
    {
        $row = $this->db->one('SELECT * FROM ai_assistant_actions WHERE ID=' . $id . ' AND USER_ID=' . $userId);
        if ($row === null) throw new RuntimeException('Предложение не найдено.');
        $this->chat->dialog((int)$row['DIALOG_ID'], $userId);
        $this->state->requireEnabled();
        $agent = $this->agents->get((int)$row['AGENT_ID']);
        if ($approve && ((int)$agent['ENABLED'] !== 1 || !in_array('create_entity', $agent['TOOLS'], true))) {
            throw new RuntimeException('Разрешение на создание отозвано.');
        }
        if ($row['STATUS'] !== 'pending') return $this->publicRow($row);
        $status = $approve ? 'applying' : 'rejected';
        $this->db->requireExec("UPDATE ai_assistant_actions SET STATUS='" . $status . "' WHERE ID=" . $id . " AND STATUS='pending'");
        if ($this->db->affectedRows() !== 1) throw new RuntimeException('Решение по предложению уже обрабатывается.');
        $data = json_decode($row['PAYLOAD'], true, 16, JSON_THROW_ON_ERROR);
        $result = 'Отменено пользователем: ' . $data['parent'] . ($data['parent'] !== '' ? '.' : '') . $data['name'] . '.';
        if ($approve) {
            register_shutdown_function(function () use ($id): void {
                try {
                    $this->db->requireExec("UPDATE ai_assistant_actions SET STATUS='failed',RESULT='Выполнение прервано сервером. Проверьте наличие сущности перед новым предложением.' WHERE ID=" . $id . " AND STATUS='applying'");
                } catch (\Throwable $failure) { error_log('MajorDoMo AI action shutdown: ' . get_class($failure)); }
            });
            try {
                if (time() - (int)$row['CREATED_AT'] > 86400) throw new RuntimeException('Предложение устарело. Попросите агента подготовить новое.');
                $expected = $data['_expected'] ?? array();
                unset($data['_expected']);
                $editing = ($data['operation'] ?? 'create') === 'update';
                $created = $editing ? $creator->updateMethod($data, $expected) : $creator->create($data);
                $status = $editing ? 'updated' : 'created';
                $result = ($editing ? 'Сохранено: ' : 'Создано: ') . $data['parent'] . ($data['parent'] !== '' ? '.' : '') . $data['name'] . ' (ID ' . $created['id'] . '). Код не запускался.';
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
        $before = $data['_expected'] ?? null;
        unset($data['_expected']);
        $codeLines = explode("\n", str_replace(array("\r\n", "\r"), "\n", $data['code']));
        $action = array('id' => (int)$row['ID'], 'status' => $row['STATUS'], 'result' => $row['RESULT'],
            'data' => $data, 'code_html' => $data['code'] !== '' ? \MDJUI::codeSample(array('language' => 'php', 'code' => $data['code'])) : '',
            'code_lines' => count($codeLines),
            'code_preview_html' => count($codeLines) > 12 ? \MDJUI::codeSample(array('language' => 'php',
                'code' => implode("\n", array_slice($codeLines, 0, 12)), 'copy' => false, 'caption' => 'Первые 12 строк. Полный код — ниже.')) : '',
            'before_html' => $before !== null ? \MDJUI::codeSample(array('language' => 'php', 'code' => $before['_SCRIPT_CODE'] ?? $before['CODE'])) : '',
            'source_script' => $before['_SCRIPT_TITLE'] ?? '');
        ob_start();
        require dirname(__DIR__, 3) . '/templates/ai_assistant/_action.php';
        $action['html'] = (string)ob_get_clean();
        return $action;
    }
}
