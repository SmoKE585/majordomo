<?php

namespace MajorDoMo\Core\Install;

use MajorDoMo\Core\Config;
use MajorDoMo\Core\ConfigCache;

/**
 * First-run setup orchestrator.
 *
 * The wizard is intentionally boring server-side code: validate everything,
 * connect to the database with the values the user just entered, write them
 * once, and create a completion marker. It never relies on the legacy loader,
 * because that loader is allowed to `exit` and would turn a typo in the DB
 * password into a blank page.
 */
final class Installer
{
    private InstallEnvironment $environment;
    /** @var array<string,string> */
    private array $values = array();
    /** @var array<string,bool> */
    private array $existingSecrets = array();
    /** @var array<string,string> */
    private array $secretActions = array();
    private bool $needsAdmin = false;
    private bool $createSchema = false;
    /** @var array<string,mixed> */
    private array $currentDbStatus = array();

    public function __construct(private string $root)
    {
        $this->root = rtrim($root, '/\\');
        $this->environment = new InstallEnvironment($this->root);
        $this->loadDefaults();
    }

    public function environment(): InstallEnvironment
    {
        return $this->environment;
    }

    public function state(): array
    {
        return InstallState::state($this->root);
    }

    public function needsAdmin(): bool
    {
        return $this->needsAdmin;
    }

    public function hasAdmin(): ?bool
    {
        return isset($this->currentDbStatus['admin_exists']) ? $this->currentDbStatus['admin_exists'] : null;
    }

    public function databaseStatus(): array
    {
        return $this->currentDbStatus;
    }

    /**
     * @return array<string,bool>
     */
    public function secretFlags(): array
    {
        return $this->existingSecrets;
    }

    /**
     * Values used by the form. With `$post`, submitted values win so a failed
     * validation can render the same page without losing the user's input.
     *
     * @param array<string,mixed>|null $post
     * @return array<string,string>
     */
    public function formValues(?array $post = null): array
    {
        if ($post === null) {
            return $this->values;
        }
        return $this->mergePost($post);
    }

    /**
     * @param array<string,mixed> $post
     * @return array<string,mixed>
     */
    public function validate(array $post): array
    {
        $values = $this->mergePost($post);
        $errors = array();
        $warnings = array();

        // --- Database first: admin visibility and table checks depend on it.
        $dbConfig = $this->databaseConfig($values, $post);
        $status = $this->environment->databaseStatus($dbConfig);
        $this->currentDbStatus = $status;
        $this->createSchema = $this->shouldCreateSchema($values, $status);
        $hasMissingTables = !empty($status['ok']) && !empty($status['missing']);
        $canAutoImport = !empty($status['ok']) && !empty($status['can_auto_import']);

        // A fresh/empty DB has no administrator. Keep the admin fields required
        // even before the DB is reachable, so finishing without a login/password
        // is impossible.
        $this->needsAdmin = empty($status['ok'])
            || $this->createSchema
            || $hasMissingTables
            || ($status['admin_exists'] === false);

        if (!$status['ok']) {
            $errors['_database'] = (string)$status['message'];
        } elseif ($hasMissingTables) {
            if (!$canAutoImport) {
                $errors['_database'] = (string)$status['message'];
            } elseif (!$this->createSchema) {
                $errors['_database'] = 'Включите «Создать таблицы автоматически» или импортируйте схему MajorDoMo вручную.';
            }
        }

        // --- Field-level validation.
        foreach (InstallSchema::fields() as $id => $field) {
            if (!$this->fieldIncluded($field, $values)) {
                continue;
            }
            $error = $this->validateField($id, $field, $values, $post);
            if ($error !== '') {
                $errors[$id] = $error;
            }
        }

        // --- Cross-field rules.
        $rootHtml = (string)($values['ROOTHTML'] ?? '');
        if ($rootHtml !== '' && $rootHtml !== '/' && !preg_match('#^/[A-Za-z0-9._~\-/]*/$#', $rootHtml)) {
            $errors['ROOTHTML'] = 'Путь должен начинаться и заканчиваться слэшем, например / или /majordomo/.';
        }
        if (str_contains($rootHtml, '..')) {
            $errors['ROOTHTML'] = 'Путь не должен содержать «..».';
        }

        if (($values['TRACK_DATA_CHANGES'] ?? '0') === '1' && trim((string)($values['TRACK_DATA_CHANGES_IGNORE'] ?? '')) === '') {
            $warnings[] = 'Журнал изменений объектов включён без списка исключений — история будет расти быстрее.';
        }
        if (($values['DEBUG_MODE'] ?? '0') === '1') {
            $warnings[] = 'Режим отладки включён. Для постоянной работы его лучше выключить.';
        }
        $baseUrl = (string)($values['BASE_URL'] ?? '');
        $host = parse_url($baseUrl, PHP_URL_HOST);
        if (is_string($host) && in_array($host, array('127.0.0.1', 'localhost', '::1'), true)) {
            $warnings[] = 'BASE_URL указывает на локальный адрес. С других устройств в домашней сети система по нему не откроется.';
        }

        $overrides = $this->environment->overrides(InstallSchema::managedEnvKeys());
        if ($overrides['local'] !== array()) {
            $warnings[] = 'Эти ключи перекрыты в .env.local и могут остаться прежними: ' . implode(', ', $overrides['local']) . '.';
        }
        if ($overrides['process'] !== array()) {
            $warnings[] = 'Эти ключи заданы окружением процесса и имеют приоритет над .env: ' . implode(', ', $overrides['process']) . '.';
        }
        if (!empty($overrides['constant'])) {
            $warnings[] = 'Эти ключи всё ещё определяются старым config.php как константы: '
                . implode(', ', $overrides['constant']) . '. Значения в .env не переопределят их.';
        }

        $this->values = $values;
        return array(
            'ok' => $errors === array(),
            'errors' => $errors,
            'warnings' => array_values(array_unique($warnings)),
            'values' => $values,
            'needs_admin' => $this->needsAdmin,
            'db_status' => $this->currentDbStatus,
            'step' => $this->firstErrorStep($errors),
        );
    }

