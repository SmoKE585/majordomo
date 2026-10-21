# Точки входа и `Application`

Документ для агентов, которые добавляют или меняют HTTP-точки входа.
Цель — не возвращать бизнес-логику в `public/*.php`.

## 1. Схема

```
public/admin.php   ─┐
public/index.php   ─┼─> bootstrap.php ─> MajorDoMo\Core\Application
public/nf.php      ─┘                         │
                                              ├─ AdminApplication  (panel)
                                              └─ SiteApplication   (сайт)
```

- `bootstrap.php` только выставляет `MD_APP_ROOT`, делает `chdir` в корень и
  регистрирует autoloader `MajorDoMo\Core\*`.
- `public/admin.php` и `public/index.php` — тонкие адаптеры по 5–10 строк.
  Логики в них быть не должно.
- [lib/core/Application.php](../../lib/core/Application.php) — общий boot:
  `config.php` → `lib/loader.php` → `load_settings.php`, плюс таймеры и
  создание сессии.
- [lib/core/AdminApplication.php](../../lib/core/AdminApplication.php) — админка:
  канонизация URL, `part_load`, `panel`, language-плейсхолдеры, вывод.
- [lib/core/SiteApplication.php](../../lib/core/SiteApplication.php) — сайт:
  `application`, 404, postprocess, вывод.

`nf.php` пока остаётся процедурным роутером, но для `/panel/...` и для
сайтовых маршрутов он подключает те же тонкие `admin.php` / `index.php`,
поэтому жизненный цикл один и тот же.

## 2. Порядок загрузки

`Application::bootConfiguration()`:

1. `lib/perfmonitor.class.php` + `startMeasure('TOTAL')`;
2. `config.php` (или старый конфиг с константами — он тоже поддерживается);
3. `$restart_threads` / `$aditional_git_urls` остаются глобальными.

`Application::bootLibraries()`:

1. `lib/loader.php` — подключает библиотеки и открывает `$db`;
2. после этого доступны `gr()`, `SQLSelect()`, `DebMes()`, `Core::*`.

`Application::bootSettings()`:

1. `load_settings.php` — `SETTINGS_*`, timezone, basic auth;
2. может вызвать `exit` (main cycle down, 401) — это ожидаемо.

`Application::startSession('prj')`:

1. создаёт `session` и кладёт его в `$GLOBALS['session']`;
2. порядок «сессия до/после settings» задаёт наследник:
   - admin: settings → session;
   - site: session → settings.

## 3. Глобалы, которые нельзя потерять

Легаси-модули обращаются к ним через `global`:

| Глобал | Кто использует | Кто обязан выставить |
| --- | --- | --- |
| `$session` | почти все модули, `panel`, `control_access` | `Application::startSession()` |
| `$db` | `objects`, `control_modules` | `lib/loader.php` |
| `$md` | `jTemplate`, модули | `AdminApplication` / `SiteApplication` |
| `$action` | `panel`, `control_access`, `control_modules` | роутер `nf.php` или `AdminApplication` |
| `$view_mode` | модули | роутер/`$_REQUEST` (как раньше) |
| `$fake_doc` | `AdminApplication`, `SiteApplication` | `nf.php` |
| `$filterblock` | postprocess | никто (обычно пусто) |
| `$wsClient` | `index.php` при закрытии | `lib/caching.class.php` |

Если переносите код в метод, объявляйте `global $session` и т.п. **до**
использования. Иначе `panel::run()` создаст вторую сессию и логин сломается.

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

- `public/nf.php` — роутер pretty URL и legacy `pd`;
- `public/api.php` — REST API;
- `public/404.php` — рендер 404;
- `objects/index.php` — обработчик объектов;
- `cycle.php` и `scripts/*` — CLI-циклы.

Их можно переводить по одному на `Application`-наследников. Начинать лучше с
`nf.php`: он уже подключает тонкие `admin.php`/`index.php`, осталось вынести
таблицу маршрутов и 404 в `RouterApplication`.

## 7. Проверка

```bash
php -l lib/core/Application.php
php -l lib/core/AdminApplication.php
php -l lib/core/SiteApplication.php
php -l public/admin.php public/index.php
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
