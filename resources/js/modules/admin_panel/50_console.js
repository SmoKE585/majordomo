(function (window, document) {
    'use strict';

    var admin = window.MDJAdminPanel = window.MDJAdminPanel || {};

    function initAdminConsoleDrawer(root) {
        var drawerContent = document.getElementById('mdAdminConsoleDrawerContent');
        var output = document.getElementById('console_output');
        var command = document.getElementById('command');
        var commandEditorHost = drawerContent ? drawerContent.querySelector('[data-code-editor-key="admin_console"]') : null;
        var form = drawerContent ? drawerContent.querySelector('form') : null;
        var moduleSelect = document.getElementById('currModuleName');
        var moduleField = document.getElementById('module_add');
        var methodsModule = document.getElementById('methodsModule');
        var methodsList = methodsModule ? methodsModule.querySelector('[data-md-console-methods-list]') : null;
        var methodsFilter = document.getElementById('consoleMethodsFilter');
        var methodsCount = document.getElementById('consoleMethodsCount');
        var methodsPanelName = document.getElementById('consoleMethodsPanelName');
        var loaderConsole = document.getElementById('loaderConsole');
        var loaderConsoleModule = document.getElementById('loaderConsoleModule');
        var dangerAlert = document.getElementById('dangerAlertConsole');
        var warningAlert = document.getElementById('warningAlertConsole');
        var dangerAlertText = dangerAlert ? dangerAlert.querySelector('[data-md-console-alert-text]') : null;
        var warningAlertText = warningAlert ? warningAlert.querySelector('[data-md-console-alert-text]') : null;
        var historyList = document.getElementById('consoleHistoryList');
        var historyCount = document.getElementById('consoleHistoryCount');
        var consoleStatus = document.getElementById('consoleStatus');
        var toggleModuleButton = document.getElementById('btnConsoleToggleModule');
        var toggleModuleLabel = document.getElementById('btnConsoleToggleModuleLabel');
        var methodsCloseButton = document.getElementById('btnConsoleMethodsClose');
        var clearHistoryButton = document.getElementById('btnConsoleClearHistory');
        var clearOutputButton = document.getElementById('btnConsoleClearOutput');
        var currentModuleName = '';
        var consoleHistoryKey = 'md-admin-console-history-v1';
        var consoleHistory = [];
        var currentHistoryIndex = -1;
        var pendingModuleLoad = false;

        if (!drawerContent || !command || !output) {
            return;
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
                width: 'min(1040px, 100vw)',
                body: drawerContent,
                focus: function () {
                    ensureConsoleEditorBindings();
                    return getConsoleEditor() ? null : command;
                }
            });
            if (window.MDCodeEditor && typeof window.MDCodeEditor.init === 'function') {
                window.MDCodeEditor.init(drawerContent);
            }
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

            editor.addKeymap({
                'Mod-Enter': function () {
                    sendCommand();
                    return true;
                },
                'Up': function (instance) {
                    var cursor = instance.getCursor();
                    if (cursor.line === 0 && cursor.ch === 0) {
                        moveHistory(1);
                        return true;
                    }
                    return false;
                },
                'Down': function (instance) {
                    var cursor = instance.getCursor();
                    var lastLine = instance.lastLine();
                    if (cursor.line === lastLine && cursor.ch === instance.getLine(lastLine).length) {
                        moveHistory(-1);
                        return true;
                    }
                    return false;
                }
            });

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
            if (historyCount) {
                historyCount.textContent = String(consoleHistory.length);
            }
            historyList.innerHTML = '';
            if (!consoleHistory.length) {
                historyList.innerHTML = '<div class="md-console-history__empty">Пока пусто. Выполненные команды появятся здесь и в стрелках редактора.</div>';
                return;
            }

            consoleHistory.forEach(function (item) {
                var button = document.createElement('button');
                button.type = 'button';
                button.className = 'md-console-history__item';
                button.setAttribute('data-md-console-history-value', item);
                button.title = 'Вставить в редактор:\n' + item;
                button.textContent = item.length > 64 ? item.slice(0, 64) + '…' : item;
                historyList.appendChild(button);
            });
        }

        function setAlerts(kind, message) {
            if (dangerAlert) {
                dangerAlert.hidden = kind !== 'danger';
                if (dangerAlertText && kind === 'danger') {
                    dangerAlertText.textContent = message;
                }
            }
            if (warningAlert) {
                warningAlert.hidden = kind !== 'warning';
                if (warningAlertText && kind === 'warning') {
                    warningAlertText.textContent = message;
                }
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

        function setConsoleStatus(state) {
            if (!consoleStatus) {
                return;
            }
            var states = {
                idle: ['md-badge', 'Ожидание'],
                running: ['md-badge md-badge--warning', 'Выполняется…'],
                success: ['md-badge md-badge--success', 'Готово'],
                warning: ['md-badge md-badge--warning', 'Предупреждение'],
                error: ['md-badge md-badge--danger', 'Ошибка']
            };
            var next = states[state] || states.idle;
            consoleStatus.className = next[0];
            consoleStatus.textContent = next[1];
        }

        function outputEmptyMarkup() {
            return '<div class="md-console-empty" data-md-console-empty>' +
                '<span class="md-console-empty__icon"><i class="ti ti-terminal-2" aria-hidden="true"></i></span>' +
                '<div class="md-console-empty__title">Готово к работе</div>' +
                '<div class="md-console-empty__text">Напишите команду и нажмите Ctrl+Enter. Результат появится здесь.</div>' +
                '</div>';
        }

        function setOutputEmpty() {
            if (output) {
                output.innerHTML = outputEmptyMarkup();
            }
            setConsoleStatus('idle');
        }

        function appendOutputEntry(commandText, resultText, moduleName, duration, level) {
            if (!output) {
                return;
            }
            var existingEmpty = output.querySelector('[data-md-console-empty]');
            if (existingEmpty) {
                existingEmpty.remove();
            }

            var tone = level === 'error' ? 'danger' : (level === 'warning' ? 'warning' : 'success');
            var toneLabel = level === 'error' ? 'Ошибка' : (level === 'warning' ? 'Предупреждение' : 'Готово');

            var entry = document.createElement('div');
            entry.className = 'md-console-entry' + (level === 'error' ? ' md-console-entry--error' : (level === 'warning' ? ' md-console-entry--warning' : ''));

            var head = document.createElement('div');
            head.className = 'md-console-entry__head';

            var status = document.createElement('span');
            status.className = 'md-badge md-badge--' + tone;
            status.textContent = toneLabel;
            head.appendChild(status);

            if (moduleName) {
                var moduleBadge = document.createElement('span');
                moduleBadge.className = 'md-badge md-badge--outline';
                moduleBadge.textContent = moduleName;
                head.appendChild(moduleBadge);
            }

            var time = document.createElement('span');
            time.className = 'md-console-entry__time';
            time.textContent = new Date().toLocaleTimeString('ru-RU', {hour: '2-digit', minute: '2-digit', second: '2-digit'});
            head.appendChild(time);

            if (typeof duration === 'number' && isFinite(duration)) {
                var timing = document.createElement('span');
                timing.className = 'md-console-entry__duration';
                timing.textContent = duration.toFixed(2) + ' c';
                head.appendChild(timing);
            }

            var resultNode = document.createElement('pre');
            resultNode.className = 'md-console-entry__result';
            resultNode.textContent = resultText || 'Запрос выполнен.';

            var commandDetails = document.createElement('details');
            commandDetails.className = 'md-console-entry__command';
            var commandSummary = document.createElement('summary');
            commandSummary.textContent = 'Показать запрос';
            var commandNode = document.createElement('pre');
            commandNode.textContent = commandText || '';
            commandDetails.appendChild(commandSummary);
            commandDetails.appendChild(commandNode);

            entry.appendChild(head);
            entry.appendChild(resultNode);
            entry.appendChild(commandDetails);
            output.prepend(entry);
        }

        function updateModuleActions() {
            var hasModule = !!(currentModuleName && currentModuleName !== '0');
            var methodsVisible = !!(methodsModule && !methodsModule.hidden);
            var methodButtons = methodsList ? methodsList.querySelectorAll('[data-md-console-method]') : [];
            if (toggleModuleButton) {
                toggleModuleButton.disabled = !hasModule;
                toggleModuleButton.setAttribute('aria-expanded', methodsVisible ? 'true' : 'false');
            }
            if (toggleModuleLabel) {
                toggleModuleLabel.textContent = methodsVisible ? 'Скрыть методы' : 'Методы';
            }
            if (methodsCount) {
                methodsCount.hidden = !hasModule;
                methodsCount.textContent = String(methodButtons.length);
            }
            if (methodsPanelName) {
                methodsPanelName.hidden = !hasModule;
                methodsPanelName.textContent = hasModule ? currentModuleName : '';
            }
        }

        function filterMethods() {
            if (!methodsList) {
                return;
            }
            var query = methodsFilter ? methodsFilter.value.toLowerCase().trim() : '';
            var visible = 0;
            methodsList.querySelectorAll('[data-md-console-method]').forEach(function (button) {
                var isVisible = query === '' || button.textContent.toLowerCase().indexOf(query) !== -1;
                button.hidden = !isVisible;
                if (isVisible) {
                    visible++;
                }
            });
            var empty = methodsList.querySelector('[data-md-console-methods-empty]');
            if (empty) {
                empty.hidden = visible !== 0 || !methodsList.querySelector('[data-md-console-method]');
            }
        }

        function setMethods(methods) {
            if (!methodsModule || !methodsList) {
                return;
            }
            methodsList.innerHTML = '';
            if (!Array.isArray(methods) || !methods.length) {
                methodsModule.hidden = false;
                methodsList.innerHTML = '<div class="md-console-methods__empty" data-md-console-methods-empty>Для выбранного модуля не найдено доступных методов.</div>';
                updateModuleActions();
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
                button.className = 'md-console-method';
                button.setAttribute('data-md-console-method', method);
                button.textContent = '$' + currentModuleName + '->' + method + '();';
                methodsList.appendChild(button);
            });
            var empty = document.createElement('div');
            empty.className = 'md-console-methods__empty';
            empty.setAttribute('data-md-console-methods-empty', '');
            empty.hidden = true;
            empty.textContent = 'Методы не найдены.';
            methodsList.appendChild(empty);
            if (methodsFilter) {
                methodsFilter.value = '';
            }
            updateModuleActions();
            filterMethods();
        }

        function setMethodsPanel(visible) {
            if (!methodsModule) {
                return;
            }
            methodsModule.hidden = !visible;
            updateModuleActions();
            if (visible && methodsFilter) {
                methodsFilter.focus({preventScroll: true});
            }
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
                updateModuleActions();
                return;
            }

            moduleField.value = buildModuleBootstrapCommand(module);
            var revealMethods = !methodsModule.hidden;
            updateModuleActions();
            setLoaderVisible(loaderConsoleModule, true);
            clearAlerts();
            pendingModuleLoad = true;

            var command = moduleField.value + "PHP_EOLjson_encode(get_class_methods('" + module + "'));";

            window.MDJAdminApi.console(command).then(function (text) {
                var methods = [];
                try {
                    methods = JSON.parse(text);
                } catch (e) {
                    methods = [];
                }
                setMethods(methods);
            }).catch(function () {
                setMethods([]);
                setAlerts('warning', 'Не удалось загрузить список методов модуля.');
            }).finally(function () {
                pendingModuleLoad = false;
                setLoaderVisible(loaderConsoleModule, false);
                if (!revealMethods) {
                    methodsModule.hidden = true;
                }
                updateModuleActions();
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
                setOutputEmpty();
                setCommandValue('');
                setLoaderVisible(loaderConsole, false);
                return false;
            }

            clearAlerts();
            setConsoleStatus('running');
            setLoaderVisible(loaderConsole, true);
            var startedAt = (window.performance && typeof window.performance.now === 'function') ? window.performance.now() : Date.now();

            var finalCommand = trimmedCommand;
            if (!customCommand && moduleField && moduleField.value) {
                finalCommand = moduleField.value + trimmedCommand;
            }

            return window.MDJAdminApi.console(finalCommand).then(function (text) {
                var result = text || 'Запрос выполнен.';
                var elapsed = ((window.performance && typeof window.performance.now === 'function' ? window.performance.now() : Date.now()) - startedAt) / 1000;
                var level = 'success';
                if (/Fatal error|Parse error|Uncaught|E_COMPILE_ERROR|E_ERROR|MajorDoMo dev error|\bError:/i.test(result)) {
                    level = 'error';
                } else if (/Warning|E_WARNING|Deprecated|Notice/i.test(result)) {
                    level = 'warning';
                }
                appendOutputEntry(trimmedCommand, result, currentModuleName, elapsed, level);
                pushHistory(trimmedCommand);
                setCommandValue('');
                setConsoleStatus(level);
            }).catch(function () {
                setConsoleStatus('error');
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
            setOutputEmpty();
        }
        updateModuleActions();

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
                if (!currentModuleName || currentModuleName === '0') {
                    return;
                }
                setMethodsPanel(!!methodsModule.hidden);
            });
        }

        if (methodsCloseButton && methodsCloseButton.dataset.mdConsoleBound !== '1') {
            methodsCloseButton.dataset.mdConsoleBound = '1';
            methodsCloseButton.addEventListener('click', function () {
                setMethodsPanel(false);
                focusCommand();
            });
        }

        if (methodsFilter && methodsFilter.dataset.mdConsoleBound !== '1') {
            methodsFilter.dataset.mdConsoleBound = '1';
            methodsFilter.addEventListener('input', filterMethods);
        }

        if (clearOutputButton && clearOutputButton.dataset.mdConsoleBound !== '1') {
            clearOutputButton.dataset.mdConsoleBound = '1';
            clearOutputButton.addEventListener('click', function () {
                clearAlerts();
                setOutputEmpty();
                focusCommand();
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
