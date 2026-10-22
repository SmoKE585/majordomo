<?php

namespace MajorDoMo\Core\Modes;

use Closure;
use InvalidArgumentException;
use MajorDoMo\Core\{Core, Database};
use MajorDoMo\Core\Events\EventBus;
use RuntimeException;
use Throwable;

/** Shared runtime for the panel, cycles, PHP helpers and CLI. */
final class ModeManager
{
    private static ?self $shared = null;
    private bool $locked = false;
    private bool $evaluating = false;
    private int $transitions = 0;

    public function __construct(
        private Database $db,
        private ?Closure $clock = null,
        private ?Closure $propertyReader = null,
        private ?Closure $actionRunner = null,
        private ?Closure $eventWriter = null,
        private ?Closure $bindingWriter = null
    ) {}

    public static function shared(): self { return self::$shared ??= new self(Core::database()); }
    public static function useInstance(?self $instance): void { self::$shared = $instance; }
    private function now(): int { return $this->clock ? ($this->clock)() : time(); }
    private function quote(string $value): string { return "'" . $this->db->escape($value) . "'"; }
    private function json(array $data): string { return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE); }

    public function all(): array { return $this->db->select('SELECT * FROM modes ORDER BY TITLE,ID'); }

    public function get(string|int $name): array
    {
        $where = is_int($name) ? 'ID=' . $name : 'NAME=' . $this->quote($name);
        $row = $this->db->one('SELECT * FROM modes WHERE ' . $where);
        if (!$row) throw new InvalidArgumentException('Режим не найден: ' . $name);
        return $row;
    }

    public function active(string $name): bool { return (bool)$this->get($name)['ACTIVE']; }

    public function history(int $id = 0): array
    {
        return $this->db->select('SELECT * FROM mode_history' . ($id ? ' WHERE MODE_ID=' . $id : '') . ' ORDER BY ID DESC LIMIT 100');
    }

    /** Changing a state never disables automation or resets its trigger memory. */
    public function set(string $name, bool $active, string $source = 'code'): bool
    {
        return $this->synchronized(function () use ($name, $active, $source): bool {
            $mode = $this->get($name);
            $this->primeForExternalChange($mode);
            $changed = $this->transition($mode, $active, Rules::text($source, 190), array());
            $this->evaluate();
            return $changed;
        });
    }

    public function tick(?string $event = null, array $details = array()): void
    {
        $this->synchronized(fn() => $this->evaluate($event === null ? null : strtoupper($event), $details));
    }

    public function save(int $id, array $input): int
    {
        $name = Rules::text($input['NAME'] ?? '', 100);
        $title = Rules::text($input['TITLE'] ?? '', 190);
        if (!Rules::validName($name)) throw new InvalidArgumentException('Код режима: строчные латинские буквы, цифры и подчёркивание; начинается с буквы.');
        if ($title === '') throw new InvalidArgumentException('Укажите название режима.');
        $rules = $input['RULES'] ?? array();
        if (!is_array($rules)) throw new InvalidArgumentException('Некорректный список правил.');
        $rules = Rules::validate($rules, $name);
        $on = $this->validateAction($input['ON_ACTION'] ?? array());
        $off = $this->validateAction($input['OFF_ACTION'] ?? array());
        $record = array('NAME' => $name, 'TITLE' => $title,
            'DESCRIPTION' => Rules::text($input['DESCRIPTION'] ?? '', 5000),
            'ENABLED' => (int)($input['ENABLED'] ?? 0) === 1 ? 1 : 0,
            'RULES' => $this->json($rules), 'ON_ACTION' => $this->json($on), 'OFF_ACTION' => $this->json($off));
        return $this->synchronized(function () use ($id, $record, $rules): int {
            $old = $id ? $this->get($id) : null;
            if ($old && $old['NAME'] !== $record['NAME']) throw new InvalidArgumentException('Код существующего режима менять нельзя: он используется в PHP и условиях.');
            $duplicate = $this->db->one('SELECT ID FROM modes WHERE NAME=' . $this->quote($record['NAME']) . ' AND ID<>' . $id);
            if ($duplicate) throw new InvalidArgumentException('Такой код режима уже занят.');
            foreach ($rules as $rule) foreach ($rule['conditions'] as $condition) {
                if ($condition['type'] === 'mode') $this->get($condition['mode']);
            }
            $before = $this->properties();
            $runtime = array();
            if ($old) {
                $oldRules = json_decode($old['RULES'], true, 64, JSON_THROW_ON_ERROR);
                $oldRuntime = json_decode($old['RUNTIME'], true, 64, JSON_THROW_ON_ERROR);
                // Preserve fired edges for unchanged rules, including after reordering.
                foreach ($rules as $index => $rule) foreach ($oldRules as $oldIndex => $oldRule) {
                    if ($rule === $oldRule && isset($oldRuntime[$oldIndex])) { $runtime[$index] = $oldRuntime[$oldIndex]; break; }
                }
            }
            $record['RUNTIME'] = $this->json($runtime);
            if ($old) {
                $record['ID'] = $id;
                $this->update($record);
            } else {
                $record += array('ACTIVE' => 0, 'CHANGED_AT' => 0, 'SOURCE' => '', 'LAST_ERROR' => '');
                $id = $this->db->insert('modes', $record);
                if (!$id) throw new RuntimeException('Не удалось создать режим.');
            }
            $this->syncProperties($before, $this->properties());
            return $id;
        });
    }

    public function delete(int $id): void
    {
        $this->synchronized(function () use ($id): void {
            $mode = $this->get($id);
            if ($mode['ACTIVE']) throw new InvalidArgumentException('Сначала выключите режим, чтобы выполнить действия выхода.');
            foreach ($this->all() as $other) foreach (json_decode($other['RULES'], true, 64, JSON_THROW_ON_ERROR) as $rule) {
                foreach ($rule['conditions'] as $condition) if ($condition['type'] === 'mode' && $condition['mode'] === $mode['NAME']) throw new InvalidArgumentException('Режим используется в условиях «' . $other['TITLE'] . '».');
            }
            $before = $this->properties();
            $this->db->requireExec('DELETE FROM modes WHERE ID=' . $id);
            $this->syncProperties($before, $this->properties());
        });
    }

    /** An already true condition is not a new trigger caused by a PHP command. */
    private function primeForExternalChange(array $mode): void
    {
        $runtime = json_decode($mode['RUNTIME'], true, 64, JSON_THROW_ON_ERROR);
        $now = $this->now();
        foreach (json_decode($mode['RULES'], true, 64, JSON_THROW_ON_ERROR) as $index => $rule) {
            $state = $runtime[$index] ?? array();
            if ($rule['trigger'] === 'condition' && !isset($state['since']) && Rules::matches($rule, $now,
                $this->propertyReader ?? static fn($property) => \getGlobal($property), fn($name) => (int)$this->get($name)['ACTIVE'])) {
                $runtime[$index] = array('since' => $now);
            }
            if ($rule['trigger'] === 'time' && date('H:i', $now) === $rule['at'] && !$state) $runtime[$index] = array('slot' => date('Y-m-d', $now) . ' ' . $rule['at']);
        }
        $json = $this->json($runtime);
        if ($json !== $mode['RUNTIME']) $this->update(array('ID' => $mode['ID'], 'RUNTIME' => $json));
    }

    private function validateAction(mixed $action): array
    {
        if (!is_array($action)) throw new InvalidArgumentException('Некорректное действие.');
        $type = Rules::text($action['type'] ?? 'none', 10);
        if (!in_array($type, array('none', 'method', 'script', 'php'), true)) throw new InvalidArgumentException('Неизвестный тип действия.');
        $value = $action['value'] ?? '';
        if (!is_string($value) || strlen($value) > 100000) throw new InvalidArgumentException('Слишком большой код действия.');
        if ($type === 'php') {
            if (str_contains($value, '<?') || str_contains($value, '?>')) throw new InvalidArgumentException('PHP-код указывается без тегов <?php и ?>.');
            try { token_get_all('<?php ' . $value, TOKEN_PARSE); }
            catch (\ParseError $e) { throw new InvalidArgumentException('Ошибка PHP: ' . $e->getMessage()); }
        }
        if ($type === 'method' && !preg_match('/^[^\s.]+\.[^\s.()]+$/uD', $value)) throw new InvalidArgumentException('Метод указывается как Объект.метод.');
        if ($type === 'script' && (!ctype_digit($value) || (int)$value < 1 || !$this->db->one('SELECT ID FROM scripts WHERE ID=' . (int)$value))) throw new InvalidArgumentException('Выберите существующий сценарий.');
        return array('type' => $type, 'value' => $value);
    }

    private function evaluate(?string $event = null, array $details = array()): void
    {
        if ($this->evaluating) return;
        $this->evaluating = true;
        try {
            // Dependency chains settle immediately; every rule consumes its edge
            // even when its target state was already reached by a caller.
            do {
                $previous = $this->transitions;
                foreach ($this->all() as $mode) {
                    if (!(int)$mode['ENABLED']) continue;
                    try { $this->evaluateMode($mode, $event, $details); }
                    catch (Throwable $error) {
                        $this->update(array('ID' => $mode['ID'], 'LAST_ERROR' => $error->getMessage()));
                        if (function_exists('DebMes')) \DebMes($mode['NAME'] . ': ' . $error->getMessage(), 'modes', 'error');
                    }
                }
                $event = null; $details = array();
            } while ($previous !== $this->transitions && $this->transitions < 32);
        } finally { $this->evaluating = false; }
    }

    private function evaluateMode(array $mode, ?string $event, array $details): void
    {
        $rules = json_decode($mode['RULES'], true, 64, JSON_THROW_ON_ERROR);
        $runtime = json_decode($mode['RUNTIME'], true, 64, JSON_THROW_ON_ERROR);
        $now = $this->now();
        $ready = array();
        foreach ($rules as $index => $rule) {
            $state = $runtime[$index] ?? array();
            $matches = Rules::matches($rule, $now,
                $this->propertyReader ?? static fn($property) => \getGlobal($property),
                function ($name) { return (int)$this->get($name)['ACTIVE']; });
            if ($rule['trigger'] === 'event' && $event === $rule['event']) {
                $state = array('pending' => true, 'since' => $now, 'details' => $details);
            } elseif ($rule['trigger'] === 'time') {
                // One occurrence per local date, including a repeated DST hour.
                $slot = date('Y-m-d', $now) . ' ' . $rule['at'];
                if (date('H:i', $now) === $rule['at'] && in_array((int)date('N', $now), $rule['days'], true) && ($state['slot'] ?? '') !== $slot) {
                    $state = array('pending' => true, 'since' => $now, 'slot' => $slot);
                }
            } elseif ($rule['trigger'] === 'condition') {
                if (!$matches) $state = array();
                elseif (!isset($state['since'])) $state = array('pending' => true, 'since' => $now);
            }
            if (!$matches) unset($state['pending'], $state['since'], $state['details']);
            if ($matches && !empty($state['pending']) && $now - $state['since'] >= $rule['delay']) {
                unset($state['pending']);
                $ready[] = array($rule, $state['details'] ?? array());
                unset($state['details']);
            }
            $runtime[$index] = $state;
        }
        $encoded = $this->json($runtime);
        if ($encoded !== $mode['RUNTIME']) $this->update(array('ID' => $mode['ID'], 'RUNTIME' => $encoded));
        if (!$ready) return;
        $targets = array_unique(array_map(static fn($entry) => $entry[0]['target'], $ready));
        if (count($targets) > 1) throw new RuntimeException('Одновременно сработали правила включения и выключения. Проверьте условия.');
        $rule = $ready[0][0];
        $this->transition($this->get((int)$mode['ID']), (bool)$rule['target'], 'rule:' . $rule['trigger'], $ready[0][1]);
    }

    private function transition(array $mode, bool $active, string $source, array $details): bool
    {
        if ((bool)$mode['ACTIVE'] === $active) return false;
        if (++$this->transitions > 32) throw new RuntimeException('Обнаружена цепочка из более 32 переключений режимов.');
        $now = $this->now();
        $history = array('MODE_ID' => $mode['ID'], 'TITLE' => $mode['TITLE'], 'ACTIVE' => (int)$active,
            'SOURCE' => $source, 'ADDED' => $now, 'ERROR' => '');
        $this->db->requireExec('START TRANSACTION');
        try {
            $this->update(array('ID' => $mode['ID'], 'ACTIVE' => (int)$active, 'CHANGED_AT' => $now, 'SOURCE' => $source, 'LAST_ERROR' => ''));
            $historyId = $this->db->insert('mode_history', $history);
            if (!$historyId) throw new RuntimeException('Не удалось записать переход режима.');
            $this->db->requireExec('COMMIT');
        } catch (Throwable $error) {
            $this->db->exec('ROLLBACK', true);
            throw $error;
        }
        $context = array('name' => $mode['NAME'], 'active' => $active, 'previous' => (bool)$mode['ACTIVE'], 'source' => $source, 'details' => $details);
        try {
            $action = json_decode($active ? $mode['ON_ACTION'] : $mode['OFF_ACTION'], true, 64, JSON_THROW_ON_ERROR);
            $this->runAction($action, $context);
        } catch (Throwable $error) {
            // State is factual. An action can already have switched physical
            // devices; rolling it back or retrying automatically is unsafe.
            $this->update(array('ID' => $mode['ID'], 'LAST_ERROR' => $error->getMessage()));
            if (!$this->db->update('mode_history', array('ID' => $historyId, 'ERROR' => $error->getMessage()))) throw new RuntimeException('Не удалось записать ошибку действия.', 0, $error);
            if (function_exists('DebMes')) \DebMes($mode['NAME'] . ': ' . $error->getMessage(), 'modes', 'error');
        }
        if ($this->eventWriter) ($this->eventWriter)('MODE_CHANGED', $context);
        else EventBus::emit('MODE_CHANGED', $context, 'modes');
        return true;
    }

    private function runAction(array $action, array $context): void
    {
        if ($action['type'] === 'none') return;
        if ($this->actionRunner) { ($this->actionRunner)($action, $context); return; }
        if ($action['type'] === 'method') { \callMethod($action['value'], $context); return; }
        if ($action['type'] === 'script') { \runScript((int)$action['value'], $context); return; }
        $variables = array('mode' => $context, 'params' => $context);
        \setEvalCode($action['value']);
        Core::code()->evaluate($this, $action['value'], $variables);
    }

    private function properties(): array
    {
        $names = array();
        foreach ($this->all() as $mode) foreach (json_decode($mode['RULES'], true, 64, JSON_THROW_ON_ERROR) as $rule) {
            foreach ($rule['conditions'] as $condition) if ($condition['type'] === 'property') $names[] = $condition['property'];
        }
        return array_unique($names);
    }

    private function syncProperties(array $before, array $after): void
    {
        foreach (array('remove' => array_diff($before, $after), 'add' => array_diff($after, $before)) as $operation => $properties) foreach ($properties as $property) {
            if ($this->bindingWriter) { ($this->bindingWriter)($operation, $property); continue; }
            [$object, $name] = explode('.', $property, 2);
            if ($operation === 'add') \addLinkedProperty($object, $name, 'modes');
            else \removeLinkedProperty($object, $name, 'modes');
        }
    }

    private function update(array $record): void
    {
        if (!$this->db->update('modes', $record)) throw new RuntimeException('Не удалось сохранить режим.');
    }

    private function synchronized(callable $operation): mixed
    {
        if ($this->locked) return $operation();
        $lock = $this->db->one("SELECT GET_LOCK('mjd:modes',5) AS ACQUIRED");
        if ((int)($lock['ACQUIRED'] ?? 0) !== 1) throw new RuntimeException('Режимы заняты другим процессом. Повторите действие.');
        $this->locked = true; $this->transitions = 0;
        try { return $operation(); }
        finally { $this->locked = false; $this->db->one("SELECT RELEASE_LOCK('mjd:modes')"); }
    }
}
