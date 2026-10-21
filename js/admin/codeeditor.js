/*
 * MajorDoMo admin code editor.
 *
 * CodeMirror 6 wrapper with a small compatibility facade for the old
 * CodeMirror 5 call-sites (`getValue`, `setValue`, `on`, `extraKeys`, ...).
 * The module is built as a separate lazy chunk; see codeeditor-loader.js.
 */
import { Compartment, EditorState, Prec, StateEffect, StateField } from '@codemirror/state';
import {
    Decoration,
    EditorView,
    crosshairCursor,
    drawSelection,
    dropCursor,
    highlightActiveLine,
    highlightActiveLineGutter,
    highlightSpecialChars,
    keymap,
    lineNumbers,
    rectangularSelection
} from '@codemirror/view';
import {
    copyLineDown,
    defaultKeymap,
    deleteLine,
    history,
    historyKeymap,
    indentWithTab,
    moveLineDown,
    moveLineUp,
    toggleComment
} from '@codemirror/commands';
import {
    gotoLine,
    openSearchPanel,
    highlightSelectionMatches,
    search,
    searchKeymap
} from '@codemirror/search';
import {
    autocompletion,
    closeBrackets,
    closeBracketsKeymap,
    completionKeymap,
    startCompletion
} from '@codemirror/autocomplete';
import {
    HighlightStyle,
    bracketMatching,
    codeFolding,
    foldCode,
    foldKeymap,
    indentOnInput,
    indentRange,
    indentUnit,
    syntaxHighlighting
} from '@codemirror/language';
import { lintKeymap, setDiagnostics } from '@codemirror/lint';
import { php } from '@codemirror/lang-php';
import { python } from '@codemirror/lang-python';
import { html } from '@codemirror/lang-html';
import { javascript } from '@codemirror/lang-javascript';
import { css } from '@codemirror/lang-css';
import { xml } from '@codemirror/lang-xml';
import { tags } from '@lezer/highlight';

var DEFAULT_LINE_HEIGHT = 21;
var DEFAULT_VISIBLE_MIN_LINES = 5;
var DARK_THEME_NAMES = ['ambiance', 'dracula', 'material', 'moxer', 'smoke_theme'];
var LIGHT_THEME_NAMES = ['base16-light', 'icecoder', 'neat'];
var moduleRegistered = false;
var openShortcutsPopover = null;

function toArray(list) {
    return Array.prototype.slice.call(list || []);
}

function decodeBase64(text) {
    if (!text) {
        return '';
    }
    try {
        var normalized = String(text).replace(/\s+/g, '');
        var binary = window.atob(normalized);
        if (window.TextDecoder) {
            var bytes = new Uint8Array(binary.length);
            for (var i = 0; i < binary.length; i += 1) {
                bytes[i] = binary.charCodeAt(i);
            }
            return new TextDecoder('utf-8').decode(bytes);
        }
        return decodeURIComponent(escape(binary));
    } catch (e) {
        return '';
    }
}

function normalizeMode(mode) {
    mode = (mode || 'php').toLowerCase();
    if (mode === 'text/x-php' || mode === 'php') return 'php';
    if (mode === 'html' || mode === 'htmlmixed' || mode === 'text/html') return 'htmlmixed';
    if (mode === 'javascript' || mode === 'text/javascript' || mode === 'application/javascript') return 'javascript';
    if (mode === 'python' || mode === 'text/x-python') return 'python';
    if (mode === 'css') return 'css';
    if (mode === 'xml' || mode === 'text/xml' || mode === 'application/xml') return 'xml';
    return mode;
}

function modeLabel(mode) {
    if (mode === 'php') return 'PHP';
    if (mode === 'python') return 'Python';
    if (mode === 'htmlmixed') return 'HTML';
    if (mode === 'javascript') return 'JavaScript';
    if (mode === 'css') return 'CSS';
    if (mode === 'xml') return 'XML';
    return String(mode || '').toUpperCase();
}

function languageFor(mode) {
    if (mode === 'python') {
        return python();
    }
    if (mode === 'htmlmixed') {
        return html();
    }
    if (mode === 'javascript') {
        return javascript();
    }
    if (mode === 'css') {
        return css();
    }
    if (mode === 'xml') {
        return xml();
    }
    // MajorDoMo methods/scripts are stored without an opening `<?php` tag.
    return php({ plain: true });
}

function resolveAppearance(theme) {
    var value = String(theme || '').toLowerCase();
    if (value === 'dark' || DARK_THEME_NAMES.indexOf(value) !== -1) {
        return 'dark';
    }
    if (value === 'light' || LIGHT_THEME_NAMES.indexOf(value) !== -1) {
        return 'light';
    }
    return 'auto';
}

function getCodeEditorDefaults() {
    var config = window.MDJAdminConfig || {};
    return config.codeEditorDefaults || {};
}

function getCodeEditorDefault(name, fallback) {
    var defaults = getCodeEditorDefaults();
    return typeof defaults[name] === 'undefined' || defaults[name] === null || defaults[name] === ''
        ? fallback
        : defaults[name];
}

function applyWrapperDefaults(wrapper) {
    if (!wrapper || wrapper.dataset.codeEditorDefaultsApplied === '1') {
        return;
    }
    if (!wrapper.dataset.codeEditorTheme) {
        wrapper.dataset.codeEditorTheme = String(getCodeEditorDefault('theme', 'auto'));
    }
    if (!wrapper.dataset.codeEditorAutosave) {
        wrapper.dataset.codeEditorAutosave = String(getCodeEditorDefault('autosave', 0));
    }
    if (!wrapper.dataset.codeEditorAutoclose) {
        wrapper.dataset.codeEditorAutoclose = String(getCodeEditorDefault('autoclose', 1));
    }
    if (!wrapper.dataset.codeEditorWraplines) {
        wrapper.dataset.codeEditorWraplines = String(getCodeEditorDefault('wraplines', 0));
    }
    if (!wrapper.dataset.codeEditorMinLines) {
        wrapper.dataset.codeEditorMinLines = String(getCodeEditorDefault('minLines', 20));
    }
    if (!wrapper.dataset.codeEditorMaxLines) {
        wrapper.dataset.codeEditorMaxLines = String(getCodeEditorDefault('maxLines', 20));
    }
    if (!wrapper.dataset.codeEditorHideErrorsOnEdit) {
        wrapper.dataset.codeEditorHideErrorsOnEdit = String(getCodeEditorDefault('showError', 0)) === '1' ? '1' : '0';
    }
    wrapper.dataset.codeEditorDefaultsApplied = '1';
}

function modeStorageKey(wrapper) {
    return 'md-codeeditor-mode:' + String(wrapper.dataset.codeEditorKey || '');
}

function getStoredMode(wrapper) {
    try {
        return normalizeMode(window.localStorage.getItem(modeStorageKey(wrapper)) || '');
    } catch (e) {
        return '';
    }
}

function storeMode(wrapper, mode) {
    try {
        window.localStorage.setItem(modeStorageKey(wrapper), normalizeMode(mode));
    } catch (e) {
        // Storage is optional; the editor works without persistence.
    }
}

function getCurrentMode(wrapper) {
    var toggle = wrapper.querySelector('[data-code-editor-mode-toggle]');
    if (toggle) {
        return toggle.checked ? 'python' : 'php';
    }
    var select = wrapper.querySelector('[data-code-editor-mode-select]');
    if (select && select.value) {
        return normalizeMode(select.value);
    }
    return normalizeMode(wrapper.dataset.codeEditorMode || 'php');
}

function syncModeControls(wrapper, mode) {
    var toggle = wrapper.querySelector('[data-code-editor-mode-toggle]');
    if (toggle) {
        toggle.checked = mode === 'python';
        toggle.setAttribute('aria-checked', toggle.checked ? 'true' : 'false');
    }
    var select = wrapper.querySelector('[data-code-editor-mode-select]');
    if (select && select.value !== mode) {
        select.value = mode;
    }
    var switcher = wrapper.querySelector('.md-code-editor__mode-switch');
    if (switcher) {
        switcher.setAttribute('data-mode', mode);
    }
}

function setText(el, value) {
    if (el) {
        el.textContent = value;
    }
}

function postJSON(url, params) {
    return fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
        },
        body: params.toString()
    }).then(function (response) {
        return response.text();
    }).then(function (text) {
        try {
            return JSON.parse(text);
        } catch (e) {
            return { status: 'error', msg: text };
        }
    });
}

function buildQuery(data) {
    var params = new URLSearchParams();
    Object.keys(data).forEach(function (key) {
        if (typeof data[key] !== 'undefined' && data[key] !== null) {
            params.append(key, data[key]);
        }
    });
    return params;
}

function hashString(value) {
    var hash = 2166136261;
    var text = String(value || '');
    for (var i = 0; i < text.length; i += 1) {
        hash ^= text.charCodeAt(i);
        hash += (hash << 1) + (hash << 4) + (hash << 7) + (hash << 8) + (hash << 24);
    }
    return (hash >>> 0).toString(16);
}

function getEditorContentHash(editor) {
    return hashString(editor ? editor.getValue() : '');
}

function shouldSkipSnapshot(wrapper, editor) {
    if (!wrapper || !editor) {
        return false;
    }
    return wrapper._codeEditorLastSavedHash === getEditorContentHash(editor);
}

