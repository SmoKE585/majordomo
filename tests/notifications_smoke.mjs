import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';

// 2026-10-22 — реальный toast-store и polling с изолированными таймерами/AJAX.
const components = readFileSync(new URL('../resources/js/modules/admin_panel/116_ui_components.js', import.meta.url), 'utf8').replace("import Alpine from 'alpinejs';", '');
const notifications = readFileSync(new URL('../resources/js/modules/admin_panel/60_notifications.js', import.meta.url), 'utf8');
function fixture() {
    const stores = new Map();
    const timers = new Map();
    const requests = [];
    let sequence = 0;
    const $ = function () {};
    $.ajax = function () {
        const request = {};
        requests.push(request);
        const chain = {
            done(callback) { request.done = callback; return chain; },
            always(callback) { request.always = callback; return chain; },
        };
        return chain;
    };
    const Alpine = {
        store(name, value) { if (value) stores.set(name, value); return stores.get(name); },
        data() {},
    };
    const window = {
        jQuery: $, ROOTHTML: '/',
        setTimeout(callback) { timers.set(++sequence, callback); return sequence; },
        clearTimeout(id) { timers.delete(id); },
    };
    const context = vm.createContext({window, document: {hidden: false}, Alpine,
        setTimeout: window.setTimeout, clearTimeout: window.clearTimeout});
    vm.runInContext(components, context);
    vm.runInContext(notifications, context);
    return {window, store: stores.get('toasts'), timers, respond(note) {
        window.checkLatestMessage();
        const request = requests.at(-1);
        request.done(note); request.always();
    }};
}
const {window, store, timers, respond} = fixture();
respond({ID: 10, DATA: 'night'});
respond({ID: 10, DATA: 'night'});
respond('{"ID":10,"DATA":"night"}');
assert.equal(store.items.length, 0, 'Baseline and repeated parsed JSON objects never create a toast');
respond({ID: 11, DATA: 'Новое сообщение'});
assert.equal(store.items.length, 1);
assert.equal(store.items[0].title, 'Журнал сообщений');
assert.equal(store.items[0].text, 'Новое сообщение');
respond({ID: 11, DATA: 'Новое сообщение'});
assert.equal(store.items.length, 1, 'Unchanged note is shown once');
respond({ID: 12, DATA: 'Новое сообщение'});
assert.equal(store.items.length, 2, 'Distinct journal entries with identical text remain distinct');
window.MDJUIToast.dismiss();
assert.equal(store.items.length, 0);
for (let i = 0; i < 5; i++) window.MDJUIToast.show({title: 'Сообщение ' + i});
assert.equal(store.items.length, 3, 'Store enforces three visible messages');
assert.equal(store.items[0].title, 'Сообщение 2');
assert.equal(timers.size, 4, 'Only visible toast timers and one poll timer remain');
window.MDJUIToast.dismiss();
window.MDJAdminUI.showToast({type: 'error', message: 'Ошибка операции'});
assert.equal(store.items[0].type, 'danger');
assert.equal(store.items[0].title, 'Ошибка');
assert.equal(store.items[0].text, 'Ошибка операции');
const legacy = window.jQuery.toast({heading: 'Дополнение', text: ['Первая строка', 'Вторая строка'], icon: 'warning', hideAfter: false, position: 'bottom-left'});
assert.equal(store.items.at(-1).text, 'Первая строка\nВторая строка');
assert.equal(store.items.at(-1).duration, 0);
legacy.update({text: 'Обновлено'});
assert.equal(store.items.length, 2);
assert.equal(store.items.at(-1).text, 'Обновлено');
legacy.reset();
assert.equal(store.items.length, 1);
window.jQuery.toast().reset('all');
assert.equal(store.items.length, 0);
assert.equal(timers.size, 1);
const fallback = fixture();
fallback.respond({DATA: 'night'});
fallback.respond({DATA: 'night'});
assert.equal(fallback.store.items.length, 0, 'Older endpoints without ID also deduplicate content');
fallback.respond({DATA: 'day'});
assert.equal(fallback.store.items.length, 1);
console.log('Notifications smoke: polling deduplication, shared store, legacy adapter and timer cleanup passed.');
