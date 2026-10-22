---
title: Конфигурация: .env и Config
audience: agent
category: core
tags: конфигурация, .env, Config, легаси-константы, ConfigSchema
description: Как читать и добавлять настройки через .env и MajorDoMo\Core\Config, не добавляя define() и не ломая легаси-константы.
order: 30
---

# Конфигурация: `.env`, `Config` и легаси-константы

Документ для агентов и контрибьюторов, которые правят модули после переезда
с `config.php` на `.env`. Читать перед любым изменением кода, который читает
настройки.

## 1. Коротко

- Источник истины — `.env` в корне проекта. Полный список ключей с типами и
  вариантами значений — [`.env.example`](../../.env.example).
- `config.php` больше не содержит настроек. Это тонкий шим, который подключают
  старые точки входа; не добавляйте в него ключи.
- Новый код читает значения только через `MajorDoMo\Core\Config`:
  `Config::get('DB_HOST')`, `Config::bool('DEBUG_MODE')` и т.д.
- Легаси-константы (`DB_HOST`, `BASE_URL`, …) продолжают работать, но это слой
  совместимости. Новых констант не добавляем.
- После изменения `.env`: `php tools/config.php clear` и перезапуск циклов.
- Остаток старых обращений: `php tools/config-audit.php`. Отчёт пишется в
  `cms/debmes/config-audit.log`.

## 2. Из чего состоит слой

| Файл | Назначение |
| --- | --- |
| `.env` | Рабочие значения. Не коммитится, не отдаётся по HTTP. |
| `.env.example` | Документированный шаблон. Коммитится, обновляется вместе с кодом. |
| `.env.local` | Локальный override поверх `.env`. Не коммитится. |
| `config.php` | Шим: `Config::boot()` + легаси-глобалы `$restart_threads`, `$aditional_git_urls`. |
| `lib/core/Env.php` | Парсер `.env`. Без зависимостей, без интерполяции. |
| `lib/core/ConfigSchema.php` | Схема: тип, default, optional, secret, required. |
| `lib/core/Config.php` | Разрешение значений, типы, define легаси-констант. |
| `lib/core/ConfigCache.php` | Компиляция в `cms/cached/config.php`, fingerprint, атомарная запись. |
| `tools/config.php` | `doctor`, `cache`, `clear`, `snapshot`, `migrate`, `audit`. |
| `tools/config-audit.php` | Статический отчёт по легаси-константам в модулях. |

Порядок разрешения значения:

1. реальная переменная окружения процесса (`getenv()`, затем `$_SERVER`);
2. `.env.local`;
3. `.env`;
4. уже определённая легаси-константа (старый `config.php`);
5. default/вычисленное значение из схемы.

`$_ENV` не используется: его содержимое зависит от `variables_order`.

## 3. API

```php
use MajorDoMo\Core\Config;

Config::get('DB_HOST');              // mixed, null если ключа нет
Config::string('DB_HOST');           // string
Config::int('LOG_MAX_SIZE');      // int
Config::float('REDIS_TIMEOUT');      // float
Config::bool('DEBUG_MODE');  // bool
Config::arrayValue('RESTART_THREADS'); // array
Config::has('USE_REDIS');            // bool: ключ реально задан
Config::source('DB_HOST');           // env|process|constant|default|computed|derived
Config::safeAll();                   // массив без secret-ключей
```

Правила:

- `get()` возвращает `null`, если optional-ключ не задан. Не полагайтесь на
  пустую строку как на «выключено»: проверяйте `has()`.
- `bool()` понимает `1/true/yes/on` и `0/false/no/off`, но не угадывает
  произвольные строки. Невалидное значение логируется и трактуется как `false`.
- `int()`/`float()` не бросают исключение; для критичных значений проверяйте
  диапазон сами.
- `string()` возвращает `''` для отсутствующего ключа, если не передан default.

## 4. Типы и `.env`

`.env` — всегда строки. Тип задаётся в `ConfigSchema`, а не в месте чтения.

