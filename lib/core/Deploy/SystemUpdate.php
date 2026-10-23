<?php

namespace MajorDoMo\Core\Deploy;

use MajorDoMo\Core\Config;
use MajorDoMo\Core\Cycles\CycleMaintenance;
use MajorDoMo\Core\Cycles\CycleManager;
use MajorDoMo\Core\Maintenance\MaintenanceMode;
use RuntimeException;
use Throwable;

/**
 * Системное обновление из терминала.
 *
 * Порядок шагов повторяет веб-обновление (модуль saverestore), чтобы оба пути
 * оставляли одинаковые артефакты: `cms/saverestore/update.pending.json`,
 * манифест установленных файлов, режим обслуживания и состояние деплоя.
 * Логика замены файлов одна и та же, но здесь она не зависит от HTTP-сессии,
 * поэтому обновление можно запускать из SSH, когда панель отвечает как попало.
 *
 * Отличия от веб-пути осознанные:
 * - версия не пишется в конфиг модуля (это делает `reconcilePendingUpdate()`
 *   при первом открытии панели), зато pending-файл остаётся со статусом
 *   `applied`, поэтому запись восстановится сама;
 * - перед запуском берётся файловая блокировка `cms/deploy/update.lock`,
 *   чтобы два обновления не скачивали архив в один и тот же master.tgz.
 *
 * 2026-10-23 — добавлено для `php majordomo deploy:update` и deploy.sh.
 */
final class SystemUpdate
{
    /** Файл-замок на время обновления (не даёт двум процессам мешать друг другу). */
    private const LOCK_RELATIVE = 'cms/deploy/update.lock';

    /** Цель обновления, записанная до замены файлов (формат модуля saverestore). */
    private const PENDING_RELATIVE = 'cms/saverestore/update.pending.json';

    /** Манифест файлов, поставленных системным обновлением. */
    private const MANIFEST_RELATIVE = 'cms/saverestore/system_update_manifest.json';

    private string $root;
    /** @var array<string,mixed> */
    private array $options;
    private CommandRunner $runner;
    private Deployment $deployment;
    private FileRemovals $removals;
    private ?\Closure $logHandler = null;
    private ?\Closure $stepHandler = null;
    private ?MaintenanceMode $maintenance = null;
    private ?CycleMaintenance $cycleMaintenance = null;
    private ?CycleManager $cycleManager = null;
    private bool $applied = false;
    private string $updateFrom = '';
    private string $updateTo = '';
    private string $updateBranch = '';
    /** Источник, выбранный в панели (кэш одного чтения). */
    private string $panelUpdateUrl = '';
    private bool $panelUpdateUrlLoaded = false;
    /** Откуда взят адрес архива: --url, панель или .env. */
    private string $updateUrlSource = '';

    /** @param array<string,mixed> $options */
    public function __construct(string $root, array $options = array())
    {
        $this->root = rtrim($root, '/\\');
        $this->options = $options + array(
            'url' => '',
            'branch' => '',
            'backup' => true,
            'restart' => true,
            'force' => false,
            'keep_temp' => false,
            'insecure' => false,
        );
        $this->runner = new CommandRunner($this->root);
        $this->deployment = new Deployment($this->root, $this->runner);
        $this->removals = new FileRemovals($this->root);
    }

    public function root(): string
    {
        return $this->root;
    }

    public function deployment(): Deployment
    {
        return $this->deployment;
    }

    /**
     * Источник обновления: --url, затем выбор панели, затем .env.
     *
     * Панель хранит ветку в конфиге модуля `saverestore` (`project_modules.DATA`),
     * а `.env` — в `GIT_URL`. Это два разных места, и CLI обязан читать то же,
     * что панель: иначе `deploy.sh` скачивает master, пока панель обновляется с
     * alpha, и в проект попадает чужое дерево.
     */
    public function updateUrl(): string
    {
        $branch = trim((string)$this->options['branch']);
        $url = trim((string)$this->options['url']);
        $this->updateUrlSource = 'аргумент --url';
        if ($url === '') {
            $url = $this->panelUpdateUrl();
            $this->updateUrlSource = 'настройка панели';
        }
        if ($url === '') {
            $url = trim(Config::string('MASTER_UPDATE_URL', ''));
            $this->updateUrlSource = '.env';
        }
        if ($url === '') {
            $git = trim(Config::string('GIT_URL', ''));
            if ($git !== '') {
                $url = rtrim($git, '/') . '/archive/' . ($branch !== '' ? $branch : 'master') . '.tar.gz';
                $this->updateUrlSource = '.env (GIT_URL)';
            }
        }
        if ($url !== '' && $branch !== '') {
            $url = self::withBranch($url, $branch);
            $this->updateUrlSource .= ', ветка из --branch';
        }
        return $url;
    }

    /** Откуда взялся адрес архива: для журнала и проверок. */
    public function updateUrlSource(): string
    {
        if ($this->updateUrlSource === '') {
            $this->updateUrl();
        }
        return $this->updateUrlSource;
    }