    /**
     * Test the database credentials currently typed into the form, without
     * saving anything. Returns the safe subset of the status (no password).
     *
     * @param array<string,mixed> $post
     * @return array<string,mixed>
     */
    public function testDatabase(array $post): array
    {
        $values = $this->mergePost($post);
        $status = $this->environment->databaseStatus($this->databaseConfig($values, $post));
        unset($status['config']);
        $status['needs_admin'] = $status['ok'] && ($status['admin_exists'] === false);
        return $status;
    }

    /**
     * @param array<string,mixed> $post
     * @return array<string,mixed>
     */
    public function apply(array $post): array
    {
        $validation = $this->validate($post);
        if (empty($validation['ok'])) {
            return array(
                'ok' => false,
                'errors' => $validation['errors'],
                'warnings' => $validation['warnings'],
                'values' => $validation['values'],
                'step' => $validation['step'],
                'needs_admin' => $validation['needs_admin'],
                'db_status' => $validation['db_status'],
            );
        }

        $values = $validation['values'];
        $warnings = $validation['warnings'];
        $dbConfig = $this->databaseConfig($values, $post);

        $database = new InstallDatabase($dbConfig);
        if (!$database->connect()) {
            return array('ok' => false, 'errors' => array('_database' => $database->error()), 'warnings' => $warnings, 'values' => $values, 'step' => InstallSchema::STEP_DATABASE, 'needs_admin' => $this->needsAdmin);
        }

        $schemaImported = false;
        if ($this->createSchema) {
            $schemaPath = $this->root . '/lib/core/Install/schema.sql';
            $schemaResult = $database->importSchema($schemaPath);
            if (empty($schemaResult['ok'])) {
                $database->close();
                return array('ok' => false, 'errors' => array('_database' => (string)$schemaResult['error']), 'warnings' => $warnings, 'values' => $values, 'step' => InstallSchema::STEP_DATABASE, 'needs_admin' => true);
            }
            $schemaImported = true;
            $warnings[] = 'Таблицы MajorDoMo созданы автоматически из чистой схемы — без демо-данных и пользователей.';
        }

        $requiredAfterImport = array('settings', 'users');
        $missingAfterImport = array();
        foreach ($requiredAfterImport as $requiredTable) {
            if (!$database->tableExists($requiredTable)) {
                $missingAfterImport[] = $requiredTable;
            }
        }
        if ($missingAfterImport !== array()) {
            $database->close();
            $hint = !empty($this->currentDbStatus['can_auto_import'])
                ? 'Включите «Создать таблицы автоматически» или импортируйте схему MajorDoMo вручную.'
                : 'Импортируйте схему MajorDoMo вручную: автоматический импорт доступен только для пустой базы.';
            return array('ok' => false, 'errors' => array('_database' => 'В базе нет обязательных таблиц: ' . implode(', ', $missingAfterImport) . '. ' . $hint), 'warnings' => $warnings, 'values' => $values, 'step' => InstallSchema::STEP_DATABASE, 'needs_admin' => $this->needsAdmin);
        }

        $dbResult = $database->upsertSettings($this->dbSettingValues($values), $this->dbSettingMeta());
        if (empty($dbResult['ok'])) {
            $database->close();
            return array('ok' => false, 'errors' => array('_database' => (string)$dbResult['error']), 'warnings' => $warnings, 'values' => $values, 'step' => InstallSchema::STEP_DATABASE, 'needs_admin' => $this->needsAdmin);
        }

        $adminCreated = false;
        if ($this->needsAdmin) {
            $admin = $database->createAdmin(
                (string)$values['admin_login'],
                (string)$values['admin_password'],
                (string)$values['admin_name']
            );
            if (empty($admin['ok'])) {
                $database->close();
                return array('ok' => false, 'errors' => array('admin_login' => (string)$admin['error']), 'warnings' => $warnings, 'values' => $values, 'step' => InstallSchema::STEP_SYSTEM, 'needs_admin' => true);
            }
            $adminCreated = true;
            $warnings[] = 'Создан администратор «' . $values['admin_login'] . '». Сохраните пароль: восстановить его нельзя.';
        }
        $database->close();

        $backup = EnvFile::backup($this->root);
        if ($backup === null) {
            $warnings[] = 'Не удалось создать резервную копию .env. Проверьте права на каталог проекта.';
        }
        $envResult = EnvFile::apply($this->root, $this->envValues($values, $post));
        if (empty($envResult['ok'])) {
            return array('ok' => false, 'errors' => array('_database' => (string)$envResult['error']), 'warnings' => $warnings, 'values' => $values, 'step' => InstallSchema::STEP_REVIEW, 'needs_admin' => $this->needsAdmin);
        }

        ConfigCache::clear($this->root);

        $marker = InstallState::markComplete($this->root, array(
            'source' => 'install.php',
            'project_title' => (string)$values['PROJECT_TITLE'],
            'base_url' => (string)$values['BASE_URL'],
            'db_name' => (string)$values['DB_NAME'],
        ));
        if (!$marker) {
            return array(
                'ok' => false,
                'errors' => array('_database' => 'Настройки записаны, но не удалось создать маркер установки в cms/modules_installed. Проверьте права на каталог и повторите.'),
                'warnings' => $warnings,
                'values' => $values,
                'step' => InstallSchema::STEP_REVIEW,
                'needs_admin' => $this->needsAdmin,
            );
        }

        $summary = array(
            'project_title' => (string)$values['PROJECT_TITLE'],
            'system_name' => (string)$values['GENERAL_ALICE_NAME'],
            'base_url' => (string)$values['BASE_URL'],
            'root_html' => (string)$values['ROOTHTML'],
            'db_name' => (string)$values['DB_NAME'],
            'db_server' => (string)$values['DB_HOST'],
            'theme' => (string)$values['THEME'],
            'language' => (string)$values['SITE_LANGUAGE'],
            'timezone' => (string)$values['SITE_TIMEZONE'],
            'admin_login' => $adminCreated ? (string)$values['admin_login'] : '',
            'admin_created' => $adminCreated,
            'redis' => ($values['redis_enabled'] ?? '0') === '1',
            'websockets' => true,
            'websockets_port' => (string)\MajorDoMo\Core\WebSockets\Runtime::PORT,
            'backup' => $backup !== null ? $backup : '',
            'env_updated' => (int)$envResult['updated'],
            'env_appended' => (int)$envResult['appended'],
            'env_removed' => (int)$envResult['removed'],
            'db_written' => (int)$dbResult['written'],
            'schema_created' => $schemaImported,
            'completed_at' => date('c'),
        );

        return array(
            'ok' => true,
            'errors' => array(),
            'warnings' => $warnings,
            'values' => $values,
            'summary' => $summary,
            'step' => InstallSchema::STEP_REVIEW,
            'needs_admin' => $this->needsAdmin,
        );
    }

