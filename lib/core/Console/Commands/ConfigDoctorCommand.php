<?php

namespace MajorDoMo\Core\Console\Commands;

use MajorDoMo\Core\Console\{Command, Input, Output, Context};
use MajorDoMo\Core\{Config, ConfigCache};

final class ConfigDoctorCommand extends Command
{
    public function name(): string { return 'config:doctor'; }
    public function description(): string { return 'Проверить конфигурацию и подключение к БД'; }
    public function execute(Input $input, Output $output, Context $context): int
    {
        $context->config();
        $checks = array();
        $checks[] = array('status' => is_file($context->root . '/.env') ? 'ok' : 'fail', 'message' => '.env');
        $source = is_file($context->root . '/config.php') ? file_get_contents($context->root . '/config.php') : '';
        $shim = is_string($source) && str_contains($source, 'Config::boot') && !preg_match('/\\bdefine\\s*\\(/i', $source);
        $checks[] = array('status' => $shim ? 'ok' : 'fail', 'message' => 'config.php: ожидается shim с Config::boot');
        foreach (Config::schema() as $key => $definition) {
            if (($definition['required'] ?? false) && (Config::get($key) === null || Config::get($key) === '')) {
                $checks[] = array('status' => 'fail', 'message' => 'Не задан обязательный ключ ' . $key);
            }
        }
        $checks[] = array('status' => is_writable(dirname(ConfigCache::path($context->root))) ? 'ok' : 'warn', 'message' => 'Каталог кеша конфигурации доступен для записи');
        $environment = new \MajorDoMo\Core\Install\InstallEnvironment($context->root);
        $database = $environment->databaseStatus();
        $checks[] = array('status' => $database['ok'] ? 'ok' : 'fail', 'message' => $database['ok'] ? 'Подключение к БД работает' : 'Не удалось подключиться к БД; проверьте DB_* и mysqli');
        $output->title('Конфигурация');
        foreach ($checks as $check) $output->status($check['status'], $check['message']);
        $output->result(array('checks' => $checks));
        return count(array_filter($checks, static fn(array $check) => $check['status'] === 'fail')) > 0 ? 1 : 0;
    }
}
