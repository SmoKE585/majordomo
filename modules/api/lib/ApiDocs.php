<?php

/**
 * Presentation helpers for the API admin page.
 *
 * The operation catalogue and its OpenAPI schemas stay in Core\Api\Contract;
 * this class only turns them into readable tables, examples and labels.
 */
final class ApiDocs
{
    /** Example values for path placeholders, used by parameter tables and cURL. */
    private const PATH_EXAMPLES = array(
        'class' => 'Computer',
        'target' => 'ThisComputer.SomebodyHome',
        'property' => 'ThisComputer.SomebodyHome',
        'script' => '1',
        'event' => 'my_event',
        'id' => '1',
        'module' => 'telegram',
    );

    public static function groupIcon(string $tag): string
    {
        $icons = array(
            'Система' => 'settings',
            'Объекты' => 'box',
            'Свойства' => 'list-details',
            'История' => 'history',
            'Выполнение' => 'player-play',
            'События' => 'bolt',
            'Сообщения' => 'message-2',
            'Помещения' => 'door',
            'Модули' => 'puzzle',
        );
        return $icons[$tag] ?? 'api';
    }

    public static function methodTone(string $method): string
    {
        return array(
            'GET' => 'success',
            'POST' => 'primary',
            'PUT' => 'warning',
            'PATCH' => 'info',
            'DELETE' => 'danger',
        )[$method] ?? 'neutral';
    }

    public static function pathExample(string $path): string
    {
        return (string)preg_replace_callback('/\{(\w+)\}/', static function (array $match): string {
            return self::PATH_EXAMPLES[$match[1]] ?? $match[0];
        }, $path);
    }

    public static function parameterExample(array $parameter): string
    {
        $name = (string)($parameter['name'] ?? '');
        if ($name === '') {
            return '';
        }
        $value = self::pathExample('{' . $name . '}');
        return $value === '{' . $name . '}' ? 'value' : $value;
    }

    public static function parameterDescription(array $parameter): string
    {
        $name = (string)($parameter['name'] ?? '');
        $descriptions = array(
            'period' => 'Период истории: часы числом либо day, 7day, week, 2week, month, year.',
            'aggregate' => 'Агрегат: max, min, count, sum или avg. Без значения — сырые точки.',
            'limit' => 'Сколько последних сообщений вернуть: от 1 до 1000.',
        );
        if (isset($descriptions[$name])) {
            return $descriptions[$name];
        }
        $description = trim((string)($parameter['description'] ?? ''));
        if ($description !== '') {
            return $description;
        }
        return (($parameter['in'] ?? '') === 'query') ? 'Параметр query-строки.' : 'Сегмент пути.';
    }

    public static function queryString(array $parameters): string
    {
        $pairs = array();
        foreach ($parameters as $parameter) {
            if (($parameter['in'] ?? '') !== 'query') {
                continue;
            }
            $pairs[] = (string)$parameter['name'] . '=' . rawurlencode(self::parameterExample($parameter));
        }
        return $pairs === array() ? '' : '?' . implode('&', $pairs);
    }

