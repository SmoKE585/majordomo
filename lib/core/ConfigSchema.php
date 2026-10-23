<?php

namespace MajorDoMo\Core;

/**
 * Declarative description of runtime configuration.
 *
 * Every key is read from `.env` (or a real process environment variable with the
 * same name) unless `computed` is set. The schema is also the source of truth for
 * value types: `.env` values are always strings and must be cast before use.
 *
 * Flags:
 *   type      string|integer|decimal|boolean|host_or_false|list|json
 *   default   value used when the key is absent and `optional` is false
 *   optional  key is absent unless it is present in .env / already defined
 *   computed  value is derived by Config, not read from .env
 *   env       false to ignore `.env` for structural keys (ROOT, DOC_ROOT)
 *   constant  expose a legacy PHP constant with the same name
 *   secret    never write the value to logs or audit output
 *   required  doctor warns when the key is missing
 */
final class ConfigSchema
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        return array(
            // Database -------------------------------------------------------
            'DB_HOST' => array('type' => 'string', 'default' => 'localhost', 'required' => true),
            'DB_PORT' => array('type' => 'string', 'default' => ''),
            'DB_NAME' => array('type' => 'string', 'default' => '', 'required' => true),
            'DB_USER' => array('type' => 'string', 'default' => ''),
            'DB_PASSWORD' => array('type' => 'string', 'default' => '', 'secret' => true),

            // Paths ----------------------------------------------------------
            'DOC_ROOT' => array('type' => 'string', 'computed' => true, 'env' => false),
            'ROOT' => array('type' => 'string', 'computed' => true, 'env' => false),
            'SERVER_ROOT' => array('type' => 'string', 'computed' => true),
            'DIR_TEMPLATES' => array('type' => 'string', 'default' => './templates/'),
            'DIR_MODULES' => array('type' => 'string', 'default' => './modules/'),
            'ROOTHTML' => array('type' => 'string', 'default' => '/'),
            'BASE_URL' => array('type' => 'string', 'computed' => true),
            // Каталог сессий. Пусто — <root>/var/sessions. Фактический путь
            // (в том числе зафиксированный php_admin_value пула php-fpm) ядро
            // определяет само; ключ нужен только для принудительного
            // переопределения, например на общий каталог вне проекта.
            'SESSION_SAVE_PATH' => array('type' => 'string', 'optional' => true, 'constant' => false),

            // Executables ----------------------------------------------------
            'PATH_TO_PHP' => array('type' => 'string', 'optional' => true),
            'PATH_TO_MYSQLDUMP' => array('type' => 'string', 'default' => 'mysqldump'),
            'PATH_TO_FFMPEG' => array('type' => 'string', 'optional' => true),
            'PYTHON_PATH' => array('type' => 'string', 'optional' => true),
            'AUDIO_PLAYER' => array('type' => 'string', 'optional' => true),

            // Project --------------------------------------------------------
            'PROJECT_TITLE' => array('type' => 'string', 'default' => 'MajorDoMo'),
            'DEBUG_MODE' => array('type' => 'boolean', 'optional' => true),
            'APP_ENV' => array('type' => 'string', 'optional' => true),
            'TIMEZONE' => array('type' => 'string', 'optional' => true, 'constant' => false),
            'UPDATES_REPOSITORY_NAME' => array('type' => 'string', 'optional' => true),

            // Updates --------------------------------------------------------
            'GIT_URL' => array('type' => 'string', 'optional' => true),
            'MASTER_UPDATE_URL' => array('type' => 'string', 'computed' => true, 'optional' => true),
            'PROJECT_URL' => array('type' => 'string', 'computed' => true, 'optional' => true),
            'PROJECT_COMMIT_URL' => array('type' => 'string', 'computed' => true, 'optional' => true),
            'ADITIONAL_GIT_URLS' => array('type' => 'json', 'default' => array(), 'constant' => false),

            // Deployment -----------------------------------------------------
            'DEPLOY_COMPOSER' => array('type' => 'string', 'default' => 'composer', 'constant' => false),
            'DEPLOY_NODE' => array('type' => 'string', 'default' => 'node', 'constant' => false),
            'DEPLOY_NPM' => array('type' => 'string', 'default' => 'npm', 'constant' => false),
            // always — собирать при каждом обновлении; auto — только при
            // изменённом lock/отсутствии node_modules; never — не собирать.
            'DEPLOY_ASSETS' => array('type' => 'string', 'default' => 'always', 'constant' => false),
            'DEPLOY_SERVICE' => array('type' => 'string', 'optional' => true, 'constant' => false),
            'DEPLOY_RESTART' => array('type' => 'boolean', 'default' => true, 'constant' => false),
            'DEPLOY_TIMEOUT' => array('type' => 'integer', 'default' => 900, 'constant' => false),

            // Background cycles ---------------------------------------------
            'RESTART_THREADS' => array('type' => 'list', 'default' => array(), 'constant' => false),
            'CORE_WORKERS' => array('type' => 'integer', 'default' => 3, 'constant' => false),
            'CYCLE_HEARTBEAT_TIMEOUT' => array('type' => 'integer', 'default' => 120, 'constant' => false),
            'CYCLE_STARTUP_TIMEOUT' => array('type' => 'integer', 'default' => 180, 'constant' => false),
            'CYCLE_TIMEOUTS' => array('type' => 'json', 'default' => array(), 'constant' => false),
            'WAIT_FOR_MAIN_CYCLE' => array('type' => 'boolean', 'optional' => true),
            'LOWER_BACKGROUND_PROCESSES' => array('type' => 'boolean', 'optional' => true),
            'ENABLE_PANEL_ACCELERATION' => array('type' => 'boolean', 'optional' => true),
            'PROPERTY_DELIVERY_STRICT' => array('type' => 'boolean', 'default' => false, 'constant' => false),
            // Always-on property audit journal. 0 keeps entries indefinitely;
            // a positive value prunes old runs during startup maintenance.
            'PROPERTY_AUDIT_RETENTION_DAYS' => array('type' => 'integer', 'default' => 0, 'constant' => false),

            // Logging --------------------------------------------------------
            'LOG_DIRECTORY' => array('type' => 'string', 'optional' => true),
            'LOG_MAX_SIZE' => array('type' => 'integer', 'optional' => true),
            'LOG_CYCLES' => array('type' => 'boolean', 'optional' => true),
            'LOG_PHP_WARNINGS' => array('type' => 'boolean', 'optional' => true),
            'VERBOSE_LOG' => array('type' => 'boolean', 'optional' => true),
            'VERBOSE_LOG_IGNORE' => array('type' => 'string', 'optional' => true),
            'SETTINGS_ERRORS_KEEP_HISTORY' => array('type' => 'boolean', 'optional' => true),

            // Cache, history, performance -----------------------------------
            'CACHE_VALUE_MAX_SIZE' => array('type' => 'integer', 'optional' => true),
            'HISTORY_NO_OPTIMIZE' => array('type' => 'boolean', 'optional' => true),
            'SEPARATE_HISTORY_STORAGE' => array('type' => 'boolean', 'optional' => true),
            'TRACK_DATA_CHANGES' => array('type' => 'boolean', 'optional' => true),
            'TRACK_DATA_CHANGES_IGNORE' => array('type' => 'string', 'optional' => true),
            'LOG_FILES_EXPIRE' => array('type' => 'integer', 'optional' => true),
            'BACKUP_FILES_EXPIRE' => array('type' => 'integer', 'optional' => true),
            'CACHED_FILES_EXPIRE' => array('type' => 'integer', 'optional' => true),

            // Redis ----------------------------------------------------------
            'USE_REDIS' => array('type' => 'host_or_false', 'optional' => true),
            'REDIS_PORT' => array('type' => 'integer', 'optional' => true),
            'REDIS_DB' => array('type' => 'integer', 'optional' => true),
            'REDIS_USERNAME' => array('type' => 'string', 'optional' => true),
            'REDIS_PASSWORD' => array('type' => 'string', 'optional' => true, 'secret' => true),
            'REDIS_TIMEOUT' => array('type' => 'decimal', 'optional' => true),
            'REDIS_PREFIX' => array('type' => 'string', 'optional' => true),

            // Network and proxy ---------------------------------------------
            'LOCAL_IP' => array('type' => 'string', 'optional' => true),
            'HOME_NETWORK' => array('type' => 'string', 'optional' => true),
            'USE_PROXY' => array('type' => 'string', 'optional' => true),
            'USE_PROXY_AUTH' => array('type' => 'string', 'optional' => true, 'secret' => true),
            'TRUSTED_PROXIES' => array('type' => 'string', 'optional' => true),

            // MQTT receive limits -------------------------------------------
            'MQTT_MAX_PACKET_BYTES' => array('type' => 'integer', 'default' => 1048576, 'constant' => false),
            'MQTT_PACKET_TIMEOUT' => array('type' => 'decimal', 'default' => 5.0, 'constant' => false),
            // Быстрый путь MQTT включается только при USE_REDIS и phpredis.
            // Ложное значение оставляет прежний синхронный путь.
            'MQTT_ASYNC_ENABLED' => array('type' => 'boolean', 'default' => true, 'constant' => false),
            'MQTT_HANDLERS' => array('type' => 'integer', 'default' => 3, 'constant' => false),
            'MQTT_HANDLER_LEASE' => array('type' => 'integer', 'default' => 120, 'constant' => false),
            'MQTT_MAX_ATTEMPTS' => array('type' => 'integer', 'default' => 5, 'constant' => false),

        );
    }

    /**
     * Keys that used to exist but are not read anywhere in the project.
     * `tools/config-audit.php` reports them, `tools/config.php migrate` drops them.
     *
     * @return array<string, string> key => reason
     */
    public static function removed(): array
    {
        return array(
            'PROJECT_BUGTRACK' => 'referenced only in a commented-out sendmail() call',
            'PROJECT_DOMAIN' => 'referenced only in a commented-out sendmail() call',
            'GETURL_WARNING_TIMEOUT' => 'never read',
            'BTRACED' => 'historical option, never read',
            'ENABLE_FORK' => 'historical option, never read',
            'DROPBOX_SHOPPING_LIST' => 'feature removed',
            'ONEWIRE_SERVER' => 'OWFS integration removed',
            'DISABLE_USERS_LIST' => 'user list on the login form removed; normal authentication will replace it',
        );
    }
}
