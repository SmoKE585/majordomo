/* 2026-10-22 — единые уведомления UI-кита и дедупликация сообщений журнала.
 * 2026-10-23 — опрос журнала не запускается вне админ-оболочки: на странице
 * входа window.ROOTHTML не определён, и запрос уходил на
 * /undefinedgetlatestnote.html. */
(function (window, document, $) {
    'use strict';

    var latestMessage = null;
    var latestMessageTimer = 0;
    var latestMessageInFlight = false;
    var latestMessageLoopStarted = false;
    var flashNotificationsShown = false;
    var LATEST_MESSAGE_INTERVAL_ACTIVE = 10000;
    var LATEST_MESSAGE_INTERVAL_HIDDEN = 60000;

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

    // Адрес опроса строим только при определённом ROOTHTML: страница входа
    // подключает тот же бандл, но оболочку и префикс проекта не задаёт.
    function latestNoteUrl() {
        if (typeof window.ROOTHTML !== 'string' || window.ROOTHTML === '') {
            return '';
        }
        return window.ROOTHTML + 'getlatestnote.html';
    }

    function checkLatestMessage() {
        if (latestMessageInFlight || !$) {
            return;
        }
        var url = latestNoteUrl();
        if (url === '') {
            latestMessageLoopStarted = false;
            return;
        }
        latestMessageInFlight = true;
        $.ajax({
            url: url
        }).done(function (data) {
            var note = safeJsonParse(data);
            if (!note || typeof note.DATA !== 'string') return;
            var identity = note.ID ? 'id:' + note.ID : 'text:' + note.DATA;
            // Первый ответ — исходное состояние, а не новое уведомление.
            var changed = latestMessage !== null && identity !== latestMessage;
            latestMessage = identity;
            if (changed && note.DATA.trim()) showToast({title: 'Журнал сообщений', message: note.DATA, type: 'info'});
        }).always(function () {
            latestMessageInFlight = false;
            clearTimeout(latestMessageTimer);
            latestMessageTimer = setTimeout(
                checkLatestMessage,
                document.hidden ? LATEST_MESSAGE_INTERVAL_HIDDEN : LATEST_MESSAGE_INTERVAL_ACTIVE
            );
        });
    }

    function bindAdminShellHandlers() {
        if (!$) {
            return;
        }
        if (!latestMessageLoopStarted) {
            latestMessageLoopStarted = true;
            checkLatestMessage();
        }
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
