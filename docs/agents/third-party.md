# Сторонние библиотеки: Composer и 3rdparty

Документ отвечает на три вопроса: что лежит в `3rdparty/`, зачем это нужно, где
используется и как добавлять PHP-пакеты через Composer. Composer и `3rdparty/`
работают параллельно: существующие библиотеки не перенесены.

## 1. Что сейчас в `3rdparty/`

| Каталог | Размер | Что это | Где используется | Composer-пакет |
| --- | --- | --- | --- | --- |
| `php-mailer/` | 204 КБ | PHPMailer 6 | `lib/general.class.php` (`sendMail`) | `phpmailer/phpmailer` |
| `phpmqtt/` | 24 КБ | Минимальный MQTT-клиент | `modules/mqtt/mqtt.class.php`, `scripts/cycle_mqtt.php` | `php-mqtt/client` |
| `Paginator/` | 9 КБ | Постраничная навигация | `modules/actions_log/actions_log_search.inc.php` | `jasongrimes/paginator` |
| `fonts/` | 1.2 МБ | Шрифт Rubik (woff2) | импорт Vite: `resources/js/app.js` → `../../3rdparty/fonts/rubik/rubik.css` | `@fontsource/rubik` (npm) |

Итого ~1,4 МБ. Только `php-mailer`, `phpmqtt` и `Paginator`
подключаются из PHP; `fonts` — веб-ресурс. Smarty удалён: шаблонный слой
(`lib/core/Template/`) использует один движок jTemplates и PHP-партиалы.

Локальная версия `phpmqtt/phpMQTT.php` содержит ограниченный приёмник пакетов:
`setReadLimits()`, таймаут незавершённого пакета, ожидание сокета, потоковый
пропуск слишком больших QoS 0 пакетов и обработку SUBACK в `proc()`.
Переподключением после ошибки управляет внешний цикл. При замене библиотеки
сохранить это поведение и прогнать `tests/network_memory_smoke.php`.

## 2. Что удалено (0 входящих ссылок)

| Было | Размер | Почему удалено |
| --- | --- | --- |
| `3rdparty/elFinder/` | 4.5 МБ | Файловый менеджер с записью в `cms/scenes` открывался из выбора папки в настройках. Заменён project-scoped folder picker: только чтение каталогов внутри проекта, без upload/write. |
| `pChart/` | 2.4 МБ | Библиотека + самописные эндпоинты `/pChart/?p=объект.свойство`. В коде и в БД ссылок нет; графики свойств сейчас рисует админка (ApexCharts). |
| `3rdparty/jpgraph/` | 6.2 МБ | Использовался только `pChart/index.php`. |
| `3rdparty/freeboard/` | 3.5 МБ | Дашборды freeboard. Эндпоинты `modules/objects/objects_widgets.js.php` и `modules/scripts/scripts_widgets.js.php` не подключены ни из одного шаблона, в БД ссылок нет. |

Восстановить при необходимости:

```bash
git log --oneline -- pChart 3rdparty/jpgraph 3rdparty/freeboard 3rdparty/elFinder
git checkout <commit>^ -- pChart 3rdparty/jpgraph 3rdparty/freeboard 3rdparty/elFinder
```

Если графики свойств снова понадобятся — современный путь: ApexCharts в админке
(`100_chart_runtime.js`) или отдельный лёгкий endpoint, а не pChart 2.x.

## 3. Использование Composer

Нужны PHP 8.1+ и Composer 2. Все команды выполняются из корня проекта.
В `config.platform.php` зафиксирован PHP 8.1.0: даже при локальном PHP 8.4
Composer подбирает зависимости для PHP 8.1. Проверяйте реальный сервер командой
`composer check-platform-reqs --no-dev`.

```bash
composer install
```

Команда создаёт `vendor/` и автозагрузку из `composer.lock`. В репозитории
хранятся `composer.json` и `composer.lock`; `vendor/` и локальный `auth.json`
исключены из Git. Пока сторонних Composer-зависимостей нет.

