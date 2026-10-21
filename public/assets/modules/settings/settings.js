(function (window, document) {
    'use strict';

    function initSettingsUI(root) {
        var page = (root || document).querySelector('.md-settings-page');
        if (!page || page.dataset.mdSettingsBound === '1') {
            return;
        }

        page.dataset.mdSettingsBound = '1';

        var searchInput = page.querySelector('[data-settings-search-input]');
        var cards = Array.prototype.slice.call(page.querySelectorAll('[data-settings-card]'));
        var emptyState = page.querySelector('[data-settings-empty]');

        function applySearch() {
            if (!searchInput) {
                return;
            }

            var query = (searchInput.value || '').toLowerCase().trim();
            var visibleCount = 0;

            cards.forEach(function (card) {
                var haystack = (card.getAttribute('data-settings-search') || '').toLowerCase();
                var isVisible = query === '' || haystack.indexOf(query) !== -1;
                var dependencyHidden = card.getAttribute('data-settings-dependency-hidden') === '1';
                card.hidden = !isVisible || dependencyHidden;
                if (isVisible && !dependencyHidden) {
                    visibleCount += 1;
                }
            });

            if (emptyState) {
                emptyState.classList.toggle('is-visible', visibleCount === 0);
            }
        }

        function fieldValue(name) {
            var inputs = page.querySelectorAll('[name="value_' + name + '"]');
            for (var i = 0; i < inputs.length; i++) {
                var input = inputs[i];
                if (input.type === 'radio' || input.type === 'checkbox') {
                    if (input.checked) {
                        return input.value;
                    }
                    continue;
                }
                return input.value;
            }
            return '';
        }

        function updateDependencies() {
            cards.forEach(function (card) {
                var raw = card.getAttribute('data-settings-depends');
                if (!raw) {
                    return;
                }
                var hidden = false;
                try {
                    var dependency = JSON.parse(raw);
                    hidden = String(fieldValue(dependency.field)) !== String(dependency.value);
                } catch (e) {
                    hidden = false;
                }
                card.setAttribute('data-settings-dependency-hidden', hidden ? '1' : '0');
            });
            applySearch();
        }

        page.addEventListener('change', function (event) {
            var target = event.target;
            if (target && target.name && target.name.indexOf('value_') === 0) {
                updateDependencies();
            }
        });

        if (searchInput) {
            searchInput.addEventListener('input', applySearch);
        }

        updateDependencies();

        var activeTab = page.querySelector('.md-settings-tabs .nav-link.active');
        var tabs = page.querySelector('.md-settings-tabs');
        if (activeTab && tabs && tabs.scrollWidth > tabs.clientWidth) {
            tabs.scrollLeft = Math.max(0, activeTab.offsetLeft - 12);
        }

        page.querySelectorAll('[data-settings-toggle-secret]').forEach(function (button) {
            button.addEventListener('click', function () {
                var targetId = button.getAttribute('data-settings-toggle-secret');
                var input = targetId ? document.getElementById(targetId) : null;
                if (!input) {
                    return;
                }

                var isPassword = input.getAttribute('type') === 'password';
                input.setAttribute('type', isPassword ? 'text' : 'password');
                button.textContent = isPassword ? 'Скрыть' : 'Показать';
            });
        });
    }

    if (window.MDJAdminUI && typeof window.MDJAdminUI.registerModuleUI === 'function') {
        window.MDJAdminUI.registerModuleUI('settings', {
            init: initSettingsUI
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        initSettingsUI(document);
    });
})(window, document);
