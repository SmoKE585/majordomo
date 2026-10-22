---
title: Переписывание legacy-модуля
audience: agent
category: migration
category_label: Переписывание модулей
category_icon: replace
category_order: 80
tags: миграция, модули, legacy, mdjui, привязки, циклы, api ядра
description: Пошаговый порядок перевода старого модуля на новый формат MajorDoMo — данные, URL, интерфейс, привязки, события, тесты.
order: 10
---

# Переписывание legacy-модуля

Коротко: модуль остаётся модулем. Он живёт в `modules/<name>/`, штатно
устанавливается и удаляется, а его код не переезжает в ядро. Меняются только
способы работы: данные, URL, интерфейс и фоновые задачи переводятся на общие
сервисы ядра. В `lib/core/` попадают только универсальные операции, нужные
нескольким модулям, и только по явному решению. Сначала сохраняем поведение,
потом улучшаем вид. Этот документ — про порядок работ и границы; визуальные
правила — в [`admin-module-ui-guidelines.md`](admin-module-ui-guidelines.md) и
[`DESIGN_SYSTEM.md`](DESIGN_SYSTEM.md).

## 1. Что считается «новым форматом»

| Слой | Legacy | Новый формат |
| --- | --- | --- |
| Класс модуля | `class x extends module` | Сохраняется: тот же базовый класс и жизненный цикл |
| Разметка | `templates/<m>/*.html` с `[#...#]`, таблицы, `panel` | `templates/<m>/*.php` (PHP-партиал) + `MDJUI` + `md-*`; старые `.html` поддерживаются |
| Стили | inline, Bootstrap 3, глобальные селекторы | `public/assets/modules/<m>/<m>.css`, только свои классы |
| JS | inline `onclick`, jQuery-плагины | vanilla JS + Bootstrap 5 API + Alpine, page-модуль |
| URL | `?md=<m>&view_mode=...` | `/panel/<m>/...` + legacy-совместимость |
| Данные | `SQLSelect*` где попало | `Core::database()` в слое модуля; системные операции — через хелперы |
| Настройки | `define()`, `getenv()`, правки `config.php` | `Config::get/int/bool/string`, ключи в `ConfigSchema` |
| Привязки | ручной `UPDATE pvalues` | `addLinkedProperty()` / `removeLinkedPropertyIfNotUsed()` |
| События | прямой вызов модуля | `subscribeToEvent()` + `registerEvent()` |
| Фон | `sleep()` в web-запросе | `scripts/cycle_<m>.php` — тонкий запускатор, логика остаётся в модуле |
| Ошибки | `echo`, `error_log`, молчание | `registerError()` / `ErrorReporter`, `DebMes()` |
| CLI | отдельный скрипт в корне | тонкая команда в `lib/core/Console/Commands/`, логика — в модуле |

Модульный класс, таблица `project_modules`, `checkInstalled()`, `dbInstall()` и
`templates/` остаются. «Новый формат» — это не новый framework, а новые
договорённости внутри существующего.

### Граница: модуль остаётся модулем

Переписывание не означает перенос модуля в ядро. Правила:

- код модуля остаётся в `modules/<name>/`; ядро не должно знать о конкретном
  модуле и не содержит его логики;
- модуль по-прежнему устанавливается и удаляется штатно: `install()` /
  `uninstall()`, `dbInstall()`, маркер
  `cms/modules_installed/<name>.installed`, запись в `project_modules`;
- после удаления модуля не остаётся его таблиц, классов, шаблонов, CSS/JS,
  пунктов меню и фоновых циклов;
- в `lib/core/`, `Core::*` и общие helpers попадают только **универсальные**
  операции, нужные нескольким модулям и не содержащие логики одного устройства
  или сервиса;
- перенос модуля или его части в ядро — отдельное решение владельца проекта,
  а не побочный эффект миграции. Без прямого указания так не делать.

