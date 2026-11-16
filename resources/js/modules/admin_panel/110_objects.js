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

    function createMethodRunPage() {
        var page = window.open('', '_blank');
        if (!page) return null;
        page.document.open();
        page.document.write('<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Результат запуска метода</title><style>body{margin:0;padding:24px;background:#f4f6f8;color:#202833;font:15px/1.5 system-ui,sans-serif}main{max-width:960px;margin:0 auto}.card{padding:20px;margin:16px 0;background:#fff;border:1px solid #dce2e8;border-radius:12px}h1{font-size:24px;margin:0 0 6px}h2{font-size:16px;margin:0 0 10px}pre{white-space:pre-wrap;overflow-wrap:anywhere;margin:0;font:13px/1.6 ui-monospace,monospace}.error{color:#b42318}.muted{color:#667085}</style></head><body><main><h1 id="title">Результат запуска метода</h1><p id="status" class="muted">Метод выполняется…</p><section class="card"><h2>Вывод echo</h2><pre id="output">Ожидание…</pre></section><section class="card"><h2>Возвращённое значение</h2><pre id="value">Ожидание…</pre></section><section class="card" id="error-card" hidden><h2 class="error">Исключение</h2><pre id="error" class="error"></pre></section></main></body></html>');
        page.document.close();
        return page;
    }

    document.addEventListener('click', function (event) {
        var link = event.target.closest('[data-md-run-object-method]');
        if (!link || !window.MDJAdminApi) return;
        event.preventDefault();
        var page = createMethodRunPage();
        if (!page) {
            window.alert('Браузер заблокировал новую вкладку. Разрешите всплывающие окна для панели и повторите запуск.');
            return;
        }
        window.MDJAdminApi.runObjectMethod(link.dataset.objectId, link.dataset.methodId).then(function (data) {
            page.document.title = data.object + '.' + data.method + ' — результат';
            page.document.getElementById('title').textContent = data.object + '.' + data.method;
            page.document.getElementById('status').textContent = (data.ok ? 'Метод выполнен' : 'Метод завершился с ошибкой') + ' · ' + data.duration_ms + ' мс';
            page.document.getElementById('status').className = data.ok ? 'muted' : 'error';
            page.document.getElementById('output').textContent = data.output || '(пусто)';
            page.document.getElementById('value').textContent = data.result;
            if (data.error) {
                page.document.getElementById('error-card').hidden = false;
                page.document.getElementById('error').textContent = data.error.class + ': ' + data.error.message;
            }
        }).catch(function (error) {
            page.document.getElementById('status').textContent = 'Запрос не выполнен';
            page.document.getElementById('status').className = 'error';
            page.document.getElementById('output').textContent = error.message || 'Неизвестная ошибка';
        });
    });

    if (window.MDJAdminUI && typeof window.MDJAdminUI.registerModuleUI === 'function') {
        window.MDJAdminUI.registerModuleUI('objects-list', {
            init: function (root) {
                (root || document).querySelectorAll('[data-md-objects-list]').forEach(initList);
            }
        });
    }
})(window, document);