    /**
     * Адрес архива, выбранный в панели «Обновление системы».
     *
     * Читаем то же поле, что и модуль: `project_modules.DATA` для `saverestore`.
     * Любая проблема (нет mysqli, нет таблицы, битые данные) — молча пустая
     * строка, тогда работает `.env`. Ошибка чтения не должна ломать обновление.
     */
    private function panelUpdateUrl(): string
    {
        if ($this->panelUpdateUrlLoaded) {
            return $this->panelUpdateUrl;
        }
        $this->panelUpdateUrlLoaded = true;
        $this->panelUpdateUrl = '';
        if (!extension_loaded('mysqli')) {
            return '';
        }
        $host = trim(Config::string('DB_HOST', ''));
        $database = trim(Config::string('DB_NAME', ''));
        if ($host === '' || $database === '') {
            return '';
        }
        try {
            $connection = mysqli_init();
            if ($connection === false) {
                return '';
            }
            $connection->options(MYSQLI_OPT_CONNECT_TIMEOUT, 3);
            if (!$connection->real_connect(
                $host,
                Config::string('DB_USER', ''),
                Config::string('DB_PASSWORD', ''),
                $database,
                Config::int('DB_PORT') ?: 3306
            )) {
                return '';
            }
            $connection->set_charset('utf8mb4');
            $result = $connection->query("SELECT DATA FROM project_modules WHERE NAME = 'saverestore' LIMIT 1");
            if ($result instanceof \mysqli_result) {
                $row = $result->fetch_assoc();
                $result->free();
                if (is_array($row) && !empty($row['DATA'])) {
                    $config = @unserialize((string)$row['DATA'], array('allowed_classes' => false));
                    if (is_array($config) && !empty($config['MASTER_UPDATE_URL'])) {
                        $this->panelUpdateUrl = trim((string)$config['MASTER_UPDATE_URL']);
                    }
                }
            }
            $connection->close();
        } catch (Throwable $error) {
            $this->panelUpdateUrl = '';
        }
        return $this->panelUpdateUrl;
    }

    /**
     * Проверки до любых изменений. Формат совпадает с Deployment::preflight(),
     * чтобы command и deploy.sh показывали один и тот же список.
     *
     * @return array<int,array{title:string,details:string,ok:bool,fatal:bool}>
     */
    public function checks(): array
    {
        $checks = array();
        $checks[] = $this->check(
            'PHP CLI',
            PHP_VERSION . ' (' . PHP_SAPI . ')',
            PHP_SAPI === 'cli' && version_compare(PHP_VERSION, '8.1', '>='),
            true
        );
        $url = $this->updateUrl();
        $source = $this->updateUrlSource();
        $checks[] = $this->check(
            'Источник обновления',
            $url !== ''
                ? $url . ($source !== '' ? ' (' . $source . ')' : '')
                : 'не задан: укажите ветку в панели, заполните GIT_URL в .env или передайте --url',
            $url !== '',
            true
        );
        $checks[] = $this->check(
            'Расширение cURL',
            function_exists('curl_init') ? 'доступно' : 'нет, архив будет скачан потоком',
            function_exists('curl_init'),
            false
        );
        $tar = $this->runner->run(array('tar', '--version'), 15);
        $checks[] = $this->check(
            'Команда tar',
            $tar['ok'] ? trim((string)strtok($tar['out'], "\n")) : 'не найдена в системе',
            $tar['ok'],
            true
        );

        if (!empty($this->options['backup'])) {
            $dump = trim(Config::string('PATH_TO_MYSQLDUMP', 'mysqldump'));
            $binary = $this->runner->find($dump !== '' ? $dump : 'mysqldump');
            $database = trim(Config::string('DB_NAME', ''));
            $details = 'не найден: задайте PATH_TO_MYSQLDUMP в .env';
            if ($binary !== '') {
                $details = $database === '' ? 'не задан DB_NAME в .env' : $binary . ', база ' . $database;
            }
            $checks[] = $this->check(
                'Резервная копия базы',
                $details,
                $binary !== '' && $database !== '',
                true
            );
        }

        $directories = array(
            $this->root => 'корень проекта: нужен для новых файлов',
            $this->root . '/cms' => 'состояние деплоя и режим обслуживания',
            $this->root . '/cms/deploy' => 'состояние деплоя и кэш Composer',
            $this->root . '/cms/saverestore' => 'архив обновления и дамп базы',
            $this->root . '/cms/cycles' => 'пауза фоновых процессов',
            $this->root . '/database_backup' => 'резервные копии базы',
            $this->root . '/public/assets' => 'сборка фронтенда',
        );
        foreach ($directories as $path => $why) {
            $ok = is_dir($path) ? is_writable($path) : $this->canWritePath($path);
            $checks[] = $this->check(
                'Запись: ' . ($path === $this->root ? 'корень проекта' : $this->relative($path)),
                $ok ? $why : 'нет прав на запись — ' . $why,
                $ok,
                true
            );
        }

        try {
            foreach ($this->deployment->preflight() as $check) {
                $title = (string)($check['title'] ?? '');
                $fatal = !empty($check['fatal']);
                // Без перезапуска службы отсутствие wrapper/systemctl не мешает:
                // обновление файлов и зависимостей от него не зависит.
                if (empty($this->options['restart']) && $title === 'Управление службой') {
                    $fatal = false;
                }
                $checks[] = array(
                    'title' => 'Деплой: ' . $title,
                    'details' => (string)($check['details'] ?? ''),
                    'ok' => !empty($check['ok']),
                    'fatal' => $fatal,
                );
            }
        } catch (Throwable $error) {
            $checks[] = $this->check('Деплой: проверка окружения', $error->getMessage(), false, true);
        }

        $maintenance = new MaintenanceMode($this->root);
        $status = $maintenance->status();
        if (!empty($status['active'])) {
            $checks[] = $this->check(
                'Режим обслуживания',
                'включён (' . ($status['reason'] !== '' ? $status['reason'] : 'без причины') . '), будет перезаписан',
                true,
                false
            );
        }
        if (is_file($this->root . '/cms/cycles/deploy.hold')) {
            $checks[] = $this->check(
                'Удержание циклов',
                'есть cms/cycles/deploy.hold — остаток неудачного деплоя, будет снят после успеха',
                true,
                false
            );
        }
        $free = @disk_free_space($this->root);
        $checks[] = $this->check(
            'Свободное место',
            $free !== false ? $this->formatBytes((float)$free) : 'не удалось определить',
            $free === false || $free > 200 * 1024 * 1024,
            false
        );
        return $checks;
    }

