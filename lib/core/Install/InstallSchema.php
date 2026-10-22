<?php

namespace MajorDoMo\Core\Install;

/**
 * Declarative schema of the first-run wizard.
 *
 * One field definition drives:
 *  - server-side validation and normalisation;
 *  - rendering (MDJUI components);
 *  - writing either `.env` or the `settings` table;
 *  - the review screen and the CLI report.
 *
 * Targets:
 *  - env  — written to `.env` (the only place runtime configuration lives);
 *  - db    — written to the `settings` table (`SETTINGS_*` constants);
 *  - meta  — UI-only switch that maps to one or more env keys (Redis/WS modes).
 */
final class InstallSchema
{
    public const STEP_CHECK = 0;
    public const STEP_DATABASE = 1;
    public const STEP_SYSTEM = 2;
    public const STEP_ADVANCED = 3;
    public const STEP_REVIEW = 4;

    public const TARGET_ENV = 'env';
    public const TARGET_DB = 'db';
    public const TARGET_META = 'meta';

    /**
     * @return array<int,array<string,string>>
     */
    public static function steps(): array
    {
        return array(
            self::STEP_CHECK => array(
                'id' => 'check',
                'number' => '01',
                'title' => 'Проверка',
                'short' => 'Проверка',
                'description' => 'PHP, расширения и права на файлы',
                'icon' => 'shield-check',
            ),
            self::STEP_DATABASE => array(
                'id' => 'database',
                'number' => '02',
                'title' => 'База данных',
                'short' => 'База данных',
                'description' => 'Подключение к MySQL или MariaDB',
                'icon' => 'database',
            ),
            self::STEP_SYSTEM => array(
                'id' => 'system',
                'number' => '03',
                'title' => 'Система',
                'short' => 'Система',
                'description' => 'Название, адрес, часовой пояс и тема',
                'icon' => 'settings',
            ),
            self::STEP_ADVANCED => array(
                'id' => 'advanced',
                'number' => '04',
                'title' => 'Дополнительно',
                'short' => 'Дополнительно',
                'description' => 'Redis, WebSockets, журналы, доступ',
                'icon' => 'adjustments',
            ),
            self::STEP_REVIEW => array(
                'id' => 'review',
                'number' => '05',
                'title' => 'Готово',
                'short' => 'Готово',
                'description' => 'Проверьте значения и завершите',
                'icon' => 'circle-check',
            ),
        );
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public static function sections(): array
    {
        return array(
            'database' => array(
                'step' => self::STEP_DATABASE,
                'title' => 'Подключение к базе данных',
                'description' => 'Эти данные попадут только в .env. Мастер проверит соединение перед сохранением.',
                'icon' => 'database',
            ),
            'identity' => array(
                'step' => self::STEP_SYSTEM,
                'title' => 'Название и адрес',
                'description' => 'Как система называется, по какому адресу открывается и в каком часовом поясе живёт.',
                'icon' => 'home',
            ),
            'behavior' => array(
                'step' => self::STEP_SYSTEM,
                'title' => 'Интерфейс и поведение',
                'description' => 'Язык, тема, озвучивание и служебные переключатели.',
                'icon' => 'palette',
            ),
            'admin' => array(
                'step' => self::STEP_SYSTEM,
                'title' => 'Администратор',
                'description' => 'Создайте администратора с паролем — без него в панель не войти.',
                'icon' => 'user-shield',
            ),
            'performance' => array(
                'step' => self::STEP_ADVANCED,
                'title' => 'Производительность и фоновые циклы',
                'description' => 'Кэш, история и то, как MajorDoMo запускает фоновые процессы.',
                'icon' => 'bolt',
            ),
            'logging' => array(
                'step' => self::STEP_ADVANCED,
                'title' => 'Журналы и хранение',
                'description' => 'Что писать в логи и как долго хранить файлы и историю.',
                'icon' => 'file-text',
            ),
            'redis' => array(
                'step' => self::STEP_ADVANCED,
                'title' => 'Redis',
                'description' => 'Быстрый кэш и очередь. Необязательно: без Redis система работает на SQL-кэше.',
                'icon' => 'stack-2',
            ),
            'websockets' => array(
                'step' => self::STEP_ADVANCED,
                'title' => 'WebSockets',
                'description' => 'Живые обновления интерфейса без перезагрузки страниц.',
                'icon' => 'broadcast',
            ),
            'security' => array(
                'step' => self::STEP_ADVANCED,
                'title' => 'Сеть и безопасность',
                'description' => 'Домашняя сеть, локальный IP и доверенные прокси. Авторизацию выполняет reverse proxy.',
                'icon' => 'shield-lock',
            ),
            'integrations' => array(
                'step' => self::STEP_ADVANCED,
                'title' => 'Интеграции и пути',
                'description' => 'Внешние программы: mysqldump, ffmpeg, Python и аудиоплеер.',
                'icon' => 'plug',
            ),
        );
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public static function fields(): array
    {
        static $fields = null;
        if (is_array($fields)) {
            return $fields;
        }

        $fields = array(
            // ----------------------------------------------------------------
            // Database
            // ----------------------------------------------------------------
            'DB_HOST' => array(
                'target' => self::TARGET_ENV,
                'step' => self::STEP_DATABASE,
                'section' => 'database',
                'type' => 'text',
                'label' => 'Сервер базы данных',
                'description' => 'Адрес MySQL/MariaDB. Для локальной установки — localhost или 127.0.0.1; в Docker — имя сервиса, например db.',
                'placeholder' => 'localhost',
                'default' => 'localhost',
                'required' => true,
                'width' => 'half',
                'icon' => 'server',
                'autocomplete' => 'off',
            ),
            'DB_PORT' => array(
                'target' => self::TARGET_ENV,
                'step' => self::STEP_DATABASE,
                'section' => 'database',
                'type' => 'number',
                'label' => 'Порт',
                'description' => 'Оставьте пустым для стандартного порта 3306.',
                'placeholder' => '3306',
                'default' => '',
                'width' => 'half',
                'min' => 1,
                'max' => 65535,
            ),
            'DB_NAME' => array(
                'target' => self::TARGET_ENV,
                'step' => self::STEP_DATABASE,
                'section' => 'database',
                'type' => 'text',
                'label' => 'Имя базы',
                'description' => 'База должна быть создана заранее. Мастер не создаёт и не удаляет базы, чтобы не затронуть чужие данные.',
                'placeholder' => 'majordomo',
                'required' => true,
                'width' => 'half',
                'icon' => 'database',
                'autocomplete' => 'off',
            ),
            'DB_USER' => array(
                'target' => self::TARGET_ENV,
                'step' => self::STEP_DATABASE,
                'section' => 'database',
                'type' => 'text',
                'label' => 'Пользователь',
                'description' => 'Пользователь MySQL с правами на выбранную базу.',
                'placeholder' => 'majordomo',
                'required' => true,
                'width' => 'half',
                'icon' => 'user',
                'autocomplete' => 'off',
            ),
            'DB_PASSWORD' => array(
                'target' => self::TARGET_ENV,
                'step' => self::STEP_DATABASE,
                'section' => 'database',
                'type' => 'password',
                'label' => 'Пароль',
                'description' => 'Хранится только в .env и никогда не выводится в интерфейс, логи или отчёты.',
                'placeholder' => 'Пароль пользователя базы',
                'default' => '',
                'secret' => true,
                'width' => 'full',
                'autocomplete' => 'new-password',
                'password_toggle' => true,
            ),
            'create_schema' => array(
                'target' => self::TARGET_META,
                'step' => self::STEP_DATABASE,
                'section' => 'database',
                'type' => 'switch',
                'label' => 'Создать таблицы автоматически',
                'description' => 'Если база пустая, мастер импортирует чистую схему MajorDoMo: только структура, без демо-данных и без пользователей. На базе с таблицами импорт не выполняется.',
                'default' => '1',
                'width' => 'full',
            ),

            // ----------------------------------------------------------------
            // Identity
            // ----------------------------------------------------------------
            'PROJECT_TITLE' => array(
                'target' => self::TARGET_ENV,
                'step' => self::STEP_SYSTEM,
                'section' => 'identity',
                'type' => 'text',
                'label' => 'Название проекта',
                'description' => 'Заголовок вкладки браузера, письма и служебные подписи. Для дома подойдёт «Мой дом».',
                'placeholder' => 'Мой дом',
                'default' => 'MajorDoMo',
                'required' => true,
                'maxlength' => 120,
                'width' => 'half',
                'icon' => 'home',
            ),
            'GENERAL_ALICE_NAME' => array(
                'target' => self::TARGET_DB,
                'step' => self::STEP_SYSTEM,
                'section' => 'identity',
                'type' => 'text',
                'label' => 'Имя системы',
                'description' => 'Как система представляется в интерфейсе, API и голосовых ответах.',
                'placeholder' => 'Мой дом',
                'default' => '',
                'required' => true,
                'maxlength' => 120,
                'width' => 'half',
                'icon' => 'sparkles',
            ),
            'BASE_URL' => array(
                'target' => self::TARGET_ENV,
                'step' => self::STEP_SYSTEM,
                'section' => 'identity',
                'type' => 'url',
                'label' => 'Публичный адрес',
                'description' => 'Полный адрес, по которому система открывается у вас и в домашней сети. Нужен для ссылок, API и OAuth.',
                'placeholder' => 'http://192.168.0.10',
                'default' => '',
                'required' => true,
                'width' => 'full',
                'icon' => 'world',
            ),
            'ROOTHTML' => array(
                'target' => self::TARGET_ENV,
                'step' => self::STEP_SYSTEM,
                'section' => 'identity',
                'type' => 'text',
                'label' => 'Путь приложения',
                'description' => 'Обычно «/». Если MajorDoMo установлен в подкаталог, укажите его: /majordomo/.',
                'placeholder' => '/',
                'default' => '/',
                'required' => true,
                'width' => 'half',
                'icon' => 'route',
            ),
            'APP_ENV' => array(
                'target' => self::TARGET_ENV,
                'step' => self::STEP_SYSTEM,
                'section' => 'identity',
                'type' => 'select',
                'label' => 'Окружение',
                'description' => 'Для домашней установки оставьте production. Development включает подробные ошибки.',
                'default' => 'production',
                'required' => true,
                'width' => 'half',
                'options' => array(
                    'production' => 'production — обычная работа',
                    'development' => 'development — разработка',
                    'testing' => 'testing — тесты',
                ),
            ),
            'TIMEZONE' => array(
                'target' => self::TARGET_ENV,
                'step' => self::STEP_SYSTEM,
                'section' => 'identity',
                'type' => 'timezone',
                'label' => 'Часовой пояс сервера',
                'description' => 'Нужен для CLI, ранних ошибок и фоновых процессов. Веб-интерфейс использует отдельный часовой пояс ниже.',
                'default' => 'Europe/Moscow',
                'required' => true,
                'width' => 'half',
                'icon' => 'clock',
            ),
            'SITE_TIMEZONE' => array(
                'target' => self::TARGET_DB,
                'step' => self::STEP_SYSTEM,
                'section' => 'identity',
                'type' => 'timezone',
                'label' => 'Часовой пояс интерфейса',
                'description' => 'По нему считаются расписания, журналы и время на страницах.',
                'default' => 'Europe/Moscow',
                'required' => true,
                'width' => 'half',
                'icon' => 'clock',
            ),

            // ----------------------------------------------------------------
            // Behavior
            // ----------------------------------------------------------------
            'SITE_LANGUAGE' => array(
                'target' => self::TARGET_DB,
                'step' => self::STEP_SYSTEM,
                'section' => 'behavior',
                'type' => 'select',
                'label' => 'Язык интерфейса',
                'description' => 'Язык административной панели и системных сообщений.',
                'default' => 'ru',
                'required' => true,
                'width' => 'half',
                'options' => self::languageOptions(),
            ),
            'THEME' => array(
                'target' => self::TARGET_DB,
                'step' => self::STEP_SYSTEM,
                'section' => 'behavior',
                'type' => 'choice',
                'label' => 'Тема оформления',
                'description' => 'Можно переключить позже в настройках или кнопкой в шапке.',
                'default' => 'dark',
                'required' => true,
                'width' => 'full',
                'options' => array(
                    array('value' => 'dark', 'label' => 'Тёмная', 'description' => 'Мягкий фон для вечера и круглосуточной панели.', 'icon' => 'moon'),
                    array('value' => 'light', 'label' => 'Светлая', 'description' => 'Классическая светлая панель для дневной работы.', 'icon' => 'sun-high'),
                ),
            ),
            'VOICE_LANGUAGE' => array(
                'target' => self::TARGET_DB,
                'step' => self::STEP_SYSTEM,
                'section' => 'behavior',
                'type' => 'select',
                'label' => 'Язык озвучивания',
                'description' => 'Язык голосовых уведомлений и ответов.',
                'default' => 'ru',
                'width' => 'half',
                'options' => self::languageOptions(),
            ),
            'SPEAK_SIGNAL' => array(
                'target' => self::TARGET_DB,
                'step' => self::STEP_SYSTEM,
                'section' => 'behavior',
                'type' => 'switch',
                'label' => 'Сигнал перед фразой',
                'description' => 'Короткий звук перед озвучиванием, чтобы сообщение было заметнее.',
                'default' => '1',
                'width' => 'half',
            ),
            'DEBUG_MODE' => array(
                'target' => self::TARGET_ENV,
                'step' => self::STEP_SYSTEM,
                'section' => 'behavior',
                'type' => 'switch',
                'label' => 'Режим отладки',
                'description' => 'Показывает отладочные страницы и HTML-комментарии шаблонов. Для обычной работы выключено.',
                'default' => '0',
                'width' => 'half',
            ),
            'GIT_URL' => array(
                'target' => self::TARGET_ENV,
                'step' => self::STEP_SYSTEM,
                'section' => 'behavior',
                'type' => 'url',
                'label' => 'Репозиторий обновлений',
                'description' => 'Откуда получать обновления через Git. Пустое значение отключает обновления из панели.',
                'placeholder' => 'https://github.com/SmoKE585/majordomo',
                'default' => 'https://github.com/SmoKE585/majordomo',
                'width' => 'full',
                'icon' => 'cloud-download',
            ),
            'BACKUP_PATH' => array(
                'target' => self::TARGET_DB,
                'step' => self::STEP_SYSTEM,
                'section' => 'behavior',
                'type' => 'path',
                'label' => 'Папка резервных копий',
                'description' => 'Куда складывать бэкапы. Пусто — стандартный каталог cms/saverestore.',
                'placeholder' => '/var/backups/majordomo',
                'default' => '',
                'width' => 'full',
                'icon' => 'archive',
            ),

            // ----------------------------------------------------------------
            // Admin (only when the database has no administrator)
            // ----------------------------------------------------------------
            'admin_login' => array(
                'target' => self::TARGET_META,
                'step' => self::STEP_SYSTEM,
                'section' => 'admin',
                'type' => 'text',
                'label' => 'Логин',
                'description' => 'Латиница, цифры, точка, дефис или подчёркивание. Минимум 3 символа.',
                'placeholder' => 'admin',
                'default' => 'admin',
                'required' => true,
                'width' => 'half',
                'icon' => 'user',
                'autocomplete' => 'username',
                'condition' => 'needs_admin',
            ),
            'admin_name' => array(
                'target' => self::TARGET_META,
                'step' => self::STEP_SYSTEM,
                'section' => 'admin',
                'type' => 'text',
                'label' => 'Имя администратора',
                'description' => 'Отображается в списке пользователей и в интерфейсе.',
                'placeholder' => 'Администратор',
                'default' => 'Администратор',
                'required' => true,
                'width' => 'half',
                'icon' => 'id-badge',
                'condition' => 'needs_admin',
            ),
            'admin_password' => array(
                'target' => self::TARGET_META,
                'step' => self::STEP_SYSTEM,
                'section' => 'admin',
                'type' => 'password',
                'label' => 'Пароль',
                'description' => 'Минимум 8 символов. Пароль сохраняется в виде хеша, восстановить его нельзя.',
                'placeholder' => 'Придумайте пароль',
                'default' => '',
                'secret' => true,
                'required' => true,
                'width' => 'half',
                'autocomplete' => 'new-password',
                'password_toggle' => true,
                'condition' => 'needs_admin',
            ),
            'admin_password_confirm' => array(
                'target' => self::TARGET_META,
                'step' => self::STEP_SYSTEM,
                'section' => 'admin',
                'type' => 'password',
                'label' => 'Повторите пароль',
                'description' => 'Нужно, чтобы исключить опечатку.',
                'placeholder' => 'Ещё раз',
                'default' => '',
                'secret' => true,
                'required' => true,
                'width' => 'half',
                'autocomplete' => 'new-password',
                'password_toggle' => true,
                'condition' => 'needs_admin',
            ),

            // ----------------------------------------------------------------
            // Performance
            // ----------------------------------------------------------------
            'CACHE_VALUE_MAX_SIZE' => array(
                'target' => self::TARGET_ENV,
                'step' => self::STEP_ADVANCED,
                'section' => 'performance',
                'type' => 'number',
                'label' => 'Максимальный размер значения в кэше',
                'description' => 'Символов. Значения длиннее не кэшируются.',
                'placeholder' => '255',
                'default' => '',
                'min' => 0,
                'width' => 'half',
            ),
            'SEPARATE_HISTORY_STORAGE' => array(
                'target' => self::TARGET_ENV,
                'step' => self::STEP_ADVANCED,
                'section' => 'performance',
                'type' => 'switch',
                'label' => 'Отдельные таблицы истории',
                'description' => 'Хранить историю в отдельных таблицах. Рекомендуется для новых установок.',
                'default' => '0',
                'width' => 'half',
            ),
            'HISTORY_NO_OPTIMIZE' => array(
                'target' => self::TARGET_ENV,
                'step' => self::STEP_ADVANCED,
                'section' => 'performance',
                'type' => 'switch',
                'label' => 'Отключить оптимизацию истории',
                'description' => 'Полезно при проблемах с записью истории. Обычно выключено.',
                'default' => '0',
                'width' => 'half',
            ),
            'TRACK_DATA_CHANGES' => array(
                'target' => self::TARGET_ENV,
                'step' => self::STEP_ADVANCED,
                'section' => 'performance',
                'type' => 'switch',
                'label' => 'Журналировать изменения объектов',
                'description' => 'Пишет изменения значений в журнал. Увеличивает объём истории.',
                'default' => '0',
                'width' => 'half',
            ),
            'TRACK_DATA_CHANGES_IGNORE' => array(
                'target' => self::TARGET_ENV,
                'step' => self::STEP_ADVANCED,
                'section' => 'performance',
                'type' => 'list',
                'label' => 'Исключения журнала',
                'description' => 'Имена объектов или свойств через запятую, которые не нужно журналировать.',
                'placeholder' => 'cycle_, clockchime, uptime, WSClientsTotal',
                'default' => '',
                'width' => 'full',
                'depends' => array('field' => 'TRACK_DATA_CHANGES', 'value' => '1'),
            ),
            'ENABLE_PANEL_ACCELERATION' => array(
                'target' => self::TARGET_ENV,
                'step' => self::STEP_ADVANCED,
                'section' => 'performance',
                'type' => 'switch',
                'label' => 'Ускорение панели',
                'description' => 'Подставляет partLoad в ссылки, чтобы страницы открывались без полной перезагрузки.',
                'default' => '0',
                'width' => 'half',
            ),
            'LOWER_BACKGROUND_PROCESSES' => array(
                'target' => self::TARGET_ENV,
                'step' => self::STEP_ADVANCED,
                'section' => 'performance',
                'type' => 'switch',
                'label' => 'Меньше фоновых процессов',
                'description' => 'Снижает нагрузку на слабых устройствах (Raspberry Pi, старый ПК).',
                'default' => '0',
                'width' => 'half',
            ),
            'WAIT_FOR_MAIN_CYCLE' => array(
                'target' => self::TARGET_ENV,
                'step' => self::STEP_ADVANCED,
                'section' => 'performance',
                'type' => 'switch',
                'label' => 'Ждать основной цикл',
                'description' => 'Остальные циклы стартуют после запуска основного. Так надёжнее при медленном старте.',
                'default' => '0',
                'width' => 'half',
            ),
            'RESTART_THREADS' => array(
                'target' => self::TARGET_ENV,
                'step' => self::STEP_ADVANCED,
                'section' => 'performance',
                'type' => 'list',
                'label' => 'Список фоновых циклов',
                'description' => 'Через запятую. Оставьте пустым, чтобы использовать стандартный набор.',
                'placeholder' => 'cycle_main.php, cycle_states.php, cycle_websockets.php',
                'default' => '',
                'width' => 'full',
            ),

            // ----------------------------------------------------------------
            // Logging
            // ----------------------------------------------------------------
            'LOG_CYCLES' => array(
                'target' => self::TARGET_ENV,
                'step' => self::STEP_ADVANCED,
                'section' => 'logging',
                'type' => 'switch',
                'label' => 'Писать вывод циклов в файлы',
                'description' => 'stdout/stderr фоновых циклов попадут в каталог логов.',
                'default' => '0',
                'width' => 'half',
            ),
            'LOG_PHP_WARNINGS' => array(
                'target' => self::TARGET_ENV,
                'step' => self::STEP_ADVANCED,
                'section' => 'logging',
                'type' => 'switch',
                'label' => 'Логировать PHP-предупреждения',
                'description' => 'Помогает находить проблемы в старых модулях.',
                'default' => '0',
                'width' => 'half',
            ),
            'VERBOSE_LOG' => array(
                'target' => self::TARGET_ENV,
                'step' => self::STEP_ADVANCED,
                'section' => 'logging',
                'type' => 'switch',
                'label' => 'Подробный лог методов',
                'description' => 'Записывает вызовы методов. Включайте только для отладки: лог быстро растёт.',
                'default' => '0',
                'width' => 'half',
            ),
            'VERBOSE_LOG_IGNORE' => array(
                'target' => self::TARGET_ENV,
                'step' => self::STEP_ADVANCED,
                'section' => 'logging',
                'type' => 'list',
                'label' => 'Исключения подробного лога',
                'description' => 'Методы через запятую, которые не нужно логировать.',
                'placeholder' => '.checkstate, ThisComputer.uptime',
                'default' => '',
                'width' => 'full',
                'depends' => array('field' => 'VERBOSE_LOG', 'value' => '1'),
            ),
            'LOG_DIRECTORY' => array(
                'target' => self::TARGET_ENV,
                'step' => self::STEP_ADVANCED,
                'section' => 'logging',
                'type' => 'path',
                'label' => 'Каталог логов',
                'description' => 'Пусто — cms/debmes. Можно вынести логи на отдельный диск.',
                'placeholder' => '/var/log/majordomo',
                'default' => '',
                'width' => 'half',
            ),
            'LOG_MAX_SIZE' => array(
                'target' => self::TARGET_ENV,
                'step' => self::STEP_ADVANCED,
                'section' => 'logging',
                'type' => 'number',
                'label' => 'Максимальный размер лога, МБ',
                'description' => '0 — без ограничения. При достижении лимита файл перестаёт расти.',
                'placeholder' => '2',
                'default' => '',
                'min' => 0,
                'width' => 'half',
            ),
            'LOG_FILES_EXPIRE' => array(
                'target' => self::TARGET_ENV,
                'step' => self::STEP_ADVANCED,
                'section' => 'logging',
                'type' => 'number',
                'label' => 'Хранить логи, дней',
                'description' => 'Сколько дней хранить файлы журналов.',
                'placeholder' => '5',
                'default' => '',
                'min' => 0,
                'width' => 'half',
            ),
            'BACKUP_FILES_EXPIRE' => array(
                'target' => self::TARGET_ENV,
                'step' => self::STEP_ADVANCED,
                'section' => 'logging',
                'type' => 'number',
                'label' => 'Хранить резервные копии, дней',
                'description' => 'Автоматическая очистка старых бэкапов.',
                'placeholder' => '10',
                'default' => '',
                'min' => 0,
                'width' => 'half',
            ),
            'CACHED_FILES_EXPIRE' => array(
                'target' => self::TARGET_ENV,
                'step' => self::STEP_ADVANCED,
                'section' => 'logging',
                'type' => 'number',
                'label' => 'Хранить кэш, дней',
                'description' => 'Очистка временных файлов в cms/cached.',
                'placeholder' => '30',
                'default' => '',
                'min' => 0,
                'width' => 'half',
            ),
            'SYSTEM_DB_MAIN_SAVE_PERIOD' => array(
                'target' => self::TARGET_DB,
                'step' => self::STEP_ADVANCED,
                'section' => 'logging',
                'type' => 'number',
                'label' => 'Период сохранения основной базы, мин',
                'description' => '0 — не сохранять автоматически. Значение по умолчанию 15.',
                'placeholder' => '15',
                'default' => '15',
                'min' => 0,
                'width' => 'half',
            ),
            'SYSTEM_DB_HISTORY_SAVE_PERIOD' => array(
                'target' => self::TARGET_DB,
                'step' => self::STEP_ADVANCED,
                'section' => 'logging',
                'type' => 'number',
                'label' => 'Период сохранения истории, мин',
                'description' => '0 — не сохранять автоматически. Значение по умолчанию 60.',
                'placeholder' => '60',
                'default' => '60',
                'min' => 0,
                'width' => 'half',
            ),
            'ERRORS_KEEP_HISTORY' => array(
                'target' => self::TARGET_DB,
                'step' => self::STEP_ADVANCED,
                'section' => 'logging',
                'type' => 'number',
                'label' => 'Хранить ошибки системы, дней',
                'description' => '0 — не хранить историю ошибок.',
                'placeholder' => '30',
                'default' => '0',
                'min' => 0,
                'width' => 'half',
            ),
            'SYSTEM_DISABLE_DEBMES' => array(
                'target' => self::TARGET_DB,
                'step' => self::STEP_ADVANCED,
                'section' => 'logging',
                'type' => 'switch',
                'label' => 'Не писать журнал DebMes',
                'description' => 'Полностью отключает текстовые журналы MajorDoMo. Полезно на SD-картах.',
                'default' => '0',
                'width' => 'half',
            ),

            // ----------------------------------------------------------------
            // Redis
            // ----------------------------------------------------------------
            'redis_enabled' => array(
                'target' => self::TARGET_META,
                'step' => self::STEP_ADVANCED,
                'section' => 'redis',
                'type' => 'switch',
                'label' => 'Использовать Redis',
                'description' => 'Ускоряет кэш и очередь операций. Если Redis не установлен, оставьте выключенным.',
                'default' => '0',
                'width' => 'half',
            ),
            'REDIS_HOST' => array(
                'target' => self::TARGET_META,
                'step' => self::STEP_ADVANCED,
                'section' => 'redis',
                'type' => 'text',
                'label' => 'Сервер Redis',
                'description' => 'Обычно 127.0.0.1 или имя сервиса redis в Docker.',
                'placeholder' => '127.0.0.1',
                'default' => '127.0.0.1',
                'width' => 'half',
                'icon' => 'stack-2',
                'depends' => array('field' => 'redis_enabled', 'value' => '1'),
            ),
            'REDIS_PORT' => array(
                'target' => self::TARGET_ENV,
                'step' => self::STEP_ADVANCED,
                'section' => 'redis',
                'type' => 'number',
                'label' => 'Порт Redis',
                'description' => 'По умолчанию 6379.',
                'placeholder' => '6379',
                'default' => '',
                'min' => 1,
                'max' => 65535,
                'width' => 'half',
                'depends' => array('field' => 'redis_enabled', 'value' => '1'),
            ),
            'REDIS_DB' => array(
                'target' => self::TARGET_ENV,
                'step' => self::STEP_ADVANCED,
                'section' => 'redis',
                'type' => 'number',
                'label' => 'Номер базы Redis',
                'description' => '0 по умолчанию. Выберите отдельный номер, если Redis используется другими программами.',
                'placeholder' => '0',
                'default' => '',
                'min' => 0,
                'width' => 'half',
                'depends' => array('field' => 'redis_enabled', 'value' => '1'),
            ),
            'REDIS_USERNAME' => array(
                'target' => self::TARGET_ENV,
                'step' => self::STEP_ADVANCED,
                'section' => 'redis',
                'type' => 'text',
                'label' => 'Пользователь Redis',
                'description' => 'Обычно пусто. Нужен только при включённой авторизации Redis 6+.',
                'placeholder' => '',
                'default' => '',
                'width' => 'half',
                'depends' => array('field' => 'redis_enabled', 'value' => '1'),
            ),
            'REDIS_PASSWORD' => array(
                'target' => self::TARGET_ENV,
                'step' => self::STEP_ADVANCED,
                'section' => 'redis',
                'type' => 'password',
                'label' => 'Пароль Redis',
                'description' => 'Если пароль не задан, оставьте пустым.',
                'placeholder' => '',
                'default' => '',
                'secret' => true,
                'width' => 'half',
                'autocomplete' => 'new-password',
                'password_toggle' => true,
                'depends' => array('field' => 'redis_enabled', 'value' => '1'),
            ),
            'REDIS_TIMEOUT' => array(
                'target' => self::TARGET_ENV,
                'step' => self::STEP_ADVANCED,
                'section' => 'redis',
                'type' => 'number',
                'label' => 'Таймаут, сек',
                'description' => 'Сколько ждать ответа Redis. 2.5 секунды — разумное значение.',
                'placeholder' => '2.5',
                'default' => '',
                'min' => 0,
                'step_attr' => '0.1',
                'width' => 'half',
                'depends' => array('field' => 'redis_enabled', 'value' => '1'),
            ),
            'REDIS_PREFIX' => array(
                'target' => self::TARGET_ENV,
                'step' => self::STEP_ADVANCED,
                'section' => 'redis',
                'type' => 'text',
                'label' => 'Префикс ключей',
                'description' => 'Должен быть стабильным между обновлениями. Пусто — majordomo:home:.',
                'placeholder' => 'majordomo:home:',
                'default' => '',
                'width' => 'full',
                'depends' => array('field' => 'redis_enabled', 'value' => '1'),
            ),

            // ----------------------------------------------------------------
            // WebSockets
            // ----------------------------------------------------------------
            'websockets_enabled' => array(
                'target' => self::TARGET_META,
                'step' => self::STEP_ADVANCED,
                'section' => 'websockets',
                'type' => 'switch',
                'label' => 'Включить WebSockets',
                'description' => 'Живые обновления датчиков и статусов. Требует свободный порт; при проблемах можно выключить.',
                'default' => '1',
                'width' => 'half',
            ),
            'WEBSOCKETS_HOST' => array(
                'target' => self::TARGET_ENV,
                'step' => self::STEP_ADVANCED,
                'section' => 'websockets',
                'type' => 'text',
                'label' => 'Адрес прослушивания',
                'description' => '0.0.0.0 — все интерфейсы. 127.0.0.1 — только локально (тогда нужен reverse proxy).',
                'placeholder' => '0.0.0.0',
                'default' => '0.0.0.0',
                'width' => 'half',
                'depends' => array('field' => 'websockets_enabled', 'value' => '1'),
            ),
            'WEBSOCKETS_PORT' => array(
                'target' => self::TARGET_ENV,
                'step' => self::STEP_ADVANCED,
                'section' => 'websockets',
                'type' => 'number',
                'label' => 'Порт',
                'description' => 'По умолчанию 8001. При подключении через nginx внешний доступ к этому порту не требуется.',
                'placeholder' => '8001',
                'default' => '8001',
                'min' => 1,
                'max' => 65535,
                'width' => 'half',
                'depends' => array('field' => 'websockets_enabled', 'value' => '1'),
            ),
            'WEBSOCKETS_PUBLIC_URL' => array(
                'target' => self::TARGET_ENV,
                'step' => self::STEP_ADVANCED,
                'section' => 'websockets',
                'type' => 'text',
                'label' => 'Публичный адрес WebSocket',
                'description' => 'Пусто — /ws на адресе панели через nginx. Для отдельного порта: wss://home.example.com:8002/majordomo. В локальной сети доступ без токена, извне — API-токен или сессия администратора. WebSocket только для чтения.',
                'default' => '',
                'width' => 'full',
                'depends' => array('field' => 'websockets_enabled', 'value' => '1'),
            ),
            'WEBSOCKETS_MAX_CLIENTS' => array(
                'target' => self::TARGET_ENV,
                'step' => self::STEP_ADVANCED,
                'section' => 'websockets',
                'type' => 'number',
                'label' => 'Максимум клиентов',
                'description' => 'Одновременных подключений. 100 хватает большинству домов.',
                'placeholder' => '100',
                'default' => '',
                'min' => 1,
                'width' => 'half',
                'depends' => array('field' => 'websockets_enabled', 'value' => '1'),
            ),
            'WEBSOCKETS_MAX_CONNECTIONS_PER_IP' => array(
                'target' => self::TARGET_ENV,
                'step' => self::STEP_ADVANCED,
                'section' => 'websockets',
                'type' => 'number',
                'label' => 'Максимум подключений с одного IP',
                'description' => 'Защита от одного клиента, открывшего слишком много вкладок.',
                'placeholder' => '100',
                'default' => '',
                'min' => 1,
                'width' => 'half',
                'depends' => array('field' => 'websockets_enabled', 'value' => '1'),
            ),
            'WEBSOCKETS_MAX_REQUESTS_PER_MINUTE' => array(
                'target' => self::TARGET_ENV,
                'step' => self::STEP_ADVANCED,
                'section' => 'websockets',
                'type' => 'number',
                'label' => 'Лимит сообщений в минуту',
                'description' => 'Сколько сообщений сервер принимает от клиентов за минуту.',
                'placeholder' => '20000',
                'default' => '',
                'min' => 1,
                'width' => 'half',
                'depends' => array('field' => 'websockets_enabled', 'value' => '1'),
            ),
            'WEBSOCKETS_CLIENT_TIMEOUT' => array(
                'target' => self::TARGET_ENV,
                'step' => self::STEP_ADVANCED,
                'section' => 'websockets',
                'type' => 'number',
                'label' => 'Таймаут простоя клиента, сек',
                'description' => '0 — не отключать неактивных клиентов.',
                'placeholder' => '0',
                'default' => '',
                'min' => 0,
                'width' => 'half',
                'depends' => array('field' => 'websockets_enabled', 'value' => '1'),
            ),
            'WEBSOCKETS_MAX_FRAME_BYTES' => array(
                'target' => self::TARGET_ENV,
                'step' => self::STEP_ADVANCED,
                'section' => 'websockets',
                'type' => 'number',
                'label' => 'Максимальный размер кадра, байт',
                'description' => 'Лимит входящих и исходящих сообщений. 1048576 — 1 МБ.',
                'placeholder' => '1048576',
                'default' => '',
                'min' => 1024,
                'width' => 'half',
                'depends' => array('field' => 'websockets_enabled', 'value' => '1'),
            ),
            'WEBSOCKETS_TLS' => array(
                'target' => self::TARGET_ENV,
                'step' => self::STEP_ADVANCED,
                'section' => 'websockets',
                'type' => 'switch',
                'label' => 'TLS (wss://)',
                'description' => 'Шифрование WebSocket. Нужен сертификат; для домашней сети обычно не требуется.',
                'default' => '0',
                'width' => 'half',
                'depends' => array('field' => 'websockets_enabled', 'value' => '1'),
            ),
            'WEBSOCKETS_TLS_PEM_FILE' => array(
                'target' => self::TARGET_ENV,
                'step' => self::STEP_ADVANCED,
                'section' => 'websockets',
                'type' => 'path',
                'label' => 'Файл сертификата',
                'description' => 'PEM-файл с сертификатом и ключом. Путь к файлу укажите вручную.',
                'placeholder' => './server.pem',
                'default' => '',
                'width' => 'half',
                'picker' => 'none',
                'depends' => array('field' => 'WEBSOCKETS_TLS', 'value' => '1'),
            ),
            'WEBSOCKETS_TLS_PEM_PASSPHRASE' => array(
                'target' => self::TARGET_ENV,
                'step' => self::STEP_ADVANCED,
                'section' => 'websockets',
                'type' => 'password',
                'label' => 'Пароль сертификата',
                'description' => 'Если ключ зашифрован.',
                'placeholder' => '',
                'default' => '',
                'secret' => true,
                'width' => 'half',
                'autocomplete' => 'new-password',
                'password_toggle' => true,
                'depends' => array('field' => 'WEBSOCKETS_TLS', 'value' => '1'),
            ),
            'WEBSOCKETS_ALLOWED_ORIGINS' => array(
                'target' => self::TARGET_ENV,
                'step' => self::STEP_ADVANCED,
                'section' => 'websockets',
                'type' => 'list',
                'label' => 'Разрешённые Origin',
                'description' => 'Проверка включена всегда. Пусто — тот же хост, что у WebSocket. Для другого сайта укажите точные Origin через запятую: https://home.example.com, http://192.168.0.10.',
                'placeholder' => 'https://home.example.com',
                'default' => '',
                'width' => 'full',
                'depends' => array('field' => 'websockets_enabled', 'value' => '1'),
            ),

            // ----------------------------------------------------------------
            // Security / remote access
            // ----------------------------------------------------------------
            'HOME_NETWORK' => array(
                'target' => self::TARGET_ENV,
                'step' => self::STEP_ADVANCED,
                'section' => 'security',
                'type' => 'text',
                'label' => 'Домашняя сеть',
                'description' => 'Например 192.168.0.* или 192.168.0.0/24. Используется для обхода прокси при обращениях внутри сети.',
                'placeholder' => '192.168.0.*',
                'default' => '',
                'width' => 'full',
                'icon' => 'network',
            ),
            'LOCAL_IP' => array(
                'target' => self::TARGET_ENV,
                'step' => self::STEP_ADVANCED,
                'section' => 'security',
                'type' => 'text',
                'label' => 'Локальный IP сервера',
                'description' => 'Помогает системе определить «своя сеть». Обычно можно оставить пустым.',
                'placeholder' => '192.168.0.10',
                'default' => '',
                'width' => 'half',
            ),
            'TRUSTED_PROXIES' => array(
                'target' => self::TARGET_ENV,
                'step' => self::STEP_ADVANCED,
                'section' => 'security',
                'type' => 'list',
                'label' => 'Доверенные прокси',
                'description' => 'IP или CIDR через запятую. Только для reverse proxy, который передаёт X-Forwarded-For.',
                'placeholder' => '127.0.0.1/32, ::1/128',
                'default' => '',
                'width' => 'full',
            ),
            'USE_PROXY' => array(
                'target' => self::TARGET_ENV,
                'step' => self::STEP_ADVANCED,
                'section' => 'security',
                'type' => 'text',
                'label' => 'Прокси для исходящих запросов',
                'description' => 'host:port. Нужен, если сервер выходит в интернет через прокси.',
                'placeholder' => '127.0.0.1:8888',
                'default' => '',
                'width' => 'half',
            ),
            'USE_PROXY_AUTH' => array(
                'target' => self::TARGET_ENV,
                'step' => self::STEP_ADVANCED,
                'section' => 'security',
                'type' => 'password',
                'label' => 'Прокси: логин и пароль',
                'description' => 'Формат user:password. Хранится в .env.',
                'placeholder' => 'user:password',
                'default' => '',
                'secret' => true,
                'width' => 'half',
                'autocomplete' => 'new-password',
                'password_toggle' => true,
            ),

            // ----------------------------------------------------------------
            // Integrations
            // ----------------------------------------------------------------
            'PATH_TO_MYSQLDUMP' => array(
                'target' => self::TARGET_ENV,
                'step' => self::STEP_ADVANCED,
                'section' => 'integrations',
                'type' => 'text',
                'label' => 'Путь к mysqldump',
                'description' => 'Нужен для резервных копий. Обычно mysqldump или полный путь к программе.',
                'placeholder' => 'mysqldump',
                'default' => 'mysqldump',
                'width' => 'half',
                'icon' => 'database-export',
            ),
            'PATH_TO_FFMPEG' => array(
                'target' => self::TARGET_ENV,
                'step' => self::STEP_ADVANCED,
                'section' => 'integrations',
                'type' => 'text',
                'label' => 'Путь к ffmpeg',
                'description' => 'Для работы с медиа. Пусто — функция недоступна.',
                'placeholder' => 'ffmpeg',
                'default' => '',
                'width' => 'half',
                'icon' => 'movie',
            ),
            'PYTHON_PATH' => array(
                'target' => self::TARGET_ENV,
                'step' => self::STEP_ADVANCED,
                'section' => 'integrations',
                'type' => 'text',
                'label' => 'Путь к Python',
                'description' => 'Для модулей, которым нужен Python. Пусто — недоступно.',
                'placeholder' => 'python',
                'default' => '',
                'width' => 'half',
                'icon' => 'brand-python',
            ),
            'AUDIO_PLAYER' => array(
                'target' => self::TARGET_ENV,
                'step' => self::STEP_ADVANCED,
                'section' => 'integrations',
                'type' => 'text',
                'label' => 'Аудиоплеер',
                'description' => 'Команда воспроизведения звука, например «omxplayer --no-osd» или «aplay».',
                'placeholder' => 'aplay',
                'default' => '',
                'width' => 'half',
                'icon' => 'volume',
            ),
        );

        // The renderer works with a field definition, so every entry carries its
        // own id. Keeping the id inside the array also makes the schema easier
        // to serialise for tests and the CLI.
        foreach ($fields as $id => &$field) {
            $field['id'] = (string)$id;
        }
        unset($field);
        return $fields;
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function field(string $id): ?array
    {
        $fields = self::fields();
        return $fields[$id] ?? null;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public static function fieldsForStep(int $step): array
    {
        return array_filter(self::fields(), static function (array $field) use ($step): bool {
            return (int)$field['step'] === $step;
        });
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public static function sectionsForStep(int $step): array
    {
        return array_filter(self::sections(), static function (array $section) use ($step): bool {
            return (int)$section['step'] === $step;
        });
    }

    /**
     * Direct env fields (meta toggles such as Redis/WS are mapped in Installer).
     *
     * @return string[]
     */
    public static function envKeys(): array
    {
        $keys = array();
        foreach (self::fields() as $id => $field) {
            if ($field['target'] === self::TARGET_ENV) {
                $keys[] = (string)$id;
            }
        }
        return array_values(array_unique($keys));
    }

    /**
     * @return string[]
     */
    public static function dbSettingNames(): array
    {
        $names = array();
        foreach (self::fields() as $field) {
            if ($field['target'] === self::TARGET_DB) {
                $names[] = (string)$field['id'];
            }
        }
        return array_values(array_unique($names));
    }

    /**
     * @return string[]
     */
    public static function metaFields(): array
    {
        return array_values(array_filter(array_keys(self::fields()), static function (string $id): bool {
            return self::fields()[$id]['target'] === self::TARGET_META;
        }));
    }

    /**
     * @return array<string,string>
     */
    public static function languageOptions(): array
    {
        return array(
            'ru' => 'Русский',
            'en' => 'English',
            'ua' => 'Українська',
            'bg' => 'Български',
            'cs' => 'Čeština',
            'el' => 'Ελληνικά',
            'es' => 'Español',
            'et' => 'Eesti',
            'he' => 'עברית',
            'it' => 'Italiano',
            'lt' => 'Lietuvių',
            'lv' => 'Latviešu',
            'pl' => 'Polski',
            'ro' => 'Română',
            'zh' => '中文',
        );
    }

    /**
     * Timezone identifiers as value => label. The list is cached per request.
     *
     * @return array<string,string>
     */
    public static function timezoneOptions(): array
    {
        static $options = null;
        if (is_array($options)) {
            return $options;
        }
        $options = array();
        $now = new \DateTimeImmutable('now');
        foreach (\DateTimeZone::listIdentifiers() as $zone) {
            try {
                $tz = new \DateTimeZone($zone);
                $offset = $tz->getOffset($now) / 3600;
                $sign = $offset >= 0 ? '+' : '';
                $options[$zone] = $zone . ' (GMT' . $sign . rtrim(rtrim(number_format($offset, 1, '.', ''), '0'), '.') . ')';
            } catch (\Throwable $e) {
                $options[$zone] = $zone;
            }
        }
        return $options;
    }

    /**
     * @return array<string,string>
     */
    public static function timezoneList(): array
    {
        return array_fill_keys(\DateTimeZone::listIdentifiers(), true);
    }

    /**
     * All env keys the wizard can write, including meta mappings.
     *
     * @return string[]
     */
    public static function managedEnvKeys(): array
    {
        $keys = array('USE_REDIS', 'DISABLE_WEBSOCKETS');
        foreach (self::fields() as $field) {
            if ($field['target'] === self::TARGET_ENV) {
                $keys[] = $field['id'];
            }
        }
        return array_values(array_unique($keys));
    }

    /**
     * @return array<string,string>
     */
    public static function defaults(): array
    {
        $defaults = array();
        foreach (self::fields() as $id => $field) {
            $defaults[$id] = isset($field['default']) ? (string)$field['default'] : '';
        }
        return $defaults;
    }
}
