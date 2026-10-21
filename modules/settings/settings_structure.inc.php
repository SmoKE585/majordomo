<?php

/**
 * Legacy DB-backed settings that are not part of the first-run schema.
 *
 * The main sections (database, identity, performance, logging, Redis,
 * WebSockets, security, integrations) come from
 * `MajorDoMo\Core\Settings\SettingsSchema`. Keeping only the unique legacy
 * sections here avoids showing the same option twice after the wizard settings
 * moved into "Общие настройки".
 */
$settings_sections = array(
    'voice' => array(
        'title' => 'Голос: хуки',
        'description' => 'Пользовательский код, который выполняется до и после озвучивания. Базовые язык и сигнал вынесены в раздел «Основные».',
        'settings' => array(
            'HOOK_BEFORE_SAY' => array(
                'title' => 'Код перед озвучиванием',
                'notes' => 'Код выполняется непосредственно перед вызовом `say()` и может менять поведение озвучки.',
                'type' => 'textarea',
                'use_code_editor' => 1,
                'default' => '',
                'priority' => 28,
                'rows' => 4,
            ),
            'HOOK_AFTER_SAY' => array(
                'title' => 'Код после озвучивания',
                'notes' => 'Код выполняется сразу после завершения озвучивания.',
                'type' => 'textarea',
                'use_code_editor' => 1,
                'default' => '',
                'priority' => 27,
                'rows' => 4,
            ),
        ),
    ),
    'events' => array(
        'title' => 'События и хуки',
        'description' => 'Маршрутизация событий и пользовательский код для воспроизведения, сканирования и реакций системы.',
        'settings' => array(
            'HOOK_EVENT_COMMAND' => array(
                'title' => 'Маршрутизация команды',
                'notes' => 'Определяет, какие обработчики получают событие команды.',
                'type' => 'json',
                'default' => '{"scripts":{"filter":""}}',
                'priority' => 26,
            ),
            'HOOK_EVENT_ASK' => array(
                'title' => 'Маршрутизация вопроса',
                'notes' => 'Определяет, куда передаётся событие вопроса от системы или устройств.',
                'type' => 'json',
                'default' => '{}',
                'priority' => 25,
            ),
            'HOOK_EVENT_SAY' => array(
                'title' => 'Маршрутизация озвучивания',
                'notes' => 'Настраивает получателей обычных голосовых сообщений.',
                'type' => 'json',
                'default' => '{}',
                'priority' => 24,
            ),
            'HOOK_EVENT_SAYTO' => array(
                'title' => 'Маршрутизация адресного сообщения',
                'notes' => 'Указывает получателей для озвучки, адресованной конкретной зоне или терминалу.',
                'type' => 'json',
                'default' => '{}',
                'priority' => 23,
            ),
            'HOOK_EVENT_SAYREPLY' => array(
                'title' => 'Маршрутизация ответа',
                'notes' => 'Определяет, кто получает голосовой ответ системы.',
                'type' => 'json',
                'default' => '{}',
                'priority' => 22,
            ),
            'HOOK_EVENT_HOURLY' => array(
                'title' => 'Маршрутизация почасового события',
                'notes' => 'Настраивает обработчики для регулярного почасового события.',
                'type' => 'json',
                'default' => '{}',
                'priority' => 21,
            ),
            'HOOK_BARCODE' => array(
                'title' => 'Код обработки штрихкода',
                'notes' => 'Пользовательский код, который выполняется после считывания штрихкода.',
                'type' => 'textarea',
                'use_code_editor' => 1,
                'default' => '',
                'priority' => 20,
                'rows' => 4,
            ),
            'HOOK_PLAYMEDIA' => array(
                'title' => 'Код для PlayMedia',
                'notes' => 'Код вызывается при запуске воспроизведения медиа.',
                'type' => 'textarea',
                'use_code_editor' => 1,
                'default' => '',
                'priority' => 19,
                'rows' => 4,
            ),
            'HOOK_BEFORE_PLAYSOUND' => array(
                'title' => 'Код перед воспроизведением звука',
                'notes' => 'Выполняется до запуска `playSound()` и позволяет подготовить окружение.',
                'type' => 'textarea',
                'use_code_editor' => 1,
                'default' => '',
                'priority' => 18,
                'rows' => 4,
            ),
            'HOOK_AFTER_PLAYSOUND' => array(
                'title' => 'Код после воспроизведения звука',
                'notes' => 'Выполняется после завершения `playSound()`.',
                'type' => 'textarea',
                'use_code_editor' => 1,
                'default' => '',
                'priority' => 17,
                'rows' => 4,
            ),
        ),
    ),
    'editor' => array(
        'title' => 'Редактор кода',
        'description' => 'Параметры встроенного редактора для скриптов, методов и других кодовых полей.',
        'settings' => array(
            'CODEEDITOR_TURNONSETTINGS' => array(
                'title' => LANG_CODEEDITOR_TURNONSETTINGS,
                'notes' => 'Включает пользовательские настройки редактора вместо стандартного поведения.',
                'type' => 'onoff',
                'default' => '0',
                'priority' => 11,
            ),
            'CODEEDITOR_SHOWLINE' => array(
                'title' => LANG_CODEEDITOR_SHOWLINE,
                'notes' => 'Максимальная высота редактора до появления внутренней прокрутки.',
                'type' => 'select',
                'data' => '10=10|35=35|45=45|100=100|500=500|1000=1000|99999=' . LANG_CODEEDITOR_BYCODEHEIGHT,
                'priority' => 10,
            ),
            'CODEEDITOR_MIXLINE' => array(
                'title' => LANG_CODEEDITOR_MIXLINE,
                'notes' => 'Минимальная стартовая высота редактора в строках.',
                'type' => 'select',
                'data' => '5=5|10=10|25=25|40=40|1=' . LANG_CODEEDITOR_BYCODEHEIGHT,
                'priority' => 9,
            ),
            'CODEEDITOR_UPTOLINE' => array(
                'title' => LANG_CODEEDITOR_UPTOLINE,
                'notes' => 'Автоматически переводит редактор к строке, в которой обнаружена ошибка.',
                'type' => 'onoff',
                'priority' => 8,
            ),
            'CODEEDITOR_SHOWERROR' => array(
                'title' => LANG_CODEEDITOR_SHOWERROR,
                'notes' => 'Показывает текст ошибки рядом с проблемной строкой.',
                'type' => 'onoff',
                'priority' => 7,
            ),
            'CODEEDITOR_AUTOCLOSEQUOTES' => array(
                'title' => LANG_CODEEDITOR_AUTOCLOSEQUOTES,
                'notes' => 'Автоматически закрывает кавычки и скобки при вводе.',
                'type' => 'onoff',
                'priority' => 6,
            ),
            'CODEEDITOR_WRAPLINES' => array(
                'title' => LANG_CODEEDITOR_WRAPLINES,
                'notes' => 'Включает перенос длинных строк без горизонтальной прокрутки.',
                'type' => 'onoff',
                'priority' => 5,
            ),
            'CODEEDITOR_THEME' => array(
                'title' => LANG_CODEEDITOR_THEME,
                'notes' => 'Редактор по умолчанию следует теме админки. Можно принудительно включить светлую или тёмную тему.',
                'type' => 'select',
                'data' => 'auto=' . LANG_DEFAULT . '|light=Светлая|dark=Тёмная',
                'default' => 'auto',
                'priority' => 4,
            ),
            'CODEEDITOR_AUTOSAVE' => array(
                'title' => LANG_CODEEDITOR_AUTOSAVE,
                'notes' => 'Частота автоматического сохранения кода во время редактирования.',
                'type' => 'select',
                'data' => '0=' . LANG_CODEEDITOR_AUTOSAVE_PARAMS_ONLY_HANDS . '|5=' . LANG_CODEEDITOR_AUTOSAVE_PARAMS_EVERY_5 . '|10=' . LANG_CODEEDITOR_AUTOSAVE_PARAMS_EVERY_10 . '|15=' . LANG_CODEEDITOR_AUTOSAVE_PARAMS_EVERY_15 . '|30=' . LANG_CODEEDITOR_AUTOSAVE_PARAMS_EVERY_30 . '|60=' . LANG_CODEEDITOR_AUTOSAVE_PARAMS_EVERY_60,
                'priority' => 3,
            ),
        ),
    ),
    'mail' => array(
        'title' => 'Почта',
        'description' => 'Настройки отправки почтовых уведомлений через SMTP или Sendmail.',
        'settings' => array(
            'MAIL_TYPE' => array(
                'title' => 'Способ отправки',
                'notes' => 'Выберите SMTP для внешнего почтового сервера или Sendmail для локальной отправки.',
                'type' => 'select',
                'data' => 'smtp=SMTP|sendmail=Sendmail',
                'priority' => 10,
            ),
            'MAIL_HOST' => array(
                'title' => 'SMTP-сервер',
                'notes' => 'Адрес SMTP-сервера, через который отправляются письма.',
                'priority' => 9,
            ),
            'MAIL_AUTH' => array(
                'title' => 'Требовать авторизацию',
                'notes' => 'Включите, если SMTP-сервер требует логин и пароль.',
                'type' => 'onoff',
                'default' => '0',
                'priority' => 8,
            ),
            'MAIL_USER' => array(
                'title' => 'SMTP-логин',
                'notes' => 'Имя пользователя для входа на SMTP-сервер.',
                'priority' => 7,
            ),
            'MAIL_PASSWORD' => array(
                'title' => 'SMTP-пароль',
                'notes' => 'Пароль для входа на SMTP-сервер.',
                'type' => 'password',
                'priority' => 6,
            ),
            'MAIL_PORT' => array(
                'title' => 'SMTP-порт',
                'notes' => 'Обычно это `25`, `465` или `587` в зависимости от сервера.',
                'priority' => 5,
            ),
            'MAIL_SECURE' => array(
                'title' => 'Защита соединения',
                'notes' => 'Тип шифрования для соединения с SMTP-сервером.',
                'type' => 'select',
                'data' => '=Без шифрования|ssl=SSL|tls=TLS',
                'priority' => 4,
            ),
        ),
    ),
);