    /**
     * Полный пайплайн.
     *
     * @param callable|null $log  function(string $message, string $kind = 'info'): void
     * @param callable|null $step function(string $id, string $title): void
     * @param callable|null $commit function(string $from, string $to, string $branch, Deployment $deployment): void
     * @return array<string,mixed>
     */
    public function run(?callable $log = null, ?callable $step = null, ?callable $commit = null): array
    {
        $started = microtime(true);
        $this->logHandler = $log !== null ? \Closure::fromCallable($log) : null;
        $this->stepHandler = $step !== null ? \Closure::fromCallable($step) : null;
        $this->applied = false;
        $this->updateFrom = '';
        $this->updateTo = '';
        $this->updateBranch = '';
        $lock = $this->acquireLock();

        try {
            $this->emitStep('prepare', 'Проверка окружения');
            $failed = array();
            foreach ($this->checks() as $check) {
                $this->reportCheck($check);
                if (!empty($check['fatal']) && empty($check['ok'])) {
                    $failed[] = (string)$check['title'];
                }
            }
            if ($failed !== array()) {
                throw new RuntimeException(
                    'Проверки не пройдены: ' . implode(', ', $failed) . '. Обновление не начато.'
                );
            }

            $this->emitStep('target', 'Источник и версия');
            $source = new UpdateSource($this->updateUrl(), empty($this->options['insecure']), 60);
            $target = $source->latest();
            $this->updateFrom = $this->installedVersion();
            $this->updateTo = (string)$target['commit'];
            $this->updateBranch = (string)$target['branch'];
            $this->write('Коммит: ' . self::shortCommit($this->updateTo) . ' · ветка ' . $this->updateBranch, 'info');
            $this->write('Источник: ' . $this->updateUrlSource() . ' · ' . $target['update_url'], 'muted');
            $this->write('Архив: ' . $target['archive_url'], 'muted');
            if ($this->updateFrom !== '') {
                $this->write('Установлено сейчас: ' . self::shortCommit($this->updateFrom), 'info');
            } else {
                $this->write('Текущая версия неизвестна — обновление будет выполнено', 'muted');
            }
            if (empty($this->options['force']) && $this->updateFrom !== '' && $this->updateFrom === $this->updateTo) {
                $this->write('Установлена последняя версия, обновление не требуется', 'success');
                return array(
                    'ok' => true,
                    'up_to_date' => true,
                    'from' => $this->updateFrom,
                    'to' => $this->updateTo,
                    'branch' => $this->updateBranch,
                    'duration_ms' => (int)round((microtime(true) - $started) * 1000),
                );
            }

            $this->emitStep('backup', 'Резервная копия базы');
            $backup = $this->backupDatabase();

            $this->emitStep('download', 'Скачивание архива');
            $archive = $this->downloadArchive((string)$target['archive_url']);

            $this->emitStep('unpack', 'Распаковка архива');
            $updateRoot = $this->unpackArchive($archive['path']);
            $this->verifyTargetWritable($updateRoot);

            $this->emitStep('stop', 'Остановка фоновых процессов');
            $this->pauseCycles();

            $this->emitStep('apply', 'Замена файлов');
            $this->writePending(array(
                'status' => 'downloaded',
                'commit' => $this->updateTo,
                'branch' => $this->updateBranch,
                'update_url' => (string)$target['update_url'],
                'download_url' => (string)$target['archive_url'],
                'archive' => basename($archive['path']),
                'archive_sha1' => $archive['sha1'],
                'created_at' => date('c'),
            ));
            $manifest = $this->applyFiles($updateRoot);

            $this->emitStep('dependencies', 'Зависимости, сборка и миграции');
            $deployLog = function (string $message, string $kind = 'info'): void {
                $this->write($message, $kind);
            };
            $this->deployment->composerInstall($deployLog);
            $this->deployment->verifyAutoload($deployLog);
            $this->deployment->buildAssets($deployLog);
            $this->deployment->runMigrations($deployLog);
            $this->saveManifest($manifest);
            $this->writePending(array('status' => 'applied', 'applied_at' => date('c')));

            $this->emitStep('restart', 'Перезапуск службы');
            // Пауза циклов снимается до перезапуска службы: новый супервизор
            // не должен ждать чужую блокировку, пока systemd поднимает службу.
            // При сбое перезапуска fail() снова удержит циклы.
            $this->releaseCycles();
            if (!empty($this->options['restart'])) {
                $restart = $this->deployment->restartService($deployLog);
                if (empty($restart['ok'])) {
                    throw new RuntimeException(
                        'Файлы и зависимости обновлены, но служба не перезапустилась: '
                        . trim((string)(($restart['err'] ?? '') !== '' ? $restart['err'] : ($restart['out'] ?? '')))
                    );
                }
            } else {
                $this->write('Перезапуск службы отключён (--no-restart): перезапустите её вручную', 'warning');
            }

            $this->emitStep('finish', 'Завершение');
            if ($commit !== null) {
                $commit($this->updateFrom, $this->updateTo, $this->updateBranch, $this->deployment);
            } else {
                try {
                    $this->deployment->writeState(array(
                        'status' => 'success',
                        'from' => $this->updateFrom,
                        'to' => $this->updateTo,
                        'branch' => $this->updateBranch,
                    ));
                } catch (Throwable $stateError) {
                    // Обновление уже применено и служба перезапущена: сбой
                    // записи состояния не делает его неуспешным.
                    $this->write('Не удалось записать состояние деплоя: ' . $stateError->getMessage(), 'warning');
                }
            }
            $this->complete();
            if (empty($this->options['keep_temp'])) {
                $this->cleanupTemp();
            }

            $duration = (int)round((microtime(true) - $started) * 1000);
            $this->write(
                'Готово: ' . self::shortCommit($this->updateFrom) . ' → ' . self::shortCommit($this->updateTo)
                . ' за ' . $this->formatDuration($duration / 1000),
                'success'
            );
            return array(
                'ok' => true,
                'up_to_date' => false,
                'from' => $this->updateFrom,
                'to' => $this->updateTo,
                'branch' => $this->updateBranch,
                'archive_url' => (string)$target['archive_url'],
                'archive_sha1' => $archive['sha1'],
                'archive_size' => $archive['size'],
                'backup' => (string)($backup['file'] ?? ''),
                'files' => count($manifest),
                'duration_ms' => $duration,
            );
        } catch (Throwable $error) {
            $this->fail($error);
            throw $error;
        } finally {
            $this->releaseLock($lock);
        }
    }

