---
title: Доступные команды
audience: user, agent
category: user
tags: команды, cli, инструменты, установка, проверки, сборка
description: Единая консоль php majordomo — справка, диагностика, циклы, перезапуск, журналы, установка и конфигурация; команды разработки.
---

# Доступные команды

Все команды выполняются из корня проекта — там, где лежат `bootstrap.php`, `package.json` и каталоги `tools/`, `tests/`, `scripts/`.

```bash
cd /path/to/majordomo
```

Для PHP-команд нужен PHP 8.4+. Справка и `about` работают без `.env` и БД;
операции с конфигурацией и сервисами используют настройки текущей установки.
Для команд `npm` — Node.js и установленные зависимости (`npm install`).

## Единая консоль

Основной вход — **`php majordomo`**. Новые команды появляются здесь автоматически
после регистрации в ядре. Примеры:

```bash
php majordomo
php majordomo list cycles
php majordomo help cycles:restart
php majordomo cycles:restart --help
php majordomo about
php majordomo doctor
```

`doctor` проверяет PHP, расширения, каталоги и подключение к настроенной БД.
`about` показывает PHP, ОС, путь установки и состояние мастера без подключения
к сервисам. Команды диагностики не исправляют обнаруженные проблемы сами.
Команды, читающие Config, могут обновить его локальный кеш.

### Управление циклами

| Команда | Действие |
| --- | --- |
| `php majordomo cycles:status` | Желаемые и фактические состояния всех циклов, heartbeat и супервизор. Читает БД/Redis. |
| `php majordomo cycles:status mqtt` | Состояние одного цикла. Имена `mqtt` и `cycle_mqtt` равнозначны. |
| `php majordomo cycles:status --offline` | Только желаемые состояния без БД/Redis; фактическое состояние — `unknown`. |
| `php majordomo cycles:start mqtt` | Запросить запуск цикла. |
| `php majordomo cycles:stop mqtt` | Остановить до явного запуска; состояние переживает перезапуск службы. |
| `php majordomo cycles:disable mqtt` | Отключить цикл, исключив автоматическое восстановление. |
| `php majordomo cycles:enable mqtt` | Включить ранее отключённый цикл. |
| `php majordomo cycles:restart mqtt` | Запросить перезапуск одного цикла; он переводится в running. |
| `php majordomo cycles:restart --all` | Перезапустить циклы с желаемым состоянием running; stopped/disabled остаются выключенными. |
| `php majordomo system:reload --managed` | Запросить завершение супервизора и дочерних циклов для повторного запуска менеджером службы. |

Циклами по-прежнему владеет `cycle.php`: CLI сохраняет запрос в
`cms/cycles/state.json`. Сообщение «запрос сохранён» означает принятие запроса,
а не уже завершённый перезапуск. Проверьте результат через `cycles:status`.
Если супервизор остановлен, запрос дождётся его запуска. Ошибка записи состояния
возвращает ненулевой код. При выключенных WebSockets сначала включите их в
общих настройках: CLI не обходит конфигурационное отключение.

`system:reload` требует `--managed`: этот флаг подтверждает, что служба
автоматически запускает супервизор после его выхода (например, systemd с
`Restart=always`). Команда не перезагружает ОС или PHP-FPM и не запускает
супервизор самостоятельно. Без внешнего менеджера после reload система
останется без циклов, поэтому без флага команда отказывается выполнять запрос.

У CLI и веб-сервера должны быть права на общий каталог `cms/cycles`.

### Журналы

```bash
php majordomo logs:tail
php majordomo logs:tail errors --lines=100
php majordomo logs:tail debug --follow
php majordomo logs:tail errors --date=2026-10-22
php majordomo logs:tail cycle_mqtt --cycle --follow
php majordomo logs:tail errors --directory=/var/log/majordomo
```

По умолчанию читается сегодняшний `errors.log`: `LOG_DIRECTORY`, если задан,
иначе `cms/debmes/YYYY-MM-DD/errors.log`. Если путь задан через настройку
`SYSTEM_DEBMES_PATH` в БД, передайте его через `--directory` — просмотр журнала
не подключается к БД. Вложенные каналы вроде `modules/mqtt` поддерживаются.
`--cycle` читает `log_YYYY-MM-DD-cycle_mqtt.php.txt`; такой файл создаётся при
`LOG_CYCLES=1`. Отсутствующий файл без `--follow` — ошибка.

`--lines` принимает 1–1000 (по умолчанию 50), начальное чтение ограничено 4 MiB.
`--follow` ждёт новые записи до Ctrl+C, учитывает ротацию/обнуление файла и смену
даты; при явном `--date` остаётся на выбранном дне. С `--json` несовместим.

### Автоматизация

```bash
php majordomo cycles:status --json
php majordomo config:snapshot --json
```

