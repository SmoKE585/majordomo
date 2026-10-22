/* Shared read-only socket. Legacy pub/sub and native page events use one connection. */
(function (window, document) {
    'use strict';
    let timer = 0;
    let connecting = false;
    let stopped = false;
    let attempt = 0;
    window.startedWebSockets = 0;
    window.wsSocket = null;

    function emit(name, detail) {
        if (window.jQuery?.publish) window.jQuery.publish(name, detail === undefined ? [] : detail);
        const native = {wsConnected: 'md:ws-connected', wsDisconnected: 'md:ws-disconnected', wsData: 'md:ws-data'}[name];
        document.dispatchEvent(new CustomEvent(native, {detail}));
    }
    function status(state) {
        const badge = document.getElementById('wsStatus');
        if (!badge) return;
        badge.textContent = {connecting: 'Подключение…', syncing: 'Синхронизация…', connected: 'Подключено', disconnected: 'Нет подключения'}[state];
        badge.setAttribute('role', 'status');
        badge.setAttribute('aria-live', 'polite');
        badge.classList.toggle('md-admin-status-badge--success', state === 'connected');
        badge.classList.toggle('md-admin-status-badge--danger', state === 'disconnected');
    }
    function retry() {
        window.clearTimeout(timer);
        if (stopped || !window.MDJWebSocketConfig) return;
        timer = window.setTimeout(start, Math.min(30000, 1000 * (2 ** Math.min(attempt++, 5))) + Math.random() * 500);
    }
    async function start() {
        window.clearTimeout(timer);
        const config = window.MDJWebSocketConfig;
        if (stopped || !config || connecting || (window.wsSocket && window.wsSocket.readyState < 2)) return;
        connecting = true;
        status('connecting');
        try {
            const response = await window.fetch(config.ticketUrl, {credentials: 'same-origin', cache: 'no-store', headers: {'X-Requested-With': 'XMLHttpRequest'}});
            if (!response.ok) {
                if (response.status === 401 || response.status === 403) stopped = true;
                throw new Error('WebSocket authentication failed');
            }
            const auth = await response.json();
            if (typeof auth.ticket !== 'string' || !/^[a-f0-9]{64}$/.test(auth.ticket)) throw new Error('Invalid WebSocket ticket');
            if (stopped) return;
            const url = new URL(config.url, window.location.href);
            if (url.protocol === 'https:') url.protocol = 'wss:';
            if (url.protocol === 'http:') url.protocol = 'ws:';
            url.searchParams.set('ticket', auth.ticket);
            const socket = window.wsSocket = new WebSocket(url.href);
            socket.onopen = function () {
                window.startedWebSockets = 1;
                status('syncing');
                emit('wsConnected');
            };
            socket.onmessage = function (event) {
                let response;
                try { response = JSON.parse(event.data); } catch { return; }
                if (!response || typeof response.action !== 'string') return;
                if (response.action === 'snapshot_start') status('syncing');
                if (response.action === 'snapshot_end') { attempt = 0; status('connected'); }
                emit('wsData', response);
            };
            socket.onclose = function () {
                if (window.wsSocket !== socket) return;
                window.startedWebSockets = 0;
                status('disconnected');
                emit('wsDisconnected');
                retry();
            };
            socket.onerror = function () { socket.close(); };
        } catch {
            status('disconnected');
            retry();
        } finally { connecting = false; }
    }
    window.startWebSockets = start;
    window.addEventListener('pagehide', function () {
        stopped = true;
        window.clearTimeout(timer);
        window.wsSocket?.close();
    });
    window.addEventListener('pageshow', function (event) { if (event.persisted) { stopped = false; start(); } });
    window.addEventListener('online', start);
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, {once: true});
    else window.queueMicrotask(start);
})(window, document);