    /**
     * Определить уже установленную версию.
     *
     * Pending-файл со статусом `applied` важнее состояния деплоя: он означает,
     * что файлы заменены, но запись версии ещё не сделана.
     */
    public function installedVersion(): string
    {
        $pending = $this->readJson($this->root . '/' . self::PENDING_RELATIVE);
        if (($pending['status'] ?? '') === 'applied') {
            $commit = UpdateSource::normalizeCommit((string)($pending['commit'] ?? ''));
            if ($commit !== '') {
                return $commit;
            }
        }
        $state = $this->readJson($this->root . '/cms/deploy/last.json');
        if (($state['status'] ?? '') === 'success') {
            return UpdateSource::normalizeCommit((string)($state['to'] ?? ''));
        }
        return '';
    }

    private function emitStep(string $id, string $title): void
    {
        if ($this->stepHandler !== null) {
            ($this->stepHandler)($id, $title);
        }
    }

    private function write(string $message, string $kind = 'info'): void
    {
        if ($this->logHandler !== null) {
            ($this->logHandler)($message, $kind);
        }
    }

    /** @param array{title:string,details:string,ok:bool,fatal:bool} $check */
    private function reportCheck(array $check): void
    {
        $title = (string)$check['title'];
        $details = (string)$check['details'];
        $ok = !empty($check['ok']);
        $fatal = !empty($check['fatal']);
        $this->write($title . ($details !== '' ? ' — ' . $details : ''), $ok ? 'success' : ($fatal ? 'error' : 'warning'));
    }

    /** @return array{title:string,details:string,ok:bool,fatal:bool} */
    private function check(string $title, string $details, bool $ok, bool $fatal): array
    {
        return array('title' => $title, 'details' => $details, 'ok' => $ok, 'fatal' => $fatal);
    }

    /** @return resource */
    private function acquireLock()
    {
        $path = $this->root . '/' . self::LOCK_RELATIVE;
        $this->ensureDir(dirname($path));
        $handle = @fopen($path, 'c+');
        if (!is_resource($handle)) {
            throw new RuntimeException('Не удалось открыть файл блокировки: ' . $this->relative($path));
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new RuntimeException(
                'Уже выполняется другое обновление (' . $this->relative($path) . '). '
                . 'Дождитесь его завершения и повторите.'
            );
        }
        return $handle;
    }

