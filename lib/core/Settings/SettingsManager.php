<?php

namespace MajorDoMo\Core\Settings;

use MajorDoMo\Core\Config;
use MajorDoMo\Core\ConfigCache;
use MajorDoMo\Core\Cycles\CycleManager;
use MajorDoMo\Core\Install\EnvFile;
use MajorDoMo\Core\Install\InstallDatabase;
use MajorDoMo\Core\Install\InstallEnvironment;
use MajorDoMo\Core\Install\InstallSchema;

/**
 * Read, validate and persist the settings from the admin "Общие настройки"
 * page. Environment values go to `.env`; UI/behaviour values go to the
 * `settings` table. A database switch is validated before anything is written,
 * and every successful save restarts the background cycles.
 */
final class SettingsManager
{
    private string $root;
    /** @var array<string,string> */
    private array $values = array();
    /** @var array<string,bool> */
    private array $existingSecrets = array();
    /** @var array<string,string> */
    private array $secretActions = array();
    /** @var array<string,mixed> */
    private array $dbStatus = array();
    /** @var array<string,string> */
    private array $errors = array();
    /** @var string[] */
    private array $warnings = array();

    public function __construct(string $root)
    {
        $this->root = rtrim($root, '/\\');
        $this->load();
    }

    /**
     * @return array<string,string>
     */
    public function values(): array
    {
        return $this->values;
    }

    /**
     * Keys whose values differ from the loaded state.
     *
     * Secret fields left empty mean "keep the stored value"; values themselves
     * are never included in the event, only the names.
     *
     * @param array<string,mixed> $values
     * @return string[]
     */
    private function changedKeys(array $values): array
    {
        $changed = array();
        foreach ($values as $key => $value) {
            $old = (string)($this->values[$key] ?? '');
            $new = (string)$value;
            if ($old === $new) {
                continue;
            }
            if ($new === '' && isset($this->existingSecrets[$key])) {
                continue;
            }
            $changed[] = (string)$key;
        }
        return $changed;
    }

    /**
     * @return array<string,bool>
     */
    public function existingSecrets(): array
    {
        return $this->existingSecrets;
    }

    /**
     * @return array<string,string>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * @return string[]
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * @return array<string,mixed>
     */
    public function databaseStatus(): array
    {
        return $this->dbStatus;
    }

    /**
     * @param array<string,mixed> $post
     * @return array{ok:bool,errors:array<string,string>,warnings:string[],values:array<string,string>,db_status:array<string,mixed>,db_changed:bool}
     */
    public function validate(array $post): array
    {
        $this->errors = array();
        $this->warnings = array();
        $values = $this->mergePost($post);
        $this->values = $values;

        $dbConfig = $this->databaseConfig($values);
        $this->dbStatus = $this->checkDatabase($dbConfig);
        if (empty($this->dbStatus['ok'])) {
            $this->errors['_database'] = (string)($this->dbStatus['message'] ?? 'Не удалось подключиться к базе данных.');
        } elseif (empty($this->dbStatus['admin_exists']) && $this->isDatabaseChanged($dbConfig)) {
            $this->errors['_database'] = 'В выбранной базе нет администратора. Сначала установите MajorDoMo в эту базу через /install.php, иначе после сохранения вы потеряете доступ.';
        }

        foreach (SettingsSchema::fields() as $id => $field) {
            if (!$this->fieldIncluded($field, $values)) {
                continue;
            }
            $error = $this->validateField($id, $field, $values);
            if ($error !== '') {
                $this->errors[$id] = $error;
            }
        }

        $rootHtml = (string)($values['ROOTHTML'] ?? '');
        if ($rootHtml !== '' && $rootHtml !== '/' && !preg_match('#^/[A-Za-z0-9._~\-/]*/$#', $rootHtml)) {
            $this->errors['ROOTHTML'] = 'Путь должен начинаться и заканчиваться слэшем, например / или /majordomo/.';
        }
        if (str_contains($rootHtml, '..')) {
            $this->errors['ROOTHTML'] = 'Путь не должен содержать «..».';
        }

        if (($values['WEBSOCKETS_CHECK_ORIGIN'] ?? '0') === '1' && trim((string)($values['WEBSOCKETS_ALLOWED_ORIGINS'] ?? '')) === '') {
            $this->errors['WEBSOCKETS_ALLOWED_ORIGINS'] = 'При проверке Origin укажите хотя бы один разрешённый адрес.';
        }
        if (($values['TRACK_DATA_CHANGES'] ?? '0') === '1' && trim((string)($values['TRACK_DATA_CHANGES_IGNORE'] ?? '')) === '') {
            $this->warnings[] = 'Журнал изменений объектов включён без списка исключений — история будет расти быстрее.';
        }
        if (($values['DEBUG_MODE'] ?? '0') === '1') {
            $this->warnings[] = 'Режим отладки включён. Для постоянной работы его лучше выключить.';
        }
        $baseUrl = (string)($values['BASE_URL'] ?? '');
        $host = parse_url($baseUrl, PHP_URL_HOST);
        if (is_string($host) && in_array($host, array('127.0.0.1', 'localhost', '::1'), true)) {
            $this->warnings[] = 'BASE_URL указывает на локальный адрес. С других устройств в домашней сети система по нему не откроется.';
        }

        try {
            $overrides = (new InstallEnvironment($this->root))->overrides(SettingsSchema::envKeys());
            if (!empty($overrides['local'])) {
                $this->warnings[] = 'Эти ключи перекрыты в .env.local: ' . implode(', ', $overrides['local']) . '.';
            }
            if (!empty($overrides['process'])) {
                $this->warnings[] = 'Эти ключи заданы окружением процесса и имеют приоритет: ' . implode(', ', $overrides['process']) . '.';
            }
        } catch (\Throwable $e) {
            // A warning must never block saving settings.
        }

        return array(
            'ok' => $this->errors === array(),
            'errors' => $this->errors,
            'warnings' => array_values(array_unique($this->warnings)),
            'values' => $values,
            'db_status' => $this->dbStatus,
            'db_changed' => $this->isDatabaseChanged($dbConfig),
        );
    }

