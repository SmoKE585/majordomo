<?php

namespace MajorDoMo\Core\Console\Commands;

use MajorDoMo\Core\Console\{Command, Input, Output, Context};

/**
 * Диагностика быстрого пути MQTT: очередь, повторы, зависшие задачи и
 * задержки участков. Управляет только обслуживанием, не публикует команды.
 *
 * 2026-10-23 — добавлено вместе с быстрым путём MQTT.
 */
final class MqttRuntimeCommand extends Command
{
    public function name(): string { return 'mqtt:runtime'; }
    public function description(): string { return 'Состояние быстрого пути MQTT, очереди и задержек'; }
    public function options(): array
    {
        return array(
            'flush' => 'Перенести накопленное состояние и историю в MySQL',
            'reap' => 'Вернуть зависшие события и поднять отложенные',
            'failed' => 'Показать последние неудачные события',
            'limit=' => 'Сколько неудачных событий показать (1–200, по умолчанию 20)',
        );
    }

    public function execute(Input $input, Output $output, Context $context): int
    {
        $limit = $input->integer('limit', 20, 1, 200);
        $this->boot($context);
        if (!\MqttRuntime::enabled()) {
            $output->warning('Быстрый путь MQTT выключен: нужны USE_REDIS и расширение Redis (или MQTT_ASYNC_ENABLED=0).');
            $output->result(array('enabled' => false));
            return 1;
        }
        $result = array('enabled' => true);
        if ($input->option('reap')) {
            $queue = \MqttRuntime::queue('incoming');
            $result['reap'] = array('promoted' => $queue->promoteDelayed(200), 'requeued' => $queue->reap(200));
        }
        if ($input->option('flush')) {
            $result['flush'] = array(
                'state' => \MqttRuntime::flushState(500),
                'history' => \MqttRuntime::flushHistory(1000),
            );
        }
        $status = \MqttRuntime::status();
        $result['status'] = $status;
        if ($input->option('failed')) {
            $failed = array();
            foreach (\MqttRuntime::queue('incoming')->failed($limit) as $row) {
                $event = is_array($row['event'] ?? null) ? $row['event'] : array();
                $failed[] = array(
                    'lane' => (string)($event['LANE'] ?? ''),
                    'path' => (string)($event['path'] ?? $event['QUEUE_ID'] ?? ''),
                    'error' => (string)($row['error'] ?? ''),
                    'at' => (string)($row['at'] ?? ''),
                );
            }
            $result['failed'] = $failed;
        }

        $output->title('Быстрый путь MQTT');
        $output->status('OK', 'Версия привязок: ' . ($status['bindings_version'] ?? '0'));
        if (isset($status['incoming'])) {
            $output->table(array('Очередь', 'Значение'), array(
                array('В ожидании', $status['incoming']['size'] ?? 0),
                array('В обработке', $status['incoming']['inflight'] ?? 0),
                array('Отложено', $status['incoming']['delayed'] ?? 0),
                array('Неудачных', $status['incoming']['failed'] ?? 0),
                array('Грязных топиков', $status['dirty_topics'] ?? 0),
                array('История в очереди', $status['history_pending'] ?? 0),
                array('Обработчиков', $status['handlers'] ?? 0),
                array('Обработчики активны', !empty($status['handlers_alive']) ? 'да' : 'нет heartbeat'),
                array('Издатель', !empty($status['publisher_alive']) ? 'работает' : 'нет heartbeat'),
            ));
        }
        $metrics = is_array($status['metrics'] ?? null) ? $status['metrics'] : array();
        $rows = array();
        foreach (array('intake', 'claim', 'handle', 'property', 'publish', 'project') as $stage) {
            if (!is_array($metrics[$stage] ?? null)) continue;
            $rows[] = array($stage, $metrics[$stage]['count'], $metrics[$stage]['avg_ms'], $metrics[$stage]['p95_ms'], $metrics[$stage]['last_ms']);
        }
        if ($rows) $output->table(array('Участок', 'Замеров', 'Среднее, мс', 'p95, мс', 'Последний, мс'), $rows);
        if (isset($result['reap'])) {
            $output->info('Возвращено в очередь: ' . $result['reap']['requeued'] . ', поднято отложенных: ' . $result['reap']['promoted']);
        }
        if (isset($result['flush'])) {
            $output->info('Перенесено состояния: ' . $result['flush']['state'] . ', истории: ' . $result['flush']['history']);
        }
        if (isset($result['failed'])) {
            if (!$result['failed']) {
                $output->muted('Неудачных событий нет.');
            } else {
                $output->table(array('Полоса', 'Путь', 'Ошибка', 'Когда'), array_map(static fn(array $row) => array(
                    $row['lane'], $row['path'], mb_substr($row['error'], 0, 120), $row['at'],
                ), $result['failed']));
            }
        }
        $output->result($result);
        return 0;
    }

    private function boot(Context $context): void
    {
        $context->config();
        chdir($context->root);
        if (!function_exists('SQLSelect')) {
            require_once $context->root . '/config.php';
            require_once $context->root . '/lib/loader.php';
            require_once $context->root . '/load_settings.php';
        }
        if (!class_exists('MqttRuntime')) {
            require_once DIR_MODULES . 'mqtt/mqtt.class.php';
        }
    }
}
