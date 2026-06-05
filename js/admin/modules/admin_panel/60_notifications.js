(function (window, document, $) {
    'use strict';

    var latestMessage = '';
    var latestMessageTimer = 0;
    var latestMessageInFlight = false;
    var latestMessageLoopStarted = false;
    var flashNotificationsShown = false;
    var LATEST_MESSAGE_INTERVAL_ACTIVE = 10000;
    var LATEST_MESSAGE_INTERVAL_HIDDEN = 60000;

    function safeJsonParse(data, fallback) {
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
        } else if (normalized === 'danger' || normalized === 'fail') {
            normalized = 'error';
        } else if (normalized === 'warn') {
            normalized = 'warning';
        }

        var palette = {
            success: {bgColor: '#198754', loaderBg: '#b7efcb'},
            error: {bgColor: '#dc3545', loaderBg: '#ffb9c0'},
            warning: {bgColor: '#b7791f', loaderBg: '#f4d38f'},
            info: {bgColor: '#24415c', loaderBg: '#8cc2ea'}
        };

        return palette[normalized] ? {
            name: normalized,
            bgColor: palette[normalized].bgColor,
            loaderBg: palette[normalized].loaderBg
        } : {
            name: 'info',
            bgColor: palette.info.bgColor,
            loaderBg: palette.info.loaderBg
        };
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
        if (!$ || !notification || !notification.message) {
            return false;
        }

        var type = normalizeToastType(notification.type);
        var text = notification.title ? notification.title + ': ' + notification.message : notification.message;

        $.toast({
            text: text,
            showHideTransition: 'fade',
            allowToastClose: true,
            hideAfter: notification.hideAfter || 4500,
            stack: 5,
            position: 'top-right',
            bgColor: type.bgColor,
            textColor: '#ffffff',
            textAlign: 'left',
            loader: true,
            loaderBg: type.loaderBg
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

    function checkLatestMessage() {
        if (latestMessageInFlight || !$) {
            return;
        }
        latestMessageInFlight = true;
        $.ajax({
            url: window.ROOTHTML + 'getlatestnote.html'
        }).done(function (data) {
            if (data !== latestMessage) {
                latestMessage = data;
                var dataJSON = safeJsonParse(latestMessage);
                if (dataJSON && dataJSON.DATA !== '' && latestMessageTimer !== 0) {
                    $.toast({
                        text: dataJSON.DATA,
                        showHideTransition: 'fade',
                        allowToastClose: true,
                        hideAfter: 3000,
                        stack: 5,
                        position: 'bottom-left',
                        bgColor: '#444444',
                        textColor: '#eeeeee',
                        textAlign: 'left',
                        loader: true,
                        loaderBg: '#9EC600'
                    });
                }
            }
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

    if (window.MDJAdminUI) {
        window.MDJAdminUI.showToast = showToast;
        window.MDJAdminUI.showToasts = showFlashNotifications;
    }

    if ($) {
        $(bindAdminShellHandlers);
    }
})(window, document, window.jQuery);
