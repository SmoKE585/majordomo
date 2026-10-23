// 2026-10-23 — экспорт цепочек без потери типов, XSS-экранирование и ошибки буфера обмена.
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';

const source = readFileSync(new URL('../resources/js/modules/admin_panel/41_trace.js', import.meta.url), 'utf8');
const toasts = [];
const copied = [];
let selected = false;
let removed = false;
let restored = false;
let commandResult = true;
let fallbackCalls = 0;
const document = {
    activeElement: {isConnected: true, focus() { restored = true; }},
    body: {appendChild() {}},
    createElement() { return {style: {}, setAttribute() {}, select() { selected = true; }, remove() { removed = true; }}; },
    execCommand(command) { assert.equal(command, 'copy'); fallbackCalls++; return commandResult; }
};
const window = {MDJAdminPanel: {addBootTask() {}}, MDJAdminUI: {showToast(toast) { toasts.push(toast); }}};
const navigator = {clipboard: {writeText(value) { copied.push(value); return Promise.resolve(); }}};
const context = vm.createContext({window, document, navigator});
vm.runInContext(source.replace('})(window, document);', 'window.traceTest = {state, renderEvent, renderRun, renderDetails, copyText, onDocumentClick}; })(window, document);'), context);
const ui = window.traceTest;
const event = {
    id: 12, type: 'method', title: 'Sensor.<script>', params: {NEW_VALUE: 1, falseValue: false, removed: true},
    out_params: {NEW_VALUE: 0, falseValue: false}, parent_event_id: 11,
    execution: {code: 'sg("Lamp.status", "<script>");', parent_order: 1}
};
const rendered = ui.renderEvent(event);
assert.match(rendered, /Изменения параметров/);
assert.match(rendered, /NEW_VALUE/);
assert.match(rendered, /удалён/);
assert.doesNotMatch(rendered, /<script>/);
assert.match(rendered, /&lt;script&gt;/);
assert.match(rendered, /data-md-trace-copy-event="12"/);
assert.match(rendered, /data-md-trace-jump="11"/);
assert.match(rendered, /Код на момент запуска/);
assert.match(ui.renderDetails('Результат', false), />false<\/pre>/);
assert.match(ui.renderDetails('Результат', 0), />0<\/pre>/);
assert.match(ui.renderEvent({...event, out_params: {}}), /удалён/);
assert.doesNotMatch(ui.renderEvent({...event, params: {x: 1}, out_params: {x: 1}}), /Изменения параметров/);

const run = {id: 7, trace_id: 'test-trace', events: [event], params: {}, started_at: '2026-10-23T21:02:54+03:00'};
assert.match(ui.renderRun(run, 0), /Копировать запуск с кодом/);
ui.state.root = {contains() { return true; }};
ui.state.data = {meta: {property_name: 'Lamp.status'}, runs: [run], pagination: {page: 2}};
function clickExport(attribute, value, inRun = true) {
    const runNode = {getAttribute() { return '7'; }};
    const button = {
        closest(selector) { return selector === '[data-md-trace-run]' && inRun ? runNode : null; },
        getAttribute(name) { return name === attribute ? value : null; }
    };
    const target = {closest(selector) {
        return selector === '[data-md-trace-copy-run], [data-md-trace-copy-event], [data-md-trace-copy-page]' ? button : null;
    }};
    ui.onDocumentClick({target, preventDefault() {}});
}
clickExport('data-md-trace-copy-run', '7');
await new Promise(setImmediate);
let exported = JSON.parse(copied.at(-1));
assert.deepEqual(exported.run, run);
assert.equal(exported.run.events[0].params.falseValue, false);
clickExport('data-md-trace-copy-event', '12');
await new Promise(setImmediate);
exported = JSON.parse(copied.at(-1));
assert.equal(exported.event.execution.code, event.execution.code);
assert.equal(exported.trace_id, 'test-trace');
clickExport('data-md-trace-copy-page', '', false);
await new Promise(setImmediate);
assert.deepEqual(JSON.parse(copied.at(-1)), ui.state.data);

// Запрет современного Clipboard API должен включать резервное копирование.
ui.state.root.appendChild = () => {};
navigator.clipboard.writeText = () => Promise.reject(new Error('Clipboard blocked'));
ui.copyText('code');
await new Promise(setImmediate);
assert.equal(fallbackCalls, 1);
assert.ok(selected && removed && restored);
assert.equal(toasts.at(-1).type, 'success');
commandResult = false;
ui.copyText('code');
await new Promise(setImmediate);
assert.equal(toasts.at(-1).type, 'danger', 'Не показываем успех, если браузер отказал в копировании');
console.log('Property trace UI smoke passed: parameter diff, escaping, run/step/page exports, clipboard fallback and failure.');
