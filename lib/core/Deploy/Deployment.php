<?php

namespace MajorDoMo\Core\Deploy;

use MajorDoMo\Core\Config;
use RuntimeException;

/**
 * Шаги установки зависимостей и проверки после замены файлов.
 *
 * Пайплайн намеренно не копирует файлы и не делает бэкапов: этим владеет
 * saverestore. Здесь только Composer, npm, миграции и health-check, чтобы один
 * и тот же код использовали веб-обновление и автообновление.
 *
 * 2026-10-22 — добавлено вместо ручного копирования без vendor.
 */
final class Deployment
{
    private string $root;
    private CommandRunner $runner;
    private ?ServiceManager $service = null;

    public function __construct(string $root, ?CommandRunner $runner = null, ?ServiceManager $service = null)
    {
        $this->root = rtrim($root, '/\\');
        $this->runner = $runner ?? new CommandRunner($this->root);
        $this->service = $service;
    }

    public function runner(): CommandRunner
    {
        return $this->runner;
    }

    public function service(): ServiceManager
    {
        return $this->service ??= new ServiceManager($this->root, $this->serviceName());
    }

    public function statePath(): string
    {
        return $this->root . '/cms/deploy/last.json';
    }

    /**
     * Проверки до замены файлов. Ошибка composer/node означает, что обновление
     * нельзя начинать: иначе на сервере останется старый vendor.
     *
     * @return array<int,array{title:string,details:string,ok:bool,fatal:bool}>
     */
    public function preflight(): array
    {
        $checks = array();
        $composer = $this->composerCommand();
        $checks[] = array(
            'title' => 'Composer',
            'details' => $composer !== array() ? implode(' ', $composer) : 'Не найден. Укажите DEPLOY_COMPOSER.',
            'ok' => $composer !== array(),
            'fatal' => true,
        );
        $checks[] = array(
            'title' => 'composer.json / composer.lock',
            'details' => is_file($this->root . '/composer.json') && is_file($this->root . '/composer.lock')
                ? 'Файлы на месте' : 'Не найдены в корне проекта',
            'ok' => is_file($this->root . '/composer.json') && is_file($this->root . '/composer.lock'),
            'fatal' => true,
        );
        $vendor = $this->root . '/vendor';
        $checks[] = array(
            'title' => 'Каталог vendor',
            'details' => is_dir($vendor) ? (is_writable($vendor) ? 'Доступен для записи' : 'Нет прав на запись')
                : (is_writable($this->root) ? 'Будет создан' : 'Нет прав на создание'),
            'ok' => is_dir($vendor) ? is_writable($vendor) : is_writable($this->root),
            'fatal' => true,
        );
        $cms = $this->root . '/cms';
        $checks[] = array(
            'title' => 'Каталог cms',
            'details' => is_dir($cms) && is_writable($cms) ? 'Доступен для режима обслуживания и состояния деплоя' : 'Нет прав на запись',
            'ok' => is_dir($cms) && is_writable($cms),
            'fatal' => true,
        );

        $assets = $this->assetsMode();
        if ($assets !== 'never') {
            $node = $this->nodeCommand();
            $npm = $this->npmCommand();
            $version = $node !== array() ? $this->runner->version($node[0], '--version') : array('ok' => false, 'version' => '');
            $major = preg_match('/^v?(\d+)/', (string)$version['version'], $matches) ? (int)$matches[1] : 0;
            $checks[] = array(
                'title' => 'Node.js 24+',
                'details' => $node !== array() ? trim((string)$version['version']) . ($major >= 24 ? '' : ' — нужен Node.js 24+') : 'Не найден. Укажите DEPLOY_NODE.',
                'ok' => $node !== array() && $major >= 24,
                'fatal' => $assets === 'always',
            );
            $checks[] = array(
                'title' => 'npm',
                'details' => $npm !== array() ? implode(' ', $npm) : 'Не найден. Укажите DEPLOY_NPM.',
                'ok' => $npm !== array(),
                'fatal' => $assets === 'always',
            );
            $assetsPath = $this->root . '/public/assets/admin';
            $assetsParent = dirname($assetsPath);
            $checks[] = array(
                'title' => 'Каталог public/assets/admin',
                'details' => is_dir($assetsPath) ? (is_writable($assetsPath) ? 'Доступен для сборки' : 'Нет прав на запись')
                    : (is_writable($assetsParent) ? 'Будет создан сборкой' : 'Нет прав на создание'),
                'ok' => is_dir($assetsPath) ? is_writable($assetsPath) : is_writable($assetsParent),
                'fatal' => $assets === 'always',
            );
        } else {
            $checks[] = array('title' => 'Сборка фронтенда', 'details' => 'Отключена (DEPLOY_ASSETS=never)', 'ok' => true, 'fatal' => false);
        }

        try {
            $service = $this->service();
            $wrapper = $service->wrapperPath();
            $checks[] = array(
                'title' => 'Управление службой',
                'details' => is_file($wrapper) && is_executable($wrapper)
                    ? 'Wrapper готов: ' . $wrapper
                    : 'Нет wrapper. Выполните sudo php majordomo systemd:give-permissions',
                'ok' => is_file($wrapper) && is_executable($wrapper),
                'fatal' => Config::bool('DEPLOY_RESTART', true),
            );
        } catch (\Throwable $error) {
            $checks[] = array(
                'title' => 'Управление службой',
                'details' => $error->getMessage(),
                'ok' => false,
                'fatal' => Config::bool('DEPLOY_RESTART', true),
            );
        }
        $free = @disk_free_space($this->root);
        $checks[] = array(
            'title' => 'Свободное место',
            'details' => $free !== false ? round($free / 1024 / 1024) . ' МБ' : 'Не удалось определить',
            'ok' => $free === false || $free > 200 * 1024 * 1024,
            'fatal' => false,
        );
        return $checks;
    }