    public static function curl(array $operation, array $parameters, string $base): string
    {
        $url = 'https://YOUR_HOST' . $base . self::pathExample((string)$operation['path']) . self::queryString($parameters);
        $command = 'curl -X ' . $operation['method'] . ' "' . $url . '"' . " \\\n"
            . '  -H "Accept: application/json"' . " \\\n"
            . '  -H "Authorization: Bearer YOUR_TOKEN"';
        if ($operation['body'] !== null) {
            $body = json_encode($operation['example'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($body === false) {
                $body = '{}';
            }
            $command .= " \\\n  -H \"Content-Type: application/json\" \\\n  --data '" . $body . "'";
        }
        return $command;
    }

    public static function typeLabel(array|\stdClass $schema): string
    {
        $schema = (array)$schema;
        $type = (string)($schema['type'] ?? '');
        if ($type === 'array') {
            $items = isset($schema['items']) ? self::typeLabel($schema['items']) : 'any';
            return 'array<' . $items . '>';
        }
        if ($type === 'object') {
            if (($schema['additionalProperties'] ?? false) === true && empty($schema['properties'])) {
                return 'object · свободная форма';
            }
            return 'object';
        }
        if ($type === '') {
            return 'any';
        }
        if (isset($schema['enum']) && is_array($schema['enum'])) {
            return $type . ' · enum';
        }
        return $type;
    }

    public static function constraints(array|\stdClass $schema): string
    {
        $schema = (array)$schema;
        $parts = array();
        if (isset($schema['enum']) && is_array($schema['enum'])) {
            $parts[] = 'одно из: ' . implode(', ', array_map('strval', $schema['enum']));
        }
        $minLength = $schema['minLength'] ?? null;
        $maxLength = $schema['maxLength'] ?? null;
        if ($minLength !== null || $maxLength !== null) {
            if ($minLength !== null && $maxLength !== null) {
                $parts[] = (int)$minLength . '–' . (int)$maxLength . ' символов';
            } elseif ($maxLength !== null) {
                $parts[] = 'до ' . (int)$maxLength . ' символов';
            } else {
                $parts[] = 'от ' . (int)$minLength . ' символов';
            }
        }
        $minimum = $schema['minimum'] ?? null;
        $maximum = $schema['maximum'] ?? null;
        if ($minimum !== null || $maximum !== null) {
            if ($minimum !== null && $maximum !== null) {
                $parts[] = (int)$minimum . '–' . (int)$maximum;
            } elseif ($maximum !== null) {
                $parts[] = '≤ ' . (int)$maximum;
            } else {
                $parts[] = '≥ ' . (int)$minimum;
            }
        }
        $minItems = $schema['minItems'] ?? null;
        $maxItems = $schema['maxItems'] ?? null;
        if ($minItems !== null || $maxItems !== null) {
            if ($minItems !== null && $maxItems !== null) {
                $parts[] = (int)$minItems . '–' . (int)$maxItems . ' элементов';
            } elseif ($maxItems !== null) {
                $parts[] = 'до ' . (int)$maxItems . ' элементов';
            } else {
                $parts[] = 'от ' . (int)$minItems . ' элементов';
            }
        }
        if (array_key_exists('default', $schema)) {
            $default = $schema['default'];
            $parts[] = 'по умолчанию ' . (is_scalar($default) ? (string)$default : (string)json_encode($default, JSON_UNESCAPED_UNICODE));
        }
        if (($schema['additionalProperties'] ?? false) === true) {
            $parts[] = 'произвольные ключи';
        }
        return implode(' · ', $parts);
    }

    /**
     * Readable rows for an object schema. Arrays are unwrapped to their item
     * schema, so "array of rooms" shows the room fields once.
     *
     * @return array<int, array{name:string,type:string,required:bool,example:string,description:string}>
     */
    public static function fields(array|\stdClass $schema, mixed $example = array()): array
    {
        $schema = (array)$schema;
        if (($schema['type'] ?? '') === 'array' && isset($schema['items'])) {
            $schema = (array)$schema['items'];
            $example = (is_array($example) && isset($example[0]) && is_array($example[0])) ? $example[0] : array();
        }
        $example = is_array($example) ? $example : array();
        $properties = $schema['properties'] ?? array();
        if (!is_array($properties) || $properties === array()) {
            return array();
        }
        $required = isset($schema['required']) && is_array($schema['required']) ? $schema['required'] : array();
        $rows = array();
        foreach ($properties as $name => $property) {
            $property = (array)$property;
            $value = $example[$name] ?? null;
            $description = trim((string)($property['description'] ?? ''));
            $rows[] = array(
                'name' => (string)$name,
                'type' => self::typeLabel($property),
                'required' => in_array($name, $required, true),
                'example' => self::exampleLabel($value),
                'description' => $description !== '' ? $description : self::constraints($property),
            );
        }
        return $rows;
    }

    /** A compact, valid sample value for a schema; used for response tables. */
    public static function sample(array|\stdClass $schema): mixed
    {
        $schema = (array)$schema;
        $type = (string)($schema['type'] ?? '');
        if ($type === 'object') {
            $properties = $schema['properties'] ?? array();
            if (!is_array($properties) || $properties === array()) {
                return new \stdClass();
            }
            $value = array();
            foreach ($properties as $name => $property) {
                $value[$name] = self::sample($property);
            }
            return $value;
        }
        if ($type === 'array') {
            return isset($schema['items']) ? array(self::sample($schema['items'])) : array();
        }
        if ($type === 'string') {
            return isset($schema['enum'][0]) ? (string)$schema['enum'][0] : 'string';
        }
        if ($type === 'integer') {
            return 1;
        }
        if ($type === 'number') {
            return 1.0;
        }
        if ($type === 'boolean') {
            return true;
        }
        return new \stdClass();
    }

    /** Everything the client-side filter may match, lower-cased once. */
    public static function searchText(array $operation, array $entry): string
    {
        $parts = array(
            (string)$operation['method'],
            (string)$operation['path'],
            (string)$operation['summary'],
            (string)$operation['tag'],
            (string)($entry['description'] ?? ''),
        );
        foreach ($entry['parameters'] ?? array() as $parameter) {
            $parts[] = (string)($parameter['name'] ?? '') . ' ' . self::parameterDescription($parameter);
        }
        foreach (self::fields($operation['body'] ?? array(), $operation['example'] ?? array()) as $field) {
            $parts[] = $field['name'] . ' ' . $field['description'];
        }
        return mb_strtolower(implode(' ', array_filter($parts, static fn($part) => $part !== '')), 'UTF-8');
    }

    public static function tokenStats(array $tokens): array
    {
        $now = time();
        $stats = array('total' => 0, 'active' => 0, 'expired' => 0, 'revoked' => 0, 'unlimited' => 0);
        foreach ($tokens as $row) {
            $stats['total']++;
            if ($row['REVOKED_AT'] !== null) {
                $stats['revoked']++;
                continue;
            }
            if ($row['EXPIRES_AT'] === null) {
                $stats['unlimited']++;
            }
            if ($row['EXPIRES_AT'] !== null && (int)$row['EXPIRES_AT'] <= $now) {
                $stats['expired']++;
            } else {
                $stats['active']++;
            }
        }
        return $stats;
    }

    public static function timezoneLabel(): string
    {
        $timezone = date_default_timezone_get();
        try {
            $offset = (new \DateTimeZone($timezone))->getOffset(new \DateTimeImmutable());
        } catch (\Throwable $error) {
            return $timezone;
        }
        $sign = $offset < 0 ? '-' : '+';
        $offset = abs($offset);
        return $timezone . ' (UTC' . $sign . sprintf('%02d:%02d', intdiv($offset, 3600), intdiv($offset % 3600, 60)) . ')';
    }

    private static function exampleLabel(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_scalar($value)) {
            return (string)$value;
        }
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return '';
        }
        return mb_strimwidth($json, 0, 90, '…', 'UTF-8');
    }
}
