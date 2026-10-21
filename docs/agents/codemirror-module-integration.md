# Code editor integration guide for MajorDoMo modules

Canonical way to embed the shared admin code editor in MajorDoMo modules.

The editor is **CodeMirror 6** and is built as a separate lazy chunk. Do not
load CodeMirror from a template and do not add vendor files.

## What to use

- Wrapper / behaviour: `resources/admin/js/codeeditor.js`
- Styles: `resources/admin/css/codeeditor.css`
- Lazy loader (already in the main admin bundle): `resources/admin/js/codeeditor-loader.js`
- Build entry: `resources/admin/codeeditor-entry.js`
- Build config: `vite.codeeditor.config.js`
- Output: `public/assets/admin/codeeditor.js` + `public/assets/admin/codeeditor.css`
- Asset URLs: `window.MDCodeEditorConfig` in `templates/common_header_admin.html`

The loader fetches the chunk only when the page contains `[data-code-editor]`
or when the console drawer is opened. New modules must not add
`<script src="...codeeditor...">` or `<link>` tags.

## Required editor structure

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

The editor generates its own footer (`Стр/стлб`, язык, `Изменено`, UTF-8).
Do not duplicate it in the template.

## Toolbar rules

- Use `md-code-editor__action` for text actions and `md-code-editor__icon-btn`
  for icon-only actions.
- Always provide `title` and `aria-label`; labels are hidden below 992 px.
- The mode switch is optional. For a single-language field use
  `<span class="md-code-editor__lang-badge">PHP</span>` instead.
- Do not use Bootstrap `btn` classes inside the editor toolbar.
- Icons are Tabler (`ti ti-*`). Typical actions: `ti-device-floppy`,
  `ti-history`, `ti-bug`, `ti-search`, `ti-maximize`, `ti-minimize`,
  `ti-player-play`, `ti-eraser`.

## Backend contract

Each editor-backed module should provide:

- `CODE_EDITOR_KEY` — stable unique key per editor instance;
- `CODE_EDITOR_MODE` — initial mode, `php` or `python`;
- `CODE_EDITOR_VALIDATE` — `1` for PHP/Python, `0` otherwise;
- `ERR_OLD_CODE_B64` — base64 of the previous code for restore.

For validation pass the selected mode:

```php
$errorDetails = code_syntax_error_details($code, $code_editor_mode);
```

## Mode handling

MajorDoMo methods/scripts store PHP without `<?php`. The editor uses
`php({ plain: true })`, which parses the whole document as PHP. Do not switch
the PHP mode to an HTML-first parser: the opening tag is usually absent.

The mode selector is the source of truth for highlighting, validation and the
restore context.

## Keyboard shortcuts

Indentation is **tabs** (`indentUnit` is `\t`, display width 4), matching the
legacy MajorDoMo code style.

| Shortcut | Action |
| --- | --- |
| `Ctrl+Alt+L` / `Ctrl+Alt+I` | Re-indent selection or whole document (PhpStorm-style) |
| `Tab` / `Shift+Tab` | Increase / decrease indent with tabs |
| `Ctrl+/` | Toggle comment |
| `Ctrl+D` | Duplicate line |
| `Ctrl+Y` | Delete line |
| `Shift+Alt+↑/↓` | Move line up/down |
| `Ctrl+F` | Search and replace |
| `Ctrl+G` | Go to line |
| `Ctrl+Space` | Autocomplete |
| `Ctrl+S` | Save autosave snapshot |
| `Ctrl+R` | Version history |
| `Ctrl+E` | Validate code |
| `F11` / `Esc` | Enter / leave fullscreen |
| `Ctrl+Enter` | Execute (console only) |

The keyboard icon in the editor toolbar opens a popover with this list. The
source of truth is `SHORTCUT_GROUPS` in `resources/admin/js/codeeditor.js`; do not
duplicate the list in module templates.

Shortcuts must also work on the Russian keyboard layout. CodeMirror's keymap
matches `event.key`, which is Cyrillic there. Do not remove the
`handleLayoutShortcut` / `event.code` fallback in `resources/admin/js/codeeditor.js`: it
maps physical keys (`KeyL`, `KeyY`, `Slash`, …) independently of the layout.

On mobile (≤767 px) the keyboard icon is hidden and the popover is not used:
shortcuts are a desktop-only affordance. Keep the core editing buttons
(save/history/search/fullscreen) visible there.

## Autosave and restore

- Endpoint: `modules/blockly_code/autosave.php`.
- Restore storage: `cms/cached/codeeditor`.
- `key` must be stable and unique.
- `action=save`, `action=restore`, `action=checkcode` are handled by the
  wrapper; modules only provide the URL and the `CODE_EDITOR_KEY`.

## Compatibility facade

`wrapper._codeEditor` is a small CM5-like facade used by legacy module code:

- `getValue()`, `setValue()`, `focus()`, `save()`;
- `getCursor()`, `setCursor()`, `lastLine()`, `getLine()`, `lineCount()`;
- `on('change'|'cursorActivity'|'blur'|'focus')`;
- `addKeymap({ 'Mod-Enter': fn, ... })`;
- `setErrorLine(line, message)`, `clearError()`;
- `setOption('mode'|'lineWrapping'|'readOnly'|'fullScreen'|'theme', value)`.

New code should prefer these methods over DOM manipulation. Do not rely on
`.CodeMirror` elements; they no longer exist.

## Error handling rules

- Server-side syntax errors should be normalized before rendering.
- Use `code_syntax_error_details($code, $mode)`.
- In templates use `ERR_FULL`, `ERRORS`, `ERR_LINE`, `ERR_OLD_CODE_B64`.
- Call `editor.setErrorLine($line, $message)`; the wrapper renders the line
  highlight, lint marker and scrolls the line into view.

## Performance rules

- Keep `data-code-editor` only on pages that really need the editor.
- For editors inside a closed drawer or modal, add `data-code-editor-lazy="1"`
  and call `window.MDCodeEditor.init(container)` when the container opens.
- The console in `templates/panel.html` uses this pattern; see
  `resources/admin/js/modules/admin_panel/50_console.js`.
- Do not import CodeMirror packages in `resources/admin/main.js`. The editor has its
  own entry and build config.

## Refactoring checklist

1. Remove old CodeMirror/Blockly vendor embeds.
2. Add the shared `data-code-editor` block.
3. Provide `CODE_EDITOR_KEY`, `CODE_EDITOR_MODE`, `CODE_EDITOR_VALIDATE`.
4. Pass `code_editor_mode` from the form to the backend.
5. Use `code_syntax_error_details($code, $mode)` on save/validate.
6. Keep the restore button wired through `data-code-editor-action="restore-error"`.
7. Do not add global `<script>`/`<link>` for the editor; the loader handles it.
8. Verify light/dark theme, 390 px width, F11/Esc, Ctrl+S, search and console.
