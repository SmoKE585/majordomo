<?php

/**
 * Индексы таблиц MQTT, без которых обработка свойства и история делают
 * полный перебор. Схема модуля, поэтому обновление живёт здесь, а не в ядре.
 *
 * 2026-10-23 — добавлено для быстрого пути MQTT.
 */
final class MqttSchema
{
    private static bool $done = false;

    public static function ensure(): void
    {
        if (self::$done) return;
        self::$done = true;
        try {
            if (empty(SQLSelect("SHOW TABLES LIKE 'mqtt'"))) return;
            self::index('mqtt', 'mqtt_linked_property', 'LINKED_OBJECT,LINKED_PROPERTY');
            self::index('mqtt', 'mqtt_linked_method', 'LINKED_OBJECT,LINKED_METHOD');
            self::index('mqtt', 'mqtt_updated', 'UPDATED');
            if (!empty(SQLSelect("SHOW TABLES LIKE 'mqtt_history'"))) {
                self::index('mqtt_history', 'mqtt_history_topic', 'MQTT_ID,ID');
            }
        } catch (Throwable $error) {
            // Индекс — оптимизация: без него MQTT продолжает работать, но
            // медленнее. Не превращаем это в отказ цикла.
            if (function_exists('DebMes')) {
                DebMes('MQTT schema index check failed: ' . $error->getMessage(), 'mqtt', 'warning');
            }
        }
    }

    private static function index(string $table, string $name, string $columns): void
    {
        $existing = array();
        foreach (SQLSelect("SHOW INDEX FROM `" . $table . "`") as $row) {
            $existing[(string)$row['Key_name']][(int)$row['Seq_in_index']] = (string)$row['Column_name'];
        }
        foreach ($existing as $indexColumns) {
            ksort($indexColumns);
            if (implode(',', $indexColumns) === $columns) return;
        }
        try {
            \MajorDoMo\Core\Core::database()->requireExec("ALTER TABLE `" . $table . "` ADD INDEX `" . $name . "` (" . $columns . ")");
        } catch (Throwable $error) {
            // Гонка двух процессов на одном индексе: второй получает
            // duplicate key name. Это не ошибка.
            if (function_exists('DebMes') && !str_contains($error->getMessage(), 'Duplicate')) {
                DebMes('MQTT index ' . $name . ' was not created: ' . $error->getMessage(), 'mqtt', 'warning');
            }
        }
    }
}
