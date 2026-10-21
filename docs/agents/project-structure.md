# Структура проекта

Документ описывает раскладку каталогов после этапа «public + группировка ассетов»
и контракт URL между приложением и nginx. Перед перемещением файлов прочитайте
раздел «Почему PHP остаётся в корне».

## 1. Каталоги

```
<project>/
├── public/                     # всё, что браузер скачивает напрямую
│   └── assets/                 # публикуется как /assets/
│       ├── admin/              # Vite-бандл (собирается, коммитится)
│       ├── css/                # legacy-стили: stl.css, темы, jQuery UI
│       ├── js/                 # legacy-скрипты: scripts.js, jQuery UI, panel.js
│       ├── img/                # картинки: иконки, логотипы, флаги, иконки модулей
│       ├── vendor/             # браузерные библиотеки (bootstrap, jquery, fancybox, …)
│       └── modules/<name>/     # CSS/JS страниц конкретного модуля
├── src/admin/                  # исходники Vite, в бандл, наружу не отдаются
│   ├── main.js                 # единственный entry основного бандла
│   ├── charts-entry.js         # entry lazy-chunk графиков (ApexCharts)
│   ├── codeeditor-entry.js     # entry lazy-chunk редактора (CodeMirror 6)
│   ├── css/                    # admin_panel.css, ui-v2.css, ui-system.css, codeeditor.css
│   └── js/                     # modules/admin_panel/*, codeeditor.js, codeeditor-loader.js
├── docs/                       # вся документация и конфиги деплоя
│   ├── README.md               # карта документации
│   ├── agents/                 # правила, дизайн-система, структура, сборка, роутинг
│   ├── user/                   # README проекта, блог, контракты API (Postman)
│   ├── deploy/                 # nginx-конфиги, systemd-unit, install-linux.sh
│   ├── dev/                    # db_terminal.sql (схема + демо-данные)
│   ├── archive/                # разовые отчёты
│   └── ui-redesign/            # локальные скриншоты (gitignored)
├── modules/                    # PHP-модули: классы, include-фрагменты, веб-эндпоинты
├── templates/                  # include-only разметка (*.html), PHP-партиалы
├── lib/                        # ядро, include-only PHP (наружу закрыт nginx)
├── languages/                  # переводы интерфейса
├── cms/                        # рантайм-данные и медиа: avatars, images, files, cached, debmes
├── backup/                     # резервные копии saverestore (runtime, gitignored)
├── 3rdparty/                   # PHP-библиотеки и elFinder (smarty3, php-mailer, phpmqtt, …)
├── objects/                    # веб-точка входа /objects/?script=…
├── rc/                         # веб-точка входа /rc/ (пульт)
├── pChart/                     # веб-точка входа /pChart/?p=… (графики свойств)
├── scripts/                    # CLI-циклы и smoke-тесты
├── tools/                      # dev-инструменты (скриншоты, иконки, сканер ассетов)
├── node_modules/               # зависимости сборки (gitignored)
├── AGENTS.md                   # короткий указатель для агентов
├── README.md                   # лендинг проекта
├── package.json / vite.*.js    # сборка статики
├── config.php(.sample)         # конфигурация (config.php не коммитится)
└── *.php                       # точки входа: index, admin, nf, api, 404, command, cycle
```

Правило: если файл скачивает браузер — он живёт в `public/assets/`; если файл
компилируется Vite — он живёт в `src/admin/`; документация и деплой-конфиги —
в `docs/`; всё остальное — код приложения.

`objects/`, `rc/`, `pChart/` — не «папки с мусором», а веб-точки входа: на них
завязаны URL (`/objects/?script=…`, `/rc/…`, `/pChart/?p=…`), поэтому они
остаются в корне. `backup/` создаёт saverestore в рантайме.

## 2. URL → файл

| URL | Откуда отдаётся |
| --- | --- |
| `/assets/admin/admin.js` | `public/assets/admin/admin.js` |
| `/assets/css/stl.css` | `public/assets/css/stl.css` |
| `/assets/js/scripts.js` | `public/assets/js/scripts.js` |
| `/assets/img/icons/home.png` | `public/assets/img/icons/home.png` |
| `/assets/vendor/bootstrap/...` | `public/assets/vendor/bootstrap/...` |
| `/assets/modules/xray/xray.css` | `public/assets/modules/xray/xray.css` |
| `/panel/...` | `nf.php` + `lib/admin_router.php` |
| `/cms/avatars/1.png` | `cms/avatars/1.png` (медиа остаётся в корне) |
| `/modules/market/update_iframe.php` | `modules/market/update_iframe.php` |
| `/css/...`, `/js/...`, `/img/...` | 301 → `/assets/...` |
| `/3rdparty/<browser-lib>/...` | 301 → `/assets/vendor/<browser-lib>/...` |
| `/templates/<module>/*.css|js` | 301 → `/assets/modules/<module>/...` |

