<?php

namespace MajorDoMo\Core\Maintenance;

use MajorDoMo\Core\{Config, Core, Database};

/**
 * Регулярная очистка, ночные копии и проверка MyISAM вне основного цикла.
 * 2026-11-11 — убраны массовый CHECK InnoDB, удаление текущих картинок и исправление часов через DELETE.
 */
final class RoutineMaintenance
{
    public function __construct(private Database $db, private string $root, private ?\Closure $dump = null) {}

    public function run(string $task): array
    {
        if (!in_array($task, AutomaticMaintenance::TASKS, true)) throw new \InvalidArgumentException('Неизвестный этап обслуживания');
        $lock = AutomaticMaintenance::lock((new AutomaticMaintenance($this->root))->directory(), 'operation.lock');
        if ($lock === null) throw new \RuntimeException('Другое обслуживание уже выполняется');
        try {
            // Даже ожидание чужой DDL/транзакции не должно задерживать очистку надолго.
            $this->db->requireExec('SET SESSION lock_wait_timeout=5');
            $this->db->requireExec('SET SESSION innodb_lock_wait_timeout=5');
            return match ($task) {
                'cleanup' => $this->cleanup(), 'backup' => $this->backup(),
                'files' => $this->files(), 'tables' => $this->tables(),
            };
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private function cleanup(): array
    {
        $audit = Core::audit()->prune(Config::int('PROPERTY_AUDIT_RETENTION_DAYS'), seconds: 4,
            maxRows: Config::int('PROPERTY_AUDIT_MAX_ROWS'));
        $events = 0;
        if ($this->db->tableExists('system_events')) {
            $row = $this->db->one('SELECT ID FROM system_events ORDER BY ID DESC LIMIT 1 OFFSET 1000');
            if ($row !== null) {
                $bound = (int)$row['ID'];
                $events = $this->deleteBatch('system_events', "CREATED<'" . date('Y-m-d H:i:s', time() - 90 * 86400) . "' AND ID<=$bound");
            }
        }
        $errors = 0;
        $days = defined('SETTINGS_ERRORS_KEEP_HISTORY') ? (int)SETTINGS_ERRORS_KEEP_HISTORY : 0;
        if ($days > 0 && $this->db->tableExists('system_errors_data')) {
            $errors = $this->deleteBatch('system_errors_data', "ADDED<'" . date('Y-m-d H:i:s', time() - $days * 86400) . "'");
        }
        // История значений уже обслуживается HistoryWriter: не подменяем её
        // настройки старым безусловным удалением всего старше пяти дней.
        return array('audit_rows' => $audit, 'system_events' => $events, 'errors' => $errors);
    }

    private function deleteBatch(string $table, string $where): int
    {
        $rows = $this->db->select("SELECT ID FROM $table WHERE $where ORDER BY ID LIMIT 1000");
        if (!$rows) return 0;
        $ids = implode(',', array_map('intval', array_column($rows, 'ID')));
        $this->db->requireExec("DELETE FROM $table WHERE ID IN ($ids) AND $where");
        return $this->db->affectedRows();
    }

    private function backup(): array
    {
        $directory = $this->backupRoot() . '/' . date('Ymd');
        if ($this->completeBackup($directory)) return array('directory' => $directory, 'already_complete' => true);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new \RuntimeException('Не удалось создать каталог резервной копии');
        $file = $directory . '/database.sql';
        if ($this->dump !== null) ($this->dump)($file);
        else $this->dumpDatabase($file);
        if (!is_file($file) || filesize($file) === 0) throw new \RuntimeException('Дамп БД пуст или отсутствует');
        if (!chmod($file, 0600)) throw new \RuntimeException('Не удалось защитить дамп БД');
        $cms = $this->root . '/cms';
        if (is_dir($cms)) {
            foreach (new \FilesystemIterator($cms, \FilesystemIterator::SKIP_DOTS) as $item) {
                if ($item->isLink() || in_array($item->getFilename(), array('cached', 'debmes', 'saverestore', 'cycles'), true)) continue;
                $source = realpath($item->getPathname());
                $destination = realpath($directory);
                // Пользователь мог разместить BACKUP_PATH внутри CMS: резервная
                // копия не должна рекурсивно копировать саму себя.
                if ($source !== false && $destination !== false && ($source === $destination || str_starts_with($destination, $source . DIRECTORY_SEPARATOR))) continue;
                $this->copyPath($item->getPathname(), $directory . '/cms/' . $item->getFilename());
            }
        }
        if (file_put_contents($directory . '/complete.json', json_encode(array('completed_at' => time(), 'database' => 'database.sql'), JSON_THROW_ON_ERROR), LOCK_EX) === false) {
            throw new \RuntimeException('Не удалось подтвердить завершение резервной копии');
        }
        return array('directory' => $directory, 'bytes' => filesize($file));
    }

    /** Один снимок InnoDB; большой аудит не удерживает общую read-lock на всех таблицах. */
    private function dumpDatabase(string $file): void
    {
        $credentials = tempnam(sys_get_temp_dir(), 'mjd-backup-');
        if ($credentials === false) throw new \RuntimeException('Не удалось создать параметры дампа');
        $part = $file . '.part';
        try {
            if (!chmod($credentials, 0600)) throw new \RuntimeException('Не удалось защитить параметры дампа');
            $quote = static fn($value) => '"' . str_replace(array('\\', '"', "\r", "\n"), array('\\\\', '\\"', '\\r', '\\n'), (string)$value) . '"';
            $options = "[client]\n";
            foreach (array('host' => 'DB_HOST', 'user' => 'DB_USER', 'password' => 'DB_PASSWORD') as $option => $key) $options .= $option . '=' . $quote(Config::string($key)) . "\n";
            $options .= 'port=' . (Config::int('DB_PORT') ?: 3306) . "\n";
            if (file_put_contents($credentials, $options, LOCK_EX) === false) throw new \RuntimeException('Не удалось записать параметры дампа');
            if (file_put_contents($part, '') === false || !chmod($part, 0600)) throw new \RuntimeException('Не удалось защитить временный дамп');
            $command = array(\SQLGetMysqlDumpPath(), '--defaults-extra-file=' . $credentials,
                '--single-transaction', '--quick', '--skip-lock-tables', '--no-tablespaces', Config::string('DB_NAME'));
            $output = array();
            if (\SQLRunExternalCommand($command, null, $part, $output) !== 0 || !is_file($part) || filesize($part) === 0) {
                throw new \RuntimeException('Дамп БД не завершён: ' . substr(implode("\n", $output), -2000));
            }
            if (!chmod($part, 0600) || !rename($part, $file)) throw new \RuntimeException('Не удалось сохранить дамп БД');
        } finally {
            unlink($credentials);
            if (is_file($part)) unlink($part);
        }
    }

    private function files(): array
    {
        $removed = 0;
        $logs = defined('SETTINGS_SYSTEM_DEBMES_PATH') && SETTINGS_SYSTEM_DEBMES_PATH !== '' ? SETTINGS_SYSTEM_DEBMES_PATH
            : (Config::string('LOG_DIRECTORY') ?: $this->root . '/cms/debmes');
        $cutoff = time() - max(1, Config::int('LOG_FILES_EXPIRE', 5)) * 86400;
        if (is_dir($logs) && !is_link($logs)) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($logs, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iterator as $item) {
                if ($item->isLink() || str_starts_with($item->getFilename(), '.')) continue;
                if ($item->isFile() && $item->getMTime() < $cutoff) {
                    if (!unlink($item->getPathname())) throw new \RuntimeException('Не удалось удалить старый журнал');
                    $removed++;
                }
                if ($item->isDir()) \removeEmptySubFolders($item->getPathname());
            }
        }
        $backups = 0;
        $base = $this->backupRoot();
        $cutoff = time() - max(1, Config::int('BACKUP_FILES_EXPIRE', 30)) * 86400;
        if (is_dir($base)) foreach (new \FilesystemIterator($base, \FilesystemIterator::SKIP_DOTS) as $item) {
            if ($item->isLink() || !$item->isDir() || !preg_match('/^\d{8}$/D', $item->getFilename())) continue;
            $date = \DateTimeImmutable::createFromFormat('!Ymd', $item->getFilename());
            if ($date === false || $date->format('Ymd') !== $item->getFilename() || $date->getTimestamp() >= $cutoff) continue;
            // Каталог automatic принадлежит только этому сервису. Просроченные
            // неполные копии тоже удаляем, иначе сбои постепенно заполнят диск.
            $resolved = realpath($item->getPathname());
            if ($resolved === false || dirname($resolved) !== realpath($base)) throw new \RuntimeException('Каталог копии выходит за разрешённые границы');
            $this->removePath($resolved);
            $backups++;
        }
        return array('log_files' => $removed, 'backups' => $backups);
    }

    private function tables(): array
    {
        $tables = $this->db->select("SELECT TABLE_NAME,ENGINE FROM information_schema.tables WHERE TABLE_SCHEMA='" . $this->db->escape(Config::string('DB_NAME')) . "' AND ENGINE='MyISAM'");
        $checked = $repaired = 0;
        $failures = array();
        foreach ($tables as $table) {
            $name = (string)$table['TABLE_NAME'];
            if (!preg_match('/^[a-zA-Z0-9_]+$/D', $name)) throw new \RuntimeException('Недопустимое имя таблицы');
            try {
                $result = $this->db->select("CHECK TABLE `$name` QUICK");
                $checked++;
                if ($this->tableOk($result)) continue;
                if (!$this->hasRecentBackup()) throw new \RuntimeException('Таблица ' . $name . ' требует ремонта, но нет завершённой копии за последние семь дней');
                // USE_FRM и EXTENDED не применяются автоматически: повреждение
                // структуры требует отдельного решения, а не агрессивного ремонта.
                if (!$this->tableOk($this->db->select("REPAIR TABLE `$name`"))) throw new \RuntimeException('Не удалось автоматически восстановить таблицу ' . $name);
                if (!$this->tableOk($this->db->select("CHECK TABLE `$name` QUICK"))) throw new \RuntimeException('Повторная проверка таблицы ' . $name . ' не пройдена');
                $repaired++;
            } catch (\Throwable $error) {
                $failures[] = $name . ': ' . $error->getMessage();
            }
        }
        if ($failures !== array()) throw new \RuntimeException(implode('; ', $failures));
        return array('checked_myisam' => $checked, 'repaired' => $repaired, 'innodb_full_check' => false);
    }

    private function tableOk(array $rows): bool
    {
        $ok = false;
        foreach ($rows as $row) {
            if (strtolower((string)($row['Msg_type'] ?? '')) === 'error') return false;
            if (strtolower((string)($row['Msg_type'] ?? '')) === 'status' && strtoupper((string)($row['Msg_text'] ?? '')) === 'OK') $ok = true;
        }
        return $ok;
    }

    private function hasRecentBackup(): bool
    {
        $base = $this->backupRoot();
        if (!is_dir($base)) return false;
        foreach (new \FilesystemIterator($base, \FilesystemIterator::SKIP_DOTS) as $item) {
            if ($item->isLink() || !$item->isDir() || !$this->completeBackup($item->getPathname())) continue;
            $data = json_decode(file_get_contents($item->getPathname() . '/complete.json'), true, 512, JSON_THROW_ON_ERROR);
            if ((int)($data['completed_at'] ?? 0) >= time() - 7 * 86400) return true;
        }
        return false;
    }

    private function completeBackup(string $directory): bool
    {
        if (is_link($directory) || !is_file($directory . '/complete.json') || !is_file($directory . '/database.sql') || filesize($directory . '/database.sql') === 0) return false;
        $data = json_decode(file_get_contents($directory . '/complete.json'), true);
        return is_array($data) && (int)($data['completed_at'] ?? 0) > 0 && ($data['database'] ?? '') === 'database.sql';
    }

    private function backupRoot(): string
    {
        $base = defined('SETTINGS_BACKUP_PATH') && SETTINGS_BACKUP_PATH !== '' ? SETTINGS_BACKUP_PATH : $this->root . '/backup';
        return rtrim($base, '/\\') . '/automatic';
    }

    private function copyPath(string $source, string $destination): void
    {
        if (is_link($source)) return;
        if (is_dir($source)) {
            if (!is_dir($destination) && !mkdir($destination, 0700, true) && !is_dir($destination)) throw new \RuntimeException('Не удалось создать каталог копии');
            foreach (new \FilesystemIterator($source, \FilesystemIterator::SKIP_DOTS) as $item) $this->copyPath($item->getPathname(), $destination . '/' . $item->getFilename());
        } else {
            if (!is_dir(dirname($destination)) && !mkdir(dirname($destination), 0700, true) && !is_dir(dirname($destination))) throw new \RuntimeException('Не удалось создать каталог копии');
            if (!copy($source, $destination) || !chmod($destination, 0600)) throw new \RuntimeException('Не удалось скопировать файл');
        }
    }

    private function removePath(string $path): void
    {
        if (is_link($path) || !is_dir($path)) {
            if (!unlink($path)) throw new \RuntimeException('Не удалось удалить файл копии');
            return;
        }
        foreach (new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS) as $item) $this->removePath($item->getPathname());
        if (!rmdir($path)) throw new \RuntimeException('Не удалось удалить каталог копии');
    }
}
