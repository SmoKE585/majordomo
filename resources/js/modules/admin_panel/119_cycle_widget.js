(function (window, document) {
    'use strict';

    var STATUS = {
        running: { text: 'Работает', tone: 'success' },
        starting: { text: 'Запускается', tone: 'info' },
        stopping: { text: 'Выключается', tone: 'warning' },
        hang: { text: 'Завис', tone: 'warning' },
        stopped: { text: 'Остановлен', tone: 'danger' },
        disabled: { text: 'Отключён', tone: 'neutral' },
        unknown: { text: 'Неизвестно', tone: 'neutral' }
    };

    function statusView(state) {
        return STATUS[state] || STATUS.unknown;
    }

    function setBadge(badge, text, tone) {
        if (!badge) {
            return;
        }
        badge.textContent = text;
        badge.className = 'md-badge' + (tone && tone !== 'neutral' ? ' md-badge--' + tone : '');
    }

    function toNumber(value) {
        var number = parseInt(value, 10);
        return isNaN(number) ? 0 : number;
    }

    function formatUpdated(value) {
        if (!value) {
            return '';
        }
        var timestamp = toNumber(value);
        if (timestamp > 0) {
            try {
                return new Date(timestamp * 1000).toLocaleString('ru-RU');
            } catch (e) {
                return String(value);
            }
        }
        return String(value);
    }

    function updateCard(card, data) {
        if (!card || !data) {
            return;
        }
        var state = data.STATUS || data.status || 'unknown';
        var view = statusView(state);
        var statusBadge = card.querySelector('[data-md-cycle-status]');
        var updated = card.querySelector('[data-md-cycle-updated]');
        var online = card.querySelector('[data-md-cycle-online]');

        card.dataset.mdCycleState = state;
        ['running', 'starting', 'stopping', 'hang', 'stopped', 'disabled', 'unknown'].forEach(function (name) {
            card.classList.toggle('is-' + name, name === state);
        });
        setBadge(statusBadge, data.STATUS_TEXT || view.text, view.tone);

        if (updated) {
            updated.textContent = formatUpdated(data.UPDATED) || '—';
        }
        if (online && typeof data.ONLINE !== 'undefined') {
            var isOnline = !!Number(data.ONLINE);
            online.textContent = isOnline ? 'ONLINE' : 'OFFLINE';
            online.classList.toggle('is-online', isOnline);
            online.classList.toggle('is-offline', !isOnline);
        }

        var disabled = state === 'disabled' || !!Number(data.DISABLED || 0);
        var stopped = state === 'stopped' || state === 'unknown';
        var start = card.querySelector('[data-md-cycle-command="start"]');
        var restart = card.querySelector('[data-md-cycle-command="restart"]');
        var stop = card.querySelector('[data-md-cycle-command="stop"]');
        var disable = card.querySelector('[data-md-cycle-command="disable"]');
        var enable = card.querySelector('[data-md-cycle-command="enable"]');
        if (start) {
            start.hidden = !stopped || disabled;
        }
        if (restart) {
            restart.hidden = stopped || disabled;
        }
        if (stop) {
            stop.hidden = stopped || disabled;
        }
        if (disable) {
            disable.hidden = disabled;
        }
        if (enable) {
            enable.hidden = !disabled;
        }
    }

    function initOne(card) {
        if (!card || card.dataset.mdCycleBound === '1') {
            return;
        }
        card.dataset.mdCycleBound = '1';

        var cycle = card.getAttribute('data-md-cycle-name') || '';
        var endpoint = card.getAttribute('data-md-cycle-endpoint') || ((window.ROOTHTML || '/') + 'ajax/xray.html?action=service');
        var poll = card.getAttribute('data-md-cycle-poll') === '1';
        var timer = 0;

        function setBusy(busy) {
            card.classList.toggle('is-busy', !!busy);
        }

        function toast(message, type) {
            if (window.MDJAdminUI && typeof window.MDJAdminUI.showToast === 'function') {
                window.MDJAdminUI.showToast({
                    message: message,
                    type: type || 'success',
                    hideAfter: 2600
                });
            }
        }

        function fetchState(op) {
            var body = new FormData();
            body.append('action', 'service');
            body.append('ajax', '1');
            body.append('cycle', cycle);
            if (op) {
                body.append('op', op);
            }

            return fetch(endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                body: body
            }).then(function (response) {
                return response.json();
            }).then(function (data) {
                updateCard(card, data);
                return data;
            });
        }

        function schedule(delay) {
            window.clearTimeout(timer);
            if (!poll) {
                return;
            }
            timer = window.setTimeout(function () {
                if (!document.body.contains(card)) {
                    return;
                }
                if (document.visibilityState === 'hidden') {
                    schedule(4000);
                    return;
                }
                fetchState().catch(function () {}).finally(function () {
                    schedule(4000);
                });
            }, delay || 3000);
        }

        function command(op) {
            setBusy(true);
            var labels = {
                start: 'Команда запуска отправлена',
                stop: 'Команда остановки отправлена',
                restart: 'Команда перезапуска отправлена',
                disable: 'Цикл отключён',
                enable: 'Цикл включён'
            };
            fetchState(op).then(function () {
                toast(labels[op] || 'Команда отправлена', 'info');
                schedule(600);
            }).catch(function () {
                toast('Не удалось отправить команду цикла', 'error');
                schedule(3000);
            }).finally(function () {
                setBusy(false);
            });
        }

        card.addEventListener('click', function (event) {
            var button = event.target.closest('[data-md-cycle-command]');
            if (!button || !card.contains(button)) {
                return;
            }
            event.preventDefault();
            var op = button.getAttribute('data-md-cycle-command');
            if (op === 'start' || op === 'stop' || op === 'restart' || op === 'disable' || op === 'enable') {
                command(op);
            }
        });

        schedule(2500);
    }

    function init(root) {
        root = root || document;
        Array.prototype.slice.call(root.querySelectorAll('[data-md-cycle]')).forEach(initOne);
    }

    window.MDJCycleWidgets = {
        init: init,
        update: updateCard
    };

    if (window.MDJAdminUI && typeof window.MDJAdminUI.registerModuleUI === 'function') {
        window.MDJAdminUI.registerModuleUI('cycle-widget', {
            init: init
        });
    } else if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            init(document);
        });
    } else {
        init(document);
    }
})(window, document);