`--json` даёт один документ с полями `ok`, `data`, `messages`, `error`, в том числе
при ошибке. В обычном режиме ошибки выводятся в stderr. Коды завершения:
`0` — успех (для управления циклами — запрос сохранён), `1` — ошибка выполнения
или диагностики, `2` — неверные аргументы/опции. JSON команды управления содержит
`accepted=true`, `applied=false`; фактическое состояние запрашивается отдельно.
Секреты конфигурации в snapshot и предварительном просмотре миграции маскируются.
Содержимое журналов выводится как записано приложением.

Опции со значением принимают обе формы: `--lines=100` и `--lines 100`.
Неизвестные/повторные опции считаются ошибкой. `--help` или `-h` показывают
справку без выполнения команды.

Старые `php tools/config.php <команда>` и `php tools/install.php <команда>`
сохранены как адаптеры совместимости. Для новых вызовов используйте `config:*`
и `install:*`, описанные ниже. Для разработчиков: [как добавить команду](../agents/console.md).

> **Главное правило.** Команды, которые пишут в базу, меняют `.env`, удаляют данные или перезапускают циклы, выполняйте осознанно. Если сомневаетесь — сначала запустите команду без флагов записи (`--write`, `--force`) или проверьте её `--help`.

## 1. Первичная настройка

Команды мастера установки: группа `install:*`.

| Команда | Что делает |
| --- | --- |
| `php majordomo install:status` | Показывает состояние настройки, путь к маркеру и URL мастера. |
| `php majordomo install:check` | Проверяет PHP, расширения, права на файлы и окружение. Возвращает ненулевой код, если есть блокирующие ошибки. |
| `php majordomo install:test-db` | Проверяет подключение к MySQL/MariaDB, наличие обязательных таблиц и администратора. |
| `php majordomo install:reset` | Снова включает мастер настройки. **База данных и существующие данные не изменяются.** |
| `php majordomo install:complete` | Помечает настройку завершённой без прохождения мастера. |

Самая частая команда после переустановки или сброса локальной базы:

```bash
php majordomo install:reset
```

После неё откройте `/install.php` (или `/install`) и пройдите мастер. Маркер установки лежит в `cms/modules_installed/install.installed`.

## 2. Конфигурация

Команды конфигурации: группа `config:*`.

| Команда | Что делает |
| --- | --- |
| `php majordomo config:doctor` | Диагностика конфигурации: `.env`, обязательные ключи, кеш и типы значений. |
| `php majordomo config:cache` | Пересобирает кеш конфигурации. |
| `php majordomo config:clear` | Очищает кеш конфигурации. |
| `php majordomo config:snapshot` | Выводит текущие значения конфигурации в JSON. Секреты маскируются. |
| `php majordomo config:audit` | Запускает аудит обращений к легаси-константам (см. ниже). |
| `php majordomo config:migrate --from=config.legacy.php` | Разбирает старый `config.php` и показывает, что будет перенесено в `.env`. |
| `php majordomo config:migrate --from=config.legacy.php --write` | Записывает перенесённые значения в `.env` (с резервной копией). |
| `php majordomo config:migrate --from=config.legacy.php --write --force` | Перезаписывает уже существующие ключи. Используйте только осознанно. |

Аудит легаси-констант можно запускать и отдельно:

```bash
php tools/config-audit.php
php tools/config-audit.php --json
php tools/config-audit.php --fail-on-legacy
php tools/config-audit.php --no-log
```

`--fail-on-legacy` возвращает ненулевой код, если в коде остались обращения к старым константам — удобно для CI.

## 3. Проверки и тесты

Перед сдачей изменений запускайте smoke-тесты. Они не требуют рабочей базы, кроме отдельно оговорённых случаев.

| Команда | Что проверяет |
| --- | --- |
| `php tests/routing_smoke.php` | Чистые URL `/panel/...`, legacy `pd`-ссылки, round-trip маршрутов, nginx-контракт. |
| `php tests/config_smoke.php` | Парсер `.env`, типы и кеш конфигурации, легаси-мост. |
| `php tests/console_smoke.php` | CLI: справка, строгие аргументы, JSON, циклы, журналы и адаптеры на временных файлах без БД/Redis. |
| `php tests/install_smoke.php` | Мастер настройки, `.env`-писатель, маркер установки, схема и gate. |
| `php tests/core_cycle_smoke.php` | Изолированная проверка цикла на фикстурах; рабочая база не используется. |
| `php tests/core_runtime_smoke.php` | Интеграционная проверка ядра. Требует `APP_ENV=testing` и отдельные тестовые порты MariaDB/Redis. |
| `php tests/settings_cycles_smoke.php` | Схема «Общих настроек», персистентные состояния и reconcile циклов. |
| `php tests/folder_picker_smoke.php` | Границы выбора папок: обход `..`, пути вне проекта, скрытые каталоги. |
| `php tests/knowledge_base_smoke.php` | Сканирование `docs/`, front matter, безопасный Markdown и ссылки базы знаний. |

Базовые проверки синтаксиса:

