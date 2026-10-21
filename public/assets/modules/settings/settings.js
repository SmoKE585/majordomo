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
                card.hidden = !isVisible;
                if (isVisible) {
                    visibleCount += 1;
                }
            });

            if (emptyState) {
                emptyState.classList.toggle('is-visible', visibleCount === 0);
            }
        }

        if (searchInput) {
            searchInput.addEventListener('input', applySearch);
            applySearch();
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

        page.querySelectorAll('[data-settings-browse]').forEach(function (button) {
            button.addEventListener('click', function () {
                var targetId = button.getAttribute('data-settings-browse');
                if (targetId && typeof window.openFileBrowser === 'function') {
                    window.openFileBrowser(targetId);
                }
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
