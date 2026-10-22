<?php

namespace MajorDoMo\Core\Modes;

use InvalidArgumentException;

/** Validation and condition evaluation; no application boot or side effects. */
final class Rules
{
    public static function validate(array $rules, string $ownName): array
    {
        if (count($rules) > 32) throw new InvalidArgumentException('Не более 32 правил на режим.');
        $result = array();
        foreach ($rules as $rule) {
            if (!is_array($rule)) throw new InvalidArgumentException('Некорректное правило.');
            $trigger = self::choice($rule['trigger'] ?? '', array('time', 'event', 'condition'));
            $target = self::choice(self::text($rule['target'] ?? '', 1), array('0', '1'));
            $match = self::choice($rule['match'] ?? 'all', array('all', 'any'));
            $delay = filter_var($rule['delay'] ?? 0, FILTER_VALIDATE_INT);
            if ($delay === false || $delay < 0 || $delay > 604800) throw new InvalidArgumentException('Задержка: от 0 до 604800 секунд.');
            $at = self::text($rule['at'] ?? '', 5);
            $event = self::text($rule['event'] ?? '', 100);
            if ($trigger === 'time' && !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $at)) throw new InvalidArgumentException('Укажите время в формате ЧЧ:ММ.');
            if ($trigger === 'event' && !preg_match('/^[A-Za-z][A-Za-z0-9_]{0,99}$/D', $event)) throw new InvalidArgumentException('Укажите имя события: латинские буквы, цифры и подчёркивание.');
            $days = $trigger === 'time' ? ($rule['days'] ?? array(1, 2, 3, 4, 5, 6, 7)) : array(1, 2, 3, 4, 5, 6, 7);
            if (!is_array($days) || !$days || count($days) > 7) throw new InvalidArgumentException('Выберите дни недели.');
            $days = array_values(array_unique(array_map(static fn($day) => (int)self::choice(self::text($day, 1), array('1','2','3','4','5','6','7')), $days)));
            $conditions = $rule['conditions'] ?? array();
            if (!is_array($conditions) || count($conditions) > 16 || ($trigger === 'condition' && !$conditions)) throw new InvalidArgumentException('Для правила по условию нужно от 1 до 16 условий.');
            $clean = array();
            foreach ($conditions as $condition) {
                if (!is_array($condition)) throw new InvalidArgumentException('Некорректное условие.');
                $type = self::choice($condition['type'] ?? '', array('property', 'mode', 'time'));
                if ($type === 'property') {
                    $property = self::text($condition['property'] ?? '', 255);
                    if (!preg_match('/^[^\s.]+\.[^\s.]+$/uD', $property)) throw new InvalidArgumentException('Свойство указывается как Объект.свойство.');
                    $clean[] = array('type' => $type, 'property' => $property,
                        'operator' => self::choice($condition['operator'] ?? 'eq', array('eq', 'ne', 'gt', 'gte', 'lt', 'lte')),
                        'value' => self::text($condition['value'] ?? '', 1000));
                } elseif ($type === 'mode') {
                    $name = self::text($condition['mode'] ?? '', 100);
                    if (!self::validName($name) || $name === $ownName) throw new InvalidArgumentException('Выберите другой режим для условия.');
                    $clean[] = array('type' => $type, 'mode' => $name,
                        'state' => (int)self::choice(self::text($condition['state'] ?? 1, 1), array('0', '1')));
                } else {
                    $start = self::text($condition['start'] ?? '', 5); $end = self::text($condition['end'] ?? '', 5);
                    foreach (array($start, $end) as $time) if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $time)) throw new InvalidArgumentException('Укажите начало и конец интервала ЧЧ:ММ.');
                    if ($start === $end) throw new InvalidArgumentException('Начало и конец интервала должны различаться.');
                    $clean[] = array('type' => $type, 'start' => $start, 'end' => $end);
                }
            }
            $result[] = array('trigger' => $trigger, 'target' => (int)$target, 'match' => $match,
                'delay' => $delay, 'at' => $at, 'days' => $days, 'event' => strtoupper($event), 'conditions' => $clean);
        }
        return $result;
    }

    public static function matches(array $rule, int $now, callable $property, callable $mode): bool
    {
        $results = array();
        foreach ($rule['conditions'] as $condition) {
            if ($condition['type'] === 'property') {
                $actual = $property($condition['property']);
                $results[] = self::compare($actual, $condition['value'], $condition['operator']);
            } elseif ($condition['type'] === 'mode') {
                $state = $mode($condition['mode']);
                $results[] = $state !== null && (int)$state === $condition['state'];
            } else {
                $time = date('H:i', $now); $start = $condition['start']; $end = $condition['end'];
                $results[] = $start < $end ? $time >= $start && $time < $end : $time >= $start || $time < $end;
            }
        }
        return !$results || ($rule['match'] === 'all' ? !in_array(false, $results, true) : in_array(true, $results, true));
    }

    private static function compare(mixed $actual, string $expected, string $operator): bool
    {
        // A missing value must not accidentally satisfy an off/empty condition.
        if ($actual === null || $actual === false || !is_scalar($actual)) return false;
        if ($operator === 'eq') return (string)$actual === $expected;
        if ($operator === 'ne') return (string)$actual !== $expected;
        if (!is_numeric($actual) || !is_numeric($expected)) return false;
        return match ($operator) {
            'gt' => (float)$actual > (float)$expected, 'gte' => (float)$actual >= (float)$expected,
            'lt' => (float)$actual < (float)$expected, 'lte' => (float)$actual <= (float)$expected,
        };
    }

    public static function validName(string $name): bool { return (bool)preg_match('/^[a-z][a-z0-9_]{0,99}$/D', $name); }

    public static function text(mixed $value, int $limit): string
    {
        if (!is_scalar($value) || strlen((string)$value) > $limit) throw new InvalidArgumentException('Некорректное или слишком длинное значение.');
        return trim((string)$value);
    }

    private static function choice(mixed $value, array $values): string
    {
        if (!is_string($value) || !in_array($value, $values, true)) throw new InvalidArgumentException('Недопустимое значение правила.');
        return $value;
    }
}
