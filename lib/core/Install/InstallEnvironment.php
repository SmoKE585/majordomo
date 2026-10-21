<?php

namespace MajorDoMo\Core\Install;

use MajorDoMo\Core\Config;

/**
 * Read-only environment diagnostics for the first wizard step and the CLI.
 *
 * Every check returns a plain array so the same data can be rendered as cards
 * on the page and printed by `php tools/install.php check`.
 */
final class InstallEnvironment
{
    /** @var array<string,array<string,mixed>> */
    private array $databaseCache = array();

    public function __construct(private string $root)
    {
        $this->root = rtrim($root, '/\\');
    }

    /**
     * @return array<string,array{title:string,icon:string,items:array<int,array<string,mixed>>}>
     */
    public function checks(): array
    {
        return array(
            'php' => array(
                'title' => 'PHP и сервер',
                'icon' => 'brand-php',
                'items' => $this->phpItems(),
            ),
            'extensions' => array(
                'title' => 'Расширения PHP',
                'icon' => 'puzzle',
                'items' => $this->extensionItems(),
            ),
            'files' => array(
                'title' => 'Файлы и каталоги',
                'icon' => 'folder-cog',
                'items' => $this->fileItems(),
            ),
            'database' => array(
                'title' => 'База данных',
                'icon' => 'database',
                'items' => $this->databaseItems(),
            ),
        );
    }

    /**
     * @return array{fail:int,warn:int,ok:int,total:int,blocking:int}
     */
    public function summary(): array
    {
        $summary = array('fail' => 0, 'warn' => 0, 'ok' => 0, 'total' => 0, 'blocking' => 0);
        foreach ($this->checks() as $group) {
            foreach ($group['items'] as $item) {
                $summary['total']++;
                $status = (string)($item['status'] ?? 'info');
                if ($status === 'fail') {
                    $summary['fail']++;
                    if (!empty($item['blocking'])) {
                        $summary['blocking']++;
                    }
                } elseif ($status === 'warn') {
                    $summary['warn']++;
                } elseif ($status === 'ok') {
                    $summary['ok']++;
                }
            }
        }
        return $summary;
    }

    /**
     * DB connection status for the current `.env`.
     *
     * @return array{ok:bool,message:string,server:string,tables:string[],missing:string[],recommended_missing:string[],admin_exists:?bool,empty:bool,can_auto_import:bool,config:array<string,string>}
     */
    public function databaseStatus(?array $config = null): array
    {
        $config = $config ?? $this->currentDatabaseConfig();
        $cacheKey = md5((string)json_encode($config));
        if (isset($this->databaseCache[$cacheKey])) {
            return $this->databaseCache[$cacheKey];
        }
        return $this->databaseCache[$cacheKey] = $this->databaseStatusUncached($config);
    }

    /**
     * @param array<string,string> $config
     * @return array{ok:bool,message:string,server:string,tables:string[],missing:string[],recommended_missing:string[],admin_exists:?bool,config:array<string,string>}
     */
    private function databaseStatusUncached(array $config): array
    {
        $result = array(
            'ok' => false,
            'message' => '',
            'server' => '',
            'tables' => array(),
            'missing' => array(),
            'recommended_missing' => array(),
            'admin_exists' => null,
            'empty' => false,
            'can_auto_import' => false,
            'config' => $config,
        );

        if (trim((string)($config['host'] ?? '')) === '' || trim((string)($config['name'] ?? '')) === '') {
            $result['message'] = 'База данных ещё не настроена: заполните DB_HOST и DB_NAME на следующем шаге.';
            return $result;
        }

        $database = new InstallDatabase($config);
        if (!$database->connect()) {
            $result['message'] = $database->error();
            return $result;
        }

        $result['ok'] = true;
        $result['server'] = $database->serverInfo();
        $result['tables'] = $database->tables();
        $result['empty'] = $result['tables'] === array();
        $result['can_auto_import'] = $result['empty'];
        $required = array('settings', 'users');
        foreach ($required as $table) {
            if (!$database->tableExists($table)) {
                $result['missing'][] = $table;
            }
        }
        $recommended = array('classes', 'objects', 'properties', 'methods', 'project_modules');
        foreach ($recommended as $table) {
            if (!$database->tableExists($table)) {
                $result['recommended_missing'][] = $table;
            }
        }
        $result['admin_exists'] = $database->adminExists();
        $database->close();

        if ($result['missing'] !== array()) {
            if ($result['empty']) {
                $result['message'] = 'База пустая. При сохранении мастер создаст таблицы MajorDoMo из чистой схемы — демо-данные и пользователи в неё не входят.';
            } else {
                $result['message'] = 'В базе уже есть таблицы, но нет обязательных: ' . implode(', ', $result['missing'])
                    . '. Автоматический импорт доступен только для пустой базы: создайте пустую БД или импортируйте схему вручную.';
            }
        } elseif ($result['recommended_missing'] !== array()) {
            $result['message'] = 'Подключение работает. Часть таблиц появится после первого запуска модулей: '
                . implode(', ', $result['recommended_missing']) . '.';
        } else {
            $result['message'] = 'Подключение работает, обязательные таблицы на месте.';
        }
        return $result;
    }

