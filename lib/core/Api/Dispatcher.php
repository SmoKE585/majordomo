<?php

namespace MajorDoMo\Core\Api;

use MajorDoMo\Core\{Core, Database};

/**
 * Обработчики API делегируют выполнение функциям и сервисам ядра.
 * Изменения: 2026-10-22 — сообщения и подписки доступны внешним клиентам v1.
 */
final class Dispatcher
{
    public function __construct(private Database $db) {}

    public function dispatch(Request $request): mixed
    {
        [$operation, $route] = $request->legacy ? $this->legacyRoute($request) : Contract::match($request);
        $params = $request->params();
        $action = $operation['action'];
        if (!$request->legacy && $operation['body'] !== null) {
            $input = in_array($action, array('say', 'sayReply', 'sayTo', 'subscriptions'), true) ? $params : $request->body;
            Contract::validate($input, $operation['body']);
        }
        $result = match ($action) {
            'spec' => Contract::openApi(defined('ROOTHTML') ? ROOTHTML : '/'),
            'objects' => $this->objects($route['class'] ?? null),
            'dataGet' => $this->dataGet($route['target'], $request->legacy),
            'dataSet' => $this->dataSet($route['target'], $params, $request->legacy),
            'dataBulk' => $this->dataBulk($params, $request->legacy),
            'history' => $this->history($route, $params),
            'method' => $this->method($route['target'], $params),
            'script' => $this->script($route['script'], $params),
            'say', 'sayReply', 'sayTo' => $this->speech($action, $params),
            'subscriptions' => array('result' => processSubscriptions($route['event'], $params['details'] ?? array(), $params['return_output'] ?? false)),
            'event' => $this->event($route['event'], $params),
            'rooms' => array('rooms' => array_map(fn($row) => $this->room($row), $this->db->select('SELECT * FROM locations ORDER BY PRIORITY DESC,TITLE'))),
            'roomGet' => array('room' => $this->room($this->location($route['id']))),
            'roomSave' => $this->roomSave($route, $params, $request->legacy),
            'roomOrder' => $this->roomOrder($request->legacy ? ($params['rooms'] ?? $request->body) : $params['rooms']),
            'roomDelete' => $this->roomDelete($route['id']),
            'module', 'modulePropertySet' => $this->module($action, $route, $params, $request),
            'messages' => $this->messages($request->method, $params),
            default => throw new ApiException(404, 'not_found', 'Маршрут не найден.'),
        };
        if ($request->legacy) return $result;
        if ($action === 'spec') return $result;
        $key = $operation['dataKey'];
        if ($key !== null) return array_key_exists($key, $result) ? $result[$key] : null;
        if ($action === 'roomSave') return $result['room'];
        unset($result['result']);
        return $result === array() ? new \stdClass() : $result;
    }

    private function legacyRoute(Request $request): array
    {
        $s = $request->segments; $resource = strtolower($s[0] ?? ''); $method = $request->method;
        $route = array(); $allowed = array(); $action = '';
        if ($resource === 'devices') throw new ApiException(410, 'gone', 'Devices module has been removed');
        if ($resource === 'objects' && count($s) <= 2) { $allowed = array('GET'); $action = 'objects'; $route = array('class' => $s[1] ?? null); }
        elseif ($resource === 'data' && count($s) === 1) { $allowed = array('POST'); $action = 'dataBulk'; }
        elseif ($resource === 'data' && count($s) === 2) { $allowed = array('GET', 'POST'); $action = $method === 'GET' ? 'dataGet' : 'dataSet'; $route['target'] = $s[1]; }
        elseif ($resource === 'history' && count($s) >= 2 && count($s) <= 4) { $allowed = array('GET'); $action = 'history'; $route = array('property' => $s[1], 'period' => $s[2] ?? 'day', 'aggregate' => $s[3] ?? ''); }
        elseif (in_array($resource, array('method', 'script'), true) && count($s) === 2) { $allowed = array('GET', 'POST'); $action = $resource; $route[$resource === 'method' ? 'target' : 'script'] = $s[1]; }
        elseif ($resource === 'events' && count($s) >= 2) { $allowed = array('GET', 'POST'); $action = 'event'; $route['event'] = implode('/', array_slice($s, 1)); }
        elseif ($resource === 'module' && count($s) >= 2) { $allowed = array('GET', 'POST', 'PUT', 'PATCH', 'DELETE'); $action = 'module'; $route = array('module' => $s[1], 'request' => array_slice($s, 2)); }
        elseif ($resource === 'modulepropertyset' && count($s) === 2) { $allowed = array('GET', 'POST'); $action = 'modulePropertySet'; $route['module'] = $s[1]; }
        elseif ($resource === 'rooms' && count($s) === 1) { $allowed = array('GET'); $action = 'rooms'; }
        elseif ($resource === 'rooms' && ($s[1] ?? '') === 'setOrder' && count($s) === 2) { $allowed = array('POST'); $action = 'roomOrder'; }
        elseif ($resource === 'rooms' && count($s) === 2) { $allowed = array('GET', 'POST', 'DELETE'); $action = match ($method) { 'GET' => 'roomGet', 'DELETE' => 'roomDelete', default => 'roomSave' }; $route['id'] = $s[1]; }
        elseif ($resource === 'room' && count($s) <= 2) { $allowed = isset($s[1]) ? array('DELETE') : array('POST'); $action = isset($s[1]) ? 'roomDelete' : 'roomSave'; if (isset($s[1])) $route['id'] = $s[1]; }
        elseif ($resource === 'messages' && count($s) === 1) { $allowed = array('GET', 'POST'); $action = 'messages'; }
        if ($allowed === array()) throw new ApiException(404, 'not_found', 'Маршрут не найден.');
        if (!in_array($method, $allowed, true)) throw new ApiException(405, 'method_not_allowed', 'HTTP-метод не поддерживается.', array(), array('Allow' => implode(', ', $allowed)));
        return array(array('action' => $action), $route);
    }