Хорошо: модуль `zigbee` использует `Core::database()` и `addLinkedProperty()`,
оставаясь в `modules/zigbee/`.

Плохо: модуль `zigbee` «переехал» в `lib/core/Zigbee/` и стал частью ядра —
его больше нельзя установить, удалить и обновить отдельно.

### Признаки legacy-модуля

Достаточно двух-трёх признаков, чтобы планировать миграцию:

- шаблон на `panel`/`btn-default`/`table width=...`, inline `style=`;
- inline `onclick="$('#...')"` и jQuery-плагины;
- SQL собирается конкатенацией `$_GET`/`$_POST`;
- прямые `UPDATE pvalues`/`properties` вместо `sg()`/`addLinkedProperty()`;
- `sleep()` или долгий цикл в `run()`/web-запросе;
- `define()`/`getenv()` вместо `Config`;
- ошибки уходят в `echo`/`error_log` или глотаются;
- нет `modules/<name>/docs/` и тестов.

## 2. Приоритеты

Не переписывайте всё сразу. Порядок по риску:

**P0 — данные и безопасность.**
Убрать прямые записи в `pvalues`/`properties`, SQL-конкатенацию без
`escape()`, глобальные CSS/JS, чтение секретов через `getenv()`, запись в
системные таблицы в обход API ядра. Это чинится первым, даже если UI ещё старый.

**P1 — совместимость.**
Старые URL (`pd`/`md`), имена полей формы, формат ответов API и `propertySetHandle`
должны работать до и после. Пока совместимость не подтверждена, UI не трогаем.

**P2 — интерфейс.**
Шаблон, `MDJUI`, `md-*`, page CSS/JS, тёмная тема, 390 px. Визуальные правила —
в [`admin-module-ui-guidelines.md`](admin-module-ui-guidelines.md).

**P3 — полировка.**
Empty states, подсказки, доступность с клавиатуры, аккуратные ошибки, метрики.

Правило: один шаг — одно изменение поведения. Не смешивайте перенос логики и
редизайн в одном коммите: если что-то сломалось, непонятно, что именно.

## 3. Карта файлов модуля

```
modules/<name>/
├── <name>.class.php          # класс, install/uninstall/dbInstall, run/admin/usual
├── <name>_search.inc.php     # подготовка данных списка (если используется)
├── <name>_edit.inc.php       # подготовка данных формы
├── <name>_helpers.inc.php    # локальные форматтеры и презентеры
├── docs/                     # документация модуля для «Базы знаний» (опционально)
└── initial.sql               # разовые запросы при установке (опционально)
templates/<name>/
├── <name>.php                # точка рендера страницы (новый стиль)
├── <name>_search_admin.html  # список
├── <name>_edit.html          # форма
└── action_admin.html         # shell страницы: hero, tabs, подключение assets
public/assets/modules/<name>/
├── <name>.css
└── <name>.js
```

Правило: PHP готовит данные и принимает решения, шаблон — только выводит.
SQL, `$_POST` и бизнес-логика в шаблоне не живут.

## 4. Порядок переписывания

1. **Снять поведение.** Выпишите: какие URL открывает модуль, какие поля
   принимает, какие таблицы и свойства читает/пишет, на какие события
   подписан, что делает в фоне. Это ваш тест-план.
2. **Backend.** Приведите класс к современному виду: `getParams()` с типами,
   `saveParams()`, `run()`, отдельные методы на действия. Логику вынесите в
   сервис/класс модуля, чтобы её можно было тестировать без HTML.
3. **Данные.** Проверьте `dbInstall()` и `initial.sql`, переведите доступ на
   `Core::database()`. Для системных сущностей — только хелперы ядра.
4. **URL.** Оставьте старые ссылки рабочими, добавьте чистые маршруты в
   `lib/admin_router.php`. После POST — `redirect()`, а не «повторная отрисовка».