| Тип | Пример | Правило |
| --- | --- | --- |
| `string` | `DB_HOST=localhost` | как есть; кавычки для пробелов и `#` |
| `integer` | `LOG_MAX_SIZE=10485760` | приводится к int |
| `decimal` | `REDIS_TIMEOUT=2.5` | приводится к float |
| `boolean` | `DEBUG_MODE=0` | `1/true/yes/on` → true; `0/false/no/off` → false |
| `host_or_false` | `USE_REDIS=false` | `false/0/off/no/''` → `false`, иначе строка-хост |
| `list` | `RESTART_THREADS=a.php, b.php` | запятая или JSON-массив |
| `json` | `ADITIONAL_GIT_URLS={"url":"title"}` | JSON-объект/массив |

Особые случаи:

- `USE_REDIS` — не bool, а хост или `false`. Никогда не сравнивайте его со
  строкой `'false'`: используйте `Config::get('USE_REDIS')` и проверку на
  truthiness.
- `DEBUG_MODE` теперь проверяется по значению, а не только по `defined()`.
  `DEBUG_MODE=0` действительно выключает отладочные страницы и HTML-комментарии.
- Пути Windows в unquoted-значении сохраняют обратные слэши:
  `PATH_TO_MYSQLDUMP=C:\Program Files\MySQL\bin\mysqldump.exe` лучше заключить
  в кавычки.
- Пароль с `#`, пробелом или `$` — в кавычках: `DB_PASSWORD="p#ss word"`.
- Подстановка `${VAR}` не поддерживается.

Ключи деплоя (`DEPLOY_*`) читаются в `lib/core/Deploy/`:

| Ключ | По умолчанию | Смысл |
| --- | --- | --- |
| `DEPLOY_COMPOSER` | `composer` | путь к Composer или `.phar` |
| `DEPLOY_NODE` | `node` | Node.js 24+ |
| `DEPLOY_NPM` | `npm` | npm |
| `DEPLOY_ASSETS` | `always` | `always` / `auto` / `never` |
| `DEPLOY_SERVICE` | `majordomo-<user>` | имя systemd-службы |
| `DEPLOY_RESTART` | `1` | перезапускать службу после обновления |
| `DEPLOY_TIMEOUT` | `900` | таймаут composer/npm, секунд |

Это машинные настройки, а не параметры модуля: держите их в `.env`, а не в
`project_modules.DATA`.

## 5. Как добавить новый ключ

1. Добавьте запись в `ConfigSchema::all()`:

   ```php
   'MY_FEATURE' => array('type' => 'boolean', 'optional' => true),
   ```

   Флаги: `default`, `optional`, `computed`, `env=false`, `constant=false`,
   `secret`, `required`.
2. Опишите ключ в `.env.example`: что делает, тип, допустимые значения, пример.
3. Читайте его через `Config`:

   ```php
   if (Config::bool('MY_FEATURE')) { ... }
   ```

4. Проверьте:

   ```bash
   php -l lib/core/ConfigSchema.php
   php tools/config.php doctor
   php tests/config_smoke.php
   ```

Не добавляйте `define('MY_FEATURE', ...)` и не создавайте новую константу.
Легаси-константа нужна только если её уже читает сторонний модуль, который
нельзя обновить; это отдельное решение, а не норма.

## 6. Как переводить модуль

Порядок:

1. Найдите обращения:

   ```bash
   php tools/config-audit.php
   ```

   В отчёте — ключ, файл, строка, вид (`read`, `defined`, `constant`,
   `define`). Начните с `read`.
2. Замените чтение:

   ```php
   // было
   $host = DB_HOST;
   $debug = defined('DEBUG_MODE');

   // стало
   $host = Config::string('DB_HOST');
   $debug = Config::bool('DEBUG_MODE');
   ```

3. Замените проверки наличия:

   ```php
   // было
   if (defined('PYTHON_PATH')) { ... }

   // стало
   if (Config::has('PYTHON_PATH')) { ... }
   ```

   Если проверялось значение, а не наличие — `Config::bool()`/`Config::get()`.