    /** @param resource $handle */
    private function releaseLock($handle): void
    {
        if (!is_resource($handle)) {
            return;
        }
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    /**
     * Обязательная резервная копия базы.
     *
     * Пароль передаётся через MYSQL_PWD, а не аргументом: иначе он виден в ps.
     * Если дамп не получился, обновление не начинается — так же, как в панели.
     *
     * @return array{file:string,size:int}
     */
    private function backupDatabase(): array
    {
        if (empty($this->options['backup'])) {
            $this->write('Резервная копия базы отключена (--skip-backup)', 'warning');
            return array('file' => '', 'size' => 0);
        }
        $directory = $this->root . '/cms/saverestore';
        $this->ensureDir($directory);
        $file = $directory . '/db_before_update_' . date('Y-m-d__H-i-s') . '.sql';

        $configured = trim(Config::string('PATH_TO_MYSQLDUMP', 'mysqldump'));
        $binary = $this->runner->find($configured !== '' ? $configured : 'mysqldump');
        if ($binary === '') {
            throw new RuntimeException(
                'Не найден mysqldump. Укажите PATH_TO_MYSQLDUMP в .env '
                . '(или повторите с --skip-backup, если он точно не нужен).'
            );
        }
        $database = trim(Config::string('DB_NAME', ''));
        if ($database === '') {
            throw new RuntimeException('Не задан DB_NAME: без имени базы резервную копию не сделать.');
        }

        $command = array($binary);
        $command[] = '-h';
        $command[] = trim(Config::string('DB_HOST', '127.0.0.1'));
        $port = trim(Config::string('DB_PORT', ''));
        if ($port !== '') {
            $command[] = '-P';
            $command[] = $port;
        }
        $command[] = '--user=' . trim(Config::string('DB_USER', ''));
        $command[] = '--no-create-db';
        $command[] = '--add-drop-table';
        $command[] = '--single-transaction';
        $command[] = '--quick';
        $command[] = $database;

        $this->write('Дамп базы ' . $database . ' → ' . basename($file), 'info');
        $result = $this->runToFile($command, $file, array('MYSQL_PWD' => Config::string('DB_PASSWORD', '')));
        clearstatcache(true, $file);
        $size = is_file($file) ? (int)filesize($file) : 0;
        if (!$result['ok'] || $size <= 0) {
            @unlink($file);
            throw new RuntimeException(
                'Не удалось сделать резервную копию базы. Обновление остановлено. '
                . $this->tail($result['err'])
            );
        }
        $this->write('Резервная копия: ' . basename($file) . ' (' . $this->formatBytes($size) . ')', 'success');
        return array('file' => $file, 'size' => $size);
    }

    /**
     * @param string[] $command
     * @param array<string,string> $environment
     * @return array{ok:bool,code:int,err:string}
     */
    private function runToFile(array $command, string $file, array $environment = array()): array
    {
        $descriptors = array(
            0 => array('pipe', 'r'),
            1 => array('file', $file, 'w'),
            2 => array('pipe', 'w'),
        );
        $env = null;
        if ($environment !== array()) {
            $current = getenv();
            $env = is_array($current) ? array_merge($current, $environment) : $environment;
        }
        $pipes = array();
        $process = @proc_open($command, $descriptors, $pipes, $this->root, $env);
        if (!is_resource($process)) {
            return array('ok' => false, 'code' => 127, 'err' => 'Не удалось запустить ' . $command[0]);
        }
        fclose($pipes[0]);
        $err = (string)stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $code = (int)proc_close($process);
        return array('ok' => $code === 0, 'code' => $code, 'err' => $err);
    }

    /** @return array{path:string,size:int,sha1:string} */
    private function downloadArchive(string $url): array
    {
        $directory = $this->root . '/cms/saverestore';
        $this->ensureDir($directory);
        $path = $directory . '/master.tgz';
        $started = microtime(true);
        $this->write('Загрузка ' . $url, 'muted');

        // Загрузчик сам повторяет попытку через IPv4 и, только при TLS-ошибке,
        // без проверки сертификата — с предупреждением в журнал.
        $downloader = new ArchiveDownloader($this->root);
        $lastReport = 0.0;
        $result = $downloader->download(
            $url,
            $path,
            function (int $downloaded, int $total, int $percent) use (&$lastReport): void {
                $now = microtime(true);
                if ($now - $lastReport < 3.0) {
                    return;
                }
                $lastReport = $now;
                $text = $this->formatBytes((float)$downloaded);
                if ($total > 0 && $percent >= 0) {
                    $text .= ' из ' . $this->formatBytes((float)$total) . ' (' . $percent . '%)';
                }
                $this->write('Скачано ' . $text, 'muted');
            }
        );

        if (!$result['ok']) {
            $host = (string)parse_url($url, PHP_URL_HOST);
            throw new RuntimeException(
                'Не удалось скачать архив: ' . $result['error']
                . ($host !== '' ? '. Проверьте, что сервер открывает ' . $host : '')
                . '. Обновление не начато, файлы не тронуты.'
            );
        }
        if (!empty($result['insecure'])) {
            $this->write('Сертификат TLS не проверен: в системе нет корневых сертификатов', 'warning');
        }
        if ((int)$result['attempts'] > 1) {
            $this->write('Загрузка удалась с попытки ' . $result['attempts'], 'warning');
        }

        clearstatcache(true, $path);
        $size = is_file($path) ? (int)filesize($path) : 0;
        $sha1 = (string)@sha1_file($path);
        $this->write(
            'Скачано ' . $this->formatBytes($size) . ' за ' . $this->formatDuration(microtime(true) - $started),
            'success'
        );
        // Диагностика скорости: сколько из общего времени ушло в сеть, а сколько
        // в вывод прогресса. Без этих чисел «медленно» неотличимо от «сеть».
        $stats = is_array($result['stats'] ?? null) ? $result['stats'] : array();
        $this->write(
            'Скорость ' . $this->formatBytes((float)($stats['speed'] ?? 0)) . '/с'
            . ', соединение ' . number_format((float)($stats['connect_time'] ?? 0), 2, ',', ' ') . ' с'
            . ', прогресс ' . number_format(((int)($stats['callback_ms'] ?? 0)) / 1000, 2, ',', ' ') . ' с'
            . (!empty($stats['ip']) ? ', ' . $stats['ip'] : '')
            . (!empty($stats['http_version']) ? ', HTTP/' . $stats['http_version'] : ''),
            'muted'
        );
        if ((string)($result['proxy'] ?? '') !== '') {
            $this->write('Прокси из окружения: ' . $result['proxy'], 'warning');
        }
        return array('path' => $path, 'size' => $size, 'sha1' => $sha1);
    }

    /** Распаковать архив и вернуть каталог, в котором лежит корень проекта. */
    private function unpackArchive(string $archive): string
    {
        $temp = $this->root . '/cms/saverestore/temp';
        if (is_dir($temp)) {
            $this->removals->removeTree($temp);
        }
        $this->ensureDir($temp);

        $command = array('tar', '-xzf', $archive, '-C', $temp);
        $result = $this->runner->run($command, max(300, Config::int('DEPLOY_TIMEOUT', 900)));
        if (!$result['ok']) {
            throw new RuntimeException(
                'Не удалось распаковать архив (код ' . $result['code'] . '): ' . $this->tail($result['err'] ?: $result['out'])
            );
        }

        $entries = scandir($temp);
        if ($entries === false) {
            throw new RuntimeException('Не удалось прочитать каталог распаковки.');
        }
        $entries = array_values(array_filter($entries, static function (string $entry): bool {
            return $entry !== '.' && $entry !== '..';
        }));
        if ($entries === array()) {
            throw new RuntimeException('Архив распаковался, но не содержит файлов.');
        }

        $root = $temp;
        if (!in_array('index.php', $entries, true) && !in_array('dump.sql', $entries, true)) {
            // GitHub/GitLab кладут проект в каталог вида `majordomo-<sha>`.
            $directories = array();
            foreach ($entries as $entry) {
                if (is_dir($temp . '/' . $entry) && !is_link($temp . '/' . $entry)) {
                    $directories[] = $entry;
                }
            }
            if ($directories === array()) {
                throw new RuntimeException(
                    'В архиве нет каталога проекта (найдено: ' . implode(', ', array_slice($entries, 0, 5)) . ').'
                );
            }
            sort($directories);
            $root = $temp . '/' . $directories[0];
            if (count($directories) > 1) {
                $this->write(
                    'В архиве несколько каталогов, выбран ' . $directories[0] . ': ' . implode(', ', $directories),
                    'warning'
                );
            }
        }
        if (!is_dir($root . '/lib') || !is_file($root . '/bootstrap.php')) {
            $preview = implode(', ', array_slice($entries, 0, 10));
            // Старая раскладка MajorDoMo: docroot в корне, без public/ и bootstrap.php.
            $legacy = !is_dir($root . '/public') && is_file($root . '/index.php');
            $reason = $legacy
                ? 'Это архив старой раскладки MajorDoMo (docroot в корне, без public/ и bootstrap.php).'
                : 'В архиве нет lib/ и bootstrap.php.';
            throw new RuntimeException(
                'Распакованный архив не похож на MajorDoMo: ' . $this->relative($root)
                . ' (содержимое: ' . $preview . '). ' . $reason
                . ' Проверьте ветку: у панели и deploy.sh источник должен совпадать. '
                . 'Ветку можно указать явно: --branch=<ветка> либо --url=<адрес архива>.'
            );
        }
        $this->write('Распаковано: ' . count($entries) . ' записей, корень ' . basename($root), 'success');
        return $root;
    }

    /** Проверить права на каждый файл до замены, чтобы не оставить половинчатое обновление. */
    private function verifyTargetWritable(string $updateRoot): void
    {
        $issues = array();
        foreach ($this->buildManifest($updateRoot) as $relative) {
            $target = $this->root . '/' . $relative;
            if (file_exists($target)) {
                if (!is_writable($target)) {
                    $issues[] = 'нет прав на перезапись: ' . $relative;
                }
            } elseif (!$this->canWritePath(dirname($target))) {
                $issues[] = 'нет прав на создание: ' . $relative;
            }
            if (count($issues) >= 20) {
                break;
            }
        }
        if ($issues !== array()) {
            $shown = array_slice($issues, 0, 8);
            $suffix = count($issues) > count($shown) ? ' и ещё ' . (count($issues) - count($shown)) : '';
            throw new RuntimeException(
                'Недостаточно прав на файлы проекта: ' . implode('; ', $shown) . $suffix
                . '. Исправьте владельца файлов (должен совпадать с пользователем службы) и повторите.'
            );
        }
        $this->write('Права на файлы проверены', 'success');
    }

    private function pauseCycles(): void
    {
        $this->maintenance = new MaintenanceMode($this->root);
        $this->maintenance->enable('system_update', 3600, 'cli', array(
            'message' => 'Идёт обновление системы. Сайт скоро вернётся.',
            'deploy_id' => bin2hex(random_bytes(8)),
        ));
        $this->write('Режим обслуживания включён', 'info');

        $this->cycleManager = new CycleManager($this->root);
        $this->cycleMaintenance = new CycleMaintenance($this->root);
        $this->cycleMaintenance->begin('');
        if (!$this->cycleMaintenance->supervisorRunning()) {
            $this->write('Фоновые процессы не запущены — останавливать нечего', 'muted');
            return;
        }
        $this->write('Ждём остановки фоновых процессов...', 'info');
        $ready = $this->cycleMaintenance->waitUntilReady(
            CycleMaintenance::READY_TIMEOUT,
            function (int $elapsed, array $blocked): void {
                $message = 'Ждём остановки: ' . $elapsed . ' с';
                if ($blocked !== array()) {
                    $message .= ', не завершаются: ' . implode(', ', $blocked);
                }
                $this->write($message . '...', 'muted');
            }
        );
        if (!$ready) {
            $this->write('Супервизор циклов не отвечает: продолжаем без подтверждения', 'warning');
            return;
        }
        $this->write('Циклы остановлены', 'success');
    }

    /** @return string[] Манифест установленных файлов. */
    private function applyFiles(string $updateRoot): array
    {
        $oldManifest = $this->readManifest();
        $newManifest = $this->buildManifest($updateRoot);
        $this->write('Файлов в архиве: ' . count($newManifest), 'info');
        // С этого момента проект может быть в промежуточном состоянии: удаление
        // по removals.json и копирование уже начались. Любая ошибка ниже
        // оставляет режим обслуживания и удержание циклов включёнными.
        $this->applied = true;

        $removals = $this->removals->apply(function (string $kind, string $path): void {
            $this->write(
                'Удалён ' . ($kind === 'directory' ? 'каталог' : 'файл') . ' (removals.json): ' . $path,
                'muted'
            );
        });
        $removed = (int)$removals['files'] + (int)$removals['directories'] + (int)$removals['patterns'];
        if ($removed > 0) {
            $this->write(
                'Очищено по removals.json: ' . $removals['files'] . ' файлов, '
                . $removals['directories'] . ' каталогов, ' . $removals['patterns'] . ' по шаблонам',
                'info'
            );
        }

        $this->removeDisappearedFiles($oldManifest, $newManifest);
        $files = $this->copyUpdateTree($updateRoot, $newManifest);
        $this->write('Заменено файлов: ' . $files, 'success');
        return $newManifest;
    }

    /** @param string[] $oldManifest @param string[] $newManifest */
    private function removeDisappearedFiles(array $oldManifest, array $newManifest): void
    {
        if ($oldManifest === array()) {
            return;
        }
        $current = array_fill_keys($newManifest, true);
        $removed = 0;
        foreach ($oldManifest as $relative) {
            if (isset($current[$relative]) || $this->isProtectedPath($relative)) {
                continue;
            }
            try {
                $done = $this->removals->removePath($relative, false, function (string $kind, string $path): void {
                    $this->write('Удалён исчезнувший из обновления файл: ' . $path, 'muted');
                });
                if ($done) {
                    $removed++;
                }
            } catch (Throwable $error) {
                $this->write('Не удалось удалить ' . $relative . ': ' . $error->getMessage(), 'warning');
            }
        }
        if ($removed > 0) {
            $this->write('Удалено файлов, исчезнувших из обновления: ' . $removed, 'info');
        }
    }

    /** @param string[] $manifest */
    private function copyUpdateTree(string $updateRoot, array $manifest): int
    {
        $source = rtrim($updateRoot, '/\\');
        $copied = 0;
        foreach ($manifest as $relative) {
            $from = $source . '/' . $relative;
            if (!is_file($from)) {
                $this->write('Пропущен отсутствующий файл: ' . $relative, 'warning');
                continue;
            }
            $to = $this->root . '/' . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $this->ensureDir(dirname($to));
            if (!@copy($from, $to)) {
                throw new RuntimeException('Не удалось записать файл: ' . $relative);
            }
            $copied++;
        }
        return $copied;
    }

    /** @return string[] */
    private function buildManifest(string $updateRoot): array
    {
        $base = rtrim($updateRoot, '/\\');
        $length = strlen($base);
        $manifest = array();
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            if (!$item->isFile() || $item->isLink()) {
                continue;
            }
            $relative = str_replace('\\', '/', substr($item->getPathname(), $length + 1));
            if ($relative === '' || $this->isProtectedPath($relative)) {
                continue;
            }
            $manifest[] = $relative;
        }
        sort($manifest);
        return $manifest;
    }