    private function objects(?string $class): array
    {
        if ($class === null) return array('objects' => $this->db->select('SELECT ID,TITLE,TITLE AS OBJECT FROM objects ORDER BY TITLE'));
        if (!$this->db->one("SELECT ID FROM classes WHERE TITLE='" . $this->db->escape($class) . "' OR ID='" . (int)$class . "'")) throw new ApiException(404, 'not_found', 'Класс не найден.');
        $objects = getObjectsByClass($class); $properties = getClassProperties($class);
        foreach ($objects as &$row) {
            $row['object'] = $row['TITLE']; $row['id'] = $row['ID']; unset($row['TITLE'], $row['ID']);
            foreach ($properties as $property) {
                $value = getGlobal($row['object'] . '.' . $property['TITLE']);
                $row[$property['TITLE']] = is_string($value) && str_contains($value, '<#LANG') ? processTitle($value) : $value;
            }
        }
        return array('objects' => $objects);
    }

    private function target(string $name, bool $requireProperty = false): array
    {
        $parts = explode('.', $name);
        if ($requireProperty && count($parts) < 2) throw new ApiException(422, 'validation_failed', 'Укажите Объект.Свойство.');
        $property = count($parts) > 1 ? array_pop($parts) : null;
        $objectName = implode('.', $parts);
        $object = Core::objects()->get($objectName);
        if (!$object) throw new ApiException(404, 'not_found', 'Объект не найден.');
        if ($property !== null && !in_array($property, array('object_title', 'object_description', 'object_id', 'class_title', 'location_title'), true)
            && !Core::properties()->resolve($object, $property, $object->class_id, $object->id)) throw new ApiException(404, 'not_found', 'Свойство не найдено.');
        return array($object, $property);
    }

    private function objectData(object $object): array
    {
        $props = getClassProperties($object->class_id);
        $props = array_merge($props, $this->db->select('SELECT TITLE FROM properties WHERE OBJECT_ID=' . (int)$object->id));
        $values = array();
        foreach ($props as $property) $values[$property['TITLE']] = $object->getProperty($property['TITLE']);
        return $values;
    }

    private function dataGet(string $name, bool $legacy): array
    {
        [$object, $property] = $this->target($name);
        $result = array('data' => $property !== null ? getGlobal($name) : $this->objectData($object));
        if ($property === null && $legacy) $result['object'] = $object;
        return $result;
    }

    private function dataSet(string $name, array $params, bool $legacy): array
    {
        if (!array_key_exists('data', $params)) throw new ApiException(422, 'validation_failed', 'Обязательное поле data.');
        if (!$legacy) $this->target($name, true);
        elseif (!str_contains($name, '.')) throw new ApiException(422, 'validation_failed', 'Укажите Объект.Свойство.');
        setGlobal($name, $params['data']);
        return array('result' => true);
    }

