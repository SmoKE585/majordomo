---
title: Структура проекта
audience: agent
category: general
tags: структура, каталоги, public, nginx, URL
description: Раскладка каталогов MajorDoMo, соответствие URL файлам, правила nginx и локального dev-сервера.
order: 10
---

# Структура проекта

## Коротко

Это карта каталогов и таблица «URL → файл» после перехода на `public/` как document root. Читайте перед добавлением точки входа, ассета или nginx-правила. Нельзя переносить `modules/`, `cms/`, `scripts/`, `packages/`, `vendor/` и `docs/` внутрь `public/`. Системные каталоги закрыты по HTTP, `/objects/` и `3rdparty/` удалены.

Раскладка каталогов после перехода на `public/` как document root (структура
«а-ля Laravel»): веб-точки входа и статика лежат в `public/`, код приложения —
уровнем выше, а каталоги, которые обязаны сохранить свои URL, смонтированы
из корня проекта через `root`-override в nginx.

## 1. Каталоги

```
<project>/
├── public/                     # document root (только это отдаётся по умолчанию)
│   ├── index.php               # главная точка входа (сайт)
│   ├── admin.php               # админка, part-load, канонизация URL
│   ├── nf.php                  # pretty URL, legacy pd, 404
│   ├── api.php                 # REST API
│   ├── install.php             # мастер первичной настройки
│   ├── 404.php                 # error_page 404
│   ├── favicon.ico  robots.txt
│   └── assets/                 # публикуется как /assets/
│       ├── admin/              # Vite-бандл (собирается, коммитится)
│       ├── install/            # page-only CSS/JS мастера
│       ├── css/                # legacy-стили: stl.css, темы, jQuery UI
│       ├── js/                 # legacy-скрипты: scripts.js, panel.js
│       ├── img/                # иконки, логотипы, флаги, иконки модулей
│       ├── vendor/             # браузерные библиотеки (bootstrap, jquery, fancybox)
│       └── modules/<name>/     # CSS/JS страниц конкретного модуля
├── bootstrap.php               # chdir в корень приложения для public-входов
├── composer.json / composer.lock # PHP-зависимости и зафиксированные версии
├── vendor/                     # Composer; не коммитится, вне document root
├── .env / .env.example         # конфигурация (.env не коммитится)
├── config.php                  # шим: Config::boot + легаси-глобалы
├── load_settings.php           # include, не точка входа
├── cycle.php                   # CLI-цикл (systemd), вне document root
├── dev_server.php              # локальный сервер, повторяет контракт nginx
├── resources/                  # исходники Vite, в бандл, наружу не отдаются
│   ├── css/                    # admin_panel.css, ui-v2.css, ui-system.css, codeeditor.css, codehighlight.css
│   └── js/                     # app.js, bootstrap.js, charts.js, modules/, codeeditor/, codehighlight/
├── docs/                       # документация и деплой-конфиги
│   ├── README.md  agents/  user/  deploy/  dev/  archive/  ui-redesign/
├── modules/                    # PHP-модули и их веб-эндпоинты (/modules/...)
├── templates/                  # include-only разметка (*.html), PHP-партиалы
│   └── install/                # разметка мастера первичной настройки
├── lib/                        # ядро, include-only PHP
│   ├── core/Install/           # мастер: состояние, gate, .env, схема, schema.sql
│   ├── core/Settings/          # общие настройки: схема, manager, presenter
│   ├── core/Cycles/            # состояние, супервизор и публичный API циклов
│   └── core/Filesystem/        # folder browser для выбора путей в настройках
├── languages/                  # переводы интерфейса
├── cms/                        # рантайм-данные и медиа (/cms/...)
├── backup/                     # резервные копии saverestore (runtime, gitignored)
├── packages/phpmqtt/           # исходники локального Composer-пакета MQTT
├── vendor/                     # Composer-зависимости, без HTTP-доступа
├── scripts/                    # CLI-циклы и служебные скрипты, без HTTP-доступа
├── tools/                      # dev-инструменты (скриншоты, иконки, config, install)
├── tests/                      # smoke/regression: routing, config, install, settings/cycles, core runtime
├── package.json / vite.*.js    # сборка статики
└── AGENTS.md  README.md        # короткие указатели в корне
```

Правило: если файл скачивает браузер — он в `public/assets/`; если файл
компилируется Vite — он в `resources/`; если это точка входа — она в `public/`;
документация и деплой — в `docs/`; всё остальное — код приложения.

## 2. URL → файл

| URL | Откуда отдаётся |
| --- | --- |
| `/` | `public/index.php` |
| `/admin.php`, `/admin.php?...` | `public/admin.php` |
| `/panel/...` (pretty URL) | `public/nf.php` + `lib/admin_router.php` |
| `/install.php` | `public/install.php` (канонический URL мастера) |
| `/install`, `/install/` | nginx: 302 → `/install.php`; `dev_server.php` отдаёт тот же файл |
| `/api/...` | `public/api.php` |
| `/404.php` | `public/404.php` |
| `/assets/...` | `public/assets/...` |
| `/cms/avatars/...`, `/cms/images/...` | `cms/...` (медиа) |
| `/modules/...` | `modules/...` (включая PHP-эндпоинты) |
| `/objects/...`, `/3rdparty/...` | Удалены; используйте `/api/v1/...`. |
| `/scripts/...`, `/vendor/...`, `/packages/...` | HTTP-доступ закрыт. |

