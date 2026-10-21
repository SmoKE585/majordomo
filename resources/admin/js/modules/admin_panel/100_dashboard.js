(function (window, document) {
    'use strict';

    var admin = window.MDJAdminPanel = window.MDJAdminPanel || {};
    var state = admin.state = admin.state || {};

    var runtime = {
        node: null,
        refreshTimer: 0,
        blockTimer: 0,
        loading: false,
        data: null,
        hasData: false,
        attention: [],
        updateAlert: null,
        loadFailed: false
    };

    function rootHtml() {
        var root = window.ROOTHTML || '/';
        return root.charAt(root.length - 1) === '/' ? root : root + '/';
    }

    function urls() {
        var root = rootHtml();
        return {
            dashboard: root + 'admin.php?ajax_panel=1&op=dashboard',
            scripts: root + 'admin.php?action=xray&md=xray&inst=adm&view_mode=scripts&ajax=1&op=getcontent',
            properties: root + 'admin.php?action=xray&md=xray&inst=adm&view_mode=properties&ajax=1&op=getcontent',
            updates: root + 'ajax/saverestore.html?op=check_updates',
            myBlock: root + 'objects/?script=dash_my_block'
        };
    }

    async function fetchJson(url) {
        var response = await fetch(url, {
            cache: 'no-store',
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        if (!response.ok) {
            throw new Error('HTTP ' + response.status);
        }
        var text = await response.text();
        if (!text) {
            return null;
        }
        try {
            return JSON.parse(text);
        } catch (error) {
            return null;
        }
    }

    function text(value, fallback) {
        if (value === null || typeof value === 'undefined' || value === '') {
            return typeof fallback === 'undefined' ? '—' : fallback;
        }
        return String(value);
    }

    function number(value) {
        var parsed = Number(value);
        return Number.isFinite(parsed) ? parsed : 0;
    }

    function formatNumber(value, maximumFractionDigits) {
        return number(value).toLocaleString('ru-RU', {
            maximumFractionDigits: typeof maximumFractionDigits === 'undefined' ? 0 : maximumFractionDigits
        });
    }

    function formatBytes(value) {
        if (value === null || typeof value === 'undefined' || !Number.isFinite(Number(value))) {
            return '—';
        }
        var bytes = Math.max(0, Number(value));
        var units = ['Б', 'КБ', 'МБ', 'ГБ', 'ТБ'];
        var index = 0;
        while (bytes >= 1024 && index < units.length - 1) {
            bytes /= 1024;
            index++;
        }
        return bytes.toLocaleString('ru-RU', {
            maximumFractionDigits: index === 0 ? 0 : 1
        }) + ' ' + units[index];
    }

    function formatPercent(value) {
        if (value === null || typeof value === 'undefined' || !Number.isFinite(Number(value))) {
            return 'н/д';
        }
        return number(value).toLocaleString('ru-RU', { maximumFractionDigits: 1 }) + '%';
    }

    function plural(value, forms) {
        var abs = Math.abs(value) % 100;
        var last = abs % 10;
        if (abs > 10 && abs < 20) {
            return forms[2];
        }
        if (last > 1 && last < 5) {
            return forms[1];
        }
        if (last === 1) {
            return forms[0];
        }
        return forms[2];
    }

    function parseDate(value) {
        if (!value || String(value).indexOf('1970') === 0) {
            return null;
        }
        var parsed = new Date(String(value).replace(' ', 'T'));
        return Number.isNaN(parsed.getTime()) ? null : parsed;
    }

    function relativeTime(value) {
        var date = parseDate(value);
        if (!date) {
            return 'не запускался';
        }
        var seconds = Math.max(0, Math.round((Date.now() - date.getTime()) / 1000));
        if (seconds < 45) {
            return 'только что';
        }
        var minutes = Math.round(seconds / 60);
        if (minutes < 60) {
            return minutes + ' ' + plural(minutes, ['минуту', 'минуты', 'минут']) + ' назад';
        }
        var hours = Math.round(minutes / 60);
        if (hours < 24) {
            return hours + ' ' + plural(hours, ['час', 'часа', 'часов']) + ' назад';
        }
        var days = Math.round(hours / 24);
        if (days < 8) {
            return days + ' ' + plural(days, ['день', 'дня', 'дней']) + ' назад';
        }
        return date.toLocaleDateString('ru-RU', { day: '2-digit', month: 'short', year: 'numeric' });
    }

    function timeOnly(value) {
        var date = parseDate(value);
        if (!date) {
            return '—';
        }
        return date.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
    }

    function setField(name, value) {
        if (!runtime.node) {
            return;
        }
        runtime.node.querySelectorAll('[data-md-dash-field="' + name + '"]').forEach(function (node) {
            node.textContent = text(value);
        });
    }

    function setNodeText(selector, value) {
        if (!runtime.node) {
            return;
        }
        var node = runtime.node.querySelector(selector);
        if (node) {
            node.textContent = text(value);
        }
    }

    function iconClass(name) {
        var safe = String(name || 'info-circle').replace(/[^a-z0-9-]/gi, '');
        return 'ti ti-' + (safe || 'info-circle');
    }

    function makeIcon(name) {
        var icon = document.createElement('i');
        icon.className = iconClass(name);
        icon.setAttribute('aria-hidden', 'true');
        return icon;
    }

    function updateDateLine() {
        if (!runtime.node) {
            return;
        }
        var node = runtime.node.querySelector('[data-md-dash-date]');
        if (!node) {
            return;
        }
        try {
            node.textContent = new Intl.DateTimeFormat('ru-RU', {
                weekday: 'long',
                day: 'numeric',
                month: 'long'
            }).format(new Date());
        } catch (error) {
            node.textContent = new Date().toLocaleDateString('ru-RU');
        }
    }

    function updateUpdatedAt(value) {
        if (!runtime.node) {
            return;
        }
        var node = runtime.node.querySelector('[data-md-dash-updated]');
        if (!node) {
            return;
        }
        var date = parseDate(value) || new Date();
        node.textContent = date.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
        node.setAttribute('datetime', date.toISOString());
        node.setAttribute('title', date.toLocaleString('ru-RU'));
    }

    // ---------------------------------------------------------------------
    // Health and attention
    // ---------------------------------------------------------------------

    function updateHealth() {
        if (!runtime.node) {
            return;
        }
        if (!runtime.hasData) {
            if (!runtime.loadFailed) {
                return;
            }
            var failedStatus = runtime.node.querySelector('#mdDashHealth');
            var failedText = runtime.node.querySelector('[data-md-dash-health-text]');
            var failedValue = runtime.node.querySelector('[data-md-dash-health-value]');
            var failedMeta = runtime.node.querySelector('[data-md-dash-health-meta]');
            if (failedStatus) {
                failedStatus.setAttribute('data-state', 'warning');
            }
            if (failedText) {
                failedText.textContent = 'Нет связи с сервером дашборда';
            }
            if (failedValue) {
                failedValue.textContent = 'Нет данных';
                failedValue.classList.remove('is-ok', 'is-danger');
                failedValue.classList.add('is-warning');
            }
            if (failedMeta) {
                failedMeta.textContent = 'Проверьте соединение и обновите страницу';
            }
            return;
        }
        var attention = runtime.attention || [];
        var danger = attention.filter(function (item) {
            return item.tone === 'danger';
        });
        var warning = attention.filter(function (item) {
            return item.tone === 'warning';
        });
        var level = danger.length ? 'danger' : (warning.length ? 'warning' : 'ok');
        var first = danger.length ? danger[0] : (warning.length ? warning[0] : null);
        var message = 'Система работает штатно';
        if (first) {
            message = first.title;
        }

        var status = runtime.node.querySelector('#mdDashHealth');
        var statusText = runtime.node.querySelector('[data-md-dash-health-text]');
        var statusLink = runtime.node.querySelector('[data-md-dash-health-link]');
        var value = runtime.node.querySelector('[data-md-dash-health-value]');
        var meta = runtime.node.querySelector('[data-md-dash-health-meta]');
        var icon = runtime.node.querySelector('[data-md-dash-health-icon]');

        if (status) {
            status.setAttribute('data-state', level);
        }
        if (statusText) {
            statusText.textContent = message;
        }
        if (statusLink) {
            statusLink.hidden = !first;
            if (first) {
                statusLink.textContent = 'Подробнее';
                statusLink.href = first.action_url || '#';
            }
        }
        if (value) {
            value.textContent = level === 'danger' ? 'Ошибка' : (level === 'warning' ? 'Внимание' : 'Штатно');
            value.classList.toggle('is-ok', level === 'ok');
            value.classList.toggle('is-warning', level === 'warning');
            value.classList.toggle('is-danger', level === 'danger');
        }
        if (meta) {
            if (danger.length) {
                meta.textContent = danger.length + ' ' + plural(danger.length, ['критическое событие', 'критических события', 'критических событий']);
            } else if (warning.length) {
                meta.textContent = warning.length + ' ' + plural(warning.length, ['предупреждение', 'предупреждения', 'предупреждений']);
            } else {
                meta.textContent = 'Активных ошибок и предупреждений нет';
            }
        }
        if (icon) {
            icon.classList.toggle('md-dash-stat__icon--success', level === 'ok');
            icon.classList.toggle('md-dash-stat__icon--warning', level === 'warning');
            icon.classList.toggle('md-dash-stat__icon--danger', level === 'danger');
            icon.innerHTML = '';
            icon.appendChild(makeIcon(level === 'ok' ? 'shield-check' : (level === 'warning' ? 'alert-triangle' : 'alert-octagon')));
        }
    }

    function alertAction(label, href) {
        if (!label || !href) {
            return null;
        }
        var action = document.createElement('a');
        action.className = 'md-btn md-btn--secondary md-btn--sm md-dash-alert__action';
        action.href = href;
        var span = document.createElement('span');
        span.className = 'md-btn__label';
        span.textContent = label;
        action.appendChild(span);
        action.appendChild(makeIcon('arrow-right'));
        return action;
    }

    function buildAlert(item) {
        var alert = document.createElement('div');
        var tone = ['info', 'success', 'warning', 'danger'].indexOf(item.tone) >= 0 ? item.tone : 'info';
        alert.className = 'md-alert md-alert--' + tone + ' md-dash-alert';
        if (tone === 'danger') {
            alert.setAttribute('role', 'alert');
        }

        var icon = document.createElement('span');
        icon.className = 'md-alert__icon';
        icon.appendChild(makeIcon(item.icon));
        alert.appendChild(icon);

        var body = document.createElement('div');
        body.className = 'md-alert__body';

        var title = document.createElement('div');
        title.className = 'md-alert__title';
        title.textContent = text(item.title, 'Системное уведомление');
        body.appendChild(title);

        if (item.text) {
            var description = document.createElement('div');
            description.className = 'md-alert__text';
            description.textContent = item.text;
            body.appendChild(description);
        }
        alert.appendChild(body);

        var action = alertAction(item.action_label, item.action_url);
        if (action) {
            alert.appendChild(action);
        }
        return alert;
    }

    function renderAttention() {
        if (!runtime.node) {
            return;
        }
        var container = runtime.node.querySelector('[data-md-dash-alerts]');
        if (!container) {
            return;
        }

        if (!runtime.hasData) {
            container.innerHTML = '';
            var loading = document.createElement('div');
            loading.className = 'md-alert md-alert--info md-dash-alert';
            var loadingIcon = document.createElement('span');
            loadingIcon.className = 'md-alert__icon';
            loadingIcon.appendChild(makeIcon('loader-2'));
            loading.appendChild(loadingIcon);
            var loadingBody = document.createElement('div');
            loadingBody.className = 'md-alert__body';
            var loadingTitle = document.createElement('div');
            loadingTitle.className = 'md-alert__title';
            loadingTitle.textContent = 'Проверяем состояние систем…';
            loadingBody.appendChild(loadingTitle);
            loading.appendChild(loadingBody);
            container.appendChild(loading);
            return;
        }

        var items = (runtime.attention || []).slice();
        if (runtime.updateAlert) {
            items.push(runtime.updateAlert);
        }
        if (runtime.loadFailed && !items.length) {
            items.push({
                tone: 'warning',
                icon: 'plug-connected-x',
                title: 'Не удалось обновить данные дашборда',
                text: 'Показаны последние доступные значения. Попробуйте обновить страницу.',
                action_label: '',
                action_url: ''
            });
        }

        container.innerHTML = '';
        if (!items.length) {
            var healthy = document.createElement('div');
            healthy.className = 'md-alert md-alert--success md-dash-alert';
            var icon = document.createElement('span');
            icon.className = 'md-alert__icon';
            icon.appendChild(makeIcon('shield-check'));
            healthy.appendChild(icon);
            var body = document.createElement('div');
            body.className = 'md-alert__body';
            var title = document.createElement('div');
            title.className = 'md-alert__title';
            title.textContent = 'Ключевые системы в норме';
            body.appendChild(title);
            var description = document.createElement('div');
            description.className = 'md-alert__text';
            description.textContent = 'Активных ошибок нет, циклы работают, ресурсы сервера в пределах нормы.';
            body.appendChild(description);
            healthy.appendChild(body);
            container.appendChild(healthy);
            return;
        }

        items.slice(0, 5).forEach(function (item) {
            container.appendChild(buildAlert(item));
        });
    }

    // ---------------------------------------------------------------------
    // Activity feed (scripts and properties from X-Ray)
    // ---------------------------------------------------------------------

    function renderSkeleton(container) {
        container.innerHTML = '';
        var skeleton = document.createElement('div');
        skeleton.className = 'md-dash-skeleton';
        skeleton.setAttribute('aria-hidden', 'true');
        for (var i = 0; i < 3; i++) {
            var row = document.createElement('div');
            row.className = 'md-dash-skeleton__row';
            row.innerHTML = '<span class="md-dash-skeleton__dot"></span>' +
                '<span class="md-dash-skeleton__line md-dash-skeleton__line--wide"></span>' +
                '<span class="md-dash-skeleton__line md-dash-skeleton__line--short"></span>';
            skeleton.appendChild(row);
        }
        container.appendChild(skeleton);
    }

    function renderEmpty(container, message) {
        container.innerHTML = '';
        var empty = document.createElement('div');
        empty.className = 'md-dash-empty';
        empty.appendChild(makeIcon('inbox'));
        var label = document.createElement('span');
        label.textContent = message;
        empty.appendChild(label);
        container.appendChild(empty);
    }

    function activityItem(options) {
        var item = document.createElement('div');
        item.className = 'md-dash-activity__item';

        var dot = document.createElement('span');
        dot.className = 'md-dash-activity__dot';
        item.appendChild(dot);

        var main = document.createElement('div');
        main.className = 'md-dash-activity__main';

        var name = document.createElement('span');
        name.className = 'md-dash-activity__name';
        name.textContent = options.name;
        if (options.title) {
            name.title = options.title;
        }
        main.appendChild(name);

        if (options.meta) {
            var meta = document.createElement('span');
            meta.className = 'md-dash-activity__meta';
            meta.textContent = options.meta;
            main.appendChild(meta);
        }

        item.appendChild(main);

        if (options.value) {
            var value = document.createElement('span');
            value.className = 'md-dash-activity__value';
            value.textContent = options.value;
            if (options.title) {
                value.title = options.title;
            }
            item.appendChild(value);
        }

        return item;
    }

    function renderScripts(list, total) {
        var container = runtime.node.querySelector('[data-md-dash-list="scripts"]');
        if (!container) {
            return;
        }
        var items = Array.isArray(list) ? list.slice() : [];
        items = items.filter(function (item) {
            return item && item.SCRIPT;
        }).sort(function (left, right) {
            return String(right.EXECUTED || '').localeCompare(String(left.EXECUTED || ''));
        }).slice(0, 6);

        var counter = runtime.node.querySelector('[data-md-dash-count="scripts"]');
        if (counter) {
            counter.textContent = formatNumber(number(total) || items.length);
        }

        container.innerHTML = '';
        if (!items.length) {
            renderEmpty(container, 'Скрипты ещё не запускались');
            return;
        }

        items.forEach(function (item) {
            container.appendChild(activityItem({
                name: item.SCRIPT,
                meta: item.DESC || '',
                value: relativeTime(item.EXECUTED),
                title: item.DESC || ''
            }));
        });
    }

    function renderProperties(list, total) {
        var container = runtime.node.querySelector('[data-md-dash-list="properties"]');
        if (!container) {
            return;
        }
        var items = Array.isArray(list) ? list.slice() : [];
        items = items.filter(function (item) {
            return item && item.NAME;
        }).sort(function (left, right) {
            return String(right.UPDATE || '').localeCompare(String(left.UPDATE || ''));
        }).slice(0, 8);

        var counter = runtime.node.querySelector('[data-md-dash-count="properties"]');
        if (counter) {
            counter.textContent = formatNumber(number(total) || items.length);
        }

        container.innerHTML = '';
        if (!items.length) {
            renderEmpty(container, 'Изменений свойств пока нет');
            return;
        }

        items.forEach(function (item) {
            container.appendChild(activityItem({
                name: item.NAME,
                meta: relativeTime(item.UPDATE),
                value: text(item.VALUE),
                title: item.SOURCE ? 'Источник: ' + item.SOURCE : ''
            }));
        });
    }

    // ---------------------------------------------------------------------
    // Cycles
    // ---------------------------------------------------------------------

    function cycleStatusLabel(status) {
        if (status === 'running') {
            return 'Работает';
        }
        if (status === 'stopped') {
            return 'Остановлен';
        }
        if (status === 'disabled') {
            return 'Отключён';
        }
        return 'Нет данных';
    }

    function cycleStatusTone(status) {
        if (status === 'running') {
            return 'success';
        }
        if (status === 'stopped') {
            return 'danger';
        }
        if (status === 'offline') {
            return 'warning';
        }
        return 'neutral';
    }

    function legendItem(tone, label, value) {
        var item = document.createElement('span');
        item.className = 'md-dash-legend__item';

        var dot = document.createElement('span');
        dot.className = 'md-dash-legend__dot md-dash-legend__dot--' + tone;
        item.appendChild(dot);

        var name = document.createElement('span');
        name.textContent = label;
        item.appendChild(name);

        var count = document.createElement('span');
        count.className = 'md-dash-legend__value';
        count.textContent = formatNumber(value);
        item.appendChild(count);

        return item;
    }

    function renderCycleList(counts, items) {
        var list = runtime.node.querySelector('[data-md-dash-cycle-list]');
        if (!list) {
            return;
        }
        list.innerHTML = '';

        var broken = (items || []).filter(function (cycle) {
            return cycle.status === 'stopped' || cycle.status === 'offline';
        }).slice(0, 3);

        if (!broken.length) {
            var ok = document.createElement('li');
            ok.className = 'md-dash-cycle md-dash-cycle--ok';
            ok.appendChild(makeIcon('circle-check'));
            var okText = document.createElement('span');
            okText.textContent = counts.disabled > 0
                ? 'Все включённые циклы работают'
                : 'Все циклы работают';
            ok.appendChild(okText);
            list.appendChild(ok);
            return;
        }

        broken.forEach(function (cycle) {
            var item = document.createElement('li');
            item.className = 'md-dash-cycle';

            var main = document.createElement('div');
            main.className = 'md-dash-cycle__main';
            var title = document.createElement('span');
            title.className = 'md-dash-cycle__name';
            title.textContent = cycle.title;
            main.appendChild(title);
            var meta = document.createElement('span');
            meta.className = 'md-dash-cycle__meta';
            meta.textContent = cycle.run_label ? 'Последний запуск: ' + cycle.run_label : 'Нет данных о запуске';
            main.appendChild(meta);
            item.appendChild(main);

            var badge = document.createElement('span');
            badge.className = 'md-badge md-badge--' + cycleStatusTone(cycle.status);
            badge.textContent = cycleStatusLabel(cycle.status);
            item.appendChild(badge);

            list.appendChild(item);
        });
    }

    function renderCycles(cycles) {
        if (!runtime.node || !cycles) {
            return;
        }
        var counts = cycles.counts || {};
        var total = number(counts.total);
        var running = number(counts.running);
        var stopped = number(counts.stopped);
        var offline = number(counts.offline);
        var disabled = number(counts.disabled);
        var broken = stopped + offline;
        var activeTotal = Math.max(0, total - disabled);

        setField('cycles-works', formatNumber(running));
        setField('cycles-total', formatNumber(activeTotal));
        setField('cycles-works-2', formatNumber(running));
        setField('cycles-total-2', formatNumber(activeTotal));
        setField(
            'cycles-meta',
            broken > 0
                ? broken + ' ' + plural(broken, ['цикл не работает', 'цикла не работают', 'циклов не работают'])
                : (disabled > 0 ? disabled + ' ' + plural(disabled, ['отключён', 'отключены', 'отключены']) : 'Все циклы работают')
        );

        var legend = runtime.node.querySelector('[data-md-dash-cycle-legend]');
        if (legend) {
            legend.innerHTML = '';
            legend.appendChild(legendItem('ok', 'Работают', running));
            if (broken > 0) {
                legend.appendChild(legendItem('fail', 'Не работают', broken));
            }
            if (disabled > 0) {
                legend.appendChild(legendItem('idle', 'Отключены', disabled));
            }
        }

        renderCycleList(counts, cycles.items || []);

        if (window.MDJDashboardCharts) {
            window.MDJDashboardCharts.renderCycles(cycles);
        }
    }

    // ---------------------------------------------------------------------
    // Resources
    // ---------------------------------------------------------------------

    function toneFor(percent) {
        if (percent === null || typeof percent === 'undefined' || !Number.isFinite(Number(percent))) {
            return 'neutral';
        }
        var value = Number(percent);
        if (value >= 90) {
            return 'danger';
        }
        if (value >= 75) {
            return 'warning';
        }
        return 'primary';
    }

    function setMeter(name, percent, value, hint) {
        if (!runtime.node) {
            return;
        }
        var meter = runtime.node.querySelector('[data-md-dash-meter="' + name + '"]');
        if (!meter) {
            return;
        }
        var tone = toneFor(percent);
        var safePercent = percent === null || typeof percent === 'undefined' ? 0 : Math.max(0, Math.min(100, Number(percent)));
        meter.classList.remove('md-meter--primary', 'md-meter--success', 'md-meter--warning', 'md-meter--danger', 'md-meter--info', 'md-meter--neutral');
        meter.classList.add('md-meter--' + tone);

        var valueNode = meter.querySelector('.md-meter__value');
        if (valueNode) {
            valueNode.textContent = value;
        }
        var hintNode = meter.querySelector('.md-meter__hint');
        if (hintNode) {
            hintNode.textContent = hint;
        }
        var progress = meter.querySelector('.md-progress');
        if (progress) {
            progress.classList.remove('md-progress--primary', 'md-progress--success', 'md-progress--warning', 'md-progress--danger', 'md-progress--info', 'md-progress--neutral');
            progress.classList.add('md-progress--' + tone);
            progress.setAttribute('aria-valuenow', String(Math.round(safePercent)));
        }
        var bar = meter.querySelector('.md-progress__bar');
        if (bar) {
            bar.style.width = safePercent + '%';
        }
    }

    function renderResources(system) {
        if (!runtime.node || !system) {
            return;
        }
        var cpu = system.cpu || {};
        var memory = system.memory || {};
        var disk = system.disk || {};

        setMeter(
            'cpu',
            cpu.percent,
            formatPercent(cpu.percent),
            cpu.cores ? cpu.cores + ' ' + plural(cpu.cores, ['ядро', 'ядра', 'ядер']) +
                (cpu.load1 !== null && typeof cpu.load1 !== 'undefined' ? ' · load ' + formatNumber(cpu.load1, 2) : '') : 'Нет данных'
        );
        setMeter(
            'memory',
            memory.percent,
            formatPercent(memory.percent),
            memory.total ? formatBytes(memory.used) + ' из ' + formatBytes(memory.total) : 'Нет данных'
        );
        setMeter(
            'disk',
            disk.percent,
            formatPercent(disk.percent),
            disk.total ? 'Свободно ' + formatBytes(disk.free) + ' из ' + formatBytes(disk.total) : 'Нет данных'
        );

        setField('load-1', cpu.load1 !== null && typeof cpu.load1 !== 'undefined' ? formatNumber(cpu.load1, 2) : '—');
        setField('load-5', cpu.load5 !== null && typeof cpu.load5 !== 'undefined' ? formatNumber(cpu.load5, 2) : '—');
        setField('load-15', cpu.load15 !== null && typeof cpu.load15 !== 'undefined' ? formatNumber(cpu.load15, 2) : '—');

        var status = runtime.node.querySelector('[data-md-dash-resource-status]');
        if (status) {
            var values = [cpu.percent, memory.percent, disk.percent].filter(function (value) {
                return value !== null && typeof value !== 'undefined' && Number.isFinite(Number(value));
            });
            var max = values.length ? Math.max.apply(null, values) : null;
            var tone = toneFor(max);
            var label = max === null ? 'Нет данных' : (tone === 'danger' ? 'Критично' : (tone === 'warning' ? 'Внимание' : 'В норме'));
            status.textContent = label;
            status.classList.toggle('is-ok', tone === 'primary');
            status.classList.toggle('is-danger', tone === 'danger');
            status.classList.toggle('is-warning', tone === 'warning');
        }

        if (window.MDJDashboardCharts) {
            window.MDJDashboardCharts.renderResources(system);
        }
    }

    // ---------------------------------------------------------------------
    // Errors
    // ---------------------------------------------------------------------

    function renderErrors(errors) {
        if (!runtime.node || !errors) {
            return;
        }
        var badge = runtime.node.querySelector('[data-md-dash-errors-badge]');
        if (badge) {
            badge.textContent = errors.active > 0 ? errors.active + ' активных' : 'Нет активных';
            badge.classList.toggle('is-danger', errors.active > 0);
            badge.classList.toggle('is-ok', errors.active === 0);
        }

        var container = runtime.node.querySelector('[data-md-dash-errors]');
        if (!container) {
            return;
        }
        container.innerHTML = '';

        var items = Array.isArray(errors.items) ? errors.items : [];
        if (!items.length) {
            var empty = document.createElement('div');
            empty.className = 'md-dash-errors-empty';
            empty.appendChild(makeIcon('shield-check'));
            var emptyText = document.createElement('span');
            emptyText.textContent = 'Активных ошибок нет';
            empty.appendChild(emptyText);
            container.appendChild(empty);
            return;
        }

        var list = document.createElement('ul');
        list.className = 'md-dash-error-list';
        items.slice(0, 3).forEach(function (error) {
            var item = document.createElement('li');
            item.className = 'md-dash-error' + (error.active ? ' is-active' : '');

            var icon = document.createElement('span');
            icon.className = 'md-dash-error__icon';
            icon.appendChild(makeIcon(error.active ? 'alert-circle' : 'history'));
            item.appendChild(icon);

            var body = document.createElement('div');
            body.className = 'md-dash-error__body';
            var title = document.createElement('span');
            title.className = 'md-dash-error__title';
            title.textContent = error.title || error.code || 'Ошибка без названия';
            body.appendChild(title);
            var meta = document.createElement('span');
            meta.className = 'md-dash-error__meta';
            meta.textContent = (error.active ? 'Активна' : 'История') + (error.latest_label ? ' · ' + error.latest_label : '');
            body.appendChild(meta);
            item.appendChild(body);

            if (error.active) {
                var dot = document.createElement('span');
                dot.className = 'md-dash-error__dot';
                dot.setAttribute('aria-label', 'Активная ошибка');
                item.appendChild(dot);
            }
            list.appendChild(item);
        });
        container.appendChild(list);
    }

    // ---------------------------------------------------------------------
    // Counts, database and system
    // ---------------------------------------------------------------------

    function applyCounts(counts) {
        setField('objects', formatNumber(counts.objects));
        setField('classes', formatNumber(counts.classes));
        setField('classes-word', plural(counts.classes, ['класс', 'класса', 'классов']));
        setField('locations', formatNumber(counts.locations));
        setField('locations-word', plural(counts.locations, ['локация', 'локации', 'локаций']));
        setField('active-objects', formatNumber(counts.active_objects));
        setField('active-window', counts.active_window_minutes || 15);

        if (counts.devices_available) {
            setField('devices-online', formatNumber(counts.devices_online));
            setField('devices-total', formatNumber(counts.devices_total));
            setField('devices-offline', formatNumber(counts.devices_offline));
            setField('devices-meta', counts.devices_offline > 0
                ? counts.devices_offline + ' ' + plural(counts.devices_offline, ['offline', 'offline', 'offline'])
                : 'Все устройства online');
        } else {
            setField('devices-online', '—');
            setField('devices-total', '—');
            setField('devices-offline', '—');
            setField('devices-meta', 'Модуль Online не установлен');
        }
    }

    function applyDb(db) {
        if (!db) {
            return;
        }
        var connections = db.connections || {};
        var usage = number(db.connection_usage);
        var level = db.level === 'danger' ? 'danger' : (db.level === 'warning' ? 'warning' : 'ok');
        var freeConnections = Math.max(0, number(connections.max) - number(connections.connected));

        setField('db-rate', formatNumber(db.qps, 1));
        setField('db-usage', formatNumber(usage, 1));
        setField('db-max', formatNumber(connections.max));
        setField('db-size', db.size_label || formatBytes(db.size));
        setField('db-tables', formatNumber(db.tables));
        setField('db-running', formatNumber(connections.running));
        setField('db-connected', formatNumber(connections.connected));
        setField('db-cached', formatNumber(connections.cached));
        setField('db-free', formatNumber(freeConnections));

        var usageNode = runtime.node.querySelector('[data-md-dash-db-usage]');
        if (usageNode) {
            usageNode.textContent = formatNumber(usage, 1);
        }
        var rateNode = runtime.node.querySelector('[data-md-dash-db-rate]');
        if (rateNode) {
            rateNode.textContent = formatNumber(db.qps, 1);
        }
        var mapping = {
            'db-running': connections.running,
            'db-connected': connections.connected,
            'db-cached': connections.cached,
            'db-max': connections.max
        };
        Object.keys(mapping).forEach(function (key) {
            var node = runtime.node.querySelector('[data-md-dash-' + key + ']');
            if (node) {
                node.textContent = formatNumber(mapping[key]);
            }
        });

        var bar = runtime.node.querySelector('[data-md-dash-db-bar]');
        var progress = runtime.node.querySelector('[data-md-dash-db-progress]');
        if (bar) {
            bar.style.width = Math.max(usage, 0.5) + '%';
            bar.classList.toggle('is-warning', level === 'warning');
            bar.classList.toggle('is-danger', level === 'danger');
        }
        if (progress) {
            progress.setAttribute('aria-valuenow', String(Math.round(usage)));
        }

        var status = runtime.node.querySelector('[data-md-dash-db-status]');
        if (status) {
            status.textContent = text(db.status_text, 'Нагрузка в норме');
            status.classList.toggle('is-ok', level === 'ok');
            status.classList.toggle('is-warning', level === 'warning');
            status.classList.toggle('is-danger', level === 'danger');
        }

        var note = runtime.node.querySelector('[data-md-dash-db-note]');
        if (note) {
            var source = db.qps_source === 'live' ? 'живой замер' : 'среднее с запуска';
            note.textContent = 'Обновлено ' + timeOnly(db.updated_at || (runtime.data && runtime.data.generated_at)) + ' · ' + source;
        }
    }

    function applySystem(system) {
        if (!system) {
            return;
        }
        setField('php-version', system.php);
        setField('os', system.os);
        setField('host', system.host);
        setField('uptime', system.uptime && system.uptime.label ? system.uptime.label : '—');
        setField('tables', formatNumber((runtime.data && runtime.data.db) ? runtime.data.db.tables : 0));
    }

    function applyDashboard(data) {
        if (!runtime.node || !data) {
            return;
        }
        runtime.data = data;
        runtime.hasData = true;
        runtime.loadFailed = false;
        runtime.attention = Array.isArray(data.attention) ? data.attention : [];

        applyCounts(data.counts || {});
        applyDb(data.db || {});
        applySystem(data.system || {});
        renderResources(data.system || {});
        renderCycles(data.cycles || {});
        renderErrors(data.errors || {});
        renderAttention();
        updateHealth();
        updateUpdatedAt(data.generated_at);

        var activity = data.activity || {};
        setField('activity-properties', formatNumber(activity.properties_total));
        setField('activity-scripts', formatNumber(activity.scripts_total));
        if (window.MDJDashboardCharts) {
            window.MDJDashboardCharts.renderActivity(activity);
        }
    }

    // ---------------------------------------------------------------------
    // Custom block and update check
    // ---------------------------------------------------------------------

    function renderMyBlock(data) {
        if (!runtime.node) {
            return;
        }
        var card = runtime.node.querySelector('#myBlockOnDash');
        if (!card) {
            return;
        }
        if (!data || Number(data.status) !== 1) {
            card.hidden = true;
            return;
        }

        card.hidden = false;
        var title = runtime.node.querySelector('#myBlockOnDash_title');
        var body = runtime.node.querySelector('#myBlockOnDash_body');
        var more = runtime.node.querySelector('#more_myBlockOnDash_body');

        if (title) {
            title.textContent = text(data.name, 'Мой блок');
        }
        if (body) {
            body.innerHTML = data.data && data.data.data ? data.data.data : '';
        }
        if (more) {
            more.innerHTML = '';
            if (data.moreinfo && typeof data.moreinfo === 'object') {
                Object.keys(data.moreinfo).forEach(function (label) {
                    var raw = String(data.moreinfo[label] || '');
                    var parts = raw.split('|color:');
                    var value = parts[0] || '—';
                    var color = parts[1] || '';

                    var item = document.createElement('li');
                    var name = document.createElement('span');
                    name.textContent = label;
                    item.appendChild(name);

                    var badge = document.createElement('span');
                    badge.className = 'badge';
                    badge.textContent = value;
                    if (color) {
                        badge.style.setProperty('--md-badge-bg', color);
                    }
                    item.appendChild(badge);
                    more.appendChild(item);
                });
            }
        }

        if (window.MDJAdminUI && typeof window.MDJAdminUI.initBootstrapWidgets === 'function') {
            window.MDJAdminUI.initBootstrapWidgets(card);
        }

        if (data.reload == true) {
            var delay = Math.max(10, number(data.reload_time) || 60) * 1000;
            clearTimeout(runtime.blockTimer);
            runtime.blockTimer = setTimeout(loadMyBlock, delay);
        }
    }

    async function loadUpdates() {
        try {
            var data = await fetchJson(urls().updates);
            if (data && String(data.needUpdate) === '1') {
                runtime.updateAlert = {
                    tone: 'info',
                    icon: 'download',
                    title: 'Доступно обновление системы',
                    text: 'Установите обновление, чтобы получить исправления и новые возможности.',
                    action_label: 'Открыть обновление',
                    action_url: '?action=saverestore'
                };
                var badge = document.getElementById('updates-check-system');
                if (badge) {
                    badge.style.display = '';
                }
            } else {
                runtime.updateAlert = null;
            }
            renderAttention();
        } catch (error) {
            // Update check is optional and may be disabled on local installs.
        }
    }

    // ---------------------------------------------------------------------
    // Loading
    // ---------------------------------------------------------------------

    function bindRefresh() {
        if (!runtime.node) {
            return;
        }
        var button = runtime.node.querySelector('[data-md-dash-refresh]');
        if (!button || button.dataset.mdDashBound === '1') {
            return;
        }
        button.dataset.mdDashBound = '1';
        button.addEventListener('click', function () {
            loadAll(true);
        });
    }

    function setLoading(isLoading) {
        runtime.loading = isLoading;
        if (!runtime.node) {
            return;
        }
        var button = runtime.node.querySelector('[data-md-dash-refresh]');
        if (button) {
            button.disabled = isLoading;
            button.classList.toggle('is-loading', isLoading);
            button.setAttribute('aria-busy', isLoading ? 'true' : 'false');
        }
    }

    async function loadDashboard(manual) {
        try {
            var url = urls().dashboard + (manual ? '&fresh=1' : '') + '&_=' + Date.now();
            var data = await fetchJson(url);
            if (data && data.generated_ts) {
                applyDashboard(data);
            } else {
                runtime.loadFailed = true;
                renderAttention();
                updateHealth();
            }
        } catch (error) {
            runtime.loadFailed = true;
            renderAttention();
            updateHealth();
        }
    }

    async function loadActivity() {
        var results = await Promise.allSettled([
            fetchJson(urls().scripts),
            fetchJson(urls().properties)
        ]);
        var scripts = results[0].status === 'fulfilled' ? results[0].value : null;
        var properties = results[1].status === 'fulfilled' ? results[1].value : null;
        if (scripts && Array.isArray(scripts.LIST)) {
            renderScripts(scripts.LIST, scripts.TOTAL);
        } else {
            renderScripts([], 0);
        }
        if (properties && Array.isArray(properties.LIST)) {
            renderProperties(properties.LIST, properties.TOTAL);
        } else {
            renderProperties([], 0);
        }
    }

    async function loadMyBlock() {
        try {
            var data = await fetchJson(urls().myBlock);
            renderMyBlock(data);
        } catch (error) {
            renderMyBlock(null);
        }
    }

    async function loadAll(manual) {
        if (!runtime.node) {
            return;
        }
        if (!document.body.contains(runtime.node)) {
            clearInterval(runtime.refreshTimer);
            clearTimeout(runtime.blockTimer);
            runtime.refreshTimer = 0;
            runtime.blockTimer = 0;
            runtime.node = null;
            return;
        }
        if (runtime.loading && !manual) {
            return;
        }
        if (document.visibilityState === 'hidden' && !manual) {
            return;
        }

        setLoading(true);
        if (manual) {
            var scripts = runtime.node.querySelector('[data-md-dash-list="scripts"]');
            var properties = runtime.node.querySelector('[data-md-dash-list="properties"]');
            if (scripts) {
                renderSkeleton(scripts);
            }
            if (properties) {
                renderSkeleton(properties);
            }
        }

        await Promise.allSettled([
            loadDashboard(manual).catch(function () {
                runtime.loadFailed = true;
            }),
            loadActivity().catch(function () {}),
            loadMyBlock().catch(function () {}),
            loadUpdates().catch(function () {})
        ]);
        setLoading(false);

        if (window.MDJAdminUI && typeof window.MDJAdminUI.initBootstrapWidgets === 'function' && runtime.node) {
            window.MDJAdminUI.initBootstrapWidgets(runtime.node);
        }
    }

    function init(root) {
        var node = (root || document).querySelector('[data-md-dashboard]');
        if (!node) {
            return;
        }

        if (runtime.node === node) {
            return;
        }

        clearInterval(runtime.refreshTimer);
        clearTimeout(runtime.blockTimer);
        if (window.MDJDashboardCharts && typeof window.MDJDashboardCharts.destroy === 'function') {
            window.MDJDashboardCharts.destroy();
        }

        runtime.node = node;
        runtime.data = null;
        runtime.hasData = false;
        runtime.attention = [];
        runtime.updateAlert = null;
        runtime.loadFailed = false;

        updateDateLine();
        bindRefresh();
        loadAll(false);

        runtime.refreshTimer = setInterval(function () {
            if (document.visibilityState !== 'hidden') {
                loadAll(false);
            }
        }, 60000);
    }

    if (window.MDJAdminUI && typeof window.MDJAdminUI.registerModuleUI === 'function') {
        window.MDJAdminUI.registerModuleUI('dashboard', { init: init });
    } else {
        window.MDJDashboard = { init: init };
    }
})(window, document);
