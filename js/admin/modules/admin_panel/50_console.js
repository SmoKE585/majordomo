(function (window, document) {
    'use strict';

    var admin = window.MDJAdminPanel = window.MDJAdminPanel || {};

    function initAdminConsoleDrawer(root) {
        var drawerContent = document.getElementById('mdAdminConsoleDrawerContent');
        var drawerFooter = document.getElementById('mdAdminConsoleDrawerFooter');
        var output = document.getElementById('console_output');
        var outputHint = document.getElementById('console_output_hintResize');
        var command = document.getElementById('command');
        var commandEditorHost = drawerContent ? drawerContent.querySelector('[data-code-editor-key="admin_console"]') : null;
        var form = drawerContent ? drawerContent.querySelector('form') : null;
        var moduleSelect = document.getElementById('currModuleName');
        var moduleField = document.getElementById('module_add');
        var methodsModule = document.getElementById('methodsModule');
        var methodsList = methodsModule ? methodsModule.querySelector('ul') : null;
        var loaderConsole = document.getElementById('loaderConsole');
        var loaderConsoleModule = document.getElementById('loaderConsoleModule');
        var dangerAlert = document.getElementById('dangerAlertConsole');
        var warningAlert = document.getElementById('warningAlertConsole');
        var historyList = document.getElementById('consoleHistoryList');
        var toggleModuleButton = document.getElementById('btnConsoleToggleModule');
        var clearHistoryButton = document.getElementById('btnConsoleClearHistory');
        var currentModuleName = '';
        var consoleHistoryKey = 'md-admin-console-history-v1';
        var consoleHistory = [];
        var currentHistoryIndex = -1;
        var pendingModuleLoad = false;

        if (!drawerContent || !command || !output) {
            return;
        }

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

        function getConsoleEditor() {
            return commandEditorHost && commandEditorHost._codeEditor ? commandEditorHost._codeEditor : null;
        }

        function getCommandValue() {
            var editor = getConsoleEditor();
            return editor ? editor.getValue() : (command ? command.value : '');
        }

        function setCommandValue(value) {
            var editor = getConsoleEditor();
            var text = String(value || '');
            currentHistoryIndex = -1;

            if (editor) {
                editor.setValue(text);
                editor.focus();
                if (typeof editor.setCursor === 'function') {
                    var lastLine = Math.max(editor.lastLine(), 0);
                    editor.setCursor({line: lastLine, ch: editor.getLine(lastLine).length});
                }
                return;
            }

            if (command) {
                command.value = text;
                command.focus();
                command.setSelectionRange(command.value.length, command.value.length);
            }
        }

        function focusCommand() {
            var editor = getConsoleEditor();
            if (editor) {
                editor.focus();
                return;
            }
            if (command) {
                command.focus();
            }
        }

        function openDrawer() {
            if (!window.MDJAdminDrawerHost) {
                return;
            }
            window.MDJAdminDrawerHost.open({
                owner: 'console',
                eyebrow: drawerContent.getAttribute('data-md-drawer-eyebrow') || 'Панель управления',
                title: drawerContent.getAttribute('data-md-drawer-title') || 'Консоль',
                subtitle: drawerContent.getAttribute('data-md-drawer-subtitle') || '',
                width: '920px',
                body: drawerContent,
                footer: drawerFooter,
                focus: function () {
                    ensureConsoleEditorBindings();
                    return getConsoleEditor() ? null : command;
                }
            });
            window.setTimeout(function () {
                ensureConsoleEditorBindings();
                focusCommand();
            }, 40);
        }

        function closeDrawer() {
            if (window.MDJAdminDrawerHost) {
                window.MDJAdminDrawerHost.close('console');
            }
        }

        function bindConsoleEditor() {
            var editor = getConsoleEditor();
            if (!editor || !commandEditorHost || commandEditorHost.dataset.mdConsoleEditorBound === '1') {
                return false;
            }

            commandEditorHost.dataset.mdConsoleEditorBound = '1';

            var existingExtraKeys = editor.getOption('extraKeys') || {};
            var extraKeys = {};
            Object.keys(existingExtraKeys).forEach(function (key) {
                extraKeys[key] = existingExtraKeys[key];
            });

            extraKeys['Ctrl-Enter'] = function () {
                sendCommand();
            };
            extraKeys['Cmd-Enter'] = function () {
                sendCommand();
            };
            extraKeys.Up = function (cm) {
                if (cm.getCursor().line === 0 && cm.getCursor().ch === 0) {
                    moveHistory(1);
                    return;
                }
                return CodeMirror.Pass;
            };
            extraKeys.Down = function (cm) {
                var cursor = cm.getCursor();
                var lastLine = cm.lastLine();
                if (cursor.line === lastLine && cursor.ch === cm.getLine(lastLine).length) {
                    moveHistory(-1);
                    return;
                }
                return CodeMirror.Pass;
            };

            editor.setOption('extraKeys', extraKeys);
            editor.on('change', function () {
                currentHistoryIndex = -1;
            });

            if (window.MDJAdminDrawerHost && window.MDJAdminDrawerHost.getCurrentOwner() === 'console') {
                window.setTimeout(function () {
                    editor.focus();
                }, 0);
            }

            return true;
        }

        function ensureConsoleEditorBindings() {
            if (bindConsoleEditor()) {
                return;
            }

            if (drawerContent._codeEditorBindTimer) {
                return;
            }

            var attempts = 0;
            drawerContent._codeEditorBindTimer = window.setInterval(function () {
                attempts += 1;
                if (bindConsoleEditor() || attempts > 100) {
                    window.clearInterval(drawerContent._codeEditorBindTimer);
                    drawerContent._codeEditorBindTimer = 0;
                }
            }, 120);
        }

        function toggleDrawer() {
            if (window.MDJAdminDrawerHost && window.MDJAdminDrawerHost.getCurrentOwner() === 'console') {
                closeDrawer();
            } else {
                openDrawer();
            }
            return false;
        }

        function readHistory() {
            try {
                var raw = window.localStorage.getItem(consoleHistoryKey);
                var parsed = raw ? JSON.parse(raw) : [];
                if (!Array.isArray(parsed)) {
                    return [];
                }
                return parsed.filter(function (item) {
                    return typeof item === 'string' && item.trim() !== '';
                }).slice(0, 20);
            } catch (e) {
                return [];
            }
        }

        function saveHistory(items) {
            try {
                window.localStorage.setItem(consoleHistoryKey, JSON.stringify(items.slice(0, 20)));
            } catch (e) {
                // Ignore storage errors. Console still works without persistence.
            }
        }

        function pushHistory(value) {
            var normalized = String(value || '').trim();
            if (!normalized) {
                return;
            }
            consoleHistory = consoleHistory.filter(function (item) {
                return item !== normalized;
            });
            consoleHistory.unshift(normalized);
            saveHistory(consoleHistory);
            renderHistory();
        }

        function renderHistory() {
            if (!historyList) {
                return;
            }
            historyList.innerHTML = '';
            if (!consoleHistory.length) {
                historyList.innerHTML = '<div class="md-admin-console-history__empty">История пуста. После отправки команд она появится здесь и в стрелках редактора.</div>';
                return;
            }

            consoleHistory.forEach(function (item) {
                var button = document.createElement('button');
                button.type = 'button';
                button.className = 'md-admin-console-history__item';
                button.setAttribute('data-md-console-history-value', item);
                button.title = 'Вставить в редактор';
                button.textContent = item;
                historyList.appendChild(button);
            });
        }

        function setAlerts(kind, message) {
            if (dangerAlert) {
                dangerAlert.hidden = kind !== 'danger';
                dangerAlert.textContent = kind === 'danger' ? message : dangerAlert.textContent;
            }
            if (warningAlert) {
                warningAlert.hidden = kind !== 'warning';
                warningAlert.textContent = kind === 'warning' ? message : warningAlert.textContent;
            }
        }

        function clearAlerts() {
            if (dangerAlert) {
                dangerAlert.hidden = true;
            }
            if (warningAlert) {
                warningAlert.hidden = true;
            }
        }

        function setLoaderVisible(node, visible) {
            if (!node) {
                return;
            }
            node.classList.toggle('is-visible', !!visible);
        }

        function escapeCommandForDisplay(value) {
            return escapeHtml(value).replace(/\n/g, '<br>');
        }

        function appendOutputEntry(commandText, resultText, metaText) {
            var entry = document.createElement('div');
            entry.className = 'md-admin-console-output__entry';

            var meta = document.createElement('div');
            meta.className = 'md-admin-console-output__meta';
            meta.innerHTML = '<strong>' + escapeHtml(metaText || 'консоль') + '</strong>';

            var commandNode = document.createElement('div');
            commandNode.className = 'md-admin-console-output__command';
            commandNode.innerHTML = escapeCommandForDisplay(commandText);

            var resultNode = document.createElement('div');
            resultNode.className = 'md-admin-console-output__result';
            resultNode.innerHTML = escapeCommandForDisplay(resultText || '');

            entry.appendChild(meta);
            entry.appendChild(commandNode);
            entry.appendChild(resultNode);

            output.prepend(entry);
        }

        function setOutputMessage(message, kind) {
            output.innerHTML = '<div class="md-admin-console-output__entry"><div class="md-admin-console-output__meta"><strong>' + escapeHtml(kind || 'консоль') + '</strong></div><div class="md-admin-console-output__result">' + escapeCommandForDisplay(message) + '</div></div>';
        }

        function setMethods(methods) {
            if (!methodsModule || !methodsList) {
                return;
            }
            methodsList.innerHTML = '';
            if (!Array.isArray(methods) || !methods.length) {
                methodsModule.hidden = false;
                methodsList.innerHTML = '<div class="md-admin-console-methods__empty">Для выбранного модуля не найдено доступных методов.</div>';
                return;
            }

            methodsModule.hidden = false;
            methods.forEach(function (methodName) {
                var method = String(methodName || '').trim();
                if (!method) {
                    return;
                }
                var button = document.createElement('button');
                button.type = 'button';
                button.className = 'btn btn-sm btn-outline-primary md-admin-console-methods__item';
                button.setAttribute('data-md-console-method', method);
                button.textContent = '$' + currentModuleName + '->' + method + '();';
                methodsList.appendChild(button);
            });
        }

        function setModuleVisibility(visible) {
            if (!moduleSelect) {
                return;
            }
            moduleSelect.classList.toggle('is-visible', !!visible);
        }

        function insertIntoTextarea(value) {
            var text = String(value || '');
            if (!text) {
                return;
            }
            setCommandValue(text);
        }

        function buildModuleBootstrapCommand(moduleName) {
            return "include(DIR_MODULES.'" + moduleName + "/" + moduleName + ".class.php');PHP_EOL$" + moduleName + " = new " + moduleName + "();";
        }

        function loadModuleMethods(moduleName) {
            var module = String(moduleName || '').trim();
            if (module && !/^[A-Za-z_][A-Za-z0-9_]*$/.test(module)) {
                setAlerts('danger', 'Некорректное имя модуля.');
                return;
            }
            currentModuleName = module;
            if (!module || module === '0') {
                moduleField.value = '';
                methodsModule.hidden = true;
                methodsList.innerHTML = '';
                return;
            }

            moduleField.value = buildModuleBootstrapCommand(module);
            methodsModule.hidden = false;
            setLoaderVisible(loaderConsoleModule, true);
            clearAlerts();
            pendingModuleLoad = true;

            var body = new URLSearchParams();
            body.set('ajax_panel', '1');
            body.set('op', 'console');
            body.set('command', moduleField.value + "PHP_EOLjson_encode(get_class_methods('" + module + "'));");

            fetch('?ajax_panel=1&op=console', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                },
                body: body.toString()
            }).then(function (response) {
                return response.text();
            }).then(function (text) {
                var methods = [];
                try {
                    methods = JSON.parse(text);
                } catch (e) {
                    methods = [];
                }
                setMethods(methods);
                setOutputMessage('Модуль подключен: ' + module, 'консоль');
            }).catch(function () {
                setMethods([]);
                setAlerts('warning', 'Не удалось загрузить список методов модуля.');
            }).finally(function () {
                pendingModuleLoad = false;
                setLoaderVisible(loaderConsoleModule, false);
            });
        }

        function sendCommand(customCommand) {
            var rawCommand = typeof customCommand === 'string' ? customCommand : getCommandValue();
            var trimmedCommand = String(rawCommand || '').trim();

            if (!trimmedCommand) {
                setAlerts('danger', 'Команда пуста. Введите код или выражение.');
                return false;
            }

            if (trimmedCommand === 'clear' || trimmedCommand === 'clear;') {
                clearAlerts();
                output.innerHTML = '<div class="md-admin-console-output__entry"><div class="md-admin-console-output__meta"><strong>консоль</strong></div><div class="md-admin-console-output__result"><em>Консоль очищена.</em></div></div>';
                setCommandValue('');
                setLoaderVisible(loaderConsole, false);
                return false;
            }

            clearAlerts();
            setLoaderVisible(loaderConsole, true);

            var finalCommand = trimmedCommand;
            if (!customCommand && moduleField && moduleField.value) {
                finalCommand = moduleField.value + trimmedCommand;
            }

            var body = new URLSearchParams();
            body.set('ajax_panel', '1');
            body.set('op', 'console');
            body.set('command', finalCommand);

            return fetch('?ajax_panel=1&op=console', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                },
                body: body.toString()
            }).then(function (response) {
                return response.text();
            }).then(function (text) {
                var result = text || 'Запрос выполнен.';
                appendOutputEntry(finalCommand, result, 'консоль');
                pushHistory(trimmedCommand);
                setCommandValue('');
                if (outputHint) {
                    outputHint.hidden = false;
                }
            }).catch(function () {
                setAlerts('danger', 'Не удалось отправить запрос.');
            }).finally(function () {
                setLoaderVisible(loaderConsole, false);
                if (!pendingModuleLoad) {
                    focusCommand();
                }
            });
        }

        function moveHistory(step) {
            if (!consoleHistory.length) {
                return;
            }
            if (currentHistoryIndex === -1) {
                currentHistoryIndex = step > 0 ? 0 : -1;
            } else if (step > 0) {
                currentHistoryIndex = Math.min(currentHistoryIndex + 1, consoleHistory.length - 1);
            } else {
                currentHistoryIndex = currentHistoryIndex <= 0 ? -1 : currentHistoryIndex - 1;
            }

            if (currentHistoryIndex === -1) {
                setCommandValue('');
                return;
            }

            setCommandValue(consoleHistory[currentHistoryIndex] || '');
        }

        consoleHistory = readHistory();
        renderHistory();
        ensureConsoleEditorBindings();

        if (!output.innerHTML.trim()) {
            setOutputMessage('Ожидание команды...', 'консоль');
        }

        if (drawerContent.dataset.mdConsoleBound !== '1') {
            drawerContent.dataset.mdConsoleBound = '1';
            drawerContent.addEventListener('click', function (event) {
                var historyButton = event.target.closest('[data-md-console-history-value]');
                if (historyButton) {
                    event.preventDefault();
                    insertIntoTextarea(historyButton.getAttribute('data-md-console-history-value'));
                    return;
                }

                var methodButton = event.target.closest('[data-md-console-method]');
                if (methodButton) {
                    event.preventDefault();
                    insertIntoTextarea(methodButton.getAttribute('data-md-console-method'));
                    return;
                }
            });
        }

        if (form && form.dataset.mdConsoleFormBound !== '1') {
            form.dataset.mdConsoleFormBound = '1';
            form.addEventListener('submit', function (event) {
                event.preventDefault();
                sendCommand();
            });
        }

        if (toggleModuleButton && toggleModuleButton.dataset.mdConsoleBound !== '1') {
            toggleModuleButton.dataset.mdConsoleBound = '1';
            toggleModuleButton.addEventListener('click', function () {
                setModuleVisibility(!moduleSelect.classList.contains('is-visible'));
                if (moduleSelect.classList.contains('is-visible')) {
                    moduleSelect.focus();
                }
            });
        }

        if (clearHistoryButton && clearHistoryButton.dataset.mdConsoleBound !== '1') {
            clearHistoryButton.dataset.mdConsoleBound = '1';
            clearHistoryButton.addEventListener('click', function () {
                consoleHistory = [];
                saveHistory(consoleHistory);
                renderHistory();
                focusCommand();
            });
        }

        var clearCommandButton = document.getElementById('btnConsoleClearCommand');
        if (clearCommandButton && clearCommandButton.dataset.mdConsoleBound !== '1') {
            clearCommandButton.dataset.mdConsoleBound = '1';
            clearCommandButton.addEventListener('click', function () {
                setCommandValue('');
                focusCommand();
            });
        }

        if (moduleSelect && moduleSelect.dataset.mdConsoleBound !== '1') {
            moduleSelect.dataset.mdConsoleBound = '1';
            moduleSelect.addEventListener('change', function () {
                loadModuleMethods(moduleSelect.value);
            });
        }

        if (command.dataset.mdConsoleBound !== '1') {
            command.dataset.mdConsoleBound = '1';
            command.addEventListener('keydown', function (event) {
                if (event.key === 'ArrowUp' && command.selectionStart === 0 && command.selectionEnd === 0) {
                    event.preventDefault();
                    moveHistory(1);
                    return;
                }
                if (event.key === 'ArrowDown' && command.selectionStart === command.value.length && command.selectionEnd === command.value.length) {
                    event.preventDefault();
                    moveHistory(-1);
                    return;
                }
                if (event.key === 'Enter' && (event.ctrlKey || event.metaKey)) {
                    event.preventDefault();
                    sendCommand();
                }
            });
            command.addEventListener('input', function () {
                currentHistoryIndex = -1;
            });
        }

        root.querySelectorAll('[data-md-console-open]').forEach(function (button) {
            if (button.dataset.mdConsoleOpenBound === '1') {
                return;
            }
            button.dataset.mdConsoleOpenBound = '1';
            button.addEventListener('click', function (event) {
                event.preventDefault();
                openDrawer();
            });
        });

        if (historyList && !historyList.dataset.mdConsoleBound) {
            historyList.dataset.mdConsoleBound = '1';
        }

        window.MDJAdminConsole = {
            open: openDrawer,
            close: closeDrawer,
            toggle: toggleDrawer,
            send: sendCommand,
            insertModule: loadModuleMethods,
            insertCommand: insertIntoTextarea,
            getHistory: function () {
                return consoleHistory.slice();
            },
            setHistory: function (items) {
                consoleHistory = Array.isArray(items) ? items.filter(function (item) {
                    return typeof item === 'string' && item.trim() !== '';
                }).slice(0, 20) : [];
                saveHistory(consoleHistory);
                renderHistory();
            }
        };
    }

    admin.addBootTask('admin-console-drawer', initAdminConsoleDrawer);
})(window, document);
