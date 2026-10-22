---
title: "История маршрутизации: index.php и nf.php"
audience: agent
category: api
tags: маршрутизация, nf.php, index.php, история, nginx, smoke-тесты
description: Исторический разбор двухслойного веб-входа MajorDoMo и того, что из него перешло в современные SiteApplication, AdminApplication и lib/admin_router.php.
order: 90
---

# История маршрутизации: `index.php` и `nf.php`

> **Статус: исторический разбор.** Документ описывает, как были устроены
> `index.php` и `nf.php` до перехода на `SiteApplication`/`AdminApplication`
> и единую таблицу `lib/admin_router.php`. Актуальное поведение, таблица
> маршрутов, правила 302/404 и nginx-контракт — в
> [admin-routing.md](../agents/admin-routing.md). Материал сохранён как история решений;
> не используйте его как инструкцию по текущему коду.

**Коротко:** раньше веб-вход строился из двух слоёв — `index.php` для приложения и `nf.php` для «человекочитаемых» URL. Сейчас обе точки входа остались тонкими адаптерами, а разбор URL и общая таблица маршрутов переехали в `lib/admin_router.php`. Ниже — как это работало и что сохранилось.

## 1. Общая картина (на момент разбора)

Веб-вход строился в два слоя:

1. Прямой вход в `index.php` — обычный запуск приложения.
2. Вход через `nf.php` для «человекочитаемых» URL (в основном `.html`, часть `.xml`
   и `module/app_mediabrowser.*`): URL разбирался в параметры и передавался в
   `index.php` или `admin.php`.

Файлы, задававшие поведение:

- `docs/deploy/nginx.conf.sample` — rewrite- и access-правила веб-сервера;
- `nf.php` — таблица regex → внутренние параметры и 404;
- `index.php` — жизненный цикл приложения;
- `admin.php` — админ-ветка для `/panel/...`.

Сейчас `public/index.php` и `public/admin.php` — тонкие адаптеры
`SiteApplication` и `AdminApplication`; `public/nf.php` разбирает pretty URL через
`mdj_admin_route_table()`.

## 2. Что делал `index.php` (исторически)

`index.php`:

1. Загружал базовую инфраструктуру: `config.php`, `lib/loader.php`,
   `load_settings.php`, `modules/application.class.php`.
2. Инициализировал сессию `new session("prj")`.
3. Создавал `application`, восстанавливал или читал параметры
   (`restoreParams()`/`getParams()`).
4. Выполнял `$app->run()`.
5. После выполнения запускал postprocess: `postprocess_general.inc.php`,
   `postprocess_subscriptions.inc.php`, `postprocess_result.inc.php`.
6. Отдавал `200 OK`, `Content-Type: text/html; charset=utf-8`,
   `Access-Control-Allow-Origin: *`.
7. Писал результат порциями (`echobig`), при необходимости сохранял кэш и сессию,
   закрывал ws-клиент.

Важно: `index.php` не разбирал красивые URL сам. Он работал с уже
подготовленными параметрами (`$_REQUEST` + внутренний `pd`).

Сейчас этот жизненный цикл перенесён в `MajorDoMo\Core\SiteApplication`, а
неизвестные пути сайта получают настоящий 404, а не тихий редирект на дашборд.

## 3. Что делал `nf.php`

`nf.php` — обработчик «not found» и friendly URL.

### Предфильтры (сохранились)

1. Если URI не оканчивается `/`, берётся basename как `$file`.
2. Если расширение `.jpg`, `.gif`, `.css` — сразу `404` и `exit`.
3. Если URI содержит `/ajax/` — определяется `ALLOW_RUNNING_WITH_ERRORS`.
4. Query-строка отрезается в `$redir_qry` (дальше фактически не использовалась).
5. У `$file` удаляется хвост `.htm...`, значение кладётся в `$fake_doc`.

### Таблица маршрутов (переехала)

Исторически в `nf.php` был массив `$requests` с regex-шаблонами (с учётом
`ROOTHTML`) и целевыми внутренними ссылками вида:

- `?(application:{...})`;
- `?(panel:{...})&md=...`;
- комбинации для вложенных контекстов (`classes:{...}` и т.п.).

Ключевые группы: `/panel/...`, `/pages...`, `/popup/...`, `/apps...`,
`/ajax/...`, `/page/<name>.html`, `/docs/<id>.html`, `/<slug>.html` → docs по
`doc_name`.

Сейчас эта таблица вынесена в `mdj_admin_route_table()` (`lib/admin_router.php`)
и используется одновременно для входящих URL и для построения ссылок. Прямого
массива `$requests` в `nf.php` больше нет.

### Преобразование в параметры (сохранилось)

После совпадения regex:

