(function (window, document) {
    'use strict';

    var admin = window.MDJAdminPanel = window.MDJAdminPanel || {};
    var escapeHtml = admin.escapeHtml || function (value) {
        return String(value || '');
    };

    function initGlobalSearchDrawer(root) {
        var drawer = document.getElementById('mdmGlobalSearchDrawer');
        var input = document.getElementById('filter_modules');
        var results = document.getElementById('filter_add');
        var summary = document.getElementById('filter_summary');
        var hint = document.querySelector('.searchNoty');
        var body = document.body;
        var searchTimer = 0;
        var searchRequest = null;

        if (!drawer || !input || !results) {
            return;
        }

        function setSummary(text) {
            if (summary) {
                summary.textContent = text || '';
            }
        }

        function renderState(className, title, text) {
            results.innerHTML = '<div class="md-admin-search-state ' + className + '"><strong>' + escapeHtml(title) + '</strong><span>' + escapeHtml(text || '') + '</span></div>';
        }

        function renderResults(data) {
            var sections = data.sections || {};
            var total = Number(data.total || 0);

            if (hint) {
                hint.hidden = total > 0;
            }
            setSummary(total ? ('Найдено: ' + total) : '');

            if (!total) {
                renderState('is-empty', data.message || 'Ничего не найдено', 'Попробуйте другое название, объект, свойство, метод или фрагмент кода.');
                return;
            }

            var html = Object.keys(sections).map(function (sectionName) {
                var items = sections[sectionName] || [];
                if (!items.length) {
                    return '';
                }
                var cards = items.map(function (item) {
                    var meta = (item.meta || []).map(function (metaItem) {
                        return '<span>' + escapeHtml(metaItem) + '</span>';
                    }).join('');
                    return '<a class="md-admin-search-result" href="' + escapeHtml(item.url || '#') + '">' +
                        '<span class="md-admin-search-result__type md-admin-search-result__type--' + escapeHtml(item.type || 'item') + '">' + escapeHtml(item.type || 'item') + '</span>' +
                        '<span class="md-admin-search-result__content">' +
                            '<strong>' + escapeHtml(item.title) + '</strong>' +
                            (item.description ? '<small>' + escapeHtml(item.description) + '</small>' : '') +
                            (meta ? '<span class="md-admin-search-result__meta">' + meta + '</span>' : '') +
                        '</span>' +
                    '</a>';
                }).join('');
                return '<section class="md-admin-search-section">' +
                    '<header><h3>' + escapeHtml(sectionName) + '</h3><span>' + items.length + '</span></header>' +
                    '<div class="md-admin-search-section__items">' + cards + '</div>' +
                '</section>';
            }).join('');

            results.innerHTML = html;
        }

        function performSearch() {
            var term = input.value.trim();

            if (searchRequest) {
                searchRequest.abort();
                searchRequest = null;
            }

            if (term.length <= 2) {
                setSummary('');
                if (hint) {
                    hint.hidden = false;
                }
                renderState('is-idle', 'Введите больше 2 символов', 'Поиск смотрит модули, классы, объекты, свойства, методы, скрипты и поддерживаемые устройства.');
                return;
            }

            renderState('is-loading', 'Ищем...', term);
            searchRequest = new AbortController();
            fetch('?ajax_panel=1&op=filter&title=' + encodeURIComponent(term), {
                headers: {
                    Accept: 'application/json'
                },
                signal: searchRequest.signal
            })
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error('Search request failed: ' + response.status);
                    }
                    return response.json();
                })
                .then(renderResults)
                .catch(function (error) {
                    if (error.name === 'AbortError') {
                        return;
                    }
                    setSummary('');
                    renderState('is-error', 'Ошибка поиска', error.message || 'Не удалось получить результаты.');
                });
        }

        function scheduleSearch() {
            window.clearTimeout(searchTimer);
            searchTimer = window.setTimeout(performSearch, 220);
        }

        function openSearch(value) {
            if (window.MDJAdminDrawerHost && typeof window.MDJAdminDrawerHost.close === 'function') {
                window.MDJAdminDrawerHost.close();
            }
            body.classList.add('md-admin-search-open');
            drawer.setAttribute('aria-hidden', 'false');
            if (typeof value === 'string') {
                input.value = value;
            }
            window.setTimeout(function () {
                input.focus();
                input.select();
            }, 60);
            performSearch();
        }

        function closeSearch() {
            body.classList.remove('md-admin-search-open');
            drawer.setAttribute('aria-hidden', 'true');
        }

        window.MDJAdminSearch = {
            open: openSearch,
            close: closeSearch,
            search: performSearch,
            setFilter: function (value) {
                openSearch(value || '');
                return false;
            }
        };

        root.querySelectorAll('[data-md-search-open]').forEach(function (button) {
            if (button.dataset.mdSearchOpenBound === '1') {
                return;
            }
            button.dataset.mdSearchOpenBound = '1';
            button.addEventListener('click', function () {
                openSearch();
            });
        });

        root.querySelectorAll('[data-md-search-close]').forEach(function (button) {
            if (button.dataset.mdSearchCloseBound === '1') {
                return;
            }
            button.dataset.mdSearchCloseBound = '1';
            button.addEventListener('click', closeSearch);
        });

        root.querySelectorAll('[data-md-search-clear]').forEach(function (button) {
            if (button.dataset.mdSearchClearBound === '1') {
                return;
            }
            button.dataset.mdSearchClearBound = '1';
            button.addEventListener('click', function () {
                input.value = '';
                input.focus();
                performSearch();
            });
        });

        if (input.dataset.mdSearchInputBound !== '1') {
            input.dataset.mdSearchInputBound = '1';
            input.addEventListener('input', scheduleSearch);
        }

        if (document.body.dataset.mdSearchEscapeBound !== '1') {
            document.body.dataset.mdSearchEscapeBound = '1';
            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && body.classList.contains('md-admin-search-open')) {
                    closeSearch();
                }
            });
        }

        if (results.dataset.mdSearchResultBound !== '1') {
            results.dataset.mdSearchResultBound = '1';
            results.addEventListener('click', function (event) {
                var link = event.target.closest('a');
                if (link) {
                    closeSearch();
                }
            });
        }

        renderState('is-idle', 'Введите больше 2 символов', 'Поиск смотрит модули, классы, объекты, свойства, методы, скрипты и поддерживаемые устройства.');
    }

    admin.addBootTask('global-search-drawer', initGlobalSearchDrawer);
})(window, document);
