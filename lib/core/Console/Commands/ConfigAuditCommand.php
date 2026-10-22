<?php

namespace MajorDoMo\Core\Console\Commands;

use MajorDoMo\Core\Console\{Command, Input, Output, Context, PhpToolRunner};

final class ConfigAuditCommand extends Command
{
    public function name(): string { return 'config:audit'; }
    public function description(): string { return 'Аудит обращений к легаси-константам конфигурации'; }
    public function options(): array { return array('no-log' => 'Не записывать журнал аудита', 'fail-on-legacy' => 'Код 1 при обнаружении легаси-констант'); }
    public function execute(Input $input, Output $output, Context $context): int
    {
        $arguments = array('--json');
        foreach (array('no-log', 'fail-on-legacy') as $option) if ($input->option($option)) $arguments[] = '--' . $option;
        $result = (new PhpToolRunner())->run($context->root . '/tools/config-audit.php', $arguments, $context->root);
        if ($result['stderr'] !== '') throw new \RuntimeException('Инструмент аудита сообщил об ошибке');
        $data = json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data) || !isset($data['reference_count'])) throw new \RuntimeException('Некорректный результат аудита');
        $output->line('Легаси-обращений: ' . $data['reference_count'] . '; файлов: ' . $data['files_with_references']);
        $output->result($data);
        return $result['code'];
    }
}
