<?php

namespace MajorDoMo\Core\Api;

/** The same operation catalogue drives routing, validation, UI and OpenAPI. */
final class Contract
{
    public static function operations(): array
    {
        $string = array('type' => 'string', 'minLength' => 1, 'maxLength' => 255);
        $room = array('type' => 'object', 'required' => array('title'), 'additionalProperties' => false, 'properties' => array(
            'title' => $string, 'priority' => array('type' => 'integer'), 'roomIcon' => array('type' => 'string', 'maxLength' => 255),
        ));
        $params = array('type' => 'object', 'additionalProperties' => true);
        $definitions = array(
            array('GET', '/openapi.json', 'spec', 'Спецификация OpenAPI', 'Система', null, null, array()),
            array('GET', '/objects', 'objects', 'Все объекты', 'Объекты', null, 'objects', array()),
            array('GET', '/objects/{class}', 'objects', 'Объекты класса со значениями свойств', 'Объекты', null, 'objects', array()),
            array('GET', '/data/{target}', 'dataGet', 'Значение свойства или свойства объекта', 'Свойства', null, 'data', array()),
            array('POST', '/data', 'dataBulk', 'Пакетное чтение свойств и объектов', 'Свойства', array('type' => 'object', 'required' => array('properties'), 'additionalProperties' => false, 'properties' => array('properties' => array('type' => 'array', 'minItems' => 1, 'maxItems' => 1000, 'items' => $string))), 'data', array('properties' => array('ThisComputer.SomebodyHome'))),
            array('POST', '/data/{target}', 'dataSet', 'Изменить значение свойства', 'Свойства', array('type' => 'object', 'required' => array('data'), 'additionalProperties' => false, 'properties' => array('data' => new \stdClass())), null, array('data' => 1)),
            array('GET', '/history/{property}', 'history', 'История и агрегаты свойства', 'История', null, 'result', array()),
            array('POST', '/method/{target}', 'method', 'Вызвать метод объекта', 'Выполнение', $params, 'result', new \stdClass()),
            array('POST', '/script/{script}', 'script', 'Выполнить сценарий по ID или названию', 'Выполнение', $params, 'result', new \stdClass()),
            array('POST', '/events/{event}', 'event', 'Зарегистрировать событие', 'События', $params, null, array('source' => 'API')),
            array('GET', '/messages', 'messages', 'Журнал сообщений и пользователь', 'Сообщения', null, null, array()),
            array('POST', '/messages', 'messages', 'Отправить сообщение', 'Сообщения', array('type' => 'object', 'required' => array('message'), 'additionalProperties' => false, 'properties' => array('message' => array('type' => 'string', 'minLength' => 1, 'maxLength' => 10000))), 'result', array('message' => 'Проверка API')),
            array('GET', '/rooms', 'rooms', 'Список помещений', 'Помещения', null, 'rooms', array()),
            array('POST', '/rooms', 'roomSave', 'Создать помещение', 'Помещения', $room, null, array('title' => 'Кухня', 'priority' => 10)),
            array('POST', '/rooms/order', 'roomOrder', 'Изменить порядок помещений', 'Помещения', array('type' => 'object', 'required' => array('rooms'), 'additionalProperties' => false, 'properties' => array('rooms' => array('type' => 'array', 'minItems' => 1, 'maxItems' => 1000, 'items' => array('type' => 'object', 'required' => array('id', 'priority'), 'additionalProperties' => false, 'properties' => array('id' => array('type' => 'integer', 'minimum' => 1), 'priority' => array('type' => 'integer')))))), null, array('rooms' => array(array('id' => 1, 'priority' => 20)))),
            array('GET', '/rooms/{id}', 'roomGet', 'Помещение по ID', 'Помещения', null, 'room', array()),
            array('PUT', '/rooms/{id}', 'roomSave', 'Обновить помещение', 'Помещения', $room, null, array('title' => 'Кухня', 'priority' => 20)),
            array('DELETE', '/rooms/{id}', 'roomDelete', 'Удалить помещение и его привязки', 'Помещения', null, null, array()),
            array('POST', '/modulePropertySet/{module}', 'modulePropertySet', 'Вызвать обработчик связанного свойства', 'Модули', array('type' => 'object', 'required' => array('object', 'property', 'value'), 'additionalProperties' => false, 'properties' => array('object' => $string, 'property' => $string, 'value' => new \stdClass())), null, array('object' => 'ThisComputer', 'property' => 'SomebodyHome', 'value' => 1)),
        );
        foreach (array('GET', 'POST', 'PUT', 'PATCH', 'DELETE') as $method) $definitions[] = array($method, '/module/{module}', 'module', 'API установленного модуля', 'Модули', in_array($method, array('POST', 'PUT', 'PATCH'), true) ? $params : null, 'apiHandleResult', new \stdClass());
        return array_map(static function ($d) {
            return array_combine(array('method', 'path', 'action', 'summary', 'tag', 'body', 'dataKey', 'example'), $d);
        }, $definitions);
    }

