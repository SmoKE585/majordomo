---
title: Точки входа и Application
audience: agent
category: core
tags: точки входа, Application, bootstrap, глобалы, nf.php
description: Устройство HTTP-точек входа и порядок загрузки Application, AdminApplication, SiteApplication и InstallApplication.
order: 20
---

# Точки входа и `Application`

Авторизация, сессии и удаление старого фронтенда описаны в [authentication.md](authentication.md). `AuthGate` проверяет доступ до loader, `SiteApplication` оставлен только для редиректа в админку и временных защищённых AJAX-входов.

## Коротко

Здесь описано, как `public/*.php` превращаются в `Application`-наследников и в каком порядке грузятся конфигурация, библиотеки, настройки и сессия. Читайте перед новой точкой входа или правкой boot-последовательности. Нельзя ломать порядок `config → loader → settings`, контракт глобалов (`$session`, `$db`, `$action`) и тонкие адаптеры в `public/`.

Консольная точка входа — `majordomo` в корне: `php majordomo <команда>`.
Она использует отдельный `MajorDoMo\Core\Console\Application` и по умолчанию
не делает HTTP boot, не подключает legacy loader и `load_settings.php`. Полный
рантайм грузит только та команда, которой он действительно нужен (например,
`modes:set` через `Context::modes()`). Правила добавления команд: [console.md](console.md).

Документ для агентов, которые добавляют или меняют HTTP-точки входа.
Цель — не возвращать бизнес-логику в `public/*.php`.

## 1. Схема

```
public/admin.php   ─┐
public/index.php   ─┼─> bootstrap.php ─> MajorDoMo\Core\Application
                   │                         ├─ AdminApplication  (panel)
                   │                         └─ SiteApplication   (сайт)

public/nf.php ──> таблица маршрутов ──> public/admin.php или public/index.php
public/install.php ───> InstallApplication (config only, без loader/settings)
public/api.php     ───> ApiApplication (config → доступ/JSON → loader/settings → Dispatcher)
```

- `bootstrap.php` только выставляет `MD_APP_ROOT`, делает `chdir` в корень и
  подключает `lib/core.class.php`: Composer и локальный autoloader `MajorDoMo\Core\*`.
  `config.php` и legacy loader используют тот же файл, поэтому пакеты доступны
  и в CLI-циклах. Конфигурация и БД для подключения автозагрузки не нужны.
- `public/admin.php` и `public/index.php` — тонкие адаптеры по 5–10 строк.
  Логики в них быть не должно.
- [lib/core/Application.php](../../lib/core/Application.php) — общий boot:
  `config.php` → `lib/loader.php` → `load_settings.php`, плюс таймеры и
  создание сессии.
- [lib/core/AdminApplication.php](../../lib/core/AdminApplication.php) — админка:
  канонизация URL, `part_load`, `panel`, language-плейсхолдеры, вывод.
- [lib/core/SiteApplication.php](../../lib/core/SiteApplication.php) — сайт:
  `application`, 404, postprocess, вывод.

`nf.php` остаётся процедурным роутером: он сам поднимает `config.php` и
`lib/loader.php`, находит маршрут по таблице `lib/admin_router.php`, а затем
подключает тонкие `admin.php` / `index.php`. Повторная загрузка тех же файлов
внутри `Application` идёт через `require_once`, поэтому двойной инициализации нет,
но порядок «сначала роутинг, потом boot приложения» отличается от прямого
`/admin.php`.

## 2. Порядок загрузки

`Application::bootConfiguration()`:

1. `lib/perfmonitor.class.php` + `startMeasure('TOTAL')`;
2. `config.php` (или старый конфиг с константами — он тоже поддерживается);
3. `$restart_threads` / `$aditional_git_urls` остаются глобальными.

`Application::bootLibraries()`:

1. `lib/loader.php` — подключает библиотеки и открывает `$db`;
2. после этого доступны `gr()`, `SQLSelect()`, `DebMes()`, `Core::*`.

Общие PHP-функции подключаются внутри loader через `lib/core/Helpers/bootstrap.php`.
Реализации находятся в `lib/core/Helpers/`; старые библиотечные пути сохранены
как адаптеры для модулей. Описание API — [системные функции](../user/system-functions.md).
Сам helpers bootstrap не открывает соединение с БД и не загружает настройки.

`Application::bootSettings()`:

1. `load_settings.php` — `SETTINGS_*`, timezone, токены WebSocket;
2. может вызвать `exit` (main cycle down) — это ожидаемо. Авторизация уже проверена `AuthGate`; встроенной Basic Auth нет.

`Application::startSession('prj')`:

1. создаёт `session` и кладёт его в `$GLOBALS['session']`;
2. порядок «сессия до/после settings» задаёт наследник:
   - admin: settings → session;
   - site: session → settings.

## 3. Глобалы, которые нельзя потерять

Легаси-модули обращаются к ним через `global`:

| Глобал | Кто использует | Кто обязан выставить |
| --- | --- | --- |
| `$session` | почти все модули, `panel` | `Application::startSession()` |
| `$db` | `objects`, `control_modules` | `lib/loader.php` |
| `$md` | шаблоны, модули | `AdminApplication` / `SiteApplication` |
| `$action` | `panel`, `control_modules` | роутер `nf.php` или `AdminApplication` |
| `$view_mode` | модули | роутер/`$_REQUEST` (как раньше) |
| `$fake_doc` | `AdminApplication`, `SiteApplication` | `nf.php` |
| `$filterblock` | postprocess | никто (обычно пусто) |
| `$wsClient` | `index.php` при закрытии | `lib/core/Helpers/WebSockets.php` через адаптер `lib/caching.class.php` |

Если переносите код в метод, объявляйте `global $session` и т.п. **до**
использования. Иначе `panel::run()` создаст вторую сессию и сломает
пользовательские сценарии.

## 4. Как добавить новую точку входа

1. Создайте класс в `lib/core`, например `CronApplication extends Application`.
2. Реализуйте `run(): void`; вызывайте `bootConfiguration()`,
   `bootLibraries()`, `bootSettings()` в нужном порядке.
3. Публичный файл оставьте адаптером:

   ```php
   <?php
   require dirname(__DIR__) . '/bootstrap.php';
   (new \MajorDoMo\Core\CronApplication(MD_APP_ROOT))->run();
   ```

4. Не добавляйте в `public/*.php` `include config.php`, `include loader.php`
   или `include load_settings.php` — это снова размажет boot.
5. Проверьте `global $session`, вывод, `exit`-пути и таймеры.

### Мастер первого запуска

`public/install.php` → `MajorDoMo\Core\InstallApplication`. Это осознанное
исключение из обычного порядка: вызывается только `bootConfiguration()`, без
`lib/loader.php` и `load_settings.php`. Причина — база может быть недоступна или
ещё не содержать таблиц, а мастер обязан показать диагностику и форму, а не
`Fatal error`. `InstallGate` при этом подключён в обычных `AdminApplication`,
`SiteApplication`, `ApiApplication` (через `public/api.php`), `objects/index.php`
и `lib/loader.php`, поэтому до завершения настройки пользователь не попадёт в
остальные разделы. Подробно — [`install.md`](install.md).

## 5. Правила для правок

- `public/admin.php` и `public/index.php` не должны расти. Новый код — в
  `AdminApplication`/`SiteApplication` или в отдельных сервисах.
- Не создавайте вторую сессию: используйте `startSession()`.
- Не дублируйте `startMeasure('TOTAL')`: его ставит `bootConfiguration()`.
- Сохраняйте порядок `config → loader → settings`: от него зависят константы,
  `$db` и `SETTINGS_*`.
- `exit` внутри `load_settings.php` — часть контракта; не убирайте.
- Вывод и `ob_start("ob_gzhandler")` остаются в наследнике: у админки и сайта
  разные заголовки и postprocess.
- Для новых сервисов используйте `Core::*`, а не новые глобалы.

## 6. Что ещё процедурное

Пока не переведены:

- `public/nf.php` — роутер pretty URL и legacy `pd` (сам поднимает config и loader);
- `public/404.php` — рендер 404;
- `objects/index.php` — обработчик объектов;
- `cycle.php` и `scripts/*` — CLI-циклы.

`public/api.php` уже переведён в тонкий адаптер `ApiApplication`:
маршруты, JSON, сети и токены находятся в `lib/core/Api/`, см. [api.md](api.md).

Их можно переводить по одному на `Application`-наследников. Начинать лучше с
`nf.php`: он уже подключает тонкие `admin.php`/`index.php`, осталось вынести
таблицу маршрутов и 404 в `RouterApplication`.

## 7. Проверка

```bash
php -l lib/core/Application.php
php -l lib/core/AdminApplication.php
php -l lib/core/SiteApplication.php
php -l public/admin.php
php -l public/index.php
php tests/routing_smoke.php
php tests/config_smoke.php
```

Вручную:

- `/panel/`, `/panel/objects.html`, `/panel/settings.html` — 200, без
  Fatal/Warning/Undefined constant;
- `/admin.php?action=settings` — 302 на `/panel/settings.html`;
- `/panel/objects.html?part_load=1` — JSON;
- `/nonexistent` и `/panel/does_not_exist.html` — 404;
- `/` — тот же редирект/страница, что и до рефакторинга.
