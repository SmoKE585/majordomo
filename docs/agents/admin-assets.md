---
title: Сборка админских ассетов
audience: agent
category: ui
tags: vite, сборка, бандл, codeeditor, charts, codehighlight, npm
description: Как устроена сборка админки MajorDoMo: Vite, основной бандл, lazy-chunk редактора/графиков/подсветки, команды npm и правила добавления пакетов.
order: 30
---

# Сборка админских ассетов

## Коротко

Админка собирается Vite: основной бандл `public/assets/admin/admin.css` + `admin.js` плюс три lazy-chunk — `codeeditor.js/css`, `charts.js/css` и `codehighlight.js/css`. Исходники лежат в `resources/`, готовые файлы коммитятся, PHP в runtime Node.js не нужен. Тяжёлые библиотеки (CodeMirror, ApexCharts, highlight.js) не входят в основной бандл.

Админка MajorDoMo собирается одним Vite-бандлом. PHP-приложению Node.js в runtime не нужен: готовые файлы лежат в `public/assets/admin/` и коммитятся вместе с кодом.

## Быстрый старт

```bash
npm install
npm run build         # production-сборка: admin bundle + editor chunk + charts chunk + code highlight chunk
npm run watch         # пересборка admin bundle
npm run watch:editor  # пересборка редактора кода (второй терминал)
npm run watch:charts  # пересборка чанка графиков (второй терминал)
npm run watch:highlight # пересборка подсветки примеров кода (второй терминал)
```

Результат:

- `public/assets/admin/admin.js` — jQuery, Bootstrap 5, Alpine.js, плагины, модули admin shell;
- `public/assets/admin/admin.css` — Bootstrap, Bootstrap Icons, Tabler Icons, Rubik, legacy admin CSS, `ui-v2.css` и `ui-system.css`;
- `public/assets/admin/codeeditor.js` + `public/assets/admin/codeeditor.css` — отдельный lazy-chunk редактора кода (CodeMirror 6);
- `public/assets/admin/charts.js` + `public/assets/admin/charts.css` — отдельный lazy-chunk графиков (ApexCharts);
- `public/assets/admin/codehighlight.js` + `public/assets/admin/codehighlight.css` — отдельный lazy-chunk подсветки read-only примеров (highlight.js);
- `public/assets/admin/fonts/*` — шрифты Bootstrap Icons, Tabler Icons и Rubik.

Шаблон `templates/common_header_admin.html` из библиотек подключает только `admin.css` и `admin.js`; после бандла идут legacy-скрипты `scripts.js` и `admin/panel.js`. Отдельные подключения Bootstrap, jQuery, Bootstrap Icons, Tom Select, fancybox, toast, duration-picker, codeeditor и модулей `resources/js/modules/admin_panel/*` из шаблонов убраны.

`codeeditor.js` не входит в основной бандл. `resources/js/codeeditor/loader.js` загружает его только когда на странице есть `[data-code-editor]` (или когда открывается консоль). Поэтому dashboard и другие страницы без редактора не скачивают CodeMirror и языковые парсеры.

`charts.js` не входит в основной бандл. Общий загрузчик `resources/js/modules/admin_panel/100_chart_runtime.js` скачивает его при первом построении графика; используют его дашборд (`101_dashboard_charts.js`) и drawer истории свойства (`40_history.js`), поэтому остальные страницы не скачивают ApexCharts.

`codehighlight.js` не входит в основной бандл. `resources/js/modules/admin_panel/123_code_sample.js` загружает его только при наличии `[data-md-code-sample]`; вкладки и копирование при этом работают сразу из основного бандла.

## Что уже в бандле

- `jquery` + `jquery-migrate`;
- `bootstrap` (JS + CSS);
- `bootstrap-icons`;
- `@tabler/icons-webfont` (5 247 иконок, канонический набор нового UI);
- `alpinejs` (локальные состояния, см. `115_alpine.js`);
- `tom-select` (JS + CSS);
- адаптер `$.toast()` к общему `MDJUIToast` (отдельный jquery-toast-plugin удалён);
- `@fancyapps/fancybox`;
- `html-duration-picker`;
- `jquery.cookie`, `jquery.tiny-pubsub` (локальные плагины);
- загрузчик редактора `resources/js/codeeditor/loader.js` (сам редактор — отдельный lazy-chunk);
- `apexcharts` — отдельный lazy-chunk `public/assets/admin/charts.js` + `charts.css` для дашборда и истории свойства;
- `highlight.js` — отдельный lazy-chunk `public/assets/admin/codehighlight.js` + `codehighlight.css` для `MDJUI::codeSample()`;
- admin shell modules из `resources/js/modules/admin_panel/`, включая `101_dashboard_charts.js` (графики дашборда), `120_linkedobject.js` (компонент связей объектов) и `123_code_sample.js` (вкладки/копирование примеров кода);
- `resources/css/admin_panel.css` (legacy), `resources/css/ui-v2.css` (shell/dashboard) и `resources/css/ui-system.css` (дизайн-система, включая LinkedObject).

## Что пока не в бандле

Общий WebSocket-клиент находится в `resources/js/modules/admin_panel/06_websockets.js`
и входит в `admin.js`: сессионный билет, синхронизация, повторное подключение и
совместимые события jQuery/native. `templates/websockets.html` подключает только
PHP-конфигурацию из `websockets_config.php`; постоянного WS-секрета в HTML нет.