5. **UI.** Переведите шаблон на PHP-партиал и `MDJUI`, подключите page CSS/JS
   только в своём шаблоне.
6. **Привязки и события.** Переведите на `addLinkedProperty()` и
   `subscribeToEvent()`, проверьте отсутствие эха.
7. **Фон.** Если модулю нужен цикл — отдельный `scripts/cycle_<name>.php`,
   а не `sleep()` в запросе.
8. **Проверка и документация.** Тесты, `docs` модуля, обновление этого гайда,
   если появился новый общий приём.

## 5. Backend: класс модуля

Минимальный современный каркас:

```php
<?php

use MajorDoMo\Core\Core;

class mymodule extends module
{
    public function __construct()
    {
        $this->name = 'mymodule';
        $this->title = 'Мой модуль';
        $this->module_category = '<#LANG_SECTION_APPLICATIONS#>';
        $this->checkInstalled();
    }

    public function dbInstall($data)
    {
        $data = <<<EOD
 mymodule_items: ID int(10) unsigned NOT NULL auto_increment
 mymodule_items: TITLE varchar(255) NOT NULL DEFAULT ''
 mymodule_items: UPDATED datetime
EOD;
        parent::dbInstall($data);
    }

    public function getParams()
    {
        global $id, $view_mode;
        if (isset($id) && is_scalar($id)) $this->id = (int)$id;
        if (isset($view_mode) && is_scalar($view_mode)) $this->view_mode = (string)$view_mode;
    }

    public function saveParams($data = 0)
    {
        return parent::saveParams(array(
            'id' => (int)$this->id,
            'view_mode' => (string)$this->view_mode,
        ));
    }

    public function run()
    {
        $this->getParams();
        require_once ROOT . 'lib/ui/MDJUI.php';
        $out = array();
        // ... подготовка данных и обработка действий
        ob_start();
        require ROOT . 'templates/mymodule/mymodule.php';
        $this->result = $this->parseLinks(ob_get_clean());
    }
}
```

Требования:

- `getParams()` не доверяет `$_GET`/`$_POST`: приводит типы и проверяет
  допустимые значения;
- действия, меняющие данные, работают только через POST;
- для POST проверяется CSRF-токен сессии (`hash_equals`), как в `modes`;
- после успешного POST — `$this->redirect(...)`, чтобы F5 не повторял запись;
- ошибки уровня модуля — `registerError($code, $message)` с понятным текстом
  (стандарт — [`errors.md`](errors.md));
- фоновые уведомления — `DebMes($message, 'mymodule', 'error')`;
- в заголовке класса — короткое описание и блок «Изменения» с датами; при
  правке кода добавляйте запись `ГГГГ-ММ-ДД — что изменилось`
  ([`code-comments.md`](code-comments.md)).

## 6. Данные

Для собственных таблиц модуля SQL допустим — это слой, который ими владеет:

```php
$db = Core::database();
$items = $db->select('SELECT * FROM mymodule_items ORDER BY TITLE');
$db->requireExec("UPDATE mymodule_items SET TITLE='" . $db->escape($title) . "' WHERE ID=" . (int)$id);
```

Для системных сущностей прямой SQL запрещён: он обходит кэш, `ONCHANGE`,
привязки, аудит и очередь. Используйте хелперы (`gg`, `sg`, `callMethod`,
`runScript`, `getObject`, `getObjectsByProperty`) или сервисы `Core::*`
(`properties()`, `objects()`, `bindings()`, `history()`, `queue()`, `events()`).

Правила:

- имена таблиц и полей не собираются из пользовательского ввода;
- `escape()` — для значений, `(int)` — для идентификаторов;
- массовые операции — пакетно, без запроса в цикле на каждую строку;
- для новых глобальных настроек — `Config`, а не `define()`;
- свои настройки модуля — своя таблица или `project_modules.DATA` только для
  legacy-совместимости.

## 7. URL и маршруты