```bash
php -l path/to/file.php
node --check path/to/file.js
```

Проверка собранных ассетов:

```bash
node tools/check-admin-assets.mjs
```

## 4. Сборка статики

Все команды — из `package.json`.

| Команда | Что делает |
| --- | --- |
| `npm install` | Устанавливает зависимости. |
| `npm run build` | Полная сборка: админ-бандл, редактор кода и графики. |
| `npm run build:admin` | Только основной бандл `public/assets/admin/admin.*`. |
| `npm run build:editor` | Только lazy-chunk редактора CodeMirror. |
| `npm run build:charts` | Только lazy-chunk графиков ApexCharts. |
| `npm run watch` | Пересобирает основной бандл при изменениях. |
| `npm run watch:editor` | Пересобирает редактор при изменениях. |
| `npm run watch:charts` | Пересобирает графики при изменениях. |
| `npm run icons` | Обновляет каталог иконок Tabler после обновления пакета. |
| `npm run check` | Синтаксическая проверка `resources/js/app.js`. |

Не редактируйте `public/assets/admin/*` вручную: это сгенерированные файлы. После сборки при необходимости поднимите версию в `templates/common_header_admin.html` и в конфигурациях lazy-chunk.

## 5. Локальный сервер

```bash
php dev_server.php --host=127.0.0.1 --port=8080
```

Сервер повторяет контракт nginx: отдаёт `public/` как document root, а `modules/`, `cms/`, `objects/`, `scripts/`, `3rdparty/` и `docs/` — из корня проекта. Нужен MySQL/MariaDB и заполненный `.env`.

Полезные адреса:

- `http://127.0.0.1:8080/` — сайт;
- `http://127.0.0.1:8080/panel/` — админка;
- `http://127.0.0.1:8080/install.php` — мастер настройки.

## 6. Скриншоты

```bash
node tools/screenshot.mjs \
  --url "http://127.0.0.1:8080/panel/" \
  --out "docs/ui-redesign/home.png" \
  --cookie "prj=<session>"
```

| Параметр | Назначение |
| --- | --- |
| `--url` | Адрес страницы. |
| `--out` | Путь к PNG. |
| `--width`, `--height` | Размер viewport. |
| `--cookie` | Cookie сессии; для тёмной темы добавьте `; theme=dark`. |
| `--auth user:password` | HTTP Basic Auth, если включён. |
| `--wait` | Ожидание перед снимком в миллисекундах. |
| `--full` | Снимок всей страницы. |

Нужен установленный Chrome или Edge.

## 7. Служебные циклы

Циклы запускаются планировщиком и работают постоянно. **Не запускайте их вручную на рабочей системе** — получится второй экземпляр, который будет конкурировать с основным.

| Файл | Назначение |
| --- | --- |
| `scripts/cycle_main.php` | Основной цикл: таймеры, расписания, обслуживание. |
| `scripts/cycle_core.php` | Ядро: обработка очереди и фоновых задач. |
| `scripts/cycle_scheduler.php` | Планировщик заданий. |
| `scripts/cycle_states.php` | Обновление состояний объектов. |
| `scripts/cycle_mqtt.php` | Обработка MQTT. |
| `scripts/cycle_ping.php` | Мониторинг доступности хостов. |
| `scripts/cycle_phistory.php` | Запись истории свойств. |
| `scripts/cycle_wscache.php` | Кеш WebSocket-данных. |
| `scripts/cycle_execs.php` | Выполнение заданий. |
| `scripts/cycle_websockets.php` | WebSocket-сервер. |
| `scripts/core_worker.php` | Воркер фоновых задач ядра. |
| `scripts/periodical_db_save.php` | Периодическое сохранение данных в БД. |
| `scripts/startup_maintenance.php` | Стартовое обслуживание и миграции; запускается из `cycle_main.php`. |

Для диагностики используйте `php majordomo cycles:status`, `logs:tail` или
раздел **X-Ray** в админке.

## 8. Типовой порядок работ

После обновления кода:

```bash
php majordomo install:status
php majordomo config:doctor
php tests/routing_smoke.php
php tests/config_smoke.php
php tests/install_smoke.php
php tests/knowledge_base_smoke.php
npm run build
php tools/config-audit.php
```

Затем откройте `/panel/`, проверьте консоль браузера, мобильную ширину 390 px, светлую и тёмную темы, переходы `partLoad` и старую `pd`-ссылку.

## 9. Что нельзя делать без необходимости

- Запускать `php majordomo install:reset` на рабочей системе «просто так»: мастер перехватит админку, сайт и API до завершения настройки.
- Запускать `php majordomo config:migrate --write --force` без резервной копии `.env`.
- Запускать `php majordomo config:clear` и одновременно править `.env`: кеш может перезаписаться.
- Запускать циклы вручную поверх работающего планировщика.
- Коммитить `public/assets/admin/*` после ручного редактирования — только после `npm run build`.