| `/docs/...` | `docs/...` (UI Kit ссылается на документацию) |
| `/css`, `/js`, `/img/...` | 301 → `/assets/...` |

| `/templates/<module>/*.css`, `*.js` | 301 → `/assets/modules/<module>/...` |
| `/public/...` | 301 → `/...` (старый префикс docroot) |
| `/rc/...` | 301 → `/panel/` (функция удалена) |

Вне document root и недоступны по HTTP: `.env`, `config.php`, `bootstrap.php`,
`load_settings.php`, `lib/`, `scripts/`, `packages/`, `vendor/`, `cycle.php`, `tools/`.

Приватные подкаталоги `cms/` (`cached`, `debmes`, `modules_installed`,
`saverestore`) закрыты в nginx; `docs/**/*.sql` и скрипты тоже.

## 3. Почему `public/`, а не перенос всего

Document root — только `public/`. Всё, что должно сохранить старые URL,
монтируется из корня проекта:

- `modules/` — маркет ставит модули вместе с их PHP-эндпоинтами
  (`modules/<name>/autosave.php`, `update_iframe.php`, `*.js.php`), поэтому
  каталог и его URL остаются на месте;
- `cms/` — 160+ обращений вида `ROOT . 'cms/...'` в коде и медиа-URL;
- `scripts/` — только системные CLI-процессы; nginx не монтирует каталог;
- PHP-библиотеки загружаются Composer из `vendor/`, локальные исходники — в `packages/`.

Точки входа перенесены в `public/` физически. Так как они используют
относительные include (`./config.php`, `./lib/...`), каждый начинается с
`require dirname(__DIR__) . '/bootstrap.php'`: тот определяет `MD_APP_ROOT`,
делает `chdir()` в корень приложения и подключает `lib/core.class.php`.
Этот общий загрузчик подключает Composer и локальную автозагрузку
`MajorDoMo\Core\*`; `config.php` использует его и для CLI-входов.

С октября 2026 `public/admin.php` и `public/index.php` — тонкие адаптеры:
boot и обработка запроса живут в `lib/core/Application.php`,
`AdminApplication.php` и `SiteApplication.php`. Подробно —
[`entrypoints.md`](entrypoints.md). `nf.php` пока остаётся процедурным
роутером, но подключает те же адаптеры. `php-fpm` запускает запрос с CWD, равным
каталогу скрипта, — это же поведение воспроизводит `dev_server.php`.

## 4. nginx

Полный конфиг — `docs/deploy/nginx.conf.sample` — универсальный: домен, пути и
сокет заменены на пример. Перед деплоем подставьте свои `root`, `server_name`,
сертификаты и сокет php-fpm.

```nginx
root /var/www/example/data/www/example.com/public;
index index.php;

location ^~ /assets/ { expires 30d; access_log off; }

location ^~ /modules/ {
    root /var/www/example/data/www/example.com;
    location ~ [^/]\.php(/|$) {           # PHP внутри ^~-префикса
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        # ...
    }
}
```

Важные детали:

- `^~` у `/modules/`, `/cms/`, `/docs/` и т.п. отключает regex-локации, поэтому
  PHP внутри этих каталогов обрабатывается **вложенной** `location ~ \.php`.
  Если этого не сделать, nginx отдаст исходник `modules/*.php` как текст.
- Редиректы старых ассетов идут до общих regex-локаций статики.
- `$is_args$args` сохраняет `?v=...` при 301.
- `error_page 404 /404.php` теперь указывает на `public/404.php`.
- Системные `scripts/`, `packages/`, `vendor/` закрыты; `/objects/` и `/3rdparty/`
  удалены. Новые внешние операции добавляются в единый контракт API v1.

## 5. Локальная разработка

`php dev_server.php` повторяет контракт nginx:

- document root — `public/`; входные файлы, `/assets/`, `favicon.ico`,
  `robots.txt` берутся оттуда;
- `/modules/`, `/cms/`,
  `/docs/` отдаются из корня проекта, PHP в них выполняется;
- CWD перед запуском скрипта = каталог скрипта (как в php-fpm), поэтому
  `bootstrap.php` и собственные `chdir()` эндпоинтов работают одинаково с продом;
- старые пути (`/css/`, `/js/`, `/img/`, `/templates/<m>/*.css|js`)
  отвечают 301, `/rc/` — 301 на `/panel/`.

## 6. Как добавить ассет

1. Статика страницы модуля → `public/assets/modules/<module>/`, подключается
   только в шаблоне этого модуля (`<#ROOTHTML#>assets/modules/<module>/...`).
2. Общая библиотека → `npm install` + импорт в `resources/js/app.js` (или отдельный
   entry). В `public/assets/vendor/` руками ничего не кладём.
3. Картинка интерфейса → `public/assets/img/...`, ссылка
   `<#ROOTHTML#>assets/img/...`.
4. Новая точка входа → `public/<name>.php` + `require dirname(__DIR__) . '/bootstrap.php'`.

## 7. Проверка

```bash
php -l <изменённые php>
node --check <изменённые js>
npm run build
php tests/routing_smoke.php     # маршруты, nginx-контракт, layout public/
node tools/asset-references.mjs   # отчёт о неиспользуемой статике
nginx -t && systemctl reload nginx
```

`tools/asset-references.mjs` — вспомогательный сканер ссылок на статику.
Отчёт «orphan» — кандидаты на удаление, а не приговор: пути могут собираться
динамически (`img/modules/<name>.png`, `img/flags/<lang>.png`) или приходить из БД.