Базовый список модуля уже работает по общему маршруту
`/panel/<module>.html`. Для карточки и действий добавьте явные правила в
`lib/admin_router.php` (рядом с другими модулями):

```php
'/^' . $root . 'panel\/mymodule\/add\.html/is'
    => '?(panel:{action=mymodule})&md=mymodule&view_mode=edit_mymodule',
'/^' . $root . 'panel\/mymodule\/(\d+)\.html/is'
    => '?(panel:{action=mymodule})&md=mymodule&view_mode=edit_mymodule&id=\1',
'/^' . $root . 'panel\/mymodule\/(\d+)\/delete\.html/is'
    => '?(panel:{action=mymodule})&md=mymodule&view_mode=delete_mymodule&id=\1',
```

Правила совместимости:

- старые `?md=...&view_mode=...` (и `pd`) обязаны открываться;
- `302` на чистый URL — только для GET-страниц, не для `part_load`, `ajax`,
  `popup`, `print` и POST;
- неизвестный модуль — честный 404, а не пустая страница;
- ссылки в шаблонах генерируйте через `parseLinks()`/`redirect()`, а не
  вручную склеивайте `?md=`.

Подробнее — [`admin-routing.md`](admin-routing.md).

## 8. Интерфейс

Коротко:

- `templates/<m>/<m>.php` — PHP-партиал; данные передаются переменными;
- старые `.html`-шаблоны поддерживаются движком `[#...#]`: не переводите файл
  на `.php` только ради расширения, если он ещё используется;
- компоненты — `MDJUI::card()`, `::field()`, `::table`-разметка через
  `md-table`, `::modal()`, `::drawer()`, `::emptyState()`, `::badge()`;
- hero/KPI — по образцу главной и других переписанных модулей;
- page CSS/JS — только `public/assets/modules/<m>/`, подключаются в шаблоне
  этого модуля;
- иконки — Tabler (`ti ti-*`), не Bootstrap Icons и не Font Awesome;
- `data-md-searchable` для select с поиском, `data-code-editor` для кода;
- jQuery — только как временная legacy-зависимость, новый код на нём не пишем;
- тёмная тема — на токенах `--md-ui-*`/`--bs-*`;
- мобильная ширина — 390 px, таблицы внутри `md-table__scroll`;
- после `partLoad` страница должна работать без перезагрузки (все обработчики
  регистрируются через `MDJAdminUI.registerModuleUI` или делегирование).

Полные правила и примеры — [`admin-module-ui-guidelines.md`](admin-module-ui-guidelines.md)
и [`DESIGN_SYSTEM.md`](DESIGN_SYSTEM.md). Демо компонентов — UI Kit
(`/panel/ui_kit.html`).

## 9. Привязки к свойствам

Если модуль управляет устройством по значению свойства:

1. добавьте в свою таблицу `LINKED_OBJECT`, `LINKED_PROPERTY` (и при
   необходимости `LINKED_METHOD`);
2. при сохранении записи вызовите `addLinkedProperty($object, $property, $module)`;
3. при удалении/смене — `removeLinkedPropertyIfNotUsed('<table>', $object, $property, $module)`;
4. реализуйте `propertySetHandle($object, $property, $value)`;
5. обработчик должен быть идемпотентным и не писать то же свойство, из-за
   которого его вызвали (иначе сработает защита `property_chain`).

```php
public function propertySetHandle($object, $property, $value)
{
    // Применить значение к оборудованию. Не вызывать sg() для этого же
    // свойства: значение уже сохранено, а повтор оборвёт цепочку.
}
```

`no_linked` исключает доставку уведомления, но не отменяет повторную запись.
Если подтверждение состояния нужно другим подписчикам — пишите его в отдельное
свойство. Полный контракт — [`property-bindings.md`](property-bindings.md).

## 10. События и режимы

Подписка на системное событие:

```php
subscribeToEvent($this->name, 'EVENT_NAME', '', 0);      // при установке/инициализации
unsubscribeFromEvent($this->name, 'EVENT_NAME');          // при удалении
```

