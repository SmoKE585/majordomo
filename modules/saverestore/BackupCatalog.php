<?php

declare(strict_types=1);

namespace MajorDoMo\Modules\SaveRestore;

/**
 * Каталог копий отделяет пользовательские архивы от служебных файлов обновления.
 *
 * Изменения:
 * 2026-11-11 — назначение, состав по имени, дата копии и единые границы скачивания.
 */
final class BackupCatalog
{
    public function __construct(private string $local, private string $storage) {}

    public function all(): array
    {
        $items = array();
        $seen = array();
        foreach (array('local' => $this->local, 'storage' => $this->storage) as $source => $directory) {
            $root = realpath($directory);
            if ($root === false || !is_dir($root)) continue;
            foreach (new \DirectoryIterator($root) as $file) {
                if ($file->isDot() || str_starts_with($file->getFilename(), '.')) continue;
                $path = $file->getRealPath();
                if ($path === false || isset($seen[$path]) || !str_starts_with($path, $root . DIRECTORY_SEPARATOR)) continue;
                $entry = $this->describe($path, $file->getFilename());
                if ($entry === null) continue;
                $seen[$path] = true;
                $entry['PATH'] = $path;
                $entry['SOURCE'] = $source;
                $entry['SOURCE_TITLE'] = $source === 'local' ? 'Каталог обновления' : 'Каталог резервных копий';
                $entry['SOURCE_PATH'] = $root;
                $items[] = $entry;
            }
        }
        usort($items, static fn(array $a, array $b): int => ($b['TIMESTAMP'] <=> $a['TIMESTAMP'])
            ?: strcmp($a['NAME'], $b['NAME']) ?: strcmp($a['SOURCE'], $b['SOURCE']));
        return $items;
    }

    /** Принимаем только имя из каталога, а не произвольный путь от браузера. */
    public function download(string $source, string $name): ?array
    {
        if (!in_array($source, array('local', 'storage'), true) || $name === '' || $name[0] === '.' || strpbrk($name, "/\\\0\r\n") !== false) return null;
        $root = realpath($source === 'local' ? $this->local : $this->storage);
        if ($root === false) return null;
        $path = realpath($root . DIRECTORY_SEPARATOR . $name);
        if ($path === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR)) return null;
        $entry = $this->describe($path, $name);
        return $entry !== null && $entry['CAN_DOWNLOAD'] ? array_merge($entry, array('PATH' => $path)) : null;
    }

    private function describe(string $path, string $name): ?array
    {
        // master.tgz — дистрибутив обновления, temp — незавершённая рабочая папка.
        if (in_array(strtolower($name), array('master.tgz', 'master.tar.gz', 'master.tar', 'temp'), true)) return null;
        $directory = is_dir($path);
        if (!$directory && (!is_file($path) || !preg_match('/\.(sql(?:\.gz)?|tgz|tar(?:\.gz)?)$/iD', $name))) return null;
        $kind = $directory ? 'directory' : 'archive';
        $title = $directory ? 'Копия в папке' : 'Архив резервной копии';
        $contents = $directory ? 'Состав папки не проверен' : 'Состав не указан в имени';
        $compressedSql = (bool)preg_match('/\.sql\.gz$/iD', $name);
        if (!$directory && preg_match('/\.sql(?:\.gz)?$/iD', $name)) {
            $kind = 'database';
            $title = $compressedSql ? 'Сжатая копия базы данных' : 'Копия базы данных';
            $contents = 'Только база данных';
            if (str_starts_with($name, 'db_before_update_')) {
                $kind = 'before_update';
                $title = 'База данных перед обновлением';
            }
        } elseif (!$directory && preg_match('/^(?:backup_)?((?:design_|code_|data_|files_)+)\d{4}-\d{2}-\d{2}__\d{2}-\d{2}-\d{2}\.(?:tgz|tar(?:\.gz)?)$/D', $name, $parts)) {
            $labels = array('data' => 'база данных', 'code' => 'код и модули', 'design' => 'оформление', 'files' => 'файлы данных');
            $included = array();
            foreach ($labels as $key => $label) if (str_contains($parts[1], $key . '_')) $included[] = $label;
            $contents = 'По имени: ' . implode(', ', $included);
        }
        $timestamp = (int)filemtime($path);
        $dateLabel = $directory ? 'Изменена папка' : 'Изменён файл';
        if (preg_match('/(\d{4}-\d{2}-\d{2}__\d{2}-\d{2}-\d{2})(?=\.|$)/D', $name, $match)) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d__H-i-s', $match[1]);
            if ($date !== false && $date->format('Y-m-d__H-i-s') === $match[1]) {
                $timestamp = $date->getTimestamp();
                $dateLabel = 'Дата из имени';
            }
        }
        $readable = is_readable($path);
        return array('NAME' => $name, 'TITLE' => $title, 'KIND' => $kind, 'CONTENTS' => $contents,
            'TIMESTAMP' => $timestamp, 'DATE' => date('d.m.Y H:i:s', $timestamp), 'DATE_LABEL' => $dateLabel,
            'SIZE' => $directory ? '—' : number_format(filesize($path) / 1048576, 2, ',', ' ') . ' МБ',
            'CAN_DOWNLOAD' => !$directory && $readable, 'CAN_RESTORE' => $readable && !$compressedSql,
            'NOTE' => !$readable ? 'Нет прав на чтение.' : ($directory ? 'Папка: скачивание одним файлом недоступно.'
                : ($compressedSql ? 'Перед восстановлением распакуйте файл .gz.' : '')));
    }
}