4. Уберите локальные `define()` дефолтов: значение уже есть в схеме.

   ```php
   // было
   if (!defined('PATH_TO_FFMPEG')) define('PATH_TO_FFMPEG', 'ffmpeg');

   // стало
   $ffmpeg = Config::string('PATH_TO_FFMPEG', 'ffmpeg');
   ```

   Если локальный default отличается от схемного — сначала обсудите это,
   потом меняйте `.env.example`.
5. Для `null`/пустой строки сохраняйте прежнюю семантику. Не превращайте
   «не задано» в `0` или `''` без проверки всех веток.
6. Секреты (`DB_PASSWORD`, `REDIS_PASSWORD`) не логируйте, не выводите в UI и не
   добавляйте в audit-отчёт.
7. Прогоните `php tools/config-audit.php` повторно: число обращений к этому
   ключу должно уменьшиться.

Структурные константы (`ROOT`, `DOC_ROOT`, `DIR_TEMPLATES`, `DIR_MODULES`,
`ROOTHTML`, `SERVER_ROOT`, `PATH_TO_PHP`) — производные от пути проекта. Audit
показывает их отдельной строкой `structural`; переводить их на `Config` можно,
но это низкий приоритет и не должно менять поведение.

## 7. Что поддерживается, а что нет

Поддерживается:

- легаси-константы для всех ключей, которые заданы в `.env` или были в старом
  `config.php`;
- `config.php` как include-таргет для старых модулей;
- `defined('X')` для optional-ключей: если ключа нет, константа не определена;
- `.env.local` и переменные окружения процесса;
- типизированные значения и кеш.

Не поддерживается:

- перехват обращения к **неизвестной** константе. В PHP неопределённая
  константа — это `Error`, а не магический метод. Мост определяет только
  известные имена из схемы/`.env`. Если сторонний модуль использует константу,
  которой нет в схеме, будет фатал; добавьте ключ в схему или обновите модуль.
- новые константы для новых настроек;
- `getenv()`/`$_ENV` в коде модулей;
- `putenv()`, `define()` для конфигурации;
- интерполяция `${VAR}` в `.env`;
- массивы в легаси-константах (используйте `Config::arrayValue()`).

## 8. Легаси-константы

- Определяются в `Config::defineConstants()` только если ключ присутствует.
- `defined('X')` сохраняет прежний смысл: «настройка задана».
- Удалённые ключи (`PROJECT_BUGTRACK`, `PROJECT_DOMAIN`,
  `GETURL_WARNING_TIMEOUT`, `BTRACED`, `ENABLE_FORK`,
  `DROPBOX_SHOPPING_LIST`, `ONEWIRE_SERVER`) не определяются. Если старый
  модуль их читает — он уже падал бы без `config.php`; при необходимости
  верните ключ в схему, а не в код.
- `MYSQL_BOTH`, `MYSQL_NUM`, `MYSQL_ASSOC` — совместимость с ext/mysql, их
  трогать нельзя.

## 9. Кеш и перезапуск

- Кеш: `cms/cached/config.php`. Содержит распарсенный `.env`, включая секреты.
  Каталог закрыт nginx и dev-сервером; не копируйте файл в репозиторий.
- Fingerprint: SHA-1 содержимого `.env`/`.env.local` + хеш схемы. Изменили `.env` —
  кеш пересоберётся сам; для гарантии: `php tools/config.php clear`.
- `opcache.validate_timestamps=0` в проде: после пересборки нужен reload
  PHP-FPM или `opcache_invalidate` (кеш вызывает его сам при записи).
- Долгоживущие циклы держат константы в памяти. После изменения `.env`
  перезапустите циклы; иначе часть процессов продолжит работать со старыми
  значениями.
- Если `cms/cached` недоступен для записи, конфигурация парсится на каждом
  запросе — это медленнее, но не ломает сайт.

## 10. Логи

| Файл | Что внутри |
| --- | --- |
| `cms/debmes/<дата>/errors.log` | Невалидные значения, неизвестный `TIMEZONE`, прочие проблемы загрузки — записи с меткой `[config]`. |
| `cms/debmes/config-audit.log` | Все оставшиеся обращения к легаси-константам: ключ, вид, файл, строка. |

Логи не содержат значений секретов. Если нужен разбор конкретного модуля —
запустите audit и отфильтруйте по файлу.

