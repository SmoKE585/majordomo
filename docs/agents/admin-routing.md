# Admin routing and 404

Документ описывает, как в админке MajorDoMo устроены «красивые» URL, почему старые
ссылки с `pd` продолжают работать и как добавить новый маршрут.

## 1. Проблема

Исторически админка хранит всё состояние навигации в query-параметрах:

```
/admin.php?pd=Y2xhc3Nlczp7aWQ9MTAsdmlld19tb2RlPWVkaXRfY2xhc3Nlc30%3Dpz_&md=objects&inst=&view_mode=edit_objects&id=7&tab=properties
```

`pd` — это `base64` от цепочки состояний модулей (`classes:{...}`, `panel:{...}`).
Формат непрозрачный, длинный, плохо живёт в закладках и ломается при ручном
редактировании. При этом старые модули строят ссылки именно так, и их нельзя
просто отключить.

## 2. Схема URL

Все страницы админки живут под `/panel/`:

| URL | Что открывает |
| --- | --- |
| `/panel/` | главная (dashboard) |
| `/panel/classes.html` | список классов |
| `/panel/class/10.html` | карточка класса |
| `/panel/class/10/objects.html` | вкладка «Объекты» класса |
| `/panel/class/10/objects/list.html` | список объектов класса (активный модуль `objects`) |
| `/panel/class/10/properties.html` | вкладка «Свойства» класса |
| `/panel/class/10/properties/18.html` | редактирование свойства класса |
| `/panel/class/10/properties/add.html` | новое свойство класса |
| `/panel/class/10/methods.html` | вкладка «Методы» класса |
| `/panel/class/10/methods/45.html` | редактирование метода класса |
| `/panel/class/10/object/7.html` | карточка объекта |
| `/panel/class/10/object/7/properties.html` | вкладка свойств объекта |
| `/panel/class/10/object/7/history.html` | история объекта |
| `/panel/class/10/object/7/methods/45.html` | метод объекта |
| `/panel/class/10/object/add.html` | новый объект класса |
| `/panel/class/10/object/7/delete.html` | удаление объекта (GET-действие) |
| `/panel/class/10/object/7/clone.html` | клонирование объекта |
| `/panel/class/10/export.html` | экспорт класса |
| `/panel/script/10.html` | сценарий |
| `/panel/event/12.html` | событие |
| `/panel/users.html` | список пользователей |
| `/panel/users/add.html` | новый пользователь |
| `/panel/users/10.html` | карточка пользователя |
| `/panel/users/10/delete.html` | удаление пользователя (GET-действие) |
| `/panel/pinghosts.html` | список хостов мониторинга |
| `/panel/pinghosts/add.html` | новый хост |
| `/panel/pinghosts/10.html` | карточка хоста |
| `/panel/pinghosts/10/delete.html` | удаление хоста (GET-действие) |
| `/panel/locations.html` | список помещений |
| `/panel/locations/add.html` | новое помещение |
| `/panel/locations/10.html` | карточка помещения |
| `/panel/locations/10/logic.html` | логика помещения (метод room-объекта) |
| `/panel/locations/10/up.html` | перемещение помещения выше |
| `/panel/locations/10/down.html` | перемещение помещения ниже |
| `/panel/locations/10/delete.html` | удаление помещения (GET-действие) |
| `/panel/system_errors.html` | журнал ошибок системы |
| `/panel/system_errors/10.html` | карточка группы ошибок |
| `/panel/system_errors/10/history.html` | история срабатываний ошибки |
| `/panel/system_errors/10/read.html` | отметить ошибку просмотренной |
| `/panel/system_errors/10/delete.html` | удаление группы ошибок |
| `/panel/system_errors/readall.html` | отметить все ошибки просмотренными |
| `/panel/system_errors/test.html` | создать тестовую ошибку |
| `/panel/mqtt.html` | журнал MQTT-топиков |
| `/panel/mqtt/add.html` | создание топика |
| `/panel/mqtt/10.html` | редактор топика |
| `/panel/mqtt/10/delete.html` | удаление топика |
| `/panel/mqtt/settings.html` | настройки брокера |
| `/panel/mqtt/clear-trash.html` | очистка непривязанных топиков |
| `/panel/mqtt/optimize.html` | оптимизация истории MQTT |
| `/panel/settings.html?filter_name=codeeditor` | настройки с фильтром |
| `/panel/<module>.html` | список любого модуля |
| `/panel/<module>/<id>.html` | карточка любого модуля (generic) |

