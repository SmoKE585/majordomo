/*
 * Modules manager: client-side search and filters for the module card grid.
 *
 * The grid is server-rendered, so the page keeps working without JavaScript.
 * This module only hides cards and updates the counter/empty state.
 */
(function (window, document) {
    'use strict';

    function normalize(value) {
        return String(value || '').toLowerCase().replace(/\s+/g, ' ').trim();
    }

    function initIcons(scope) {
        scope.querySelectorAll('img[data-md-module-icon]').forEach(function (image) {
            if (image.dataset.mdModuleIconReady === '1') {
                return;
            }
            image.dataset.mdModuleIconReady = '1';

            function markLoaded() {
                image.classList.add('is-loaded');
            }

            if (image.complete && image.naturalWidth > 0) {
                markLoaded();
            } else {
                image.addEventListener('load', markLoaded, { once: true });
            }
        });
    }

    function init(root) {
        var scope = root && root.querySelectorAll ? root : document;
        initIcons(scope);

        scope.querySelectorAll('[data-md-module-grid]').forEach(function (grid) {
            if (grid.dataset.mdModulesReady === '1') {
                return;
            }
            grid.dataset.mdModulesReady = '1';

            var page = grid.closest('.md-modules-page') || scope;
            var search = page.querySelector('[data-md-module-search]');
            var category = page.querySelector('[data-md-module-category]');
            var state = page.querySelector('[data-md-module-state]');
            var count = page.querySelector('[data-md-module-count]');
            var empty = page.querySelector('[data-md-module-empty]');
            var items = Array.prototype.slice.call(grid.querySelectorAll('[data-md-module-item]'));
            var timer = 0;

            function apply() {
                var query = search ? normalize(search.value) : '';
                var categoryValue = category ? category.value : '';
                var stateValue = state ? state.value : '';
                var visible = 0;

                items.forEach(function (item) {
                    var haystack = normalize(item.getAttribute('data-search') || item.textContent);
                    var matches = true;
                    if (query && haystack.indexOf(query) === -1) {
                        matches = false;
                    }
                    if (matches && categoryValue && item.getAttribute('data-category') !== categoryValue) {
                        matches = false;
                    }
                    if (matches && stateValue && item.getAttribute('data-state') !== stateValue) {
                        matches = false;
                    }
                    item.hidden = !matches;
                    if (matches) {
                        visible += 1;
                    }
                });

                if (count) {
                    count.textContent = visible === items.length
                        ? 'Модулей: ' + items.length
                        : 'Найдено: ' + visible + ' из ' + items.length;
                }
                if (empty) {
                    empty.hidden = visible !== 0;
                }
            }

            if (search) {
                search.addEventListener('input', function () {
                    window.clearTimeout(timer);
                    timer = window.setTimeout(apply, 100);
                });
            }
            if (category) {
                category.addEventListener('change', apply);
            }
            if (state) {
                state.addEventListener('change', apply);
            }

            apply();
        });
    }

    if (window.MDJAdminUI && typeof window.MDJAdminUI.registerModuleUI === 'function') {
        window.MDJAdminUI.registerModuleUI('control-modules', { init: init });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            init(document);
        });
    } else {
        init(document);
    }
})(window, document);