    private function dataBulk(array $params, bool $legacy): array
    {
        $properties = $params['properties'] ?? null;
        if (!is_array($properties) || count($properties) < 1 || count($properties) > 1000) throw new ApiException(422, 'validation_failed', 'Передайте от 1 до 1000 properties.');
        $data = array();
        foreach ($properties as $name) {
            if (!is_string($name) || $name === '') throw new ApiException(422, 'validation_failed', 'Название свойства должно быть строкой.');
            $result = $this->dataGet($name, $legacy);
            if (str_contains($name, '.')) $data[$name] = $result['data'];
            else foreach ($result['data'] as $property => $value) $data[$name . '.' . $property] = $value;
        }
        return array('data' => $data, 'result' => true);
    }

    public static function periodSeconds(string $period): int
    {
        if (preg_match('/^\d+(?:\.\d+)?$/D', $period)) $seconds = (float)$period * 3600;
        elseif (preg_match('/^(\d+)?(day|week|month|year)$/D', $period, $m)) $seconds = (float)($m[1] !== '' ? $m[1] : 1) * array('day' => 86400, 'week' => 604800, 'month' => 2592000, 'year' => 31536000)[$m[2]];
        else throw new ApiException(422, 'validation_failed', 'Некорректный период истории.');
        if ($seconds < 1 || $seconds > 315360000) throw new ApiException(422, 'validation_failed', 'Период истории: от секунды до 10 лет.');
        return (int)$seconds;
    }

    private function history(array $route, array $params): array
    {
        $period = $route['period'] ?? $params['period'] ?? 'day'; $aggregate = $route['aggregate'] ?? $params['aggregate'] ?? '';
        if (!is_scalar($period) || !is_string($aggregate)) throw new ApiException(422, 'validation_failed', 'Некорректные параметры истории.');
        $seconds = self::periodSeconds((string)$period);
        $functions = array('' => 'getHistory', 'max' => 'getHistoryMax', 'min' => 'getHistoryMin', 'count' => 'getHistoryCount', 'sum' => 'getHistorySum', 'avg' => 'getHistoryAvg');
        if (!isset($functions[$aggregate])) throw new ApiException(422, 'validation_failed', 'Неизвестный агрегат истории.');
        $this->target($route['property'], true);
        return array('result' => $functions[$aggregate]($route['property'], -$seconds));
    }

    private function method(string $target, array $params): array
    {
        $parts = explode('.', $target); $name = array_pop($parts); $objectName = $parts === array() ? 'ThisComputer' : implode('.', $parts);
        if ($objectName === 'AllScripts') return $this->script($name, $params);
        $object = Core::objects()->get($objectName);
        if (!$object || !Core::methods()->resolve($object, $name, $object->class_id, $object->id)) throw new ApiException(404, 'not_found', 'Метод не найден.');
        return array('result' => Core::objects()->call($target, $params) ?? 'OK');
    }

    private function script(string $name, array $params): array
    {
        $record = $this->db->one("SELECT ID FROM scripts WHERE " . (ctype_digit($name) ? 'ID=' . (int)$name : "TITLE='" . $this->db->escape($name) . "'"));
        if (!$record) throw new ApiException(404, 'not_found', 'Сценарий не найден.');
        return array('result' => runScript((int)$record['ID'], $params) ?? 'OK');
    }

    private function event(string $name, array $params): array
    {
        if (strlen($name) > 255) throw new ApiException(422, 'validation_failed', 'Название события слишком длинное.');
        return array('event_name' => $name, 'params' => $params, 'event_id' => registerEvent($name, $params), 'result' => true);
    }

    private function speech(string $action, array $params): array
    {
        $level = $params['level'] ?? 0;
        $result = match ($action) {
            'sayReply' => sayReply($params['message'], $level, $params['replyto'] ?? ''),
            'sayTo' => sayTo($params['message'], $level, $params['destination']),
            default => say($params['message'], $level, $params['member_id'] ?? 0, $params['source'] ?? 'API'),
        };
        return array('result' => $result);
    }

    private function location(string $id): array
    {
        if (!ctype_digit($id) || (int)$id < 1) throw new ApiException(422, 'validation_failed', 'ID помещения должен быть положительным целым числом.');
        $row = $this->db->one('SELECT * FROM locations WHERE ID=' . (int)$id);
        if (!$row) throw new ApiException(404, 'not_found', 'Помещение не найдено.');
        return $row;
    }