- `public/assets/js/scripts.js` — legacy-хелперы, которые вызываются из inline-обработчиков старых шаблонов. Загружается одним классическим `<script>` после бандла.
- `public/assets/js/admin/panel.js` — legacy-хелперы shell (`partLoad`, фильтр меню, collapse, file browser). Загружается классическим `<script>` после `scripts.js`, потому что функции вызываются из inline-атрибутов старых шаблонов.
- CSS/JS конкретных модулей (`public/assets/modules/settings/settings.css`, `public/assets/modules/scripts/scripts.js` и т.п.). Они **сознательно остаются локальными** и подключаются только на страницах своих модулей — чтобы не грузить код всех установленных модулей сразу. В общий бандл их не переносить.
- `public/assets/js/admin/ui_kit.js` — page-only JS страницы UI Kit (каталог иконок, copy, scrollspy). Подключается только на `?action=ui_kit`; общие Alpine-компоненты и toast store находятся в бандле (`116_ui_components.js`).

`templates/panel_js_scripts.html` удалён: его содержимое перенесено в `public/assets/js/admin/panel.js` без шаблонных директив. Фильтр меню теперь работает по DOM-атрибутам `data-md-module` / `data-md-title` / `data-md-category`, а не по серверной генерации JS.

## Редактор кода

Редактор — CodeMirror 6, собранный Vite в `public/assets/admin/codeeditor.js` и `public/assets/admin/codeeditor.css`:

- исходники: `resources/js/codeeditor/editor.js` (обвязка и рендер), `resources/css/codeeditor.css`;
- entry: `resources/js/codeeditor/index.js`, конфиг: `vite.codeeditor.config.js`;
- загрузка: `resources/js/codeeditor/loader.js` в основном бандле;
- разметка: `data-code-editor` + `templates/*` (methods, objects, scripts, blockly_code, settings, console).

Страницы без редактора не загружают chunk. Консоль в shell помечена `data-code-editor-lazy="1"` и инициализируется при открытии drawer. Для новых модулей используйте существующую разметку `data-code-editor`; отдельные `<script>`/`<link>` для CodeMirror не добавляйте.

Устаревший `3rdparty/codemirror/` удалён. Тема редактора (`auto` / `light` / `dark`) задаётся в настройках и следует теме админки по умолчанию.

## Как добавить пакет

1. `npm install <package>`.
2. Импортировать в `resources/js/app.js` (или в модуль, который его использует).
3. Если старый код обращается к глобальной переменной, явно выставить её:
   ```js
   import TomSelect from 'tom-select';
   window.TomSelect = TomSelect;
   ```
4. Если нужен CSS: импортировать его в `resources/js/app.js` до `admin_panel.css`/`ui-v2.css`/`ui-system.css`, чтобы legacy-слой мог переопределить внешний вид.
5. `npm run build` и проверить страницу.

Не добавляйте новые `<script src="...">` и `<link rel="stylesheet" ...>` для библиотек в шаблоны модулей. Всё общее — через бандл.

## UI Kit и компоненты

Новые страницы собирайте из готовых компонентов: `MDJUI` + `templates/ui/components/` + классы `md-*` из `resources/css/ui-system.css`. Полный каталог, токены и правила — в `docs/agents/DESIGN_SYSTEM.md`; живая документация — `/admin.php?action=ui_kit`.

Не создавайте альтернативные кнопки, поля, селекты, карточки и модалки. Если компонента нет, добавьте его в общий слой и продемонстрируйте в UI Kit.

## Иконки

Канонический набор — Tabler Icons. CSS и шрифт подключены в бандле; используйте классы `ti ti-*` или `MDJUI::icon('name')`. Каталог для UI Kit генерируется командой `npm run icons` в `lib/ui/tabler-icons.json`. Bootstrap Icons остаётся только для legacy-`glyphicon`.

## Searchable selects

Tom Select уже в бандле. Чтобы превратить обычный `<select>` в select с поиском, добавьте атрибут:

```html
<select name="class_id" class="form-select" data-md-searchable>
  ...
</select>
```

Инициализация выполняется автоматически в `resources/js/modules/admin_panel/70_controls.js` при загрузке страницы и при `partLoad`.

`linkedobject` — отдельный случай: это не searchable select, а редактор связей со свободным вводом. Его контроллер `120_linkedobject.js` и стили в `ui-system.css` входят в общий бандл, потому что компонент используется многими старыми модулями. Native input остаётся полем формы; не заменяйте его на `data-md-searchable`.

## Тема

`data-bs-theme` выставляется inline-скриптом в `common_header_admin.html` до загрузки CSS, чтобы не было вспышки светлой темы. Новые компоненты должны использовать переменные `--md-ui-*` и `--bs-*`.

## Удалённые локальные копии

После переезда на бандл удалены неиспользуемые копии:

- `3rdparty/bootstrap5/`
- `3rdparty/bootstrap-icons/`
- `3rdparty/tom-select/`
- `3rdparty/amcharts/`
- `3rdparty/codemirror/` (CodeMirror 5; заменён на CodeMirror 6 в lazy-chunk)
- `js/admin/admin_panel.js` (старый загрузчик модулей)

Перед удалением проверялись входящие ссылки. Если модуль всё ещё ссылается на старый путь — это ошибка, его нужно перевести на бандл.

## Ограничения

- jQuery обновлён до 3.7.1, `jquery-migrate` — 3.4.1. Старые модули проверены на основных страницах, но при появлении предупреждений Migrate их нужно чинить точечно.
- Legacy CSS (`admin_panel.css`, ~8 тыс. строк) пока входит в бандл целиком. Чистка мёртвых правил и разбиение на слои — отдельный этап.
- ApexCharts вынесен в `charts.js` и загружается только на страницах с графиками; на дашборде это происходит при первом построении чарта.
