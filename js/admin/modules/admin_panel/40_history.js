(function (window, document) {
    'use strict';

    var admin = window.MDJAdminPanel = window.MDJAdminPanel || {};
    var escapeHtml = admin.escapeHtml || function (value) {
        return String(value || '');
    };

    function initObjectPropertyHistoryDrawer(root) {
        var drawer = document.getElementById('mdObjectHistoryDrawer');
        var panel = document.getElementById('mdObjectHistoryPanel');
        var state = document.getElementById('mdObjectHistoryState');
        var title = document.getElementById('mdObjectHistoryTitle');
        var subtitle = document.getElementById('mdObjectHistorySubtitle');
        var rangeLabel = document.getElementById('mdObjectHistoryRangeLabel');
        var currentValue = document.getElementById('mdObjectHistoryCurrentValue');
        var currentMeta = document.getElementById('mdObjectHistoryCurrentMeta');
        var stats = document.getElementById('mdObjectHistoryStats');
        var chart = document.getElementById('mdObjectHistoryChart');
        var chartSummary = document.getElementById('mdObjectHistoryChartSummary');
        var feed = document.getElementById('mdObjectHistoryFeed');
        var feedCount = document.getElementById('mdObjectHistoryFeedCount');
        var pagination = document.getElementById('mdObjectHistoryPagination');
        var paginationPages = document.getElementById('mdObjectHistoryPaginationPages');
        var tabs = document.getElementById('mdObjectHistoryTabs');
        var body = document.body;
        var activeTrigger = null;
        var activeRange = '3h';
        var activePage = 1;
        var activeRequest = null;
        var activeTab = 'history';

        if (!drawer || !panel || !state) {
            return;
        }

        function setState(mode, heading, text) {
            state.className = 'md-object-history-state is-' + mode;
            state.innerHTML = '<strong>' + escapeHtml(heading || '') + '</strong>' +
                (text ? '<span>' + escapeHtml(text) + '</span>' : '');
            state.hidden = false;
            panel.hidden = true;
        }

        function formatValue(value) {
            if (value === null || typeof value === 'undefined' || value === '') {
                return '—';
            }
            if (typeof value === 'number') {
                return Number.isInteger(value) ? String(value) : value.toFixed(2).replace(/\.00$/, '');
            }
            return String(value);
        }

        function setRangeButtons(range) {
            drawer.querySelectorAll('[data-md-history-range]').forEach(function (button) {
                var isActive = button.getAttribute('data-md-history-range') === range;
                button.classList.toggle('is-active', isActive);
                button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
            });
        }

        function setActiveTab(tabName) {
            activeTab = tabName || 'history';
            drawer.querySelectorAll('[data-md-history-tab]').forEach(function (button) {
                var isActive = button.getAttribute('data-md-history-tab') === activeTab;
                button.classList.toggle('is-active', isActive);
                button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
            });
            drawer.querySelectorAll('[data-md-history-panel]').forEach(function (panelNode) {
                panelNode.hidden = panelNode.getAttribute('data-md-history-panel') !== activeTab;
            });
        }

        function toggleChartTab(isVisible) {
            var chartButton = drawer.querySelector('[data-md-history-tab="chart"]');
            if (tabs) {
                tabs.hidden = !isVisible;
            }
            if (chartButton) {
                chartButton.hidden = !isVisible;
            }
            if (!isVisible) {
                setActiveTab('history');
            }
        }

        function openDrawer(trigger) {
            activeTrigger = trigger;
            activePage = 1;
            body.classList.add('md-object-history-open');
            drawer.setAttribute('aria-hidden', 'false');
            title.textContent = trigger.getAttribute('data-property-name') || 'История свойства';
            subtitle.textContent = trigger.getAttribute('data-property-description') || '';
            setRangeButtons(activeRange);
            setActiveTab('history');
            loadHistory();
        }

        function closeDrawer() {
            body.classList.remove('md-object-history-open');
            drawer.setAttribute('aria-hidden', 'true');
            if (activeRequest) {
                activeRequest.abort();
                activeRequest = null;
            }
            if (activeTrigger) {
                activeTrigger.focus();
            }
        }

        function buildPaginationPages(currentPage, totalPages) {
            var pages = [];
            var startPage = Math.max(1, currentPage - 2);
            var endPage = Math.min(totalPages, currentPage + 2);

            if (startPage > 1) {
                pages.push(1);
            }
            if (startPage > 2) {
                pages.push('dots-left');
            }
            for (var page = startPage; page <= endPage; page++) {
                pages.push(page);
            }
            if (endPage < totalPages - 1) {
                pages.push('dots-right');
            }
            if (endPage < totalPages) {
                pages.push(totalPages);
            }

            return pages;
        }

        function renderPagination(meta) {
            if (!pagination || !paginationPages) {
                return;
            }

            var totalPages = Number(meta && meta.pages ? meta.pages : 0);
            var currentPage = Number(meta && meta.page ? meta.page : 1);
            activePage = currentPage;

            if (totalPages <= 1) {
                pagination.hidden = true;
                paginationPages.innerHTML = '';
                return;
            }

            pagination.hidden = false;
            paginationPages.innerHTML = buildPaginationPages(currentPage, totalPages).map(function (item) {
                if (typeof item !== 'number') {
                    return '<span class="md-object-history-pagination__dots">...</span>';
                }
                var activeClass = item === currentPage ? ' is-active' : '';
                return '<button type="button" class="md-object-history-pagination__page' + activeClass + '" data-md-history-page="' + item + '">' + item + '</button>';
            }).join('');

            pagination.querySelectorAll('[data-md-history-page-nav]').forEach(function (button) {
                var direction = button.getAttribute('data-md-history-page-nav');
                if (direction === 'prev') {
                    button.disabled = !meta.has_prev;
                } else if (direction === 'next') {
                    button.disabled = !meta.has_next;
                }
            });
        }

        function renderStats(data) {
            var items = [
                {label: 'Изменений', value: data.changes},
                {label: 'Минимум', value: formatValue(data.min)},
                {label: 'Среднее', value: formatValue(data.avg)},
                {label: 'Максимум', value: formatValue(data.max)}
            ];

            stats.innerHTML = items.map(function (item) {
                return '<article class="md-object-history-stat">' +
                    '<span>' + escapeHtml(item.label) + '</span>' +
                    '<strong>' + escapeHtml(formatValue(item.value)) + '</strong>' +
                '</article>';
            }).join('');
        }

        function buildChartSvg(points, minValue, maxValue) {
            var width = 820;
            var height = 280;
            var paddingX = 18;
            var paddingY = 20;
            var usableWidth = width - (paddingX * 2);
            var usableHeight = height - (paddingY * 2);
            var range = maxValue - minValue || 1;
            var path = '';
            var dots = '';

            points.forEach(function (point, index) {
                var x = paddingX + ((usableWidth / Math.max(points.length - 1, 1)) * index);
                var ratio = (point.value - minValue) / range;
                var y = height - paddingY - (ratio * usableHeight);
                path += (index ? ' L ' : 'M ') + x.toFixed(2) + ' ' + y.toFixed(2);
                dots += '<circle cx="' + x.toFixed(2) + '" cy="' + y.toFixed(2) + '" r="3.5">' +
                    '<title>' + escapeHtml(point.label + ' - ' + formatValue(point.value)) + '</title>' +
                '</circle>';
            });

            return '' +
                '<svg viewBox="0 0 ' + width + ' ' + height + '" role="img" aria-label="График изменения свойства">' +
                    '<defs>' +
                        '<linearGradient id="mdObjectHistoryLine" x1="0%" y1="0%" x2="100%" y2="0%">' +
                            '<stop offset="0%" stop-color="#4792d1"></stop>' +
                            '<stop offset="100%" stop-color="#0f8a64"></stop>' +
                        '</linearGradient>' +
                    '</defs>' +
                    '<line x1="' + paddingX + '" y1="' + paddingY + '" x2="' + paddingX + '" y2="' + (height - paddingY) + '" class="md-object-history-chart__axis"></line>' +
                    '<line x1="' + paddingX + '" y1="' + (height - paddingY) + '" x2="' + (width - paddingX) + '" y2="' + (height - paddingY) + '" class="md-object-history-chart__axis"></line>' +
                    '<path d="' + path + '" class="md-object-history-chart__line"></path>' +
                    '<g class="md-object-history-chart__dots">' + dots + '</g>' +
                '</svg>';
        }

        function renderChart(chartData) {
            if (!chartData || !chartData.has_data || !chartData.points || chartData.points.length < 2) {
                chart.innerHTML = '<div class="md-object-history-chart__empty">Недостаточно числовых данных для построения графика.</div>';
                chartSummary.textContent = 'График недоступен';
                return;
            }

            chart.innerHTML = buildChartSvg(chartData.points, Number(chartData.min || 0), Number(chartData.max || 0));
            chartSummary.textContent = [
                chartData.first_label || '',
                chartData.last_label || ''
            ].filter(Boolean).join(' - ');
        }

        function renderFeed(items, paginationMeta) {
            var total = Number(paginationMeta && paginationMeta.total ? paginationMeta.total : 0);
            var from = Number(paginationMeta && paginationMeta.from ? paginationMeta.from : 0);
            var to = Number(paginationMeta && paginationMeta.to ? paginationMeta.to : 0);
            feedCount.textContent = total ? ('Показаны ' + from + '-' + to + ' из ' + total) : '';
            if (!items.length) {
                feed.innerHTML = '<tr><td colspan="3" class="md-object-history-table__empty">История за выбранный период пока пуста.</td></tr>';
                if (pagination) {
                    pagination.hidden = true;
                }
                return;
            }

            feed.innerHTML = items.map(function (item) {
                return '<tr>' +
                    '<td class="md-object-history-table__time">' + escapeHtml(item.added_label || '') + '</td>' +
                    '<td class="md-object-history-table__value">' + escapeHtml(formatValue(item.value)) + '</td>' +
                    '<td class="md-object-history-table__source">' + escapeHtml(item.source || '—') + '</td>' +
                '</tr>';
            }).join('');
        }

        function renderHistory(data) {
            title.textContent = (data.meta && data.meta.property_name) || (activeTrigger ? activeTrigger.getAttribute('data-property-name') : 'История свойства');
            subtitle.textContent = (data.meta && data.meta.description) || (activeTrigger ? activeTrigger.getAttribute('data-property-description') : '');
            rangeLabel.textContent = data.range && data.range.label ? data.range.label : '';
            currentValue.textContent = formatValue(data.current ? data.current.value : '');

            var metaParts = [];
            if (data.current && data.current.updated_label) {
                metaParts.push('Обновлено: ' + data.current.updated_label);
            }
            if (data.current && data.current.source) {
                metaParts.push('Источник: ' + data.current.source);
            }
            currentMeta.textContent = metaParts.join(' • ');

            renderStats(data.stats || {});
            renderFeed(data.history || [], data.history_pagination || {});
            renderPagination(data.history_pagination || {});
            toggleChartTab(!!(data.meta && data.meta.is_numeric));
            if (data.meta && data.meta.is_numeric) {
                renderChart(data.chart || {});
            } else {
                chart.innerHTML = '';
                chartSummary.textContent = '';
            }

            state.hidden = true;
            panel.hidden = false;
            setActiveTab('history');
        }

        function loadHistory() {
            if (!activeTrigger) {
                return;
            }

            var baseUrl = activeTrigger.getAttribute('data-history-url');
            if (!baseUrl) {
                setState('error', 'Ошибка', 'Не найден URL для загрузки истории.');
                return;
            }

            if (activeRequest) {
                activeRequest.abort();
            }

            setState('loading', 'Загружаю историю', 'Получаю точки графика и последние изменения.');
            activeRequest = new AbortController();
            fetch(baseUrl + '&history_range=' + encodeURIComponent(activeRange) + '&history_page=' + encodeURIComponent(activePage), {
                headers: {
                    Accept: 'application/json'
                },
                signal: activeRequest.signal
            })
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error('History request failed: ' + response.status);
                    }
                    return response.json();
                })
                .then(function (data) {
                    if (!data || data.status !== 'ok') {
                        throw new Error(data && data.message ? data.message : 'Не удалось загрузить историю.');
                    }
                    activeRequest = null;
                    renderHistory(data);
                })
                .catch(function (error) {
                    if (error.name === 'AbortError') {
                        return;
                    }
                    activeRequest = null;
                    setState('error', 'История недоступна', error.message || 'Не удалось получить данные.');
                });
        }

        root.querySelectorAll('[data-md-prop-history-open]').forEach(function (button) {
            if (button.dataset.mdPropHistoryBound === '1') {
                return;
            }
            button.dataset.mdPropHistoryBound = '1';
            button.addEventListener('click', function () {
                openDrawer(button);
            });
        });

        root.querySelectorAll('[data-md-object-history-close]').forEach(function (button) {
            if (button.dataset.mdObjectHistoryCloseBound === '1') {
                return;
            }
            button.dataset.mdObjectHistoryCloseBound = '1';
            button.addEventListener('click', closeDrawer);
        });

        drawer.querySelectorAll('[data-md-history-range]').forEach(function (button) {
            if (button.dataset.mdHistoryRangeBound === '1') {
                return;
            }
            button.dataset.mdHistoryRangeBound = '1';
            button.addEventListener('click', function () {
                var nextRange = button.getAttribute('data-md-history-range') || '3h';
                if (nextRange === activeRange) {
                    return;
                }
                activeRange = nextRange;
                activePage = 1;
                setRangeButtons(activeRange);
                loadHistory();
            });
        });

        if (drawer.dataset.mdHistoryPaginationBound !== '1') {
            drawer.dataset.mdHistoryPaginationBound = '1';
            drawer.addEventListener('click', function (event) {
                var pageButton = event.target.closest('[data-md-history-page]');
                if (pageButton) {
                    var nextPage = parseInt(pageButton.getAttribute('data-md-history-page'), 10);
                    if (!isNaN(nextPage) && nextPage > 0 && nextPage !== activePage) {
                        activePage = nextPage;
                        loadHistory();
                    }
                    return;
                }

                var navButton = event.target.closest('[data-md-history-page-nav]');
                if (navButton) {
                    if (navButton.disabled) {
                        return;
                    }
                    var direction = navButton.getAttribute('data-md-history-page-nav');
                    if (direction === 'prev' && activePage > 1) {
                        activePage -= 1;
                        loadHistory();
                    } else if (direction === 'next') {
                        activePage += 1;
                        loadHistory();
                    }
                    return;
                }
            });
        }

        drawer.querySelectorAll('[data-md-history-tab]').forEach(function (button) {
            if (button.dataset.mdHistoryTabBound === '1') {
                return;
            }
            button.dataset.mdHistoryTabBound = '1';
            button.addEventListener('click', function () {
                if (button.hidden) {
                    return;
                }
                setActiveTab(button.getAttribute('data-md-history-tab') || 'history');
            });
        });

        if (document.body.dataset.mdObjectHistoryEscapeBound !== '1') {
            document.body.dataset.mdObjectHistoryEscapeBound = '1';
            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && body.classList.contains('md-object-history-open')) {
                    closeDrawer();
                }
            });
        }
    }

    admin.addBootTask('object-property-history-drawer', initObjectPropertyHistoryDrawer);
})(window, document);
