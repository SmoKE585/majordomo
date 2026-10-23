<?php

declare(strict_types=1);

use MajorDoMo\Core\Core;
use MajorDoMo\Core\Database;
use MajorDoMo\Modules\AiAssistant\ModuleState;
use MajorDoMo\Modules\AiAssistant\PanelController;
use MajorDoMo\Modules\AiAssistant\Schema;

/**
 * Штатный модуль ИИ-помощника; установка не включает обращения к моделям.
 *
 * Изменения:
 * 2026-10-23 — подписки на установку, обновление и фоновую сверку справки.
 * 2026-10-23 — раздел собран из контроллера: чат, настройки, знания, диагностика.
 * 2026-10-23 — жизненный цикл, собственная схема и состояние в админке.
 */
class ai_assistant extends module
{
    public string $title;
    public string $module_category;
    private Database $database;
    private bool $sqlite;

    public function __construct(?Database $database = null, bool $sqlite = false)
    {
        $this->name = 'ai_assistant';
        $this->title = 'ИИ-помощник';
        $this->module_category = '<#LANG_SECTION_APPLICATIONS#>';
        $this->database = $database ?? Core::database();
        $this->sqlite = $sqlite;
        $previousSchema = isModuleInstalled('ai_assistant') ? $this->database->one('SELECT SCHEMA_VERSION FROM ai_assistant_settings WHERE ID=1') : null;
        $this->checkInstalled();
        // Маркер установленного модуля не препятствует обновлению его схемы.
        Schema::install($this->database, $this->sqlite);
        if (function_exists('subscribeToEvent') && !$this->sqlite) {
            foreach (array('MODULE_INSTALLED', 'SYSTEM_UPDATED', 'HOURLY') as $event) subscribeToEvent($this->name, $event);
            if ((int)($previousSchema['SCHEMA_VERSION'] ?? 0) < Schema::VERSION) {
                \MajorDoMo\Modules\AiAssistant\KnowledgeSync::schedule();
            }
        }
    }

    public function processSubscription($event_name, $details = '')
    {
        if (in_array($event_name, array('MODULE_INSTALLED', 'SYSTEM_UPDATED', 'HOURLY'), true)) {
            \MajorDoMo\Modules\AiAssistant\KnowledgeSync::schedule();
        }
    }

    public function dbInstall($data)
    {
        Schema::install($this->database, $this->sqlite);
    }

    public function install($parent_name = '')
    {
        parent::install($parent_name);
        $this->state()->installed();
    }

    /** Обычное удаление сохраняет данные; очистка требует точной фразы. */
    public function uninstall($deleteData = false)
    {
        if ($deleteData !== false) {
            Schema::confirmDeletion($deleteData);
        }
        if (!$this->sqlite) {
            if (function_exists('unsubscribeFromEvent')) {
                foreach (array('MODULE_INSTALLED', 'SYSTEM_UPDATED', 'HOURLY') as $event) unsubscribeFromEvent($this->name, $event);
            }
            if (function_exists('clearTimeOut')) clearTimeOut('ai_assistant.knowledge_sync');
        }
        $this->state()->detached();
        parent::uninstall();
        if ($deleteData !== false) {
            Schema::remove($this->database, $deleteData);
        }
    }

    public function state(): ModuleState
    {
        return new ModuleState($this->database);
    }

    public function run()
    {
        $user = Core::session()->user();
        if ($user === null) {
            http_response_code(403);
            $this->result = '';
            return;
        }
        require_once ROOT . 'lib/ui/MDJUI.php';
        try {
            $controller = new PanelController($this->database, $this->state(), $this->sqlite);
            $this->result = $this->parseLinks($controller->handle());
        } catch (\Throwable $failure) {
            // Ошибка раздела не должна ронять оболочку админки; подробности — в журнале.
            error_log('MajorDoMo ai_assistant panel failed: ' . get_class($failure));
            http_response_code(500);
            ob_start();
            echo '<div class="md-ui-page md-ui-page--stack">'
                . \MDJUI::alert('danger', 'Раздел ИИ-помощника временно недоступен',
                    'Проверьте журнал ошибок системы и обновите страницу. Настройки и история не изменены.')
                . '</div>';
            $this->result = (string)ob_get_clean();
        }
    }
}