Добавить новый пакет:

```bash
composer require vendor/package
```

`vendor/package` замените именем выбранного пакета. После команды сохраняйте
оба файла Composer вместе с использующим пакет кодом. Классы пакета доступны
по его namespace в модулях, сценариях, методах объектов и CLI-циклах;
дополнительный `require` в каждом файле не нужен. Отдельный внешний CLI-скрипт
может подключить `bootstrap.php` по пути относительно своего `__DIR__`.

Общая точка подключения — `lib/core.class.php`, которую используют
`bootstrap.php`, `config.php` и `lib/loader.php`. Она однократно подключает
`vendor/autoload.php`, включая объявленные пакетами `autoload.files`.
Namespace `MajorDoMo\Core\` сопоставлен с `lib/core/` через PSR-4.
Локальный загрузчик ядра сохранён: текущая система может загрузиться без
`vendor/`, но код с новыми пакетами требует предварительного `composer install`.
Неполный или повреждённый `vendor/` не маскируется fallback-логикой.

Старые PHPMailer, MQTT и Paginator продолжают подключаться из `3rdparty/`.
Их миграция — отдельная задача. Не устанавливайте вторую копию той же библиотеки
с теми же классами: возможны конфликты версий и повторное объявление классов.

### Сервер и обновления

Обычный `composer install` поддерживается. Для рабочего сервера:

```bash
composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
composer check-platform-reqs --no-dev
```

Команды не запускают приложение и не подключаются к его БД. Скрипт
`docs/deploy/install-linux.sh` также устанавливает зависимости из корня проекта
и прекращает выполнение при ошибке Composer.

После доставки изменённых файлов Composer на сервер выполните `composer install`.
Встроенный updater `saverestore` Composer автоматически не запускает.
Обновлять версии пакетов следует локально с проверками и переносом lock-файла;
на сервере используется `install`, а не `update`. Для работающих CLI-циклов
новый набор зависимостей вступает в силу после их перезапуска.
`vendor/` расположен вне `public/` и не должен публиковаться через nginx.

### Проверки

```bash
composer validate --strict
composer dump-autoload --optimize --strict-psr
php tests/composer_smoke.php
php tests/routing_smoke.php
php tests/config_smoke.php
```

`composer_smoke.php` проверяет реальные Composer PSR-4 mappings, загрузку через
bootstrap и CLI, классы/функции пакета, однократное подключение и работу без
`vendor/`. Тест использует только временные файлы, не загружает рабочий `.env`
и не обращается к БД или внешним сервисам.

## 4. Что важно не сломать

- **Маркет модулей.** Сторонние модули могут ожидать `3rdparty/...` в корне
  (например, `3rdparty/php-mailer`). Перед переносом библиотеки в `vendor/`
  проверьте входящие ссылки по всему проекту:
  `grep -rn "3rdparty/<name>" --include=*.php .`
- **Веб-ресурсы.** `fonts` отдаётся браузеру; для него нужен URL-путь в
  `public/assets/` или `root`-override в nginx.
- **Сборка.** `fonts` импортируется Vite — путь является частью сборки, менять
  его нужно вместе с `resources/js/app.js`.
- **Бандл коммитится.** После переезда шрифтов не забудьте `npm run build` и
  поднять `?v=` в `templates/common_header_admin.html`.

## 5. Критерий готовности

- `3rdparty/` сохраняет существующие библиотеки; новые PHP-пакеты ставятся через Composer.
- `composer.json` + `composer.lock` в репозитории, `vendor/` в `.gitignore`.
- `lib/core.class.php` подключает `vendor/autoload.php`, если файл существует;
  HTTP и CLI используют одну точку подключения.
- Composer validation, `php tests/composer_smoke.php`, routing/config smoke и `php -l` проходят.
