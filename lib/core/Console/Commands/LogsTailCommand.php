<?php

namespace MajorDoMo\Core\Console\Commands;

use MajorDoMo\Core\Console\{Command, Input, Output, Context, LogTail};
use MajorDoMo\Core\Config;

final class LogsTailCommand extends Command
{
    public function name(): string { return 'logs:tail'; }
    public function description(): string { return 'Последние строки журнала DebMes или stdout цикла'; }
    public function arguments(): array { return array('channel?' => 'Канал DebMes, по умолчанию errors; для stdout — cycle_mqtt и --cycle'); }
    public function options(): array
    {
        return array('lines=' => 'Количество строк: 1–1000, по умолчанию 50', 'follow' => 'Следить за новыми записями до Ctrl+C (без --json)', 'date=' => 'Дата YYYY-MM-DD, по умолчанию сегодня', 'directory=' => 'Каталог журналов; по умолчанию LOG_DIRECTORY или cms/debmes', 'cycle' => 'Читать stdout цикла (при LOG_CYCLES=1)');
    }
    public function execute(Input $input, Output $output, Context $context): int
    {
        $lines = $input->integer('lines', 50, 1, 1000);
        if ($input->option('follow') && $output->isJson()) throw new \InvalidArgumentException('--follow несовместим с --json');
        $channel = $input->argument('channel', 'errors');
        if (!preg_match('~^[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*$~D', $channel)) throw new \InvalidArgumentException('Недопустимое имя журнала');
        $date = $input->option('date', null);
        if ($date !== null) {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if ($parsed === false || $parsed->format('Y-m-d') !== $date) throw new \InvalidArgumentException('Дата должна иметь формат YYYY-MM-DD');
        }
        $context->config();
        if ($input->option('cycle')) $channel = $context->cycleName($channel, $context->cycles());
        $directory = (string)$input->option('directory', Config::string('LOG_DIRECTORY') ?: $context->root . '/cms/debmes');
        $directory = realpath($directory);
        if ($directory === false || !is_dir($directory)) throw new \RuntimeException('Каталог журналов не найден. Укажите --directory при нестандартном пути.');
        $path = static fn() => $input->option('cycle')
            ? $directory . '/log_' . ($date ?? date('Y-m-d')) . '-' . $channel . '.php.txt'
            : $directory . '/' . ($date ?? date('Y-m-d')) . '/' . $channel . '.log';
        $file = $path();
        $tail = new LogTail();
        $result = array('lines' => array(), 'offset' => 0, 'identity' => '', 'truncated' => false);
        if (is_file($file)) $result = $tail->lastLines($file, $lines);
        elseif (!$input->option('follow')) throw new \RuntimeException('Журнал не найден: ' . $file);
        $output->line('Журнал: ' . $file);
        foreach ($result['lines'] as $line) $output->line(rtrim($line, "\r"));
        if ($result['truncated']) $output->line('Прочитан последний фрагмент размером до 4 MiB.');
        $output->result(array('file' => $file, 'lines' => $result['lines'], 'truncated' => $result['truncated']));
        if ($input->option('follow')) $tail->follow($path, $result['offset'], $result['identity'], $output);
        return 0;
    }
}
