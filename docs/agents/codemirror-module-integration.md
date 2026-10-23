---
title: Интеграция редактора кода в модули
audience: agent
category: ui
tags: codemirror, редактор кода, модули, data-code-editor, lazy-chunk
description: Канонический способ встроить общий редактор CodeMirror 6 в модуль MajorDoMo — разметка, data-атрибуты, backend-контракт, горячие клавиши и правила производительности.
order: 70
---

# Интеграция редактора кода в модули MajorDoMo

## Коротко

Общий редактор админки — CodeMirror 6, собранный отдельным lazy-chunk `public/assets/admin/codeeditor.js` + `codeeditor.css`. В шаблоне нужна только разметка `[data-code-editor]` с классами `md-code-editor__*`; загрузчик уже входит в основной бандл и сам скачивает chunk. Не подключайте CodeMirror из шаблона и не добавляйте вендорные файлы.

Канонический способ встроить общий редактор кода админки в модуль MajorDoMo.

Редактор — **CodeMirror 6**, собранный отдельным lazy-chunk. Не загружайте CodeMirror из шаблона и не добавляйте вендорные файлы.

## Что использовать

- Обвязка и поведение: `resources/js/codeeditor/editor.js`
- Стили: `resources/css/codeeditor.css`
- Lazy-загрузчик (уже в основном бандле админки): `resources/js/codeeditor/loader.js`
- Entry сборки: `resources/js/codeeditor/index.js`
- Конфиг сборки: `vite.codeeditor.config.js`
- Результат: `public/assets/admin/codeeditor.js` + `public/assets/admin/codeeditor.css`
- URL ассетов: `window.MDCodeEditorConfig` в `templates/common_header_admin.html`

Загрузчик скачивает chunk только когда на странице есть `[data-code-editor]` или открывается drawer консоли. Новые модули не должны добавлять `<script src="...codeeditor...">` или `<link>`.

## Обязательная структура редактора

```html
<div class="md-code-editor"
     data-code-editor
     data-code-editor-key="..."
     data-code-editor-mode="php|python"
     data-code-editor-theme="auto|light|dark"
     data-code-editor-autosave="..."
     data-code-editor-autoclose="..."
     data-code-editor-wraplines="..."
     data-code-editor-min-lines="..."
     data-code-editor-max-lines="..."
     data-code-editor-autofocus="0|1"
     data-code-editor-validate-url=".../modules/blockly_code/autosave.php"
     data-code-editor-autosave-url=".../modules/blockly_code/autosave.php"
     data-code-editor-id="..."
     data-code-editor-md="..."
     data-code-editor-hide-errors-on-edit="0|1">

  <div class="md-code-editor__toolbar" data-code-editor-toolbar>
    <label class="md-code-editor__mode-switch" data-mode="php">
      <input type="checkbox" data-code-editor-mode-toggle aria-label="Режим редактора">
      <span class="md-code-editor__mode-switch-option md-code-editor__mode-switch-option--php">PHP</span>
      <span class="md-code-editor__mode-switch-option md-code-editor__mode-switch-option--python">Python</span>
      <input type="hidden" name="code_editor_mode" value="php" data-code-editor-mode-select>
    </label>

    <span class="md-code-editor__toolbar-divider" aria-hidden="true"></span>
    <div class="md-code-editor__toolbar-group">
      <button type="button" class="md-code-editor__action" data-code-editor-action="save" title="Сохранить версию (Ctrl+S)" aria-label="Сохранить версию">
        <i class="ti ti-device-floppy" aria-hidden="true"></i>
        <span class="md-code-editor__action-label">Сохранить</span>
      </button>
      <button type="button" class="md-code-editor__action" data-code-editor-action="restore" title="История версий (Ctrl+R)" aria-label="История версий">
        <i class="ti ti-history" aria-hidden="true"></i>
        <span class="md-code-editor__action-label">Восстановить</span>
      </button>
    </div>

    <div class="md-code-editor__toolbar-spacer"></div>
    <span class="md-code-editor__status" data-code-editor-status aria-live="polite"></span>
    <button type="button" class="md-code-editor__icon-btn" data-code-editor-action="search" title="Найти (Ctrl+F)" aria-label="Найти">
      <i class="ti ti-search" aria-hidden="true"></i>
    </button>
    <button type="button" class="md-code-editor__icon-btn" data-code-editor-action="fullscreen" title="Полный экран (F11)" aria-label="Полный экран">
      <i class="ti ti-maximize" aria-hidden="true"></i>
    </button>
  </div>

  <div class="md-code-editor__error" data-code-editor-error data-code-editor-old-code-b64="...">
    ...
  </div>

  <textarea name="code" id="code">...</textarea>

  <div class="md-code-editor__drawer-content" data-code-editor-drawer-content hidden
       data-md-drawer-title="История" data-md-drawer-subtitle="Сохранённые версии">
    <div class="md-code-editor__drawer-body">
      <div class="md-code-editor__section">
        <h4>История</h4>
        <div class="md-code-editor__restore-list" data-code-editor-autosave-list></div>
        <pre class="md-code-editor__preview" data-code-editor-preview hidden></pre>
      </div>
    </div>
  </div>
</div>
```