    public function preflightOk(): bool
    {
        foreach ($this->preflight() as $check) {
            if ($check['fatal'] && !$check['ok']) return false;
        }
        return true;
    }

    /**
     * @param callable|null $log function(string $message, string $kind = 'info'): void
     * @return array{ok:bool,output:string,error:string,duration_ms:int}
     */
    public function composerInstall(?callable $log = null): array
    {
        $composer = $this->composerCommand();
        if ($composer === array()) throw new RuntimeException('Composer не найден. Установите Composer или задайте DEPLOY_COMPOSER.');
        $command = array_merge($composer, array(
            'install', '--no-dev', '--prefer-dist', '--no-interaction', '--no-progress', '--optimize-autoloader',
        ));
        $environment = $this->composerEnvironment();
        $this->log($log, 'composer install', 'info');
        $result = $this->runner->run($command, $this->timeout());
        $this->log($log, 'composer: ' . ($result['ok'] ? 'OK' : 'ошибка ' . $result['code']), $result['ok'] ? 'success' : 'error');
        if (!$result['ok']) {
            throw new RuntimeException('composer install завершился с кодом ' . $result['code'] . ': ' . $this->tail($result['err'] ?: $result['out']));
        }
        return array('ok' => true, 'output' => $this->tail($result['out']), 'error' => '', 'duration_ms' => $result['duration_ms']);
    }

    /**
     * @param callable|null $log
     * @return array{ok:bool,classes:array<string,bool>,output:string}
     */
    public function verifyAutoload(?callable $log = null): array
    {
        $code = 'require "vendor/autoload.php"; $classes = array("Bluerhinos\\\\phpMQTT", "PHPMailer\\\\PHPMailer\\\\PHPMailer");'
            . ' $result = array(); foreach ($classes as $class) { $result[$class] = class_exists($class); }'
            . ' echo json_encode($result);';
        $result = $this->runner->run(array($this->phpBinary(), '-d', 'display_errors=1', '-r', $code), 60);
        $classes = json_decode(trim($result['out']), true);
        if (!is_array($classes)) $classes = array();
        $ok = $result['ok'] && $classes !== array() && !in_array(false, $classes, true);
        $this->log($log, 'Автозагрузка: ' . ($ok ? 'OK' : 'неполная'), $ok ? 'success' : 'error');
        if (!$ok) {
            throw new RuntimeException('Проверка автозагрузки не прошла: ' . $this->tail($result['err'] ?: $result['out']));
        }
        return array('ok' => true, 'classes' => $classes, 'output' => $this->tail($result['out']));
    }

