---
title: Доступные команды
audience: user, agent
category: user
tags: команды, cli, инструменты, установка, проверки, сборка
description: Полный список CLI-команд MajorDoMo — установка, конфигурация, проверки, сборка статики, локальный сервер, скриншоты и служебные циклы.
---

# Доступные команды

Все команды выполняются из корня проекта — там, где лежат `bootstrap.php`, `package.json` и каталоги `tools/`, `tests/`, `scripts/`.

```bash
cd /path/to/majordomo
```

Для PHP-команд нужен PHP 8.4+ и настроенный `.env`. Для команд `npm` — Node.js и установленные зависимости (`npm install`).

> **Главное правило.** Команды, которые пишут в базу, меняют `.env`, удаляют данные или перезапускают циклы, выполняйте осознанно. Если сомневаетесь — сначала запустите команду без флагов записи (`--write`, `--force`) или проверьте её `--help`.

## 1. Первичная настройка

CLI мастера установки: `tools/install.php`.

| Команда | Что делает |
| --- | --- |
| `php tools/install.php status` | Показывает состояние настройки, путь к маркеру и URL мастера. |
| `php tools/install.php check` | Проверяет PHP, расширения, права на файлы и окружение. Возвращает ненулевой код, если есть блокирующие ошибки. |
| `php tools/install.php test-db` | Проверяет подключение к MySQL/MariaDB, наличие обязательных таблиц и администратора. |
| `php tools/install.php reset` | Снова включает мастер настройки. **База данных и существующие данные не изменяются.** |
| `php tools/install.php complete` | Помечает настройку завершённой без прохождения мастера. |

Самая частая команда после переустановки или сброса локальной базы:

```bash
php tools/install.php reset
```

После неё откройте `/install.php` (или `/install`) и пройдите мастер. Маркер установки лежит в `cms/modules_installed/install.installed`.

## 2. Конфигурация

CLI конфигурации: `tools/config.php`.

| Команда | Что делает |
| --- | --- |
| `php tools/config.php doctor` | Диагностика конфигурации: `.env`, обязательные ключи, кеш и типы значений. |
| `php tools/config.php cache` | Пересобирает кеш конфигурации. |
| `php tools/config.php clear` | Очищает кеш конфигурации. |
| `php tools/config.php snapshot` | Выводит текущие значения конфигурации в JSON. Секреты маскируются. |
| `php tools/config.php audit` | Запускает аудит обращений к легаси-константам (см. ниже). |
| `php tools/config.php migrate --from=config.legacy.php` | Разбирает старый `config.php` и показывает, что будет перенесено в `.env`. |
| `php tools/config.php migrate --from=config.legacy.php --write` | Записывает перенесённые значения в `.env` (с резервной копией). |
| `php tools/config.php migrate --from=config.legacy.php --write --force` | Перезаписывает уже существующие ключи. Используйте только осознанно. |

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
| `php tests/install_smoke.php` | Мастер настройки, `.env`-писатель, маркер установки, схема и gate. |
| `php tests/core_cycle_smoke.php` | Изолированная проверка цикла на фикстурах; рабочая база не используется. |
| `php tests/core_runtime_smoke.php` | Интеграционная проверка ядра. Требует `APP_ENV=testing` и отдельные тестовые порты MariaDB/Redis. |
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

Для диагностики цикла используйте раздел **X-Ray** в админке, а не ручной запуск.

## 8. Типовой порядок работ

После обновления кода:

```bash
php tools/install.php status
php tools/config.php doctor
php tests/routing_smoke.php
php tests/config_smoke.php
php tests/install_smoke.php
php tests/knowledge_base_smoke.php
npm run build
php tools/config-audit.php
```

Затем откройте `/panel/`, проверьте консоль браузера, мобильную ширину 390 px, светлую и тёмную темы, переходы `partLoad` и старую `pd`-ссылку.

## 9. Что нельзя делать без необходимости

- Запускать `php tools/install.php reset` на рабочей системе «просто так»: мастер перехватит админку, сайт и API до завершения настройки.
- Запускать `php tools/config.php migrate --write --force` без резервной копии `.env`.
- Запускать `php tools/config.php clear` и одновременно править `.env`: кеш может перезаписаться.
- Запускать циклы вручную поверх работающего планировщика.
- Коммитить `public/assets/admin/*` после ручного редактирования — только после `npm run build`.