## 11. Антипаттерны

```php
// Плохо: обходит типы, кеш и .env.local
$host = getenv('DB_HOST');
$host = $_ENV['DB_HOST'];

// Плохо: новое имя константы
define('MY_SETTING', 'value');

// Плохо: сравнение строки
if (Config::get('DEBUG_MODE') === '1') { ... }

// Плохо: сравнение с 'false' — значение уже приведено к bool/false
if (Config::get('USE_REDIS') === 'false') { ... }

// Хорошо
$host = Config::string('DB_HOST');
if (Config::bool('DEBUG_MODE')) { ... }
if (Config::get('USE_REDIS', false)) { ... }
```

## 12. Чек-лист перед сдачей

- [ ] Новых `define()` и новых констант нет.
- [ ] Чтение только через `Config`.
- [ ] `Config::has()` вместо `defined()`, где проверяется наличие.
- [ ] Типы: `bool`/`int`/`float`, без сравнения со строками.
- [ ] Секреты не логируются и не выводятся.
- [ ] `.env.example` обновлён, если добавлен ключ.
- [ ] `php -l` для изменённых файлов.
- [ ] `php tests/config_smoke.php`.
- [ ] `php tests/routing_smoke.php`, если затронут роутинг/точки входа.
- [ ] `php tools/config-audit.php`: число обращений не выросло.
- [ ] После изменения `.env` — `php tools/config.php clear` и рестарт циклов.

## 13. Частые вопросы

**Почему нельзя «перехватить» константу?**
В PHP нет `__get` для констант. Обращение к неопределённой константе — это
фатальный `Error`, после которого нельзя продолжить выполнение. `runkit7` и
`uopz` требуют расширений, которых нет на обычном хостинге. Поэтому мост
определяет известные имена заранее, а для неизвестных остаётся только
диагностика.

**Почему `config.php` не удалили?**
Старые модули и точки входа подключают его по относительному пути. Шим
сохраняет этот контракт и заодно заполняет `$restart_threads` /
`$aditional_git_urls`.

**Что с `SETTINGS_*`?**
Это пользовательские настройки из таблицы `settings` (тема, язык, время).
Они остаются в БД и определяются в `load_settings.php`; в `.env` их нет.
Приоритет для websocket-токена:
настройка из БД > `.env` > default.

**Как переопределить значение только на этой машине?**
Добавьте его в `.env.local`; он перекрывает `.env`, но не реальные переменные
окружения процесса.

## 14. Обновление существующей установки

Если на сервере ещё лежит старый `config.php` с `Define(...)`:

1. Сделайте резервную копию `config.php` и файлов проекта.
2. Сгенерируйте `.env` из старого файла:

   ```bash
   php tools/config.php migrate --from=config.php --write
   php tools/config.php doctor
   ```

   Команда создаст `.env`, резервную копию `config.php.bak.*` и список
   перенесённых ключей. Секреты в выводе маскируются. Если `.env` уже
   существует, команда откажется его менять — добавьте `--force`.
3. Замените `config.php` шимом из репозитория (файл теперь версионируется):

   ```php
   <?php
   require_once __DIR__ . '/lib/core.class.php';
   \MajorDoMo\Core\Config::boot(__DIR__);
   $restart_threads = \MajorDoMo\Core\Config::arrayValue('RESTART_THREADS');
   $aditional_git_urls = \MajorDoMo\Core\Config::arrayValue('ADITIONAL_GIT_URLS');
   ```

   Если оставить старый `config.php`, новый код продолжит работать: `Config`
   увидит уже определённые константы. Но тогда `.env` не станет источником
   истины, а `doctor` будет предупреждать.
4. Проверьте `php tools/config.php doctor`, откройте `/panel/`, перезапустите
   циклы.
5. Убедитесь, что `.env` и `.env.local` недоступны по HTTP и не попали в
   резервные копии/репозиторий.

Если `git pull` отказывается обновлять `config.php` («untracked working tree
file would be overwritten»), значит на сервере остался старый локальный файл:
сначала выполните шаги 1–2, затем уберите/переименуйте старый `config.php` и
повторите обновление.

