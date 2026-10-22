import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {getEventListeners} from 'node:events';
import vm from 'node:vm';

// Run the actual page initializer without app boot, networking or a browser.
const template = readFileSync(new URL('../templates/objects/objects_edit_properties.html', import.meta.url), 'utf8');
const scripts = [...template.matchAll(/<script[^>]*>([\s\S]*?)<\/script>/g)];
const source = scripts.at(-1)[1];
const panelSource = readFileSync(new URL('../public/assets/js/admin/panel.js', import.meta.url), 'utf8');
const socketTemplate = readFileSync(new URL('../templates/websockets.html', import.meta.url), 'utf8');
new vm.Script(socketTemplate.match(/<script[^>]*>([\s\S]*?)<\/script>/)[1]
    .replace('<#WEBSOCKETS_AUTH_TOKEN_JSON#>', '""').replace(/\[#.*?#\]/g, ''));
assert.ok(socketTemplate.includes("document.dispatchEvent(new Event('md:ws-connected'))"));
assert.ok(socketTemplate.includes("document.dispatchEvent(new CustomEvent('md:ws-data', {detail: response}))"));
assert.equal(panelSource.match(/dispatchEvent\(new Event\('md:before-part-content-replace'\)\)/g).length, 3);

class FixtureDocument extends EventTarget {
    readyState = 'complete';
    objectName = 'Room';
    getElementById() { return null; }
    querySelectorAll() { return []; }
    querySelector(selector) {
        return selector === '[data-md-object-properties-page]'
            ? {getAttribute: () => this.objectName}
            : null;
    }
}
const document = new FixtureDocument();
const sent = [];
const socket = {readyState: 1, send: (message) => sent.push(JSON.parse(message))};
const context = vm.createContext({document, window: {wsSocket: socket}, wsSocket: socket,
    WebSocket: {OPEN: 1}, startedWebSockets: 1, AbortController, Event, console});
const events = ['md:ws-connected', 'md:ws-data', 'md:before-part-content-replace'];
for (let page = 0; page < 100; page++) {
    document.objectName = 'Room' + page;
    vm.runInContext(source, context);
    assert.deepEqual(sent.at(-1), {action: 'Subscribe', data: {TYPE: 'properties', PROPERTIES: 'Room' + page}});
    for (const event of events) assert.equal(getEventListeners(document, event).length, 1);
    document.dispatchEvent(new Event('md:ws-connected'));
    assert.equal(sent.at(-1).action, 'Subscribe');
    document.dispatchEvent(new Event('md:before-part-content-replace'));
    assert.deepEqual(sent.at(-1), {action: 'Unsubscribe', data: {TYPE: 'properties', PROPERTIES: 'Room' + page}});
    for (const event of events) assert.equal(getEventListeners(document, event).length, 0);
    const total = sent.length;
    document.dispatchEvent(new Event('md:ws-connected'));
    assert.equal(sent.length, total, 'detached page must not resubscribe after reconnect');
}
document.readyState = 'loading';
vm.runInContext(source, context);
assert.equal(getEventListeners(document, 'DOMContentLoaded').length, 1);
document.dispatchEvent(new Event('DOMContentLoaded'));
assert.equal(getEventListeners(document, 'DOMContentLoaded').length, 0);
assert.equal(getEventListeners(document, 'md:ws-connected').length, 1);
document.dispatchEvent(new Event('md:before-part-content-replace'));
assert.equal(getEventListeners(document, 'md:ws-connected').length, 0);
console.log('WebSocket subscription smoke: 100 page transitions, reconnect and initial loading passed; no retained page listeners.');
