import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {getEventListeners} from 'node:events';
import vm from 'node:vm';

// 2026-10-23 — снимки не подсвечиваются как изменения, дата и источник берутся
// из записи БД; инициализатор выполняется без приложения и сетевых соединений.
const template = readFileSync(new URL('../templates/objects/objects_edit_properties.html', import.meta.url), 'utf8');
const scripts = [...template.matchAll(/<script[^>]*>([\s\S]*?)<\/script>/g)];
const source = scripts.at(-1)[1];
const panelSource = readFileSync(new URL('../public/assets/js/admin/panel.js', import.meta.url), 'utf8');
const socketSource = readFileSync(new URL('../resources/js/modules/admin_panel/06_websockets.js', import.meta.url), 'utf8');
new vm.Script(socketSource);
assert.ok(socketSource.includes("wsConnected: 'md:ws-connected'"));
assert.ok(socketSource.includes("wsData: 'md:ws-data'"));
assert.equal(panelSource.match(/dispatchEvent\(new Event\('md:before-part-content-replace'\)\)/g).length, 3);

class FixtureDocument extends EventTarget {
    readyState = 'complete';
    objectName = 'Room';
    cards = [];
    getElementById() { return null; }
    querySelectorAll(selector) { return selector === '[data-md-object-property]' ? this.cards : []; }
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

function node(attributes = {}) {
    const classes = new Set();
    return {
        attributes, textContent: '', innerHTML: '', title: '', hidden: false,
        getAttribute: (key) => attributes[key] ?? null,
        setAttribute: (key, value) => { attributes[key] = String(value); },
        classList: {
            add: (...names) => names.forEach((name) => classes.add(name)),
            remove: (...names) => names.forEach((name) => classes.delete(name)),
            contains: (name) => classes.has(name),
            toggle(name, enabled) { if (enabled) classes.add(name); else classes.delete(name); },
        },
    };
}
function property(name, value, savedSource, savedDate) {
    const card = node({'data-md-prop-full-name': name});
    const current = node({'data-md-prop-value': value, 'data-md-prop-type': '0'});
    const display = node(); display.textContent = value;
    const author = node(); author.title = savedSource; author.innerHTML = 'исходная метка';
    const updated = node(); updated.textContent = savedDate;
    const editor = node(); editor.hidden = true;
    const input = {value};
    current.querySelector = (selector) => ({
        '[data-md-prop-display]': display,
        '[data-md-prop-source]': author,
        '[data-md-prop-updated]': updated,
    })[selector] || null;
    editor.querySelector = () => input;
    card.querySelector = (selector) => ({
        '[data-md-prop-current]': current,
        '[data-md-prop-editor]': editor,
    })[selector] || null;
    return {card, current, display, author, updated, editor, input};
}

const active = property('Room.temperature', '21', '/panel/objects/1.html', '22.10.2026 10:20:30');
const empty = property('Room.empty', '', '', 'ещё не обновлялось');
document.objectName = 'Room';
document.readyState = 'complete';
document.cards = [active.card, empty.card];
const highlights = [];
context.window.setTimeout = (callback) => highlights.push(callback);
vm.runInContext(source, context);

function update(items, snapshot = false) {
    const event = new Event('md:ws-data');
    event.detail = {action: 'properties', data: JSON.stringify(items), snapshot};
    document.dispatchEvent(event);
}
const stored = {PROPERTY: 'Room.temperature', VALUE: '21', SOURCE: '/panel/objects/1.html', UPDATED: '2026-10-22 10:20:30'};
update([stored, {PROPERTY: 'Room.empty', VALUE: null, SOURCE: '', UPDATED: null}], true);
assert.equal(highlights.length, 0, 'начальный снимок не подсвечивает свойства');
assert.equal(active.author.innerHTML, 'исходная метка', 'неизменный источник сохраняет метку PHP');
assert.equal(active.updated.textContent, '22.10.2026 10:20:30', 'снимок не заменяет дату часами браузера');
assert.equal(empty.updated.textContent, 'ещё не обновлялось', 'пустому свойству не придумывается время');
assert.equal(empty.author.classList.contains('is-empty'), true);
assert.equal(empty.display.textContent, 'Значение не задано');

update([{...stored, VALUE: '22'}], true);
assert.equal(active.display.textContent, '22', 'снимок устраняет расхождение с HTML страницы');
assert.equal(active.input.value, '22');
assert.equal(highlights.length, 0, 'отличающееся значение в снимке обновляется без подсветки');
update([{...stored, VALUE: 22}]);
assert.equal(highlights.length, 0, 'одинаковое значение в обычном потоке не подсвечивается');

update([{...stored, VALUE: '23', SOURCE: 'mqtt', UPDATED: '2026-10-23 11:12:13'}]);
assert.equal(highlights.length, 1, 'реальное изменение значения подсвечивается');
assert.equal(active.card.classList.contains('is-ws-updated'), true);
assert.equal(active.author.title, 'mqtt', 'новый источник взят из записи, а не из транспорта');
assert.ok(active.author.innerHTML.includes('mqtt'));
assert.equal(active.updated.textContent, '23.10.2026 11:12:13', 'показано сохранённое время изменения');
update([{...stored, VALUE: '23', SOURCE: 'mqtt', UPDATED: '2026-10-23 11:12:13'}]);
assert.equal(highlights.length, 1, 'повтор кадра не запускает подсветку заново');
highlights[0]();
update([{...stored, VALUE: '24', SOURCE: 'mqtt', UPDATED: '2026-10-23 11:13:00'}], true);
assert.equal(highlights.length, 1, 'снимок после переподключения также не считается событием изменения');
assert.equal(active.card.classList.contains('is-ws-updated'), false);

active.editor.hidden = false;
active.input.value = 'ещё не сохранено';
update([{...stored, VALUE: '25', SOURCE: '<img src=x onerror=alert(1)>', UPDATED: '2026-10-23 11:14:00'}]);
assert.equal(active.input.value, 'ещё не сохранено', 'обновление не затирает редактируемое значение');
assert.ok(active.author.innerHTML.includes('&lt;img'), 'текст источника экранируется');
assert.ok(!active.author.innerHTML.includes('<img'), 'источник не вставляется как HTML');
const previousSource = active.author.title;
const previousDate = active.updated.textContent;
update([{PROPERTY: 'Room.temperature', VALUE: '25'}]);
assert.equal(active.author.title, previousSource, 'кадр старого сервера не придумывает источник');
assert.equal(active.updated.textContent, previousDate, 'кадр без метаданных не придумывает время');
update([{PROPERTY: 'Room.temperature', VALUE: '', SOURCE: '', UPDATED: null}]);
assert.equal(active.author.classList.contains('is-empty'), true);
assert.equal(active.card.getAttribute('data-md-prop-has-source'), '0');
assert.equal(active.updated.textContent, 'ещё не обновлялось');
document.dispatchEvent(new Event('md:before-part-content-replace'));
for (const event of events) assert.equal(getEventListeners(document, event).length, 0);
console.log('WebSocket subscription smoke passed: 100 page transitions, reconnect, snapshots, saved metadata, real-change highlights and protected editor.');