function markSnapshotSaved(wrapper, editor) {
    if (!wrapper || !editor) {
        return;
    }
    wrapper._codeEditorLastSavedHash = getEditorContentHash(editor);
    wrapper.classList.remove('is-dirty');
    updateDirtyState(wrapper);
}

function restoreFromCode(wrapper, editor, code) {
    editor.setValue(code || '');
    editor.focus();
    wrapper.classList.add('is-dirty');
    updateDirtyState(wrapper);
    updateSize(wrapper, editor);
}

function updateAutosaveProgress(wrapper, progress) {
    if (!wrapper) {
        return;
    }
    var normalized = Math.max(0, Math.min(1, progress || 0));
    wrapper.style.setProperty('--md-codeeditor-autosave-progress', String(normalized * 100) + '%');
    wrapper.classList.toggle('is-autosave-countdown', normalized > 0 && normalized < 1);
}

function stopAutosaveProgress(wrapper) {
    if (wrapper && wrapper._codeEditorAutosaveProgressTimer) {
        window.clearInterval(wrapper._codeEditorAutosaveProgressTimer);
        wrapper._codeEditorAutosaveProgressTimer = null;
    }
    updateAutosaveProgress(wrapper, 0);
}

function startAutosaveProgress(wrapper, interval) {
    if (!wrapper || !interval) {
        return;
    }
    stopAutosaveProgress(wrapper);
    wrapper._codeEditorAutosaveDueAt = Date.now() + (interval * 1000);
    updateAutosaveProgress(wrapper, 0.01);
    wrapper._codeEditorAutosaveProgressTimer = window.setInterval(function () {
        var remaining = wrapper._codeEditorAutosaveDueAt - Date.now();
        if (remaining <= 0) {
            stopAutosaveProgress(wrapper);
            return;
        }
        updateAutosaveProgress(wrapper, 1 - (remaining / (interval * 1000)));
    }, 100);
}

function showAutosaveToast(wrapper, message, type) {
    if (!window.MDJAdminUI || typeof window.MDJAdminUI.showToast !== 'function' || !message) {
        return;
    }
    var now = Date.now();
    var minGap = 3500;
    if (wrapper && wrapper._codeEditorLastToastAt && (now - wrapper._codeEditorLastToastAt) < minGap) {
        return;
    }
    if (wrapper) {
        wrapper._codeEditorLastToastAt = now;
    }
    window.MDJAdminUI.showToast({
        message: message,
        type: type || 'success',
        hideAfter: 1800
    });
}

function autosaveSuccessMessage(wrapper, suffix) {
    return (wrapper.dataset.codeEditorAutosaveSuccessLabel || 'Успешное автосохранение черновика.') + (suffix ? ' ' + suffix : '');
}

function updateStatus(wrapper, message, type) {
    var status = wrapper.querySelector('[data-code-editor-status]');
    if (!status) {
        return;
    }
    status.textContent = message || '';
    status.dataset.statusType = type || '';
    status.setAttribute('title', message || '');
    status.classList.toggle('is-empty', !message);
}

function updateDirtyState(wrapper) {
    var dirty = wrapper.querySelector('[data-code-editor-dirty]');
    if (dirty) {
        dirty.hidden = !wrapper.classList.contains('is-dirty');
    }
}

function saveSnapshot(wrapper, editor) {
    var url = wrapper.dataset.codeEditorAutosaveUrl || '';
    var key = wrapper.dataset.codeEditorKey || '';
    if (!url || !key) {
        return Promise.resolve();
    }
    if (shouldSkipSnapshot(wrapper, editor)) {
        markSnapshotSaved(wrapper, editor);
        updateStatus(wrapper, wrapper.dataset.codeEditorNoChangesLabel || 'Изменений нет', 'ok');
        return Promise.resolve({ status: 'skip' });
    }

    var params = buildQuery({
        action: 'save',
        key: key,
        id: wrapper.dataset.codeEditorId || '',
        md: wrapper.dataset.codeEditorMd || '',
        code: editor.getValue()
    });

    return postJSON(url, params).then(function (res) {
        if (res && res.status === 'skip') {
            markSnapshotSaved(wrapper, editor);
            updateStatus(wrapper, wrapper.dataset.codeEditorNoChangesLabel || 'Изменений нет', 'ok');
            return res;
        }
        if (!res || res.status !== 'ok') {
            updateStatus(wrapper, wrapper.dataset.codeEditorAutosaveFailedLabel || 'Не удалось сохранить версию', 'error');
            return res;
        }
        markSnapshotSaved(wrapper, editor);
        var message = autosaveSuccessMessage(wrapper, res.msg || '');
        updateStatus(wrapper, message, 'ok');
        showAutosaveToast(wrapper, message, 'success');
        return res;
    }).catch(function () {
        updateStatus(wrapper, wrapper.dataset.codeEditorAutosaveFailedLabel || 'Не удалось сохранить версию', 'error');
    });
}

function saveEditorSnapshot(wrapper, editor) {
    editor.save();
    window.clearTimeout(wrapper._codeEditorAutosaveTimer);
    stopAutosaveProgress(wrapper);
    updateStatus(wrapper, 'Сохраняю версию…', 'busy');
    return saveSnapshot(wrapper, editor);
}

function flushAutosave(wrapper, editor) {
    var interval = parseInt(wrapper.dataset.codeEditorAutosave || '0', 10) || 0;
    if (!interval || !wrapper || !editor || !wrapper.classList.contains('is-dirty')) {
        return;
    }
    if (shouldSkipSnapshot(wrapper, editor)) {
        markSnapshotSaved(wrapper, editor);
        return;
    }

    window.clearTimeout(wrapper._codeEditorAutosaveTimer);
    stopAutosaveProgress(wrapper);

    var params = buildQuery({
        action: 'save',
        key: wrapper.dataset.codeEditorKey || '',
        id: wrapper.dataset.codeEditorId || '',
        md: wrapper.dataset.codeEditorMd || '',
        code: editor.getValue()
    });

    postJSON(wrapper.dataset.codeEditorAutosaveUrl, params).then(function (res) {
        if (!res || res.status !== 'ok') {
            if (res && res.status === 'skip') {
                markSnapshotSaved(wrapper, editor);
            }
            return;
        }
        markSnapshotSaved(wrapper, editor);
        var message = autosaveSuccessMessage(wrapper, res.msg || '');
        updateStatus(wrapper, message, 'ok');
        showAutosaveToast(wrapper, message, 'success');
    }).catch(function () {
        updateStatus(wrapper, wrapper.dataset.codeEditorAutosaveFailedLabel || 'Автосохранение не удалось', 'error');
    });
}

function autosaveCode(wrapper, editor) {
    var interval = parseInt(wrapper.dataset.codeEditorAutosave || '0', 10) || 0;
    if (!interval) {
        stopAutosaveProgress(wrapper);
        return;
    }
    if (shouldSkipSnapshot(wrapper, editor)) {
        markSnapshotSaved(wrapper, editor);
        stopAutosaveProgress(wrapper);
        return;
    }

    window.clearTimeout(wrapper._codeEditorAutosaveTimer);
    startAutosaveProgress(wrapper, interval);
    wrapper._codeEditorAutosaveTimer = window.setTimeout(function () {
        stopAutosaveProgress(wrapper);
        var params = buildQuery({
            action: 'save',
            key: wrapper.dataset.codeEditorKey || '',
            id: wrapper.dataset.codeEditorId || '',
            md: wrapper.dataset.codeEditorMd || '',
            code: editor.getValue()
        });
        postJSON(wrapper.dataset.codeEditorAutosaveUrl, params).then(function (res) {
            if (!res || res.status !== 'ok') {
                if (res && res.status === 'skip') {
                    markSnapshotSaved(wrapper, editor);
                }
                return;
            }
            markSnapshotSaved(wrapper, editor);
            var message = autosaveSuccessMessage(wrapper, res.msg || '');
            updateStatus(wrapper, message, 'ok');
            showAutosaveToast(wrapper, message, 'success');
        }).catch(function () {
            updateStatus(wrapper, wrapper.dataset.codeEditorAutosaveFailedLabel || 'Автосохранение не удалось', 'error');
        });
    }, interval * 1000);
}

function updateSize(wrapper, editor) {
    if (!wrapper || !editor) {
        return;
    }
    var host = wrapper.querySelector('[data-code-editor-surface]');
    if (!host) {
        return;
    }
    if (wrapper.classList.contains('is-fullscreen')) {
        host.style.height = '';
        return;
    }

    var minLines = parseInt(wrapper.dataset.codeEditorMinLines || '0', 10) || 0;
    var maxLines = parseInt(wrapper.dataset.codeEditorMaxLines || '0', 10) || 0;
    var lineHeight = editor.defaultTextHeight() || DEFAULT_LINE_HEIGHT;
    var effectiveMin = Math.max(minLines, DEFAULT_VISIBLE_MIN_LINES);
    var total = Math.max(editor.lineCount(), 1);
    var lines;

    if (maxLines > 0) {
        // Auto-grow towards the configured minimum instead of reserving a
        // large empty area for a one-line snippet.
        var effectiveMax = Math.max(maxLines, effectiveMin);
        lines = Math.max(DEFAULT_VISIBLE_MIN_LINES, Math.min(total + 1, effectiveMax));
    } else {
        lines = Math.max(effectiveMin, total);
    }

    var height = (lines * lineHeight) + 24;
    var viewportCap = Math.max(320, Math.round(window.innerHeight * 0.72));
    if (maxLines <= 0 && height > viewportCap) {
        height = viewportCap;
    }

    host.style.height = height + 'px';
}

