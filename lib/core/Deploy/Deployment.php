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
 * 2026-10-23 — окружение Composer (COMPOSER_IPRESOLVE=4, COMPOSER_HOME,
 *              GIT_TERMINAL_PROMPT=0) теперь действительно передаётся процессу:
 *              раньше переменные вычислялись, но не применялись, из-за чего
 *              `composer install` мог висеть на IPv6 до Packagist/GitHub.
 *              Вывод composer/npm идёт в журнал обновления потоком.
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
            'install', '--no-dev', '--prefer-dist', '--no-interaction', '--no-progress',
            '--optimize-autoloader', '--no-scripts', '--no-plugins', '--no-audit',
        ));
        $environment = $this->composerEnvironment();
        $this->log($log, 'composer install', 'info');
        $result = $this->runner->run($command, $this->timeout(), $environment, $this->lineStream($log, 'composer'), 20);
        if (!$result['ok'] && empty($result['timed_out']) && str_contains($result['err'] . $result['out'], '--no-audit')) {
            // Composer старше 2.4 не знает --no-audit: один повтор без него,
            // иначе обновление падало бы на «option does not exist».
            $this->log($log, 'Composer не поддерживает --no-audit, повтор без него', 'muted');
            $command = array_values(array_filter($command, static function (string $argument): bool {
                return $argument !== '--no-audit';
            }));
            $result = $this->runner->run($command, $this->timeout(), $environment, $this->lineStream($log, 'composer'), 20);
        }
        $this->log($log, 'composer: ' . ($result['ok'] ? 'OK' : 'ошибка ' . $result['code']), $result['ok'] ? 'success' : 'error');
        if ($result['timed_out']) {
            throw new RuntimeException(
                'composer install не завершился за ' . $this->timeout() . ' с и был остановлен. '
                . 'Обычно это сеть: проверьте доступ сервера к packagist.org и github.com.'
            );
        }
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
        $viteBin = $this->root . '/node_modules/.bin/vite';
        $viteJs = $this->root . '/node_modules/vite/bin/vite.js';
        $viteInstalled = is_file($viteBin) || is_file($viteJs);
        $needsCi = !is_dir($this->root . '/node_modules') || !$viteInstalled || !is_array($state) || ($state['lock_hash'] ?? '') !== $hash;
        $output = '';
        $npmEnvironment = array(
            // На FastPanel/серверах NODE_ENV часто = production. В этом режиме
            // npm ci не ставит vite из devDependencies, и `npm run build`
            // падает с `vite: not found` (127). npm читает env в нижнем регистре.
            'NODE_ENV' => 'development',
            'NPM_CONFIG_PRODUCTION' => 'false',
            'npm_config_production' => 'false',
            'PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
        );
        if ($needsCi) {
            $this->log($log, 'npm ci', 'info');
            $ci = $this->runner->run(array_merge($npm, array('ci', '--include=dev', '--no-audit', '--no-fund')), $this->timeout(), $npmEnvironment, $this->lineStream($log, 'npm'), 20);
            $output .= $this->tail($ci['out']) . $this->tail($ci['err']);
            $this->assertCommand($ci, 'npm ci');
        }
        if (!is_file($viteBin) && !is_file($viteJs)) {
            // Некоторые версии npm/старые .npmrc игнорируют --include=dev.
            // Явно доводим devDependencies, иначе build бессмысленно запускать.
            $this->log($log, 'vite не установлен после npm ci — npm install --include=dev', 'warning');
            $install = $this->runner->run(array_merge($npm, array('install', '--include=dev', '--no-save', '--no-audit', '--no-fund')), $this->timeout(), $npmEnvironment, $this->lineStream($log, 'npm'), 20);
            $output .= $this->tail($install['out']) . $this->tail($install['err']);
            $this->assertCommand($install, 'npm install --include=dev');
        }
        if (!is_file($viteBin) && !is_file($viteJs)) {
            throw new RuntimeException('vite не установлен: npm ci/install не создали node_modules/.bin/vite.');
        }
        $this->log($log, 'npm run build', 'info');
        $build = $this->runner->run(array_merge($npm, array('run', 'build')), $this->timeout(), $npmEnvironment, $this->lineStream($log, 'npm'), 20);
        $output .= $this->tail($build['out']) . $this->tail($build['err']);
        $this->assertCommand($build, 'npm run build');
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
        // stdout дочерней команды — это JSON-отчёт, его нельзя лить в журнал;
        // оставляем только пульс, чтобы долгая миграция не выглядела зависанием.
        $migrationStream = $log !== null
            ? static function (string $line, bool $pulse = false) use ($log): void {
                if ($pulse) {
                    $log('… миграции: ' . $line, 'muted');
                }
            }
            : null;
        $result = $this->runner->run($command, $this->timeout(), array(), $migrationStream, 20);
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
        if ($found === '') return array();
        // composer часто установлен как PHP-скрипт с shebang `#!/usr/bin/env php`.
        // Прямой запуск возьмёт php из PATH (на FastPanel это может быть 8.0),
        // поэтому запускаем его выбранным CLI PHP той же версии, что и FPM.
        if ($this->isPhpScript($found)) return array($this->phpBinary(), $found);
        return array($found);
    }

    private function isPhpScript(string $path): bool
    {
        $head = @file_get_contents($path, false, null, 0, 512);
        if (!is_string($head) || $head === '') return false;
        return str_starts_with($head, '#!/usr/bin/env php') || str_contains($head, '<?php');
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
            // На некоторых хостингах IPv6 до Packagist/GitHub висит без ответа;
            // Composer поддерживает принудительный IPv4 через эту переменную.
            'COMPOSER_IPRESOLVE' => '4',
            // Git fallback не должен ждать ввод пароля/токена.
            'GIT_TERMINAL_PROMPT' => '0',
            'COMPOSER_PROCESS_TIMEOUT' => '300',
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

    /**
     * Понятная ошибка вместо «код 137»: таймаут и падение команды лечатся
     * по-разному, и по сообщению должно быть ясно, куда смотреть.
     *
     * @param array{ok:bool,code:int,out:string,err:string,timed_out?:bool} $result
     */
    private function assertCommand(array $result, string $label): void
    {
        if (!empty($result['timed_out'])) {
            throw new RuntimeException(
                $label . ' не завершился за ' . $this->timeout() . ' с и был остановлен. '
                . 'Обычно это сеть: проверьте доступ сервера к реестрам пакетов.'
            );
        }
        if (empty($result['ok'])) {
            throw new RuntimeException(
                $label . ' завершился с кодом ' . $result['code'] . ': ' . $this->tail($result['err'] ?: $result['out'])
            );
        }
    }

    /**
     * Поток вывода дочерней команды в журнал обновления.
     *
     * Composer и npm могут молчать минутами (резолв, скачивание пакета), а окно
     * обновления всё это время показывает «Применение» без новых строк — это
     * неотличимо от зависания. Поэтому строки идут по мере появления, а при
     * долгом молчании CommandRunner присылает пульс «выполняется N с».
     *
     * @param callable|null $log function(string $message, string $kind = 'info'): void
     */
    private function lineStream(?callable $log, string $prefix): ?callable
    {
        if ($log === null) {
            return null;
        }
        $last = 0.0;
        $count = 0;
        return static function (string $line, bool $pulse = false) use ($log, $prefix, &$last, &$count): void {
            if ($pulse) {
                $log('… ' . $line, 'muted');
                return;
            }
            $now = microtime(true);
            // Журнал не должен превращаться в поток: не чаще 4 строк в секунду
            // и не больше 120 строк на команду.
            if ($now - $last < 0.25 || $count >= 120) {
                return;
            }
            $last = $now;
            $count++;
            $log($prefix . ': ' . mb_substr($line, 0, 300), 'muted');
        };
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
