/*
 * Ping hosts editor.
 *
 * Keeps the form toggles and the lazy code editor in sync. The form itself is
 * server-rendered; this module only switches visibility and initializes the
 * editor when its panel becomes visible.
 */
(function (window, document) {
    'use strict';

    function initType(form) {
        if (!form || form.dataset.mdPingTypeReady === '1') {
            return;
        }
        var select = form.querySelector('[data-md-ping-type]');
        if (!select) {
            return;
        }
        form.dataset.mdPingTypeReady = '1';

        var searchField = form.querySelector('[data-md-ping-search-word]');
        var hostInput = form.querySelector('[data-md-ping-hostname]');
        var hostHint = form.querySelector('[data-md-ping-hostname-hint]');

        var copy = {
            '0': {
                placeholder: '192.168.1.10 или example.local',
                hint: 'IP-адрес или DNS-имя хоста.'
            },
            '1': {
                placeholder: 'https://example.com/status',
                hint: 'URL страницы, доступность которой нужно проверять.'
            },
            '2': {
                placeholder: '192.168.1.10:80',
                hint: 'Хост и порт в формате host:port.'
            }
        };

        function sync() {
            var type = String(select.value || '0');
            var text = copy[type] || copy['0'];
            if (searchField) {
                searchField.classList.toggle('d-none', type !== '1');
            }
            if (hostInput) {
                hostInput.setAttribute('placeholder', text.placeholder);
            }
            if (hostHint) {
                hostHint.textContent = text.hint;
            }
        }

        select.addEventListener('change', sync);
        sync();
    }

    function initRunGroup(form, kind) {
        var readyKey = kind === 'online' ? 'mdPingRunOnlineReady' : 'mdPingRunOfflineReady';
        if (!form || form.dataset[readyKey] === '1') {
            return;
        }

        var inputs = Array.prototype.slice.call(form.querySelectorAll('[data-md-ping-run-type="' + kind + '"]'));
        var codePanel = form.querySelector('[data-md-ping-code-panel="' + kind + '"]');
        var scriptPanel = form.querySelector('[data-md-ping-script-panel="' + kind + '"]');
        if (!inputs.length || !codePanel || !scriptPanel) {
            return;
        }
        form.dataset[readyKey] = '1';

        function ensureEditor() {
            var wrapper = codePanel.querySelector('[data-code-editor]');
            if (!wrapper || !window.MDCodeEditor || typeof window.MDCodeEditor.init !== 'function') {
                return;
            }
            Promise.resolve(window.MDCodeEditor.init(codePanel)).then(function () {
                window.setTimeout(function () {
                    if (wrapper._codeEditor && typeof wrapper._codeEditor.refresh === 'function') {
                        wrapper._codeEditor.refresh();
                    }
                }, 30);
            });
        }

        function sync() {
            var selected = inputs.find(function (input) {
                return input.checked;
            });
            var isCode = !selected || selected.value === 'code';
            codePanel.classList.toggle('d-none', !isCode);
            scriptPanel.classList.toggle('d-none', isCode);
            var visible = codePanel.offsetParent !== null && !codePanel.classList.contains('d-none');
            if (isCode && visible) {
                ensureEditor();
            }
        }

        form._mdPingSync = form._mdPingSync || {};
        form._mdPingSync[kind] = sync;

        inputs.forEach(function (input) {
            input.addEventListener('change', sync);
        });

        sync();
    }

    function initTabs(form) {
        if (!form || form.dataset.mdPingTabsReady === '1') {
            return;
        }
        var tabs = Array.prototype.slice.call(form.querySelectorAll('[data-md-ping-action-tab]'));
        if (!tabs.length) {
            return;
        }
        form.dataset.mdPingTabsReady = '1';
        var tabInput = form.querySelector('[data-md-ping-tab-input]');

        tabs.forEach(function (tab) {
            tab.addEventListener('shown.bs.tab', function () {
                var kind = tab.getAttribute('data-md-ping-action-tab');
                if (tabInput) {
                    tabInput.value = kind;
                }
                if (form._mdPingSync && typeof form._mdPingSync[kind] === 'function') {
                    form._mdPingSync[kind]();
                }
            });
        });
    }

    function init(root) {
        var scope = root && root.querySelectorAll ? root : document;
        scope.querySelectorAll('[data-md-ping-form]').forEach(function (form) {
            initType(form);
            initTabs(form);
            initRunGroup(form, 'online');
            initRunGroup(form, 'offline');
        });
    }

    if (window.MDJAdminUI && typeof window.MDJAdminUI.registerModuleUI === 'function') {
        window.MDJAdminUI.registerModuleUI('pinghosts-edit', { init: init });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            init(document);
        });
    } else {
        init(document);
    }
})(window, document);
