# Сторонние библиотеки (3rdparty) и план перехода на Composer

Документ отвечает на три вопроса: что лежит в `3rdparty/`, зачем это нужно, где
используется. В конце — план перехода на `vendor/` + Composer.

## 1. Что сейчас в `3rdparty/`

| Каталог | Размер | Что это | Где используется | Composer-пакет |
| --- | --- | --- | --- | --- |
| `smarty3/` | 1.1 МБ | Smarty 3, шаблонизатор | `lib/parser.class.php` (`SMARTY_DIR`), `lib/jTemplate.class.php` | `smarty/smarty` |
| `php-mailer/` | 204 КБ | PHPMailer 6 | `lib/general.class.php` (`sendMail`) | `phpmailer/phpmailer` |
| `phpmqtt/` | 18 КБ | Минимальный MQTT-клиент | `modules/mqtt/mqtt.class.php`, `scripts/cycle_mqtt.php` | `php-mqtt/client` |
| `Paginator/` | 9 КБ | Постраничная навигация | `modules/actions_log/actions_log_search.inc.php` | `jasongrimes/paginator` |
| `elFinder/` | 4.5 МБ | Файловый менеджер (UI + PHP-коннектор) | `public/assets/js/admin/panel.js` → `/3rdparty/elFinder/elfinder.html`, PHP-коннектор через URL | `studio-42/elfinder` (PHP), JS идёт в комплекте |
| `fonts/` | 1.2 МБ | Шрифт Rubik (woff2) | импорт Vite: `resources/js/app.js` → `../../3rdparty/fonts/rubik/rubik.css` | `@fontsource/rubik` (npm) |

Итого ~7 МБ. Только `smarty3`, `php-mailer`, `phpmqtt` и `Paginator`
подключаются из PHP; `elFinder` и `fonts` — это веб-ресурсы.

## 2. Что удалено (0 входящих ссылок)

| Было | Размер | Почему удалено |
| --- | --- | --- |
| `pChart/` | 2.4 МБ | Библиотека + самописные эндпоинты `/pChart/?p=объект.свойство`. В коде и в БД ссылок нет; графики свойств сейчас рисует админка (ApexCharts). |
| `3rdparty/jpgraph/` | 6.2 МБ | Использовался только `pChart/index.php`. |
| `3rdparty/freeboard/` | 3.5 МБ | Дашборды freeboard. Эндпоинты `modules/objects/objects_widgets.js.php` и `modules/scripts/scripts_widgets.js.php` не подключены ни из одного шаблона, в БД ссылок нет. |

Восстановить при необходимости:

```bash
git log --oneline -- pChart 3rdparty/jpgraph 3rdparty/freeboard
git checkout <commit>^ -- pChart 3rdparty/jpgraph 3rdparty/freeboard
```

Если графики свойств снова понадобятся — современный путь: ApexCharts в админке
(`100_chart_runtime.js`) или отдельный лёгкий endpoint, а не pChart 2.x.

## 3. План перехода на Composer

Цель — `composer.json` + `vendor/` (в `.gitignore`), без рукописных копий
библиотек в `3rdparty/`. Порядок безопасный, по одной библиотеке:

1. **Инфраструктура**
   - `composer init` → `composer.json` с `"require": {}` и `"config": {"optimize-autoloader": true}`;
   - `vendor/` в `.gitignore`, `vendor/autoload.php` подключается в `bootstrap.php`
     (рядом с `chdir`), чтобы автозагрузка была доступна всем входам;
   - на проде — `composer install --no-dev --optimize-autoloader` при деплое
     (в `docs/deploy/install-linux.sh` добавить шаг, если composer доступен).

2. **Paginator** (9 КБ, один класс) — первым: `composer require jasongrimes/paginator`,
   заменить `require ROOT . '3rdparty/Paginator/Paginator.php'` на автозагрузку
   (`JasonGrimes\Paginator`). Простейшая проверка — история действий, `actions_log`.

3. **PHPMailer** — `composer require phpmailer/phpmailer`, в `lib/general.class.php`
   убрать три `include_once`, классы `PHPMailer\PHPMailer\*` придут из `vendor/`.
   Проверка: отправка письма из настроек (или CLI-скрипт).

4. **php-mqtt** — `composer require php-mqtt/client`. Текущий `phpmqtt/phpMQTT.php`
   имеет другой API (`phpMQTT`), поэтому нужен адаптер в `modules/mqtt` и
   `scripts/cycle_mqtt.php`, либо временно оставить как есть. Проверка: подключение
   к брокеру и чтение топика.

5. **Smarty** — `composer require smarty/smarty`. Здесь больше всего кода:
   `lib/parser.class.php` (`SMARTY_DIR`), `lib/jTemplate.class.php`,
   `cms/cached/templates_c`. Нужен отдельный этап с проверкой всех legacy-страниц,
   которые рендерятся через Smarty.

6. **elFinder** — `composer require studio-42/elfinder` даёт PHP-часть; `elfinder.html`,
   `js/`, `css/`, `img/` всё равно нужно публиковать как веб-ресурсы. Вариант:
   оставить `3rdparty/elFinder` как есть или перенести UI в `public/assets/vendor/elfinder`.
   Это отдельная задача, к Composer не критична.

7. **Rubik** — `npm install @fontsource/rubik` и импорт в `resources/js/app.js`;
   `3rdparty/fonts` после этого удаляется (шрифт попадает в бандл `public/assets/admin/fonts`).

После каждого шага: `php -l`, `npm run build` (если затронут фронтенд),
`php tests/routing_smoke.php`, ручная проверка затронутого модуля. Коммит — на
каждую библиотеку, чтобы откат был точечным.

## 4. Что важно не сломать

- **Маркет модулей.** Сторонние модули могут ожидать `3rdparty/...` в корне
  (например, `3rdparty/php-mailer`). Перед переносом библиотеки в `vendor/`
  проверьте входящие ссылки по всему проекту:
  `grep -rn "3rdparty/<name>" --include=*.php .`
- **Веб-ресурсы.** `elFinder` и `fonts` отдаются браузеру; для них нужен либо
  URL-путь в `public/assets/vendor/`, либо `root`-override в nginx.
- **Сборка.** `fonts` импортируется Vite — путь является частью сборки, менять
  его нужно вместе с `resources/js/app.js`.
- **Бандл коммитится.** После переезда шрифтов не забудьте `npm run build` и
  поднять `?v=` в `templates/common_header_admin.html`.

## 5. Критерий готовности

- `3rdparty/` содержит только то, что нельзя поставить пакетным менеджером
  (или ещё не перенесено и явно перечислено в этом документе).
- `composer.json` + `composer.lock` в репозитории, `vendor/` в `.gitignore`.
- `bootstrap.php` подключает `vendor/autoload.php`, если файл существует.
- `php tests/routing_smoke.php` и `php -l` проходят, затронутые модули проверены.