    /**
     * Пути, которые обновление никогда не трогает.
     *
     * Список повторяет правило модуля saverestore: конфигурация, пользовательские
     * данные и зависимости живут на сервере и не приходят из архива.
     */
    private function isProtectedPath(string $relative): bool
    {
        $relative = str_replace('\\', '/', trim($relative));
        if ($relative === '' || preg_match('#(^|/)\.\.(/|$)#', $relative)) {
            return true;
        }
        if (preg_match('#(^|/)config\.php$#i', $relative)) {
            return true;
        }
        $basename = basename($relative);
        if ($basename === '.env' || $basename === '.env.local' || preg_match('/^\.env\..+\.local$/', $basename)) {
            return true;
        }
        if (preg_match('#^cms(/|$)#i', $relative)) {
            return true;
        }
        if (preg_match('#^(backup|database_backup|vendor|node_modules)(/|$)#i', $relative)) {
            return true;
        }
        if (preg_match('#^\.git(/|$)#i', $relative)) {
            return true;
        }
        return false;
    }

    /** @return string[] */
    private function readManifest(): array
    {
        $data = $this->readJson($this->root . '/' . self::MANIFEST_RELATIVE);
        $files = array();
        foreach ($data as $path) {
            if (is_string($path) && trim($path) !== '') {
                $files[] = str_replace('\\', '/', $path);
            }
        }
        return $files;
    }