Своё событие:

```php
registerEvent('MY_EVENT', 'Что произошло');
```

Режимы:

```php
if (isModeActive('AWAY')) { /* ... */ }
setMode('AWAY', true, 'mymodule');
```

События обрабатываются через очередь, а не прямым вызовом из web-запроса.
Подробнее — [`events.md`](events.md), [`modes.md`](../user/modes.md).

## 11. Фоновые циклы

Если модулю нужна постоянная работа — отдельный скрипт `scripts/cycle_<name>.php`,
который супервизор находит автоматически (`scripts/cycle_*.php`).

```php
<?php

chdir(dirname(__DIR__));
require_once './config.php';
require_once './lib/loader.php';
require_once './load_settings.php';
set_time_limit(0);

while (true) {
    setGlobal('cycle_mymoduleRun', time(), 1);   // heartbeat для watchdog
    try {
        // ... одна порция работы
    } catch (Throwable $e) {
        DebMes('cycle_mymodule: ' . $e->getMessage(), 'cycle_mymodule', 'error');
    }
    if (isRebootRequired() || isset($_GET['onetime'])) break;
    sleep(1);
}
```

Нельзя: бесконечный цикл в `run()` модуля, `sleep()` в web-запросе, работа с
оборудованием во время рендера страницы. Скрипт остаётся тонким: он поднимает
рантайм и вызывает метод модуля, а логика живёт в `modules/<name>/`. Состояние
цикла (`running`/`stopped`/`disabled`), рестарты и X-Ray — [`cycles.md`](cycles.md).

## 12. Настройки и секреты

- глобальные ключи — `.env` + `Config::get()/int()/bool()/string()`;
- новый ключ — `ConfigSchema` + `.env.example`;
- в модуле нельзя `define()` и `getenv()`;
- секреты не попадают в логи, сообщения об ошибках и `data-*` атрибуты;
- смена настроек, влияющих на циклы, должна перезапускать цикл, а не ждать
  следующего запроса.

Подробнее — [`configuration.md`](configuration.md), [`settings.md`](settings.md).

## 13. Что нельзя ломать

| Нельзя | Почему |
| --- | --- |
| Менять старые URL и имена POST-полей без редиректа | Закладки, сценарии, старые модули |
| Удалять `templates/<m>/<m>.html`, пока есть входящие ссылки | Сломается legacy-режим |
| Писать в `pvalues`/`properties` напрямую | Обход кэша, `ONCHANGE`, привязок и аудита |
| Добавлять `define()`/`getenv()` в модуль | Конфигурация только через `Config` |
| Подключать Bootstrap/jQuery/Font Awesome повторно | Ломает shell и тему |
| Делать `sleep()`/тяжёлый цикл в web-запросе | Блокирует воркер и страницу |
| Писать `sg()` для свойства в его же `propertySetHandle` | Защита остановит цепочку, часть подписчиков не получит обновление |
| Переносить код модуля в ядро без явного указания | Модуль перестаёт устанавливаться и удаляться, ядро обрастает частным кодом |
| Возвращать `true` вместо результата операции | Вызывающий код теряет ошибку |
| Молча глотать исключения | Ошибка не попадёт в журнал и не будет исправлена |

## 14. Антипаттерны и замена