Редактор сам генерирует футер (`Стр/стлб`, язык, `Изменено`, UTF-8). Не дублируйте его в шаблоне.

## Правила toolbar

- Для текстовых действий используйте `md-code-editor__action`, для icon-only — `md-code-editor__icon-btn`.
- Всегда задавайте `title` и `aria-label`; подписи скрываются при ширине меньше 992 px.
- Переключатель режима необязателен. Для поля с одним языком используйте `<span class="md-code-editor__lang-badge">PHP</span>`.
- Не используйте классы Bootstrap `btn` внутри toolbar редактора.
- Иконки — Tabler (`ti ti-*`). Типичные действия: `ti-device-floppy`, `ti-history`, `ti-bug`, `ti-search`, `ti-maximize`, `ti-minimize`, `ti-player-play`, `ti-eraser`.

## Контракт бэкенда

Каждый модуль с редактором должен предоставить:

- `CODE_EDITOR_KEY` — стабильный уникальный ключ каждого экземпляра редактора;
- `CODE_EDITOR_MODE` — начальный режим, `php` или `python`;
- `CODE_EDITOR_VALIDATE` — `1` для PHP/Python, `0` в остальных случаях;
- `ERR_OLD_CODE_B64` — base64 предыдущего кода для восстановления.

Для валидации передавайте выбранный режим:

```php
$errorDetails = code_syntax_error_details($code, $code_editor_mode);
```

## Работа с режимами

Методы и сценарии MajorDoMo хранят PHP без `<?php`. Редактор использует `php({ plain: true })`, который разбирает весь документ как PHP. Не переключайте PHP-режим на HTML-first-парсер: открывающего тега обычно нет.

После инициализации переключатель режима — источник истины для подсветки, валидации и контекста восстановления. При инициализации сохранённый в `localStorage` режим имеет приоритет над `data-code-editor-mode`; это позволяет редактору запоминать выбор пользователя.

Если `PYTHON_PATH` не задан, `common_header_admin.html` передаёт `window.MDCodeEditorConfig.pythonAvailable = false`: переключатель Python блокируется, а подсказка просит указать путь в настройках системы. Модулю не нужно дублировать эту проверку — достаточно стандартной разметки переключателя.

## Горячие клавиши

Отступы — **табы** (`indentUnit` — `\t`, ширина отображения 4), как в legacy-стиле кода MajorDoMo.

| Сочетание | Действие |
| --- | --- |
| `Ctrl+Alt+L` / `Ctrl+Alt+I` | Переиндентировать выделение или весь документ (как в PhpStorm) |
| `Tab` / `Shift+Tab` | Увеличить / уменьшить отступ табами |
| `Ctrl+/` | Закомментировать / раскомментировать |
| `Ctrl+D` | Дублировать строку |
| `Ctrl+Y` | Удалить строку |
| `Shift+Alt+↑/↓` | Переместить строку вверх/вниз |
| `Ctrl+F` | Поиск и замена |
| `Ctrl+G` | Перейти к строке |
| `Ctrl+Space` | Автодополнение |
| `Ctrl+S` | Сохранить снимок автосохранения |
| `Ctrl+R` | История версий |
| `Ctrl+E` | Проверить код |
| `F11` / `Esc` | Войти в полный экран / выйти из него |
| `Ctrl+Enter` | Выполнить (только консоль) |

