/*
 * Property editor: show the validation settings for the selected type.
 */
(function (window, document) {
    'use strict';

    function initForm(form) {
        if (!form || form.dataset.mdPropertyFormReady === '1') {
            return;
        }

        var select = form.querySelector('[data-md-property-validation]');
        if (!select) {
            return;
        }

        form.dataset.mdPropertyFormReady = '1';

        function sync() {
            form.querySelectorAll('[data-md-property-validation-block]').forEach(function (block) {
                block.classList.toggle('d-none', block.getAttribute('data-md-property-validation-block') !== select.value);
            });
        }

        select.addEventListener('change', sync);
        sync();
    }

    function init(root) {
        var scope = root && root.querySelectorAll ? root : document;
        scope.querySelectorAll('[data-md-property-validation]').forEach(function (select) {
            initForm(select.closest('form'));
        });
    }

    if (window.MDJAdminUI && typeof window.MDJAdminUI.registerModuleUI === 'function') {
        window.MDJAdminUI.registerModuleUI('properties-edit', { init: init });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            init(document);
        });
    } else {
        init(document);
    }
})(window, document);