    public static function match(Request $request): array
    {
        $candidates = array(); $best = -1;
        foreach (self::operations() as $operation) {
            $pattern = explode('/', trim($operation['path'], '/'));
            $segments = $request->segments;
            if ($operation['action'] !== 'module' && count($pattern) !== count($segments)) continue;
            if ($operation['action'] === 'module' && count($segments) < 2) continue;
            $params = array(); $matches = true; $score = 0;
            foreach ($pattern as $i => $part) {
                if (!isset($segments[$i])) { $matches = false; break; }
                if (preg_match('/^\{(\w+)\}$/D', $part, $m)) $params[$m[1]] = $segments[$i];
                elseif ($segments[$i] !== $part) { $matches = false; break; }
                else $score++;
            }
            if (!$matches) continue;
            $params['request'] = array_slice($segments, 2);
            if ($score > $best) { $best = $score; $candidates = array(); }
            if ($score === $best) $candidates[] = array($operation, $params);
        }
        $allowed = array();
        foreach ($candidates as [$operation, $params]) {
            $allowed[] = $operation['method'];
            if ($request->method === $operation['method']) return array($operation, $params);
        }
        if ($allowed !== array()) throw new ApiException(405, 'method_not_allowed', 'HTTP-метод не поддерживается.', array(), array('Allow' => implode(', ', array_unique($allowed))));
        throw new ApiException(404, 'not_found', 'Маршрут не найден.');
    }

    public static function validate(mixed $value, array|\stdClass $schema, string $field = 'body'): void
    {
        $schema = (array)$schema;
        $type = $schema['type'] ?? '';
        $valid = match ($type) {
            'object' => is_array($value) && ($value === array() || !array_is_list($value)),
            'array' => is_array($value) && array_is_list($value),
            'string' => is_string($value), 'integer' => is_int($value), default => true,
        };
        if (!$valid) self::invalid($field, 'Некорректный тип поля.');
        if ($type === 'object') {
            foreach ($schema['required'] ?? array() as $key) if (!array_key_exists($key, $value)) self::invalid($field . '.' . $key, 'Обязательное поле.');
            foreach ($value as $key => $item) {
                if (isset($schema['properties'][$key])) self::validate($item, $schema['properties'][$key], $field . '.' . $key);
                elseif (($schema['additionalProperties'] ?? true) === false) self::invalid($field . '.' . $key, 'Неизвестное поле.');
            }
        } elseif ($type === 'array') {
            if (count($value) < ($schema['minItems'] ?? 0) || count($value) > ($schema['maxItems'] ?? PHP_INT_MAX)) self::invalid($field, 'Недопустимое количество элементов.');
            foreach ($value as $i => $item) self::validate($item, $schema['items'], $field . '.' . $i);
        } elseif ($type === 'string') {
            if (mb_strlen($value) < ($schema['minLength'] ?? 0) || mb_strlen($value) > ($schema['maxLength'] ?? PHP_INT_MAX) || preg_match('/[\x00-\x1f\x7f]/', $value)) self::invalid($field, 'Недопустимая длина или управляющие символы.');
        } elseif ($type === 'integer' && $value < ($schema['minimum'] ?? PHP_INT_MIN)) self::invalid($field, 'Значение меньше допустимого.');
    }

    private static function invalid(string $field, string $message): void { throw new ApiException(422, 'validation_failed', $message, array('field' => $field)); }

