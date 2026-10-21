/*
 * Method editor: switch between a ready script and inline code.
 *
 * Server renders both blocks; this module only toggles visibility.
 */
(function (window, document) {
    'use strict';

    function initForm(form) {
        if (!form || form.dataset.mdMethodFormReady === '1') {
            return;
        }

        var inputs = Array.prototype.slice.call(form.querySelectorAll('[data-md-method-run-type]'));
        var codePanel = form.querySelector('[data-md-method-code-panel]');
        var scriptSelect = form.querySelector('[data-md-method-script-select]');
        if (!inputs.length || !codePanel || !scriptSelect) {
            return;
        }

        form.dataset.mdMethodFormReady = '1';

        function ensureCodeEditor() {
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
            scriptSelect.classList.toggle('d-none', isCode);
            if (isCode) {
                ensureCodeEditor();
            }
        }

        inputs.forEach(function (input) {
            input.addEventListener('change', sync);
        });

        sync();
    }

    function init(root) {
        var scope = root && root.querySelectorAll ? root : document;
        scope.querySelectorAll('[data-md-method-run-type]').forEach(function (input) {
            initForm(input.closest('form'));
        });
    }

    if (window.MDJAdminUI && typeof window.MDJAdminUI.registerModuleUI === 'function') {
        window.MDJAdminUI.registerModuleUI('methods-edit', { init: init });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            init(document);
        });
    } else {
        init(document);
    }
})(window, document);
