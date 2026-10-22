<?php

namespace MajorDoMo\Core\Console\Commands;

use MajorDoMo\Core\Console\{Command, Input, Output, Context, PhpToolRunner};
use MajorDoMo\Core\{ConfigSchema, ConfigCache};
use MajorDoMo\Core\Install\EnvFile;

final class ConfigMigrateCommand extends Command
{
    public function name(): string { return 'config:migrate'; }
    public function description(): string { return 'Перенести старый PHP-конфиг в .env; по умолчанию только просмотр'; }
    public function options(): array
    {
        return array('from=' => 'Путь к доверенному старому PHP-конфигу (по умолчанию config.legacy.php)', 'write' => 'Записать .env с резервной копией', 'force' => 'Разрешить изменение существующего .env');
    }
    public function execute(Input $input, Output $output, Context $context): int
    {
        if ($input->option('force') && !$input->option('write')) throw new \InvalidArgumentException('--force используется вместе с --write');
        $source = (string)$input->option('from', $context->root . '/config.legacy.php');
        if (!is_file($source)) $source = $context->root . '/' . $source;
        $source = realpath($source);
        if ($source === false || !is_file($source)) throw new \InvalidArgumentException('Не найден старый конфиг');
        $result = (new PhpToolRunner())->run($context->root . '/tools/config-migrate-runner.php', array($source), $context->root);
        // A legacy file can print secrets or errors: never echo its raw output.
        if ($result['code'] !== 0 || $result['stderr'] !== '') throw new \RuntimeException('Не удалось прочитать старый конфиг в изолированном процессе');
        try { $data = json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR); }
        catch (\JsonException $error) { throw new \RuntimeException('Старый конфиг должен выполняться без вывода в консоль'); }
        if (!is_array($data) || !isset($data['constants']) || !is_array($data['constants'])) throw new \RuntimeException('Некорректный результат чтения старого конфига');
        $schema = ConfigSchema::all();
        $values = array();
        $skipped = array();
        foreach ($data['constants'] as $key => $value) {
            if (!isset($schema[$key]) || ($schema[$key]['computed'] ?? false) || ($schema[$key]['env'] ?? true) === false) { $skipped[] = $key; continue; }
            $values[$key] = is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) : (is_bool($value) ? ($value ? '1' : '0') : (string)$value);
        }
        if (!empty($data['timezone'])) $values['TIMEZONE'] = $data['timezone'];
        if (!empty($data['restart_threads'])) $values['RESTART_THREADS'] = implode(', ', $data['restart_threads']);
        if (!empty($data['aditional_git_urls'])) $values['ADITIONAL_GIT_URLS'] = json_encode($data['aditional_git_urls'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        ksort($values);
        $safe = array();
        foreach ($values as $key => $value) $safe[$key] = ($schema[$key]['secret'] ?? false) ? '***' : $value;
        $output->table(array('Ключ', 'Значение'), array_map(static fn($key) => array($key, $safe[$key]), array_keys($safe)));
        $written = false;
        $backup = null;
        if ($input->option('write')) {
            $exists = is_file(EnvFile::path($context->root));
            if ($exists && !$input->option('force')) throw new \InvalidArgumentException('.env уже существует; для изменения нужен --force');
            if ($exists) {
                $backup = EnvFile::backup($context->root);
                if ($backup === null) throw new \RuntimeException('Не удалось создать резервную копию .env');
            }
            $legacyBackup = $context->root . '/config.php.bak.' . date('Ymd-His') . '.' . bin2hex(random_bytes(3));
            if (!copy($source, $legacyBackup)) throw new \RuntimeException('Не удалось сохранить резервную копию старого конфига');
            $saved = EnvFile::apply($context->root, $values);
            if (!$saved['ok']) throw new \RuntimeException($saved['error']);
            $written = true;
            if (!ConfigCache::clear($context->root)) throw new \RuntimeException('.env записан, но удалить кеш не удалось. Проверьте права на cms/cached.');
            $output->line('.env записан. Резервная копия старого конфига: ' . $legacyBackup);
        } else {
            $output->line('Предварительный просмотр. Для записи добавьте --write.');
        }
        $output->result(array('values' => $safe, 'skipped' => $skipped, 'written' => $written, 'backup' => $backup));
        return 0;
    }
}