function updateFooter(wrapper, editor) {
    var footer = wrapper.querySelector('[data-code-editor-footer]');
    if (!footer || !editor) {
        return;
    }
    var cursor = editor.getCursor();
    var position = footer.querySelector('[data-code-editor-position]');
    if (position) {
        position.textContent = 'Стр ' + (cursor.line + 1) + ', стлб ' + (cursor.ch + 1);
    }
    var language = footer.querySelector('[data-code-editor-language]');
    if (language) {
        language.textContent = modeLabel(getCurrentMode(wrapper));
    }
    updateDirtyState(wrapper);
}

function refreshEditorLayout(wrapper) {
    if (!wrapper || !wrapper._codeEditor) {
        return;
    }
    wrapper._codeEditor.refresh();
    updateSize(wrapper, wrapper._codeEditor);
}

function getDrawerContent(wrapper) {
    if (!wrapper) {
        return null;
    }
    if (!wrapper._codeEditorDrawerContent) {
        wrapper._codeEditorDrawerContent = wrapper.querySelector('[data-code-editor-drawer-content]');
    }
    return wrapper._codeEditorDrawerContent;
}

function queryDrawerNode(wrapper, selector) {
    var content = getDrawerContent(wrapper);
    return content ? content.querySelector(selector) : null;
}

function closeDrawer(wrapper) {
    if (window.MDJAdminUI && typeof window.MDJAdminUI.closeDrawer === 'function') {
        window.MDJAdminUI.closeDrawer(wrapper._codeEditorDrawerOwner || '');
    }
    wrapper.classList.remove('is-drawer-open');
}

function openDrawer(wrapper) {
    var drawerContent = getDrawerContent(wrapper);
    if (!drawerContent || !window.MDJAdminUI || typeof window.MDJAdminUI.openDrawer !== 'function') {
        return;
    }
    if (!wrapper._codeEditorDrawerOwner) {
        wrapper._codeEditorDrawerOwner = 'code-editor:' + (wrapper.dataset.codeEditorKey || 'editor') + ':' + (wrapper.dataset.codeEditorId || '0') + ':' + Math.random().toString(36).slice(2, 8);
    }
    window.MDJAdminUI.openDrawer({
        owner: wrapper._codeEditorDrawerOwner,
        title: drawerContent.getAttribute('data-md-drawer-title') || wrapper.dataset.codeEditorDrawerTitle || 'История',
        subtitle: drawerContent.getAttribute('data-md-drawer-subtitle') || wrapper.dataset.codeEditorDrawerSubtitle || '',
        width: '672px',
        body: drawerContent,
        onClose: function () {
            wrapper.classList.remove('is-drawer-open');
        }
    });
    wrapper.classList.add('is-drawer-open');
}

function renderRestoreItems(wrapper, editor, items) {
    var list = queryDrawerNode(wrapper, '[data-code-editor-autosave-list]');
    if (!list) {
        return;
    }
    list.innerHTML = '';

    if (!items || !items.length) {
        var empty = document.createElement('div');
        empty.className = 'md-code-editor__empty';
        empty.textContent = wrapper.dataset.codeEditorAutosaveEmpty || 'Сохранённых версий пока нет';
        list.appendChild(empty);
        return;
    }

    items.forEach(function (item) {
        var row = document.createElement('div');
        row.className = 'md-code-editor__restore-item';

        var meta = document.createElement('div');
        meta.className = 'md-code-editor__restore-meta';
        var metaTitle = document.createElement('strong');
        metaTitle.textContent = item.name || 'Черновик';
        var metaTime = document.createElement('span');
        var code = String(item.code || '');
        var lines = code ? code.split('\n').length : 0;
        metaTime.textContent = (item.create || '') + (lines ? ' · ' + lines + ' стр.' : '');
        meta.appendChild(metaTitle);
        meta.appendChild(metaTime);

        var actions = document.createElement('div');
        actions.className = 'md-code-editor__restore-actions';

        var preview = document.createElement('button');
        preview.type = 'button';
        preview.className = 'md-btn md-btn--secondary md-btn--sm';
        preview.textContent = wrapper.dataset.codeEditorPreviewLabel || 'Показать';
        preview.addEventListener('click', function () {
            var codeBox = queryDrawerNode(wrapper, '[data-code-editor-preview]');
            if (!codeBox) {
                return;
            }
            codeBox.hidden = false;
            codeBox.textContent = item.code || '';
        });

        var restore = document.createElement('button');
        restore.type = 'button';
        restore.className = 'md-btn md-btn--primary md-btn--sm';
        restore.textContent = wrapper.dataset.codeEditorRestoreLabel || 'Восстановить';
        restore.addEventListener('click', function () {
            if (!window.confirm(wrapper.dataset.codeEditorConfirm || 'Восстановить эту версию?')) {
                return;
            }
            restoreFromCode(wrapper, editor, item.code || '');
            closeDrawer(wrapper);
            updateStatus(wrapper, wrapper.dataset.codeEditorRestoredLabel || 'Версия восстановлена', 'ok');
        });

        actions.appendChild(preview);
        actions.appendChild(restore);
        row.appendChild(meta);
        row.appendChild(actions);
        list.appendChild(row);
    });
}

function loadRestoreItems(wrapper, editor) {
    var params = buildQuery({
        action: 'restore',
        key: wrapper.dataset.codeEditorKey || '',
        id: wrapper.dataset.codeEditorId || '',
        md: wrapper.dataset.codeEditorMd || ''
    });

    return postJSON(wrapper.dataset.codeEditorAutosaveUrl, params).then(function (res) {
        if (!res || res.status !== 'ok') {
            renderRestoreItems(wrapper, editor, []);
            return;
        }
        renderRestoreItems(wrapper, editor, res.msg || []);
    }).catch(function () {
        renderRestoreItems(wrapper, editor, []);
        updateStatus(wrapper, wrapper.dataset.codeEditorRestoreFailedLabel || 'Не удалось загрузить версии', 'error');
    });
}

function bindVersionActions(wrapper, editor) {
    if (wrapper.dataset.codeEditorVersionBound === '1') {
        return;
    }
    wrapper.dataset.codeEditorVersionBound = '1';

    function handleVersionAction(event) {
        var actionButton = event.target.closest('[data-code-editor-action]');
        if (actionButton && wrapper.contains(actionButton) && actionButton.getAttribute('data-code-editor-action') === 'restore-error') {
            var oldCode = decodeBase64(wrapper.dataset.codeEditorOldCodeB64 || '');
            if (oldCode) {
                restoreFromCode(wrapper, editor, oldCode);
            }
            event.preventDefault();
            return;
        }

        var button = event.target.closest('[data-code-editor-version-action]');
        if (!button) {
            return;
        }

        var action = button.getAttribute('data-code-editor-version-action');
        var item = button.closest('[data-code-editor-version]');
        var code = item ? decodeBase64(item.getAttribute('data-code-editor-version-code-b64') || '') : '';
        var preview = queryDrawerNode(wrapper, '[data-code-editor-preview]');

        event.preventDefault();

        if (action === 'preview') {
            if (preview) {
                preview.hidden = false;
                preview.textContent = code;
            }
            return;
        }

        if (action === 'restore') {
            if (!window.confirm(wrapper.dataset.codeEditorConfirm || 'Восстановить эту версию?')) {
                return;
            }
            restoreFromCode(wrapper, editor, code);
            closeDrawer(wrapper);
            updateStatus(wrapper, wrapper.dataset.codeEditorRestoredLabel || 'Версия восстановлена', 'ok');
        }
    }

    wrapper.addEventListener('click', handleVersionAction);

    var drawerContent = getDrawerContent(wrapper);
    if (drawerContent && drawerContent !== wrapper) {
        drawerContent.addEventListener('click', handleVersionAction);
    }
}

function toggleFullscreen(wrapper, editor) {
    var isFullscreen = !wrapper.classList.contains('is-fullscreen');
    wrapper.classList.toggle('is-fullscreen', isFullscreen);
    document.body.classList.toggle('md-code-editor-fullscreen-open', document.querySelectorAll('.md-code-editor.is-fullscreen').length > 0);

    var button = wrapper.querySelector('[data-code-editor-action="fullscreen"]');
    if (button) {
        var icon = button.querySelector('i');
        if (icon) {
            icon.className = 'ti ' + (isFullscreen ? 'ti-minimize' : 'ti-maximize');
        }
        button.setAttribute('title', isFullscreen ? 'Выйти из полного экрана (Esc)' : 'Полный экран (F11)');
        button.setAttribute('aria-label', isFullscreen ? 'Выйти из полного экрана' : 'Полный экран');
    }

    window.setTimeout(function () {
        editor.refresh();
        updateSize(wrapper, editor);
    }, 30);
}

