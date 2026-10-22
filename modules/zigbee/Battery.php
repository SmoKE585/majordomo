<?php

namespace MajorDoMo\Modules\Zigbee;

use InvalidArgumentException;

final class Battery
{
    public static function validatePoints(array $points): void
    {
        if (count($points) < 2) throw new InvalidArgumentException('Нужно минимум две точки напряжение/процент.');
        $previous = null;
        foreach ($points as $point) {
            if (!is_array($point) || !self::number($point['voltage'] ?? null) || !self::number($point['percent'] ?? null)
                || $point['voltage'] <= 0 || $point['voltage'] > 60 || $point['percent'] < 0 || $point['percent'] > 100
                || ($previous !== null && ($point['voltage'] <= $previous['voltage'] || $point['percent'] < $previous['percent']))) {
                throw new InvalidArgumentException('Точки задаются по возрастанию напряжения (В) и процента (0–100).');
            }
            $previous = $point;
        }
    }

    public static function estimate(mixed $raw, array $settings): ?int
    {
        if ($settings['voltage_profile'] === 'none' || !self::number($raw) || (float)$raw <= 0) return null;
        $voltage = (float)$raw / ($settings['voltage_unit'] === 'mV' ? 1000 : 1);
        $points = match ($settings['voltage_profile']) {
            'linear3v' => array(array('voltage' => 2.1, 'percent' => 0), array('voltage' => 3.0, 'percent' => 100)),
            'alkaline' => array(array('voltage' => 0.9, 'percent' => 0), array('voltage' => 1.5, 'percent' => 100)),
            'custom' => $settings['voltage_points'],
            default => throw new InvalidArgumentException('Неизвестный профиль батареи.'),
        };
        self::validatePoints($points);
        // A wrong unit or mains voltage must not turn into a reassuring 100%.
        if ($voltage > end($points)['voltage'] * 1.3 || $voltage < $points[0]['voltage'] * 0.5) return null;
        if ($voltage <= $points[0]['voltage']) return (int)round($points[0]['percent']);
        foreach ($points as $index => $point) {
            if ($voltage > $point['voltage'] || $index === 0) continue;
            $from = $points[$index - 1];
            return (int)round($from['percent'] + ($voltage - $from['voltage']) / ($point['voltage'] - $from['voltage']) * ($point['percent'] - $from['percent']));
        }
        return (int)round(end($points)['percent']);
    }

    public static function number(mixed $value): bool
    {
        return !is_bool($value) && is_numeric($value) && is_finite((float)$value);
    }
}
