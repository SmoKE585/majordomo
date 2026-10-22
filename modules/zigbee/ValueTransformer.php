<?php

namespace MajorDoMo\Modules\Zigbee;

use InvalidArgumentException;

/** Declarative, typed conversion. Never evaluates user code. */
final class ValueTransformer
{
    public static function validate(array $rule): array
    {
        $rule += array('mode' => 'none', 'pairs' => array(), 'case_sensitive' => true,
            'factor' => 1, 'offset' => 0, 'precision' => null);
        if (!in_array($rule['mode'], array('none', 'binary', 'exposes', 'map', 'number', 'invert'), true)) {
            throw new InvalidArgumentException('Неизвестный режим преобразования.');
        }
        if (!is_array($rule['pairs']) || count($rule['pairs']) > 64) throw new InvalidArgumentException('Допустимо до 64 замен.');
        foreach ($rule['pairs'] as $pair) {
            if (!is_array($pair) || !array_key_exists('from', $pair) || !array_key_exists('to', $pair)
                || !(is_scalar($pair['from']) || $pair['from'] === null)
                || !(is_scalar($pair['to']) || $pair['to'] === null)) {
                throw new InvalidArgumentException('Замены должны содержать простые значения «из» и «в».');
            }
        }
        foreach (array('factor', 'offset') as $key) {
            if (!is_numeric($rule[$key]) || !is_finite((float)$rule[$key])) throw new InvalidArgumentException('Масштаб и смещение должны быть конечными числами.');
        }
        if ($rule['precision'] !== null && (filter_var($rule['precision'], FILTER_VALIDATE_INT) === false
            || (int)$rule['precision'] < 0 || (int)$rule['precision'] > 8)) {
            throw new InvalidArgumentException('Точность: от 0 до 8 знаков.');
        }
        return $rule;
    }

    public static function equal(mixed $a, mixed $b, bool $caseSensitive = true): bool
    {
        // PHP's loose comparison would confuse false, 0, "0" and an empty string.
        if (is_string($a) && is_string($b) && !$caseSensitive) return mb_strtolower($a) === mb_strtolower($b);
        if (is_numeric($a) && is_numeric($b) && !is_bool($a) && !is_bool($b)) return (float)$a === (float)$b;
        return $a === $b;
    }

    public static function apply(mixed $value, array $rule, array $expose = array(), bool $outgoing = false): mixed
    {
        $rule = self::validate($rule);
        switch ($rule['mode']) {
            case 'binary':
                if (is_bool($value)) return $outgoing ? ($value ? 'ON' : 'OFF') : (int)$value;
                $word = is_scalar($value) ? strtolower((string)$value) : '';
                if (in_array($word, array('on', 'true', 'yes', '1'), true)) return $outgoing ? 'ON' : 1;
                if (in_array($word, array('off', 'false', 'no', '0'), true)) return $outgoing ? 'OFF' : 0;
                throw new InvalidArgumentException('Значение не является ON/OFF, true/false или 1/0.');
            case 'exposes':
                if (!array_key_exists('value_on', $expose) || !array_key_exists('value_off', $expose)) {
                    throw new InvalidArgumentException('У свойства нет бинарного описания exposes.');
                }
                if ($outgoing) {
                    if ($value === true || self::equal($value, 1)) return $expose['value_on'];
                    if ($value === false || self::equal($value, 0)) return $expose['value_off'];
                } else {
                    if (self::equal($value, $expose['value_on'])) return 1;
                    if (self::equal($value, $expose['value_off'])) return 0;
                }
                throw new InvalidArgumentException('Значение не соответствует бинарному описанию устройства.');
            case 'map':
                foreach ($rule['pairs'] as $pair) {
                    if (self::equal($value, $pair['from'], (bool)$rule['case_sensitive'])) return $pair['to'];
                }
                return $value;
            case 'number':
                if (!is_numeric($value) || !is_finite((float)$value)) throw new InvalidArgumentException('Ожидалось число.');
                $number = (float)$value * (float)$rule['factor'] + (float)$rule['offset'];
                if (!is_finite($number)) throw new InvalidArgumentException('Результат преобразования выходит за пределы числа.');
                return $rule['precision'] === null ? $number : round($number, (int)$rule['precision']);
            case 'invert':
                return 1 - self::apply($value, array('mode' => 'binary'));
            default:
                return $value;
        }
    }

    public static function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    public static function decode(?string $value): mixed
    {
        return $value === null ? null : json_decode($value, true, 64, JSON_THROW_ON_ERROR);
    }
}
