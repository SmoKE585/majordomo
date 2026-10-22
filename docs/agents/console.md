---
title: Консоль MajorDoMo — правила разработки
audience: agent
category: core
tags: cli, console, команды, архитектура
description: Структура CLI, отдельные классы команд, регистрация, ввод/вывод, безопасная загрузка ядра и изолированные тесты.
order: 50
---

# Консоль MajorDoMo

Коротко: единственная точка входа — `php majordomo`, каждая команда — отдельный
класс в `lib/core/Console/Commands/`, зарегистрированный в `Console\Application`.
Новые самостоятельные CLI-скрипты в корне и `tools/` не создаются.

Единственная точка входа для новых административных CLI-команд:

```bash
php majordomo
php majordomo help cycles:restart
php majordomo list config --json
```

Если функциональности требуется управление, обслуживание или диагностика из
терминала, добавляйте CLI-команду вместе с ней. Не копируйте бизнес-логику панели:
оба интерфейса должны вызывать один сервис ядра. Команды не используют HTTP API
самой системы и не зависят от доступности веб-сервера.

## Структура

| Файл / каталог | Ответственность |
| --- | --- |
| `majordomo` | CLI-only адаптер: bootstrap → Console Application → exit code |
| `lib/core/Console/Application.php` | Явный реестр классов, выбор команды, справка, обработка ошибок |
| `lib/core/Console/Command.php` | Контракт команды и декларации аргументов/опций |
| `lib/core/Console/Commands/*Command.php` | Один отдельный конкретный класс на команду |
| `lib/core/Console/Input.php` | Строгая проверка аргументов, опций и числовых границ |
| `lib/core/Console/Output.php` | stdout/stderr, таблицы, единый JSON |
| `lib/core/Console/TerminalInput.php` | Скрытый ввод пароля с подтверждением в команде и чтение из stdin |
| `lib/core/Users/UserAccounts.php` | Список/поиск пользователей и изменение пароля; подготовленные SQL-запросы |
| `lib/core/Console/Context.php` | Корень приложения, ленивая конфигурация, доступ к CycleManager |
| `lib/core/Console/CycleActionCommand.php` | Общая обвязка команд циклов, без собственного управления процессами |
| `lib/core/Console/CycleRuntimeReader.php` | Снимок heartbeat из MySQL/Redis, только чтение |
| `lib/core/Console/LogTail.php` | Ограниченное чтение конца журнала и follow |
| `lib/core/Console/PhpToolRunner.php` | Запуск существующих изолированных PHP-инструментов без shell |
| `tools/config.php`, `tools/install.php` | Старые имена команд; тонкие адаптеры в общий CLI |