    private function room(array $row): array
    {
        $object = getRoomObjectByLocation($row['ID'], 1);
        return array('id' => (int)$row['ID'], 'title' => processTitle($row['TITLE']), 'priority' => (int)($row['PRIORITY'] ?? 0), 'object' => (string)$object, 'roomIcon' => $object ? (string)getGlobal($object . '.roomIcon') : '');
    }

    private function roomSave(array $route, array $params, bool $legacy): array
    {
        $title = $params['title'] ?? null;
        if (!is_string($title) || trim($title) === '' || mb_strlen($title) > 255) throw new ApiException(422, 'validation_failed', 'Введите название помещения: до 255 символов.');
        $id = $route['id'] ?? ($legacy ? (string)($params['id'] ?? '') : '');
        if ($id !== '' && $id !== 'new') $record = $this->location($id);
        else $record = array();
        $record['TITLE'] = $title;
        if (array_key_exists('priority', $params)) {
            if (filter_var($params['priority'], FILTER_VALIDATE_INT) === false) throw new ApiException(422, 'validation_failed', 'priority должен быть целым числом.');
            $record['PRIORITY'] = (int)$params['priority'];
        } elseif (empty($record['ID'])) $record['PRIORITY'] = (int)($this->db->one('SELECT MAX(PRIORITY) AS P FROM locations')['P'] ?? 0) + 10;
        if (isset($params['roomIcon']) && !is_string($params['roomIcon'])) throw new ApiException(422, 'validation_failed', 'roomIcon должен быть строкой.');
        if (empty($record['ID'])) {
            $record['ID'] = $this->db->insert('locations', $record);
            if (!$record['ID']) throw new \RuntimeException('Cannot create location');
        } elseif (!$this->db->update('locations', $record)) throw new \RuntimeException('Cannot update location');
        $room = $this->room($record);
        if (isset($params['roomIcon']) && $room['object']) {
            $room['roomIcon'] = $params['roomIcon'] === 'default' ? '' : $params['roomIcon'];
            setGlobal($room['object'] . '.roomIcon', $room['roomIcon']);
        }
        // Both historical room endpoints have consumers; retain their field names.
        return $room + array('room' => $room, 'result' => true);
    }

    private function roomOrder(mixed $rooms): array
    {
        if (!is_array($rooms) || !array_is_list($rooms) || count($rooms) < 1 || count($rooms) > 1000) throw new ApiException(422, 'validation_failed', 'Передайте список помещений.');
        $validated = array();
        foreach ($rooms as $row) {
            if (!is_array($row) || !isset($row['id'], $row['priority']) || filter_var($row['priority'], FILTER_VALIDATE_INT) === false || !is_scalar($row['id'])) throw new ApiException(422, 'validation_failed', 'Укажите id и целочисленный priority.');
            $record = $this->location((string)$row['id']);
            if (isset($validated[$record['ID']])) throw new ApiException(422, 'validation_failed', 'Повторяющийся ID помещения.');
            $validated[$record['ID']] = (int)$row['priority'];
        }
        $this->db->requireExec('START TRANSACTION');
        try {
            foreach ($validated as $id => $priority) $this->db->requireExec('UPDATE locations SET PRIORITY=' . $priority . ' WHERE ID=' . (int)$id, true);
            $this->db->requireExec('COMMIT', true);
        } catch (\Throwable $error) { $this->db->exec('ROLLBACK', true); throw $error; }
        return array('result' => true);
    }

    private function roomDelete(string $id): array
    {
        $record = $this->location($id);
        $this->db->requireExec('START TRANSACTION');
        try {
            $this->db->requireExec('UPDATE objects SET LOCATION_ID=0 WHERE LOCATION_ID=' . (int)$record['ID'], true);
            $this->db->requireExec('DELETE FROM locations WHERE ID=' . (int)$record['ID'], true);
            $this->db->requireExec('COMMIT', true);
        } catch (\Throwable $error) { $this->db->exec('ROLLBACK', true); throw $error; }
        return array('result' => true);
    }

