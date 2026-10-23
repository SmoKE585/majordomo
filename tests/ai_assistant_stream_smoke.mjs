// 2026-10-23 — браузерный SSE-контракт без сети, БД и обычного .env.
import assert from 'node:assert/strict';
import vm from 'node:vm';
import {readFile} from 'node:fs/promises';

let fetchResponse;
const sandbox = {TextDecoder, Uint8Array, URLSearchParams, DOMException, navigator: {},
    document: {addEventListener() {}, querySelector() { return {content: 'fixture-csrf'}; }},
    fetch: async () => fetchResponse};
sandbox.window = {ROOTHTML: '/', isSecureContext: true, setTimeout: (fn) => setTimeout(fn, 40), clearTimeout};
vm.runInNewContext(await readFile(new URL('../resources/js/modules/admin_panel/129_ai_runtime.js', import.meta.url), 'utf8'), sandbox);
const ai = sandbox.window.MDAssistant;
const encoder = new TextEncoder();
const response = (parts) => new Response(new ReadableStream({start(controller) {
    for (const part of parts) controller.enqueue(encoder.encode(part));
    controller.close();
}}), {headers: {'Content-Type': 'text/event-stream'}});
const events = [];
await ai.consume(response(['event: token\r', '\ndata: {"text":"Привет"}\r\n\r', '\nevent: done\r\ndata: {"status":"done"}\r\n\r\n']),
    (name, data) => events.push([name, data]));
assert.equal(events[0][0], 'token');
assert.equal(events[0][1].text, 'Привет');
assert.equal(events[1][0], 'done');
await assert.rejects(ai.consume(response(['event: token\ndata: {"text":"Часть"}\n\n']), () => {}), /без ответа сервера/);
await assert.rejects(ai.consume(response(['event: done\ndata: broken\n\n']), () => {}), /повреждённое событие/);
await ai.consume(response(['event: error\ndata: {"message":"Ошибка модели"}\n\n']), (name) => assert.equal(name, 'error'));
const broken = {body: new ReadableStream({pull(controller) { controller.error(new TypeError('network error')); }})};
await assert.rejects(ai.consume(broken, () => {}), /network error/);
assert.match(ai.errorText(new TypeError('network error')), /Соединение.*прервано/);
assert.equal(ai.errorText(new TypeError('Cannot read properties of undefined')), 'Cannot read properties of undefined', 'Ошибка интерфейса не маскируется сетевой.');
let readsAfterDone = 0;
const droppedAfterDone = {body: {getReader() {return {
    async read() {
        if (readsAfterDone++ === 0) return {value: encoder.encode('event: done\ndata: {"status":"done"}\n\n'), done: false};
        throw new TypeError('network error');
    }, cancel: async () => {}, releaseLock() {}
};}}};
await ai.consume(droppedAfterDone, () => {});
assert.equal(readsAfterDone, 1, 'Завершённый ответ не зависит от дальнейшего закрытия HTTP-соединения.');
const hanging = {body: new ReadableStream({pull() {}})};
await assert.rejects(ai.consume(hanging, () => {}), /90 секунд/);
fetchResponse = new Response('<html>Bad gateway</html>', {status: 502});
await assert.rejects(ai.request('send', {}, undefined, true), /неполный ответ/);
fetchResponse = new Response('{"ok":false,"message":"Сессия истекла"}', {status: 403});
await assert.rejects(ai.request('send', {}, undefined, true), /Сессия истекла/);
fetchResponse = new Response('{"ok":true,"cancelled":true}', {headers: {'Content-Type': 'application/json'}});
assert.equal((await ai.request('cancel', {dialog_id: 1})).cancelled, true);
console.log('ИИ-помощник SSE: 14 проверок пройдено (без сети).');