    /**
     * @param array<string,string> $errors
     */
    public function firstErrorStep(array $errors): int
    {
        if (isset($errors['_database'])) {
            return InstallSchema::STEP_DATABASE;
        }
        $fields = InstallSchema::fields();
        $first = InstallSchema::STEP_REVIEW;
        foreach ($errors as $id => $message) {
            if (!isset($fields[$id])) {
                continue;
            }
            $step = (int)$fields[$id]['step'];
            if ($step < $first) {
                $first = $step;
            }
        }
        return $first;
    }

    /**
     * @param array<string,mixed> $field
     * @param array<string,string> $values
     */
    public function fieldIncluded(array $field, array $values): bool
    {
        if (($field['condition'] ?? '') === 'needs_admin' && !$this->needsAdmin) {
            return false;
        }
        if (!empty($field['depends']) && is_array($field['depends'])) {
            $depends = $field['depends'];
            if (isset($depends['field'], $depends['value'])) {
                $dependsField = InstallSchema::field((string)$depends['field']);
                if ($dependsField !== null && !$this->fieldIncluded($dependsField, $values)) {
                    return false;
                }
                $current = (string)($values[(string)$depends['field']] ?? '0');
                return $current === (string)$depends['value'];
            }
        }
        return true;
    }

    /**
     * Import is allowed only when the selected database is completely empty.
     * This keeps the wizard from touching a database that already has tables.
     *
     * @param array<string,string> $values
     * @param array<string,mixed> $status
     */
    private function shouldCreateSchema(array $values, array $status): bool
    {
        return !empty($status['ok'])
            && !empty($status['can_auto_import'])
            && (string)($values['create_schema'] ?? '1') === '1';
    }

