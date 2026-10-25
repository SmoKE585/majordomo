<?php

use MajorDoMo\Core\Core;
use MajorDoMo\Core\Config;
use MajorDoMo\Core\Scheduler;

/**
 * Панель отложенных заданий: состояние, ошибки, команды и цепочки выполнения.
 * 2026-10-25 — MDJUI, безопасные POST-действия и общий планировщик с Redis.
 */
class jobs extends module
{
    public function __construct()
    {
        $this->name = 'jobs';
        $this->title = '<#LANG_MODULE_JOBS#>';
        $this->module_category = '<#LANG_SECTION_SYSTEM#>';
        $this->checkInstalled();
    }

    public function getParams()
    {
        global $id, $view_mode, $mode;
        if (isset($id) && is_scalar($id)) $this->id = max(0, (int)$id);
        if (isset($view_mode) && is_string($view_mode)) $this->view_mode = $view_mode;
        if (isset($mode) && is_string($mode)) $this->mode = $mode;
    }

    public function saveParams($data = 0)
    {
        return parent::saveParams(array('id' => (int)$this->id, 'view_mode' => (string)$this->view_mode));
    }

    public function run()
    {
        $this->getParams();
        $scheduler = Core::scheduler();
        if (($_GET['ajax'] ?? '') === '1' && ($_GET['op'] ?? '') === 'trace') {
            try {
                $payload = $this->traceResponse($scheduler, (int)$this->id);
            } catch (Throwable $error) {
                http_response_code(500);
                DebMes('Не удалось прочитать цепочку задания: ' . $error->getMessage(), 'jobs', 'error');
                $payload = array('status' => 'error', 'message' => 'Не удалось прочитать журнал задания.');
            }
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }
        require_once ROOT . 'lib/ui/MDJUI.php';
        global $session;
        if (!$session->has('jobs_csrf')) $session->set('jobs_csrf', bin2hex(random_bytes(32)));
        $token = (string)$session->get('jobs_csrf');
        $error = '';
        $draft = array('title' => '', 'commands' => '', 'runin' => '0', 'runtype' => 's');
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            try {
                if (!hash_equals($token, self::posted('_token'))) throw new InvalidArgumentException('Форма устарела. Обновите страницу.');
                $operation = self::posted('operation') ?: self::posted('mode');
                if ($operation === 'add') {
                    foreach (array_keys($draft) as $key) $draft[$key] = self::posted($key);
                    $title = trim($draft['title']);
                    $delay = filter_var($draft['runin'], FILTER_VALIDATE_INT, array('options' => array('min_range' => 0, 'max_range' => 31536000)));
                    $units = array('s' => 1, 'm' => 60, 'h' => 3600);
                    if ($delay === false || !isset($units[$draft['runtype']]) || $delay * $units[$draft['runtype']] > 31536000) throw new InvalidArgumentException('Укажите задержку от 0 секунд до 365 дней.');
                    if (trim($draft['commands']) === '' || strlen($draft['commands']) > 60000) throw new InvalidArgumentException('Укажите PHP-команду длиной до 60 000 байт.');
                    try { token_get_all('<?php ' . $draft['commands'], TOKEN_PARSE); }
                    catch (ParseError $syntax) { throw new InvalidArgumentException('Ошибка PHP: ' . $syntax->getMessage()); }
                    $scheduler->schedule($title, $draft['commands'], time() + $delay * $units[$draft['runtype']], 0);
                    $this->redirect('?saved=1');
                    return;
                }
                if ($operation === 'cancel' || $this->view_mode === 'delete_jobs') {
                    $id = filter_var(self::posted('id'), FILTER_VALIDATE_INT, array('options' => array('min_range' => 1)));
                    if ($id === false) throw new InvalidArgumentException('Некорректный номер задания.');
                    $scheduler->cancel($id);
                    $this->redirect('?cancelled=1');
                    return;
                }
                throw new InvalidArgumentException('Неизвестное действие.');
            } catch (Throwable $failure) {
                $error = $failure->getMessage();
            }
        } elseif ($this->mode === 'cancel' || $this->view_mode === 'delete_jobs') {
            $error = 'Для отмены используйте кнопку в списке и подтвердите действие.';
        }
        $status = self::query('status');
        $search = mb_substr(self::query('q'), 0, 255);
        $page = max(1, (int)self::query('page'));
        $data = $scheduler->listing($status, $search, $page);
        $maxAttempts = Scheduler::maxAttempts();
        $states = array('waiting' => array('Ожидает', 'neutral'), 'running' => array('Выполняется', 'info'),
            'retry' => array('Повтор', 'warning'), 'failed' => array('Ошибка', 'danger'), 'done' => array('Выполнено', 'success'));
        ob_start();
        require ROOT . 'templates/jobs/jobs.php';
        $this->result = $this->parseLinks(ob_get_clean());
    }

    public function traceResponse(Scheduler $scheduler, int $id): array
    {
        $job = $scheduler->get($id);
        if (!$job) { http_response_code(404); return array('status' => 'error', 'message' => 'Задание не найдено.'); }
        $runs = Core::audit()->trace((string)$job['TRACE_ID']);
        foreach ($runs as &$run) {
            foreach ($run['events'] as &$event) {
                $event['links'] = self::eventLinks($event);
            }
            unset($event);
        }
        unset($run);
        $count = count($runs);
        return array('status' => 'ok', 'meta' => array('property_name' => $job['TITLE'],
            'description' => 'Постановка, попытки выполнения и вызванные изменения.',
            'retention_days' => Config::int('PROPERTY_AUDIT_RETENTION_DAYS', 7)),
            'runs' => $runs, 'pagination' => array('page' => 1, 'pages' => $count, 'total' => $count,
                'per_page' => 1, 'from' => $count, 'to' => $count, 'has_prev' => false, 'has_next' => false));
    }

    private static function eventLinks(array $event): array
    {
        $root = ROOTHTML . 'admin.php?';
        $links = array();
        if ($event['object_id'] > 0) $links[] = array('label' => 'Свойства объекта', 'url' => $root . http_build_query(array('action' => 'objects', 'md' => 'objects', 'view_mode' => 'edit_objects', 'id' => $event['object_id'], 'tab' => 'properties')), 'icon' => 'box');
        if ($event['method_id'] > 0) $links[] = array('label' => 'Метод', 'url' => $root . http_build_query(array('action' => 'methods', 'md' => 'methods', 'view_mode' => 'edit_methods', 'id' => $event['method_id'])), 'icon' => 'code');
        if ($event['script_id'] > 0) $links[] = array('label' => 'Сценарий', 'url' => $root . http_build_query(array('action' => 'scripts', 'md' => 'scripts', 'view_mode' => 'edit_scripts', 'id' => $event['script_id'])), 'icon' => 'player-play');
        if ($event['module'] !== '' && preg_match('/^[a-zA-Z0-9_]+$/D', $event['module'])) $links[] = array('label' => 'Модуль', 'url' => ROOTHTML . 'panel/' . $event['module'] . '.html', 'icon' => 'puzzle');
        return $links;
    }

    private static function posted(string $key): string
    {
        $value = $_POST[$key] ?? '';
        if (!is_string($value)) throw new InvalidArgumentException('Некорректное поле формы.');
        return $value;
    }

    private static function query(string $key): string
    {
        return isset($_GET[$key]) && is_string($_GET[$key]) ? $_GET[$key] : '';
    }

    public function delete_jobs($id) { return Core::scheduler()->cancel((int)$id); }

    public function install($parent_name = '')
    {
        parent::install($parent_name);
        Core::database()->requireExec("UPDATE project_modules SET HIDDEN=1 WHERE NAME='jobs'");
    }

    public function uninstall()
    {
        // Таблица принадлежит планировщику ядра и нужна setTimeOut() без панели jobs.
        parent::uninstall();
    }

    public function dbInstall($data) { parent::dbInstall(''); }
}