| Legacy-приём | Чем заменить |
| --- | --- |
| `$sql = "SELECT ... WHERE ID='$id'"` | `(int)$id` + `escape()` / `Core::database()` |
| `SQLSelect("SELECT * FROM objects")` для системных данных | `getObject()`, `getObjectsByProperty()`, `Core::objects()` |
| `echo`/`print_r` для отладки | `DebMes(..., 'mymodule')` |
| `error_log($e->getMessage())` | `registerError('mymodule', ...)` + `DebMes` |
| `setGlobal()` в обход привязок | `sg()`/`Core::properties()->set()` (сам вызовет привязки) |
| `$_GET['id']` напрямую | `gr('id', 'int')` или явная проверка |
| Inline `onclick="$('#x').hide()"` | `data-*` + page JS / Alpine |
| `panel panel-default` | `md-card`/`section` |
| `btn btn-default`, `data-toggle` | `btn-*`, `data-bs-*` |
| Свой `datepicker`/`select`/`drawer` | Готовый компонент `MDJUI` или библиотека из бандла |
| `sleep(5)` в `run()` | `scripts/cycle_<name>.php` |
| Отдельный CLI-скрипт в корне | Команда `lib/core/Console/Commands/` + `php majordomo` |

## 15. Пример: было / стало

Было (типичный legacy-модуль):

```php
function run()
{
    global $id;
    if ($id) {
        $rec = SQLSelectOne("SELECT * FROM mymodule_items WHERE ID='" . $id . "'");
        $this->data = $rec;
    }
    $p = new parser(DIR_TEMPLATES . $this->name . '/' . $this->name . '.html', $this->data, $this);
    $this->result = $p->result;
}
```

Стало:

```php
public function run()
{
    $this->getParams();
    require_once ROOT . 'lib/ui/MDJUI.php';
    $record = $this->id
        ? Core::database()->one('SELECT * FROM mymodule_items WHERE ID=' . (int)$this->id)
        : array();
    ob_start();
    require ROOT . 'templates/mymodule/mymodule.php';
    $this->result = $this->parseLinks(ob_get_clean());
}
```

Шаблон получает `$record`, а разметка строится на `md-*` и `MDJUI`. Старый
`.html`-шаблон можно оставить как fallback, пока есть входящие ссылки.

## 16. Чек-лист готовности

Перед тем как считать миграцию завершённой:

- [ ] код модуля остался в `modules/<name>/`; ядро не знает о конкретном модуле;
- [ ] модуль по-прежнему устанавливается и удаляется, после удаления не остаётся
      его таблиц, шаблонов, CSS/JS и пунктов меню;
- [ ] старые URL и POST-поля работают, есть редиректы на чистые URL;
- [ ] `php -l` по изменённым файлам, `node --check` по JS;
- [ ] `npm run build`, если менялись `resources/`;
- [ ] нет прямых записей в системные таблицы;
- [ ] нет новых `define()`/`getenv()` в модуле;
- [ ] привязки добавляются и удаляются через хелперы, `propertySetHandle`
      идемпотентен и не пишет то же свойство;
- [ ] фон вынесен в цикл, heartbeat есть;
- [ ] ошибки описаны по стандарту [`errors.md`](errors.md);
- [ ] в заголовке класса есть описание и запись «Изменения: ГГГГ-ММ-ДД»,
      новые комментарии — по-русски ([`code-comments.md`](code-comments.md));
- [ ] тёмная тема и 390 px проверены;
- [ ] `partLoad`-переход не ломает страницу;
- [ ] документация модуля лежит в `modules/<name>/docs/` и видна в
      «Базе знаний»;
- [ ] `php tests/routing_smoke.php` и профильные тесты проходят.

## 17. Что читать дальше

- Правила проекта и текущее состояние — [`AGENTS.md`](AGENTS.md).
- Дизайн-система — [`DESIGN_SYSTEM.md`](DESIGN_SYSTEM.md).
- Визуальная миграция модуля — [`admin-module-ui-guidelines.md`](admin-module-ui-guidelines.md).
- Свойства и привязки — [`property-bindings.md`](property-bindings.md).
- События — [`events.md`](events.md), циклы — [`cycles.md`](cycles.md).
- Маршруты и 404 — [`admin-routing.md`](admin-routing.md).
- Ошибки — [`errors.md`](errors.md).
- CLI — [`console.md`](console.md).
- API — [`api.md`](api.md), [`api-client.md`](api-client.md).