Дополнительные параметры, которые не выражаются сегментом пути, остаются в
query-строке: фильтры, страницы, `view_mode` для нестандартных действий.

## 3. Как это работает

Единый источник правды — `lib/admin_router.php`:

- `mdj_admin_route_table()` — таблица inbound-маршрутов (pretty path → legacy query).
  Её использует 
f.php`.
- `mdj_admin_pretty_url()` — outbound-преобразователь (legacy query → pretty path).
  Его используют `parseLinks()`, `module::redirect()` и AJAX-поиск в шапке.
- `mdj_admin_resolve_path()` / `mdj_admin_state_from_query()` — разбор и нормализация
  состояния.

Преобразование **проверяется round-trip**: кандидат pretty URL снова разбирается
через ту же таблицу маршрутов, и состояние сравнивается с исходным. Если хотя бы
один параметр теряется, ссылка остаётся legacy. Поэтому непортированные модули
продолжают работать без изменений.


f.php` при открытии pretty URL собирает те же `$_REQUEST['pd']`, `md`, `inst` и
параметры модуля, что и раньше. Старые модули не знают, что URL изменился.

Значения из маршрута — это состояние **по умолчанию**. Явные GET/POST-параметры
запроса имеют приоритет: например, `/panel/script/10.html?view_mode=run_script_ajax`
должен открыть AJAX-обработчик запуска скрипта, а не редактор из маршрута. На этом
держатся page-JS эндпоинты, которые вызываются относительно текущей pretty-страницы.

## 4. Legacy-ссылки и закладки

- Старые URL с `pd`/`md` по-прежнему открываются.
- Если состояние можно выразить красиво и без потерь, `admin.php` отвечает
  `302 Location: /panel/...` на канонический URL.
- POST, `part_load`, `ajax`, `print`, `popup` и прочие служебные запросы **не**
  редиректятся.
- Старые закладки после первого открытия попадают на красивый URL.

## 5. 404

- Неизвестный `*.html` → 
f.php` не находит маршрут и отдаёт `lib/error_page.php`
  со статусом `404`.
- Неизвестный модуль в админке (`/panel/foo.html`) → `panel.class.php` проверяет
  наличие класса модуля и показывает 404 внутри shell, тоже со статусом `404`.
- `public/404.php` — публичная точка входа для nginx `error_page 404`.
- `ajax`/`part_load`/`Accept: application/json` получают JSON, а не HTML.
- Страница использует те же токены и `md-*` компоненты, светлую и тёмную тему.

## 6. Как добавить маршрут

1. Добавьте правило в `mdj_admin_route_table()` **до** generic-правил.
2. Добавьте построение пути в `mdj_admin_build_pretty()` (если это не generic).
3. Добавьте кейс в `tests/routing_smoke.php`: inbound, outbound и fallback.
4. Проверьте `php tests/routing_smoke.php`.
5. Проверьте старую ссылку: она должна либо редиректить на новый URL, либо
   остаться legacy без потери состояния.

## 7. Правила для кода

- В PHP-шаблонах используйте обычные `href="?..."`: `parseLinks()` сам сделает
  красивую ссылку.
- В JS не собирайте `?action=...` вручную от текущего пути: используйте
  `window.ROOTHTML + 'admin.php?...'` или готовый pretty path. Текущий путь может
  быть `/panel/class/...`, и относительный query уйдёт не туда.
- Не добавляйте `pd` в новые ссылки вручную.
- Не возвращайте catch-all `([\w-]+).html` → docs: он превращал любую опечатку в
  страницу с кодом 200.
- Не меняйте формат `pd` и правила `parseLinks()` без проверки старых модулей.

## 8. nginx

Канонический шаблон — `docs/deploy/nginx.conf.sample` (универсальный, без конкретного
домена): `root` указывает на `<project>/public`, а каталоги вне document root
(`/modules/`, `/cms/`, `/objects/`, `/scripts/`, `/3rdparty/`, `/docs/`) смонтированы
через `root`-override с вложенной PHP-локацией.

Для pretty URL в конфиге обязательны:

- `location = /panel { return 301 /panel/; }`;
- `location ^~ /panel/ { try_files $uri $uri/ /nf.php?$query_string; }`;
- `location ^~ /api/ { rewrite ^ /api.php last; }`;
- `deny` для `config.php` и `/lib/` через `=`/`^~`, а не обычный prefix:
  prefix-location проигрывает regex-локации `~ \.php$`;
- запрет `templates/*.html`, `templates/ui/`, `templates/ui_kit/`, dotfiles и
  служебных расширений (`sql`, `log`, ...).

