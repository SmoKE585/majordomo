<?php

use MajorDoMo\Core\Core;
use MajorDoMo\Core\Modes\{ModeManager, Schema};

class modes extends module
{
    public function __construct()
    {
        $this->name = 'modes';
        $this->title = 'Режимы';
        $this->module_category = '<#LANG_SECTION_APPLICATIONS#>';
        $this->checkInstalled();
    }

    public function dbInstall($data) { Schema::install(Core::database()); }

    public function getParams()
    {
        global $id, $view_mode;
        if (isset($id) && is_scalar($id)) $this->id = (int)$id;
        if (isset($view_mode) && is_scalar($view_mode)) $this->view_mode = (string)$view_mode;
    }

    public function saveParams($data = 0)
    {
        return parent::saveParams(array('id' => (int)$this->id, 'view_mode' => (string)$this->view_mode));
    }

    public function propertySetHandle($object, $property, $value) { ModeManager::shared()->tick(); }

    public function run()
    {
        $this->getParams();
        require_once ROOT . 'lib/ui/MDJUI.php';
        global $session;
        if (!isset($session->data['modes_csrf'])) $session->data['modes_csrf'] = bin2hex(random_bytes(32));
        $token = $session->data['modes_csrf'];
        $manager = ModeManager::shared();
        $error = ''; $success = ''; $draft = null;
        $view = (string)$this->view_mode; $id = max(0, (int)$this->id);
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            try {
                if (!hash_equals($token, self::posted('_token'))) throw new InvalidArgumentException('Форма устарела. Обновите страницу.');
                $operation = self::posted('operation');
                if ($operation === 'save') {
                    $draft = self::draft();
                    $id = $manager->save($id, $draft);
                    $this->id = $id; $this->view_mode = $view = 'edit_modes'; $draft = null;
                    $success = 'Настройки режима сохранены.';
                } elseif ($operation === 'set') {
                    $target = self::posted('active');
                    if (!in_array($target, array('0', '1'), true)) throw new InvalidArgumentException('Некорректное состояние.');
                    $manager->set($manager->get($id)['NAME'], $target === '1', 'admin');
                    $success = 'Состояние установлено. Автоматика продолжает работать.';
                } elseif ($operation === 'delete') {
                    $manager->delete($id); $this->id = $id = 0; $this->view_mode = $view = ''; $success = 'Режим удалён.';
                } else throw new InvalidArgumentException('Неизвестное действие.');
            } catch (Throwable $failure) { $error = $failure->getMessage(); }
        }
        $all = $manager->all();
        $editing = $view === 'edit_modes';
        $record = null;
        if ($editing && $id) {
            try { $record = $manager->get($id); }
            catch (InvalidArgumentException $failure) { http_response_code(404); $error = $failure->getMessage(); $editing = false; }
        }
        if ($editing) {
            $form = $draft ?? ($record ? $record + array() : array('NAME' => '', 'TITLE' => '', 'DESCRIPTION' => '', 'ENABLED' => 1, 'RULES' => array(), 'ON_ACTION' => array('type' => 'none', 'value' => ''), 'OFF_ACTION' => array('type' => 'none', 'value' => '')));
            foreach (array('RULES', 'ON_ACTION', 'OFF_ACTION') as $field) if (is_string($form[$field])) $form[$field] = json_decode($form[$field], true, 64, JSON_THROW_ON_ERROR);
        }
        $history = $manager->history($editing ? $id : 0);
        $modeOptions = array('' => 'Выберите режим');
        foreach ($all as $mode) if (!$editing || (int)$mode['ID'] !== $id) $modeOptions[$mode['NAME']] = $mode['TITLE'];
        $scripts = Core::database()->select('SELECT ID,TITLE FROM scripts ORDER BY TITLE');
        $cycleEnabled = !(new MajorDoMo\Core\Cycles\CycleManager(ROOT))->isDisabled('cycle_states');
        ob_start();
        require ROOT . 'templates/modes/modes.php';
        $this->result = $this->parseLinks(ob_get_clean());
    }

    private static function posted(string $key): string
    {
        if (!isset($_POST[$key])) return '';
        if (!is_string($_POST[$key])) throw new InvalidArgumentException('Некорректное поле формы.');
        return $_POST[$key];
    }

    private static function draft(): array
    {
        $rules = $_POST['rules'] ?? array();
        if (!is_array($rules) || count($rules) > 32) throw new InvalidArgumentException('Не более 32 правил на режим.');
        // The form has at most four nested array levels. Reject malformed values
        // before passing a draft to UI components, which expect scalar leaves.
        $validate = function (array $items, int $depth = 0) use (&$validate): void {
            if ($depth > 4 || count($items) > 64) throw new InvalidArgumentException('Некорректная структура правил.');
            foreach ($items as $value) {
                if (is_array($value)) $validate($value, $depth + 1);
                elseif (!is_string($value)) throw new InvalidArgumentException('Некорректное поле правила.');
            }
        };
        $validate($rules);
        $normalized = array();
        foreach ($rules as $rule) {
            if (!is_array($rule)) throw new InvalidArgumentException('Некорректное правило.');
            foreach (array('trigger', 'target', 'match', 'delay', 'at', 'event') as $field) if (isset($rule[$field]) && !is_string($rule[$field])) throw new InvalidArgumentException('Некорректное поле правила.');
            $conditions = $rule['conditions'] ?? array();
            if (!is_array($conditions)) throw new InvalidArgumentException('Некорректные условия.');
            foreach ($conditions as $condition) {
                if (!is_array($condition)) throw new InvalidArgumentException('Некорректное условие.');
                foreach ($condition as $value) if (!is_string($value)) throw new InvalidArgumentException('Некорректное значение условия.');
            }
            $rule['conditions'] = array_values($conditions);
            $rule['days'] = $rule['days'] ?? array();
            if (!is_array($rule['days'])) throw new InvalidArgumentException('Некорректные дни недели.');
            foreach ($rule['days'] as $day) if (!is_string($day)) throw new InvalidArgumentException('Некорректный день недели.');
            $normalized[] = $rule;
        }
        return array('NAME' => self::posted('name'), 'TITLE' => self::posted('title'), 'DESCRIPTION' => self::posted('description'),
            'ENABLED' => self::posted('enabled') === '1' ? 1 : 0, 'RULES' => $normalized,
            'ON_ACTION' => array('type' => self::posted('on_type'), 'value' => self::posted('on_value')),
            'OFF_ACTION' => array('type' => self::posted('off_type'), 'value' => self::posted('off_value')));
    }
}