    /** @param string[] $manifest */
    private function saveManifest(array $manifest): void
    {
        $this->writeJson($this->root . '/' . self::MANIFEST_RELATIVE, array_values($manifest));
    }

    /** @param array<string,mixed> $changes */
    private function writePending(array $changes): void
    {
        $path = $this->root . '/' . self::PENDING_RELATIVE;
        $data = $this->readJson($path);
        $data = array_merge($data, $changes, array('updated_at' => date('c')));
        $this->writeJson($path, $data);
    }

    private function complete(): void
    {
        $this->releaseCycles();
        if ($this->maintenance !== null) {
            try {
                $this->maintenance->disable();
                $this->write('Режим обслуживания снят', 'info');
            } catch (Throwable $error) {
                $this->write('Не удалось снять режим обслуживания: ' . $error->getMessage(), 'warning');
            }
        }
    }

    /** Отпустить паузу циклов: удержание снимается, супервизор получает сигнал. */
    private function releaseCycles(): void
    {
        if ($this->cycleManager !== null) {
            try {
                $this->cycleManager->releaseHold();
            } catch (Throwable $error) {
                $this->write('Не удалось снять удержание циклов: ' . $error->getMessage(), 'warning');
            }
        }
        if ($this->cycleMaintenance !== null) {
            try {
                $this->cycleMaintenance->finish();
            } catch (Throwable $error) {
                $this->write('Не удалось завершить паузу циклов: ' . $error->getMessage(), 'warning');
            }
        }
    }

