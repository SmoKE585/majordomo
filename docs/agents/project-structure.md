# Структура проекта

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
│   ├── 404.php                 # error_page 404
│   ├── backup.php  command.php  diagnostic.php  print_all.php  write_error.php
│   ├── favicon.ico  robots.txt
│   └── assets/                 # публикуется как /assets/
│       ├── admin/              # Vite-бандл (собирается, коммитится)
│       ├── css/                # legacy-стили: stl.css, темы, jQuery UI
│       ├── js/                 # legacy-скрипты: scripts.js, panel.js
│       ├── img/                # иконки, логотипы, флаги, иконки модулей
│       ├── vendor/             # браузерные библиотеки (bootstrap, jquery, fancybox)
│       └── modules/<name>/     # CSS/JS страниц конкретного модуля
├── bootstrap.php               # chdir в корень приложения для public-входов
├── config.php(.sample)         # конфигурация (config.php не коммитится)
├── load_settings.php           # include, не точка входа
├── cycle.php                   # CLI-цикл (systemd), вне document root
├── dev_server.php              # локальный сервер, повторяет контракт nginx
├── src/admin/                  # исходники Vite, в бандл, наружу не отдаются
│   ├── main.js  charts-entry.js  codeeditor-entry.js
│   ├── css/                    # admin_panel.css, ui-v2.css, ui-system.css, codeeditor.css
│   └── js/                     # modules/admin_panel/*, codeeditor.js, codeeditor-loader.js
├── docs/                       # документация и деплой-конфиги
│   ├── README.md  agents/  user/  deploy/  dev/  archive/  ui-redesign/
├── modules/                    # PHP-модули и их веб-эндпоинты (/modules/...)
├── templates/                  # include-only разметка (*.html), PHP-партиалы
├── lib/                        # ядро, include-only PHP
├── languages/                  # переводы интерфейса
├── cms/                        # рантайм-данные и медиа (/cms/...)
├── backup/                     # резервные копии saverestore (runtime, gitignored)
├── 3rdparty/                   # PHP-библиотеки и elFinder (/3rdparty/...)
├── objects/                    # /objects/?script=…
├── scripts/                    # CLI-циклы и smoke-тесты (/scripts/…)
├── tools/                      # dev-инструменты (скриншоты, иконки, сканер ассетов)
├── package.json / vite.*.js    # сборка статики
└── AGENTS.md  README.md        # короткие указатели в корне
```

Правило: если файл скачивает браузер — он в `public/assets/`; если файл
компилируется Vite — он в `src/admin/`; если это точка входа — она в `public/`;
документация и деплой — в `docs/`; всё остальное — код приложения.

## 2. URL → файл

| URL | Откуда отдаётся |
| --- | --- |
| `/` | `public/index.php` |
| `/admin.php`, `/admin.php?...` | `public/admin.php` |
| `/panel/...` (pretty URL) | `public/nf.php` + `lib/admin_router.php` |
| `/api/...` | `public/api.php` |
| `/404.php` | `public/404.php` |
| `/assets/...` | `public/assets/...` |
| `/cms/avatars/...`, `/cms/images/...` | `cms/...` (медиа) |
| `/modules/...` | `modules/...` (включая PHP-эндпоинты) |
| `/objects/?script=...` | `objects/index.php` |

| `/scripts/...` | `scripts/...` |
| `/3rdparty/...` | `3rdparty/...` (elFinder, библиотеки) |
| `/docs/...` | `docs/...` (UI Kit ссылается на документацию) |
| `/css|/js|/img/...` | 301 → `/assets/...` |
| `/3rdparty/<browser-lib>/...` | 301 → `/assets/vendor/<browser-lib>/...` |
| `/templates/<module>/*.css|js` | 301 → `/assets/modules/<module>/...` |
| `/public/...` | 301 → `/...` (старый префикс docroot) |
| `/rc/...` | 301 → `/panel/` (функция удалена) |

Вне document root и недоступны по HTTP: `config.php`, `bootstrap.php`,
`load_settings.php`, `lib/`, `cycle.php`, `dev_server.php`, `src/`, `tools/`.

Приватные подкаталоги `cms/` (`cached`, `debmes`, `modules_installed`,
`saverestore`) закрыты в nginx; `docs/**/*.sql` и скрипты тоже.

## 3. Почему `public/`, а не перенос всего

Document root — только `public/`. Всё, что должно сохранить старые URL,
монтируется из корня проекта:

- `modules/` — маркет ставит модули вместе с их PHP-эндпоинтами
  (`modules/<name>/autosave.php`, `update_iframe.php`, `*.js.php`), поэтому
  каталог и его URL остаются на месте;
- `cms/` — 160+ обращений вида `ROOT . 'cms/...'` в коде и медиа-URL;
- `objects/`, `scripts/` — веб-эндпоинты, завязанные на старые URL;
- `3rdparty/` — elFinder и библиотеки, которые подключают модули.

Точки входа перенесены в `public/` физически. Так как они используют
относительные include (`./config.php`, `./lib/...`), каждый начинается с
`require dirname(__DIR__) . '/bootstrap.php'`: тот определяет `MD_APP_ROOT` и
делает `chdir()` в корень приложения. `php-fpm` запускает запрос с CWD, равным
каталогу скрипта, — это же поведение воспроизводит `dev_server.php`.

## 4. nginx

Полный конфиг — `docs/deploy/nginx.conf.sample` — универсальный: домен, пути и
сокет заменены на пример. Перед деплоем подставьте свои `root`, `server_name`,
`сертификаты и сокет php-fpm.

