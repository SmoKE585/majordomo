import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';

// 2026-10-23 — уведомления через WebSocket: подписка, переподключение,
// дедупликация и отсутствие HTTP-опроса, включая страницу входа.
const source = readFileSync(new URL('../resources/js/modules/admin_panel/60_notifications.js', import.meta.url), 'utf8');

const sent = [];
const toasts = [];
const listeners = new Map();
let requests = 0;
let timers = 0;

function jquery(target) {
    if (typeof target === 'function') {
        target();
        return {on() { return this; }};
    }
    return {on() { return this; }, removeClass() { return this; }, addClass() { return this; }, css() { return this; }, val() { return ''; }};
}
jquery.ajax = function () {
    requests++;
    throw new Error('Опрос журнала через HTTP запрещён');
};

const document = {hidden: false, title: 'Вход', addEventListener(name, callback) {
    if (!listeners.has(name)) listeners.set(name, []);
    listeners.get(name).push(callback);
}};
const window = {location: {href: 'https://home.test/admin.php?auth=login'}, history: {replaceState() {}}};
window.jQuery = jquery;
window.MDJUIToast = {show: (toast) => toasts.push(toast)};
function socket() {
    return {readyState: 1, send: (message) => sent.push(JSON.parse(message))};
}
function dispatch(name, detail) {
    for (const listener of listeners.get(name) || []) listener({detail});
}
function frame(name, value, sequence = 1, epoch = 'server-one') {
    return {action: 'events', data: JSON.stringify({EVENT_DATA: {NAME: name, VALUE: value}}), sequence, epoch};
}
function deliver(response) { dispatch('md:ws-data', response); }
const context = vm.createContext({
    window,
    document,
    URL,
    console,
    setTimeout: () => { timers++; throw new Error('Таймер опроса запрещён'); },
    clearTimeout() {}
});
vm.runInContext(source, context);

assert.equal(typeof window.MDJAdminUI.showToast, 'function', 'модуль зарегистрировал свой UI');
window.wsSocket = socket();
dispatch('md:ws-connected');
window.checkLatestMessage();
deliver(frame('SAY', {id: 1, message: 'Вход'}));
assert.equal(sent.length, 0, 'страница входа не подписывается на журнал');
assert.equal(toasts.length, 0, 'страница входа не показывает сообщения журнала');

// Подписка не заменяет чужие подписки и не зависит от пути установки.
window.ROOTHTML = '/';
window.wsSocket.readyState = 0;
window.checkLatestMessage();
assert.equal(sent.length, 0, 'подписка ждёт открытия соединения');
window.wsSocket.readyState = 1;
dispatch('md:ws-connected');
window.checkLatestMessage();
assert.deepEqual(sent, [{action: 'Subscribe', data: {TYPE: 'events', EVENTS: ['SAY', 'ASK', 'SAYTO']}}]);
assert.equal(toasts.length, 0, 'подписка не показывает старую запись журнала');

deliver(frame('SAY', {id: 1, message: 'Первое сообщение'}));
assert.equal(toasts.length, 1, 'первое новое событие показывается сразу');
assert.equal(toasts[0].text, 'Первое сообщение');
assert.equal(toasts[0].title, 'Журнал сообщений');
deliver(frame('SAY', {id: '1', message: 'Первое сообщение'}, 2));
assert.equal(toasts.length, 1, 'повтор ID, включая строковый, не показывается');
deliver(frame('SAY', {id: 2, message: 'Первое сообщение'}, 3));
assert.equal(toasts.length, 2, 'одинаковый текст с новым ID показывается');
deliver(frame('SAYTO', {id: 3, message: 'Для терминала'}, 4));
assert.equal(toasts.at(-1).text, 'Для терминала');

const question = frame('ASK', {message: 'Повторяемый вопрос'}, 5);
deliver(question);
const questionCount = toasts.length;
deliver(question);
assert.equal(toasts.length, questionCount, 'повтор кадра ASK без ID отсеивается');
deliver(frame('ASK', {message: 'Повторяемый вопрос'}, 6));
deliver(frame('ASK', {message: 'Повторяемый вопрос'}, 6, 'server-two'));
assert.equal(toasts.length, questionCount + 2, 'новые вопросы не склеиваются по тексту или номеру после рестарта');

const beforeInvalid = toasts.length;
for (const response of [
    null, {action: 'events', data: '{broken'},
    {action: 'events', data: '{}'},
    {action: 'properties', data: question.data},
    frame('COMMAND', {id: 10, message: 'Пользовательская команда'}),
    frame('SAYREPLY', {message: 'Дублирующий ответ'}),
    frame('SAY', {id: 10, message: '   '}),
    frame('SAY', {id: 10, message: 123}),
    frame('SAY', {id: 10, message: 'Личное', member_id: 7}),
    frame('SAY', null),
]) deliver(response);
assert.equal(toasts.length, beforeInvalid, 'посторонние, пустые и некорректные события игнорируются');

// Новое соединение получает подписку; известные ID не показываются повторно.
window.wsSocket.readyState = 3;
dispatch('md:ws-disconnected');
window.checkLatestMessage();
assert.equal(sent.length, 1, 'закрытое соединение не используется');
window.ROOTHTML = '/majordomo/';
window.wsSocket = socket();
dispatch('md:ws-connected');
window.checkLatestMessage();
assert.equal(sent.length, 2, 'при переподключении подписка отправляется один раз');
assert.deepEqual(sent[1], sent[0], 'подкаталог не меняет подписку');
deliver(frame('SAY', {id: 2, message: 'Первое сообщение'}, 7, 'server-two'));
assert.equal(toasts.length, beforeInvalid, 'дедупликация ID сохраняется при переподключении');
document.hidden = true;
deliver(frame('SAY', {id: 4, message: 'В скрытой вкладке'}, 8, 'server-two'));
assert.equal(toasts.at(-1).text, 'В скрытой вкладке');
assert.equal(requests, 0, 'HTTP-запросов журнала нет');
assert.equal(timers, 0, 'таймеров опроса журнала нет');

console.log('Panel notifications smoke passed: WebSocket subscription, reconnect, deduplication, event filtering, no HTTP polling.');
