import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';

// Опрос журнала сообщений входит в общий бандл админки, поэтому он попадает и
// на страницу входа, где window.ROOTHTML не определён.
const source = readFileSync(new URL('../resources/js/modules/admin_panel/60_notifications.js', import.meta.url), 'utf8');

const ajaxUrls = [];
const answers = [];
const timers = [];

function jquery(target) {
    if (typeof target === 'function') {
        target();
        return {on() { return this; }};
    }
    return {on() { return this; }, removeClass() { return this; }, addClass() { return this; }, css() { return this; }, val() { return ''; }};
}
jquery.ajax = function (options) {
    ajaxUrls.push(options.url);
    return {
        done(callback) { answers.push({done: callback}); return this; },
        always(callback) { answers.at(-1).always = callback; return this; }
    };
};

const document = {hidden: false, title: 'Вход', addEventListener() {}};
const window = {location: {href: 'https://home.test/admin.php?auth=login'}, history: {replaceState() {}}};
window.jQuery = jquery;
const context = vm.createContext({
    window,
    document,
    URL,
    console,
    setTimeout: (callback) => timers.push(callback) - 1,
    clearTimeout() {}
});
vm.runInContext(source, context);

assert.equal(typeof window.MDJAdminUI.showToast, 'function', 'модуль зарегистрировал свой UI');
assert.deepEqual(ajaxUrls, [], 'страница без ROOTHTML не опрашивает журнал');

// Вход в систему: оболочка задаёт префикс проекта, опрос идёт по нему.
window.ROOTHTML = '/';
window.checkLatestMessage();
assert.deepEqual(ajaxUrls, ['/getlatestnote.html'], 'оболочка опрашивает журнал по ROOTHTML');
const first = answers[0];
first.done('{"ID":1,"DATA":"тест"}');
first.always();
assert.equal(typeof timers[0], 'function', 'опрос перепланируется');

// Установка в подкаталог не должна терять префикс после ответа.
window.ROOTHTML = '/majordomo/';
timers[0]();
assert.equal(ajaxUrls.at(-1), '/majordomo/getlatestnote.html', 'подкаталог проекта учитывается');

console.log('Panel notifications smoke: no polling outside the admin shell and correct latest-note URL passed.');
