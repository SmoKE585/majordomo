<?php

namespace MajorDoMo\Core\Console\Commands;

use MajorDoMo\Core\Console\Command;
use MajorDoMo\Core\Console\Context;
use MajorDoMo\Core\Console\Input;
use MajorDoMo\Core\Console\Output;
use MajorDoMo\Core\Deploy\SystemUpdate;
use MajorDoMo\Core\Deploy\UpdateSource;

/**
 * Системное обновление из терминала.
 *
 * Повторяет то, что делает панель («Обновление системы»), но без HTTP-сессии:
 * проверки окружения, обязательный дамп базы, архив по коммиту, распаковка,
 * пауза циклов, замена файлов, Composer/npm/миграции и перезапуск службы.
 * Это единственный вход для deploy.sh, поэтому шаги печатаются тем же Output.
 *
 * 2026-10-23 — добавлено вместе с корневым deploy.sh.
 */
final class DeployUpdateCommand extends Command
{
    public function name(): string
    {
        return 'deploy:update';
    }

    public function description(): string
    {
        return 'Обновление системы: проверки, бэкап базы, архив, распаковка, зависимости, перезапуск';
    }

    /** @return array<string,string> */
    public function arguments(): array
    {
        return array();
    }

    /** @return array<string,string> */
    public function options(): array
    {
        return array(
            'check' => 'Только проверки окружения, без изменений',
            'plan' => 'Показать доступный коммит и ссылку на архив, ничего не менять',
            'yes' => 'Не спрашивать подтверждение',
            'url=' => 'URL архива обновления (по умолчанию MASTER_UPDATE_URL из .env)',
            'branch=' => 'Ветка обновления: master, alpha или другая',
            'skip-backup' => 'Не делать резервную копию базы (не рекомендуется)',
            'no-restart' => 'Не перезапускать службу после обновления',
            'keep-temp' => 'Оставить скачанный архив и распакованные файлы',
            'insecure' => 'Не проверять TLS-сертификат при скачивании',
            'force' => 'Обновить, даже если этот коммит уже установлен',
        );
    }

    public function execute(Input $input, Output $output, Context $context): int
    {
        $context->config();
        $update = new SystemUpdate($context->root, array(
            'url' => (string)$input->option('url', ''),
            'branch' => (string)$input->option('branch', ''),
            'backup' => !$input->option('skip-backup'),
            'restart' => !$input->option('no-restart'),
            'force' => (bool)$input->option('force'),
            'keep_temp' => (bool)$input->option('keep-temp'),
            'insecure' => (bool)$input->option('insecure'),
        ));

        if ($input->option('check')) {
            return $this->renderChecks($update, $output);
        }
        if ($input->option('plan')) {
            return $this->renderPlan($update, $output);
        }

        $output->title('Обновление системы');
        $output->muted('Источник: ' . ($update->updateUrl() !== '' ? $update->updateUrl() : 'не задан'));

        if (!$input->option('yes')) {
            if ($output->isJson() || !$this->isInteractive()) {
                throw new \InvalidArgumentException('Нужно подтверждение: добавьте --yes, чтобы выполнить обновление без вопросов.');
            }
            $output->warning('Будут заменены файлы проекта, поставлены зависимости и перезапущена служба.');
            $output->write('  Продолжить? [y/N]: ');
            $answer = strtolower(trim((string)fgets(STDIN)));
            $output->line();
            if (!in_array($answer, array('y', 'yes', 'д', 'да'), true)) {
                $output->warning('Обновление отменено');
                return 1;
            }
        }

        $step = 0;
        $onStep = function (string $id, string $title) use ($output, &$step): void {
            $step++;
            $output->section('Шаг ' . $step . ' · ' . $title);
        };
        $onLog = function (string $message, string $kind = 'info') use ($output): void {
            switch ($kind) {
                case 'error':
                    $output->status('FAIL', $message);
                    break;
                case 'success':
                    $output->success($message);
                    break;
                case 'warning':
                    $output->warning($message);
                    break;
                case 'muted':
                    $output->muted($message);
                    break;
                default:
                    $output->info($message);
                    break;
            }
        };

        $result = $update->run($onLog, $onStep);

        if (!empty($result['up_to_date'])) {
            $output->section('Готово');
            $output->success('Установлена последняя версия: ' . $this->shortCommit((string)$result['to']));
            $output->result($result);
            return 0;
        }

        $output->section('Готово');
        $output->success(
            'Система обновлена: ' . $this->shortCommit((string)$result['from'])
            . ' → ' . $this->shortCommit((string)$result['to'])
        );
        if (!empty($result['backup'])) {
            $output->muted('Дамп базы: ' . $result['backup']);
        }
        $output->muted('Запись версии в конфиг модуля сделает панель при первом открытии (pending-файл).');
        $output->result($result);
        return 0;
    }

    private function renderChecks(SystemUpdate $update, Output $output): int
    {
        $output->title('Проверка окружения');
        $checks = $update->checks();
        $rows = array();
        $failed = array();
        foreach ($checks as $check) {
            $ok = !empty($check['ok']);
            $fatal = !empty($check['fatal']);
            if (!$ok && $fatal) {
                $failed[] = (string)$check['title'];
            }
            $rows[] = array(
                $ok ? 'OK' : ($fatal ? 'ERROR' : 'WARN'),
                (string)$check['title'],
                (string)$check['details'],
            );
        }
        $output->table(array('Статус', 'Проверка', 'Подробности'), $rows);
        $output->result(array('checks' => $checks, 'failed' => $failed));
        if ($failed !== array()) {
            $output->error('Не пройдены обязательные проверки: ' . implode(', ', $failed));
            return 1;
        }
        $output->success('Окружение готово к обновлению');
        return 0;
    }

    private function renderPlan(SystemUpdate $update, Output $output): int
    {
        $output->title('План обновления');
        $current = $update->installedVersion();
        $output->info('Установлено сейчас: ' . ($current !== '' ? $this->shortCommit($current) : 'неизвестно'));
        $source = new UpdateSource($update->updateUrl(), true, 60);
        $target = $source->latest();
        $output->success('Доступно: ' . $this->shortCommit((string)$target['commit']) . ' · ветка ' . $target['branch']);
        $output->muted('Архив: ' . $target['archive_url']);
        $output->muted('Лента: ' . $target['feed_url']);
        if ($current !== '' && $current === (string)$target['commit']) {
            $output->warning('Этот коммит уже установлен: обновление не требуется');
        }
        $output->result($target + array('current' => $current));
        return 0;
    }

    private function isInteractive(): bool
    {
        if (!defined('STDIN') || !is_resource(STDIN)) {
            return false;
        }
        return function_exists('stream_isatty') ? @stream_isatty(STDIN) : false;
    }

    private function shortCommit(string $commit): string
    {
        $commit = trim($commit);
        return $commit === '' ? 'неизвестно' : strtoupper(substr($commit, 0, 7));
    }
}
