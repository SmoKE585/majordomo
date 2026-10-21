(function (window, document) {
    'use strict';

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

    function initList(list) {
        if (list.dataset.mdObjectsReady === '1') {
            return;
        }
        list.dataset.mdObjectsReady = '1';

        var rows = Array.prototype.slice.call(list.querySelectorAll('[data-md-object-row]'));
        var search = list.querySelector('[data-md-objects-search]');
        var classFilter = list.querySelector('[data-md-objects-class-filter]');
        var count = list.querySelector('[data-md-objects-count]');
        var shown = list.querySelector('[data-md-objects-shown]');
        var empty = list.querySelector('[data-md-objects-empty]');
        var total = Number(list.getAttribute('data-md-objects-total'));
        if (!Number.isFinite(total) || total <= 0) {
            total = rows.length;
        }

        if (classFilter && rows.length) {
            var classes = {};
            rows.forEach(function (row) {
                var classId = row.getAttribute('data-class-id');
                var classLink = row.querySelector('.md-obj-class');
                if (!classId || !classLink) {
                    return;
                }
                var title = (classLink.textContent || '').trim();
                if (title) {
                    classes[classId] = title;
                }
            });

            Object.keys(classes).sort(function (left, right) {
                return classes[left].localeCompare(classes[right], 'ru');
            }).forEach(function (classId) {
                var option = document.createElement('option');
                option.value = classId;
                option.textContent = classes[classId];
                classFilter.appendChild(option);
            });
        }

        function applyFilter() {
            var query = search ? String(search.value || '').trim().toLowerCase() : '';
            var classId = classFilter ? String(classFilter.value || '') : '';
            var visible = 0;

            rows.forEach(function (row) {
                var matchesText = !query || String(row.textContent || '').toLowerCase().indexOf(query) !== -1;
                var matchesClass = !classId || row.getAttribute('data-class-id') === classId;
                var matches = matchesText && matchesClass;
                row.hidden = !matches;
                if (matches) {
                    visible++;
                }
            });

            if (empty) {
                empty.hidden = visible > 0;
            }
            if (count) {
                count.textContent = visible === total
                    ? total + ' ' + plural(total, 'объект', 'объекта', 'объектов')
                    : visible + ' из ' + total;
            }
            if (shown) {
                shown.textContent = 'Показано ' + visible + ' из ' + total;
            }
        }

        if (search) {
            search.addEventListener('input', applyFilter);
        }
        if (classFilter) {
            classFilter.addEventListener('change', applyFilter);
        }

        applyFilter();
    }

    if (window.MDJAdminUI && typeof window.MDJAdminUI.registerModuleUI === 'function') {
        window.MDJAdminUI.registerModuleUI('objects-list', {
            init: function (root) {
                (root || document).querySelectorAll('[data-md-objects-list]').forEach(initList);
            }
        });
    }
})(window, document);