    private function module(string $action, array $route, array $params, Request $request): array
    {
        $name = $route['module'];
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $name)) throw new ApiException(422, 'validation_failed', 'Некорректное имя модуля.');
        $base = rtrim(DIR_MODULES, '/\\') . '/'; $file = $base . $name . '/' . $name . '.class.php';
        if (!is_file($file)) foreach (scandir($base) as $candidate) {
            if (strcasecmp($candidate, $name) === 0) { $name = $candidate; $file = $base . $name . '/' . $name . '.class.php'; break; }
        }
        if (!is_file($file)) throw new ApiException(404, 'not_found', 'Модуль не найден.');
        require_once $file;
        $module = new $name(); $handler = $action === 'module' ? 'api' : 'propertySetHandle';
        if (!is_callable(array($module, $handler))) throw new ApiException(404, 'not_found', 'Модуль не поддерживает этот обработчик.');
        $params['request'] = $route['request'] ?? array();
        if ($action === 'modulePropertySet') {
            foreach (array('object', 'property') as $key) if (!isset($params[$key]) || !is_string($params[$key]) || $params[$key] === '') throw new ApiException(422, 'validation_failed', 'Обязательное поле ' . $key . '.');
            if (!array_key_exists('value', $params)) throw new ApiException(422, 'validation_failed', 'Обязательное поле value.');
        }
        // Legacy handlers read both $params and superglobals. Restore them even
        // when a handler throws, so nested internal calls cannot corrupt callers.
        $saved = array($_GET, $_POST, $_REQUEST, $_SERVER);
        $_GET = $params; $_POST = $request->method === 'GET' ? array() : $params; $_REQUEST = $params;
        $_SERVER['REQUEST_METHOD'] = $request->method;
        $_SERVER['REQUEST_URI'] = '/api/module/' . rawurlencode($name);
        $audit = Core::audit(); $owned = false; $step = null; $failure = null; $value = null;
        try {
            if ($action === 'modulePropertySet') {
                $owned = $audit->beginIfMissing('module', $name, $params);
                $step = $audit->stepStart('module', $name, array('module' => $name, 'subtitle' => 'Обработчик свойства', 'params' => $params));
                $value = $module->$handler($params['object'], $params['property'], $params['value']);
                return array('setHandleResult' => $value, 'result' => true);
            }
            return array('apiHandleResult' => $module->$handler($params));
        } catch (\Throwable $error) { $failure = $error; throw $error; }
        finally {
            if ($action === 'modulePropertySet') {
                $audit->stepFinish($step, $value, $failure);
                if ($owned) { $audit->finishRun($failure ? 'error' : 'done', $value, $failure); $audit->leave(); }
            }
            [$_GET, $_POST, $_REQUEST, $_SERVER] = $saved;
        }
    }

    private function messages(string $method, array $params): array
    {
        if ($method === 'POST') {
            $message = $params['message'] ?? null;
            if (!is_string($message) || trim($message) === '' || mb_strlen($message) > 10000) throw new ApiException(422, 'validation_failed', 'Введите сообщение: до 10000 символов.');
            $user = $this->db->one('SELECT ID FROM users ORDER BY ID LIMIT 1');
            if (!$user) throw new ApiException(422, 'validation_failed', 'Для сообщений создайте пользователя.');
            say(htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), 0, $user['ID'], 'API');
            return array('result' => 'OK');
        }
        $limit = $params['limit'] ?? 50;
        if (filter_var($limit, FILTER_VALIDATE_INT) === false || (int)$limit < 1 || (int)$limit > 1000) throw new ApiException(422, 'validation_failed', 'limit должен быть от 1 до 1000.');
        $rows = $this->db->select('SELECT shouts.ID,shouts.MEMBER_ID AS USER_ID,shouts.ADDED,shouts.MESSAGE,users.NAME FROM shouts LEFT JOIN users ON shouts.MEMBER_ID=users.ID ORDER BY shouts.ADDED DESC,shouts.ID DESC LIMIT ' . (int)$limit);
        foreach ($rows as &$row) {
            if (!$row['USER_ID']) $row['NAME'] = defined('SETTINGS_GENERAL_ALICE_NAME') && SETTINGS_GENERAL_ALICE_NAME !== '' ? SETTINGS_GENERAL_ALICE_NAME : 'System';
            elseif (!$row['NAME']) $row['NAME'] = 'User';
        }
        $user = $this->db->one('SELECT ID,NAME,USERNAME FROM users ORDER BY ID LIMIT 1');
        if (!$user) $user = array('ID' => '0', 'NAME' => 'System');
        elseif (!$user['NAME']) $user['NAME'] = $user['USERNAME'];
        return array('messages' => array_reverse($rows), 'user' => $user);
    }
}