function validateCode(wrapper, editor) {
    var url = wrapper.dataset.codeEditorValidateUrl;
    if (!url) {
        return Promise.resolve();
    }
    updateStatus(wrapper, wrapper.dataset.codeEditorValidatingLabel || 'Проверяю код…', 'busy');

    var params = buildQuery({
        action: 'checkcode',
        key: wrapper.dataset.codeEditorKey || '',
        id: wrapper.dataset.codeEditorId || '',
        md: wrapper.dataset.codeEditorMd || '',
        mode: getCurrentMode(wrapper),
        code: editor.getValue()
    });

    return postJSON(url, params).then(function (res) {
        if (!res || res.status !== 'ok') {
            updateStatus(wrapper, wrapper.dataset.codeEditorValidationErrorLabel || 'Проверка не удалась', 'error');
            return res;
        }
        var details = res.details || {};
        if (details && details.line) {
            editor.setErrorLine(parseInt(details.line, 10) || 0, details.message || res.msg || '');
        } else if (res.msg) {
            editor.clearError();
            updateStatus(wrapper, res.msg, 'error');
        } else {
            editor.clearError();
            updateStatus(wrapper, wrapper.dataset.codeEditorValidationOkLabel || 'Ошибок не найдено', 'ok');
        }
        return res;
    }).catch(function () {
        editor.clearError();
        updateStatus(wrapper, wrapper.dataset.codeEditorValidationRequestFailedLabel || 'Не удалось проверить код', 'error');
    });
}

function isLatinShortcutKey(event) {
    var key = event.key || '';
    if (key.length !== 1) {
        return true;
    }
    return /^[a-z0-9]$/i.test(key)
        || key === '/'
        || key === ' '
        || key === '\\'
        || key === ';'
        || key === '['
        || key === ']'
        || key === '-'
        || key === '=';
}

function handleLayoutShortcut(event, view, wrapper, editor) {
    var code = event.code || '';
    var mod = event.ctrlKey || event.metaKey;

    function stop() {
        event.preventDefault();
        event.stopPropagation();
    }

    if (mod && event.altKey) {
        if (code === 'KeyL' || code === 'KeyI') {
            stop();
            formatCode(wrapper, editor);
            return true;
        }
        if (code === 'KeyG') {
            stop();
            gotoLine(view);
            return true;
        }
    }

    if (mod && !event.altKey) {
        if (code === 'KeyS') {
            stop();
            saveEditorSnapshot(wrapper, editor);
            return true;
        }
        if (code === 'KeyR') {
            stop();
            openDrawer(wrapper);
            loadRestoreItems(wrapper, editor);
            return true;
        }
        if (code === 'KeyE') {
            stop();
            validateCode(wrapper, editor);
            return true;
        }
        if (code === 'KeyF') {
            stop();
            openSearchPanel(view);
            return true;
        }
        if (code === 'KeyG') {
            stop();
            gotoLine(view);
            return true;
        }
        if (code === 'KeyD') {
            stop();
            copyLineDown(view);
            return true;
        }
        if (code === 'KeyY') {
            stop();
            deleteLine(view);
            return true;
        }
        if (code === 'Slash') {
            stop();
            toggleComment(view);
            return true;
        }
    }

    if (event.altKey && event.shiftKey) {
        if (code === 'ArrowUp') {
            stop();
            moveLineUp(view);
            return true;
        }
        if (code === 'ArrowDown') {
            stop();
            moveLineDown(view);
            return true;
        }
    }

    return false;
}

function formatCode(wrapper, editor) {
    var view = editor.view;
    var state = view.state;
    var selection = state.selection.main;
    var from;
    var to;

    if (!selection.empty) {
        from = state.doc.lineAt(selection.from).from;
        to = state.doc.lineAt(selection.to).to;
    } else {
        from = state.doc.line(1).from;
        to = state.doc.line(state.doc.lines).to;
    }

    updateStatus(wrapper, 'Выравниваю код…', 'busy');
    var changes = indentRange(state, from, to);
    if (changes.empty) {
        updateStatus(wrapper, 'Код уже выровнен', 'ok');
        return true;
    }

    view.dispatch({
        changes: changes,
        scrollIntoView: true,
        userEvent: 'input.format'
    });
    updateStatus(wrapper, 'Код выровнен табами', 'ok');
    updateSize(wrapper, editor);
    return true;
}

var SHORTCUT_GROUPS = [
    {
        title: 'Форматирование',
        items: [
            { keys: ['Ctrl', 'Alt', 'L'], label: 'Выровнять код табами' },
            { keys: ['Tab', '/', 'Shift+Tab'], label: 'Отступ / убрать' }
        ]
    },
    {
        title: 'Код',
        items: [
            { keys: ['Ctrl', '/'], label: 'Комментарий' },
            { keys: ['Ctrl', 'D'], label: 'Дублировать строку' },
            { keys: ['Ctrl', 'Y'], label: 'Удалить строку' },
            { keys: ['Shift', 'Alt', '↑↓'], label: 'Переместить строку' }
        ]
    },
    {
        title: 'Навигация',
        items: [
            { keys: ['Ctrl', 'F'], label: 'Поиск и замена' },
            { keys: ['Ctrl', 'G'], label: 'Перейти к строке' }
        ]
    },
    {
        title: 'Файл',
        items: [
            { keys: ['Ctrl', 'S'], label: 'Сохранить версию' },
            { keys: ['Ctrl', 'R'], label: 'История версий' }
        ]
    },
    {
        title: 'Вид и консоль',
        items: [
            { keys: ['F11'], label: 'Полный экран' },
            { keys: ['Ctrl', 'Enter'], label: 'Выполнить (консоль)' }
        ]
    }
];

function shortcutsMarkup() {
    function keysMarkup(keys) {
        return '<span class="md-code-editor__shortcuts-keys">' + keys.map(function (key) {
            return '<kbd>' + key + '</kbd>';
        }).join('') + '</span>';
    }

    return '<div class="md-code-editor__shortcuts">'
        + SHORTCUT_GROUPS.map(function (group) {
            return '<div class="md-code-editor__shortcuts-group">'
                + '<div class="md-code-editor__shortcuts-title">' + group.title + '</div>'
                + group.items.map(function (item) {
                    return '<div class="md-code-editor__shortcuts-row">'
                        + keysMarkup(item.keys)
                        + '<span class="md-code-editor__shortcuts-label">' + item.label + '</span>'
                        + '</div>';
                }).join('')
                + '</div>';
        }).join('')
        + '<div class="md-code-editor__shortcuts-note">На macOS <kbd>⌘</kbd> вместо <kbd>Ctrl</kbd>, отступы — табы шириной 4.</div>'
        + '</div>';
}

function ensureShortcutsButton(toolbar) {
    var button = toolbar.querySelector('[data-code-editor-action="shortcuts"]');
    if (button) {
        return button;
    }

    button = document.createElement('button');
    button.type = 'button';
    button.className = 'md-code-editor__icon-btn';
    button.setAttribute('data-code-editor-action', 'shortcuts');
    button.setAttribute('title', 'Горячие клавиши');
    button.setAttribute('aria-label', 'Горячие клавиши');
    button.innerHTML = '<i class="ti ti-keyboard" aria-hidden="true"></i>';

    var fullscreen = toolbar.querySelector('[data-code-editor-action="fullscreen"]');
    if (fullscreen && fullscreen.parentNode === toolbar) {
        toolbar.insertBefore(button, fullscreen);
    } else {
        toolbar.appendChild(button);
    }

    return button;
}

function initShortcutsPopover(button) {
    if (!button || button.dataset.mdShortcutsBound === '1') {
        return;
    }
    button.dataset.mdShortcutsBound = '1';

    if (!window.bootstrap || !window.bootstrap.Popover) {
        return;
    }

    var popover = window.bootstrap.Popover.getOrCreateInstance(button, {
        trigger: 'manual',
        html: true,
        sanitize: false,
        title: 'Горячие клавиши',
        content: shortcutsMarkup(),
        placement: window.innerWidth >= 768 ? 'left' : 'bottom',
        customClass: 'md-code-editor-shortcuts-popover',
        popperConfig: function (defaultConfig) {
            var modifiers = (defaultConfig.modifiers || []).map(function (modifier) {
                if (modifier.name === 'preventOverflow' || modifier.name === 'flip') {
                    return Object.assign({}, modifier, {
                        options: Object.assign({}, modifier.options, {
                            boundary: 'viewport',
                            padding: 8
                        })
                    });
                }
                return modifier;
            });
            return Object.assign({}, defaultConfig, {
                strategy: 'fixed',
                modifiers: modifiers
            });
        }
    });

    button._mdShortcutsPopover = popover;
    button.addEventListener('hidden.bs.popover', function () {
        if (openShortcutsPopover === popover) {
            openShortcutsPopover = null;
        }
    });
}

function toggleShortcuts(button) {
    var popover = button && button._mdShortcutsPopover;
    if (!popover) {
        return;
    }
    if (openShortcutsPopover && openShortcutsPopover !== popover) {
        openShortcutsPopover.hide();
    }
    popover.toggle();
    openShortcutsPopover = popover;
}