    /**
     * Validate database credentials without saving anything.
     *
     * @param array<string,mixed> $post
     * @return array<string,mixed>
     */
    public function testDatabase(array $post): array
    {
        $values = $this->mergePost($post);
        $status = $this->checkDatabase($this->databaseConfig($values));
        return $status;
    }

    /**
     * @param array<string,mixed> $post
     * @return array{ok:bool,errors:array<string,string>,warnings:string[],values:array<string,string>,db_changed:bool,cycles:array<string,mixed>,reload:bool}
     */
    public function save(array $post): array
    {
        $validation = $this->validate($post);
        if (empty($validation['ok'])) {
            return array(
                'ok' => false,
                'errors' => $validation['errors'],
                'warnings' => $validation['warnings'],
                'values' => $validation['values'],
                'db_changed' => false,
                'cycles' => array(),
                'reload' => false,
            );
        }

        $values = $validation['values'];
        $dbConfig = $this->databaseConfig($values);
        $database = new InstallDatabase($dbConfig);
        if (!$database->connect()) {
            return array(
                'ok' => false,
                'errors' => array('_database' => $database->error()),
                'warnings' => $validation['warnings'],
                'values' => $values,
                'db_changed' => false,
                'cycles' => array(),
                'reload' => false,
            );
        }

        $dbResult = $database->upsertSettings($this->dbValues($values), $this->dbMeta());
        $database->close();
        if (empty($dbResult['ok'])) {
            return array(
                'ok' => false,
                'errors' => array('_database' => (string)$dbResult['error']),
                'warnings' => $validation['warnings'],
                'values' => $values,
                'db_changed' => false,
                'cycles' => array(),
                'reload' => false,
            );
        }

        EnvFile::backup($this->root);
        $envResult = EnvFile::apply($this->root, $this->envValues($values));
        if (empty($envResult['ok'])) {
            return array(
                'ok' => false,
                'errors' => array('_database' => (string)$envResult['error']),
                'warnings' => $validation['warnings'],
                'values' => $values,
                'db_changed' => false,
                'cycles' => array(),
                'reload' => false,
            );
        }
        ConfigCache::clear($this->root);

        $changedKeys = $this->changedKeys($values);
        if ($changedKeys !== array()) {
            \MajorDoMo\Core\Events\SystemEvents::settingsChanged(
                (string)($post['section'] ?? $post['filter_name'] ?? 'general'),
                $changedKeys
            );
        }

        $dbChanged = $this->isDatabaseChanged($dbConfig);
        $websocketsChanged = Config::bool('DISABLE_WEBSOCKETS') !== (($values['websockets_enabled'] ?? '0') !== '1');
        $manager = new CycleManager($this->root);
        $cycles = array();
        $reload = false;
        if ($dbChanged || $websocketsChanged) {
            // The supervisor also needs the new flag before deciding which cycles to run.
            $manager->requestReload();
            $reload = true;
        } else {
            $cycles = $manager->restartAll();
        }
        if (!$manager->isSupervisorRunning()) {
            $this->warnings[] = 'Основной цикл (cycle.php) сейчас не запущен. Настройки сохранены, но фоновые процессы подхватят их при следующем запуске.';
        }

        return array(
            'ok' => true,
            'errors' => array(),
            'warnings' => array_values(array_unique($this->warnings)),
            'values' => $values,
            'db_changed' => $dbChanged,
            'cycles' => $cycles,
            'reload' => $reload,
        );
    }

