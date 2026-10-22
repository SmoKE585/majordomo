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

            // Executables ----------------------------------------------------
            'PATH_TO_PHP' => array('type' => 'string', 'computed' => true),
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

            // Background cycles ---------------------------------------------
            'RESTART_THREADS' => array('type' => 'list', 'default' => array(), 'constant' => false),
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

            // WebSockets -----------------------------------------------------
            'DISABLE_WEBSOCKETS' => array('type' => 'boolean', 'optional' => true),
            'WEBSOCKETS_HOST' => array('type' => 'string', 'default' => '0.0.0.0'),
            'WEBSOCKETS_PORT' => array('type' => 'integer', 'default' => 8001),
            'WEBSOCKETS_PUBLIC_URL' => array('type' => 'string', 'default' => '', 'constant' => false),
            'WEBSOCKETS_TLS' => array('type' => 'boolean', 'default' => false),
            'WEBSOCKETS_MAX_CLIENTS' => array('type' => 'integer', 'default' => 100),
            'WEBSOCKETS_MAX_CONNECTIONS_PER_IP' => array('type' => 'integer', 'default' => 100),
            'WEBSOCKETS_MAX_REQUESTS_PER_MINUTE' => array('type' => 'integer', 'default' => 20000),
            'WEBSOCKETS_CLIENT_TIMEOUT' => array('type' => 'integer', 'default' => 0),
            'WEBSOCKETS_WRITE_TIMEOUT' => array('type' => 'decimal', 'default' => 0.25),
            'WEBSOCKETS_CLIENT_WRITE_TIMEOUT' => array('type' => 'decimal', 'default' => 0.5),
            'WEBSOCKETS_MAX_FRAME_BYTES' => array('type' => 'integer', 'default' => 1048576),
            'WEBSOCKETS_CACHE_MAX_ENTRIES' => array('type' => 'integer', 'default' => 4096, 'constant' => false),
            'WEBSOCKETS_CACHE_MAX_BYTES' => array('type' => 'integer', 'default' => 8388608, 'constant' => false),
            'WEBSOCKETS_CACHE_TTL' => array('type' => 'integer', 'default' => 300, 'constant' => false),
            'WEBSOCKETS_MAX_SUBSCRIPTIONS' => array('type' => 'integer', 'default' => 4096, 'constant' => false),
            'WEBSOCKETS_CHECK_ORIGIN' => array('type' => 'boolean', 'default' => false),
            'WEBSOCKETS_ALLOWED_ORIGINS' => array('type' => 'string', 'default' => ''),
            'WEBSOCKETS_AUTH_TOKEN' => array('type' => 'string', 'default' => '', 'secret' => true),
            'WEBSOCKETS_TLS_PEM_FILE' => array('type' => 'string', 'default' => './server.pem'),
            'WEBSOCKETS_TLS_PEM_PASSPHRASE' => array('type' => 'string', 'default' => 'shinywss', 'secret' => true),
            'WEBSOCKETS_TLS_ALLOW_SELF_SIGNED' => array('type' => 'boolean', 'default' => true),
            'WEBSOCKETS_TLS_VERIFY_PEER' => array('type' => 'boolean', 'default' => false),
            'WEBSOCKETS_TLS_VERIFY_PEER_NAME' => array('type' => 'boolean', 'default' => false),
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
