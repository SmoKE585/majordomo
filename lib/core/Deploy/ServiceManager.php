<?php

namespace MajorDoMo\Core\Deploy;

use MajorDoMo\Core\Config;
use RuntimeException;

/**
 * Определение и управление systemd-службой MajorDoMo.
 *
 * Веб-деплой работает от пользователя сайта и вызывает только wrapper из
 * `/usr/local/sbin`, разрешённый в sudoers. Сам класс не повышает права.
 *
 * 2026-10-22 — добавлено для деплоя на FastPanel/systemd.
 */
final class ServiceManager
{
    private string $root;
    private string $service;
    private string $user;
    private string $group;
    private string $home;
    private string $php;

    public function __construct(string $root, ?string $service = null, ?string $user = null, ?string $php = null)
    {
        $this->root = rtrim($root, '/\\');
        $this->user = $this->detectUser($user);
        $this->group = $this->detectGroup($this->user);
        $this->home = $this->detectHome($this->user);
        $this->php = $php !== null && $php !== '' ? $php : $this->detectPhp();
        $this->service = $service !== null && $service !== ''
            ? SystemdUnit::validateName($service, 'service')
            : 'majordomo-' . $this->user;
    }

    public function root(): string { return $this->root; }
    public function serviceName(): string { return $this->service; }
    public function user(): string { return $this->user; }
    public function group(): string { return $this->group; }
    public function home(): string { return $this->home; }
    public function phpBinary(): string { return $this->php; }

    public function unitPath(): string
    {
        return '/etc/systemd/system/' . $this->service . '.service';
    }

    public function wrapperPath(): string
    {
        return '/usr/local/sbin/' . $this->service;
    }

    public function sudoersPath(): string
    {
        return '/etc/sudoers.d/' . $this->service;
    }

    public function systemctlPath(): string
    {
        foreach (array('/bin/systemctl', '/usr/bin/systemctl') as $path) {
            if (is_file($path)) return $path;
        }
        return 'systemctl';
    }

    public function unitFor(?string $execStartPre = null): SystemdUnit
    {
        return new SystemdUnit(
            $this->service,
            $this->user,
            $this->group,
            $this->root,
            $this->php,
            $execStartPre ?? SystemdUnit::defaultExecStartPre(Config::string('DB_HOST', '127.0.0.1')),
            $this->home
        );
    }

    /** @return array{ok:bool,state:string,out:string,err:string,code:int} */
    public function status(): array
    {
        if (is_file($this->wrapperPath()) && is_executable($this->wrapperPath())) {
            $result = $this->run(array('sudo', '-n', $this->wrapperPath(), 'status'));
        } else {
            $result = $this->run(array($this->systemctlPath(), 'is-active', $this->service . '.service'));
        }
        return array(
            'ok' => $result['ok'],
            'state' => trim($result['out']),
            'out' => $result['out'],
            'err' => $result['err'],
            'code' => $result['code'],
        );
    }

    /** @return array{ok:bool,code:int,out:string,err:string,timed_out:bool} */
    public function restart(): array
    {
        if (is_file($this->wrapperPath()) && is_executable($this->wrapperPath())) {
            return $this->run(array('sudo', '-n', $this->wrapperPath(), 'restart'), 120);
        }
        return $this->run(array($this->systemctlPath(), 'restart', $this->service . '.service'), 120);
    }

    /**
     * Запуск команды без shell. Возвращает код и обрезанный вывод.
     *
     * @param string[] $command
     * @return array{ok:bool,code:int,out:string,err:string,timed_out:bool}
     */
    public function run(array $command, int $timeout = 60): array
    {
        $descriptors = array(
            0 => array('pipe', 'r'),
            1 => array('pipe', 'w'),
            2 => array('pipe', 'w'),
        );
        $pipes = array();
        $process = @proc_open($command, $descriptors, $pipes, $this->root);
        if (!is_resource($process)) {
            return array('ok' => false, 'code' => 127, 'out' => '', 'err' => 'Не удалось запустить команду', 'timed_out' => false);
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = '';
        $err = '';
        $started = microtime(true);
        $timedOut = false;
        $status = array('running' => true, 'exitcode' => -1);
        while (true) {
            $status = proc_get_status($process);
            $read = array($pipes[1], $pipes[2]);
            $write = null;
            $except = null;
            if ($read !== array() && @stream_select($read, $write, $except, 0, 200000) !== false) {
                foreach ($read as $stream) {
                    $chunk = fread($stream, 8192);
                    if ($chunk === false || $chunk === '') continue;
                    if ($stream === $pipes[1]) $out .= $chunk;
                    else $err .= $chunk;
                }
            }
            if (!$status['running']) break;
            if (microtime(true) - $started > $timeout) {
                $timedOut = true;
                @proc_terminate($process, 9);
                break;
            }
        }
        foreach (array($pipes[1], $pipes[2]) as $stream) {
            if (!is_resource($stream)) continue;
            $chunk = stream_get_contents($stream);
            if ($chunk === false || $chunk === '') continue;
            if ($stream === $pipes[1]) $out .= $chunk;
            else $err .= $chunk;
            fclose($stream);
        }
        $code = (int)($status['exitcode'] ?? -1);
        if ($code === -1) {
            $closed = proc_close($process);
            $code = $closed >= 0 ? $closed : 1;
        } else {
            proc_close($process);
        }
        $limit = 12000;
        return array(
            'ok' => !$timedOut && $code === 0,
            'code' => $code,
            'out' => mb_substr($out, -$limit),
            'err' => mb_substr($err, -$limit),
            'timed_out' => $timedOut,
        );
    }

    private function detectUser(?string $user): string
    {
        $candidate = $user !== null && $user !== '' ? $user : (getenv('SUDO_USER') ?: '');
        if ($candidate === '' && function_exists('posix_geteuid') && posix_geteuid() === 0 && function_exists('fileowner')) {
            $owner = @fileowner($this->root);
            if ($owner !== false && function_exists('posix_getpwuid')) {
                $candidate = (string)(posix_getpwuid($owner)['name'] ?? '');
            }
        }
        if ($candidate === '') {
            if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
                $candidate = (string)(posix_getpwuid(posix_geteuid())['name'] ?? '');
            }
        }
        if ($candidate === '') $candidate = (string)(getenv('USERNAME') ?: getenv('USER') ?: get_current_user());
        if ($candidate === '' || $candidate === 'root' || $candidate === '0') {
            throw new RuntimeException('Не удалось определить пользователя сайта. Укажите --user=<имя>.');
        }
        if (function_exists('posix_getpwnam') && posix_getpwnam($candidate) === false) {
            throw new RuntimeException('Пользователь сайта не найден: ' . $candidate);
        }
        return $candidate;
    }

    private function detectGroup(string $user): string
    {
        if (function_exists('posix_getpwnam') && function_exists('posix_getgrgid')) {
            $account = posix_getpwnam($user);
            if (is_array($account) && isset($account['gid'])) {
                $group = posix_getgrgid((int)$account['gid']);
                if (is_array($group) && !empty($group['name'])) return (string)$group['name'];
            }
        }
        return $user;
    }

    private function detectHome(string $user): string
    {
        if (function_exists('posix_getpwnam')) {
            $account = posix_getpwnam($user);
            if (is_array($account) && !empty($account['dir'])) return (string)$account['dir'];
        }
        return '/home/' . $user;
    }

    private function detectPhp(): string
    {
        $configured = Config::string('PATH_TO_PHP', '');
        if ($configured !== '' && (is_file($configured) || str_contains($configured, '/'))) return $configured;
        return PHP_BINARY;
    }
}