    private function loadDefaults(): void
    {
        $this->currentDbStatus = $this->environment->databaseStatus();
        // On a fresh/empty database there is no administrator yet. Show the
        // admin fields even before the DB is reachable, so a first-time user
        // is forced to provide a login and password.
        $this->needsAdmin = empty($this->currentDbStatus['ok'])
            || !empty($this->currentDbStatus['can_auto_import'])
            || !empty($this->currentDbStatus['missing'])
            || ($this->currentDbStatus['admin_exists'] === false);

        $dbSettings = array();
        if ($this->currentDbStatus['ok']) {
            $database = new InstallDatabase($this->currentDbStatus['config']);
            if ($database->connect()) {
                $dbSettings = $database->fetchSettings(InstallSchema::dbSettingNames());
                $database->close();
            }
        }

        $values = InstallSchema::defaults();
        $fields = InstallSchema::fields();
        foreach ($fields as $id => $field) {
            $target = (string)$field['target'];
            if ($target === InstallSchema::TARGET_ENV) {
                $values[$id] = $this->configString($id, $values[$id], (string)($field['type'] ?? 'text'));
            } elseif ($target === InstallSchema::TARGET_DB) {
                if (array_key_exists($id, $dbSettings)) {
                    $values[$id] = (string)$dbSettings[$id];
                }
            }
            if (!empty($field['secret'])) {
                $existing = $target === InstallSchema::TARGET_ENV && Config::has($id) && (string)Config::get($id) !== '';
                $this->existingSecrets[$id] = $existing;
                if ($existing) {
                    $values[$id] = '';
                }
            }
        }

        // Meta toggles are derived from the current effective configuration.
        $useRedis = Config::has('USE_REDIS') ? Config::get('USE_REDIS') : false;
        $redisHost = is_string($useRedis) && $useRedis !== '' ? $useRedis : '127.0.0.1';
        $values['redis_enabled'] = is_string($useRedis) && $useRedis !== '' ? '1' : '0';
        $values['REDIS_HOST'] = $redisHost;

        if (($values['GENERAL_ALICE_NAME'] ?? '') === '') {
            $values['GENERAL_ALICE_NAME'] = Config::string('PROJECT_TITLE', 'Мой дом');
        }
        if (($values['SITE_TIMEZONE'] ?? '') === '') {
            $values['SITE_TIMEZONE'] = $values['TIMEZONE'] !== '' ? $values['TIMEZONE'] : 'Europe/Moscow';
        }
        if (($values['SITE_LANGUAGE'] ?? '') === '') {
            $values['SITE_LANGUAGE'] = 'ru';
        }
        if (($values['THEME'] ?? '') === '') {
            $values['THEME'] = 'dark';
        }

        $this->values = $values;
    }