    /**
     * @return array<string,string>
     */
    public function currentDatabaseConfig(): array
    {
        return array(
            'host' => Config::string('DB_HOST'),
            'port' => Config::string('DB_PORT'),
            'name' => Config::string('DB_NAME'),
            'user' => Config::string('DB_USER'),
            'password' => Config::string('DB_PASSWORD'),
        );
    }

    /**
     * @param string[] $keys
     * @return array{local:string[],process:string[],constant:string[]}
     */
    public function overrides(array $keys): array
    {
        $local = EnvFile::overriddenKeys($this->root, $keys);
        $process = array();
        $constant = array();
        foreach ($keys as $key) {
            $source = Config::source($key);
            if ($source === 'process') {
                $process[] = $key;
            } elseif ($source === 'constant') {
                $constant[] = $key;
            }
        }
        return array('local' => $local, 'process' => $process, 'constant' => $constant);
    }

    public function diskFree(): string
    {
        $free = @disk_free_space($this->root);
        return is_float($free) || is_int($free) ? self::humanBytes((float)$free) : 'неизвестно';
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function phpItems(): array
    {
        $version = PHP_VERSION;
        $versionStatus = version_compare($version, '8.4.0', '>=') ? 'ok' : (version_compare($version, '8.1.0', '>=') ? 'warn' : 'fail');
        $versionHint = $versionStatus === 'ok'
            ? 'Версия подходит.'
            : ($versionStatus === 'warn'
                ? 'MajorDoMo рассчитан на PHP 8.4+. Более старые версии могут давать предупреждения.'
                : 'Нужен PHP 8.1 или новее. Обновите PHP перед продолжением.');

        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

        return array(
            array(
                'id' => 'php-version',
                'label' => 'Версия PHP',
                'value' => $version,
                'status' => $versionStatus,
                'blocking' => $versionStatus === 'fail',
                'hint' => $versionHint,
            ),
            array(
                'id' => 'php-sapi',
                'label' => 'Веб-сервер',
                'value' => PHP_SAPI . (!empty($_SERVER['SERVER_SOFTWARE']) ? ' · ' . $_SERVER['SERVER_SOFTWARE'] : ''),
                'status' => 'info',
                'hint' => 'Целевая конфигурация — nginx + PHP-FPM.',
            ),
            array(
                'id' => 'os',
                'label' => 'Операционная система',
                'value' => PHP_OS_FAMILY . ' · ' . php_uname('m'),
                'status' => 'info',
            ),
            array(
                'id' => 'https',
                'label' => 'HTTPS',
                'value' => $https ? 'включён' : 'выключен',
                'status' => $https ? 'ok' : 'warn',
                'hint' => $https ? 'Соединение защищено.' : 'Для доступа из интернета настройте HTTPS и укажите адрес https:// в BASE_URL.',
            ),
            array(
                'id' => 'disk',
                'label' => 'Свободно на диске',
                'value' => $this->diskFree(),
                'status' => 'info',
                'hint' => 'Резервные копии и история требуют свободного места.',
            ),
            array(
                'id' => 'timezone',
                'label' => 'Часовой пояс PHP',
                'value' => date_default_timezone_get(),
                'status' => 'info',
            ),
        );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function extensionItems(): array
    {
        $extensions = array(
            'mysqli' => array('MySQL', true, 'Без него нет подключения к базе.'),
            'mbstring' => array('mbstring', true, 'Нужен для UTF-8 и русского языка.'),
            'json' => array('JSON', true, 'Нужен для настроек и API.'),
            'session' => array('session', true, 'Нужен для входа и защиты формы.'),
            'openssl' => array('OpenSSL', false, 'Нужен для HTTPS-запросов и OAuth.'),
            'curl' => array('cURL', false, 'Нужен для обновлений и интеграций.'),
            'sockets' => array('sockets', false, 'Нужен для WebSocket-сервера.'),
            'redis' => array('redis', false, 'Необязательно: нужен только при включённом Redis.'),
            'fileinfo' => array('fileinfo', false, 'Нужен для загрузки файлов.'),
        );

        $items = array();
        foreach ($extensions as $extension => $definition) {
            [$label, $required, $hint] = $definition;
            $loaded = extension_loaded($extension);
            $items[] = array(
                'id' => 'ext-' . $extension,
                'label' => $label,
                'value' => $loaded ? 'загружено' : 'не загружено',
                'status' => $loaded ? 'ok' : ($required ? 'fail' : 'info'),
                'blocking' => !$loaded && $required,
                'hint' => $loaded ? '' : $hint,
            );
        }
        return $items;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function fileItems(): array
    {
        $items = array();

        $envPath = $this->root . DIRECTORY_SEPARATOR . '.env';
        if (is_file($envPath)) {
            $items[] = array(
                'id' => 'env',
                'label' => '.env',
                'value' => is_writable($envPath) ? 'существует, доступен для записи' : 'существует, только чтение',
                'status' => is_writable($envPath) ? 'ok' : 'fail',
                'blocking' => !is_writable($envPath),
                'hint' => is_writable($envPath) ? 'Мастер обновит значения и сделает резервную копию.' : 'Выдайте веб-серверу права на запись в .env.',
            );
        } else {
            $items[] = array(
                'id' => 'env',
                'label' => '.env',
                'value' => 'будет создан',
                'status' => is_writable($this->root) ? 'warn' : 'fail',
                'blocking' => !is_writable($this->root),
                'hint' => is_writable($this->root) ? 'Файл будет создан автоматически.' : 'Корневой каталог недоступен для записи — .env создать не получится.',
            );
        }

        $localPath = $this->root . DIRECTORY_SEPARATOR . '.env.local';
        if (is_file($localPath)) {
            $items[] = array(
                'id' => 'env-local',
                'label' => '.env.local',
                'value' => 'существует',
                'status' => 'warn',
                'hint' => 'Значения из .env.local перекрывают .env. Если ключ задан там, мастер не сможет его изменить.',
            );
        }

        $paths = array(
            'cms/cached' => array('Кэш', true),
            'cms/debmes' => array('Журналы', true),
            'cms/modules_installed' => array('Маркеры модулей', true),
            'cms/saverestore' => array('Резервные копии', false),
            'cms/images' => array('Изображения', false),
        );
        foreach ($paths as $relative => $definition) {
            [$label, $required] = $definition;
            $path = $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $exists = is_dir($path);
            $writable = $exists ? is_writable($path) : is_writable(dirname($path));
            $status = $writable ? ($exists ? 'ok' : 'warn') : ($required ? 'fail' : 'warn');
            $items[] = array(
                'id' => 'path-' . str_replace('/', '-', $relative),
                'label' => $label,
                'value' => $relative . ($exists ? '' : ' (будет создан)'),
                'status' => $status,
                'blocking' => !$writable && $required,
                'hint' => $writable ? '' : 'Нет прав на запись. Выполните chown/chmod для каталога ' . $relative . '.',
            );
        }

        return $items;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function databaseItems(): array
    {
        $status = $this->databaseStatus();
        $items = array();

        if ($status['ok']) {
            $items[] = array(
                'id' => 'db-connection',
                'label' => 'Подключение',
                'value' => 'MySQL ' . $status['server'] . ' · ' . (string)($status['config']['name'] ?? ''),
                'status' => $status['missing'] === array() ? 'ok' : 'fail',
                'blocking' => false,
                'hint' => $status['message'],
            );
            if ($status['missing'] !== array()) {
                $items[] = array(
                    'id' => 'db-tables',
                    'label' => 'Обязательные таблицы',
                    'value' => 'нет: ' . implode(', ', $status['missing']),
                    'status' => 'fail',
                    'blocking' => false,
                    'hint' => !empty($status['empty'])
                        ? 'База пустая — мастер создаст таблицы автоматически, если включено «Создать таблицы автоматически».'
                        : 'Автоматический импорт доступен только для пустой базы. Создайте пустую БД или импортируйте схему вручную.',
                );
            } elseif ($status['recommended_missing'] !== array()) {
                $items[] = array(
                    'id' => 'db-recommended',
                    'label' => 'Таблицы модулей',
                    'value' => 'появятся позже: ' . implode(', ', $status['recommended_missing']),
                    'status' => 'warn',
                    'hint' => 'Обычно они создаются при первом входе в панель.',
                );
            }
            if ($status['admin_exists'] === true) {
                $items[] = array(
                    'id' => 'db-admin',
                    'label' => 'Администратор',
                    'value' => 'уже существует',
                    'status' => 'ok',
                    'hint' => 'Создавать нового администратора не нужно.',
                );
            } elseif ($status['admin_exists'] === false) {
                $items[] = array(
                    'id' => 'db-admin',
                    'label' => 'Администратор',
                    'value' => 'не найден',
                    'status' => 'warn',
                    'hint' => 'Мастер предложит создать учётную запись на шаге «Система».',
                );
            }
        } else {
            $items[] = array(
                'id' => 'db-connection',
                'label' => 'Подключение',
                'value' => 'ещё не настроено',
                'status' => 'warn',
                'hint' => $status['message'],
            );
        }

        return $items;
    }

    private static function humanBytes(float $bytes): string
    {
        $units = array('Б', 'КБ', 'МБ', 'ГБ', 'ТБ');
        $index = 0;
        while ($bytes >= 1024 && $index < count($units) - 1) {
            $bytes /= 1024;
            $index++;
        }
        return number_format($bytes, $index === 0 ? 0 : 1, '.', ' ') . ' ' . $units[$index];
    }
}
