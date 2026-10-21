(function (window, document) {
    'use strict';

    /**
     * Generic history drawer.
     *
     * Any trigger with `data-md-history-open` opens the shared admin drawer
     * shell and mounts `[data-md-history]` into it. The component is data
     * source agnostic: pass `data-history-url` or object/property ids for the
     * built-in property-history endpoint. A response with the same JSON shape
     * can also be supplied inline through `data-md-history-demo` (UI Kit).
     */
    var admin = window.MDJAdminPanel = window.MDJAdminPanel || {};
    var OWNER = 'md-history';

    var state = {
        root: null,
        footer: null,
        trigger: null,
        range: '24h',
        page: 1,
        data: null,
        request: null,
        token: 0,
        chart: null,
        themeObserver: null
    };

    function escapeHtml(value) {
        return String(value === null || typeof value === 'undefined' ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function formatValue(value) {
        if (value === null || typeof value === 'undefined' || value === '') {
            return '—';
        }
        return String(value);
    }

    function formatMetric(value) {
        if (value === null || typeof value === 'undefined' || value === '') {
            return '—';
        }
        var number = Number(value);
        if (Number.isFinite(number)) {
            try {
                return new Intl.NumberFormat('ru-RU', {maximumFractionDigits: 2}).format(number);
            } catch (error) {
                return String(value);
            }
        }
        return String(value);
    }

    function part(selector) {
        return state.root ? state.root.querySelector(selector) : null;
    }

    function rootUrl() {
        var root = String(window.ROOTHTML || '/');
        return root.charAt(root.length - 1) === '/' ? root : root + '/';
    }

    function normalizeUrl(raw) {
        var url = String(raw || '').trim();
        if (!url) {
            return '';
        }
        if (/^https?:\/\//i.test(url)) {
            return url;
        }
        if (url.charAt(0) === '/') {
            return url;
        }
        if (url.charAt(0) === '?') {
            return rootUrl() + 'admin.php' + url;
        }
        return rootUrl() + url.replace(/^\.\//, '');
    }

    function buildHistoryUrl(trigger) {
        var explicit = trigger.getAttribute('data-history-url');
        if (explicit) {
            return normalizeUrl(explicit);
        }

        var objectId = trigger.getAttribute('data-history-object-id');
        var propertyId = trigger.getAttribute('data-history-property-id');
        if (!objectId || !propertyId) {
            return '';
        }

        return rootUrl() + 'admin.php?action=objects&md=objects&view_mode=edit_objects'
            + '&tab=properties&ajax=1&id=' + encodeURIComponent(objectId)
            + '&op=property_history&property_id=' + encodeURIComponent(propertyId);
    }

    function appendQuery(url, params) {
        var hashIndex = url.indexOf('#');
        var hash = '';
        if (hashIndex !== -1) {
            hash = url.substring(hashIndex);
            url = url.substring(0, hashIndex);
        }
        var separator = url.indexOf('?') === -1 ? '?' : '&';
        var pairs = [];
        Object.keys(params).forEach(function (key) {
            pairs.push(encodeURIComponent(key) + '=' + encodeURIComponent(params[key]));
        });
        return url + (pairs.length ? separator + pairs.join('&') : '') + hash;
    }

    function resolveRoot(trigger) {
        var target = trigger.getAttribute('data-md-history-target');
        if (target) {
            var id = target.charAt(0) === '#' ? target.substring(1) : target;
            var byId = document.getElementById(id);
            if (byId && byId.matches('[data-md-history]')) {
                return byId;
            }
            try {
                var bySelector = document.querySelector(target);
                if (bySelector && bySelector.matches('[data-md-history]')) {
                    return bySelector;
                }
            } catch (error) {
            }
        }
        return document.querySelector('[data-md-history]');
    }

    function destroyChart() {
        if (state.chart && typeof state.chart.destroy === 'function') {
            try {
                state.chart.destroy();
            } catch (error) {
            }
        }
        state.chart = null;
        var chart = part('[data-md-history-chart]');
        if (chart) {
            chart.innerHTML = '';
        }
    }

    function setRangeButtons(component) {
        var root = component || state.root;
        if (!root) {
            return;
        }
        root.querySelectorAll('[data-md-history-range]').forEach(function (button) {
            var isActive = button.getAttribute('data-md-history-range') === state.range;
            button.classList.toggle('active', isActive);
            button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
        });
    }

    function setLoading() {
        var box = part('[data-md-history-state]');
        var content = part('[data-md-history-content]');
        if (box) {
            box.hidden = false;
            box.className = 'md-history__state';
            box.innerHTML = '<div class="md-history__loading">'
                + '<span class="md-spinner" aria-hidden="true"></span>'
                + '<span>Загружаю историю…</span>'
                + '</div>';
        }
        if (content) {
            content.hidden = true;
        }
        setActionsEnabled(false);
    }

    function setActionsEnabled(enabled) {
        if (!state.footer) {
            return;
        }
        state.footer.querySelectorAll('button').forEach(function (button) {
            button.disabled = !enabled;
        });
    }

    function showError(title, message) {
        var box = part('[data-md-history-state]');
        var content = part('[data-md-history-content]');
        if (box) {
            box.hidden = false;
            box.className = 'md-history__state';
            box.innerHTML = '<div class="md-empty md-empty--compact">'
                + '<span class="md-empty__icon"><i class="ti ti-alert-triangle" aria-hidden="true"></i></span>'
                + '<div class="md-empty__title">' + escapeHtml(title) + '</div>'
                + '<p class="md-empty__text">' + escapeHtml(message) + '</p>'
                + '<div class="md-empty__actions">'
                + '<button type="button" class="md-btn md-btn--secondary md-btn--sm" data-md-history-retry>'
                + '<i class="ti ti-refresh" aria-hidden="true"></i><span class="md-btn__label">Повторить</span>'
                + '</button></div></div>';
        }
        if (content) {
            content.hidden = true;
        }
        setActionsEnabled(false);
    }

    function requestErrorMessage(error, payload) {
        if (payload && payload.message) {
            var messages = {
                'Object not found': 'Объект не найден.',
                'Property not found': 'Свойство не найдено.',
                'Object instance not found': 'Объект не загружен. Попробуйте обновить страницу.',
                'Property is not linked to the selected object': 'Свойство не привязано к выбранному объекту.'
            };
            return messages[payload.message] || String(payload.message);
        }
        if (error && error.status === 404) {
            return 'Сервер не нашёл обработчик истории. Проверьте, что модуль «Объекты» доступен.';
        }
        if (error && error.status === 403) {
            return 'Недостаточно прав для просмотра истории.';
        }
        if (error && error.status >= 400) {
            return 'Сервер вернул ошибку. Попробуйте ещё раз.';
        }
        return 'Не удалось связаться с сервером. Проверьте соединение и повторите.';
    }

    function updateDrawerHeader(payload) {
        if (!window.MDJAdminDrawerHost || typeof window.MDJAdminDrawerHost.update !== 'function') {
            return;
        }
        var meta = payload && payload.meta ? payload.meta : {};
        var update = {owner: OWNER};
        var trigger = state.trigger;
        var title = meta.property_name || (trigger ? trigger.getAttribute('data-history-title') : '');
        var subtitle = meta.description || '';
        var eyebrow = trigger ? trigger.getAttribute('data-history-eyebrow') : '';

        if (title) {
            update.title = title;
        }
        update.subtitle = subtitle;
        if (eyebrow) {
            update.eyebrow = eyebrow;
        }
        window.MDJAdminDrawerHost.update(update);
    }

    function renderStats(stats) {
        var container = part('[data-md-history-stats]');
        if (!container) {
            return;
        }
        stats = stats || {};
        var items = [
            {label: 'Изменений', value: formatMetric(stats.changes), icon: 'history', tone: 'primary'},
            {label: 'Минимум', value: formatMetric(stats.min), icon: 'arrow-down', tone: 'info'},
            {label: 'Среднее', value: formatMetric(stats.avg), icon: 'chart-line', tone: 'neutral'},
            {label: 'Максимум', value: formatMetric(stats.max), icon: 'arrow-up', tone: 'warning'}
        ];
        container.innerHTML = items.map(function (item) {
            return '<article class="md-kpi md-kpi--' + item.tone + ' md-history__stat">'
                + '<div class="md-kpi__top">'
                + '<span class="md-kpi__icon"><i class="ti ti-' + item.icon + '" aria-hidden="true"></i></span>'
                + '<span class="md-kpi__label">' + escapeHtml(item.label) + '</span>'
                + '</div>'
                + '<div class="md-kpi__value">' + escapeHtml(item.value) + '</div>'
                + '</article>';
        }).join('');
    }

    function renderSummary(payload) {
        var current = payload.current || {};
        var meta = payload.meta || {};
        var value = part('[data-md-history-current]');
        var metaNode = part('[data-md-history-current-meta]');
        var badges = part('[data-md-history-badges]');
        var rangeBadge = part('[data-md-history-range-badge]');

        if (value) {
            var currentValue = formatValue(current.value);
            value.textContent = currentValue;
            value.classList.toggle('is-long', currentValue.length > 80);
            value.title = currentValue;
        }

        if (metaNode) {
            var parts = [];
            if (current.updated_label) {
                parts.push('Обновлено: ' + current.updated_label);
            }
            if (current.source) {
                parts.push('Источник: ' + current.source);
            }
            metaNode.textContent = parts.length ? parts.join(' • ') : 'Нет данных об обновлении.';
        }

        if (badges) {
            var badgeHtml = [];
            var keepHistory = Number(meta.keep_history || 0);
            if (keepHistory > 0) {
                badgeHtml.push('<span class="md-badge md-badge--info"><i class="ti ti-history" aria-hidden="true"></i>История: ' + keepHistory + ' дн.</span>');
            } else {
                badgeHtml.push('<span class="md-badge md-badge--neutral"><i class="ti ti-history-off" aria-hidden="true"></i>История выключена</span>');
            }
            if (meta.is_numeric) {
                badgeHtml.push('<span class="md-badge md-badge--outline"><i class="ti ti-chart-line" aria-hidden="true"></i>Числовое</span>');
            }
            badges.innerHTML = badgeHtml.join('');
        }

        var rangeText = payload.range && payload.range.label ? payload.range.label : '';
        if (rangeBadge) {
            rangeBadge.textContent = rangeText;
        }
    }

    function buildPages(currentPage, totalPages) {
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
        var pagination = part('[data-md-history-pagination]');
        if (!pagination) {
            return;
        }
        var totalPages = Number(meta && meta.pages ? meta.pages : 0);
        var currentPage = Number(meta && meta.page ? meta.page : 1);
        if (totalPages <= 1) {
            pagination.hidden = true;
            pagination.innerHTML = '';
            return;
        }

        pagination.hidden = false;
        var html = '<li class="page-item' + (meta.has_prev ? '' : ' disabled') + '">'
            + '<button type="button" class="page-link" data-md-history-page-nav="prev" aria-label="Предыдущая страница"'
            + (meta.has_prev ? '' : ' disabled') + '><i class="ti ti-chevron-left" aria-hidden="true"></i></button></li>';

        buildPages(currentPage, totalPages).forEach(function (item) {
            if (typeof item !== 'number') {
                html += '<li class="page-item disabled"><span class="page-link">…</span></li>';
                return;
            }
            var active = item === currentPage;
            html += '<li class="page-item' + (active ? ' active' : '') + '"' + (active ? ' aria-current="page"' : '') + '>'
                + '<button type="button" class="page-link" data-md-history-page="' + item + '">' + item + '</button></li>';
        });

        html += '<li class="page-item' + (meta.has_next ? '' : ' disabled') + '">'
            + '<button type="button" class="page-link" data-md-history-page-nav="next" aria-label="Следующая страница"'
            + (meta.has_next ? '' : ' disabled') + '><i class="ti ti-chevron-right" aria-hidden="true"></i></button></li>';
        pagination.innerHTML = html;
    }

    function renderHistory(payload) {
        var rows = part('[data-md-history-rows]');
        var count = part('[data-md-history-count]');
        var countLabel = part('[data-md-history-count-label]');
        var items = payload.history || [];
        var pagination = payload.history_pagination || {};
        var total = Number(pagination.total || 0);
        var from = Number(pagination.from || 0);
        var to = Number(pagination.to || 0);

        if (count) {
            count.textContent = String(total);
        }
        if (countLabel) {
            countLabel.textContent = total
                ? 'Показаны ' + from + '–' + to + ' из ' + total
                : 'Нет изменений за период';
        }
        if (rows) {
            if (!items.length) {
                rows.innerHTML = '<tr><td colspan="3" class="md-history__empty-cell">'
                    + '<i class="ti ti-history-off" aria-hidden="true"></i>'
                    + '<span>За выбранный период изменений нет.</span>'
                    + '</td></tr>';
            } else {
                rows.innerHTML = items.map(function (item) {
                    var value = formatValue(item.value);
                    var source = item.source ? String(item.source) : '—';
                    return '<tr>'
                        + '<td class="md-history__time"><time datetime="' + escapeHtml(item.added || '') + '">' + escapeHtml(item.added_label || '') + '</time></td>'
                        + '<td class="md-history__value" title="' + escapeHtml(value) + '">' + escapeHtml(value) + '</td>'
                        + '<td class="md-history__source" title="' + escapeHtml(source) + '">' + escapeHtml(source) + '</td>'
                        + '</tr>';
                }).join('');
            }
        }

        renderPagination(pagination);
    }

    function renderChartEmpty(title, text) {
        var chart = part('[data-md-history-chart]');
        if (!chart) {
            return;
        }
        chart.innerHTML = '<div class="md-empty md-empty--compact md-history__chart-empty">'
            + '<span class="md-empty__icon"><i class="ti ti-chart-line" aria-hidden="true"></i></span>'
            + '<div class="md-empty__title">' + escapeHtml(title) + '</div>'
            + '<p class="md-empty__text">' + escapeHtml(text) + '</p>'
            + '</div>';
    }

    function renderChart(payload) {
        destroyChart();

        var chartNode = part('[data-md-history-chart]');
        var chartData = payload.chart || {};
        var meta = payload.meta || {};
        if (!chartNode) {
            return;
        }

        if (!meta.is_numeric) {
            renderChartEmpty('График недоступен', 'Свойство не является числовым — для него строится только журнал изменений.');
            return;
        }
        if (!chartData.has_data || !Array.isArray(chartData.points) || chartData.points.length < 2) {
            renderChartEmpty('Недостаточно данных', 'За выбранный период накопилось меньше двух числовых точек.');
            return;
        }

        var runtime = window.MDJCharts;
        if (!runtime) {
            renderChartEmpty('График недоступен', 'Библиотека графиков не загружена.');
            return;
        }

        var token = state.token;
        var points = chartData.points;
        var compact = window.innerWidth < 560;
        var chartPoints = points.map(function (point) {
            var timestamp = Number(point.timestamp);
            return [timestamp > 0 ? timestamp * 1000 : 0, Number(point.value)];
        });
        var propertyName = meta.property_name || 'Значение';

        runtime.load().then(function (Apex) {
            if (token !== state.token || !state.root || !state.root.isConnected) {
                return;
            }
            var palette = runtime.colors();
            var options = runtime.merge(runtime.baseOptions(), {
                chart: {
                    type: 'area',
                    height: 260,
                    sparkline: {enabled: false}
                },
                series: [{
                    name: propertyName,
                    data: chartPoints
                }],
                colors: [palette.primary],
                stroke: {
                    curve: 'smooth',
                    width: 2,
                    lineCap: 'round'
                },
                fill: {
                    type: 'gradient',
                    gradient: {
                        shadeIntensity: 1,
                        opacityFrom: 0.26,
                        opacityTo: 0.02,
                        stops: [0, 92]
                    }
                },
                markers: {size: 0, strokeWidth: 0, hover: {size: 4}},
                grid: {show: true},
                xaxis: {
                    type: 'datetime',
                    tickAmount: compact ? 3 : 6,
                    axisBorder: {show: false},
                    axisTicks: {show: false},
                    labels: {
                        datetimeUTC: false,
                        format: compact ? 'HH:mm' : 'dd.MM HH:mm',
                        style: {colors: palette.muted, fontSize: '11px'},
                        rotate: 0,
                        hideOverlappingLabels: true
                    },
                    tooltip: {enabled: false}
                },
                yaxis: {
                    tickAmount: 4,
                    labels: {
                        style: {colors: palette.muted, fontSize: '11px'},
                        formatter: function (value) {
                            return formatMetric(value);
                        }
                    }
                },
                tooltip: {
                    theme: runtime.isDark() ? 'dark' : 'light',
                    x: {
                        format: 'dd.MM.yyyy HH:mm'
                    },
                    y: {
                        formatter: function (value) {
                            return formatMetric(value);
                        }
                    }
                }
            });

            chartNode.innerHTML = '';
            state.chart = new Apex(chartNode, options);
            state.chart.render();
        }).catch(function () {
            if (token === state.token) {
                renderChartEmpty('График недоступен', 'Не удалось загрузить библиотеку графиков.');
            }
        });
    }

    function render(payload) {
        if (!payload || payload.status !== 'ok') {
            showError('История недоступна', requestErrorMessage(null, payload));
            return;
        }

        state.data = payload;
        state.page = Number(payload.history_pagination && payload.history_pagination.page ? payload.history_pagination.page : 1);
        updateDrawerHeader(payload);
        renderSummary(payload);
        renderStats(payload.stats || {});
        renderHistory(payload);

        var content = part('[data-md-history-content]');
        var box = part('[data-md-history-state]');
        if (box) {
            box.hidden = true;
        }
        if (content) {
            content.hidden = false;
        }

        renderChart(payload);
        var hasItems = !!(payload.history && payload.history.length);
        setActionsEnabled(hasItems);
    }

    function downloadCsv() {
        if (!state.data || !state.data.history || !state.data.history.length) {
            return;
        }

        function cell(value) {
            return '"' + String(value === null || typeof value === 'undefined' ? '' : value).replace(/"/g, '""') + '"';
        }

        var rows = [['Дата и время', 'Значение', 'Источник']];
        state.data.history.forEach(function (item) {
            rows.push([item.added_label || '', item.value === null ? '' : item.value, item.source || '']);
        });
        var csv = '\uFEFF' + rows.map(function (row) {
            return row.map(cell).join(';');
        }).join('\r\n');

        var name = (state.data.meta && state.data.meta.property_name ? state.data.meta.property_name : 'history')
            + '-' + (state.data.range && state.data.range.key ? state.data.range.key : state.range);
        name = name.replace(/[\\/:*?"<>|]+/g, '-').replace(/\s+/g, '_');

        var blob = new Blob([csv], {type: 'text/csv;charset=utf-8;'});
        var url = URL.createObjectURL(blob);
        var link = document.createElement('a');
        link.href = url;
        link.download = name + '.csv';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        window.setTimeout(function () {
            URL.revokeObjectURL(url);
        }, 0);
    }

    function loadHistory() {
        if (!state.root || !state.trigger) {
            return;
        }

        var demo = state.root.getAttribute('data-md-history-demo');
        var token = ++state.token;

        if (state.request) {
            state.request.abort();
            state.request = null;
        }
        setLoading();

        if (demo) {
            window.setTimeout(function () {
                if (token !== state.token) {
                    return;
                }
                try {
                    var demoPayload = JSON.parse(demo);
                    render(demoPayload);
                } catch (error) {
                    showError('История недоступна', 'Демо-данные компонента истории повреждены.');
                }
            }, 120);
            return;
        }

        var url = buildHistoryUrl(state.trigger);
        if (!url) {
            showError('История недоступна', 'Не удалось определить адрес загрузки истории.');
            return;
        }

        var controller = new AbortController();
        state.request = controller;
        var requestUrl = appendQuery(url, {
            history_range: state.range,
            history_page: state.page
        });

        fetch(requestUrl, {
            headers: {Accept: 'application/json'},
            credentials: 'same-origin',
            signal: controller.signal
        }).then(function (response) {
            if (!response.ok) {
                var error = new Error('HTTP ' + response.status);
                error.status = response.status;
                throw error;
            }
            return response.json();
        }).then(function (payload) {
            if (token !== state.token) {
                return;
            }
            state.request = null;
            if (!payload || payload.status !== 'ok') {
                showError('История недоступна', requestErrorMessage(null, payload));
                return;
            }
            render(payload);
        }).catch(function (error) {
            if (error.name === 'AbortError' || token !== state.token) {
                return;
            }
            state.request = null;
            showError('История недоступна', requestErrorMessage(error));
        });
    }

    function resetUi(component) {
        destroyChart();
        var box = component.querySelector('[data-md-history-state]');
        var content = component.querySelector('[data-md-history-content]');
        if (box) {
            box.hidden = true;
            box.innerHTML = '';
        }
        if (content) {
            content.hidden = true;
        }
        component.querySelectorAll('[data-md-history-stats], [data-md-history-badges], [data-md-history-rows]').forEach(function (node) {
            node.innerHTML = '';
        });
        var current = component.querySelector('[data-md-history-current]');
        if (current) {
            current.textContent = '—';
            current.classList.remove('is-long');
            current.removeAttribute('title');
        }
        var meta = component.querySelector('[data-md-history-current-meta]');
        if (meta) {
            meta.textContent = '';
        }
        var count = component.querySelector('[data-md-history-count]');
        if (count) {
            count.textContent = '0';
        }
        var countLabel = component.querySelector('[data-md-history-count-label]');
        if (countLabel) {
            countLabel.textContent = '';
        }
        var pagination = component.querySelector('[data-md-history-pagination]');
        if (pagination) {
            pagination.hidden = true;
            pagination.innerHTML = '';
        }
        var rangeBadge = component.querySelector('[data-md-history-range-badge]');
        if (rangeBadge) {
            rangeBadge.textContent = '';
        }
    }

    function cleanup() {
        state.token++;
        if (state.request) {
            state.request.abort();
            state.request = null;
        }
        destroyChart();
        state.data = null;
        state.root = null;
        state.footer = null;
        state.trigger = null;
        state.page = 1;
    }

    function openHistory(trigger) {
        var component = resolveRoot(trigger);
        if (!component) {
            return;
        }
        if (!window.MDJAdminDrawerHost) {
            if (window.MDJAdminUI && typeof window.MDJAdminUI.showToast === 'function') {
                window.MDJAdminUI.showToast({type: 'danger', message: 'Не удалось открыть панель истории.'});
            }
            return;
        }

        state.range = trigger.getAttribute('data-history-range')
            || component.getAttribute('data-md-history-default-range')
            || '24h';
        state.page = 1;
        resetUi(component);
        setRangeButtons(component);

        var footer = component.querySelector('[data-md-history-actions]');
        var eyebrow = trigger.getAttribute('data-history-eyebrow') || 'История изменения';
        var title = trigger.getAttribute('data-history-title') || 'История изменения';
        var subtitle = trigger.getAttribute('data-history-subtitle') || '';

        window.MDJAdminDrawerHost.open({
            owner: OWNER,
            eyebrow: eyebrow,
            title: title,
            subtitle: subtitle,
            width: trigger.getAttribute('data-history-width') || 'min(960px, 100vw)',
            body: component,
            footer: footer,
            focus: function () {
                return component.querySelector('[data-md-history-range].active');
            },
            onClose: cleanup
        });

        // The host synchronously closes the previous drawer, so assign the
        // active state only after open() has settled.
        state.root = component;
        state.trigger = trigger;
        state.footer = footer;
        setRangeButtons();
        loadHistory();
    }

    function onDocumentClick(event) {
        var openTrigger = event.target.closest('[data-md-history-open]');
        if (openTrigger) {
            event.preventDefault();
            openHistory(openTrigger);
            return;
        }

        if (!state.root) {
            return;
        }
        var inside = state.root.contains(event.target)
            || (state.footer && state.footer.contains(event.target));
        if (!inside) {
            return;
        }

        var retry = event.target.closest('[data-md-history-retry]');
        if (retry) {
            event.preventDefault();
            loadHistory();
            return;
        }

        var refresh = event.target.closest('[data-md-history-refresh]');
        if (refresh) {
            event.preventDefault();
            loadHistory();
            return;
        }

        var download = event.target.closest('[data-md-history-download]');
        if (download) {
            event.preventDefault();
            downloadCsv();
            return;
        }

        var rangeButton = event.target.closest('[data-md-history-range]');
        if (rangeButton) {
            event.preventDefault();
            var range = rangeButton.getAttribute('data-md-history-range') || '24h';
            if (range === state.range) {
                return;
            }
            state.range = range;
            state.page = 1;
            setRangeButtons();
            loadHistory();
            return;
        }

        var pageButton = event.target.closest('[data-md-history-page]');
        if (pageButton) {
            event.preventDefault();
            var nextPage = parseInt(pageButton.getAttribute('data-md-history-page'), 10);
            if (!isNaN(nextPage) && nextPage > 0 && nextPage !== state.page) {
                state.page = nextPage;
                loadHistory();
            }
            return;
        }

        var navButton = event.target.closest('[data-md-history-page-nav]');
        if (navButton) {
            event.preventDefault();
            if (navButton.disabled) {
                return;
            }
            var direction = navButton.getAttribute('data-md-history-page-nav');
            if (direction === 'prev' && state.page > 1) {
                state.page--;
                loadHistory();
            } else if (direction === 'next') {
                state.page++;
                loadHistory();
            }
        }
    }

    function observeTheme() {
        if (state.themeObserver || !window.MutationObserver) {
            return;
        }
        state.themeObserver = new MutationObserver(function (mutations) {
            var changed = mutations.some(function (mutation) {
                return mutation.type === 'attributes' && mutation.attributeName === 'data-bs-theme';
            });
            if (!changed || !state.data || !state.root) {
                return;
            }
            if (!window.MDJAdminDrawerHost || window.MDJAdminDrawerHost.getCurrentOwner() !== OWNER) {
                return;
            }
            renderChart(state.data);
        });
        state.themeObserver.observe(document.documentElement, {attributes: true, attributeFilter: ['data-bs-theme']});
    }

    function initHistoryDrawer() {
        observeTheme();
        if (!document.body || document.body.dataset.mdHistoryBound === '1') {
            return;
        }
        document.body.dataset.mdHistoryBound = '1';
        document.addEventListener('click', onDocumentClick);
    }

    admin.addBootTask('history-drawer', initHistoryDrawer);
})(window, document);