function bindToolbar(wrapper, editor) {
    var toolbar = wrapper.querySelector('[data-code-editor-toolbar]');
    if (!toolbar) {
        return;
    }

    initShortcutsPopover(ensureShortcutsButton(toolbar));

    var modeSelect = toolbar.querySelector('[data-code-editor-mode-select]');
    if (modeSelect && !modeSelect.dataset.codeEditorBound) {
        modeSelect.dataset.codeEditorBound = '1';
        modeSelect.addEventListener('change', function () {
            editor.setMode(modeSelect.value, true);
        });
    }

    var modeToggle = toolbar.querySelector('[data-code-editor-mode-toggle]');
    if (modeToggle && !modeToggle.dataset.codeEditorBound) {
        modeToggle.dataset.codeEditorBound = '1';
        modeToggle.addEventListener('change', function () {
            editor.setMode(modeToggle.checked ? 'python' : 'php', true);
        });
    }

    toolbar.addEventListener('click', function (event) {
        var button = event.target.closest('[data-code-editor-action]');
        if (!button || !toolbar.contains(button)) {
            return;
        }
        var action = button.getAttribute('data-code-editor-action');
        event.preventDefault();

        if (action === 'save') {
            saveEditorSnapshot(wrapper, editor);
            return;
        }
        if (action === 'validate') {
            validateCode(wrapper, editor);
            return;
        }
        if (action === 'restore') {
            openDrawer(wrapper);
            loadRestoreItems(wrapper, editor);
            return;
        }
        if (action === 'shortcuts') {
            toggleShortcuts(button);
            return;
        }
        if (action === 'search') {
            openSearchPanel(editor.view);
            return;
        }
        if (action === 'fullscreen') {
            toggleFullscreen(wrapper, editor);
            return;
        }
        if (action === 'close-drawer') {
            closeDrawer(wrapper);
            return;
        }
        if (action === 'restore-error') {
            var oldCode = decodeBase64(wrapper.dataset.codeEditorOldCodeB64 || '');
            if (oldCode) {
                restoreFromCode(wrapper, editor, oldCode);
            }
        }
    });
}

function createFooter(wrapper, host) {
    if (wrapper.querySelector('[data-code-editor-footer]')) {
        return;
    }
    var footer = document.createElement('div');
    footer.className = 'md-code-editor__footer';
    footer.setAttribute('data-code-editor-footer', '1');
    footer.innerHTML = ''
        + '<div class="md-code-editor__footer-group">'
        + '<span data-code-editor-position>Стр 1, стлб 1</span>'
        + '<span class="md-code-editor__footer-sep" aria-hidden="true"></span>'
        + '<span data-code-editor-language>PHP</span>'
        + '<span class="md-code-editor__footer-sep" aria-hidden="true"></span>'
        + '<span>Табы: 4</span>'
        + '<span class="md-code-editor__dirty" data-code-editor-dirty hidden>Изменено</span>'
        + '</div>'
        + '<div class="md-code-editor__footer-group md-code-editor__footer-group--hints">'
        + '<span class="md-code-editor__footer-hint">Ctrl+Alt+L — выровнять</span>'
        + '<span class="md-code-editor__footer-sep" aria-hidden="true"></span>'
        + '<span>UTF-8</span>'
        + '</div>';
    if (host) {
        host.insertAdjacentElement('afterend', footer);
    } else {
        wrapper.appendChild(footer);
    }
}

