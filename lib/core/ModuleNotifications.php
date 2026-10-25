<?php

namespace MajorDoMo\Core;

/**
 * Общие уведомления модулей: непрочитанные сообщения и явное прочтение.
 *
 * 2026-10-25 — повторы отбрасываются до записи и логирования; добавлены
 * подробности, внутренние ссылки и стабильные ключи для авторов модулей.
 */
final class ModuleNotifications
{
    private ?bool $available = null;
    private ?bool $extended = null;

    public function __construct(private Database $db) {}

    private function available(): bool
    {
        return $this->available ??= $this->db->tableExists('module_notifications');
    }

    private function extended(): bool
    {
        if ($this->extended === null) {
            $columns = array_column($this->db->select('SHOW COLUMNS FROM module_notifications'), 'Field');
            $this->extended = in_array('NOTIFICATION_KEY', $columns, true) && in_array('PAYLOAD', $columns, true);
        }
        return $this->extended;
    }

    public function send(string $module, string $message, string $type = 'default', array $options = array()): array
    {
        if (!$this->available()) return array('status' => false);
        if ($module === '' || mb_strlen($module) > 50) return array('status' => false, 'error' => 'Invalid module name.');
        $message = htmlspecialchars(strip_tags($message), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        if (trim($message) === '' || mb_strlen($message) > 255) {
            return array('status' => false, 'error' => 'Message must contain 1 to 255 characters. Use details for long text.');
        }
        if (!in_array($type, array('info', 'danger', 'warning', 'success', 'default'), true)) $type = 'default';
        $payload = array();
        foreach (array('key' => 190, 'title' => 200, 'details' => 16000, 'url' => 2048, 'label' => 100) as $name => $length) {
            $value = $options[$name] ?? '';
            if (!is_string($value) || mb_strlen($value) > $length) {
                return array('status' => false, 'error' => 'Invalid notification option: ' . $name);
            }
            $payload[$name] = trim($value);
        }
        if ($payload['url'] !== '' && self::internalUrl($payload['url']) === '') {
            return array('status' => false, 'error' => 'Notification URL must be an internal absolute path.');
        }
        $hasOptions = array_filter($payload, static fn(string $value): bool => $value !== '') !== array();
        if ($hasOptions && !$this->extended()) {
            return array('status' => false, 'error' => 'Apply the module notifications migration before using options.');
        }

        $name = $this->db->escape($module);
        // Один замок на модуль защищает и от гонки проверки с INSERT,
        // и от одновременного превышения лимита разных уведомлений.
        $lock = "CONCAT('mdn:', SHA1(CONCAT(DATABASE(), ':', '" . $name . "')))";
        $acquired = $this->db->one('SELECT GET_LOCK(' . $lock . ',5) AS ACQUIRED');
        if ((int)($acquired['ACQUIRED'] ?? 0) !== 1) throw new \RuntimeException('Не удалось заблокировать отправку уведомления модуля.');
        try {
            $where = "MODULE_NAME='" . $name . "' AND IS_READ=0";
            $identity = $payload['key'] !== ''
                ? "NOTIFICATION_KEY='" . $this->db->escape($payload['key']) . "'"
                : "MESSAGE='" . $this->db->escape($message) . "' AND TYPE='" . $this->db->escape($type) . "'";
            $existing = $this->db->one('SELECT ID FROM module_notifications WHERE ' . $where . ' AND ' . $identity . ' ORDER BY ID LIMIT 1');
            if ($existing !== null) {
                // Старый контракт сохраняется: повтор — отказ с ID исходной
                // записи. Ни время, ни содержимое, ни журнал не меняются.
                return array('status' => false, 'error' => 'Notification already exists. ID: ' . $existing['ID'], 'id' => (int)$existing['ID']);
            }
            $count = $this->db->one('SELECT COUNT(*) AS TOTAL_UNREAD FROM module_notifications WHERE ' . $where);
            if ((int)($count['TOTAL_UNREAD'] ?? 0) > 10) {
                return array('status' => false, 'error' => 'More than 10 notifications in the unread status.');
            }
            $record = array('MODULE_NAME' => $module, 'MESSAGE' => $message, 'TYPE' => $type, 'IS_READ' => 0, 'ADDED' => date('Y-m-d H:i:s'));
            if ($hasOptions) {
                $record['NOTIFICATION_KEY'] = $payload['key'];
                unset($payload['key']);
                $record['PAYLOAD'] = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                if (strlen($record['PAYLOAD']) > 65535) return array('status' => false, 'error' => 'Notification options exceed the storage limit.');
            }
            $id = $this->db->insert('module_notifications', $record);
            if ($id < 1) throw new \RuntimeException('Не удалось сохранить уведомление модуля.');
            if (function_exists('DebMes')) DebMes($module . ' (' . $type . '): ' . $message, 'module_notifications', $type);
            return array('status' => true, 'id' => $id);
        } finally {
            $this->db->one('SELECT RELEASE_LOCK(' . $lock . ') AS RELEASED');
        }
    }

    public function unreadRecords(string $module = '', int $limit = 0, int $offset = 0): array
    {
        if (!$this->available()) return array();
        $where = 'n.IS_READ=0';
        if ($module !== '') $where .= " AND n.MODULE_NAME='" . $this->db->escape($module) . "'";
        $sql = 'SELECT n.*, p.TITLE AS MODULE_TITLE FROM module_notifications n LEFT JOIN project_modules p ON p.NAME=n.MODULE_NAME WHERE ' . $where
            . " ORDER BY CASE n.TYPE WHEN 'danger' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END, n.ADDED DESC, n.ID DESC";
        if ($limit > 0) $sql .= ' LIMIT ' . min(100, $limit) . ' OFFSET ' . max(0, $offset);
        return $this->db->select($sql);
    }

    public function listing(string $module = '', int $offset = 0): array
    {
        return array_merge($this->summary($module), array(
            'items' => array_map(array(self::class, 'present'), $this->unreadRecords($module, 50, $offset)), 'offset' => $offset,
        ));
    }

    public function summary(string $module = ''): array
    {
        $groups = $this->available() ? $this->db->select('SELECT MODULE_NAME, TYPE, COUNT(*) AS TOTAL FROM module_notifications WHERE IS_READ=0 GROUP BY MODULE_NAME, TYPE') : array();
        $total = 0;
        $counts = array();
        $levels = array();
        $ranks = array('info' => 0, 'success' => 0, 'warning' => 1, 'danger' => 2);
        $severity = 'info';
        foreach ($groups as $group) {
            $name = $group['MODULE_NAME'];
            $type = self::type((string)$group['TYPE']);
            $count = (int)$group['TOTAL'];
            $counts[$name] = ($counts[$name] ?? 0) + $count;
            if (!isset($levels[$name]) || $ranks[$type] > $ranks[$levels[$name]]) $levels[$name] = $type;
            if ($module === '' || $module === $name) {
                $total += $count;
                if ($ranks[$type] > $ranks[$severity]) $severity = $type;
            }
        }
        return array('total' => $total, 'counts' => $counts, 'levels' => $levels, 'severity' => $severity);
    }

    public static function type(string $type): string
    {
        return in_array($type, array('info', 'success', 'warning', 'danger'), true) ? $type : 'info';
    }

    public static function internalUrl(string $url): string
    {
        // Запрещаем внешние схемы, protocol-relative URL и управляющие символы,
        // в том числе после декодирования: ссылка доставляется сторонним модулем.
        $decoded = rawurldecode($url);
        return !preg_match('/%(?![a-f0-9]{2})/i', $url) && str_starts_with($decoded, '/') && !str_starts_with($decoded, '//')
            && !preg_match('/[\\\\\x00-\x20\x7f]/', $decoded) ? $url : '';
    }

    public static function present(array $row): array
    {
        $payload = json_decode((string)($row['PAYLOAD'] ?? ''), true);
        $payload = is_array($payload) ? $payload : array();
        $text = static fn(string $key): string => is_string($payload[$key] ?? null) ? $payload[$key] : '';
        return array('id' => (int)$row['ID'], 'module' => (string)$row['MODULE_NAME'],
            'module_title' => (string)(($row['MODULE_TITLE'] ?? '') ?: $row['MODULE_NAME']),
            'message' => html_entity_decode((string)$row['MESSAGE'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'type' => self::type((string)$row['TYPE']), 'added' => (string)$row['ADDED'],
            'title' => $text('title'), 'details' => $text('details'), 'url' => self::internalUrl($text('url')), 'label' => $text('label'),
            'module_url' => (defined('ROOTHTML') ? ROOTHTML : '/') . 'panel/' . rawurlencode((string)$row['MODULE_NAME']) . '.html');
    }

    public function dismiss(?int $id, ?string $module = null): int
    {
        if (!$this->available()) return 0;
        if ($id === null && ($module === null || $module === '')) throw new \InvalidArgumentException('Укажите уведомление или модуль.');
        $where = 'IS_READ=0';
        if ($id !== null) $where .= ' AND ID=' . $id;
        if ($module !== null) $where .= " AND MODULE_NAME='" . $this->db->escape($module) . "'";
        $this->db->requireExec('UPDATE module_notifications SET IS_READ=1 WHERE ' . $where);
        return $this->db->affectedRows();
    }

    public function readLegacy(string $module, mixed $notification): array
    {
        if (!$this->available()) return array('status' => false);
        $where = "MODULE_NAME='" . $this->db->escape($module) . "' AND IS_READ=0 AND ";
        $where .= is_int($notification) ? 'ID=' . $notification : "MESSAGE='" . $this->db->escape($notification) . "'";
        $row = $this->db->one('SELECT * FROM module_notifications WHERE ' . $where . ' ORDER BY ID LIMIT 1');
        if ($row === null || !$this->dismiss((int)$row['ID'], $module)) return array('status' => false, 'error' => 'No such noty found');
        if (function_exists('DebMes')) DebMes($module . ' (' . $row['TYPE'] . ' - marked as read): ' . $row['MESSAGE'], 'module_notifications');
        return array('status' => true);
    }
}
