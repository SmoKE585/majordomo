/*
 * Classes tree.
 *
 * Progressive enhancement for the server-rendered classes table:
 *  - expand / collapse class subtrees at any depth and their object panels;
 *  - filter by title, description and object names;
 *  - filter classes with or without objects in the whole subtree.
 */
(function (window, document) {
    'use strict';

    function normalize(value) {
        return String(value || '').toLowerCase().replace(/\s+/g, ' ').trim();
    }

    function plural(value, one, few, many) {
        var abs = Math.abs(value) % 100;
        var last = abs % 10;
        if (abs > 10 && abs < 20) {
            return many;
        }
        if (last > 1 && last < 5) {
            return few;
        }
        if (last === 1) {
            return one;
        }
        return many;
    }

    function initPage(page) {
        if (page.dataset.mdClassesReady === '1') {
            return;
        }
        page.dataset.mdClassesReady = '1';

        var rows = Array.prototype.slice.call(page.querySelectorAll('[data-md-class-row]'));
        if (rows.length === 0) {
            return;
        }

        var search = page.querySelector('[data-md-classes-search]');
        var filter = page.querySelector('[data-md-classes-filter]');
        var count = page.querySelector('[data-md-classes-count]');
        var empty = page.querySelector('[data-md-classes-empty]');
        var scroll = page.querySelector('.md-table__scroll');
        var reset = page.querySelector('[data-md-classes-reset]');
        var expandAll = page.querySelector('[data-md-classes-expand-all]');
        var collapseAll = page.querySelector('[data-md-classes-collapse-all]');

        var parentOf = {};
        var detailByClass = {};
        var toggles = {};
        var expanded = new Set();

        page.querySelectorAll('[data-md-class-detail]').forEach(function (detail) {
            detailByClass[detail.getAttribute('data-detail-for')] = detail;
        });

        rows.forEach(function (row) {
            var id = row.getAttribute('data-class-id');
            parentOf[id] = row.getAttribute('data-parent-id') || '0';
            var toggle = row.querySelector('[data-md-class-toggle]');
            if (toggle) {
                toggles[id] = toggle;
            }
        });

        var total = Number(page.getAttribute('data-md-classes-total') || rows.length);
        if (!Number.isFinite(total) || total <= 0) {
            total = rows.length;
        }

        function rowSearchText(row) {
            var detail = detailByClass[row.getAttribute('data-class-id')];
            return normalize((row.getAttribute('data-search') || '') + ' ' + (detail ? detail.textContent : ''));
        }

        function ancestorsVisible(id) {
            var parent = parentOf[id];
            while (parent && parent !== '0') {
                if (!expanded.has(parent)) {
                    return false;
                }
                parent = parentOf[parent];
            }
            return true;
        }

        function addAncestors(id, visible) {
            var parent = parentOf[id];
            while (parent && parent !== '0') {
                visible.add(parent);
                parent = parentOf[parent];
            }
        }

        function expandAncestors(id) {
            var parent = parentOf[id];
            while (parent && parent !== '0') {
                expanded.add(parent);
                parent = parentOf[parent];
            }
        }

        function apply() {
            var query = search ? normalize(search.value) : '';
            var filterValue = filter ? String(filter.value || '') : '';
            var active = query !== '' || filterValue !== '';
            var visible = new Set();
            var matched = new Set();

            if (active) {
                rows.forEach(function (row) {
                    var id = row.getAttribute('data-class-id');
                    var objects = Number(row.getAttribute('data-objects') || '0');
                    var matchesQuery = !query || rowSearchText(row).indexOf(query) !== -1;
                    var matchesFilter = filterValue === 'with'
                        ? objects > 0
                        : filterValue === 'empty'
                            ? objects === 0
                            : true;
                    if (matchesQuery && matchesFilter) {
                        matched.add(id);
                        visible.add(id);
                        addAncestors(id, visible);
                    }
                });
            }

            rows.forEach(function (row) {
                var id = row.getAttribute('data-class-id');
                row.hidden = active ? !visible.has(id) : !ancestorsVisible(id);
                var toggle = toggles[id];
                var isOpen = expanded.has(id);
                row.classList.toggle('is-expanded', isOpen);
                if (toggle) {
                    toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                    toggle.setAttribute('aria-label', (isOpen ? 'Скрыть' : 'Показать') + ' содержимое класса');
                }
            });

            Object.keys(detailByClass).forEach(function (id) {
                var detail = detailByClass[id];
                if (active) {
                    detail.hidden = !matched.has(id);
                } else {
                    detail.hidden = !(expanded.has(id) && ancestorsVisible(id));
                }
            });

            var shown = active ? matched.size : rows.length;
            if (count) {
                count.textContent = active
                    ? shown + ' из ' + total + ' ' + plural(total, 'класса', 'классов', 'классов')
                    : total + ' ' + plural(total, 'класс', 'класса', 'классов');
            }
            if (empty) {
                empty.hidden = shown > 0;
            }
            if (scroll) {
                scroll.hidden = shown === 0;
            }
        }

        function setExpanded(id, open) {
            if (open) {
                expandAncestors(id);
                expanded.add(id);
                return;
            }
            expanded.delete(id);
            Object.keys(parentOf).forEach(function (childId) {
                if (!ancestorsVisible(childId)) {
                    expanded.delete(childId);
                }
            });
        }

        function clearFilters() {
            if (search) {
                search.value = '';
            }
            if (filter) {
                filter.value = '';
            }
        }

        Object.keys(toggles).forEach(function (id) {
            toggles[id].addEventListener('click', function () {
                setExpanded(id, !expanded.has(id));
                apply();
            });
        });

        if (search) {
            search.addEventListener('input', apply);
        }
        if (filter) {
            filter.addEventListener('change', apply);
        }
        if (reset) {
            reset.addEventListener('click', function () {
                clearFilters();
                expanded.clear();
                apply();
            });
        }
        if (expandAll) {
            expandAll.addEventListener('click', function () {
                Object.keys(toggles).forEach(function (id) {
                    expanded.add(id);
                });
                apply();
            });
        }
        if (collapseAll) {
            collapseAll.addEventListener('click', function () {
                expanded.clear();
                apply();
            });
        }

        apply();
    }

    function init(root) {
        (root || document).querySelectorAll('[data-md-classes-page]').forEach(initPage);
    }

    if (window.MDJAdminUI && typeof window.MDJAdminUI.registerModuleUI === 'function') {
        window.MDJAdminUI.registerModuleUI('classes-tree', { init: init });
    }

    // The script is loaded with the page content during partLoad, which may
    // happen after the shell boot. Initialise right away in that case.
    if (document.querySelector('[data-md-classes-page]')) {
        init(document);
    }
})(window, document);