    /**
     * @param callable|null $log
     * @return array{ok:bool,ci:bool,build:bool,output:string}
     */
    public function buildAssets(?callable $log = null): array
    {
        $mode = $this->assetsMode();
        if ($mode === 'never') {
            $this->log($log, 'Сборка фронтенда отключена', 'muted');
            return array('ok' => true, 'ci' => false, 'build' => false, 'output' => '');
        }
        $npm = $this->npmCommand();
        if ($npm === array()) {
            if ($mode === 'auto') {
                $this->log($log, 'npm не найден: сборка пропущена, используется собранный бандл', 'warning');
                return array('ok' => true, 'ci' => false, 'build' => false, 'output' => '');
            }
            throw new RuntimeException('npm не найден. Установите Node.js 24+ или задайте DEPLOY_ASSETS=never.');
        }
        $lock = $this->root . '/package-lock.json';
        $stateFile = $this->root . '/cms/deploy/assets.json';
        $state = is_file($stateFile) ? json_decode((string)@file_get_contents($stateFile), true) : array();
        $hash = is_file($lock) ? (string)hash_file('sha256', $lock) : '';
        $needsCi = !is_dir($this->root . '/node_modules') || !is_array($state) || ($state['lock_hash'] ?? '') !== $hash;
        $output = '';
        if ($needsCi) {
            $this->log($log, 'npm ci', 'info');
            $ci = $this->runner->run(array_merge($npm, array('ci', '--no-audit', '--no-fund')), $this->timeout());
            $output .= $this->tail($ci['out']) . $this->tail($ci['err']);
            if (!$ci['ok']) throw new RuntimeException('npm ci завершился с кодом ' . $ci['code'] . ': ' . $this->tail($ci['err'] ?: $ci['out']));
        }
        $this->log($log, 'npm run build', 'info');
        $build = $this->runner->run(array_merge($npm, array('run', 'build')), $this->timeout());
        $output .= $this->tail($build['out']) . $this->tail($build['err']);
        if (!$build['ok']) throw new RuntimeException('npm run build завершился с кодом ' . $build['code'] . ': ' . $this->tail($build['err'] ?: $build['out']));
        $this->writeJson($stateFile, array('lock_hash' => $hash, 'built_at' => date('c')));
        $this->log($log, 'Сборка фронтенда: OK', 'success');
        return array('ok' => true, 'ci' => $needsCi, 'build' => true, 'output' => $this->tail($output));
    }

    /**
     * @param callable|null $log
     * @return array{applied:string[],batch:int,total:int,orphaned:string[]}
     */
    public function runMigrations(?callable $log = null): array
    {
        // Отдельный процесс: автообновление работает в уже загруженном
        // cycle.php, где старый класс Migrations остался в памяти. Дочерний
        // CLI загрузит новые файлы миграций с диска.
        $command = array($this->phpBinary(), $this->root . '/majordomo', 'migrations:run', '--no-ansi', '--json');
        $this->log($log, 'Применение миграций', 'info');
        $result = $this->runner->run($command, $this->timeout());
        $data = json_decode(trim($result['out']), true);
        if (!$result['ok'] || !is_array($data) || empty($data['ok'])) {
            throw new RuntimeException('migrations:run завершился с ошибкой: ' . $this->tail($result['err'] ?: $result['out']));
        }
        $migrations = is_array($data['data'] ?? null) ? $data['data'] : array();
        $applied = is_array($migrations['applied'] ?? null) ? $migrations['applied'] : array();
        $orphaned = is_array($migrations['orphaned'] ?? null) ? $migrations['orphaned'] : array();
        if ($applied === array()) $this->log($log, 'Новых миграций нет', 'muted');
        else $this->log($log, 'Применено миграций: ' . count($applied), 'success');
        if ($orphaned !== array()) {
            $this->log($log, 'Записи миграций без файлов: ' . implode(', ', $orphaned), 'warning');
        }
        return array(
            'applied' => $applied,
            'batch' => (int)($migrations['batch'] ?? 0),
            'total' => (int)($migrations['total'] ?? 0),
            'orphaned' => $orphaned,
        );
    }

