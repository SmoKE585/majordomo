(function (window, document) {
    'use strict';

    var ROOT = (window.ROOTHTML || '/').replace(/\/?$/, '/');
    var CM_BASE = ROOT + '3rdparty/codemirror/';
    var resourcePromises = {};
    var moduleRegistered = false;

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

    function loadStyle(url) {
        if (resourcePromises[url]) {
            return resourcePromises[url];
        }
        resourcePromises[url] = new Promise(function (resolve) {
            var existing = document.querySelector('link[href="' + url + '"]');
            if (existing) {
                resolve();
                return;
            }
            var link = document.createElement('link');
            link.rel = 'stylesheet';
            link.href = url;
            link.onload = function () {
                resolve();
            };
            link.onerror = function () {
                resolve();
            };
            document.head.appendChild(link);
        });
        return resourcePromises[url];
    }

    function loadScript(url) {
        if (resourcePromises[url]) {
            return resourcePromises[url];
        }
        resourcePromises[url] = new Promise(function (resolve, reject) {
            if (document.querySelector('script[src="' + url + '"]')) {
                resolve();
                return;
            }
            var script = document.createElement('script');
            script.async = false;
            script.src = url;
            script.onload = function () {
                resolve();
            };
            script.onerror = function () {
                reject(new Error('Failed to load ' + url));
            };
            document.head.appendChild(script);
        });
        return resourcePromises[url];
    }

    function loadScriptsSequentially(urls) {
        return urls.reduce(function (promise, url) {
            return promise.then(function () {
                return loadScript(url);
            });
        }, Promise.resolve());
    }

    function cmUrl(path) {
        return CM_BASE + path;
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
            wrapper.dataset.codeEditorTheme = String(getCodeEditorDefault('theme', 'codemirror'));
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

    function editorModeConfig(mode) {
        if (mode === 'php') {
            return {name: 'text/x-php', startOpen: true};
        }
        if (mode === 'python') {
            return 'text/x-python';
        }
        return mode;
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
            // Ignore storage failures.
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

    function setEditorMode(wrapper, editor, mode, persist) {
        mode = normalizeMode(mode || 'php');
        if (!editor || !mode) {
            return Promise.resolve();
        }
        wrapper.dataset.codeEditorMode = mode;
        if (persist !== false) {
            storeMode(wrapper, mode);
        }
        syncModeControls(wrapper, mode);
        return ensureCodeMirror(mode, wrapper.dataset.codeEditorTheme || 'codemirror').then(function () {
            editor.setOption('mode', editorModeConfig(mode));
            editor.setOption('autoCloseTags', mode === 'htmlmixed');
            editor.setOption('matchTags', mode === 'htmlmixed');
            editor.refresh();
        });
    }

    function modeAssets(mode) {
        var assets = [];
        if (mode === 'php') {
            assets = [
                'mode/xml/xml.js',
                'mode/javascript/javascript.js',
                'mode/css/css.js',
                'mode/clike/clike.js',
                'mode/htmlmixed/htmlmixed.js',
                'mode/php/php.js'
            ];
        } else if (mode === 'htmlmixed') {
            assets = [
                'mode/xml/xml.js',
                'mode/javascript/javascript.js',
                'mode/css/css.js',
                'mode/htmlmixed/htmlmixed.js'
            ];
        } else if (mode === 'javascript') {
            assets = ['mode/javascript/javascript.js'];
        } else if (mode === 'python') {
            assets = ['mode/python/python.js'];
        } else if (mode === 'css') {
            assets = ['mode/css/css.js'];
        } else if (mode === 'xml') {
            assets = ['mode/xml/xml.js'];
        }
        return assets;
    }

    function baseAssets() {
        return [
            'lib/codemirror.css',
            'addon/display/fullscreen.css',
            'addon/fold/foldgutter.css',
            'addon/hint/show-hint.css',
            'lib/codemirror.js',
            'addon/edit/matchbrackets.js',
            'addon/edit/closebrackets.js',
            'addon/edit/closetag.js',
            'addon/edit/matchtags.js',
            'addon/comment/comment.js',
            'addon/display/fullscreen.js',
            'addon/fold/foldcode.js',
            'addon/fold/foldgutter.js',
            'addon/fold/brace-fold.js',
            'addon/fold/comment-fold.js',
            'addon/search/searchcursor.js',
            'addon/dialog/dialog.js',
            'addon/search/search.js',
            'addon/hint/show-hint.js',
            'addon/hint/anyword-hint.js'
        ];
    }

    function ensureTheme(theme) {
        if (!theme || theme === 'codemirror') {
            return Promise.resolve();
        }
        return loadStyle(cmUrl('lib/' + theme + '.css'));
    }

    function ensureCodeMirror(mode, theme) {
        var assets = baseAssets().concat(modeAssets(mode));
        var sequence = Promise.resolve();

        sequence = sequence.then(function () {
            return loadStyle(cmUrl(assets.shift()));
        });
        return assets.reduce(function (promise, asset) {
            return promise.then(function () {
                if (/\.css$/i.test(asset)) {
                    return loadStyle(cmUrl(asset));
                }
                return loadScript(cmUrl(asset));
            });
        }, sequence).then(function () {
            return ensureTheme(theme);
        });
    }

    function hintDelayForMode(mode) {
        if (mode === 'php' || mode === 'python') {
            return 160;
        }
        return 0;
    }

    function setText(el, value) {
        if (!el) {
            return;
        }
        el.textContent = value;
    }

    function readText(el) {
        return el ? (el.textContent || el.value || '') : '';
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
                return {status: 'error', msg: text};
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
    }

    function restoreFromCode(wrapper, editor, code) {
        editor.setValue(code || '');
        editor.focus();
        wrapper.classList.add('is-dirty');
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
            clearInterval(wrapper._codeEditorAutosaveProgressTimer);
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
        wrapper._codeEditorAutosaveProgressTimer = setInterval(function () {
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

    function saveSnapshot(wrapper, editor) {
        var url = wrapper.dataset.codeEditorAutosaveUrl || '';
        var key = wrapper.dataset.codeEditorKey || '';
        if (!url || !key) {
            return Promise.resolve();
        }
        if (shouldSkipSnapshot(wrapper, editor)) {
            return Promise.resolve({status: 'skip'});
        }

        var params = buildQuery({
            action: 'save',
            key: key,
            id: wrapper.dataset.codeEditorId || '',
            md: wrapper.dataset.codeEditorMd || '',
            code: editor.getValue()
        });

        return postJSON(url, params).then(function (res) {
            if (!res || res.status !== 'ok') {
                if (res && res.status === 'skip') {
                    markSnapshotSaved(wrapper, editor);
                }
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

    function submitEditorForm(wrapper, editor) {
        editor.save();
        clearTimeout(wrapper._codeEditorAutosaveTimer);
        stopAutosaveProgress(wrapper);
        saveSnapshot(wrapper, editor).finally(function () {
            var form = textareaForm(wrapper);
            if (form) {
                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit();
                } else {
                    form.submit();
                }
            }
        });
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

        clearTimeout(wrapper._codeEditorAutosaveTimer);
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

    function updateSize(wrapper, editor) {
        var minLines = parseInt(wrapper.dataset.codeEditorMinLines || '0', 10) || 0;
        var maxLines = parseInt(wrapper.dataset.codeEditorMaxLines || '0', 10) || 0;
        var lineHeight = editor && typeof editor.defaultTextHeight === 'function'
            ? editor.defaultTextHeight()
            : (parseInt(wrapper.dataset.codeEditorLineHeight || '20', 10) || 20);
        var totalLines = editor.lineCount();
        var height = '';
        var editorWrapper = editor.getWrapperElement();
        var scroller = editor.getScrollerElement();

        if (wrapper.classList.contains('is-fullscreen')) {
            editor.setSize('100%', '100%');
            return;
        }

        if (totalLines < minLines) {
            height = (minLines * lineHeight) + 'px';
        } else if (maxLines > 0 && totalLines >= maxLines) {
            height = (maxLines * lineHeight) + 'px';
        } else {
            height = 'auto';
        }

        editorWrapper.style.height = height;
        scroller.style.height = height;
        scroller.style.minHeight = minLines > 0 ? (minLines * lineHeight) + 'px' : '0';
    }

    function updateStatus(wrapper, message, type) {
        var status = wrapper.querySelector('[data-code-editor-status]');
        if (!status) {
            return;
        }
        status.textContent = message || '';
        status.dataset.statusType = type || '';
    }

    function clearValidationMarker(wrapper, editor) {
        if (!editor || typeof wrapper._codeEditorErrorLine !== 'number') {
            return;
        }
        var lineIndex = wrapper._codeEditorErrorLine;
        if (lineIndex >= 0) {
            editor.removeLineClass(lineIndex, 'background', 'md-code-editor-line-error');
        }
        wrapper._codeEditorErrorLine = null;
    }

    function setValidationMarker(wrapper, editor, line, message) {
        clearValidationMarker(wrapper, editor);
        if (!editor || !line || line < 1) {
            updateStatus(wrapper, message || '', 'error');
            return;
        }
        var lineIndex = line - 1;
        wrapper._codeEditorErrorLine = lineIndex;
        editor.addLineClass(lineIndex, 'background', 'md-code-editor-line-error');
        editor.scrollIntoView({line: lineIndex, ch: 0}, 120);
        updateStatus(wrapper, (wrapper.dataset.codeEditorErrorLabel || 'Строка') + ' ' + line + ': ' + (message || ''), 'error');
    }

    function getDrawerContent(wrapper) {
        if (!wrapper) {
            return null;
        }
        if (wrapper._codeEditorDrawerContent) {
            return wrapper._codeEditorDrawerContent;
        }
        wrapper._codeEditorDrawerContent = wrapper.querySelector('[data-code-editor-drawer-content]');
        return wrapper._codeEditorDrawerContent;
    }

    function queryDrawerNode(wrapper, selector) {
        var content = getDrawerContent(wrapper);
        return content ? content.querySelector(selector) : null;
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

    function closeDrawer(wrapper) {
        if (window.MDJAdminUI && typeof window.MDJAdminUI.closeDrawer === 'function') {
            window.MDJAdminUI.closeDrawer(wrapper._codeEditorDrawerOwner || '');
        }
        wrapper.classList.remove('is-drawer-open');
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
            metaTitle.textContent = item.name || '';
            var metaTime = document.createElement('span');
            metaTime.textContent = item.create || '';
            meta.appendChild(metaTitle);
            meta.appendChild(metaTime);

            var actions = document.createElement('div');
            actions.className = 'md-code-editor__restore-actions';

            var preview = document.createElement('button');
            preview.type = 'button';
            preview.className = 'btn btn-outline-secondary btn-sm';
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
            restore.className = 'btn btn-primary btn-sm';
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

    function bindToolbar(wrapper, editor) {
        var toolbar = wrapper.querySelector('[data-code-editor-toolbar]');
        if (!toolbar) {
            return;
        }

        var modeSelect = toolbar.querySelector('[data-code-editor-mode-select]');
        if (modeSelect && !modeSelect.dataset.codeEditorBound) {
            modeSelect.dataset.codeEditorBound = '1';
            modeSelect.addEventListener('change', function () {
                setEditorMode(wrapper, editor, modeSelect.value, true).catch(function (error) {
                    if (window.console && window.console.warn) {
                        window.console.warn(error);
                    }
                });
            });
        }

        var modeToggle = toolbar.querySelector('[data-code-editor-mode-toggle]');
        if (modeToggle && !modeToggle.dataset.codeEditorBound) {
            modeToggle.dataset.codeEditorBound = '1';
            modeToggle.addEventListener('change', function () {
                setEditorMode(wrapper, editor, modeToggle.checked ? 'python' : 'php', true).catch(function (error) {
                    if (window.console && window.console.warn) {
                        window.console.warn(error);
                    }
                });
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
                submitEditorForm(wrapper, editor);
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
                return;
            }
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

    function textareaForm(wrapper) {
        var textarea = wrapper.querySelector('textarea');
        return textarea ? textarea.form : null;
    }

    function toggleFullscreen(wrapper, editor) {
        wrapper.classList.toggle('is-fullscreen');
        editor.setOption('fullScreen', wrapper.classList.contains('is-fullscreen'));
        if (!wrapper.classList.contains('is-fullscreen')) {
            editor.setOption('fullScreen', false);
        }
        setTimeout(function () {
            editor.refresh();
        }, 25);
    }

    function validateCode(wrapper, editor) {
        var url = wrapper.dataset.codeEditorValidateUrl;
        if (!url) {
            return Promise.resolve();
        }
        updateStatus(wrapper, wrapper.dataset.codeEditorValidatingLabel || 'Validating...', 'busy');

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
                updateStatus(wrapper, wrapper.dataset.codeEditorValidationErrorLabel || 'Validation failed', 'error');
                return res;
            }
            var details = res.details || {};
            if (details && details.line) {
                setValidationMarker(wrapper, editor, parseInt(details.line, 10) || 0, details.message || res.msg || '');
            } else if (res.msg) {
                clearValidationMarker(wrapper, editor);
                updateStatus(wrapper, res.msg, 'error');
            } else {
                clearValidationMarker(wrapper, editor);
                updateStatus(wrapper, wrapper.dataset.codeEditorValidationOkLabel || 'No errors found', 'ok');
            }
            return res;
        }).catch(function () {
            clearValidationMarker(wrapper, editor);
            updateStatus(wrapper, wrapper.dataset.codeEditorValidationRequestFailedLabel || 'Validation request failed', 'error');
        });
    }

    function scheduleHints(wrapper, editor, change) {
        var mode = getCurrentMode(wrapper);
        var hintDelay = hintDelayForMode(mode);
        if (!hintDelay || !editor || !change || !change.text || !change.text.length) {
            return;
        }
        if (editor.state && editor.state.completionActive) {
            return;
        }
        var inserted = change.text.join('\n');
        if (!/[\w$.:>\-]/.test(inserted)) {
            return;
        }
        clearTimeout(wrapper._codeEditorHintTimer);
        wrapper._codeEditorHintTimer = setTimeout(function () {
            if (!editor || editor.getOption('readOnly')) {
                return;
            }
            editor.showHint({
                hint: CodeMirror.hint.anyword || CodeMirror.hint.auto,
                completeSingle: false,
                closeOnUnfocus: true
            });
        }, hintDelay);
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

        clearTimeout(wrapper._codeEditorAutosaveTimer);
        startAutosaveProgress(wrapper, interval);
        wrapper._codeEditorAutosaveTimer = setTimeout(function () {
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

    function createCommands(wrapper, editor) {
        return {
            'F11': function () {
                toggleFullscreen(wrapper, editor);
            },
            'Esc': function () {
                if (wrapper.classList.contains('is-fullscreen')) {
                    toggleFullscreen(wrapper, editor);
                }
            },
            'Ctrl-S': function () {
                submitEditorForm(wrapper, editor);
            },
            'Cmd-S': function () {
                submitEditorForm(wrapper, editor);
            },
            'Ctrl-F': 'findPersistent',
            'Cmd-F': 'findPersistent',
            'Ctrl-R': function () {
                openDrawer(wrapper);
                loadRestoreItems(wrapper, editor);
            },
            'Cmd-R': function () {
                openDrawer(wrapper);
                loadRestoreItems(wrapper, editor);
            },
            'Ctrl-E': function () {
                validateCode(wrapper, editor);
            },
            'Cmd-E': function () {
                validateCode(wrapper, editor);
            },
            'Ctrl-Space': 'autocomplete',
            'Cmd-Space': 'autocomplete',
            'Ctrl-/': 'toggleComment',
            'Cmd-/': 'toggleComment',
            'Ctrl-D': function (cm) {
                var currentCursor = cm.doc.getCursor();
                var lineContent = cm.doc.getLine(currentCursor.line);
                cm.execCommand('goLineEnd');
                cm.execCommand('newlineAndIndent');
                cm.replaceSelection(lineContent, 'end');
                cm.doc.setCursor({line: currentCursor.line + 1, ch: currentCursor.ch});
            },
            'Cmd-D': function (cm) {
                var currentCursor = cm.doc.getCursor();
                var lineContent = cm.doc.getLine(currentCursor.line);
                cm.execCommand('goLineEnd');
                cm.execCommand('newlineAndIndent');
                cm.replaceSelection(lineContent, 'end');
                cm.doc.setCursor({line: currentCursor.line + 1, ch: currentCursor.ch});
            },
            'Ctrl-Q': function (cm) {
                cm.foldCode(cm.getCursor());
            },
            'Cmd-Q': function (cm) {
                cm.foldCode(cm.getCursor());
            }
        };
    }

    function bindDrawer(wrapper) {
        getDrawerContent(wrapper);
    }

    function ensureLoadingState(wrapper, textarea) {
        if (!wrapper || !textarea || wrapper._codeEditorLoading) {
            return;
        }

        var loader = document.createElement('div');
        loader.className = 'md-code-editor__loading';
        loader.setAttribute('data-code-editor-loading', '1');
        loader.innerHTML = ''
            + '<div class="md-code-editor__loading-bar"></div>'
            + '<div class="md-code-editor__loading-glow"></div>'
            + '<div class="md-code-editor__loading-content">'
            + '<div class="md-code-editor__loading-spinner" aria-hidden="true"></div>'
            + '<div class="md-code-editor__loading-copy">'
            + '<strong>Подготавливаю редактор</strong>'
            + '<span>Загружаю CodeMirror, тему и подсказки.</span>'
            + '</div>'
            + '</div>';

        textarea.classList.add('md-code-editor__source');
        textarea.hidden = true;
        textarea.insertAdjacentElement('afterend', loader);
        wrapper.classList.add('is-loading');
        wrapper._codeEditorLoading = loader;
    }

    function clearLoadingState(wrapper, textarea) {
        if (!wrapper) {
            return;
        }
        wrapper.classList.remove('is-loading');
        if (textarea) {
            textarea.hidden = false;
            textarea.classList.remove('md-code-editor__source');
        }
        if (wrapper._codeEditorLoading && wrapper._codeEditorLoading.parentNode) {
            wrapper._codeEditorLoading.parentNode.removeChild(wrapper._codeEditorLoading);
        }
        wrapper._codeEditorLoading = null;
    }

    function initOne(wrapper) {
        if (wrapper.dataset.codeEditorReady === '1') {
            return;
        }
        var textarea = wrapper.querySelector('textarea');
        if (!textarea) {
            return;
        }

        applyWrapperDefaults(wrapper);

        var mode = getStoredMode(wrapper) || normalizeMode(wrapper.dataset.codeEditorMode || 'php');
        var theme = wrapper.dataset.codeEditorTheme || 'codemirror';
        wrapper.dataset.codeEditorReady = '1';
        wrapper.dataset.codeEditorMode = mode;
        storeMode(wrapper, mode);
        syncModeControls(wrapper, mode);
        bindDrawer(wrapper);
        ensureLoadingState(wrapper, textarea);

        ensureCodeMirror(mode, theme).then(function () {
            if (!window.CodeMirror) {
                clearLoadingState(wrapper, textarea);
                return;
            }

            var editor = window.CodeMirror.fromTextArea(textarea, {
                lineNumbers: true,
                lineWrapping: wrapper.dataset.codeEditorWraplines === '1',
                mode: editorModeConfig(mode),
                theme: theme === 'codemirror' ? 'default' : theme,
                autofocus: wrapper.dataset.codeEditorAutofocus === '1',
                indentUnit: 4,
                tabSize: 4,
                indentWithTabs: false,
                matchBrackets: true,
                autoCloseBrackets: wrapper.dataset.codeEditorAutoclose === '1',
                autoCloseTags: mode === 'htmlmixed',
                matchTags: mode === 'htmlmixed',
                foldGutter: true,
                gutters: ['CodeMirror-linenumbers', 'CodeMirror-foldgutter'],
                extraKeys: createCommands(wrapper, null)
            });

            editor.setOption('extraKeys', createCommands(wrapper, editor));
            editor.on('change', function () {
                wrapper.classList.add('is-dirty');
                clearValidationMarker(wrapper, editor);
                autosaveCode(wrapper, editor);
                updateSize(wrapper, editor);
            });
            editor.on('inputRead', function (cm, change) {
                scheduleHints(wrapper, cm, change);
            });
            editor.on('cursorActivity', function () {
                if (wrapper.dataset.codeEditorHideErrorsOnEdit === '1') {
                    var error = wrapper.querySelector('[data-code-editor-error]');
                    if (error) {
                        error.hidden = true;
                    }
                }
            });
            editor.on('blur', function () {
                flushAutosave(wrapper, editor);
            });

            wrapper._codeEditor = editor;
            markSnapshotSaved(wrapper, editor);
            bindToolbar(wrapper, editor);
            bindVersionActions(wrapper, editor);
            updateSize(wrapper, editor);
            clearLoadingState(wrapper, textarea);
            setTimeout(function () {
                editor.refresh();
            }, 25);
            if (window.MDJAdminUI && typeof window.MDJAdminUI.boot === 'function') {
                window.MDJAdminUI.boot(wrapper);
            }
        }).catch(function (error) {
            clearLoadingState(wrapper, textarea);
            // Fail silently to keep the rest of the page usable.
            if (window.console && window.console.warn) {
                window.console.warn(error);
            }
        });
    }

    function init(root) {
        root = root || document;
        toArray(root.querySelectorAll('[data-code-editor]')).forEach(initOne);
    }

    function register() {
        if (moduleRegistered) {
            return;
        }
        moduleRegistered = true;

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
    }

    window.MDCodeEditor = {
        init: init,
        register: register
    };

    register();
    document.addEventListener('DOMContentLoaded', function () {
        init(document);
    });
    document.addEventListener('shown.bs.collapse', function (event) {
        var editor = event.target.querySelector('[data-code-editor]');
        if (editor && editor._codeEditor) {
            editor._codeEditor.refresh();
        }
    });
    document.addEventListener('shown.bs.tab', function () {
        toArray(document.querySelectorAll('[data-code-editor]')).forEach(function (wrapper) {
            if (wrapper._codeEditor) {
                wrapper._codeEditor.refresh();
            }
        });
    });
})(window, document);