Новых зависимостей нет; используется действующая автозагрузка `MajorDoMo\Core\`.
Не добавляйте новые команды в switch, корневые PHP-скрипты или `tools/`.
Существующие `tools/config-audit.php` и `tools/config-migrate-runner.php` сохранены:
аудит — отдельный статический инструмент, чтение старого PHP-конфига намеренно
изолировано дочерним процессом. Это не образец для новой бизнес-команды.

## Добавление команды

1. Создайте `lib/core/Console/Commands/ExampleStatusCommand.php`:

   ```php
   <?php

   namespace MajorDoMo\Core\Console\Commands;

   use MajorDoMo\Core\Console\{Command, Input, Output, Context};

   final class ExampleStatusCommand extends Command
   {
       public function name(): string { return 'example:status'; }
       public function description(): string { return 'Состояние подсистемы'; }
       public function arguments(): array
       {
           return array('name?' => 'Необязательное имя');
       }
       public function options(): array
       {
           return array('limit=' => 'Лимит от 1 до 100, по умолчанию 10');
       }
       public function execute(Input $input, Output $output, Context $context): int
       {
           $limit = $input->integer('limit', 10, 1, 100);
           // Здесь вызов существующего сервиса подсистемы.
           $data = array('name' => $input->argument('name'), 'limit' => $limit);
           $output->table(array('Имя', 'Лимит'), array(array($data['name'] ?? 'все', $limit)));
           $output->result($data);
           return 0;
       }
   }
   ```

2. Зарегистрируйте `new Commands\ExampleStatusCommand()` в конструкторе
   `Console\Application`. Имена уникальны: `group:action`, латиница в нижнем регистре.
3. Опишите команду, побочные эффекты и пример в `docs/user/commands.md`.
4. Добавьте сценарии в `tests/console_smoke.php` или отдельный изолированный тест
   подсистемы; проверьте успех, ошибки ввода и JSON.

## Ввод и вывод

- Суффикс `?` у аргумента — необязательный; без него аргумент обязателен.
- Суффикс `=` у опции — значение обязательно. Поддерживаются `--limit=10` и
  `--limit 10`. Опции без `=` — флаги. `--` завершает разбор опций.
- `--help`, `-h`, `--json`, `--ansi`, `--no-ansi`, `--verbose` добавляются автоматически
  и зарезервированы для общего CLI. Справка не выполняет команду.
- Неизвестные/повторные опции, лишние/пропущенные аргументы — ошибка, код `2`.
- `execute()` возвращает код: `0` — успех, `1` — ошибка выполнения/диагностики,
  `2` — ошибка использования. Внутри классов запрещены `exit`/`die`.
- Вывод — только через `Output`; не используйте `echo`, `printf`, прямой stdout.
  В обычном режиме ошибки идут в stderr. `write()` нужен только потоковому tail.
- Для автоматизации передавайте массив в `result()`. При `--json` Application
  печатает один документ `{ok, data, messages, error}`; ошибка также валидный JSON.
- Не включайте в результат `.env`, пароли, токены, сырые ошибки драйверов и
  необработанный stdout/stderr старого конфига. `config:snapshot`/`config:migrate`
  маскируют ключи по `ConfigSchema.secret`; DB-диагностика не отдаёт `config`.
- Длительный поток `logs:tail --follow` несовместим с `--json`.

## Оформление команд

- Используйте `Output::title()` для заголовка, `section()` для группы,
  `muted()` для подсказок, `success()`, `warning()`, `info()`, `status()` для состояний.
  Не вставляйте ANSI escape-последовательности в команды.
- В терминале цвета включаются автоматически (в Windows — VT100); в файле и pipe
  отключены. Учитываются `NO_COLOR`, `TERM=dumb`, `--ansi`, `--no-ansi`.
- Таблицы, подсказки и статусы переносятся по `COLUMNS` (40–200, по умолчанию 80).
  `line()`/`write()` оставлены для точного вывода, например строк журнала.
- Оформление не должно менять `result()` и JSON-контракт. Цвет дополняет
  текстовую метку, а не заменяет её. Пользовательские ANSI/управляющие символы
  удаляются из таблиц, статусов и обычных строк.
- Диагностика по умолчанию компактна; полные подсказки — с `--verbose`.

## Деплой, миграции и systemd

| Команда | Назначение |
| --- | --- |
| `migrations:status` | применённые/ожидающие файловые миграции, `--pending` |
| `migrations:run` | применить все отсутствующие версии; `--pending` только показать |
| `maintenance:on` | включить `cms/maintenance.json`; `--reason`, `--ttl`, `--message` |
| `maintenance:off` | выключить режим и снять `cms/cycles/deploy.hold` |
| `maintenance:status` | состояние маркера и ошибки |
| `systemd:set` | создать unit `majordomo-<user>`; `--apply`, `--disable-old`, `--fix-owner`, `--dry-run` |
| `systemd:give-permissions` | wrapper + sudoers, только точные действия `systemctl` |

`migrations:*` требуют БД и загружают штатный config/loader через
`Context::migrations()`. `maintenance:*` работают без БД. `systemd:*` реально
меняют ОС только от root; `--dry-run` доступен без root и печатает unit,
wrapper и sudoers. Реальные команды проверяются на изолированных временных
каталогах, а не на рабочем `/etc`.

## Пользователи и пароли

`users` — источник пользователей и их прав. Команды `users:list` и
`users:password <id>` вызывают `UserAccounts`, не подключая legacy loader.
Продуктивное подключение — mysqli из Config; PDO нужен только изолированным
SQLite-тестам и не является новым требованием к установке.

- Список возвращает только ID, USERNAME, NAME, IS_ADMIN, IS_DEFAULT; PASSWORD
  не читается и не возвращается. Поиск выполняется с подготовленными параметрами.
- Смена пароля — один подготовленный UPDATE по первичному ID, без изменения прав
  и остальных полей. Сохранён действующий контракт `hash('sha512', $password)`:
  модуль users и установщик используют его же. Менять формат только в CLI нельзя.
- Пароль вводится скрыто дважды через `TerminalInput`. При отсутствии терминала
  нужен `--password-stdin`; для JSON этот флаг обязателен. В аргументах CLI,
  Output, исключениях, журнале и временных файлах пароль и хеш не допускаются.
- Скрытый ввод не имеет fallback на открытый ввод. Unix использует stty с
  восстановлением состояния в finally (при наличии pcntl также обрабатываются
  сигналы отмены); Windows — Console.ReadKey(true) в PowerShell с pipe в память.
- Для тестов Application принимает ленивую фабрику UserAccounts и TerminalInput.
  Тестовый hiddenReader — только подмена ввода, реальные команды его не создают.

## Загрузка и побочные эффекты

- Конструкторы и декларации команд не должны читать конфигурацию, обращаться к
  сервисам или создавать файлы: `list`, `help`, `about` работают без `.env` и БД.
- `$context->config()` вызывайте только в `execute()`, когда нужны настройки.
  `Config::boot()` может пересобрать кеш в `cms/cached`; конфигурация по-прежнему
  загружается через существующий Config, с поддержкой окружения и `.env.local`.
- Не подключайте `lib/loader.php` или `load_settings.php`: там HTTP/legacy boot,
  глобалы и побочные действия. Подключайте только необходимые сервисы ядра.
- Команды циклов пишут желаемое состояние через `CycleManager`; никаких `kill`,
  `taskkill`, прямого запуска `cycle_*.php` или изменения legacy-флагов вручную.
  Код `0` означает сохранённый запрос; JSON содержит `accepted=true`, `applied=false`.
  Проверка реального состояния — отдельная `cycles:status`.
- `cycles:status` читает runtime одним снимком. При недоступном хранилище выдаёт
  ошибку, а не выдуманные состояния. `--offline` не обращается к БД/Redis и явно
  показывает `unknown`. Параметры Redis из таблицы settings учитываются как в ядре,
  с приоритетом соответствующих ключей Config.
- `system:reload --managed` только запрашивает выход живого супервизора. Повторный
  запуск обеспечивает менеджер службы; наличие systemd команда не предполагает.
- `CycleStateStore` обязан выбросить исключение при ошибке блокировки/записи:
  недопустимо подтверждать команду, которую не удалось сохранить.
- Новые подтверждения/флаги записи добавляйте по реальным побочным эффектам.
  Не требуйте интерактивный ввод от read-only команд.

## Тестирование

```bash
php -l majordomo
php -l lib/core/Console/Commands/ExampleStatusCommand.php
php tests/console_smoke.php
php tests/console_users_smoke.php
php tests/settings_cycles_smoke.php
php tests/routing_smoke.php
php tests/config_smoke.php
```

Тесты CLI создают отдельное дерево в системном temp, включают `APP_ENV=testing`,
запрещают подключения к рабочей БД/Redis и подставляют runtime через callback.
Тесты персистентного состояния и журналов вообще не используют БД; SQLite им не
нужен. Никогда не проверяйте мутирующие команды на корне рабочего проекта.
Для проверки реального MySQL/Redis нужен отдельный интеграционный стенд.