`public/` напрямую не отдаётся (`location ^~ /public/ { deny all; }`): у каждого
ассета должен быть один канонический URL.

## 3. Почему PHP остаётся в корне

`public/` — это каталог статики, а не document root. Причины:

1. Маркет ставит сторонние модули вместе с их PHP-эндпоинтами
   (`modules/<name>/*.php`) и шаблонами. Они должны оставаться доступными по
   прежним URL, иначе установка модулей из маркета ломается.
2. Точки входа (`index.php`, `admin.php`, `nf.php`, `api.php`) используют
   относительные include (`./config.php`) и вычисляют `ROOT` от своего каталога.
3. `cms/` — это и медиа (должно быть доступно), и приватные данные
   (`cached/`, `debmes/`, `modules_installed/`). Разделение потребует отдельного
   этапа с миграцией путей в 160+ местах.

Чтобы сделать `public/` настоящим document root, нужен отдельный этап:
перенос entrypoints + реестр веб-эндпоинтов модулей + разделение `cms/` на
`public/media` и приватный runtime. До этого `root` указывает на корень проекта,
а `/assets/` отдаётся через `alias` (см. `docs/deploy/nginx.conf.sample`).

## 4. nginx

Полный конфиг — `docs/deploy/nginx.conf.sample` (эталон) и `docs/deploy/nginx.conf.may-13.ru.sample`
(боевой домен). Ключевые блоки:

```nginx
location ^~ /assets/ {
    alias /var/www/example/data/www/example.com/public/assets/;
    expires 30d;
    access_log off;
}

location ^~ /public/ { deny all; }

location ~ ^/(css|js|img)/(.+)$ {
    return 301 /assets/$1/$2$is_args$args;
}

location ~ ^/3rdparty/(bootstrap|bootstrap-datetimepicker|duration-picker|fancybox|jquery|jquery\.toast|jquerymobile|long-press-event|moment)/(.*)$ {
    return 301 /assets/vendor/$1/$2$is_args$args;
}

location ~ ^/templates/([a-z0-9_-]+)/(.+\.(?:css|js))$ {
    return 301 /assets/modules/$1/$2$is_args$args;
}
```

Важно:

- `^~` у `/assets/` и `/public/` обязателен: иначе regex-локации PHP/статики
  перехватят путь.
- Редиректы отдают `$is_args$args`, чтобы не терять `?v=...` (cache busting).
- Список браузерных библиотек в `/3rdparty/` дублируется в `dev_server.php`
  (`devVendorPackages()`). При добавлении новой библиотеки обновите оба места.
- `scripts/routing_smoke.php` проверяет наличие всех блоков — прогоняйте его
  после правок конфига.

## 5. Локальная разработка

`php dev_server.php` повторяет контракт nginx:

- `/assets/...` отдаётся из `public/assets/`;
- старые пути (`/css/`, `/js/`, `/img/`, `/3rdparty/<lib>/`, `/templates/<m>/*.css|js`)
  отвечают 301 на канонический URL;
- `/public/...` закрыт;
- PHP-точки входа и `/panel/...` работают как раньше.

## 6. Как добавить ассет

1. Статика страницы модуля → `public/assets/modules/<module>/`, подключается
   только в шаблоне этого модуля (`<#ROOTHTML#>assets/modules/<module>/...`).
2. Общая библиотека → `npm install` + импорт в `src/admin/main.js` (или отдельный
   entry). В `public/assets/vendor/` руками ничего не кладём.
3. Картинка интерфейса → `public/assets/img/...`, ссылка
   `<#ROOTHTML#>assets/img/...`.
4. Новый модуль с CSS/JS → создайте `public/assets/modules/<module>/`, не
   добавляйте файлы в общий бандл.

## 7. Проверка

```bash
php -l <изменённые php>
node --check <изменённые js>
npm run build
php scripts/routing_smoke.php
node tools/asset-references.mjs   # отчёт о неиспользуемой статике
```

`tools/asset-references.mjs` — вспомогательный сканер: он ищет ссылки на файлы
из `public/assets`, `img`, `css`, `js`, `3rdparty`. Отчёт «orphan» — это
кандидаты на удаление, а не приговор: пути могут собираться динамически
(`img/modules/<name>.png`, `img/flags/<lang>.png`) или приходить из БД.