Иконка клавиатуры в toolbar открывает popover с этим списком. Источник истины — `SHORTCUT_GROUPS` в `resources/js/codeeditor/editor.js`; не дублируйте список в шаблонах модулей.

Сочетания должны работать и на русской раскладке. Keymap CodeMirror сопоставляет `event.key`, а там кириллица. Не удаляйте fallback `handleLayoutShortcut` / `event.code` в `resources/js/codeeditor/editor.js`: он сопоставляет физические клавиши (`KeyL`, `KeyY`, `Slash` и т. д.) независимо от раскладки.

На мобильных (≤767 px) иконка клавиатуры скрыта, popover не используется: горячие клавиши — возможность для desktop. Основные кнопки редактирования (сохранить/история/поиск/полный экран) должны оставаться видимыми.

## Автосохранение и восстановление

- Endpoint: `modules/blockly_code/autosave.php`.
- Хранилище версий: `cms/cached/codeeditor`.
- `key` должен быть стабильным и уникальным.
- `action=save`, `action=restore`, `action=checkcode` обрабатывает обвязка; модуль предоставляет только URL и `CODE_EDITOR_KEY`.

## Фасад совместимости

`wrapper._codeEditor` — небольшой фасад в стиле CM5 для legacy-кода модулей:

- `getValue()`, `setValue()`, `focus()`, `save()`;
- `getCursor()`, `setCursor()`, `lastLine()`, `getLine()`, `lineCount()`;
- `on('change'|'cursorActivity'|'blur'|'focus')`;
- `addKeymap({ 'Mod-Enter': fn, ... })`;
- `setErrorLine(line, message)`, `clearError()`;
- `setOption('mode'|'lineWrapping'|'readOnly'|'fullScreen'|'theme', value)`.

Новый код должен предпочитать эти методы прямому изменению DOM. Не полагайтесь на элементы `.CodeMirror`; их больше нет.

## Правила обработки ошибок

- Серверные синтаксические ошибки должны быть нормализованы до рендера.
- Используйте `code_syntax_error_details($code, $mode)`.
- В шаблонах используйте `ERR_FULL`, `ERRORS`, `ERR_LINE`, `ERR_OLD_CODE_B64`.
- Вызывайте `editor.setErrorLine($line, $message)`; обвязка подсветит строку, поставит lint-маркер и прокрутит к строке.

## Правила производительности

- Оставляйте `data-code-editor` только на страницах, которым редактор действительно нужен.
- Для редакторов в закрытом drawer или modal добавьте `data-code-editor-lazy="1"` и вызывайте `window.MDCodeEditor.init(container)` при открытии контейнера.
- Консоль в `templates/panel.html` использует именно этот паттерн; см. `resources/js/modules/admin_panel/50_console.js`.
- Не импортируйте пакеты CodeMirror в `resources/js/app.js`. У редактора свой entry и свой конфиг сборки.

## Чек-лист рефакторинга

1. Удалите старые вендорные вставки CodeMirror/Blockly.
2. Добавьте общий блок `data-code-editor`.
3. Передайте `CODE_EDITOR_KEY`, `CODE_EDITOR_MODE`, `CODE_EDITOR_VALIDATE`.
4. Передавайте `code_editor_mode` из формы на бэкенд.
5. Используйте `code_syntax_error_details($code, $mode)` при сохранении и проверке.
6. Оставьте кнопку восстановления привязанной через `data-code-editor-action="restore-error"`.
7. Не добавляйте глобальные `<script>`/`<link>` для редактора; загрузчик всё сделает сам.
8. Проверьте светлую/тёмную тему, ширину 390 px, F11/Esc, Ctrl+S, поиск и консоль.