    private function configString(string $key, string $fallback, string $type): string
    {
        if (!Config::has($key)) {
            return $fallback;
        }
        if ($type === 'switch') {
            return Config::bool($key) ? '1' : '0';
        }
        $value = Config::get($key);
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_array($value)) {
            return implode(', ', array_map('strval', $value));
        }
        return (string)$value;
    }

    /**
     * @param array<string,mixed> $post
     * @return array<string,string>
     */
    private function mergePost(array $post): array
    {
        $values = $this->values;
        foreach (InstallSchema::fields() as $id => $field) {
            $type = (string)($field['type'] ?? 'text');
            if ($type === 'switch') {
                $values[$id] = !empty($post[$id]) && (string)$post[$id] !== '0' ? '1' : '0';
                continue;
            }
            // A missing text/number field means "not changed", not "empty":
            // conditional sections may be omitted by a partial client, and the
            // safe behaviour is to keep the current/default value. Switches are
            // handled above because an absent checkbox explicitly means "off".
            $raw = array_key_exists($id, $post) && is_scalar($post[$id])
                ? (string)$post[$id]
                : (string)($this->values[$id] ?? '');
            $raw = str_replace(array("\r", "\n", "\0"), ' ', $raw);
            $raw = trim($raw);
            if (!empty($field['secret'])) {
                $clear = !empty($post[$id . '__clear']);
                if ($clear) {
                    $this->secretActions[$id] = 'clear';
                    $values[$id] = '';
                } elseif ($raw !== '') {
                    $this->secretActions[$id] = 'set';
                    $values[$id] = $raw;
                } elseif (!empty($this->existingSecrets[$id])) {
                    $this->secretActions[$id] = 'keep';
                    $values[$id] = '';
                } else {
                    $this->secretActions[$id] = 'set';
                    $values[$id] = '';
                }
                continue;
            }
            $values[$id] = $raw;
        }

        // Normalise the values that have a single canonical representation.
        $values['PROJECT_TITLE'] = trim((string)$values['PROJECT_TITLE']);
        if ($values['PROJECT_TITLE'] === '') {
            $values['PROJECT_TITLE'] = 'MajorDoMo';
        }
        $values['GENERAL_ALICE_NAME'] = trim((string)$values['GENERAL_ALICE_NAME']);
        if ($values['GENERAL_ALICE_NAME'] === '') {
            $values['GENERAL_ALICE_NAME'] = $values['PROJECT_TITLE'];
        }
        $values['ROOTHTML'] = '/' . trim(str_replace('\\', '/', (string)$values['ROOTHTML']), '/') . '/';
        if ($values['ROOTHTML'] === '//') {
            $values['ROOTHTML'] = '/';
        }
        $values['BASE_URL'] = rtrim(trim((string)$values['BASE_URL']), '/');
        $values['GIT_URL'] = rtrim(trim((string)$values['GIT_URL']), '/');
        if (($values['SITE_LANGUAGE'] ?? '') === '') {
            $values['SITE_LANGUAGE'] = 'ru';
        }
        if (($values['SITE_TIMEZONE'] ?? '') === '') {
            $values['SITE_TIMEZONE'] = $values['TIMEZONE'] !== '' ? $values['TIMEZONE'] : 'Europe/Moscow';
        }
        if (($values['THEME'] ?? '') === '') {
            $values['THEME'] = 'dark';
        }
        if (($values['APP_ENV'] ?? '') === '') {
            $values['APP_ENV'] = 'production';
        }
        if (($values['redis_enabled'] ?? '') === '1' && trim((string)$values['REDIS_HOST']) === '') {
            $values['REDIS_HOST'] = '127.0.0.1';
        }

        return $values;
    }

    /**
     * @param array<string,mixed> $field
     * @param array<string,string> $values
     * @param array<string,mixed> $post
     */
    private function validateField(string $id, array $field, array $values, array $post): string
    {
        $type = (string)($field['type'] ?? 'text');
        $value = (string)($values[$id] ?? '');
        $required = !empty($field['required']);
        $isSecret = !empty($field['secret']);
        $secretExists = $isSecret && !empty($this->existingSecrets[$id]) && ($this->secretActions[$id] ?? '') === 'keep';

        if ($required && $value === '' && !$secretExists) {
            return 'Заполните это поле.';
        }
        if ($value === '') {
            return '';
        }


        if ($type === 'number') {
            if (!is_numeric($value)) {
                return 'Введите число.';
            }
            $number = (float)$value;
            if (isset($field['min']) && $number < (float)$field['min']) {
                return 'Значение не может быть меньше ' . (string)$field['min'] . '.';
            }
            if (isset($field['max']) && $number > (float)$field['max']) {
                return 'Значение не может быть больше ' . (string)$field['max'] . '.';
            }
        }

        if ($type === 'url') {
            if (!filter_var($value, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $value)) {
                return 'Введите полный адрес, начиная с http:// или https://.';
            }
        }

        if ($type === 'timezone') {
            $list = InstallSchema::timezoneList();
            if (!isset($list[$value])) {
                return 'Выберите часовой пояс из списка.';
            }
        }

        if ($type === 'select' || $type === 'choice') {
            $allowed = array();
            foreach ((array)($field['options'] ?? array()) as $key => $option) {
                if (is_array($option)) {
                    $allowed[] = (string)($option['value'] ?? $key);
                } else {
                    $allowed[] = (string)$key;
                }
            }
            if ($allowed !== array() && !in_array($value, $allowed, true)) {
                return 'Выберите значение из списка.';
            }
        }

        if ($type === 'password' && $id === 'admin_password') {
            if (mb_strlen($value) < 8) {
                return 'Пароль должен быть не короче 8 символов.';
            }
            try { \MajorDoMo\Core\Users\Passwords::validate($value); }
            catch (\InvalidArgumentException $error) { return $error->getMessage(); }
        }

        if ($id === 'admin_login' && !preg_match('/^[A-Za-z0-9_.-]{3,64}$/D', $value)) {
            return 'Логин: 3–64 символа, латиница, цифры, точка, дефис или подчёркивание.';
        }

        if ($id === 'admin_password_confirm') {
            if ($value !== (string)($values['admin_password'] ?? '')) {
                return 'Пароли не совпадают.';
            }
        }

        if (in_array($id, array('DB_HOST', 'DB_NAME', 'REDIS_HOST'), true) && preg_match('/\s/', $value)) {
            return 'Значение не должно содержать пробелы.';
        }

        if ($id === 'ROOTHTML' && $value !== '/' && !preg_match('#^/[A-Za-z0-9._~\-/]*/$#', $value)) {
            return 'Путь должен начинаться и заканчиваться слэшем, например / или /majordomo/.';
        }

        if (strlen($value) > 2000) {
            return 'Значение слишком длинное.';
        }

        return '';
    }

    /**
     * @param array<string,string> $values
     * @param array<string,mixed> $post
     * @return array<string,string>
     */
    private function databaseConfig(array $values, array $post): array
    {
        $password = (string)($values['DB_PASSWORD'] ?? '');
        $action = $this->secretActions['DB_PASSWORD'] ?? '';
        if ($action === 'keep') {
            $password = Config::string('DB_PASSWORD');
        } elseif ($action === 'clear') {
            $password = '';
        } elseif ($action === '' && $password === '' && !empty($this->existingSecrets['DB_PASSWORD'])) {
            $password = Config::string('DB_PASSWORD');
        }
        unset($post);
        return array(
            'host' => (string)($values['DB_HOST'] ?? ''),
            'port' => (string)($values['DB_PORT'] ?? ''),
            'name' => (string)($values['DB_NAME'] ?? ''),
            'user' => (string)($values['DB_USER'] ?? ''),
            'password' => $password,
        );
    }

    /**
     * @param array<string,string> $values
     * @param array<string,mixed> $post
     * @return array<string,string|null>
     */
    private function envValues(array $values, array $post): array
    {
        $map = array();
        foreach (InstallSchema::fields() as $id => $field) {
            if (($field['target'] ?? '') !== InstallSchema::TARGET_ENV) {
                continue;
            }
            if (!$this->fieldIncluded($field, $values)) {
                continue;
            }
            $type = (string)($field['type'] ?? 'text');
            if (!empty($field['secret'])) {
                $action = $this->secretActions[$id] ?? 'set';
                if ($action === 'keep') {
                    continue;
                }
                $map[$id] = (string)($values[$id] ?? '');
                continue;
            }
            $value = (string)($values[$id] ?? '');
            if ($type === 'switch') {
                $map[$id] = $value === '1' ? '1' : '0';
                continue;
            }
            if ($value === '' && empty($field['required'])) {
                $map[$id] = null;
                continue;
            }
            $map[$id] = $value;
        }

        $map['USE_REDIS'] = ($values['redis_enabled'] ?? '0') === '1'
            ? (trim((string)($values['REDIS_HOST'] ?? '')) !== '' ? (string)$values['REDIS_HOST'] : '127.0.0.1')
            : 'false';

        // Redis/TLS/Windows-only keys must not be written when their feature is off.
        if (($values['redis_enabled'] ?? '0') !== '1') {
            foreach (array('REDIS_PORT', 'REDIS_DB', 'REDIS_USERNAME', 'REDIS_PASSWORD', 'REDIS_TIMEOUT', 'REDIS_PREFIX') as $key) {
                unset($map[$key]);
            }
        }
        unset($post);
        return $map;
    }

    /**
     * @param array<string,string> $values
     * @return array<string,string>
     */
    private function dbSettingValues(array $values): array
    {
        $map = array();
        foreach (InstallSchema::fields() as $id => $field) {
            if (($field['target'] ?? '') !== InstallSchema::TARGET_DB) {
                continue;
            }
            if (!$this->fieldIncluded($field, $values)) {
                continue;
            }
            $value = (string)($values[$id] ?? '');
            if (($field['type'] ?? 'text') === 'switch') {
                $value = $value === '1' ? '1' : '0';
            }
            $map[$id] = $value;
        }
        return $map;
    }

    /**
     * @return array<string,array{title:string,type:string,default:string,notes:string,priority:int,data?:string}>
     */
    private function dbSettingMeta(): array
    {
        $meta = array();
        foreach (InstallSchema::fields() as $id => $field) {
            if (($field['target'] ?? '') !== InstallSchema::TARGET_DB) {
                continue;
            }
            $type = (string)($field['type'] ?? 'text');
            $metaType = 'text';
            $data = '';
            if ($type === 'switch') {
                $metaType = 'onoff';
            } elseif ($type === 'select') {
                $metaType = 'select';
                $parts = array();
                foreach ((array)($field['options'] ?? array()) as $key => $label) {
                    $parts[] = $key . '=' . (is_array($label) ? ($label['label'] ?? $key) : $label);
                }
                $data = implode('|', $parts);
            } elseif ($type === 'choice') {
                $metaType = 'select';
                $parts = array();
                foreach ((array)($field['options'] ?? array()) as $option) {
                    if (is_array($option)) {
                        $parts[] = ($option['value'] ?? '') . '=' . ($option['label'] ?? '');
                    }
                }
                $data = implode('|', $parts);
            }
            $meta[$id] = array(
                'title' => (string)($field['label'] ?? $id),
                'type' => $metaType,
                'default' => (string)($field['default'] ?? ''),
                'notes' => (string)($field['description'] ?? ''),
                'priority' => 0,
            );
            if ($data !== '') {
                $meta[$id]['data'] = $data;
            }
        }
        return $meta;
    }
}
