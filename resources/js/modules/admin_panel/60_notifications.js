/* 2026-10-23 — сообщения журнала приходят через общий WebSocket без HTTP-опроса;
 * подписка восстанавливается при подключении, повторная доставка отсеивается.
 * 2026-10-22 — единые уведомления UI-кита. */
(function (window, document, $) {
    'use strict';

    var latestMessageSocket = null;
    var seenMessages = new Set();
    var messageEvents = ['SAY', 'ASK', 'SAYTO'];
    var flashNotificationsShown = false;

    function safeJsonParse(data, fallback) {
        if (data && typeof data === 'object') {
            return data;
        }
        try {
            return JSON.parse(data);
        } catch (e) {
            return typeof fallback === 'undefined' ? null : fallback;
        }
    }

    function applyDbBadgeState($el, value, warnThreshold, dangerThreshold) {
        $el.removeClass('label-success label-warning label-danger').css('cursor', 'help');
        if (value <= warnThreshold) {
            $el.addClass('label-success');
        } else if (value < dangerThreshold) {
            $el.addClass('label-warning');
        } else {
            $el.addClass('label-danger');
        }
    }

    function normalizeToastType(type) {
        var normalized = (type || 'info').toString().toLowerCase();
        if (normalized === 'ok') {
            normalized = 'success';
        } else if (normalized === 'error' || normalized === 'fail') {
            normalized = 'danger';
        } else if (normalized === 'warn') {
            normalized = 'warning';
        }

        return ['success', 'danger', 'warning', 'info'].includes(normalized) ? normalized : 'info';
    }

    function sanitizeFlashQuery() {
        if (!window.history || typeof window.history.replaceState !== 'function' || !window.location) {
            return;
        }

        var url = new URL(window.location.href);
        var keys = ['ok_msg', 'err_msg', 'notify_msg', 'notify_type', 'notify_title'];
        var changed = false;

        keys.forEach(function (key) {
            if (url.searchParams.has(key)) {
                url.searchParams.delete(key);
                changed = true;
            }
        });

        if (changed) {
            window.history.replaceState({}, document.title, url.pathname + (url.search ? url.search : '') + url.hash);
        }
    }

    function showToast(notification) {
        if (!window.MDJUIToast || !notification || !(notification.message || notification.text || notification.title)) {
            return false;
        }

        window.MDJUIToast.show({
            type: normalizeToastType(notification.type),
            title: notification.title || '',
            text: notification.message || notification.text || '',
            duration: typeof notification.hideAfter === 'number' ? notification.hideAfter : notification.duration
        });

        return true;
    }

    function showFlashNotifications(force) {
        if (!$) {
            return;
        }

        if (flashNotificationsShown && !force) {
            return;
        }

        var config = window.MDJAdminConfig || {};
        var notifications = Array.isArray(config.flashNotifications) ? config.flashNotifications : [];

        if (notifications.length) {
            notifications.forEach(showToast);
        }

        flashNotificationsShown = true;
        config.flashNotifications = [];
        window.MDJAdminConfig = config;

        if (config.sanitizeFlashQuery) {
            sanitizeFlashQuery();
            config.sanitizeFlashQuery = false;
        }
    }

    function isAdminShell() {
        return typeof window.ROOTHTML === 'string' && window.ROOTHTML !== '';
    }

    // Сохраняем старое имя для внешних модулей. Оно обеспечивает подписку,
    // но больше не запускает запросы и таймеры опроса журнала.
    function checkLatestMessage() {
        var socket = window.wsSocket;
        if (!isAdminShell() || !socket || socket.readyState !== 1 || latestMessageSocket === socket) {
            return;
        }

        socket.send(JSON.stringify({action: 'Subscribe', data: {TYPE: 'events', EVENTS: messageEvents}}));
        latestMessageSocket = socket;
    }

    function receiveMessage(event) {
        if (!isAdminShell()) return;
        var response = event.detail;
        if (!response || response.action !== 'events') return;
        var payload = safeJsonParse(response.data);
        var notification = payload && payload.EVENT_DATA;
        if (!notification || typeof notification.NAME !== 'string'
            || !messageEvents.includes(notification.NAME.toUpperCase())) return;
        var note = notification.VALUE;
        if (!note || typeof note.message !== 'string' || !note.message.trim() || note.member_id) return;

        // SAY и SAYTO содержат ID записи. У ASK его нет: повтор кадра отличаем
        // по запуску сервера и номеру события, не склеивая одинаковые вопросы.
        var identity = null;
        if (/^[1-9]\d*$/.test(String(note.id))) {
            identity = 'id:' + note.id;
        } else if (typeof response.epoch === 'string' && Number.isSafeInteger(response.sequence)) {
            identity = response.epoch + ':' + response.sequence;
        }
        if (identity !== null && seenMessages.has(identity)) return;
        if (!showToast({title: 'Журнал сообщений', message: note.message, type: 'info'})) return;

        if (identity !== null) {
            seenMessages.add(identity);
            // Ограничиваем память вкладки, которая может быть открыта неделями.
            if (seenMessages.size > 256) seenMessages.delete(seenMessages.values().next().value);
        }
    }

    document.addEventListener('md:ws-connected', checkLatestMessage);
    document.addEventListener('md:ws-disconnected', function () {
        latestMessageSocket = null;
    });
    document.addEventListener('md:ws-data', receiveMessage);

    function bindAdminShellHandlers() {
        if (!$) {
            return;
        }
        checkLatestMessage();
        showFlashNotifications();
        $(document).on('click', '#btnFilterCloseSearch', function () {
            $('#filter_modules').val('');
            window.filterSearch();
        });
        $(document).on('click', '#stopLoadBtnPreloader', function () {
            $('#preloader').hide();
        });
        $(document).on('click', '#linkToggleLeftPanel', function (e) {
            e.preventDefault();
            return window.leftPanelToggle();
        });
        $(document).on('click', '#linkConsoleDebug', function (e) {
            e.preventDefault();
            return window.consoleDebugToggle();
        });
    }

    window.safeJsonParse = safeJsonParse;
    window.applyDbBadgeState = applyDbBadgeState;
    window.checkLatestMessage = checkLatestMessage;
    window.mdjShowToast = showToast;
    window.mdjShowSuccessToast = function (message, title) {
        return showToast({message: message, title: title || '', type: 'success'});
    };
    window.mdjShowErrorToast = function (message, title) {
        return showToast({message: message, title: title || '', type: 'error'});
    };
    window.mdjShowWarningToast = function (message, title) {
        return showToast({message: message, title: title || '', type: 'warning'});
    };
    window.mdjShowInfoToast = function (message, title) {
        return showToast({message: message, title: title || '', type: 'info'});
    };
    window.showFlashNotifications = showFlashNotifications;

    window.MDJAdminUI = window.MDJAdminUI || {};
    window.MDJAdminUI.showToast = showToast;
    window.MDJAdminUI.showToasts = showFlashNotifications;

    if ($) {
        $(bindAdminShellHandlers);
    }
})(window, document, window.jQuery);
