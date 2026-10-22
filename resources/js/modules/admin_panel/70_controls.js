(function (window, document) {
    'use strict';

    function initSearchableSelects(root) {
        if (!window.TomSelect) {
            return;
        }
        (root || document).querySelectorAll('select[data-md-searchable]').forEach(function (select) {
            if (select.tomselect || select.dataset.mdSearchableReady === '1') {
                return;
            }
            select.dataset.mdSearchableReady = '1';
            var multiple = select.multiple;
            new window.TomSelect(select, {
                allowEmptyOption: true,
                create: false,
                maxItems: multiple ? null : 1,
                maxOptions: 300,
                plugins: multiple ? ['remove_button'] : [],
                sortField: [{ field: 'text', direction: 'asc' }]
            });
        });
    }

    function updateRange(input) {
        var min = parseFloat(input.min || '0');
        var max = parseFloat(input.max || '100');
        var value = parseFloat(input.value || '0');
        var progress = max > min ? ((value - min) / (max - min)) * 100 : 0;
        input.style.setProperty('--md-range-progress', Math.max(0, Math.min(100, progress)) + '%');

        var outputId = input.getAttribute('data-md-range-output');
        if (outputId) {
            var output = document.getElementById(outputId);
            if (output) {
                output.textContent = input.value + (input.getAttribute('data-md-range-unit') || '');
            }
        }
    }

    function initRanges(root) {
        (root || document).querySelectorAll('input[type="range"].md-range, input[type="range"][data-md-range]').forEach(function (input) {
            updateRange(input);
            if (input.dataset.mdRangeReady === '1') {
                return;
            }
            input.dataset.mdRangeReady = '1';
            input.addEventListener('input', function () {
                updateRange(input);
            });
            input.addEventListener('change', function () {
                updateRange(input);
            });
        });
    }

    function initPasswordToggles(root) {
        (root || document).querySelectorAll('[data-md-password-toggle]').forEach(function (button) {
            if (button.dataset.mdPasswordReady === '1') {
                return;
            }
            button.dataset.mdPasswordReady = '1';
            button.addEventListener('click', function () {
                var input = document.getElementById(button.getAttribute('data-md-password-toggle'));
                if (!input) {
                    return;
                }
                var visible = input.type === 'text';
                input.type = visible ? 'password' : 'text';
                button.setAttribute('aria-pressed', visible ? 'false' : 'true');
                button.setAttribute('aria-label', visible ? 'Показать пароль' : 'Скрыть пароль');
                var icon = button.querySelector('i');
                if (icon) {
                    icon.className = visible ? 'ti ti-eye' : 'ti ti-eye-off';
                }
            });
        });
    }

    var alertDismissBound = false;

    function initAlertDismiss() {
        if (alertDismissBound) {
            return;
        }
        alertDismissBound = true;
        document.addEventListener('click', function (event) {
            var button = event.target.closest('[data-md-dismiss="alert"], [data-md-dismiss="hint"]');
            if (!button) {
                return;
            }
            var message = button.closest('.md-alert, .md-hint');
            if (message) {
                message.remove();
            }
        });
    }

    function initFormControls(root) {
        initSearchableSelects(root);
        initRanges(root);
        initPasswordToggles(root);
        initAlertDismiss(root);
    }

    if (window.MDJAdminUI && typeof window.MDJAdminUI.registerModuleUI === 'function') {
        window.MDJAdminUI.registerModuleUI('form-controls', {
            init: initFormControls
        });
    }
})(window, document);

