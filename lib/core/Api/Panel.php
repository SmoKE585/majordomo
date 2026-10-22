<?php

namespace MajorDoMo\Core\Api;

use MajorDoMo\Core\{Core, Database};

/**
 * Данные и действия панели администрирования.
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
                ->issue($user, session_id(), (int)($_SESSION['md_admin']['created'] ?? 0));
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
            $this->db->exec('UPDATE module_notifications SET IS_READ=1 WHERE ID=' . (int)$id);
            return array('dismissed' => $this->db->affectedRows());
        }

        $module = $params['module'] ?? $params['module_name'] ?? null;
        if (!is_string($module) || trim($module) === '' || mb_strlen($module) > 255) {
            throw new ApiException(422, 'validation_failed', 'Передайте ID уведомления или имя модуля.');
        }
        $this->db->exec("UPDATE module_notifications SET IS_READ=1 WHERE MODULE_NAME='" . $this->db->escape($module) . "' AND IS_READ=0");
        return array('dismissed' => $this->db->affectedRows());
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