    /**
     * Reset a section to schema defaults. The database section is intentionally
     * excluded: a reset there would point a working installation at localhost.
     *
     * @return array<string,mixed>
     */
    public function resetSection(string $section): array
    {
        if ($section === SettingsSchema::SECTION_DATABASE) {
            return array(
                'ok' => false,
                'errors' => array('_section' => 'Сброс раздела «База данных» отключён, чтобы случайно не потерять подключение.'),
                'warnings' => array(),
                'values' => $this->values,
                'db_changed' => false,
            );
        }
        $post = array();
        foreach (SettingsSchema::fieldsForSection($section) as $id => $field) {
            if (!empty($field['secret'])) {
                $post['clear_' . $id] = '1';
                continue;
            }
            $default = isset($field['default']) ? (string)$field['default'] : '';
            // A required value with no usable default (BASE_URL, the system
            // name) stays as-is; resetting it to an empty string would make the
            // whole section unsavable.
            if ($default === '' && !empty($field['required'])) {
                $post[$id] = (string)($this->values[$id] ?? '');
                continue;
            }
            $post[$id] = $default;
        }
        return $this->save($post);
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function formRows(string $section): array
    {
        return (new SettingsPresenter($this))->rows($section);
    }

    public function sectionChangedCount(string $section): int
    {
        $count = 0;
        foreach (SettingsSchema::fieldsForSection($section) as $id => $field) {
            $current = (string)($this->values[$id] ?? '');
            $default = isset($field['default']) ? (string)$field['default'] : '';
            if ($current !== $default) {
                $count++;
            }
        }
        return $count;
    }

    private function load(): void
    {
        $dbValues = $this->fetchDbValues(SettingsSchema::dbSettingNames());
        $values = SettingsSchema::defaults();
        foreach (SettingsSchema::fields() as $id => $field) {
            $target = (string)($field['target'] ?? 'db');
            if ($target === InstallSchema::TARGET_ENV) {
                $values[$id] = $this->configString($id, $values[$id], (string)($field['type'] ?? 'text'));
            } elseif ($target === InstallSchema::TARGET_DB) {
                if (array_key_exists($id, $dbValues)) {
                    $values[$id] = (string)$dbValues[$id];
                }
            }
            if (!empty($field['secret'])) {
                if ($target === InstallSchema::TARGET_ENV) {
                    $existing = Config::has($id) && (string)Config::get($id) !== '';
                } else {
                    $existing = isset($dbValues[$id]) && (string)$dbValues[$id] !== '';
                }
                $this->existingSecrets[$id] = $existing;
                if ($existing) {
                    $values[$id] = '';
                }
            }
        }

        $useRedis = Config::has('USE_REDIS') ? Config::get('USE_REDIS') : false;
        $values['redis_enabled'] = is_string($useRedis) && $useRedis !== '' ? '1' : '0';
        $values['REDIS_HOST'] = is_string($useRedis) && $useRedis !== '' ? $useRedis : '127.0.0.1';
        $values['websockets_enabled'] = Config::has('DISABLE_WEBSOCKETS') && Config::bool('DISABLE_WEBSOCKETS') ? '0' : '1';

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
        $this->dbStatus = $this->checkDatabase($this->databaseConfig($values));
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
     * @param string[] $names
     * @return array<string,string>
     */
    private function fetchDbValues(array $names): array
    {
        if ($names === array() || !function_exists('SQLSelect')) {
            return array();
        }
        $quoted = array();
        foreach ($names as $name) {
            if (preg_match('/^[A-Za-z0-9_]+$/D', (string)$name)) {
                $quoted[] = "'" . (function_exists('DBSafe') ? DBSafe((string)$name) : str_replace("'", "''", (string)$name)) . "'";
            }
        }
        if ($quoted === array()) {
            return array();
        }
        try {
            $rows = SQLSelect('SELECT NAME, VALUE FROM settings WHERE NAME IN (' . implode(',', $quoted) . ')');
        } catch (\Throwable $e) {
            return array();
        }
        $values = array();
        foreach ((array)$rows as $row) {
            if (isset($row['NAME'])) {
                $values[(string)$row['NAME']] = (string)($row['VALUE'] ?? '');
            }
        }
        return $values;
    }

    /**
     * @param array<string,mixed> $post
     * @return array<string,string>
     */
    private function mergePost(array $post): array
    {
        $values = $this->values;
        foreach (SettingsSchema::fields() as $id => $field) {
            // The "Общие настройки" page is rendered by the shared settings
            // template, which names every control `value_<ID>` (that template
            // also serves DB-backed rows). The install wizard and resetSection()
            // post plain field IDs. Accept both, preferring the explicit form
            // value, otherwise the page would silently save the old values.
            $key = array_key_exists('value_' . $id, $post) ? 'value_' . $id : $id;
            $type = (string)($field['type'] ?? 'text');
            if ($type === 'switch') {
                // Only the switches actually rendered in the submitted form may
                // change. The admin page saves one section at a time, so an
                // absent switch means "another section", not "off"; treating it
                // as off would silently disable options across the whole .env.
                if (array_key_exists($key, $post)) {
                    $values[$id] = !empty($post[$key]) && (string)$post[$key] !== '0' ? '1' : '0';
                }
                continue;
            }
            if (!empty($field['secret'])) {
                $clear = !empty($post['clear_' . $id]) || !empty($post[$id . '__clear']);
                $raw = array_key_exists($key, $post) && is_scalar($post[$key]) ? trim((string)$post[$key]) : '';
                if ($clear) {
                    $this->secretActions[$id] = 'clear';
                    $values[$id] = '';
                } elseif ($raw !== '') {
                    $this->secretActions[$id] = 'set';
                    $values[$id] = str_replace(array("\r", "\n", "\0"), ' ', $raw);
                } elseif (!empty($this->existingSecrets[$id])) {
                    $this->secretActions[$id] = 'keep';
                    $values[$id] = '';
                } else {
                    $this->secretActions[$id] = 'set';
                    $values[$id] = '';
                }
                continue;
            }
            $raw = array_key_exists($key, $post) && is_scalar($post[$key])
                ? (string)$post[$key]
                : (string)($this->values[$id] ?? '');
            $raw = str_replace(array("\r", "\n", "\0"), ' ', $raw);
            $values[$id] = trim($raw);
        }

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
     */
    private function fieldIncluded(array $field, array $values): bool
    {
        if (($field['condition'] ?? '') === 'needs_admin') {
            return false;
        }
        if (!empty($field['depends']) && is_array($field['depends'])) {
            $depends = $field['depends'];
            if (isset($depends['field'], $depends['value'])) {
                $dependsField = SettingsSchema::field((string)$depends['field']);
                if ($dependsField !== null && !$this->fieldIncluded($dependsField, $values)) {
                    return false;
                }
                return (string)($values[(string)$depends['field']] ?? '0') === (string)$depends['value'];
            }
        }
        return true;
    }

    /**
     * @param array<string,mixed> $field
     * @param array<string,string> $values
     */
    private function validateField(string $id, array $field, array $values): string
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

        if ($id === 'WEBSOCKETS_PUBLIC_URL') {
            $url = parse_url($value);
            if (!is_array($url) || !in_array($url['scheme'] ?? '', array('ws', 'wss'), true) || empty($url['host']) || isset($url['user']) || isset($url['pass']) || isset($url['fragment']) || isset($url['query'])) {
                return 'Укажите ws:// или wss:// адрес без пароля, токена и параметров запроса.';
            }
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

        if (in_array($id, array('DB_HOST', 'DB_NAME', 'WEBSOCKETS_HOST', 'REDIS_HOST'), true) && preg_match('/\s/', $value)) {
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
     * @return array{host:string,port:string,name:string,user:string,password:string}
     */
    private function databaseConfig(array $values): array
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
        return array(
            'host' => (string)($values['DB_HOST'] ?? ''),
            'port' => (string)($values['DB_PORT'] ?? ''),
            'name' => (string)($values['DB_NAME'] ?? ''),
            'user' => (string)($values['DB_USER'] ?? ''),
            'password' => $password,
        );
    }

    /**
     * @param array{host:string,port:string,name:string,user:string,password:string} $config
     * @return array<string,mixed>
     */
    private function checkDatabase(array $config): array
    {
        $database = new InstallDatabase($config);
        if (!$database->connect()) {
            return array('ok' => false, 'message' => $database->error(), 'admin_exists' => false, 'tables' => array());
        }
        $tables = $database->tables();
        $missing = array();
        foreach (array('settings', 'users') as $required) {
            if (!$database->tableExists($required)) {
                $missing[] = $required;
            }
        }
        $adminExists = $database->adminExists();
        $database->close();
        if ($missing !== array()) {
            return array(
                'ok' => false,
                'message' => 'В выбранной базе нет обязательных таблиц: ' . implode(', ', $missing) . '. Импортируйте схему MajorDoMo через /install.php.',
                'admin_exists' => false,
                'tables' => $tables,
            );
        }
        return array(
            'ok' => true,
            'message' => 'Подключение установлено.',
            'admin_exists' => $adminExists,
            'tables' => $tables,
        );
    }

    /**
     * @param array{host:string,port:string,name:string,user:string,password:string} $config
     */
    private function isDatabaseChanged(array $config): bool
    {
        $currentPort = Config::string('DB_PORT');
        $targetPort = trim((string)$config['port']);
        return Config::string('DB_HOST') !== trim((string)$config['host'])
            || Config::string('DB_NAME') !== trim((string)$config['name'])
            || Config::string('DB_USER') !== trim((string)$config['user'])
            || ((int)($currentPort !== '' ? $currentPort : 3306)) !== ((int)($targetPort !== '' ? $targetPort : 3306))
            || Config::string('DB_PASSWORD') !== (string)$config['password'];
    }

    /**
     * @param array<string,string> $values
     * @return array<string,string|null>
     */
    private function envValues(array $values): array
    {
        $map = array();
        foreach (SettingsSchema::fields() as $id => $field) {
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
        $map['DISABLE_WEBSOCKETS'] = ($values['websockets_enabled'] ?? '0') === '1' ? '0' : '1';

        if (($values['redis_enabled'] ?? '0') !== '1') {
            foreach (array('REDIS_PORT', 'REDIS_DB', 'REDIS_USERNAME', 'REDIS_PASSWORD', 'REDIS_TIMEOUT', 'REDIS_PREFIX') as $key) {
                unset($map[$key]);
            }
        }
        if (($values['websockets_enabled'] ?? '0') !== '1') {
            foreach (array('WEBSOCKETS_HOST', 'WEBSOCKETS_PORT') as $key) {
                unset($map[$key]);
            }
        }
        if (($values['WEBSOCKETS_TLS'] ?? '0') !== '1') {
            foreach (array('WEBSOCKETS_TLS_PEM_FILE', 'WEBSOCKETS_TLS_PEM_PASSPHRASE', 'WEBSOCKETS_TLS_ALLOW_SELF_SIGNED', 'WEBSOCKETS_TLS_VERIFY_PEER', 'WEBSOCKETS_TLS_VERIFY_PEER_NAME') as $key) {
                unset($map[$key]);
            }
        }
        return $map;
    }

    /**
     * @param array<string,string> $values
     * @return array<string,string>
     */
    private function dbValues(array $values): array
    {
        $map = array();
        foreach (SettingsSchema::fields() as $id => $field) {
            if (($field['target'] ?? '') !== InstallSchema::TARGET_DB) {
                continue;
            }
            if (!$this->fieldIncluded($field, $values)) {
                continue;
            }
            if (!empty($field['secret']) && ($this->secretActions[$id] ?? '') === 'keep') {
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
    private function dbMeta(): array
    {
        $meta = array();
        foreach (SettingsSchema::fields() as $id => $field) {
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