function createEditor(wrapper, textarea, mode) {
    var appearance = resolveAppearance(wrapper.dataset.codeEditorTheme || 'auto');
    wrapper.dataset.codeEditorAppearance = appearance;

    var host = document.createElement('div');
    host.className = 'md-code-editor__surface';
    host.setAttribute('data-code-editor-surface', '1');
    textarea.classList.add('md-code-editor__source');
    textarea.hidden = true;
    textarea.insertAdjacentElement('afterend', host);
    createFooter(wrapper, host);

    var listeners = {};
    var adapter = null;
    var customKeys = {};
    var customKeymap = new Compartment();
    var languageCompartment = new Compartment();
    var wrapCompartment = new Compartment();
    var autoCloseCompartment = new Compartment();
    var readOnlyCompartment = new Compartment();
    var tabSizeCompartment = new Compartment();

    var setErrorLineEffect = StateEffect.define();
    var errorLineField = StateField.define({
        create: function () {
            return Decoration.none;
        },
        update: function (value, tr) {
            value = value.map(tr.changes);
            for (var i = 0; i < tr.effects.length; i += 1) {
                var effect = tr.effects[i];
                if (effect.is(setErrorLineEffect)) {
                    value = effect.value == null
                        ? Decoration.none
                        : Decoration.set([Decoration.line({ class: 'cm-md-error-line' }).range(effect.value)]);
                }
            }
            return value;
        },
        provide: function (field) {
            return EditorView.decorations.from(field);
        }
    });

    var editTheme = EditorView.theme({
        '&': {
            height: '100%',
            fontSize: '13px',
            backgroundColor: 'var(--md-codeeditor-surface)',
            color: 'var(--md-codeeditor-text)'
        },
        '&.cm-focused': {
            outline: 'none'
        },
        '.cm-scroller': {
            fontFamily: 'var(--md-codeeditor-font-mono)',
            lineHeight: '1.65',
            overflow: 'auto'
        },
        '.cm-content': {
            padding: '12px 0',
            caretColor: 'var(--md-codeeditor-caret)'
        },
        '.cm-line': {
            padding: '0 14px 0 6px'
        },
        '.cm-gutters': {
            backgroundColor: 'var(--md-codeeditor-gutter-bg)',
            color: 'var(--md-codeeditor-muted)',
            border: 'none',
            borderRight: '1px solid var(--md-codeeditor-border)'
        },
        '.cm-lineNumbers .cm-gutterElement': {
            padding: '0 10px 0 14px',
            minWidth: '44px'
        },
        '.cm-activeLineGutter': {
            backgroundColor: 'var(--md-codeeditor-active-line)',
            color: 'var(--md-codeeditor-text)'
        },
        '.cm-activeLine': {
            backgroundColor: 'var(--md-codeeditor-active-line)'
        },
        '.cm-selectionBackground, &.cm-focused .cm-selectionBackground, ::selection': {
            backgroundColor: 'var(--md-codeeditor-selection) !important'
        },
        '.cm-cursor, .cm-dropCursor': {
            borderLeftColor: 'var(--md-codeeditor-caret)',
            borderLeftWidth: '2px'
        },
        '.cm-matchingBracket': {
            backgroundColor: 'var(--md-codeeditor-match)',
            outline: '1px solid var(--md-codeeditor-match-border)'
        },
        '.cm-nonmatchingBracket': {
            color: 'var(--md-codeeditor-danger)'
        },
        '.cm-foldGutter .cm-gutterElement': {
            color: 'var(--md-codeeditor-muted)',
            padding: '0 4px'
        },
        '.cm-foldPlaceholder': {
            backgroundColor: 'var(--md-codeeditor-surface-2)',
            border: '1px solid var(--md-codeeditor-border)',
            color: 'var(--md-codeeditor-muted)',
            borderRadius: '6px',
            padding: '0 6px'
        },
        '.cm-panels': {
            backgroundColor: 'var(--md-codeeditor-surface-2)',
            color: 'var(--md-codeeditor-text)'
        },
        '.cm-panels-top': {
            borderBottom: '1px solid var(--md-codeeditor-border)'
        },
        '.cm-search': {
            position: 'relative',
            display: 'grid',
            gridTemplateColumns: 'minmax(140px, 1fr) repeat(6, auto)',
            alignItems: 'center',
            gap: '6px',
            padding: '8px 38px 8px 10px',
            fontFamily: 'var(--md-ui-font)',
            fontSize: '12px'
        },
        '.cm-search br': {
            display: 'none'
        },
        '.cm-search input, .cm-search button': {
            fontFamily: 'var(--md-ui-font)',
            fontSize: '12px'
        },
        '.cm-search input[name="search"]': {
            gridColumn: '1'
        },
        '.cm-search button[name="next"]': {
            gridColumn: '2'
        },
        '.cm-search button[name="prev"]': {
            gridColumn: '3'
        },
        '.cm-search button[name="select"]': {
            gridColumn: '4'
        },
        '.cm-search input[name="replace"]': {
            gridColumn: '1'
        },
        '.cm-search button[name="replace"]': {
            gridColumn: '2'
        },
        '.cm-search button[name="replaceAll"]': {
            gridColumn: '3'
        },
        '.cm-textfield': {
            width: '100%',
            height: '28px',
            padding: '0 9px',
            border: '1px solid var(--md-codeeditor-border)',
            borderRadius: '7px',
            backgroundColor: 'var(--md-codeeditor-surface)',
            color: 'var(--md-codeeditor-text)',
            outline: 'none'
        },
        '.cm-textfield:focus': {
            borderColor: 'var(--md-codeeditor-accent)',
            boxShadow: '0 0 0 3px var(--md-codeeditor-focus)'
        },
        '.cm-button': {
            height: '28px',
            padding: '0 9px',
            border: '1px solid var(--md-codeeditor-border)',
            borderRadius: '7px',
            backgroundColor: 'var(--md-codeeditor-surface)',
            backgroundImage: 'none',
            color: 'var(--md-codeeditor-text-soft)',
            cursor: 'pointer'
        },
        '.cm-button:hover': {
            backgroundColor: 'var(--md-codeeditor-surface-3)',
            color: 'var(--md-codeeditor-text)'
        },
        '.cm-search button[name="close"]': {
            position: 'absolute',
            top: '8px',
            right: '8px',
            width: '26px',
            minWidth: '26px',
            padding: '0',
            fontSize: '16px',
            lineHeight: '1'
        },
        '.cm-search label': {
            display: 'inline-flex',
            alignItems: 'center',
            gap: '4px',
            color: 'var(--md-codeeditor-muted)',
            whiteSpace: 'nowrap',
            cursor: 'pointer'
        },
        '.cm-search label input': {
            accentColor: 'var(--md-codeeditor-accent)'
        },
        '.cm-tooltip': {
            border: '1px solid var(--md-codeeditor-border)',
            borderRadius: '10px',
            backgroundColor: 'var(--md-codeeditor-surface)',
            color: 'var(--md-codeeditor-text)',
            boxShadow: 'var(--md-ui-shadow-pop, 0 12px 32px rgba(16, 24, 40, .14))',
            overflow: 'hidden'
        },
        '.cm-tooltip-autocomplete > ul': {
            fontFamily: 'var(--md-codeeditor-font-mono)',
            fontSize: '12px',
            maxHeight: '16rem'
        },
        '.cm-tooltip-autocomplete > ul > li': {
            padding: '4px 10px'
        },
        '.cm-tooltip-autocomplete > ul > li[aria-selected]': {
            backgroundColor: 'var(--md-codeeditor-accent-soft)',
            color: 'var(--md-codeeditor-text)'
        },
        '.cm-completionLabel': {
            color: 'var(--md-codeeditor-text)'
        },
        '.cm-completionMatchedText': {
            textDecoration: 'none',
            color: 'var(--md-codeeditor-accent)',
            fontWeight: '700'
        },
        '.cm-tooltip-lint': {
            fontFamily: 'var(--md-ui-font)'
        },
        '.cm-diagnostic-error': {
            borderLeft: '3px solid var(--md-codeeditor-danger)',
            paddingLeft: '8px'
        },
        '.cm-lintRange-error': {
            backgroundImage: 'none',
            borderBottom: '1px dashed var(--md-codeeditor-danger)'
        },
        '.cm-lint-marker-error': {
            color: 'var(--md-codeeditor-danger)'
        },
        '.cm-selectionMatch': {
            backgroundColor: 'var(--md-codeeditor-match)'
        },
        '.cm-searchMatch': {
            backgroundColor: 'var(--md-codeeditor-search-match)',
            outline: '1px solid var(--md-codeeditor-search-match-border)'
        },
        '.cm-searchMatch.cm-searchMatch-selected': {
            backgroundColor: 'var(--md-codeeditor-search-match-selected)'
        },
        '.cm-md-error-line': {
            backgroundColor: 'var(--md-codeeditor-error-line)'
        }
    });

    var highlightStyle = HighlightStyle.define([
        { tag: tags.keyword, color: 'var(--md-codeeditor-syntax-keyword)' },
        { tag: tags.moduleKeyword, color: 'var(--md-codeeditor-syntax-keyword)' },
        { tag: tags.controlKeyword, color: 'var(--md-codeeditor-syntax-keyword)' },
        { tag: tags.operatorKeyword, color: 'var(--md-codeeditor-syntax-keyword)' },
        { tag: tags.string, color: 'var(--md-codeeditor-syntax-string)' },
        { tag: tags.special(tags.string), color: 'var(--md-codeeditor-syntax-string)' },
        { tag: tags.regexp, color: 'var(--md-codeeditor-syntax-string)' },
        { tag: tags.escape, color: 'var(--md-codeeditor-syntax-escape)' },
        { tag: tags.number, color: 'var(--md-codeeditor-syntax-number)' },
        { tag: tags.bool, color: 'var(--md-codeeditor-syntax-bool)' },
        { tag: tags.null, color: 'var(--md-codeeditor-syntax-bool)' },
        { tag: tags.comment, color: 'var(--md-codeeditor-syntax-comment)', fontStyle: 'italic' },
        { tag: tags.lineComment, color: 'var(--md-codeeditor-syntax-comment)', fontStyle: 'italic' },
        { tag: tags.blockComment, color: 'var(--md-codeeditor-syntax-comment)', fontStyle: 'italic' },
        { tag: tags.docComment, color: 'var(--md-codeeditor-syntax-comment)', fontStyle: 'italic' },
        { tag: tags.function(tags.variableName), color: 'var(--md-codeeditor-syntax-function)' },
        { tag: tags.function(tags.propertyName), color: 'var(--md-codeeditor-syntax-function)' },
        { tag: tags.definition(tags.variableName), color: 'var(--md-codeeditor-syntax-definition)' },
        { tag: tags.local(tags.variableName), color: 'var(--md-codeeditor-syntax-variable)' },
        { tag: tags.variableName, color: 'var(--md-codeeditor-syntax-variable)' },
        { tag: tags.typeName, color: 'var(--md-codeeditor-syntax-type)' },
        { tag: tags.className, color: 'var(--md-codeeditor-syntax-type)' },
        { tag: tags.namespace, color: 'var(--md-codeeditor-syntax-type)' },
        { tag: tags.operator, color: 'var(--md-codeeditor-syntax-operator)' },
        { tag: tags.punctuation, color: 'var(--md-codeeditor-syntax-punctuation)' },
        { tag: tags.bracket, color: 'var(--md-codeeditor-syntax-punctuation)' },
        { tag: tags.tagName, color: 'var(--md-codeeditor-syntax-tag)' },
        { tag: tags.attributeName, color: 'var(--md-codeeditor-syntax-attribute)' },
        { tag: tags.attributeValue, color: 'var(--md-codeeditor-syntax-string)' },
        { tag: tags.propertyName, color: 'var(--md-codeeditor-syntax-property)' },
        { tag: tags.meta, color: 'var(--md-codeeditor-syntax-meta)' },
        { tag: tags.annotation, color: 'var(--md-codeeditor-syntax-meta)' },
        { tag: tags.processingInstruction, color: 'var(--md-codeeditor-syntax-meta)' },
        { tag: tags.heading, color: 'var(--md-codeeditor-syntax-definition)', fontWeight: '700' },
        { tag: tags.strong, fontWeight: '700' },
        { tag: tags.emphasis, fontStyle: 'italic' },
        { tag: tags.strikethrough, textDecoration: 'line-through' },
        { tag: tags.link, color: 'var(--md-codeeditor-syntax-function)', textDecoration: 'underline' },
        { tag: tags.url, color: 'var(--md-codeeditor-syntax-function)' },
        { tag: tags.invalid, color: 'var(--md-codeeditor-danger)' }
    ]);

    function wordCompletion(context) {
        var word = context.matchBefore(/[A-Za-z_$][\w$]{1,}/);
        if (!word || (word.from === word.to && !context.explicit)) {
            return null;
        }
        var text = context.state.doc.toString();
        var words = [];
        var seen = {};
        var regexp = /[A-Za-z_$][\w$]{2,}/g;
        var match;
        var limit = 500;
        while ((match = regexp.exec(text)) && words.length < limit) {
            var value = match[0];
            if (!Object.prototype.hasOwnProperty.call(seen, value) && value.length <= 48) {
                seen[value] = true;
                words.push(value);
            }
        }
        var prefix = word.text.toLowerCase();
        var options = words.filter(function (value) {
            return value.toLowerCase().indexOf(prefix) === 0 && value !== word.text;
        }).slice(0, 40).map(function (value) {
            return { label: value, type: 'text' };
        });
        return options.length ? { from: word.from, options: options, validFor: /^[\w$]*$/ } : null;
    }

    var searchPhrases = EditorState.phrases.of({
        'Find': 'Найти',
        'Replace': 'Заменить',
        'next': 'далее',
        'previous': 'назад',
        'all': 'все',
        'match case': 'регистр',
        'regexp': 'regexp',
        'by word': 'слово целиком',
        'replace': 'заменить',
        'replace all': 'заменить все',
        'close': 'закрыть',
        'current match': 'Текущее совпадение',
        'on line': 'в строке',
        'Go to line': 'Перейти к строке',
        'go': 'перейти',
        'replaced match on line $': 'Заменено в строке $',
        'replaced $ matches': 'Заменено совпадений: $',
        'Diagnostics': 'Ошибки',
        'No diagnostics': 'Ошибок нет'
    });

    var updateListener = EditorView.updateListener.of(function (update) {
        if (!adapter) {
            return;
        }
        if (update.docChanged) {
            wrapper.classList.add('is-dirty');
            updateDirtyState(wrapper);
            if (wrapper.dataset.codeEditorHideErrorsOnEdit === '1') {
                var error = wrapper.querySelector('[data-code-editor-error]');
                if (error) {
                    error.hidden = true;
                }
            }
            autosaveCode(wrapper, adapter);
            updateSize(wrapper, adapter);
            updateFooter(wrapper, adapter);
            adapter.emit('change', update);
        }
        if (update.selectionSet || update.docChanged) {
            updateFooter(wrapper, adapter);
            adapter.emit('cursorActivity', update);
        }
        if (update.focusChanged) {
            adapter.emit(view.hasFocus ? 'focus' : 'blur', update);
        }
    });

    var extensions = [
        lineNumbers(),
        highlightActiveLineGutter(),
        highlightSpecialChars(),
        history(),
        codeFolding(),
        drawSelection(),
        dropCursor(),
        EditorState.allowMultipleSelections.of(true),
        indentOnInput(),
        bracketMatching(),
        autoCloseCompartment.of(wrapper.dataset.codeEditorAutoclose === '0' ? [] : closeBrackets()),
        autocompletion({
            override: [wordCompletion],
            activateOnTyping: true,
            maxRenderedOptions: 14,
            closeOnBlur: true
        }),
        rectangularSelection(),
        crosshairCursor(),
        highlightActiveLine(),
        highlightSelectionMatches(),
        search({ top: true }),
        EditorView.contentAttributes.of({
            'aria-label': 'Редактор кода',
            'spellcheck': 'false',
            'autocapitalize': 'off',
            'autocomplete': 'off'
        }),
        EditorState.tabSize.of(4),
        tabSizeCompartment.of(indentUnit.of('\t')),
        languageCompartment.of(languageFor(mode)),
        wrapCompartment.of(wrapper.dataset.codeEditorWraplines === '1' ? EditorView.lineWrapping : []),
        readOnlyCompartment.of(wrapper.dataset.codeEditorReadonly === '1' ? [EditorState.readOnly.of(true), EditorView.editable.of(false)] : []),
        editTheme,
        syntaxHighlighting(highlightStyle),
        errorLineField,
        searchPhrases,
        updateListener,
        EditorView.domEventHandlers({
            keydown: function (event) {
                if (!adapter || isLatinShortcutKey(event)) {
                    return false;
                }
                return handleLayoutShortcut(event, view, wrapper, adapter);
            }
        }),
        customKeymap.of(Prec.highest(keymap.of([]))),
        keymap.of([
            {
                key: 'F11',
                run: function () {
                    if (adapter) {
                        toggleFullscreen(wrapper, adapter);
                    }
                    return true;
                }
            },
            {
                key: 'Escape',
                run: function () {
                    if (wrapper.classList.contains('is-fullscreen') && adapter) {
                        toggleFullscreen(wrapper, adapter);
                        return true;
                    }
                    return false;
                }
            },
            {
                key: 'Mod-s',
                preventDefault: true,
                run: function () {
                    if (adapter) {
                        saveEditorSnapshot(wrapper, adapter);
                    }
                    return true;
                }
            },
            {
                key: 'Mod-r',
                preventDefault: true,
                run: function () {
                    if (adapter) {
                        openDrawer(wrapper);
                        loadRestoreItems(wrapper, adapter);
                    }
                    return true;
                }
            },
            {
                key: 'Mod-e',
                preventDefault: true,
                run: function () {
                    if (adapter) {
                        validateCode(wrapper, adapter);
                    }
                    return true;
                }
            },
            {
                key: 'Mod-/',
                preventDefault: true,
                run: toggleComment
            },
            {
                key: 'Mod-d',
                preventDefault: true,
                run: copyLineDown
            },
            {
                key: 'Mod-Space',
                preventDefault: true,
                run: startCompletion
            },
            {
                key: 'Mod-Alt-l',
                preventDefault: true,
                run: function () {
                    if (adapter) {
                        formatCode(wrapper, adapter);
                    }
                    return true;
                }
            },
            {
                key: 'Mod-Alt-i',
                preventDefault: true,
                run: function () {
                    if (adapter) {
                        formatCode(wrapper, adapter);
                    }
                    return true;
                }
            },
            {
                key: 'Shift-Alt-ArrowUp',
                preventDefault: true,
                run: moveLineUp
            },
            {
                key: 'Shift-Alt-ArrowDown',
                preventDefault: true,
                run: moveLineDown
            },
            {
                key: 'Ctrl-y',
                preventDefault: true,
                run: deleteLine
            },
            {
                key: 'Mod-y',
                preventDefault: true,
                run: deleteLine
            },
            {
                key: 'Mod-g',
                preventDefault: true,
                run: gotoLine
            },
            {
                key: 'Ctrl-Space',
                preventDefault: true,
                run: startCompletion
            },
            {
                key: 'Ctrl-q',
                preventDefault: true,
                run: foldCode
            }
        ].concat(closeBracketsKeymap, defaultKeymap, searchKeymap, historyKeymap, foldKeymap, completionKeymap, lintKeymap, [indentWithTab]))
    ];

    var view = new EditorView({
        state: EditorState.create({
            doc: textarea.value || '',
            extensions: extensions
        }),
        parent: host
    });

    function syncTextarea() {
        textarea.value = view.state.doc.toString();
    }

    function clearError() {
        view.dispatch({
            effects: setErrorLineEffect.of(null)
        });
        view.dispatch(setDiagnostics(view.state, []));
    }

    function setErrorLine(line, message) {
        var lineNo = parseInt(line, 10) || 0;
        if (!lineNo || lineNo < 1) {
            updateStatus(wrapper, message || '', 'error');
            return;
        }
        var doc = view.state.doc;
        var target = doc.line(Math.max(1, Math.min(doc.lines, lineNo)));
        view.dispatch({
            effects: setErrorLineEffect.of(target.from),
            selection: { anchor: target.from },
            scrollIntoView: true
        });
        if (message) {
            view.dispatch(setDiagnostics(view.state, [{
                from: target.from,
                to: target.to,
                severity: 'error',
                message: String(message)
            }]));
            updateStatus(wrapper, (wrapper.dataset.codeEditorErrorLabel || 'Строка') + ' ' + lineNo + ': ' + message, 'error');
        }
    }

    function setCustomKeys(map) {
        customKeys = map && typeof map === 'object' ? map : {};
        var bindings = [];
        Object.keys(customKeys).forEach(function (key) {
            var handler = customKeys[key];
            if (typeof handler === 'function') {
                bindings.push({
                    key: normalizeKeyName(key),
                    preventDefault: !/^(Up|Down|ArrowUp|ArrowDown)$/.test(key),
                    run: function () {
                        return handler(adapter) === false ? false : true;
                    }
                });
            }
        });
        view.dispatch({
            effects: customKeymap.reconfigure(Prec.highest(keymap.of(bindings)))
        });
    }

    function normalizeKeyName(key) {
        return String(key || '')
            .replace(/^Ctrl-/, 'Ctrl-')
            .replace(/^Cmd-/, 'Meta-')
            .replace(/^Cmd$/, 'Meta')
            .replace(/^Ctrl$/, 'Ctrl');
    }

    adapter = {
        view: view,
        getValue: function () {
            return view.state.doc.toString();
        },
        setValue: function (value) {
            var text = String(value == null ? '' : value);
            view.dispatch({
                changes: { from: 0, to: view.state.doc.length, insert: text }
            });
        },
        focus: function () {
            view.focus();
        },
        save: syncTextarea,
        getOption: function (name) {
            if (name === 'extraKeys') {
                return customKeys;
            }
            if (name === 'lineWrapping') {
                return wrapper.dataset.codeEditorWraplines === '1';
            }
            if (name === 'mode') {
                return getCurrentMode(wrapper);
            }
            if (name === 'readOnly') {
                return wrapper.dataset.codeEditorReadonly === '1';
            }
            return null;
        },
        setOption: function (name, value) {
            if (name === 'mode') {
                adapter.setMode(value, false);
                return;
            }
            if (name === 'extraKeys') {
                setCustomKeys(value);
                return;
            }
            if (name === 'lineWrapping') {
                wrapper.dataset.codeEditorWraplines = value ? '1' : '0';
                view.dispatch({
                    effects: wrapCompartment.reconfigure(value ? EditorView.lineWrapping : [])
                });
                return;
            }
            if (name === 'autoCloseBrackets') {
                wrapper.dataset.codeEditorAutoclose = value ? '1' : '0';
                view.dispatch({
                    effects: autoCloseCompartment.reconfigure(value ? closeBrackets() : [])
                });
                return;
            }
            if (name === 'readOnly') {
                wrapper.dataset.codeEditorReadonly = value ? '1' : '0';
                view.dispatch({
                    effects: readOnlyCompartment.reconfigure(value ? [EditorState.readOnly.of(true), EditorView.editable.of(false)] : [])
                });
                return;
            }
            if (name === 'tabSize') {
                view.dispatch({
                    effects: tabSizeCompartment.reconfigure([
                        EditorState.tabSize.of(parseInt(value, 10) || 4),
                        indentUnit.of('\t')
                    ])
                });
                return;
            }
            if (name === 'fullScreen') {
                if (!!value !== wrapper.classList.contains('is-fullscreen')) {
                    toggleFullscreen(wrapper, adapter);
                }
                return;
            }
            if (name === 'theme') {
                wrapper.dataset.codeEditorTheme = String(value || 'auto');
                wrapper.dataset.codeEditorAppearance = resolveAppearance(value);
            }
        },
        setMode: function (nextMode, persist) {
            var normalized = normalizeMode(nextMode || 'php');
            wrapper.dataset.codeEditorMode = normalized;
            if (persist !== false) {
                storeMode(wrapper, normalized);
            }
            syncModeControls(wrapper, normalized);
            view.dispatch({
                effects: languageCompartment.reconfigure(languageFor(normalized))
            });
            updateFooter(wrapper, adapter);
        },
        on: function (name, handler) {
            if (!listeners[name]) {
                listeners[name] = [];
            }
            listeners[name].push(handler);
            return adapter;
        },
        off: function (name, handler) {
            if (!listeners[name]) {
                return adapter;
            }
            listeners[name] = listeners[name].filter(function (item) {
                return item !== handler;
            });
            return adapter;
        },
        emit: function (name, payload) {
            (listeners[name] || []).forEach(function (handler) {
                handler(payload);
            });
        },
        addKeymap: setCustomKeys,
        refresh: function () {
            view.requestMeasure();
            updateSize(wrapper, adapter);
        },
        lastLine: function () {
            return view.state.doc.lines - 1;
        },
        lineCount: function () {
            return view.state.doc.lines;
        },
        getLine: function (line) {
            var number = Math.max(1, Math.min(view.state.doc.lines, (parseInt(line, 10) || 0) + 1));
            return view.state.doc.line(number).text;
        },
        getCursor: function () {
            var main = view.state.selection.main;
            var line = view.state.doc.lineAt(main.head);
            return { line: line.number - 1, ch: main.head - line.from };
        },
        setCursor: function (pos) {
            var lineNumber = Math.max(1, Math.min(view.state.doc.lines, (parseInt(pos && pos.line, 10) || 0) + 1));
            var line = view.state.doc.line(lineNumber);
            var ch = Math.max(0, Math.min(line.length, parseInt(pos && pos.ch, 10) || 0));
            view.dispatch({ selection: { anchor: line.from + ch }, scrollIntoView: true });
        },
        defaultTextHeight: function () {
            return view.defaultLineHeight;
        },
        getWrapperElement: function () {
            return host;
        },
        getScrollerElement: function () {
            return view.scrollDOM;
        },
        addLineClass: function (line) {
            setErrorLine((parseInt(line, 10) || 0) + 1, '');
        },
        removeLineClass: clearError,
        scrollIntoView: function (pos) {
            var lineNumber = Math.max(1, Math.min(view.state.doc.lines, (parseInt(pos && pos.line, 10) || 0) + 1));
            view.dispatch({
                selection: { anchor: view.state.doc.line(lineNumber).from },
                scrollIntoView: true
            });
        },
        showHint: function () {
            startCompletion(view);
        },
        execCommand: function (command) {
            if (command === 'toggleComment') {
                return toggleComment(view);
            }
            if (command === 'findPersistent') {
                openSearchPanel(view);
                return true;
            }
            if (command === 'autocomplete') {
                startCompletion(view);
                return true;
            }
            return false;
        },
        replaceSelection: function (text) {
            view.dispatch(view.state.replaceSelection(String(text == null ? '' : text)));
        },
        setErrorLine: setErrorLine,
        clearError: clearError,
        setDiagnostics: function (diagnostics) {
            view.dispatch(setDiagnostics(view.state, diagnostics || []));
        },
        destroy: function () {
            view.destroy();
            host.remove();
            textarea.hidden = false;
            textarea.classList.remove('md-code-editor__source');
            delete wrapper._codeEditor;
        }
    };

    var form = wrapper.closest('form');
    if (form) {
        form.addEventListener('submit', syncTextarea);
    }

    adapter.on('blur', function () {
        flushAutosave(wrapper, adapter);
    });

    return adapter;
}

