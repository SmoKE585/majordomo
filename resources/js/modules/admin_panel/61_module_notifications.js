/* 2026-10-25 — общий центр уведомлений. Открытие и переход в модуль
 * не означают прочтение: запись скрывается только после явного действия. */
(function (window, document) {
    'use strict';

    var filter = '';
    var offset = 0;
    var drawerRequest = 0;
    var refreshRequest = 0;
    var bound = false;
    var severityLabels = {info: 'Информация', success: 'Успешно', warning: 'Предупреждение', danger: 'Ошибка'};
    var icons = {info: 'info-circle', success: 'circle-check', warning: 'alert-triangle', danger: 'alert-circle'};

    function element(tag, className, text) {
        var node = document.createElement(tag);
        node.className = className || '';
        if (typeof text === 'string') node.textContent = text;
        return node;
    }

    function internalUrl(url) {
        if (typeof url !== 'string') return '';
        try {
            var decoded = decodeURIComponent(url);
            return decoded.startsWith('/') && !decoded.startsWith('//') && !/[\\\x00-\x20\x7f]/.test(decoded) ? url : '';
        } catch (error) { return ''; }
    }

    function renderList(target, items) {
        if (!target) return;
        var signature = JSON.stringify(items);
        if (target.dataset.mdNotificationsRendered === signature) return;
        target.dataset.mdNotificationsRendered = signature;
        var focused = document.activeElement;
        var focusId = focused && target.contains(focused) ? focused.getAttribute('data-md-notification-read') : null;
        target.replaceChildren();
        if (!items.length) {
            target.append(element('p', 'text-body-secondary mb-0', 'Непрочитанных уведомлений нет.'));
            return;
        }
        var preview = target.hasAttribute('data-md-notifications-preview');
        items.forEach(function (note) {
            var type = Object.hasOwn(icons, note.type) ? note.type : 'info';
            var row = element('article', 'md-alert md-alert--' + type + ' md-notification');
            if (preview) row.classList.add('md-notification--preview');
            row.dataset.mdNotificationId = String(note.id);
            var icon = element('i', 'ti ti-' + icons[type] + ' md-alert__icon');
            icon.setAttribute('aria-hidden', 'true');
            row.append(icon);
            var body = element('div', 'md-alert__body');
            var meta = element('div', 'md-notification__meta');
            var moduleLink = element('a', 'md-badge md-badge--outline', note.module_title || note.module);
            moduleLink.href = internalUrl(note.module_url) || '#';
            meta.append(moduleLink, element('span', 'small', severityLabels[type]));
            var date = new Date(String(note.added || '').replace(' ', 'T'));
            var time = element('time', 'small', Number.isNaN(date.getTime()) ? String(note.added || '') : date.toLocaleString('ru-RU'));
            if (!Number.isNaN(date.getTime())) time.dateTime = date.toISOString();
            meta.append(time);
            body.append(meta);
            if (note.title) body.append(element('div', 'md-alert__title', note.title));
            body.append(element('div', 'md-alert__text md-notification__text', note.message));
            if (note.details && !preview) {
                var details = element('details', 'md-notification__details');
                details.append(element('summary', '', 'Подробности'), element('div', 'md-notification__text', note.details));
                body.append(details);
            }
            var actions = element('div', 'md-alert__actions');
            var url = internalUrl(note.url);
            if (url && !preview) {
                var link = element('a', 'md-btn md-btn--secondary md-btn--sm', note.label || 'Подробнее');
                link.href = url;
                actions.append(link);
            }
            var read = element('button', preview ? 'md-icon-btn' : 'md-btn md-btn--ghost md-btn--sm', preview ? '' : 'Прочитано');
            if (preview) {
                var readIcon = element('i', 'ti ti-check');
                readIcon.setAttribute('aria-hidden', 'true');
                read.append(readIcon);
                read.title = 'Отметить прочитанным';
            }
            read.type = 'button';
            read.dataset.mdNotificationRead = String(note.id);
            read.setAttribute('aria-label', 'Отметить прочитанным: ' + (note.title || note.message));
            actions.append(read);
            body.append(actions);
            row.append(body);
            target.append(row);
        });
        if (focusId) {
            var next = Array.from(target.querySelectorAll('[data-md-notification-read]')).find(function (button) {
                return button.dataset.mdNotificationRead === focusId;
            }) || target.querySelector('[data-md-notification-read]');
            if (next) next.focus();
        }
    }

    function showError(target, error) {
        if (!target) return;
        target.hidden = false;
        delete target.dataset.mdNotificationsRendered;
        target.replaceChildren(element('p', 'text-danger mb-0', 'Не удалось загрузить уведомления: ' + error.message));
    }

    function updateSummary(data) {
        document.querySelectorAll('[data-md-notifications-open]').forEach(function (button) {
            if (!button.classList.contains('md-notifications-bell')) return;
            button.setAttribute('aria-label', 'Уведомления модулей: ' + data.total + ' непрочитанных');
            button.dataset.severity = data.severity;
            var badge = button.querySelector('[data-md-notifications-count]');
            if (badge) {
                badge.textContent = data.total > 99 ? '99+' : String(data.total);
                badge.hidden = data.total === 0;
            }
        });
        document.querySelectorAll('[data-md-notifications-module-open]').forEach(function (button) {
            var name = button.dataset.mdNotificationsModuleOpen;
            var count = data.counts[name] || 0;
            var countBadge = button.querySelector('.md-badge');
            countBadge.textContent = String(count);
            countBadge.className = 'md-badge md-badge--' + (data.levels[name] || 'info');
            button.hidden = count === 0;
            button.setAttribute('aria-label', 'Уведомления: ' + name + ', непрочитанных: ' + count);
        });
        document.querySelectorAll('[data-md-notifications-preview]').forEach(function (target) {
            renderList(target, data.items.slice(0, 5));
        });
    }

    function isOpen() {
        return window.MDJAdminDrawerHost && window.MDJAdminDrawerHost.getCurrentOwner() === 'module-notifications';
    }

    async function loadDrawer() {
        var request = ++drawerRequest;
        var content = document.getElementById('mdNotificationsContent');
        if (!content || !isOpen()) return;
        var status = content.querySelector('[data-md-notifications-status]');
        content.setAttribute('aria-busy', 'true');
        try {
            var data = await window.MDJAdminApi.notifications(filter, offset);
            if (request !== drawerRequest || !isOpen()) return;
            if (offset && offset >= data.total) {
                offset = Math.max(0, Math.floor((data.total - 1) / 50) * 50);
                return loadDrawer();
            }
            renderList(content.querySelector('[data-md-notifications-list]'), data.items);
            status.textContent = 'Непрочитанных: ' + data.total + '. Открытие списка не отмечает их прочитанными.';
            content.querySelector('[data-md-notifications-pages]').hidden = data.total <= 50;
            content.querySelector('[data-md-notifications-range]').textContent = (data.total ? offset + 1 : 0) + '–' + (offset + data.items.length) + ' из ' + data.total;
            content.querySelector('[data-md-notifications-page="-1"]').disabled = offset === 0;
            content.querySelector('[data-md-notifications-page="1"]').disabled = offset + data.items.length >= data.total;
        } catch (error) {
            if (request === drawerRequest) status.textContent = 'Не удалось загрузить уведомления: ' + error.message;
        } finally {
            if (request === drawerRequest) content.removeAttribute('aria-busy');
        }
    }

    async function refresh() {
        if (!document.getElementById('mdNotificationsContent') || !window.MDJAdminApi) return;
        var request = ++refreshRequest;
        var moduleTarget = document.querySelector('[data-md-notifications-module]');
        try {
            var data = await window.MDJAdminApi.notifications('', 0);
            if (request !== refreshRequest) return;
            updateSummary(data);
            if (moduleTarget && moduleTarget.isConnected) {
                var moduleData = await window.MDJAdminApi.notifications(moduleTarget.dataset.mdNotificationsModule, 0);
                if (request === refreshRequest && moduleTarget.isConnected) {
                    renderList(moduleTarget, moduleData.items);
                    moduleTarget.hidden = moduleData.items.length === 0;
                }
            }
        } catch (error) {
            if (request !== refreshRequest) return;
            document.querySelectorAll('[data-md-notifications-preview]').forEach(function (target) { showError(target, error); });
            showError(moduleTarget, error);
        }
        if (request === refreshRequest && isOpen()) await loadDrawer();
    }

    function open(module) {
        var content = document.getElementById('mdNotificationsContent');
        if (!content || !window.MDJAdminDrawerHost) return;
        filter = typeof module === 'string' ? module : '';
        offset = 0;
        var mobileMenu = document.querySelector('.md-admin-sidebar [data-md-admin-sidebar-close]');
        if (mobileMenu) mobileMenu.click();
        var list = content.querySelector('[data-md-notifications-list]');
        delete list.dataset.mdNotificationsRendered;
        list.replaceChildren(element('p', 'text-body-secondary mb-0', 'Загружаем уведомления…'));
        content.querySelector('[data-md-notifications-status]').textContent = 'Загружаем уведомления…';
        content.querySelector('[data-md-notifications-pages]').hidden = true;
        window.MDJAdminDrawerHost.open({owner: 'module-notifications', title: 'Уведомления',
            subtitle: filter ? 'Модуль: ' + filter : 'Все модули', body: content, width: '620px'});
        loadDrawer();
    }

    async function dismiss(id, button) {
        if (!window.MDJAdminApi) return;
        if (button) button.disabled = true;
        try {
            await window.MDJAdminApi.dismissNotification(id);
            document.querySelectorAll('[data-md-notification-id="' + Number(id) + '"]').forEach(function (node) { node.remove(); });
            // Старые шаблоны ещё могут вызвать dismissNotification(id).
            var legacy = document.getElementById('notification' + Number(id));
            if (legacy) legacy.remove();
            await refresh();
        } catch (error) {
            if (window.mdjShowErrorToast) window.mdjShowErrorToast('Не удалось отметить уведомление прочитанным: ' + error.message);
            if (button) button.disabled = false;
        }
    }

    function init() {
        if (!document.getElementById('mdNotificationsContent')) return;
        window.dismissNotification = function (id) { dismiss(id); return false; };
        if (!bound) {
            bound = true;
            document.addEventListener('click', function (event) {
                var trigger = event.target.closest('[data-md-notifications-open], [data-md-notifications-module-open]');
                if (trigger) {
                    event.preventDefault();
                    open(trigger.dataset.mdNotificationsModuleOpen || '');
                    return;
                }
                var read = event.target.closest('[data-md-notification-read]');
                if (read) { event.preventDefault(); dismiss(Number(read.dataset.mdNotificationRead), read); return; }
                var page = event.target.closest('[data-md-notifications-page]');
                if (page) { offset = Math.max(0, offset + Number(page.dataset.mdNotificationsPage) * 50); loadDrawer(); return; }
                if (event.target.closest('[data-md-notifications-refresh]')) refresh();
            });
            document.addEventListener('visibilitychange', function () { if (!document.hidden) refresh(); });
            window.setInterval(function () { if (!document.hidden) refresh(); }, 60000);
        }
        refresh();
    }

    window.MDJModuleNotifications = {open: open, refresh: refresh, dismiss: dismiss};
    window.MDJAdminUI.registerModuleUI('module-notifications', {init: init});
})(window, document);
