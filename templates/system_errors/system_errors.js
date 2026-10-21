/*
 * System errors page: keep-history switch and history expand/collapse.
 *
 * The list is server-rendered; this module only submits the per-row switch
 * form and toggles <details> elements in the history card.
 */
(function (window, document) {
    'use strict';

    function initKeepForms(root) {
        (root || document).querySelectorAll('[data-md-error-keep]').forEach(function (input) {
            if (input.dataset.mdErrorKeepReady === '1') {
                return;
            }
            input.dataset.mdErrorKeepReady = '1';
            input.addEventListener('change', function () {
                if (input.form) {
                    input.form.submit();
                }
            });
        });
    }

    function initHistory(root) {
        var scope = root && root.querySelectorAll ? root : document;

        scope.querySelectorAll('[data-md-errors-expand-all]').forEach(function (button) {
            if (button.dataset.mdErrorsToggleReady === '1') {
                return;
            }
            button.dataset.mdErrorsToggleReady = '1';
            button.addEventListener('click', function () {
                scope.querySelectorAll('[data-md-errors-history] .md-errors-event, [data-md-errors-history] .md-errors-more').forEach(function (event) {
                    event.open = true;
                });
            });
        });

        scope.querySelectorAll('[data-md-errors-collapse-all]').forEach(function (button) {
            if (button.dataset.mdErrorsToggleReady === '1') {
                return;
            }
            button.dataset.mdErrorsToggleReady = '1';
            button.addEventListener('click', function () {
                scope.querySelectorAll('[data-md-errors-history] .md-errors-event, [data-md-errors-history] .md-errors-more').forEach(function (event) {
                    event.open = false;
                });
            });
        });
    }

    function init(root) {
        initKeepForms(root);
        initHistory(root);
    }

    if (window.MDJAdminUI && typeof window.MDJAdminUI.registerModuleUI === 'function') {
        window.MDJAdminUI.registerModuleUI('system-errors', { init: init });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            init(document);
        });
    } else {
        init(document);
    }
})(window, document);