    public static function openApi(string $root = '/'): array
    {
        $spec = array('openapi' => '3.0.3', 'info' => array('title' => 'MajorDoMo Core API', 'version' => '1.0.0', 'description' => 'Локальные сети: доступ без токена. Внешний доступ: Bearer-токен или X-API-Key. Права токена распространяются на всё API. Запросы с телом — только JSON. Методы и сценарии выполняются синхронно; повтор запроса может повторить действие. Legacy URL /api/* и /api.php/* сохраняют прежний формат.'),
            'servers' => array(array('url' => rtrim($root, '/') . '/api/v1')),
            'security' => array(array('bearerAuth' => array()), array('apiKey' => array()), new \stdClass()),
            'components' => array('securitySchemes' => array('bearerAuth' => array('type' => 'http', 'scheme' => 'bearer'), 'apiKey' => array('type' => 'apiKey', 'in' => 'header', 'name' => 'X-API-Key')), 'schemas' => array(
                'Error' => array('type' => 'object', 'required' => array('ok', 'error', 'meta'), 'properties' => array('ok' => array('type' => 'boolean', 'enum' => array(false)), 'error' => array('type' => 'object', 'required' => array('code', 'message', 'details'), 'properties' => array('code' => array('type' => 'string'), 'message' => array('type' => 'string'), 'details' => array('type' => 'object'))), 'meta' => array('type' => 'object'))),
            )), 'paths' => array());
        foreach (self::operations() as $operation) {
            $parameters = array();
            preg_match_all('/\{(\w+)\}/', $operation['path'], $matches);
            foreach ($matches[1] as $name) $parameters[] = array('name' => $name, 'in' => 'path', 'required' => true, 'schema' => $name === 'id' ? array('type' => 'integer', 'minimum' => 1) : array('type' => 'string', 'minLength' => 1));
            if ($operation['action'] === 'history') {
                $parameters[] = array('name' => 'period', 'in' => 'query', 'schema' => array('type' => 'string', 'default' => 'day'), 'description' => 'Часы числом либо day, 7day, week, 2week, month, year. Максимум 10 лет.');
                $parameters[] = array('name' => 'aggregate', 'in' => 'query', 'schema' => array('type' => 'string', 'enum' => array('max', 'min', 'count', 'sum', 'avg')));
            }
            if ($operation['action'] === 'messages') $parameters[] = array('name' => 'limit', 'in' => 'query', 'schema' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 1000, 'default' => 50));
            $dataSchema = self::responseSchema($operation['action']);
            $entry = array('operationId' => $operation['action'] . '_' . strtolower($operation['method']) . '_' . substr(sha1($operation['path']), 0, 6), 'summary' => $operation['summary'], 'tags' => array($operation['tag']), 'parameters' => $parameters,
                'responses' => array('200' => array('description' => 'Успех. Полезная нагрузка находится в data; meta содержит request_id, timestamp и duration_ms.', 'content' => array('application/json' => array('schema' => array('type' => 'object', 'required' => array('ok', 'data', 'meta'), 'properties' => array('ok' => array('type' => 'boolean', 'enum' => array(true)), 'data' => $dataSchema, 'meta' => array('type' => 'object'))))))));
            foreach (array(400 => 'invalid_json / invalid_path', 401 => 'token_required / invalid_token', 403 => 'cross_origin_denied', 404 => 'not_found', 405 => 'method_not_allowed', 410 => 'gone', 413 => 'payload_too_large', 415 => 'unsupported_media_type', 422 => 'validation_failed', 500 => 'internal_error', 503 => 'unavailable') as $status => $description) $entry['responses'][(string)$status] = array('description' => $description, 'content' => array('application/json' => array('schema' => array('$ref' => '#/components/schemas/Error'))));
            if ($operation['body'] !== null) $entry['requestBody'] = array('required' => true, 'content' => array('application/json' => array('schema' => $operation['body'], 'example' => $operation['example'])));
            if ($operation['action'] === 'module') $entry['description'] = 'Совместимый вызов module::api($params). Дополнительные сегменты после имени модуля доступны в params.request. Query и JSON передаются модулю; результат определяется модулем. Схемы произвольного стороннего модуля ядру неизвестны.';
            $spec['paths'][$operation['path']][strtolower($operation['method'])] = $entry;
        }
        return $spec;
    }

    private static function responseSchema(string $action): array|\stdClass
    {
        $room = array('type' => 'object', 'properties' => array('id' => array('type' => 'integer'), 'title' => array('type' => 'string'), 'priority' => array('type' => 'integer'), 'object' => array('type' => 'string'), 'roomIcon' => array('type' => 'string')));
        return match ($action) {
            'objects' => array('type' => 'array', 'items' => array('type' => 'object', 'additionalProperties' => true)),
            'rooms' => array('type' => 'array', 'items' => $room), 'roomGet', 'roomSave' => $room,
            'roomOrder', 'roomDelete', 'dataSet', 'modulePropertySet' => array('type' => 'object', 'additionalProperties' => true),
            'event' => array('type' => 'object', 'properties' => array('event_id' => array('type' => 'integer'), 'event_name' => array('type' => 'string'), 'params' => array('type' => 'object'))),
            default => new \stdClass(),
        };
    }
}
