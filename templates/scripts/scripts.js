(function (window, document) {
    'use strict';

    var STORAGE_KEY = 'md-scripts-open-categories-v1';

    function escapeHtml(value) {
        return String(value === null || value === undefined ? '' : value).replace(/[&<>"']/g, function (char) {
            return {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            }[char];
        });
    }

    function readJsonStorage(key, fallback) {
        try {
            var raw = window.localStorage.getItem(key);
            if (!raw) {
                return fallback;
            }
            var parsed = JSON.parse(raw);
            return parsed && typeof parsed === 'object' ? parsed : fallback;
        } catch (e) {
            return fallback;
        }
    }

    function writeJsonStorage(key, value) {
        try {
            window.localStorage.setItem(key, JSON.stringify(value));
        } catch (e) {
            // Ignore storage failures.
        }
    }

    function toggleVisible(node, visible) {
        if (!node) {
            return;
        }
        node.hidden = !visible;
    }

    function formatJson(value) {
        if (typeof value === 'string') {
            return value;
        }
        try {
            return JSON.stringify(value, null, 2);
        } catch (e) {
            return String(value);
        }
    }

    function formatBytes(bytes) {
        var value = Number(bytes || 0);
        if (!value) {
            return '—';
        }
        var units = ['B', 'KB', 'MB', 'GB'];
        var unitIndex = 0;
        while (value >= 1024 && unitIndex < units.length - 1) {
            value = value / 1024;
            unitIndex += 1;
        }
        return value.toFixed(value >= 10 || unitIndex === 0 ? 0 : 1) + ' ' + units[unitIndex];
    }

    function setBreadcrumbLabel(root) {
        var page = root.querySelector('[data-scripts-page]');
        var breadcrumb = document.querySelector('.breadcrumb');
        var note;

        if (!page || !breadcrumb) {
            return;
        }

        var existing = breadcrumb.querySelector('[data-scripts-breadcrumb]');
        if (existing) {
            existing.remove();
        }

        note = document.createElement('li');
        note.className = 'md-scripts-breadcrumb-muted';
        note.setAttribute('data-scripts-breadcrumb', '1');
        if (!page.querySelector('[data-scripts-edit-page]')) {
            return;
        }
        note.textContent = page.dataset.scriptsEditMode === 'edit'
            ? (page.dataset.scriptsBreadcrumbEdit || 'Edit record')
            : (page.dataset.scriptsBreadcrumbNew || 'New record');
        if (note.textContent) {
            breadcrumb.appendChild(note);
        }
    }

    function initSearchPage(root) {
        var page = root.querySelector('[data-scripts-page]');
        var searchForm = page ? page.querySelector('[data-scripts-search-form]') : null;
        var exportForm = page ? page.querySelector('[data-scripts-export-form]') : null;
        var exportButton = page ? page.querySelector('[data-scripts-export-submit]') : null;
        var exportLabel = exportButton ? (exportButton.getAttribute('data-scripts-export-label') || exportButton.textContent || 'Export') : '';
        var exportInputs = page ? page.querySelectorAll('[data-scripts-export-checkbox]') : [];
        var exportToggles = page ? page.querySelectorAll('[data-scripts-export-toggle]') : [];
        var categories = page ? page.querySelectorAll('details[data-scripts-category]') : [];
        var importPanel = page ? page.querySelector('[data-scripts-import]') : null;
        var searchInput = page ? page.querySelector('[data-scripts-filter]') : null;
        var recentlyUpdated = page ? page.querySelector('[data-scripts-recently-updated]') : null;
        var searchButtons = page ? page.querySelectorAll('[data-scripts-search-open]') : [];
        var titleValue = searchInput ? String(searchInput.value || '').trim() : '';
        var openState = readJsonStorage(STORAGE_KEY, {});
        var hasTitleFilter = titleValue.length > 0;

        function syncExportToggle(input) {
            var card = input ? input.closest('.md-scripts-record') : null;
            var toggle = card ? card.querySelector('[data-scripts-export-toggle]') : null;

            if (card) {
                card.classList.toggle('is-export-selected', !!input.checked);
            }
            if (toggle) {
                toggle.classList.toggle('is-active', !!input.checked);
                toggle.setAttribute('aria-pressed', input.checked ? 'true' : 'false');
            }
        }

        function applyExportState() {
            var total = 0;
            exportInputs.forEach(function (input) {
                if (input.checked) {
                    total += 1;
                }
                syncExportToggle(input);
            });
            if (exportButton) {
                exportButton.textContent = total ? (exportLabel + ' ' + total) : exportLabel;
                exportButton.disabled = total === 0;
            }
            if (exportForm) {
                var exportBar = exportForm.querySelector('.md-scripts-export-bar');
                if (exportBar) {
                    exportBar.hidden = total === 0;
                }
            }
        }

        function setCategoryState(details) {
            var categoryId = details.getAttribute('data-category-id') || '';
            var savedState = Object.prototype.hasOwnProperty.call(openState, categoryId) ? !!openState[categoryId] : null;
            if (hasTitleFilter) {
                details.open = true;
                return;
            }
            if (savedState === null) {
                details.open = categories.length === 1;
                return;
            }
            details.open = savedState;
        }

        if (!page) {
            return;
        }
        if (page.dataset.scriptsSearchBound === '1') {
            return;
        }
        page.dataset.scriptsSearchBound = '1';

        categories.forEach(function (details) {
            setCategoryState(details);
            details.addEventListener('toggle', function () {
                var id = details.getAttribute('data-category-id') || '';
                openState[id] = details.open;
                writeJsonStorage(STORAGE_KEY, openState);
            });
        });

        exportInputs.forEach(function (input) {
            input.addEventListener('change', applyExportState);
        });
        exportToggles.forEach(function (toggle) {
            toggle.addEventListener('click', function () {
                var card = toggle.closest('.md-scripts-record');
                var input = card ? card.querySelector('[data-scripts-export-checkbox]') : null;
                if (!input) {
                    return;
                }
                input.checked = !input.checked;
                applyExportState();
            });
        });
        applyExportState();

        if (searchButtons.length) {
            searchButtons.forEach(function (button) {
                button.addEventListener('click', function () {
                    var term = button.getAttribute('data-scripts-search-term') || '';
                    if (window.MDJAdminUI && typeof window.MDJAdminUI.openSearch === 'function') {
                        window.MDJAdminUI.openSearch(term);
                        return;
                    }
                    if (window.MDJAdminSearch && typeof window.MDJAdminSearch.setFilter === 'function') {
                        window.MDJAdminSearch.setFilter(term);
                    }
                });
            });
        }

        if (recentlyUpdated) {
            recentlyUpdated.addEventListener('change', function () {
                if (recentlyUpdated.value) {
                    recentlyUpdated.form.submit();
                }
            });
        }

        if (searchForm) {
            searchForm.addEventListener('submit', function () {
                if (searchInput && searchInput.value.trim() === '') {
                    return;
                }
            });
        }

        if (importPanel) {
            var summary = importPanel.querySelector('summary');
            if (summary) {
                summary.addEventListener('click', function () {
                    window.setTimeout(function () {
                        writeJsonStorage('md-scripts-import-open-v1', !!importPanel.open);
                    }, 0);
                });
            }
            importPanel.open = !!readJsonStorage('md-scripts-import-open-v1', false);
        }

        page.querySelectorAll('[data-scripts-confirm]').forEach(function (link) {
            link.addEventListener('click', function (event) {
                var message = link.getAttribute('data-scripts-confirm') || '';
                if (message && !window.confirm(message)) {
                    event.preventDefault();
                }
            });
        });
    }

    function initEditPage(root) {
        var page = root.querySelector('[data-scripts-edit-page]');
        var descriptionToggleButtons = page ? page.querySelectorAll('[data-scripts-description-toggle]') : [];
        var descriptionBlock = document.getElementById('description_div');
        var descriptionPreviewBlock = document.getElementById('description_div_show');
        var autoHideAlerts = page ? page.querySelectorAll('[data-scripts-auto-hide]') : [];
        var moreToggle = page ? page.querySelector('[data-scripts-more-toggle]') : null;
        var morePanel = page ? page.querySelector('[data-scripts-more-actions]') : null;
        var scheduleToggle = page ? page.querySelector('[data-scripts-schedule-toggle]') : null;
        var schedulePanel = page ? page.querySelector('[data-scripts-schedule-panel]') : null;
        var errorNode = page ? page.querySelector('[data-scripts-error-line]') : null;
        var checkCodeButton = page ? page.querySelector('[data-scripts-check-code]') : null;
        var form = page ? page.querySelector('form') : null;
        var codeEditorWrapper = page ? page.querySelector('[data-code-editor]') : null;
        var runDrawerBody = page ? page.querySelector('[data-scripts-run-drawer]') : null;
        var runDrawerFooter = page ? page.querySelector('[data-scripts-run-drawer-footer]') : null;
        var runOpenButtons = page ? page.querySelectorAll('[data-scripts-run-drawer-open]') : [];
        var runSubmitButton = page ? page.querySelector('[data-scripts-run-submit]') : null;
        var codeMirrorLines = [];

        if (!page) {
            return;
        }
        if (page.dataset.scriptsEditBound === '1') {
            return;
        }
        page.dataset.scriptsEditBound = '1';

        autoHideAlerts.forEach(function (node) {
            window.setTimeout(function () {
                node.style.transition = 'opacity .2s ease';
                node.style.opacity = '0';
                window.setTimeout(function () {
                    toggleVisible(node, false);
                }, 220);
            }, 4500);
        });

        if (descriptionToggleButtons.length && descriptionBlock && descriptionPreviewBlock) {
            descriptionToggleButtons.forEach(function (button) {
                button.addEventListener('click', function () {
                    var previewVisible = !descriptionPreviewBlock.hidden;
                    toggleVisible(descriptionPreviewBlock, !previewVisible);
                    toggleVisible(descriptionBlock, previewVisible);
                });
            });
        }

        if (moreToggle && morePanel) {
            moreToggle.addEventListener('click', function () {
                morePanel.hidden = !morePanel.hidden;
            });
        }

        if (scheduleToggle && schedulePanel) {
            scheduleToggle.addEventListener('change', function () {
                schedulePanel.classList.toggle('is-collapsed', !scheduleToggle.checked);
            });
        }

        if (errorNode) {
            if (errorNode.getAttribute('data-scripts-error-enabled') === '1' && errorNode.getAttribute('data-scripts-error-settings') === '1') {
                var errorLine = Number(errorNode.getAttribute('data-scripts-error-line') || 0);
                var errorText = errorNode.getAttribute('data-scripts-error-text') || '';
                var errorUp = errorNode.getAttribute('data-scripts-error-up') === '1';
                if (window.CodeMirror && errorLine > 0) {
                    window.setTimeout(function () {
                        codeMirrorLines = Array.prototype.slice.call(document.querySelectorAll('div pre.CodeMirror-line'));
                        var index = Math.max(0, errorLine - 1);
                        var lineNode = codeMirrorLines[index];
                        if (!lineNode) {
                            return;
                        }
                        lineNode.id = 'errorline_' + errorLine;
                        if (!lineNode.querySelector('.cm-error.md-scripts-code-error-line')) {
                            var errorBlock = document.createElement('div');
                            errorBlock.className = 'cm-error md-scripts-code-error-line';
                            errorBlock.textContent = 'Code error -> ' + errorText;
                            lineNode.appendChild(errorBlock);
                        }
                        if (errorUp) {
                            window.location.hash = '#errorline_' + errorLine;
                        }
                    }, 100);
                } else {
                    errorNode.textContent = errorText;
                }
            }
        }

        if (checkCodeButton && form) {
            checkCodeButton.addEventListener('click', function () {
                if (typeof window.ajaxAutosave === 'function') {
                    window.ajaxAutosave('checkcode');
                }
            });
        }

        function getEditorCode() {
            var textarea = form ? form.querySelector('textarea[name="code"]') : null;
            if (codeEditorWrapper && codeEditorWrapper._codeEditor) {
                return codeEditorWrapper._codeEditor.getValue();
            }
            return textarea ? textarea.value : '';
        }

        function getEditorMode() {
            var modeInput = form ? form.querySelector('[data-code-editor-mode-select]') : null;
            return modeInput ? String(modeInput.value || 'php') : 'php';
        }

        function setRunDrawerState(payload) {
            var statusPill = runDrawerBody ? runDrawerBody.querySelector('[data-scripts-run-status]') : null;
            var statusText = runDrawerBody ? runDrawerBody.querySelector('[data-scripts-run-status-text]') : null;
            var modeNode = runDrawerBody ? runDrawerBody.querySelector('[data-scripts-run-mode]') : null;
            var durationNode = runDrawerBody ? runDrawerBody.querySelector('[data-scripts-run-duration]') : null;
            var contentTypeNode = runDrawerBody ? runDrawerBody.querySelector('[data-scripts-run-content-type]') : null;
            var executedAtNode = runDrawerBody ? runDrawerBody.querySelector('[data-scripts-run-executed-at]') : null;
            var memoryNode = runDrawerBody ? runDrawerBody.querySelector('[data-scripts-run-memory]') : null;
            var outputNode = runDrawerBody ? runDrawerBody.querySelector('[data-scripts-run-output]') : null;
            var returnNode = runDrawerBody ? runDrawerBody.querySelector('[data-scripts-run-return]') : null;
            var headersNode = runDrawerBody ? runDrawerBody.querySelector('[data-scripts-run-headers]') : null;
            var status = payload.status || 'idle';
            var pillText = payload.pill || 'Готов к запуску';

            if (statusPill) {
                statusPill.textContent = pillText;
                statusPill.className = 'md-scripts-run-drawer__pill is-' + status;
            }
            if (statusText) {
                statusText.textContent = payload.statusText || 'Ожидание';
            }
            if (modeNode) {
                modeNode.textContent = (payload.mode || 'php').toUpperCase();
            }
            if (durationNode) {
                durationNode.textContent = payload.duration || '—';
            }
            if (contentTypeNode) {
                contentTypeNode.textContent = payload.contentType || '—';
            }
            if (executedAtNode) {
                executedAtNode.textContent = payload.executedAt || '—';
            }
            if (memoryNode) {
                memoryNode.textContent = payload.memory || '—';
            }
            if (outputNode) {
                outputNode.textContent = payload.output || '—';
            }
            if (returnNode) {
                returnNode.textContent = payload.returnValue || '—';
            }
            if (headersNode) {
                headersNode.textContent = payload.headers || '—';
            }
        }

        function openRunDrawer() {
            if (!runDrawerBody || !window.MDJAdminUI || typeof window.MDJAdminUI.openDrawer !== 'function') {
                return;
            }
            var titleInput = form ? form.querySelector('input[name="title"]') : null;
            var currentTitle = titleInput ? String(titleInput.value || '').trim() : '';
            window.MDJAdminUI.openDrawer({
                owner: 'scripts-run:' + (page.dataset.scriptsEditTitle || 'script'),
                eyebrow: runDrawerBody.getAttribute('data-md-drawer-eyebrow') || 'Скрипты',
                title: currentTitle || runDrawerBody.getAttribute('data-md-drawer-title') || page.dataset.scriptsEditTitle || 'Скрипт',
                subtitle: runDrawerBody.getAttribute('data-md-drawer-subtitle') || '',
                width: '980px',
                body: runDrawerBody,
                footer: runDrawerFooter,
                focus: function () {
                    return runSubmitButton;
                }
            });
        }

        function executeCurrentScript() {
            if (!runDrawerBody) {
                return Promise.resolve();
            }

            var runUrl = runDrawerBody.getAttribute('data-scripts-run-url') || '';
            var titleInput = form ? form.querySelector('input[name="title"]') : null;
            var returnJsonInput = form ? form.querySelector('input[name="return_json"]') : null;
            var code = getEditorCode();
            var mode = getEditorMode();
            var title = titleInput ? titleInput.value : '';
            var requestBody = new URLSearchParams();

            requestBody.set('id', form && form.elements.id ? form.elements.id.value : '');
            requestBody.set('title', title || '');
            requestBody.set('code', code || '');
            requestBody.set('code_editor_mode', mode || 'php');
            requestBody.set('return_json', returnJsonInput && returnJsonInput.checked ? '1' : '0');

            setRunDrawerState({
                status: 'loading',
                pill: 'Выполняю',
                statusText: 'Запрос отправлен',
                mode: mode,
                duration: '—',
                contentType: '—',
                executedAt: new Date().toLocaleString('ru-RU'),
                memory: '—',
                output: 'Запуск скрипта...',
                returnValue: 'Ожидание...',
                headers: 'Ожидание...'
            });

            if (runSubmitButton) {
                runSubmitButton.disabled = true;
            }

            return fetch(runUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                    'Accept': 'application/json'
                },
                body: requestBody.toString()
            }).then(function (response) {
                return response.json();
            }).then(function (data) {
                var isOk = data && data.status === 'ok';
                setRunDrawerState({
                    status: isOk ? 'success' : 'error',
                    pill: isOk ? 'Выполнено' : 'Ошибка',
                    statusText: isOk ? 'Скрипт завершён' : (data.message || 'Ошибка выполнения'),
                    mode: data.mode || mode,
                    duration: data.duration_ms ? (String(data.duration_ms) + ' ms') : '—',
                    contentType: data.content_type || '—',
                    executedAt: data.executed_at || '—',
                    memory: formatBytes(data.memory_peak_bytes),
                    output: data.output || (isOk ? 'Скрипт не вернул текстовый вывод.' : (data.message || 'Ошибка выполнения')),
                    returnValue: typeof data.return_value_type !== 'undefined'
                        ? ('[' + data.return_value_type + ']\n' + formatJson(data.return_value))
                        : (data.syntax ? formatJson(data.syntax) : '—'),
                    headers: data.headers && data.headers.length ? data.headers.join('\n') : '—'
                });

                if (!isOk && window.MDJAdminUI && typeof window.MDJAdminUI.showToast === 'function') {
                    window.MDJAdminUI.showToast({message: data.message || 'Не удалось выполнить скрипт.', type: 'error'});
                }
            }).catch(function (error) {
                setRunDrawerState({
                    status: 'error',
                    pill: 'Ошибка',
                    statusText: 'Не удалось выполнить',
                    mode: mode,
                    duration: '—',
                    contentType: '—',
                    executedAt: '—',
                    memory: '—',
                    output: error && error.message ? error.message : 'Ошибка сетевого запроса.',
                    returnValue: '—',
                    headers: '—'
                });
            }).finally(function () {
                if (runSubmitButton) {
                    runSubmitButton.disabled = false;
                }
            });
        }

        runOpenButtons.forEach(function (button) {
            if (button.dataset.scriptsRunBound === '1') {
                return;
            }
            button.dataset.scriptsRunBound = '1';
            button.addEventListener('click', function (event) {
                event.preventDefault();
                openRunDrawer();
                executeCurrentScript();
            });
        });

        if (runSubmitButton && runSubmitButton.dataset.scriptsRunBound !== '1') {
            runSubmitButton.dataset.scriptsRunBound = '1';
            runSubmitButton.addEventListener('click', function (event) {
                event.preventDefault();
                executeCurrentScript();
            });
        }

        setBreadcrumbLabel(root);
    }

    function init(root) {
        initSearchPage(root);
        initEditPage(root);
    }

    window.MDScriptsUI = {
        init: init,
        escapeHtml: escapeHtml
    };

    if (window.MDJAdminUI && typeof window.MDJAdminUI.registerModuleUI === 'function') {
        window.MDJAdminUI.registerModuleUI('scripts', window.MDScriptsUI);
    }

    document.addEventListener('DOMContentLoaded', function () {
        init(document);
    });
})(window, document);
