(function (window, document) {
    'use strict';

    /**
     * Always-on property trace drawer.
     *
     * A run is one causal story: trigger -> method/script/module -> property
     * writes. The server returns all steps for the selected property; this
     * module only renders them and keeps the drawer shell consistent with the
     * history component.
     */
    var admin = window.MDJAdminPanel = window.MDJAdminPanel || {};
    var OWNER = 'md-trace';

    var state = {
        root: null,
        footer: null,
        trigger: null,
        range: '24h',
        page: 1,
        data: null,
        request: null,
        token: 0
    };

    function escapeHtml(value) {
        return String(value === null || typeof value === 'undefined' ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
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

    function buildTraceUrl(trigger) {
        var explicit = trigger.getAttribute('data-trace-url');
        if (explicit) {
            return normalizeUrl(explicit);
        }
        var objectId = trigger.getAttribute('data-trace-object-id');
        var propertyId = trigger.getAttribute('data-trace-property-id');
        if (!objectId || !propertyId) {
            return '';
        }
        return rootUrl() + 'admin.php?action=objects&md=objects&view_mode=edit_objects'
            + '&tab=properties&ajax=1&id=' + encodeURIComponent(objectId)
            + '&op=property_trace&property_id=' + encodeURIComponent(propertyId);
    }

    function resolveRoot(trigger) {
        var target = trigger.getAttribute('data-md-trace-target');
        if (target) {
            var id = target.charAt(0) === '#' ? target.substring(1) : target;
            var byId = document.getElementById(id);
            if (byId && byId.matches('[data-md-trace]')) {
                return byId;
            }
            try {
                var bySelector = document.querySelector(target);
                if (bySelector && bySelector.matches('[data-md-trace]')) {
                    return bySelector;
                }
            } catch (error) {
            }
        }
        return document.querySelector('[data-md-trace]');
    }

    function part(selector) {
        return state.root ? state.root.querySelector(selector) : null;
    }

    function setRangeButtons(component) {
        var root = component || state.root;
        if (!root) {
            return;
        }
        root.querySelectorAll('[data-md-trace-range]').forEach(function (button) {
            var isActive = button.getAttribute('data-md-trace-range') === state.range;
            button.classList.toggle('active', isActive);
            button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
        });
    }

    function setActionsEnabled(enabled) {
        if (!state.footer) {
            return;
        }
        state.footer.querySelectorAll('button').forEach(function (button) {
            button.disabled = !enabled;
        });
    }

    function setLoading() {
        var box = part('[data-md-trace-state]');
        var content = part('[data-md-trace-content]');
        if (box) {
            box.hidden = false;
            box.className = 'md-trace__state';
            box.innerHTML = '<div class="md-trace__loading">'
                + '<span class="md-spinner" aria-hidden="true"></span>'
                + '<span>Загружаю журнал изменений…</span>'
                + '</div>';
        }
        if (content) {
            content.hidden = true;
        }
        setActionsEnabled(false);
    }

    function showError(title, message) {
        var box = part('[data-md-trace-state]');
        var content = part('[data-md-trace-content]');
        if (box) {
            box.hidden = false;
            box.className = 'md-trace__state';
            box.innerHTML = '<div class="md-empty md-empty--compact">'
                + '<span class="md-empty__icon"><i class="ti ti-route-off" aria-hidden="true"></i></span>'
                + '<div class="md-empty__title">' + escapeHtml(title) + '</div>'
                + '<p class="md-empty__text">' + escapeHtml(message) + '</p>'
                + '<div class="md-empty__actions">'
                + '<button type="button" class="md-btn md-btn--secondary md-btn--sm" data-md-trace-retry>'
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
            return 'Сервер не нашёл обработчик журнала. Проверьте, что модуль «Объекты» доступен.';
        }
        if (error && error.status === 403) {
            return 'Недостаточно прав для просмотра журнала.';
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
        var trigger = state.trigger;
        var update = {owner: OWNER};
        var title = meta.property_name || (trigger ? trigger.getAttribute('data-trace-title') : '');
        var subtitle = meta.description || '';
        var eyebrow = trigger ? trigger.getAttribute('data-trace-eyebrow') : '';
        if (title) {
            update.title = title;
        }
        update.subtitle = subtitle;
        if (eyebrow) {
            update.eyebrow = eyebrow;
        }
        window.MDJAdminDrawerHost.update(update);
    }

    function formatDuration(seconds) {
        var value = Number(seconds || 0);
        if (!Number.isFinite(value) || value <= 0) {
            return '0 с';
        }
        if (value < 1) {
            return '<1 с';
        }
        if (value < 60) {
            return String(Math.round(value)) + ' с';
        }
        var minutes = Math.floor(value / 60);
        var rest = Math.round(value % 60);
        return minutes + ' мин' + (rest ? ' ' + rest + ' с' : '');
    }

    function formatMs(ms) {
        var value = Number(ms || 0);
        if (!Number.isFinite(value) || value <= 0) {
            return '';
        }
        if (value < 1000) {
            return Math.round(value) + ' мс';
        }
        return (value / 1000).toFixed(1) + ' с';
    }

    function formatValue(value) {
        if (value === null || typeof value === 'undefined') {
            return '—';
        }
        if (value === '') {
            return 'пусто';
        }
        return String(value);
    }

    function shorten(value, limit) {
        var text = String(value === null || typeof value === 'undefined' ? '' : value);
        var max = limit || 240;
        return text.length > max ? text.substring(0, max - 1) + '…' : text;
    }

    function jsonText(value) {
        if (value === null || typeof value === 'undefined' || value === '') {
            return '';
        }
        if (typeof value === 'string') {
            return value;
        }
        try {
            return JSON.stringify(value, null, 2);
        } catch (error) {
            return String(value);
        }
    }

    function hasContent(value) {
        if (value === null || typeof value === 'undefined' || value === '') {
            return false;
        }
        if (Array.isArray(value)) {
            return value.length > 0;
        }
        if (typeof value === 'object') {
            return Object.keys(value).length > 0;
        }
        return true;
    }

    function badge(label, tone, icon) {
        return '<span class="md-badge md-badge--' + escapeHtml(tone || 'neutral') + '">'
            + (icon ? '<i class="ti ti-' + escapeHtml(icon) + '" aria-hidden="true"></i>' : '')
            + escapeHtml(label) + '</span>';
    }

    function statusTone(status) {
        if (status === 'error') {
            return 'danger';
        }
        if (status === 'warning') {
            return 'warning';
        }
        if (status === 'running') {
            return 'info';
        }
        return 'success';
    }

    function eventIcon(type) {
        var icons = {
            trigger: 'player-play',
            job: 'clock',
            method: 'braces',
            script: 'file-code',
            module: 'puzzle',
            property: 'adjustments-horizontal',
            error: 'alert-triangle',
            warning: 'alert-triangle',
            chain: 'arrows-loop'
        };
        return icons[type] || 'point';
    }

    function renderLinks(event) {
        if (!Array.isArray(event.links) || !event.links.length) {
            return '';
        }
        return '<div class="md-trace__event-links">' + event.links.map(function (link) {
            return '<a class="md-badge md-badge--outline" href="' + escapeHtml(link.url || '#') + '">'
                + (link.icon ? '<i class="ti ti-' + escapeHtml(link.icon) + '" aria-hidden="true"></i>' : '')
                + escapeHtml(link.label || 'Открыть') + '</a>';
        }).join('') + '</div>';
    }

    function renderValues(event) {
        if (event.type !== 'property') {
            return '';
        }
        var oldValue = formatValue(event.old_value);
        var newValue = formatValue(event.new_value);
        return '<div class="md-trace__values">'
            + '<span class="md-trace__value md-trace__value--old" title="' + escapeHtml(oldValue) + '">' + escapeHtml(shorten(oldValue)) + '</span>'
            + '<i class="ti ti-arrow-right md-trace__arrow" aria-hidden="true"></i>'
            + '<span class="md-trace__value md-trace__value--new" title="' + escapeHtml(newValue) + '">' + escapeHtml(shorten(newValue)) + '</span>'
            + '</div>';
    }

    function renderDetails(label, value) {
        if (!hasContent(value)) {
            return '';
        }
        return '<details class="md-trace__details">'
            + '<summary>' + escapeHtml(label) + '</summary>'
            + '<pre class="md-trace__json">' + escapeHtml(jsonText(value)) + '</pre>'
            + '</details>';
    }

    function renderEvent(event) {
        var classes = ['md-trace__event'];
        if (event.is_target) {
            classes.push('is-target');
        }
        if (event.status === 'error') {
            classes.push('is-error');
        }
        var title = event.title || event.type_label || 'Шаг';
        var duration = formatMs(event.duration_ms);
        var params = renderDetails('Параметры на входе', event.params);
        var outParams = renderDetails('Параметры после выполнения', event.out_params);
        var result = event.type === 'property' ? '' : renderDetails('Результат', event.result);
        var chain = renderDetails('Цепочка свойств', event.chain);
        var error = event.error ? '<div class="md-trace__error">' + escapeHtml(event.error) + '</div>' : '';
        var subtitle = event.subtitle ? '<div class="md-trace__event-subtitle">' + escapeHtml(event.subtitle) + '</div>' : '';
        var source = event.source ? '<div class="md-trace__event-subtitle">Источник: ' + escapeHtml(event.source) + '</div>' : '';
        return '<li class="' + classes.join(' ') + '" data-trace-event-type="' + escapeHtml(event.type) + '">'
            + '<span class="md-trace__event-marker"><i class="ti ti-' + eventIcon(event.type) + '" aria-hidden="true"></i></span>'
            + '<div class="md-trace__event-body">'
            + '<div class="md-trace__event-head">'
            + (event.time_label ? '<span class="md-trace__event-time">' + escapeHtml(event.time_label) + '</span>' : '')
            + '<span class="md-trace__event-title">' + escapeHtml(title) + '</span>'
            + badge(event.type_label || event.type, event.status === 'error' ? 'danger' : 'outline')
            + (duration ? badge(duration, 'neutral', 'clock') : '')
            + '</div>'
            + subtitle + source + renderLinks(event) + renderValues(event)
            + params + outParams + result + chain + error
            + '</div></li>';
    }

    function renderFact(label, value) {
        if (!value) {
            return '';
        }
        return '<div class="md-trace__fact">'
            + '<span class="md-trace__fact-label">' + escapeHtml(label) + '</span>'
            + '<span class="md-trace__fact-value">' + value + '</span>'
            + '</div>';
    }

    function renderRun(run, index) {
        var targetEvents = (run.events || []).filter(function (event) {
            return event.is_target;
        });
        var countBadge = badge('Изменений: ' + targetEvents.length, targetEvents.length ? 'info' : 'outline', 'adjustments-horizontal');
        var duration = formatDuration(run.duration_seconds);
        var statusBadge = badge(run.status_label || run.status, statusTone(run.status));
        var actor = run.actor ? escapeHtml(run.actor) : '—';
        var source = run.source ? escapeHtml(run.source) : '—';
        var trace = run.trace_id ? '<span class="md-trace__trace-id">' + escapeHtml(run.trace_id) + '</span>'
            + ' <button type="button" class="md-icon-btn md-icon-btn--sm" data-md-trace-copy="' + escapeHtml(run.trace_id) + '" aria-label="Скопировать ID запуска" title="Скопировать ID запуска"><i class="ti ti-copy" aria-hidden="true"></i></button>' : '';
        var params = renderDetails('Параметры запуска', run.params);
        var result = renderDetails('Результат запуска', run.result);
        var error = run.error ? '<div class="md-trace__error">' + escapeHtml(run.error) + '</div>' : '';
        var events = (run.events || []).map(renderEvent).join('');
        return '<details class="md-trace__run"' + (index === 0 ? ' open' : '') + ' data-md-trace-run="' + escapeHtml(run.id) + '">'
            + '<summary class="md-trace__run-head">'
            + '<span class="md-trace__run-id">#' + escapeHtml(run.id) + '</span>'
            + '<span class="md-trace__run-main">'
            + '<span class="md-trace__run-title">' + escapeHtml(run.trigger_label || 'Запуск') + ' · ' + escapeHtml(run.trigger_title || '') + '</span>'
            + '<span class="md-trace__run-time">' + escapeHtml(run.started_label || '') + '</span>'
            + '</span>'
            + '<span class="md-trace__run-meta">' + statusBadge + countBadge + badge(duration, 'neutral', 'clock') + '</span>'
            + '<span class="md-trace__chevron" aria-hidden="true"><i class="ti ti-chevron-down"></i></span>'
            + '</summary>'
            + '<div class="md-trace__run-body">'
            + '<div class="md-trace__facts">'
            + renderFact('ID запуска', trace)
            + renderFact('Старт', escapeHtml(run.started_label || '—'))
            + renderFact('Длительность', escapeHtml(duration))
            + renderFact('Источник', source)
            + renderFact('Кто', actor)
            + renderFact('Запрос', run.request_uri ? escapeHtml(run.request_uri) : '')
            + '</div>'
            + params + result + error
            + '<ol class="md-trace__chain">' + events + '</ol>'
            + '</div></details>';
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

    function renderPagination(pagination) {
        var node = part('[data-md-trace-pagination]');
        if (!node) {
            return;
        }
        pagination = pagination || {};
        var totalPages = Number(pagination.pages || 0);
        var currentPage = Number(pagination.page || 1);
        if (totalPages <= 1) {
            node.hidden = true;
            node.innerHTML = '';
            return;
        }
        node.hidden = false;
        var html = '<li class="page-item' + (pagination.has_prev ? '' : ' disabled') + '">'
            + '<button type="button" class="page-link" data-md-trace-page-nav="prev" aria-label="Предыдущая страница"'
            + (pagination.has_prev ? '' : ' disabled') + '><i class="ti ti-chevron-left" aria-hidden="true"></i></button></li>';
        buildPages(currentPage, totalPages).forEach(function (item) {
            if (typeof item !== 'number') {
                html += '<li class="page-item disabled"><span class="page-link">…</span></li>';
                return;
            }
            var active = item === currentPage;
            html += '<li class="page-item' + (active ? ' active' : '') + '"' + (active ? ' aria-current="page"' : '') + '>'
                + '<button type="button" class="page-link" data-md-trace-page="' + item + '">' + item + '</button></li>';
        });
        html += '<li class="page-item' + (pagination.has_next ? '' : ' disabled') + '">'
            + '<button type="button" class="page-link" data-md-trace-page-nav="next" aria-label="Следующая страница"'
            + (pagination.has_next ? '' : ' disabled') + '><i class="ti ti-chevron-right" aria-hidden="true"></i></button></li>';
        node.innerHTML = html;
    }

    function renderEmpty(payload) {
        var meta = payload && payload.meta ? payload.meta : {};
        var retention = Number(meta.retention_days || 0);
        var hint = retention > 0
            ? 'За выбранный период изменений нет. Журнал хранится ' + retention + ' дн.'
            : 'За выбранный период изменений нет. Журнал ведётся всегда; запись появится после первого изменения значения.';
        return '<div class="md-empty md-empty--compact md-trace__empty">'
            + '<span class="md-empty__icon"><i class="ti ti-route-off" aria-hidden="true"></i></span>'
            + '<div class="md-empty__title">Цепочек пока нет</div>'
            + '<p class="md-empty__text">' + escapeHtml(hint) + '</p>'
            + '</div>';
    }

    function render(payload) {
        if (!payload || payload.status !== 'ok') {
            showError('Журнал недоступен', requestErrorMessage(null, payload));
            return;
        }
        state.data = payload;
        state.page = Number(payload.pagination && payload.pagination.page ? payload.pagination.page : 1);
        updateDrawerHeader(payload);
        var count = part('[data-md-trace-count]');
        var total = Number(payload.pagination && payload.pagination.total ? payload.pagination.total : 0);
        if (count) {
            count.textContent = String(total);
        }
        var runs = part('[data-md-trace-runs]');
        if (runs) {
            runs.innerHTML = payload.runs && payload.runs.length
                ? payload.runs.map(renderRun).join('')
                : renderEmpty(payload);
        }
        renderPagination(payload.pagination || {});
        var box = part('[data-md-trace-state]');
        var content = part('[data-md-trace-content]');
        if (box) {
            box.hidden = true;
        }
        if (content) {
            content.hidden = false;
        }
        setActionsEnabled(total > 0);
    }

    function loadTrace() {
        if (!state.root || !state.trigger) {
            return;
        }
        var demo = state.root.getAttribute('data-md-trace-demo');
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
                    render(JSON.parse(demo));
                } catch (error) {
                    showError('Журнал недоступен', 'Демо-данные компонента повреждены.');
                }
            }, 120);
            return;
        }
        var url = buildTraceUrl(state.trigger);
        if (!url) {
            showError('Журнал недоступен', 'Не удалось определить адрес загрузки журнала.');
            return;
        }
        var controller = new AbortController();
        state.request = controller;
        var requestUrl = appendQuery(url, {
            trace_range: state.range,
            trace_page: state.page
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
            render(payload);
        }).catch(function (error) {
            if (error.name === 'AbortError' || token !== state.token) {
                return;
            }
            state.request = null;
            showError('Журнал недоступен', requestErrorMessage(error));
        });
    }

    function copyText(value) {
        if (!value) {
            return;
        }
        function done() {
            if (window.MDJAdminUI && typeof window.MDJAdminUI.showToast === 'function') {
                window.MDJAdminUI.showToast({type: 'success', message: 'ID запуска скопирован'});
            }
        }
        if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
            navigator.clipboard.writeText(value).then(done).catch(function () {});
            return;
        }
        var input = document.createElement('textarea');
        input.value = value;
        input.setAttribute('readonly', 'readonly');
        input.style.position = 'absolute';
        input.style.left = '-9999px';
        document.body.appendChild(input);
        input.select();
        try {
            document.execCommand('copy');
            done();
        } catch (error) {
        }
        document.body.removeChild(input);
    }

    function resetUi(component) {
        var stateBox = component.querySelector('[data-md-trace-state]');
        var content = component.querySelector('[data-md-trace-content]');
        var runs = component.querySelector('[data-md-trace-runs]');
        var count = component.querySelector('[data-md-trace-count]');
        var pagination = component.querySelector('[data-md-trace-pagination]');
        if (stateBox) {
            stateBox.hidden = true;
            stateBox.innerHTML = '';
        }
        if (content) {
            content.hidden = true;
        }
        if (runs) {
            runs.innerHTML = '';
        }
        if (count) {
            count.textContent = '0';
        }
        if (pagination) {
            pagination.hidden = true;
            pagination.innerHTML = '';
        }
    }

    function cleanup() {
        state.token++;
        if (state.request) {
            state.request.abort();
            state.request = null;
        }
        state.data = null;
        state.root = null;
        state.footer = null;
        state.trigger = null;
        state.page = 1;
    }

    function openTrace(trigger) {
        var component = resolveRoot(trigger);
        if (!component) {
            return;
        }
        if (!window.MDJAdminDrawerHost) {
            if (window.MDJAdminUI && typeof window.MDJAdminUI.showToast === 'function') {
                window.MDJAdminUI.showToast({type: 'danger', message: 'Не удалось открыть журнал изменений.'});
            }
            return;
        }
        state.range = trigger.getAttribute('data-trace-range')
            || component.getAttribute('data-md-trace-default-range')
            || '24h';
        state.page = 1;
        resetUi(component);
        setRangeButtons(component);
        var footer = component.querySelector('[data-md-trace-actions]');
        var eyebrow = trigger.getAttribute('data-trace-eyebrow') || 'Цепочка изменений';
        var title = trigger.getAttribute('data-trace-title') || 'Журнал изменений свойства';
        var subtitle = trigger.getAttribute('data-trace-subtitle') || '';
        window.MDJAdminDrawerHost.open({
            owner: OWNER,
            eyebrow: eyebrow,
            title: title,
            subtitle: subtitle,
            width: trigger.getAttribute('data-trace-width') || 'min(920px, 100vw)',
            body: component,
            footer: footer,
            focus: function () {
                return component.querySelector('[data-md-trace-range].active');
            },
            onClose: cleanup
        });
        state.root = component;
        state.trigger = trigger;
        state.footer = footer;
        setRangeButtons();
        loadTrace();
    }

    function onDocumentClick(event) {
        var openTrigger = event.target.closest('[data-md-trace-open]');
        if (openTrigger) {
            event.preventDefault();
            openTrace(openTrigger);
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
        var copy = event.target.closest('[data-md-trace-copy]');
        if (copy) {
            event.preventDefault();
            event.stopPropagation();
            copyText(copy.getAttribute('data-md-trace-copy') || '');
            return;
        }
        var retry = event.target.closest('[data-md-trace-retry]');
        if (retry) {
            event.preventDefault();
            loadTrace();
            return;
        }
        var refresh = event.target.closest('[data-md-trace-refresh]');
        if (refresh) {
            event.preventDefault();
            loadTrace();
            return;
        }
        var rangeButton = event.target.closest('[data-md-trace-range]');
        if (rangeButton) {
            event.preventDefault();
            var range = rangeButton.getAttribute('data-md-trace-range') || '24h';
            if (range === state.range) {
                return;
            }
            state.range = range;
            state.page = 1;
            setRangeButtons();
            loadTrace();
            return;
        }
        var pageButton = event.target.closest('[data-md-trace-page]');
        if (pageButton) {
            event.preventDefault();
            var nextPage = parseInt(pageButton.getAttribute('data-md-trace-page'), 10);
            if (!isNaN(nextPage) && nextPage > 0 && nextPage !== state.page) {
                state.page = nextPage;
                loadTrace();
            }
            return;
        }
        var navButton = event.target.closest('[data-md-trace-page-nav]');
        if (navButton) {
            event.preventDefault();
            if (navButton.disabled) {
                return;
            }
            var direction = navButton.getAttribute('data-md-trace-page-nav');
            if (direction === 'prev' && state.page > 1) {
                state.page--;
                loadTrace();
            } else if (direction === 'next') {
                state.page++;
                loadTrace();
            }
        }
    }

    function initTraceDrawer() {
        if (!document.body || document.body.dataset.mdTraceBound === '1') {
            return;
        }
        document.body.dataset.mdTraceBound = '1';
        document.addEventListener('click', onDocumentClick);
    }

    admin.addBootTask('property-trace-drawer', initTraceDrawer);
})(window, document);
