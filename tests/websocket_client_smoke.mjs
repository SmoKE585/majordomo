import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';

// Execute the actual browser transport with deterministic network and timers.
const source = readFileSync(new URL('../resources/js/modules/admin_panel/06_websockets.js', import.meta.url), 'utf8');
class FixtureDocument extends EventTarget {
    readyState = 'loading';
    badge = {textContent: '', setAttribute() {}, classList: {toggle() {}}};
    getElementById() { return this.badge; }
}
const document = new FixtureDocument();
const window = new EventTarget();
const sockets = [];
const timers = new Map();
let timerId = 0;
let requests = 0;
let responseStatus = 200;
class FixtureSocket {
    readyState = 0;
    constructor(url) { this.url = new URL(url); sockets.push(this); }
    close() { this.readyState = 3; this.onclose?.({code: 1000}); }
    open() { this.readyState = 1; this.onopen?.({}); }
    message(value) { this.onmessage?.({data: JSON.stringify(value)}); }
}
Object.assign(window, {
    location: {href: 'https://home.test/panel/'},
    MDJWebSocketConfig: {enabled: true, url: '/ws', ticketUrl: '/admin.php?ajax_panel=1&op=websocket'},
    setTimeout(callback) { timers.set(++timerId, callback); return timerId; },
    clearTimeout(id) { timers.delete(id); },
    queueMicrotask,
    fetch: async (_url, options) => {
        requests++;
        assert.equal(options.credentials, 'same-origin');
        assert.equal(options.cache, 'no-store');
        return {ok: responseStatus === 200, status: responseStatus, json: async () => ({ticket: String(requests).padStart(64, 'a')})};
    },
});
const events = [];
for (const name of ['md:ws-connected','md:ws-data','md:ws-disconnected']) document.addEventListener(name, event => events.push([name, event.detail]));
const context = vm.createContext({window, document, WebSocket: FixtureSocket, URL, CustomEvent, console});
vm.runInContext(source, context);
document.dispatchEvent(new Event('DOMContentLoaded'));
await new Promise(resolve => setImmediate(resolve));
assert.equal(sockets.length, 1);
assert.equal(sockets[0].url.href, 'wss://home.test/ws?ticket=' + '1'.padStart(64, 'a'));
await window.startWebSockets();
assert.equal(sockets.length, 1, 'concurrent starts keep one connection');
sockets[0].open();
assert.equal(window.startedWebSockets, 1);
assert.equal(events.at(-1)[0], 'md:ws-connected');
assert.equal(document.badge.textContent, 'Синхронизация…');
sockets[0].message({action: 'properties', data: '[{"PROPERTY":"Room.temp","VALUE":"21"}]'});
assert.equal(events.at(-1)[1].action, 'properties');
sockets[0].message({action: 'snapshot_end', data: '{}'});
assert.equal(document.badge.textContent, 'Подключено');
sockets[0].close();
assert.equal(window.startedWebSockets, 0);
assert.equal(timers.size, 1);
[...timers.values()][0]();
await new Promise(resolve => setImmediate(resolve));
assert.equal(requests, 2, 'reconnect fetches a fresh one-use ticket');
assert.equal(sockets.length, 2);
sockets[1].open();
assert.equal(events.filter(([name]) => name === 'md:ws-connected').length, 2, 'page subscriptions are restored on reconnect');
window.dispatchEvent(new Event('pagehide'));
assert.equal(timers.size, 0, 'pagehide prevents reconnect');
await window.startWebSockets();
assert.equal(requests, 2);
const pageShow = new Event('pageshow'); Object.defineProperty(pageShow, 'persisted', {value: true});
responseStatus = 401;
window.dispatchEvent(pageShow);
await new Promise(resolve => setImmediate(resolve));
assert.equal(requests, 3);
assert.equal(timers.size, 0, 'expired admin session stops retries');
console.log('WebSocket client smoke: lifecycle, snapshot status, native events, reconnect, one-use tickets and expired session passed.');