1. Подставляются группы `\1`, `\2`, ...
2. Вызывается `module()->parseLinks("<a href=\"$link\">")`.
3. Из результата извлекаются `pd` и дополнительные параметры.
4. Заполняются `$_REQUEST[...]` и `$_REQUEST['pd']`.

Если маршрут не найден — `lib/error_page.php` отдаёт 404 (HTML или JSON), а не
старый текстовый ответ со ссылкой на `/`.

### Куда передавалось управление

- Если URI начинается с `${ROOTHTML}panel/` — `include_once("admin.php")`.
- Иначе — `include_once("index.php")`.

Это правило сохранилось: `public/nf.php` до сих пор выбирает `admin.php` или
`index.php` по префиксу `/panel/`.

## 4. Связка с nginx

Веб-сервер — только nginx: rewrite- и access-правила лежат в
`docs/deploy/nginx.conf.sample`. `.htaccess` в проекте нет.

Исторический порядок правил (частично изменился):

1. `location ~* pda$` → redirect на `/popup/pda.html`.
2. `location ^~ /api/` → `rewrite ^ /api.php last`.
3. Не существует файл/каталог и URI оканчивается на `.html` → `nf.php`.
4. Не существует файл/каталог и URI подходит `/module/app_mediabrowser\..*` → `nf.php`.
5. Не существует файл/каталог и URI оканчивается на `.xml` → `nf.php`.
6. Остальное → `index.php`.
7. Запреты: dotfiles, служебные расширения, каталоги вне document root.

**Что изменилось:** отдельного правила `pda` в актуальном примере больше нет, а
`.html` и `.xml` обрабатываются одним regex `\.(html|xml)$`. API больше не
переписывается через `rewrite`: в актуальном примере nginx для `/api/` и
`/api.php/` стоит отдельный FastCGI-location без редиректа — он сохраняет
исходный URI, передаёт `Authorization`, `X-API-Key` и `X-Forwarded-For`,
отключает перехват ошибок и ограничивает тело 1 МиБ. Для `/panel/` используется
`try_files $uri $uri/ /nf.php?$query_string`, а для 404 — `error_page 404 /404.php`.

Именно веб-сервер решает, когда вызывать `nf.php`, поэтому конфиг nginx — часть
контракта роутинга.

## 5. Конфиг nginx

Канонический конфиг — `docs/deploy/nginx.conf.sample`; в документации он не
дублируется, чтобы не расходиться с реальным файлом. Под хост правятся только:

- `listen` / `server_name`;
- `root`;
- `fastcgi_pass` (unix-сокет php-fpm или `127.0.0.1:9000`) и путь к сокету под
  установленную версию PHP.

Примечания:

- Важен приоритет `location`: `/panel/` и API-локация должны срабатывать до
  общего fallback.
- `nf.php` должен получать исходный `REQUEST_URI`, потому что он матчится по
  `$_SERVER["REQUEST_URI"]`.
- Проверка: `nginx -t && systemctl reload nginx`.

## 6. Что учесть при будущем рефакторе роутинга

1. Таблица маршрутов — центр legacy-совместимости URL; теперь она одна на
   входящие и исходящие ссылки.
2. Многие административные deep-link завязаны на точный формат query-параметров
   (`md`, `view_mode`, `id`, `tab`, `pd`).
3. Для безопасного рефактора сначала нужны smoke-тесты: `/panel/*.html`,
   `/popup/*.html`, `/apps*.html`, `/docs/*.html`, fallback `/<slug>.html`.
4. После этого можно менять внутреннее представление mapping, сохраняя regex и
   round-trip-проверку.

## 7. Smoke-тесты роутинга

Контрактный smoke-скрипт — `tests/routing_smoke.php`. Он проверяет:

- разбор таблицы маршрутов и набор ключевых friendly URL → ожидаемое состояние;
- ожидаемую точку входа (`admin.php` для `/panel/...`, `index.php` для остальных);
- наличие критичных правил в `docs/deploy/nginx.conf.sample` (`/api/`, `.html`,
  `.xml`, `app_mediabrowser`, `nf.php`, защита служебных файлов);
- отсутствие `.htaccess` в дереве проекта.

Запуск:

```bash
php tests/routing_smoke.php
```

Актуальный ожидаемый результат — строка вида
`Routing smoke tests passed (62 route cases + 21 nginx checks)`; число кейсов
меняется вместе с таблицей маршрутов.

### Историческое исправление в `nf.php` (2026-05-31)

Когда таблица ещё жила в `nf.php`, были исправлены regex-маршруты с лишним
экранированием слеша:

- `.../object/...\\/methods...` → `.../object/.../methods...`;
- `.../elements/...\\/state...` → `.../elements/.../state...`.

После правки deep-link вида
`/panel/class/<class_id>/object/<object_id>/methods/<method_id>.html` корректно
матчился. Сейчас это покрывает общий smoke-тест маршрутов.
