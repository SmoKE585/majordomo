<?php

namespace MajorDoMo\Core\Deploy;

use RuntimeException;

/**
 * Рендер systemd-юнита и вспомогательных файлов для MajorDoMo.
 *
 * Класс не трогает файловую систему и systemctl: его можно проверять тестами
 * без root. Команды `systemd:set` и `systemd:give-permissions` используют его
 * как единственный источник формата.
 *
 * 2026-10-22 — добавлено для деплоя на FastPanel/systemd.
 */
final class SystemdUnit
{
    public function __construct(
        private string $service,
        private string $user,
        private string $group,
        private string $root,
        private string $php,
        private string $execStartPre = '',
        private string $home = ''
    ) {
        $this->service = self::validateName($service, 'service');
        $this->user = self::validateName($user, 'user');
        $this->group = self::validateName($group, 'group');
        $this->root = rtrim($root, '/\\');
        if ($this->root === '') throw new RuntimeException('Не задан корень проекта.');
        if ($this->php === '' || str_contains($this->php, "\0")) throw new RuntimeException('Не задан PHP для службы.');
    }

    public function serviceName(): string
    {
        return $this->service;
    }

    public function user(): string
    {
        return $this->user;
    }

    public function group(): string
    {
        return $this->group;
    }

    public function execStartPre(): string
    {
        return $this->execStartPre;
    }

    public function render(): string
    {
        $lines = array(
            '[Unit]',
            'Description=MajorDoMo (' . $this->user . ')',
            'After=network-online.target mysql.service mariadb.service',
            'Wants=network-online.target mysql.service',
            '',
            '[Service]',
            'Type=simple',
            'User=' . $this->user,
            'Group=' . $this->group,
            'WorkingDirectory=' . $this->root,
        );
        if (trim($this->execStartPre) !== '') {
            $lines[] = 'ExecStartPre=' . trim($this->execStartPre);
        }
        $lines[] = 'ExecStart=' . $this->php . ' ' . $this->root . '/cycle.php';
        $lines[] = 'Restart=always';
        $lines[] = 'RestartSec=5';
        $lines[] = 'KillMode=control-group';
        $lines[] = 'TimeoutStopSec=20';
        if ($this->home !== '') {
            $lines[] = 'Environment="HOME=' . self::escapeEnvironment($this->home) . '"';
        }
        $lines[] = 'Environment="PATH=/usr/local/bin:/usr/bin:/bin"';
        $lines[] = '';
        $lines[] = '[Install]';
        $lines[] = 'WantedBy=multi-user.target';
        return implode("\n", $lines) . "\n";
    }

    public function wrapper(string $systemctl = '/bin/systemctl'): string
    {
        if ($systemctl === '' || str_contains($systemctl, "\0")) throw new RuntimeException('Не задан путь к systemctl.');
        return "#!/bin/sh\n"
            . "set -eu\n"
            . "case \"\${1:-}\" in\n"
            . "  restart|start|stop|status|is-active)\n"
            . "    exec " . $systemctl . " \"\$1\" " . $this->service . ".service\n"
            . "    ;;\n"
            . "  *)\n"
            . "    echo \"usage: \$0 restart|start|stop|status|is-active\" >&2\n"
            . "    exit 64\n"
            . "    ;;\n"
            . "esac\n";
    }

    public function sudoers(string $wrapper): string
    {
        if ($wrapper === '' || str_contains($wrapper, "\0")) throw new RuntimeException('Не задан путь к wrapper.');
        return "Cmnd_Alias MAJORDOMO_" . strtoupper(preg_replace('/[^A-Za-z0-9_]/', '_', $this->service)) . " = " . $wrapper . "\n"
            . $this->user . " ALL=(root) NOPASSWD: " . $wrapper . "\n"
            . "Defaults:" . $this->user . " !requiretty\n";
    }

    /** Сохранить ExecStartPre и User из уже установленного юнита. */
    public static function parseExisting(string $contents): array
    {
        $result = array('execStartPre' => '', 'user' => '', 'group' => '');
        foreach (preg_split('/\R/', $contents) ?: array() as $line) {
            $line = trim($line);
            if (str_starts_with($line, 'ExecStartPre=')) {
                $result['execStartPre'] = substr($line, strlen('ExecStartPre='));
            } elseif (str_starts_with($line, 'User=')) {
                $result['user'] = substr($line, strlen('User='));
            } elseif (str_starts_with($line, 'Group=')) {
                $result['group'] = substr($line, strlen('Group='));
            }
        }
        return $result;
    }

    public static function defaultExecStartPre(string $dbHost = '127.0.0.1'): string
    {
        $host = $dbHost === '' || $dbHost === 'localhost' ? '127.0.0.1' : $dbHost;
        if (!preg_match('/^[A-Za-z0-9_.:-]+$/D', $host)) $host = '127.0.0.1';
        return "/bin/sh -c 'for i in $(seq 1 60); do mysqladmin ping -h " . $host . " --silent && exit 0; sleep 2; done; exit 1'";
    }

    public static function validateName(string $value, string $label): string
    {
        if (!preg_match('/^[A-Za-z0-9_.@-]{1,80}$/D', $value)) {
            throw new RuntimeException('Недопустимое имя ' . $label . ': ' . $value);
        }
        return $value;
    }

    private static function escapeEnvironment(string $value): string
    {
        return str_replace(array('\\', '"', '%'), array('\\\\', '\\"', '%%'), $value);
    }
}