function initOne(wrapper) {
    if (wrapper.dataset.codeEditorReady === '1') {
        return;
    }
    var textarea = wrapper.querySelector('textarea');
    if (!textarea) {
        return;
    }
    if (textarea.classList.contains('md-code-editor__source') && wrapper.querySelector('[data-code-editor-surface]')) {
        return;
    }

    applyWrapperDefaults(wrapper);

    var mode = getStoredMode(wrapper) || normalizeMode(wrapper.dataset.codeEditorMode || 'php');
    wrapper.dataset.codeEditorReady = '1';
    wrapper.dataset.codeEditorMode = mode;
    storeMode(wrapper, mode);
    syncModeControls(wrapper, mode);

    var editor;
    try {
        editor = createEditor(wrapper, textarea, mode);
    } catch (error) {
        wrapper.dataset.codeEditorReady = '0';
        wrapper.classList.remove('is-loading');
        textarea.hidden = false;
        textarea.classList.remove('md-code-editor__source');
        if (window.console && window.console.warn) {
            window.console.warn('Code editor init failed', error);
        }
        return;
    }

    wrapper._codeEditor = editor;
    wrapper.classList.remove('is-loading');
    wrapper.classList.add('is-ready');
    wrapper.querySelectorAll('input[type="checkbox"]').forEach(function (input) {
        input.setAttribute('data-md-raw-checkbox', '1');
        input.classList.remove('md-admin-toggle-checkbox');
        input.removeAttribute('role');
        input.removeAttribute('aria-checked');
    });
    bindToolbar(wrapper, editor);
    bindVersionActions(wrapper, editor);
    markSnapshotSaved(wrapper, editor);
    updateSize(wrapper, editor);
    updateFooter(wrapper, editor);
    updateStatus(wrapper, '', '');

    var view = editor.view;

    if (wrapper.dataset.codeEditorAutofocus === '1') {
        window.setTimeout(function () {
            view.focus();
            updateSize(wrapper, editor);
        }, 60);
    }

    if (window.MDJAdminUI && typeof window.MDJAdminUI.boot === 'function') {
        window.MDJAdminUI.boot(wrapper);
    }
}

