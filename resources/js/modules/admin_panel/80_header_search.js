(function (window, document) {
    'use strict';

    var typeLabels = {
        module: 'Модуль',
        class: 'Класс',
        object: 'Объект',
        property: 'Свойство',
        method: 'Метод',
        script: 'Скрипт',
        zwave: 'Z-Wave',
        gps: 'GPS',
        item: 'Элемент'
    };

    var state = {
        wrapper: null,
        input: null,
        results: null,
        timer: 0,
        controller: null,
        term: '',
        close: function () {}
    };

    function escapeHtml(value) {
        return String(value === null || value === undefined ? '' : value).replace(/[&<>"']/g, function (char) {
            return {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            }[char];
        });
    }

    function init(root) {
        var wrapper = (root || document).querySelector('[data-md-header-search]');
        var input = document.getElementById('mdHeaderSearchInput');
        var results = document.getElementById('mdHeaderSearchResults');

        if (!wrapper || !input || !results || wrapper.dataset.mdHeaderSearchReady === '1') {
            return;
        }
        wrapper.dataset.mdHeaderSearchReady = '1';

        state.wrapper = wrapper;
        state.input = input;
        state.results = results;
        state.term = '';

        function setOpen(open) {
            results.hidden = !open;
            input.setAttribute('aria-expanded', open ? 'true' : 'false');
            wrapper.classList.toggle('is-open', open);
        }

        function close() {
            setOpen(false);
        }

        state.close = close;

        function renderState(title, text) {
            results.innerHTML = '<div class="md-header-search__state"><strong>' +
                escapeHtml(title) + '</strong><span>' + escapeHtml(text || '') + '</span></div>';
        }

        function renderResults(data, term) {
            var sections = data && data.sections ? data.sections : {};
            var total = Number(data && data.total ? data.total : 0);

            if (!total) {
                renderState('Ничего не найдено', data && data.message ? data.message : 'Попробуйте другое название.');
                return;
            }

            var html = '';
            Object.keys(sections).forEach(function (sectionName) {
                var items = sections[sectionName] || [];
                if (!items.length) {
                    return;
                }
                html += '<section class="md-header-search__section">' +
                    '<div class="md-header-search__section-title"><span>' + escapeHtml(sectionName) + '</span><span>' + items.length + '</span></div>';
                items.slice(0, 4).forEach(function (item) {
                    var type = String(item.type || 'item');
                    var meta = (item.meta || []).map(function (metaItem) {
                        return '<span>' + escapeHtml(metaItem) + '</span>';
                    }).join('');
                    html += '<a class="md-header-search__item" role="option" href="' + escapeHtml(item.url || '#') + '">' +
                        '<span class="md-header-search__type">' + escapeHtml(typeLabels[type] || type) + '</span>' +
                        '<span class="md-header-search__body">' +
                            '<strong>' + escapeHtml(item.title) + '</strong>' +
                            (item.description ? '<small>' + escapeHtml(item.description) + '</small>' : '') +
                            (meta ? '<span class="md-header-search__meta">' + meta + '</span>' : '') +
                        '</span>' +
                    '</a>';
                });
                html += '</section>';
            });

            html += '<a class="md-header-search__all" href="#" data-md-header-search-all>Все результаты' +
                (term ? ' по запросу «' + escapeHtml(term) + '»' : '') + '</a>';
            results.innerHTML = html;
        }

        function search(term) {
            state.term = term;

            if (state.controller) {
                state.controller.abort();
                state.controller = null;
            }

            if (term.length < 3) {
                setOpen(false);
                return;
            }

            setOpen(true);
            renderState('Ищем...', term);
            state.controller = new AbortController();

            window.MDJAdminApi.search(term, {signal: state.controller.signal})
                .then(function (data) {
                    if (state.term === term && state.results === results) {
                        renderResults(data, term);
                    }
                })
                .catch(function (error) {
                    if (error.name === 'AbortError') {
                        return;
                    }
                    renderState('Ошибка поиска', error.message || 'Не удалось получить результаты.');
                });
        }

        function scheduleSearch() {
            window.clearTimeout(state.timer);
            var term = input.value.trim();
            if (!term) {
                setOpen(false);
                return;
            }
            state.timer = window.setTimeout(function () {
                search(term);
            }, 180);
        }

        input.addEventListener('input', scheduleSearch);

        input.addEventListener('focus', function () {
            if (input.value.trim().length >= 3) {
                search(input.value.trim());
            }
        });

        input.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                setOpen(false);
                input.blur();
                return;
            }
            if (event.key === 'Enter') {
                var first = results.querySelector('.md-header-search__item');
                if (first) {
                    event.preventDefault();
                    first.click();
                }
            }
        });

        results.addEventListener('click', function (event) {
            var all = event.target.closest('[data-md-header-search-all]');
            if (all) {
                event.preventDefault();
                setOpen(false);
                if (window.MDJAdminSearch && typeof window.MDJAdminSearch.open === 'function') {
                    window.MDJAdminSearch.open(state.term);
                }
                return;
            }
            if (event.target.closest('a')) {
                setOpen(false);
            }
        });
    }

    function isActive() {
        return state.wrapper && document.body.contains(state.wrapper);
    }

    function bindGlobal() {
        if (!document.body || document.body.dataset.mdHeaderSearchGlobal === '1') {
            return;
        }
        document.body.dataset.mdHeaderSearchGlobal = '1';

        document.addEventListener('click', function (event) {
            if (isActive() && !state.wrapper.contains(event.target)) {
                state.close();
            }
        });

        document.addEventListener('keydown', function (event) {
            var key = String(event.key || '').toLowerCase();
            if (!(event.ctrlKey || event.metaKey) || key !== 'k') {
                return;
            }
            event.preventDefault();
            if (!isActive()) {
                return;
            }
            state.input.focus();
            state.input.select();
            if (state.input.value.trim().length >= 3) {
                state.input.dispatchEvent(new Event('input', { bubbles: true }));
            }
        });
    }

    if (window.MDJAdminUI && typeof window.MDJAdminUI.registerModuleUI === 'function') {
        window.MDJAdminUI.registerModuleUI('header-search', { init: init });
    }

    function boot() {
        init(document);
        bindGlobal();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})(window, document);
