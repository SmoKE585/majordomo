<?php

namespace MajorDoMo\Core\Api;

use MajorDoMo\Core\{Core, Database};

/**
 * Данные и действия панели администрирования.
 * 2026-10-25 — список и явное прочтение через общий сервис уведомлений.
 *
 * 2026-10-22 — служебный AJAX панели переведён на /api/v1/panel: дашборд,
 * поиск, консоль, уведомления, билет WebSocket и запросы X-Ray. Маршруты
 * доступны только в сеансе администратора (проверка в Dispatcher).
 * 2026-10-23 — билет WebSocket выпускается по фактическому каталогу сессий,
 * а его ошибка возвращается как 503 с причиной вместо пустого 500.
 */
final class Panel
{
    public function __construct(private Database $db) {}

    public function dashboard(bool $fresh = false): array
    {
        require_once ROOT . 'lib/dashboard_data.php';
        return mdj_dashboard_collect($fresh);
    }

    public function search(array $params): array
    {
        $raw = $params['q'] ?? $params['title'] ?? '';
        if (!is_scalar($raw)) {
            throw new ApiException(422, 'validation_failed', 'Поисковый запрос должен быть строкой.');
        }
        $limit = $params['limit'] ?? 60;
        if (!is_scalar($limit)) {
            throw new ApiException(422, 'validation_failed', 'limit должен быть числом.');
        }
        return (new PanelSearch())->search((string)$raw, (int)$limit);
    }

    public function console(array $params): array
    {
        $command = $params['command'] ?? null;
        if (!is_string($command) || trim($command) === '') {
            throw new ApiException(422, 'validation_failed', 'Передайте код для выполнения.');
        }
        if (mb_strlen($command) > 65536) {
            throw new ApiException(422, 'validation_failed', 'Код слишком длинный.');
        }
        return array('output' => (new PanelConsole())->run($command));
    }

    /**
     * Запуск выбранного метода из формы объекта с перехватом echo и исключений.
     * 2026-11-16 — заменена удалённая GET-ссылка /objects/ на вызов панели.
     */
    public function runMethod(array $params): array
    {
        $objectId = filter_var($params['object_id'] ?? null, FILTER_VALIDATE_INT);
        $methodId = filter_var($params['method_id'] ?? null, FILTER_VALIDATE_INT);
        if ($objectId === false || (int)$objectId < 1 || $methodId === false || (int)$methodId < 1) {
            throw new ApiException(422, 'validation_failed', 'Некорректный объект или метод.');
        }

        $method = $this->db->one('SELECT ID,TITLE,CLASS_ID,OBJECT_ID FROM methods WHERE ID=' . (int)$methodId);
        $objectRow = $this->db->one('SELECT ID,TITLE FROM objects WHERE ID=' . (int)$objectId);
        if (!$method || !$objectRow) {
            throw new ApiException(404, 'not_found', 'Объект или метод не найден.');
        }
        $object = Core::objects()->get((string)$objectRow['TITLE']);
        if (!$object) {
            throw new ApiException(404, 'not_found', 'Объект не найден.');
        }
        $resolvedMethodId = Core::methods()->resolve($object, (string)$method['TITLE'], $object->class_id, $object->id);
        $methodIsInClassTree = false;
        $classId = (int)$object->class_id;
        $visited = array();
        while ($classId > 0 && !isset($visited[$classId])) {
            if ((int)$method['CLASS_ID'] === $classId) {
                $methodIsInClassTree = true;
                break;
            }
            $visited[$classId] = true;
            $classRow = $this->db->one('SELECT PARENT_ID FROM classes WHERE ID=' . $classId);
            $classId = (int)($classRow['PARENT_ID'] ?? 0);
        }
        $methodIsObjectOverride = (int)$method['OBJECT_ID'] === (int)$object->id;
        if ((int)$resolvedMethodId < 1 || (!$methodIsObjectOverride && !$methodIsInClassTree)) {
            throw new ApiException(422, 'method_mismatch', 'Выбранный метод не принадлежит этому объекту.');
        }

        $startedAt = hrtime(true);
        $bufferLevel = ob_get_level();
        ob_start();
        $result = null;
        $error = null;
        try {
            $result = Core::objects()->call((string)$objectRow['TITLE'] . '.' . (string)$method['TITLE'], array(), false);
        } catch (\Throwable $exception) {
            $error = array(
                'class' => get_class($exception),
                'message' => $exception->getMessage(),
            );
        } finally {
            $output = '';
            while (ob_get_level() > $bufferLevel) {
                $chunk = ob_get_clean();
                if (is_string($chunk)) $output = $chunk . $output;
            }
        }

        $outputLimit = 65536;
        $returnValue = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR, 32);
        if ($returnValue === false) $returnValue = '[не удалось представить возвращаемое значение]';
        if (strlen($returnValue) > $outputLimit) $returnValue = substr($returnValue, 0, $outputLimit) . '… [обрезано]';
        $truncated = strlen($output) > $outputLimit;
        if ($truncated) $output = substr($output, 0, $outputLimit) . "\n… [вывод обрезан]";