    /** @return array{ok:bool,code:int,out:string,err:string} */
    public function restartService(?callable $log = null): array
    {
        if (!Config::bool('DEPLOY_RESTART', true)) {
            $this->log($log, 'Перезапуск службы отключён (DEPLOY_RESTART=0)', 'warning');
            return array('ok' => true, 'code' => 0, 'out' => '', 'err' => '');
        }
        try {
            $service = $this->service();
            $result = $service->restart();
        } catch (\Throwable $error) {
            $this->log($log, 'Перезапуск службы не удался: ' . $error->getMessage(), 'warning');
            return array('ok' => false, 'code' => 1, 'out' => '', 'err' => $error->getMessage());
        }
        if (!$result['ok']) {
            $this->log($log, 'Перезапуск службы не удался: ' . $this->tail($result['err'] ?: $result['out']), 'warning');
        } else {
            $this->log($log, 'Служба перезапущена: ' . $service->serviceName(), 'success');
        }
        return array('ok' => $result['ok'], 'code' => $result['code'], 'out' => $result['out'], 'err' => $result['err']);
    }

    /** @param array<string,mixed> $state */
    public function writeState(array $state): void
    {
        $state['updated_at'] = date('c');
        $state['root'] = $this->root;
        $this->writeJson($this->statePath(), $state);
    }

    /** @return array<string,mixed> */
    public function readState(): array
    {
        $path = $this->statePath();
        if (!is_file($path)) return array();
        $data = json_decode((string)@file_get_contents($path), true);
        return is_array($data) ? $data : array();
    }

    /** @return string[] */
    private function composerCommand(): array
    {
        $configured = trim(Config::string('DEPLOY_COMPOSER', 'composer'));
        if ($configured === '') return array();
        if (str_ends_with(strtolower($configured), '.phar')) {
            $phar = $configured;
            if (!str_contains($phar, '/') && !str_contains($phar, '\\')) $phar = $this->root . '/' . $phar;
            if (is_file($phar)) return array($this->phpBinary(), $phar);
        }
        $found = $this->runner->find($configured);
        return $found !== '' ? array($found) : array();
    }

    /** @return string[] */
    private function nodeCommand(): array
    {
        $found = $this->runner->find(trim(Config::string('DEPLOY_NODE', 'node')));
        return $found !== '' ? array($found) : array();
    }

    /** @return string[] */
    private function npmCommand(): array
    {
        $found = $this->runner->find(trim(Config::string('DEPLOY_NPM', 'npm')));
        return $found !== '' ? array($found) : array();
    }

    private function phpBinary(): string
    {
        return PhpBinary::cli();
    }

    private function assetsMode(): string
    {
        $mode = strtolower(trim(Config::string('DEPLOY_ASSETS', 'always')));
        return in_array($mode, array('auto', 'always', 'never'), true) ? $mode : 'always';
    }

    private function serviceName(): ?string
    {
        $service = trim(Config::string('DEPLOY_SERVICE', ''));
        return $service !== '' ? $service : null;
    }

    private function timeout(): int
    {
        return max(60, Config::int('DEPLOY_TIMEOUT', 900));
    }

    /** @return array<string,string> */
    private function composerEnvironment(): array
    {
        $home = $this->root . '/cms/deploy/composer';
        if (!is_dir($home) && !@mkdir($home, 0775, true) && !is_dir($home)) {
            throw new RuntimeException('Не удалось создать ' . $home);
        }
        $environment = array(
            'COMPOSER_HOME' => $home,
            'COMPOSER_CACHE_DIR' => $home . '/cache',
            'COMPOSER_NO_INTERACTION' => '1',
        );
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) $environment['COMPOSER_ALLOW_SUPERUSER'] = '1';
        return $environment;
    }

    private function writeJson(string $path, array $data): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Не удалось создать каталог ' . $directory);
        }
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false) throw new RuntimeException('Не удалось сериализовать ' . $path);
        $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($temporary, $json . PHP_EOL, LOCK_EX) === false || !@rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Не удалось записать ' . $path);
        }
    }

    private function tail(string $value): string
    {
        $value = trim($value);
        return $value === '' ? '' : mb_substr($value, -4000);
    }

    private function log(?callable $log, string $message, string $kind = 'info'): void
    {
        if ($log !== null) $log($message, $kind);
    }
}
