/*
 * Events page: live feed refresh and pause control.
 *
 * The feed markup is rendered by PHP (events::renderEventsFeedHtml) and the
 * same markup is returned by /ajax/events.html?getlatest=1. This module only
 * refreshes the container, so the page still works without JavaScript.
 */
(function (window, document) {
    'use strict';

    var timers = new WeakMap();

    function init(root) {
        var scope = root && root.querySelectorAll ? root : document;

        scope.querySelectorAll('[data-md-events-feed]').forEach(function (feed) {
            if (feed.dataset.mdEventsReady === '1') {
                return;
            }
            feed.dataset.mdEventsReady = '1';

            var card = feed.closest('.md-card') || scope;
            var pauseButton = card.querySelector('[data-md-events-pause]');
            var status = card.querySelector('[data-md-events-status]');
            var statusLabel = status ? status.querySelector('[data-md-events-status-label]') : null;
            var pauseIcon = pauseButton ? pauseButton.querySelector('[data-md-events-pause-icon]') : null;
            var pauseLabel = pauseButton ? pauseButton.querySelector('[data-md-events-pause-label]') : null;
            var url = feed.getAttribute('data-md-events-feed-url') || '';
            var interval = parseInt(feed.getAttribute('data-md-events-feed-interval') || '5000', 10);
            if (!interval || interval < 2000) {
                interval = 5000;
            }

            var paused = false;
            var loading = false;
            var timer = 0;

            function setStatus(state, text) {
                if (!status) {
                    return;
                }
                status.classList.toggle('is-paused', state === 'paused');
                status.classList.toggle('is-error', state === 'error');
                if (statusLabel && text) {
                    statusLabel.textContent = text;
                }
            }

            function load() {
                if (paused || loading || !url || !document.documentElement.contains(feed)) {
                    return;
                }
                loading = true;
                window.fetch(url, {
                    credentials: 'same-origin',
                    cache: 'no-store',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                }).then(function (response) {
                    if (!response.ok) {
                        throw new Error('HTTP ' + response.status);
                    }
                    return response.text();
                }).then(function (html) {
                    if (typeof html === 'string' && html.trim() !== '') {
                        feed.innerHTML = html;
                    }
                    setStatus('live', 'Автообновление');
                }).catch(function () {
                    setStatus('error', 'Нет связи');
                }).finally(function () {
                    loading = false;
                });
            }

            function schedule() {
                window.clearInterval(timer);
                timer = window.setInterval(function () {
                    if (!document.documentElement.contains(feed)) {
                        window.clearInterval(timer);
                        timers.delete(feed);
                        return;
                    }
                    load();
                }, interval);
                timers.set(feed, timer);
            }

            if (pauseButton) {
                pauseButton.addEventListener('click', function () {
                    paused = !paused;
                    pauseButton.setAttribute('aria-pressed', paused ? 'true' : 'false');
                    pauseButton.setAttribute('aria-label', paused ? 'Возобновить автообновление' : 'Приостановить автообновление');
                    if (pauseIcon) {
                        pauseIcon.className = paused ? 'ti ti-play' : 'ti ti-pause';
                    }
                    if (pauseLabel) {
                        pauseLabel.textContent = paused ? 'Продолжить' : 'Пауза';
                    }
                    setStatus(paused ? 'paused' : 'live', paused ? 'Пауза' : 'Автообновление');
                    if (!paused) {
                        load();
                    }
                });
            }

            load();
            schedule();
        });
    }

    if (window.MDJAdminUI && typeof window.MDJAdminUI.registerModuleUI === 'function') {
        window.MDJAdminUI.registerModuleUI('events', { init: init });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            init(document);
        });
    } else {
        init(document);
    }
})(window, document);