    private function fail(Throwable $error): void
    {
        $message = $error->getMessage();
        $this->write('Ошибка: ' . $message, 'error');

        if ($this->applied) {
            $this->write(
                'Файлы уже заменены: сайт остаётся в режиме обслуживания, циклы удерживаются остановленными.',
                'warning'
            );
            if ($this->cycleManager !== null) {
                try {
                    $this->cycleManager->hold('deploy_failed');
                } catch (Throwable $holdError) {
                    $this->write('Не удалось удержать циклы: ' . $holdError->getMessage(), 'warning');
                }
            }
            if ($this->maintenance !== null) {
                try {
                    $this->maintenance->markFailed($message);
                } catch (Throwable $maintenanceError) {
                    $this->write('Не удалось отметить сбой в режиме обслуживания: ' . $maintenanceError->getMessage(), 'warning');
                }
            }
            try {
                $this->deployment->writeState(array(
                    'status' => 'failed',
                    'error' => $message,
                    'from' => $this->updateFrom,
                    'to' => $this->updateTo,
                    'branch' => $this->updateBranch,
                ));
            } catch (Throwable $stateError) {
                $this->write('Не удалось записать состояние деплоя: ' . $stateError->getMessage(), 'warning');
            }
            try {
                // Pending-файл — единственный источник, из которого панель
                // восстановит версию. Успешный статус `applied` после сбоя
                // записал бы незавершённое обновление как установленное.
                if (is_file($this->root . '/' . self::PENDING_RELATIVE)) {
                    $this->writePending(array(
                        'status' => 'failed',
                        'error' => $message,
                        'failed_at' => date('c'),
                    ));
                }
            } catch (Throwable $pendingError) {
                $this->write('Не удалось отметить сбой в pending-файле: ' . $pendingError->getMessage(), 'warning');
            }
            $this->write('После исправления: php majordomo migrations:run, затем php majordomo maintenance:off', 'muted');
        } else {
            if ($this->maintenance !== null) {
                try {
                    $this->maintenance->disable();
                } catch (Throwable $maintenanceError) {
                    $this->write('Не удалось снять режим обслуживания: ' . $maintenanceError->getMessage(), 'warning');
                }
                $this->write('Файлы не менялись: режим обслуживания снят, обновление можно повторить.', 'muted');
            } else {
                $this->write('Файлы не менялись: обновление можно повторить.', 'muted');
            }
        }

        if ($this->cycleMaintenance !== null) {
            try {
                $this->cycleMaintenance->abort($message);
            } catch (Throwable $abortError) {
                $this->write('Не удалось снять паузу циклов: ' . $abortError->getMessage(), 'warning');
            }
        }
    }

    private function cleanupTemp(): void
    {
        $temp = $this->root . '/cms/saverestore/temp';
        if (is_dir($temp)) {
            try {
                $this->removals->removeTree($temp);
            } catch (Throwable $error) {
                $this->write('Не удалось удалить ' . $this->relative($temp) . ': ' . $error->getMessage(), 'warning');
            }
        }
        $archive = $this->root . '/cms/saverestore/master.tgz';
        if (is_file($archive)) {
            @unlink($archive);
        }
    }

    private function ensureDir(string $directory): void
    {
        if ($directory === '' || is_dir($directory)) {
            return;
        }
        if (!@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Не удалось создать каталог: ' . $this->relative($directory));
        }
    }

    /** Можно ли создать файл в каталоге (сам каталог может ещё не существовать). */
    private function canWritePath(string $path): bool
    {
        $current = $path;
        while ($current !== '' && !file_exists($current)) {
            $parent = dirname($current);
            if ($parent === $current) {
                break;
            }
            $current = $parent;
        }
        if ($current === '' || !file_exists($current)) {
            return false;
        }
        return is_dir($current) ? is_writable($current) : is_writable(dirname($current));
    }

    /** @param array<string,mixed> $data */
    private function writeJson(string $path, array $data): void
    {
        $this->ensureDir(dirname($path));
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false) {
            throw new RuntimeException('Не удалось сериализовать ' . $this->relative($path));
        }
        $temporary = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($temporary, $json . PHP_EOL, LOCK_EX) === false) {
            @unlink($temporary);
            throw new RuntimeException('Не удалось записать ' . $this->relative($path));
        }
        if (!@rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Не удалось заменить ' . $this->relative($path));
        }
    }

    /** @return array<string,mixed> */
    private function readJson(string $path): array
    {
        if (!is_file($path)) {
            return array();
        }
        $data = json_decode((string)@file_get_contents($path), true);
        return is_array($data) ? $data : array();
    }

    private function relative(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $root = str_replace('\\', '/', $this->root);
        if ($path === $root) {
            return '.';
        }
        if (str_starts_with($path, $root . '/')) {
            return substr($path, strlen($root) + 1);
        }
        return $path;
    }

    private function formatBytes(float $bytes): string
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 1, ',', ' ') . ' ГБ';
        }
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1, ',', ' ') . ' МБ';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 1, ',', ' ') . ' КБ';
        }
        return (string)(int)$bytes . ' Б';
    }

    private function formatDuration(float $seconds): string
    {
        if ($seconds < 1) {
            return 'меньше секунды';
        }
        if ($seconds < 60) {
            return number_format($seconds, 1, ',', ' ') . ' с';
        }
        $minutes = (int)floor($seconds / 60);
        return $minutes . ' мин ' . (int)round($seconds - $minutes * 60) . ' с';
    }

    private function tail(string $value, int $length = 800): string
    {
        $value = trim((string)preg_replace('/\s+/u', ' ', $value));
        if ($value === '') {
            return '';
        }
        return mb_strlen($value) > $length ? '…' . mb_substr($value, -$length) : $value;
    }

    private static function shortCommit(string $commit): string
    {
        $commit = trim($commit);
        return $commit === '' ? 'неизвестно' : mb_strtoupper(mb_substr($commit, 0, 7));
    }

    /** Подставить ветку в ссылку архива (GitHub и GitLab). */
    private static function withBranch(string $url, string $branch): string
    {
        if (preg_match('#^(.*/-/archive/)[^/]+/([^/]+\.tar\.gz)$#i', $url, $match)) {
            return $match[1] . $branch . '/' . $match[2];
        }
        if (preg_match('#^(.*/archive/)(?:refs/(?:heads|tags)/)?[^/]+\.tar\.gz$#i', $url, $match)) {
            return $match[1] . $branch . '.tar.gz';
        }
        return $url;
    }
}
