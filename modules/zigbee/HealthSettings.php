<?php

namespace MajorDoMo\Modules\Zigbee;

use InvalidArgumentException;
use ParseError;

final class HealthSettings
{
    public const EVENTS = array('offline' => 'Нет связи', 'online' => 'Связь восстановлена', 'battery_low' => 'Низкий заряд', 'battery_critical' => 'Критический заряд', 'battery_ok' => 'Заряд восстановлен');

    public static function defaults(): array
    {
        $messages = array('offline' => '{device}: нет связи.', 'online' => '{device}: связь восстановлена.',
            'battery_low' => '{device}: низкий заряд батареи, {value}%.', 'battery_critical' => '{device}: критический заряд батареи, {value}%.',
            'battery_ok' => '{device}: заряд батареи восстановлен, {value}%.');
        $events = array();
        foreach ($messages as $key => $message) $events[$key] = array('enabled' => true, 'say' => false, 'level' => 2, 'repeat_hours' => 0, 'message' => $message, 'code' => '');
        return array('enabled' => true, 'offline_delay' => 120, 'timeout_mains' => 0, 'timeout_battery' => 0,
            'low' => 20, 'critical' => 10, 'recovery' => 25, 'battery_source' => 'auto', 'voltage_path' => 'voltage',
            'voltage_unit' => 'mV', 'voltage_profile' => 'none', 'voltage_points' => array(), 'events' => $events);
    }

    public static function validate(array $settings): array
    {
        $settings = array_replace_recursive(self::defaults(), $settings);
        foreach (array('enabled') as $key) $settings[$key] = self::boolean($settings[$key]);
        foreach (array('offline_delay' => 86400, 'timeout_mains' => 525600, 'timeout_battery' => 525600,
            'low' => 100, 'critical' => 100, 'recovery' => 100) as $key => $max) {
            $settings[$key] = self::integer($settings[$key], 0, $max, $key);
        }
        if ($settings['critical'] >= $settings['low'] || $settings['low'] >= $settings['recovery']) throw new InvalidArgumentException('Пороги батареи: критический < низкий < возврат в норму.');
        if (!in_array($settings['battery_source'], array('auto', 'voltage', 'off'), true)
            || !in_array($settings['voltage_unit'], array('V', 'mV'), true)
            || !in_array($settings['voltage_profile'], array('none', 'linear3v', 'alkaline', 'custom'), true)) throw new InvalidArgumentException('Неизвестный режим батареи.');
        if (!is_string($settings['voltage_path']) || !preg_match('/^[\pL\pN_.:-]{1,190}$/uD', $settings['voltage_path'])) throw new InvalidArgumentException('Укажите путь свойства напряжения.');
        if (!is_array($settings['voltage_points']) || !array_is_list($settings['voltage_points']) || count($settings['voltage_points']) > 32) throw new InvalidArgumentException('Допустимо до 32 точек напряжения.');
        if ($settings['voltage_profile'] === 'custom') Battery::validatePoints($settings['voltage_points']);
        foreach (self::EVENTS as $event => $label) {
            $rule = $settings['events'][$event];
            if (!is_array($rule)) throw new InvalidArgumentException('Некорректные настройки события: ' . $label);
            $rule['enabled'] = self::boolean($rule['enabled']); $rule['say'] = self::boolean($rule['say']);
            $rule['level'] = self::integer($rule['level'], 0, 10, 'Уровень say');
            $rule['repeat_hours'] = self::integer($rule['repeat_hours'], 0, 8760, 'Повтор, часы');
            if (!is_string($rule['message']) || mb_strlen($rule['message']) > 2000 || ($rule['say'] && trim($rule['message']) === '')) throw new InvalidArgumentException('Укажите текст say до 2000 символов.');
            if (!is_string($rule['code']) || strlen($rule['code']) > 65536) throw new InvalidArgumentException('PHP-обработчик: до 64 КБ.');
            if (str_contains($rule['code'], '<?') || str_contains($rule['code'], '?>')) throw new InvalidArgumentException('Введите PHP без открывающих и закрывающих тегов.');
            try { token_get_all("<?php\n" . $rule['code'], TOKEN_PARSE); }
            catch (ParseError $e) { throw new InvalidArgumentException($label . ': ошибка PHP, строка ' . max(1, $e->getLine() - 1) . ': ' . $e->getMessage(), 0, $e); }
            $settings['events'][$event] = $rule;
        }
        return $settings;
    }

    private static function boolean(mixed $value): bool
    {
        if (!in_array($value, array(true, false, 0, 1, '0', '1'), true)) throw new InvalidArgumentException('Некорректный переключатель.');
        return (bool)$value;
    }

    private static function integer(mixed $value, int $min, int $max, string $label): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false || $value < $min || $value > $max) throw new InvalidArgumentException($label . ': от ' . $min . ' до ' . $max . '.');
        return (int)$value;
    }
}
