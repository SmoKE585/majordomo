# Routing в MajorDoMo: `index.php` и `nf.php`

## 1) Общая картина

Веб-вход строится в 2 слоя:

1. Прямой вход в `index.php` (обычный запуск приложения).
2. Вход через `nf.php` для "человеко-читаемых" URL (в основном `.html`, часть `.xml`, и `module/app_mediabrowser.*`), которые сначала парсятся в параметры, а потом передаются в `index.php` или `admin.php`.

Файлы, задающие поведение:
- `docs/deploy/nginx.conf.sample` (rewrite- и access-правила веб-сервера: `api.php`/`nf.php`)
- `nf.php` (роут-таблица regex -> внутренние параметры)
- `index.php` (основной lifecycle приложения)
- `admin.php` (админ-ветка для `/panel/...`)

## 2) Что делает `index.php`

`index.php`:

1. Загружает базовую инфраструктуру:
   - `config.php`
   - `lib/loader.php`
   - `load_settings.php`
   - `modules/application.class.php`
2. Инициализирует сессию: `new session("prj")`.
3. Создает `application`, восстанавливает/читает параметры (`restoreParams()`/`getParams()`).
4. Выполняет `$app->run()`.
5. После выполнения делает postprocess:
   - `postprocess_general.inc.php`
   - `postprocess_subscriptions.inc.php`
   - `postprocess_result.inc.php`
6. Отдает `200 OK`, `Content-Type: text/html; charset=utf-8`, `Access-Control-Allow-Origin: *`.
7. Пишет результат порциями (`echobig`), сохраняет cache при необходимости, сохраняет сессию, закрывает ws-клиент.

Важно: `index.php` не занимается разбором красивых URL сам. Он работает с уже подготовленными параметрами (`$_REQUEST` + внутренний `pd`).

## 3) Что делает `nf.php`

`nf.php` — обработчик "not found"/friendly URL.

### Предфильтры

1. Если URI не оканчивается `/`, берется basename как `$file`.
2. Если расширение `.jpg`, `.gif`, `.css` -> сразу `404` и `exit`.
3. Если URI содержит `/ajax/` -> `ALLOW_RUNNING_WITH_ERRORS`.
4. Отрезается query-string в отдельный `$redir_qry` (по факту дальше не используется).
5. У `$file` удаляется хвост `.htm...`, значение кладется в `$fake_doc`.

### Таблица маршрутов

В массиве `$requests` описаны regex-шаблоны (с учетом `ROOTHTML`) и целевые внутренние ссылки вида:
- `?(application:{...})`
- `?(panel:{...})&md=...`
- комбинации для вложенных контекстов (`classes:{...}` и т.п.)

Ключевые группы маршрутов:
- `/panel/...` (админские страницы, объекты, классы, устройства и т.д.)
- `/pages...`, `/popup/...`, `/apps...`, `/ajax/...`
- `/page/<name>.html`
- `/docs/<id>.html`
- `/<slug>.html` -> docs по `doc_name`

### Преобразование в параметры

После совпадения regex:
1. Подстановка групп `\1`, `\2`, ...
2. `module()->parseLinks("<a href=\"$link\">")`
3. Из результата вытаскивается `pd` и доп.параметры.
4. Заполняется `$_REQUEST[...]` и `$_REQUEST['pd']`.

Если маршрут не найден -> `404 Not Found` + текст со ссылкой на `/`.

### Куда передается управление

- Если URI начинается с `${ROOTHTML}panel/` -> `include_once("admin.php")`
- Иначе -> `include_once("index.php")`

Итого: `nf.php` — это адаптер URL -> внутренний формат параметров MajorDoMo.

## 4) Связка с nginx

Веб-сервер — только nginx: все rewrite- и access-правила лежат в `docs/deploy/nginx.conf.sample` и копируются в конфиг сайта nginx. Конфигурации `.htaccess` в проекте больше нет.

Порядок правил:

1. `location ~* pda$` -> redirect на `/popup/pda.html`
2. `location ^~ /api/` -> `rewrite ^ /api.php last`
3. Если не существует файл/директория и URI оканчивается на `.html` -> `nf.php`
4. Если не существует файл/директория и URI подходит `/module/app_mediabrowser\..*` -> `nf.php`
5. Если не существует файл/директория и URI оканчивается на `.xml` -> `nf.php`
6. Остальное -> `index.php`
7. Запреты: `.env`, `config.php`, `debmes.txt`, `lib/`, `templates/*.html`, dotfiles, расширения `.sql/.log/.sh/...`

Именно веб-сервер решает, когда вызывать `nf.php`, поэтому конфиг nginx — часть контракта роутинга.

## 5) Конфиг nginx

Канонический конфиг — `docs/deploy/nginx.conf.sample`; в документации он не дублируется, чтобы не расходиться с реальным файлом. Под хост правятся только:

- `listen` / `server_name`;
- `root`;
- `fastcgi_pass` (unix-сокет php-fpm или `127.0.0.1:9000`) и путь к сокету под установленную версию PHP.

Примечания:
- Важен приоритет `location`: `^~ /api/` и regex для `app_mediabrowser`/`.html|.xml` должны срабатывать до общего fallback.
- `nf.php` должен получать исходный `REQUEST_URI`, потому что он матчится по `$_SERVER["REQUEST_URI"]`.
- Проверка: `nginx -t && systemctl reload nginx`.

## 6) Что учесть при будущем рефакторе роутинга

1. Таблица `$requests` в `nf.php` — центр legacy-совместимости URL.
2. Много административных deep-link маршрутов завязаны на точный формат query-параметров (`md`, `view_mode`, `id`, `tab`, `pd`).
3. Для безопасного рефактора лучше сначала покрыть smoke-тестами:
   - `/panel/*.html`
   - `/popup/*.html`
   - `/apps*.html`
   - `/docs/*.html`
   - fallback `/<slug>.html`
4. После этого можно выносить mapping из массива в более формальную структуру (например, отдельный routing config), но сохраняя текущие regex.

## 7) Smoke-тесты роутинга (добавлено)

Для снижения риска перед рефактором добавлен локальный контрактный smoke-скрипт:

- [tests/routing_smoke.php](</C:/Проекты/_Умный дом (проекты)/___majordomo/tests/routing_smoke.php>)

Что проверяет:
- парсинг роут-таблицы `$requests` из `nf.php`;
- набор ключевых friendly URL -> ожидаемый internal link;
- ожидаемую точку входа (`admin.php` для `/panel/...`, `index.php` для остальных);
- наличие критичных правил в `docs/deploy/nginx.conf.sample` (`/api/`, `.html`, `.xml`, `app_mediabrowser`, `nf.php`, защита `lib/`, `templates/*.html`, `config.php`, `.env`);
- отсутствие `.htaccess` в дереве проекта — веб-сервер настраивается только через nginx.

Запуск:

```bash
php tests/routing_smoke.php
```

Ожидаемый успешный результат:

```text
Routing smoke tests passed (5 route cases + 10 nginx checks)
```

### Исправление в `nf.php` (2026-05-31)

Исправлены regex-маршруты с лишним экранированием слеша:
- `.../object/...\\/methods...` -> `.../object/.../methods...`
- `.../elements/...\\/state...` -> `.../elements/.../state...`

После правки deep-link вида `/panel/class/<class_id>/object/<object_id>/methods/<method_id>.html` корректно матчится.  
Smoke-тест теперь проверяет именно этот маршрут.