        return array(
            'ok' => $error === null,
            'object' => (string)$objectRow['TITLE'],
            'method' => (string)$method['TITLE'],
            'output' => $output,
            'result' => $returnValue,
            'error' => $error,
            'duration_ms' => round((hrtime(true) - $startedAt) / 1e6, 2),
            'output_truncated' => $truncated,
        );
    }

    public function websocketTicket(): array
    {
        $session = Core::session(ROOT);
        $user = (new \MajorDoMo\Core\Auth\Authentication($session, Core::users()))->current();
        if ($user === null) {
            throw new ApiException(401, 'authentication_required', 'Необходимо войти в систему.');
        }
        // Каталог берём фактический, а не предполагаемый: хостинг может
        // навязывать свой session.save_path. Иначе билет не находится и
        // эндпоинт отвечал 500 без внятной причины.
        try {
            $ticket = (new \MajorDoMo\Core\WebSockets\Tickets(rtrim(ROOT, '/\\'), \MajorDoMo\Core\Http\Session::effectiveDirectory()))
                ->issue($user, session_id(), (int)($_SESSION['md_admin']['created'] ?? 0), null, (string)($_SERVER['HTTP_HOST'] ?? ''));
        } catch (\RuntimeException $error) {
            error_log('MajorDoMo WebSocket ticket: ' . $error->getMessage());
            throw new ApiException(503, 'websocket_ticket_unavailable', 'Билет WebSocket не выдан: ' . $error->getMessage());
        }
        return array('ticket' => $ticket);
    }

    public function dismiss(array $params): array
    {
        if (isset($params['id'])) {
            $id = filter_var($params['id'], FILTER_VALIDATE_INT);
            if ($id === false || (int)$id < 1) {
                throw new ApiException(422, 'validation_failed', 'Некорректный ID уведомления.');
            }
            return array('dismissed' => (new \MajorDoMo\Core\ModuleNotifications($this->db))->dismiss((int)$id));
        }

        $module = $params['module'] ?? $params['module_name'] ?? null;
        if (!is_string($module) || trim($module) === '' || mb_strlen($module) > 255) {
            throw new ApiException(422, 'validation_failed', 'Передайте ID уведомления или имя модуля.');
        }
        return array('dismissed' => (new \MajorDoMo\Core\ModuleNotifications($this->db))->dismiss(null, $module));
    }

    public function notifications(array $params): array
    {
        $module = $params['module'] ?? '';
        $offset = $params['offset'] ?? 0;
        if (!is_string($module) || mb_strlen($module) > 50 || filter_var($offset, FILTER_VALIDATE_INT) === false || (int)$offset < 0) {
            throw new ApiException(422, 'validation_failed', 'Некорректный модуль или смещение списка уведомлений.');
        }
        return (new \MajorDoMo\Core\ModuleNotifications($this->db))->listing($module, (int)$offset);
    }

    public function xrayContent(array $params): array
    {
        foreach (array('view', 'mode', 'filter', 'limit') as $key) {
            if (isset($params[$key]) && !is_scalar($params[$key])) {
                throw new ApiException(422, 'validation_failed', 'Некорректный параметр ' . $key . '.');
            }
        }
        $view = isset($params['view']) ? (string)$params['view'] : '';
        if ($view === 'logs') {
            $view = '';
        }
        if (!in_array($view, array('', 'properties', 'methods', 'scripts', 'performance', 'timers', 'dead', 'events', 'database', 'services'), true)) {
            throw new ApiException(422, 'validation_failed', 'Неизвестный режим X-Ray.');
        }
        $mode = (string)($params['mode'] ?? '');
        if ($mode !== '' && !in_array($mode, array('chart', 'showdbload'), true)) {
            throw new ApiException(422, 'validation_failed', 'Неизвестный режим отображения X-Ray.');
        }
        $filter = isset($params['filter']) ? (string)$params['filter'] : '';
        if (mb_strlen($filter) > 255) {
            throw new ApiException(422, 'validation_failed', 'Фильтр слишком длинный.');
        }
        $limit = isset($params['limit']) ? (int)$params['limit'] : 0;
        if ($limit < 0 || $limit > 1000) {
            throw new ApiException(422, 'validation_failed', 'limit должен быть от 0 до 1000.');
        }
        $files = $this->fileList($params['files'] ?? array());

        return $this->xrayCall(fn() => $this->xray()->panelContent(array(
            'view' => $view,
            'mode' => $mode,
            'filter' => $filter,
            'limit' => $limit,
            'files' => $files,
        )));
    }

    public function xrayService(string $cycle): array
    {
        return $this->xrayCall(fn() => $this->xray()->panelService($cycle));
    }

    public function xrayCommand(string $cycle, string $command): array
    {
        if (!in_array($command, array('start', 'stop', 'restart', 'disable', 'enable'), true)) {
            throw new ApiException(422, 'validation_failed', 'Неизвестная команда цикла.');
        }
        return $this->xrayCall(fn() => $this->xray()->panelService($cycle, $command));
    }

    public function xrayCycleLog(string $cycle): array
    {
        return $this->xrayCall(fn() => $this->xray()->panelCycleLog($cycle));
    }

    public function xrayClearCycleLog(string $cycle): array
    {
        return $this->xrayCall(fn() => $this->xray()->panelClearCycleLog($cycle));
    }

    public function xrayClearLogs(array $params): array
    {
        $files = $this->fileList($params['files'] ?? array());
        if ($files === array()) {
            throw new ApiException(422, 'validation_failed', 'Выберите хотя бы один лог-файл.');
        }
        return $this->xrayCall(fn() => $this->xray()->panelClearLogs($files));
    }

    public function xrayDatabase(string $table, string $action): array
    {
        return $this->xrayCall(fn() => $this->xray()->panelDatabase($table, $action));
    }

    public function xrayStopTimer(string $timer): array
    {
        return $this->xrayCall(fn() => $this->xray()->panelStopTimer($timer));
    }

    public function updates(bool $force = false): array
    {
        require_once rtrim(DIR_MODULES, '/\\') . '/saverestore/saverestore.class.php';
        if (!class_exists('saverestore', false)) {
            throw new ApiException(503, 'unavailable', 'Модуль обновления системы недоступен.');
        }
        return (new \saverestore())->getUpdateStatus($force);
    }

    public function propertyHistory(array $route, array $params): array
    {
        [$objectId, $propertyId, $range, $page] = $this->propertyQuery($route, $params);
        $module = $this->objects();
        return $module->buildPropertyHistoryResponse($objectId, $propertyId, $range, $page);
    }

    public function propertyTrace(array $route, array $params): array
    {
        [$objectId, $propertyId, $range, $page] = $this->propertyQuery($route, $params);
        $module = $this->objects();
        return $module->buildPropertyTraceResponse($objectId, $propertyId, $range, $page);
    }

    private function xray(): object
    {
        require_once rtrim(DIR_MODULES, '/\\') . '/xray/xray.class.php';
        if (!class_exists('xray', false)) {
            throw new ApiException(503, 'unavailable', 'Модуль X-Ray недоступен.');
        }
        return new \xray();
    }

    private function objects(): object
    {
        require_once rtrim(DIR_MODULES, '/\\') . '/objects/objects.class.php';
        if (!class_exists('objects', false)) {
            throw new ApiException(503, 'unavailable', 'Модуль объектов недоступен.');
        }
        return new \objects();
    }

    private function propertyQuery(array $route, array $params): array
    {
        [$objectId, $propertyId] = $this->propertyIds($route);
        $range = $params['range'] ?? '';
        $page = $params['page'] ?? 1;
        if (!is_scalar($range) || !is_scalar($page)) {
            throw new ApiException(422, 'validation_failed', 'Некорректные параметры истории.');
        }
        return array($objectId, $propertyId, (string)$range, max(1, (int)$page));
    }

    private function propertyIds(array $route): array
    {
        $objectId = filter_var($route['object'] ?? null, FILTER_VALIDATE_INT);
        $propertyId = filter_var($route['property'] ?? null, FILTER_VALIDATE_INT);
        if ($objectId === false || $propertyId === false || (int)$objectId < 1 || (int)$propertyId < 1) {
            throw new ApiException(422, 'validation_failed', 'Некорректные ID объекта или свойства.');
        }
        return array((int)$objectId, (int)$propertyId);
    }

    private function xrayCall(callable $callback): array
    {
        try {
            return $callback();
        } catch (\InvalidArgumentException $error) {
            throw new ApiException(422, 'validation_failed', $error->getMessage());
        }
    }

    private function fileList(mixed $files): array
    {
        if (is_string($files)) {
            $files = array($files);
        }
        if (!is_array($files)) {
            return array();
        }
        $result = array();
        foreach ($files as $file) {
            if (!is_string($file)) {
                continue;
            }
            $file = trim($file);
            if ($file === '' || mb_strlen($file) > 512) {
                continue;
            }
            $result[] = $file;
            if (count($result) >= 200) {
                break;
            }
        }
        return $result;
    }
}
