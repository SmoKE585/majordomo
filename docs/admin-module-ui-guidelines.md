# UI-гайд для сторонних модулей админки MajorDoMo

Этот документ описывает, как оформлять страницы сторонних модулей в админке MajorDoMo, чтобы они соответствовали общей дизайн-системе и не ломали интерфейс.

Область применения: только административная панель. Пользовательские сцены и фронтовое меню больше не являются целевой частью MajorDoMo и не должны модернизироваться в рамках UI-миграции.

> Для новых страниц обязателен `DESIGN_SYSTEM.md` и готовые компоненты `MDJUI` / `templates/ui/components/`. Этот гайд описывает миграцию legacy-модулей и общие ограничения; если правила расходятся, приоритет у `DESIGN_SYSTEM.md` и `AGENTS.md`.

## Цель

Интерфейс админки должен выглядеть как единая система:

- современный чистый SaaS/admin UI;
- основной брендовый цвет: `#4792d1` (legacy); новый UI использует токен `--md-ui-primary`;
- шрифт Rubik;
- Bootstrap 5 как базовый UI-фреймворк;
- карточки, формы, таблицы, модалки и подсказки должны выглядеть одинаково во всех модулях;
- модуль не должен подключать свои версии Bootstrap, jQuery, Font Awesome или глобальные CSS reset;
- новый UI-код не должен зависеть от jQuery;
- локальные состояния — Alpine.js из общего бандла.

## Что уже есть в админке

Не подключайте повторно то, что уже загружено shell-ом админки.

- Bootstrap 5 CSS/JS.
- Tabler Icons (5 247 иконок; Bootstrap Icons — только legacy `glyphicon`).
- Rubik локально: `3rdparty/fonts/rubik/`.
- Общий CSS админки: `src/admin/css/admin_panel.css` (legacy, постепенно заменяется).
- Канонический слой компонентов: `src/admin/css/ui-system.css` + `MDJUI` + `templates/ui/components/`.
- Новый слой shell/dashboard: `src/admin/css/ui-v2.css`.
- Общий JS админки: `src/admin/js/modules/admin_panel/` (включая Alpine, data table, controls).
- Bootstrap 5 modal, dropdown, collapse, tooltip, popover; Tom Select; Tabler Icons.
- Общие цвета, тени, радиусы, layout и базовые legacy-compat классы.

Если модулю нужен свой JS/CSS, он должен быть модульным и не влиять на всю админку.

## Приоритет готовых компонентов

Цель — минимум собственного JavaScript. Новый UI не должен превращаться в набор самописных виджетов.

Порядок выбора решения:

1. Готовый компонент Bootstrap 5 (modal, dropdown, collapse, tabs, offcanvas, toast, tooltip).
2. Готовая проверенная библиотека, установленная через npm и подключённая через общий admin bundle (например, Tom Select, Flatpickr, SortableJS, ApexCharts).
3. Небольшая обвязка на vanilla JS только там, где нет готового решения.

Не писать свои аналоги уже существующих компонентов: drawers, combobox/select, date picker, tabs, toast, confirm-dialog, сортировка таблиц. Если компонента не хватает — сначала ищем библиотеку, и только потом пишем свой минимальный код.

Новые библиотеки не подключаются отдельными `<script>` в шаблонах модулей. Они добавляются в общий build и становятся доступны всем модулям.

## Основные правила

1. Используйте Bootstrap 5-разметку.
2. Не используйте Bootstrap 3 API и атрибуты.
3. Не добавляйте inline styles без крайней необходимости.
4. Не подключайте глобальные CSS-файлы из модуля, которые меняют `body`, `.row`, `.btn`, `.modal`, `.table` во всей админке.
5. Не добавляйте новый jQuery UI-код. Новый UI пишется на vanilla JS + Bootstrap 5 API.
6. Для новых классов используйте префикс модуля или `md-admin-`, если компонент общий.
7. Сохраняйте мобильную адаптивность.
8. Не ломайте dark/light theme: используйте CSS variables и Bootstrap variables.

## Где хранить CSS и JS

Для общих компонентов админки:

- новые общие стили и компоненты — `src/admin/css/ui-system.css` (канонический слой) и `MDJUI`/`templates/ui/components/`;
- `src/admin/css/ui-v2.css` — shell/dashboard; не добавляйте туда новые общие компоненты;
- `src/admin/css/admin_panel.css` считается legacy: не добавляйте туда новые компоненты без необходимости, только точечные правки существующих страниц;
- новый общий JS — модули в `src/admin/js/modules/admin_panel/`, подключаемые через `src/admin/main.js`;
- JS конкретной страницы модуля — локальный файл рядом с модулем, подключается только в шаблоне этого модуля и не регистрируется в общем bundle;
- перед созданием компонента проверьте UI Kit (`/admin.php?action=ui_kit`) и `DESIGN_SYSTEM.md`.

Для CSS/JS конкретного модуля:

- храните файлы рядом с модулем или в его папке assets, только если код действительно нужен одному модулю;
- называйте файлы явно: `module_name_admin.css`, `module_name_admin.js`;
- подключайте их только на страницах этого модуля;
- не делайте глобальные селекторы вроде `table { ... }`, `.btn { ... }`, `.row { ... }`.

Общий bundle — это только shell, дизайн-система, общие библиотеки и общие компоненты. CSS/JS конкретного модуля не переносите в `admin.css`/`admin.js`: иначе код всех установленных модулей будет грузиться на каждой странице.

Сборка:

```bash
npm install
npm run build
```

Подробности — в `docs/admin-assets.md`.

Select с поиском:

```html
<select name="class_id" class="form-select" data-md-searchable>
  ...
</select>
```

Tom Select уже в бандле и инициализируется автоматически.

### Связи объектов — исключение

`linkedobject` — не обычный select, а редактор связей со свободным вводом: значение может быть вызовом метода с параметрами, а исходный `input` уже существует в legacy-шаблоне и должен остаться полем формы. Поэтому у него собственный контроллер `MDLinkedObjectUI` (`src/admin/js/modules/admin_panel/120_linkedobject.js`) и собственный portal-dropdown. Не копируйте его разметку и не заменяйте поле на `data-md-searchable`; для нового кода используйте существующий шаблон:

```html
[#module name="linkedobject"
    object_field="linked_object"
    property_field="linked_property"
    method_field="linked_method"
    width="100%"#]
```

Native input с `name`/`id` должен быть отрисован до тега модуля. Компонент сам сохранит его обработчики и синхронизирует `change`. Демо — `/admin.php?action=ui_kit#linkedobject`.

Хорошо:

```css
.my-module-card {
  border-radius: 18px;
}

.my-module-table td {
  vertical-align: middle;
}
```

Плохо:

```css
.card {
  border-radius: 0;
}

table td {
  padding: 2px;
}

body {
  background: red;
}
```

## Bootstrap 5

Используйте Bootstrap 5 API:

```html
<button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#settingsModal">
  Настройки
</button>
```

Не используйте Bootstrap 3 API:

```html
<!-- Плохо -->
<button class="btn btn-default" data-toggle="modal" data-target="#settingsModal">
  Настройки
</button>
```

Правильные атрибуты Bootstrap 5:

- `data-bs-toggle`;
- `data-bs-target`;
- `data-bs-dismiss`;
- `data-bs-placement`;
- `data-bs-title`;
- `data-bs-content`.

Устаревшие атрибуты, которые не нужно добавлять:

- `data-toggle`;
- `data-target`;
- `data-dismiss`;
- `data-placement`;
- `data-original-title`.

## Модалки

Используйте Bootstrap 5 modal markup.

Хорошо:

```html
<div class="modal fade" id="settingsModal" tabindex="-1" aria-labelledby="settingsModalTitle" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="settingsModalTitle">Настройки</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Закрыть"></button>
      </div>
      <div class="modal-body">
        ...
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
        <button type="button" class="btn btn-primary">Сохранить</button>
      </div>
    </div>
  </div>
</div>
```

Плохо:

```html
<div class="modal" role="dialog">
  <button class="close" data-dismiss="modal">×</button>
</div>
```

Не вызывайте новые модалки через jQuery:

```js
// Плохо для нового кода
$('#settingsModal').modal('show');
```

Используйте Bootstrap 5 JS API:

```js
const modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('settingsModal'));
modal.show();
```

## Tooltips и popovers

Подсказки должны быть короткими. Не превращайте tooltip в документацию.

Хорошо:

```html
<button class="btn btn-light" data-bs-toggle="tooltip" data-bs-title="Перезапустить сервис">
  Restart
</button>
```

Плохо:

```html
<span title="Очень длинный текст на пять строк с HTML и инструкцией...">?</span>
```

Инициализация Bootstrap tooltips/popovers уже есть в общем admin JS. Не добавляйте повторную инициализацию на каждой странице без необходимости.

## Карточки и панели

Новые страницы должны использовать карточную структуру, а не Bootstrap 3 `panel`.

Хорошо:

```html
<section class="md-admin-section-card my-module-card">
  <header class="md-admin-section-card__header">
    <h2>Сценарии</h2>
    <a class="btn btn-primary btn-sm" href="?view_mode=edit">Добавить</a>
  </header>
  <div class="md-admin-section-card__body">
    ...
  </div>
</section>
```

Плохо:

```html
<div class="panel panel-default">
  <div class="panel-heading">
    <h3 class="panel-title">Сценарии</h3>
  </div>
  <div class="panel-body">...</div>
</div>
```

Если общий компонент уже есть, используйте его. Если компонента нет, делайте локальные классы модуля и не переопределяйте базовые Bootstrap классы.

## Формы

Формы должны быть спокойными, читаемыми и предсказуемыми.

Хорошо:

```html
<div class="mb-3">
  <label for="deviceTitle" class="form-label">Название</label>
  <input id="deviceTitle" class="form-control" name="title" value="">
  <div class="form-text">Отображается в списке устройств.</div>
</div>
```

Плохо:

```html
<tr>
  <td width="20%">Название</td>
  <td><input style="width:100%;height:20px;color:red" name="title"></td>
</tr>
```

Правила:

- используйте `form-label`, `form-control`, `form-select`, `form-check`;
- группируйте действия в нижней части формы;
- primary action должен быть один;
- destructive action должен быть визуально отделен;
- показывайте ошибки рядом с полем.

## Таблицы

Таблицы нужны для плотных данных, но они не должны выглядеть как старый Excel.

Хорошо:

```html
<div class="table-responsive">
  <table class="table table-hover align-middle my-module-table">
    <thead>
      <tr>
        <th>Название</th>
        <th>Статус</th>
        <th class="text-end">Действия</th>
      </tr>
    </thead>
    <tbody>...</tbody>
  </table>
</div>
```

Правила:

- используйте `table-responsive`;
- действия выравнивайте вправо;
- не делайте 10 кнопок в строке без группировки;
- для статусов используйте badge;
- на мобильных таблица должна скроллиться горизонтально внутри контейнера.

## Загрузка и async-состояния

Не используйте старые gif loader.

Плохо:

```html
<img src="/img/ajax-loader.gif">
```

Хорошо:

```html
<div class="d-flex align-items-center gap-2 text-muted">
  <span class="spinner-border spinner-border-sm" aria-hidden="true"></span>
  <span>Загрузка...</span>
</div>
```

Правила:

- показывайте loading state рядом с блоком, который загружается;
- не блокируйте всю страницу без причины;
- на кнопках, которые запускают запрос, временно ставьте disabled;
- показывайте success/error inline, а не только через `alert()`.

## JavaScript

Новый UI-код должен быть минимальным: Bootstrap 5 + готовая библиотека + тонкая обвязка.

Правила:

- сначала проверьте, решает ли задачу штатный компонент Bootstrap 5;
- если нет — проверенная библиотека через npm и общий bundle;
- свой JS пишите только как «клей»: загрузить данные, обновить DOM, зарегистрировать обработчик;
- не пишите собственные виджеты и не дублируйте функциональность библиотек;
- не добавляйте отдельные `<script>` в шаблоны модулей, если код можно вынести в общий bundle;
- данные для страницы лучше отдавать с сервера готовой разметкой, а JS использовать для обновления/интерактивности;
- новый код не должен зависеть от jQuery.

Хорошо:

```js
document.addEventListener('click', function (event) {
  const button = event.target.closest('[data-my-module-action]');
  if (!button) return;
  // ...
});
```

Плохо:

```html
<button onclick="$('#block').hide(); $('#other').show(); return false;">
  Click
</button>
```

jQuery допускается только как временная legacy-зависимость в старом коде, который еще не переписан. Если при миграции пришлось оставить jQuery-вызов, рядом должна быть понятная причина и следующий шаг по удалению зависимости.

Нельзя писать новый UI на jQuery. Нельзя добавлять новые jQuery-плагины. Нельзя делать новые inline handlers с `$('#...')`.

## Inline styles

Inline styles почти всегда запрещены.

Плохо:

```html
<div style="padding:10px;background:#000080;color:#c0c0c0;border-radius:5px">
  ...
</div>
```

Хорошо:

```html
<div class="my-module-console">
  ...
</div>
```

```css
.my-module-console {
  padding: 12px;
  color: #dbeafe;
  background: #101827;
  border-radius: 14px;
}
```

Допустимые исключения:

- CSS custom property с динамическим значением:

```html
<span class="my-module-color" style="--item-color:#4792d1"></span>
```

- координаты/размеры, которые генерирует сторонний виджет;
- временный legacy-код во время миграции, если сразу вынести нельзя.

## Цвета

Основной цвет: `#4792d1`.

Используйте его для:

- primary buttons;
- active states;
- важных ссылок;
- акцентов;
- selected menu item;
- focus state.

Не используйте случайные яркие цвета без значения.

Плохо:

```css
.status {
  background: lime;
}
```

Хорошо:

```html
<span class="badge text-bg-success">OK</span>
<span class="badge text-bg-warning">Warning</span>
<span class="badge text-bg-danger">Error</span>
```

## Типографика

В админке используется Rubik.

Правила:

- обычный текст: `font-weight: 400`;
- вторичные labels: `500`;
- заголовки и важные action/badge: `600`;
- не используйте `700+` без причины;
- не задавайте `font-family` в модуле, если не нужен monospace-код.

Для кода и логов:

```css
.my-module-code {
  font-family: Consolas, Monaco, monospace;
}
```

## Иконки

В админке уже есть **Tabler Icons** (5 247 иконок). Это канонический набор для нового UI.

Не подключайте Font Awesome, Heroicons или вторую библиотеку ради пары иконок.

Хорошо:

```html
<i class="ti ti-settings" aria-hidden="true"></i>
```

```php
<?= MDJUI::icon('settings') ?>
```

Полный каталог с поиском — в UI Kit (`/admin.php?action=ui_kit#foundations`). Новые `bi bi-*` не добавляются. Bootstrap Icons остаётся только для legacy-`glyphicon`; если модуль пока использует старые `glyphicon`, не добавляйте новые.

## Мобильная адаптация

Модуль должен работать в ширине 360px.

Проверяйте:

- таблицы обернуты в `table-responsive`;
- action buttons не вылезают за экран;
- карточки становятся одной колонкой;
- модалки не шире viewport;
- длинные строки имеют перенос или внутренний скролл;
- sidebar на мобильном уже работает как drawer, модуль не должен добавлять свой глобальный sidebar.

## Доступность

Минимальные правила:

- интерактивный элемент должен быть `button` или `a`;
- у icon-only кнопок должен быть `aria-label`;
- у модалки должен быть `aria-labelledby`;
- focus state должен быть видимым;
- цвет не должен быть единственным способом показать состояние;
- не удаляйте keyboard navigation.

Плохо:

```html
<div onclick="save()">💾</div>
```

Хорошо:

```html
<button type="button" class="btn btn-primary" aria-label="Сохранить" onclick="save()">
  Сохранить
</button>
```

## Что нельзя делать

Нельзя:

- подключать Bootstrap 3;
- подключать свою версию Bootstrap 5 поверх системной;
- подключать свой jQuery ради UI;
- писать новый UI-код на jQuery;
- добавлять новые jQuery-плагины;
- писать свои drawers/combobox/datepicker/tabs/toast, если есть готовая библиотека или компонент Bootstrap;
- подключать библиотеку отдельным `<script>` в шаблоне модуля в обход общего bundle;
- переопределять глобальные `.btn`, `.row`, `.modal`, `.table`, `body`;
- использовать `data-toggle`, `data-target`, `data-dismiss`;
- добавлять новые `panel panel-default`;
- использовать `btn-default`;
- использовать `alert()` как основной UI ошибок;
- делать fixed headers/sidebars внутри модуля;
- добавлять глобальные reset/normalize CSS;
- скрывать overflow страницы, если это не локальный компонент;
- вставлять большие inline `<script>` блоки с UI-логикой.
- модернизировать пользовательские сцены и фронтовое меню как часть новой админки;
- сохранять или переносить старый модуль фронтового меню, если он больше не нужен.

## Что желательно делать

Желательно:

- использовать карточки и секции;
- держать actions справа или в явной action row;
- использовать `btn-primary` только для главного действия;
- использовать `btn-light`, `btn-outline-secondary` для вторичных действий;
- выносить повторяющиеся блоки в include/template;
- делать понятные empty states;
- показывать loading/error/success состояния;
- проверять mobile;
- проверять темную тему, если модуль использует кастомные цвета;
- писать CSS с префиксом модуля.

## Чеклист перед отправкой модуля

Перед тем как считать UI модуля готовым:

- Нет подключений Bootstrap/jQuery/Font Awesome поверх системных.
- Нет `data-toggle`, `data-target`, `data-dismiss`.
- Нет новых `panel panel-*`.
- Нет `btn-default`.
- Нет глобальных CSS-селекторов, влияющих на всю админку.
- Нет больших inline styles.
- Модалки используют Bootstrap 5.
- Tooltips/popovers используют `data-bs-*`.
- Таблицы адаптивны.
- Формы имеют labels.
- Loading state не использует `img/ajax-loader.gif`.
- На мобильном ничего не вылезает за viewport.
- Важные кнопки доступны с клавиатуры.
- Проверен внешний вид в общей админке, а не только отдельно в модуле.

## Пример структуры страницы модуля

```html
<div class="my-module-page">
  <section class="md-admin-section-card my-module-card">
    <header class="md-admin-section-card__header">
      <div>
        <h2>Название модуля</h2>
        <p class="my-module-subtitle">Краткое описание состояния или назначения страницы.</p>
      </div>
      <a href="?view_mode=edit" class="btn btn-primary btn-sm">Добавить</a>
    </header>

    <div class="md-admin-section-card__body">
      <div class="table-responsive">
        <table class="table table-hover align-middle my-module-table">
          ...
        </table>
      </div>
    </div>
  </section>
</div>
```

```css
.my-module-page {
  display: grid;
  gap: 18px;
}

.my-module-subtitle {
  margin: 4px 0 0;
  color: var(--bs-secondary-color);
}

.my-module-table {
  margin-bottom: 0;
}
```

## Миграция старого модуля

Не нужно переписывать все за один коммит. Безопасный порядок:

1. Удалить лишние подключения Bootstrap/jQuery/CSS.
2. Заменить `data-toggle` на `data-bs-toggle`.
3. Заменить `panel` на card/section layout.
4. Вынести inline styles в CSS.
5. Перевести модалки на Bootstrap 5.
6. Заменить gif loaders на spinners.
7. Проверить mobile.
8. Убрать jQuery UI-код там, где он больше не нужен.

Если файл или компонент больше не используется, не удаляйте его сразу. Сначала пометьте как `DEPRECATED` и проверьте входящие ссылки.

## Сцены и фронтовое меню

Пользовательские сцены и отдельный модуль фронтового меню выводятся из новой системы. Их не нужно адаптировать под новую дизайн-систему админки.

Правила:

- не переносить их CSS/JS в `src/admin/css/admin_panel.css` и `js/admin/admin_panel.js`;
- не использовать их как пример для новых модулей;
- не добавлять совместимость ради старого фронтового меню;
- при обнаружении неиспользуемых файлов сцен/фронтового меню готовить отдельный cleanup: найти входящие ссылки, удалить или пометить `DEPRECATED`, затем убрать подключения;
- если старый модуль фронтового меню больше не нужен, его можно удалять отдельным явным рефакторингом вместе со стилями, шаблонами и подключениями.

