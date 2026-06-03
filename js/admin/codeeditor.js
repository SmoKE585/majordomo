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

    function restoreFromCode(wrapper, editor, code) {
        editor.setValue(code || '');
        editor.focus();
        wrapper.classList.add('is-dirty');
        updateSize(wrapper, editor);
    }

    function updateSize(wrapper, editor) {
        var minLines = parseInt(wrapper.dataset.codeEditorMinLines || '0', 10) || 0;
        var maxLines = parseInt(wrapper.dataset.codeEditorMaxLines || '0', 10) || 0;
        var lineHeight = parseInt(wrapper.dataset.codeEditorLineHeight || '20', 10) || 20;
        var totalLines = editor.lineCount();
        var height = '';

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

        editor.getWrapperElement().style.height = height;
        editor.getScrollerElement().style.height = height;
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
        updateStatus(wrapper, (wrapper.dataset.codeEditorErrorLabel || 'Line') + ' ' + line + ': ' + (message || ''), 'error');
    }

    function openDrawer(wrapper) {
        var drawer = wrapper.querySelector('[data-code-editor-drawer]');
        var backdrop = wrapper.querySelector('[data-code-editor-backdrop]');
        if (!drawer || !backdrop) {
            return;
        }
        drawer.hidden = false;
        backdrop.hidden = false;
        wrapper.classList.add('is-drawer-open');
    }

    function closeDrawer(wrapper) {
        var drawer = wrapper.querySelector('[data-code-editor-drawer]');
        var backdrop = wrapper.querySelector('[data-code-editor-backdrop]');
        if (!drawer || !backdrop) {
            return;
        }
        drawer.hidden = true;
        backdrop.hidden = true;
        wrapper.classList.remove('is-drawer-open');
    }

    function renderRestoreItems(wrapper, editor, items) {
        var list = wrapper.querySelector('[data-code-editor-autosave-list]');
        if (!list) {
            return;
        }
        list.innerHTML = '';

        if (!items || !items.length) {
            var empty = document.createElement('div');
            empty.className = 'md-code-editor__empty';
            empty.textContent = wrapper.dataset.codeEditorAutosaveEmpty || 'No backups yet';
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
            preview.textContent = wrapper.dataset.codeEditorPreviewLabel || 'Preview';
            preview.addEventListener('click', function () {
                var codeBox = wrapper.querySelector('[data-code-editor-preview]');
                if (!codeBox) {
                    return;
                }
                codeBox.hidden = false;
                codeBox.textContent = item.code || '';
            });

            var restore = document.createElement('button');
            restore.type = 'button';
            restore.className = 'btn btn-primary btn-sm';
            restore.textContent = wrapper.dataset.codeEditorRestoreLabel || 'Restore';
            restore.addEventListener('click', function () {
                if (!window.confirm(wrapper.dataset.codeEditorConfirm || 'Restore this version?')) {
                    return;
                }
                restoreFromCode(wrapper, editor, item.code || '');
                closeDrawer(wrapper);
                updateStatus(wrapper, wrapper.dataset.codeEditorRestoredLabel || 'Version restored', 'ok');
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
            updateStatus(wrapper, wrapper.dataset.codeEditorRestoreFailedLabel || 'Could not load backups', 'error');
        });
    }

    function bindToolbar(wrapper, editor) {
        var toolbar = wrapper.querySelector('[data-code-editor-toolbar]');
        if (!toolbar) {
            return;
        }

        toolbar.addEventListener('click', function (event) {
            var button = event.target.closest('[data-code-editor-action]');
            if (!button || !toolbar.contains(button)) {
                return;
            }
            var action = button.getAttribute('data-code-editor-action');
            event.preventDefault();

            if (action === 'save') {
                editor.save();
                var form = textareaForm(wrapper);
                if (form) {
                    if (typeof form.requestSubmit === 'function') {
                        form.requestSubmit();
                    } else {
                        form.submit();
                    }
                }
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

        wrapper.addEventListener('click', function (event) {
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
            if (!button || !wrapper.contains(button)) {
                return;
            }

            var action = button.getAttribute('data-code-editor-version-action');
            var item = button.closest('[data-code-editor-version]');
            var code = item ? decodeBase64(item.getAttribute('data-code-editor-version-code-b64') || '') : '';
            var preview = wrapper.querySelector('[data-code-editor-preview]');

            event.preventDefault();

            if (action === 'preview') {
                if (preview) {
                    preview.hidden = false;
                    preview.textContent = code;
                }
                return;
            }

            if (action === 'restore') {
                if (!window.confirm(wrapper.dataset.codeEditorConfirm || 'Restore this version?')) {
                    return;
                }
                restoreFromCode(wrapper, editor, code);
                closeDrawer(wrapper);
                updateStatus(wrapper, wrapper.dataset.codeEditorRestoredLabel || 'Version restored', 'ok');
            }
        });
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
        var mode = normalizeMode(wrapper.dataset.codeEditorMode || 'php');
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
                hint: CodeMirror.hint.auto,
                completeSingle: false,
                closeOnUnfocus: true
            });
        }, hintDelay);
    }

    function autosaveCode(wrapper, editor) {
        var interval = parseInt(wrapper.dataset.codeEditorAutosave || '0', 10) || 0;
        if (!interval) {
            return;
        }

        clearTimeout(wrapper._codeEditorAutosaveTimer);
        wrapper._codeEditorAutosaveTimer = setTimeout(function () {
            var params = buildQuery({
                action: 'save',
                key: wrapper.dataset.codeEditorKey || '',
                id: wrapper.dataset.codeEditorId || '',
                md: wrapper.dataset.codeEditorMd || '',
                code: editor.getValue()
            });
            postJSON(wrapper.dataset.codeEditorAutosaveUrl, params).then(function (res) {
                if (!res || res.status !== 'ok') {
                    return;
                }
                updateStatus(wrapper, (wrapper.dataset.codeEditorSavedLabel || 'Saved') + ' ' + (res.msg || ''), 'ok');
            }).catch(function () {
                updateStatus(wrapper, wrapper.dataset.codeEditorAutosaveFailedLabel || 'Autosave failed', 'error');
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
                editor.save();
                var form = textareaForm(wrapper);
                if (form) {
                    if (typeof form.requestSubmit === 'function') {
                        form.requestSubmit();
                    } else {
                        form.submit();
                    }
                }
            },
            'Cmd-S': function () {
                editor.save();
                var form = textareaForm(wrapper);
                if (form) {
                    if (typeof form.requestSubmit === 'function') {
                        form.requestSubmit();
                    } else {
                        form.submit();
                    }
                }
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
        var drawer = wrapper.querySelector('[data-code-editor-drawer]');
        var backdrop = wrapper.querySelector('[data-code-editor-backdrop]');
        if (backdrop && !backdrop.dataset.codeEditorBound) {
            backdrop.dataset.codeEditorBound = '1';
            backdrop.addEventListener('click', function () {
                closeDrawer(wrapper);
            });
        }
        if (drawer && !drawer.dataset.codeEditorBound) {
            drawer.dataset.codeEditorBound = '1';
            drawer.addEventListener('click', function (event) {
                if (event.target.closest('[data-code-editor-action="close-drawer"]')) {
                    closeDrawer(wrapper);
                }
            });
        }
    }

    function initOne(wrapper) {
        if (wrapper.dataset.codeEditorReady === '1') {
            return;
        }
        var textarea = wrapper.querySelector('textarea');
        if (!textarea) {
            return;
        }

        var mode = normalizeMode(wrapper.dataset.codeEditorMode || 'php');
        var theme = wrapper.dataset.codeEditorTheme || 'codemirror';
        wrapper.dataset.codeEditorReady = '1';
        bindDrawer(wrapper);

        ensureCodeMirror(mode, theme).then(function () {
            if (!window.CodeMirror) {
                return;
            }

            var editor = window.CodeMirror.fromTextArea(textarea, {
                lineNumbers: true,
                lineWrapping: wrapper.dataset.codeEditorWraplines === '1',
                mode: mode,
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

            wrapper._codeEditor = editor;
            bindToolbar(wrapper, editor);
            bindVersionActions(wrapper, editor);
            updateSize(wrapper, editor);
            setTimeout(function () {
                editor.refresh();
            }, 25);
            if (window.MDJAdminUI && typeof window.MDJAdminUI.boot === 'function') {
                window.MDJAdminUI.boot(wrapper);
            }
        }).catch(function (error) {
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