function init(root) {
    root = root || document;
    var isDocumentRoot = root === document;
    toArray(root.querySelectorAll('[data-code-editor]')).forEach(function (wrapper) {
        if (isDocumentRoot && wrapper.dataset.codeEditorLazy === '1') {
            return;
        }
        initOne(wrapper);
    });
}

function register() {
    if (moduleRegistered) {
        return;
    }
    moduleRegistered = true;

    window.MDCodeEditor = {
        init: init,
        register: register,
        load: function () {
            return Promise.resolve(window.MDCodeEditor);
        },
        isLoaded: function () {
            return true;
        },
        getEditor: function (wrapper) {
            return wrapper && wrapper._codeEditor ? wrapper._codeEditor : null;
        }
    };

    if (window.MDJAdminUI && typeof window.MDJAdminUI.registerModuleUI === 'function') {
        window.MDJAdminUI.registerModuleUI('codeeditor', {
            init: init
        });
    }

    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-code-editor-action="restore-error"]');
        if (!button) {
            return;
        }
        var container = button.closest('[data-code-editor-error]');
        var form = button.closest('form');
        var wrapper = null;
        if (container) {
            wrapper = container.querySelector('[data-code-editor]');
        }
        if (!wrapper && form) {
            wrapper = form.querySelector('[data-code-editor]');
        }
        if (!wrapper) {
            wrapper = document.querySelector('[data-code-editor]');
        }
        var oldCodeB64 = '';
        if (container && container.hasAttribute('data-code-editor-old-code-b64')) {
            oldCodeB64 = container.getAttribute('data-code-editor-old-code-b64') || '';
        } else if (wrapper && wrapper.dataset.codeEditorOldCodeB64) {
            oldCodeB64 = wrapper.dataset.codeEditorOldCodeB64;
        }
        var oldCode = decodeBase64(oldCodeB64);
        if (wrapper && wrapper._codeEditor && oldCode) {
            restoreFromCode(wrapper, wrapper._codeEditor, oldCode);
            if (container) {
                container.hidden = true;
            }
            event.preventDefault();
        }
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            init(document);
        });
    } else {
        init(document);
    }

    document.addEventListener('shown.bs.collapse', function (event) {
        var editor = event.target.querySelector('[data-code-editor]');
        if (editor && editor._codeEditor) {
            refreshEditorLayout(editor);
        }
    });
    document.addEventListener('shown.bs.tab', function () {
        toArray(document.querySelectorAll('[data-code-editor]')).forEach(function (wrapper) {
            refreshEditorLayout(wrapper);
        });
    });
    document.addEventListener('md:drawer-opened', function (event) {
        var root = event && event.detail && event.detail.body ? event.detail.body : document;
        toArray(root.querySelectorAll('[data-code-editor]')).forEach(function (wrapper) {
            window.setTimeout(function () {
                refreshEditorLayout(wrapper);
            }, 0);
        });
    });
}

document.addEventListener('click', function (event) {
    if (!openShortcutsPopover) {
        return;
    }
    var target = event.target;
    if (target && target.closest && (target.closest('.md-code-editor-shortcuts-popover') || target.closest('[data-code-editor-action="shortcuts"]'))) {
        return;
    }
    openShortcutsPopover.hide();
    openShortcutsPopover = null;
});

document.addEventListener('keydown', function (event) {
    if (event.key !== 'Escape' || !openShortcutsPopover) {
        return;
    }
    event.preventDefault();
    event.stopPropagation();
    openShortcutsPopover.hide();
    openShortcutsPopover = null;
}, true);

register();