``
ginx
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
- Список браузерных библиотек в `/3rdparty/` дублируется в `dev_server.php`
  (`devVendorPackages()`); при добавлении библиотеки обновите оба места.

## 5. Локальная разработка

`php dev_server.php` повторяет контракт nginx:

- document root — `public/`; входные файлы, `/assets/`, `favicon.ico`,
  `robots.txt` берутся оттуда;
- `/modules/`, `/objects/`, `/scripts/`, `/3rdparty/`, `/cms/`,
  `/docs/` отдаются из корня проекта, PHP в них выполняется;
- CWD перед запуском скрипта = каталог скрипта (как в php-fpm), поэтому
  `bootstrap.php` и собственные `chdir()` эндпоинтов работают одинаково с продом;
- старые пути (`/css/`, `/js/`, `/img/`, `/3rdparty/<lib>/`, `/templates/<m>/*.css|js`)
  отвечают 301, `/rc/` — 301 на `/panel/`.

## 6. Как добавить ассет

1. Статика страницы модуля → `public/assets/modules/<module>/`, подключается
   только в шаблоне этого модуля (`<#ROOTHTML#>assets/modules/<module>/...`).
2. Общая библиотека → 
pm install` + импорт в `src/admin/main.js` (или отдельный
   entry). В `public/assets/vendor/` руками ничего не кладём.
3. Картинка интерфейса → `public/assets/img/...`, ссылка
   `<#ROOTHTML#>assets/img/...`.
4. Новая точка входа → `public/<name>.php` + `require dirname(__DIR__) . '/bootstrap.php'`.

## 7. Проверка

```bash
php -l <изменённые php>
node --check <изменённые js>
npm run build
php scripts/routing_smoke.php     # маршруты, nginx-контракт, layout public/
node tools/asset-references.mjs   # отчёт о неиспользуемой статике
nginx -t && systemctl reload nginx
```

`tools/asset-references.mjs` — вспомогательный сканер ссылок на статику.
Отчёт «orphan» — кандидаты на удаление, а не приговор: пути могут собираться
динамически (`img/modules/<name>.png`, `img/flags/<lang>.png`) или приходить из БД.