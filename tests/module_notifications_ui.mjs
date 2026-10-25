// 2026-10-25 — настоящий браузер, статические шаблоны и API fake без рабочей установки.
import assert from 'node:assert/strict';
import {spawn, spawnSync} from 'node:child_process';
import {existsSync, readFileSync} from 'node:fs';
import {mkdtemp, writeFile, rm} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {createServer} from 'node:net';
import path from 'node:path';
import {fileURLToPath, pathToFileURL} from 'node:url';

const project = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const temporary = await mkdtemp(path.join(tmpdir(), 'mjd-notifications-ui-'));
const browserPath = ['C:/Program Files/Google/Chrome/Application/chrome.exe', 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', 'C:/Program Files/Microsoft/Edge/Application/msedge.exe'].find(existsSync);
assert.ok(browserPath, 'Chrome/Edge required');
const rendered = spawnSync('php', ['-r', `
define('ROOT', str_replace('\\\\', '/', getcwd()) . '/');
define('ROOTHTML', '/');
require ROOT . 'lib/core.class.php';
require ROOT . 'lib/ui/MDJUI.php';
function startMeasure($name) {} function endMeasure($name, $save = 0) {}
function templateSafe($value) { return $value; }
$data = array('AUTH_USERNAME'=>'admin', 'AUTH_USER_NAME'=>'Администратор', 'AUTH_USER_INITIALS'=>'АД', 'AUTH_USER_ROLE'=>'Администратор',
'AUTH_USER_PROFILE_URL'=>'/panel/users/1.html', 'AUTH_CSRF'=>'fixture', 'SUB_MODULES'=>array(array('NEW_CATEGORY'=>1,'CATEGORY_ID'=>'test','CATEGORY'=>'Устройства','SELECTED'=>1,'NAME'=>'tuya','TITLE'=>'Устройства Tuya','ICON_BI'=>'ti-plug-connected','NOTIFICATIONS_TYPE'=>'danger','NOTIFICATIONS_COUNT'=>3,'LAST_IN_CATEGORY'=>1)));
ob_start(); include ROOT . 'templates/panel_notifications_drawer.php'; $drawer = ob_get_clean();
ob_start(); include ROOT . 'templates/panel_notifications_dashboard.php'; $dashboard = ob_get_clean();
$sidebar = new \\MajorDoMo\\Core\\Template\\JTemplate(ROOT . 'templates/panel_nav_left.html', $data);
echo json_encode(array('drawer'=>$drawer, 'dashboard'=>$dashboard, 'sidebar'=>$sidebar->result), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
`], {cwd: project, encoding: 'utf8', windowsHide: true});
assert.equal(rendered.status, 0, rendered.stderr);
const parts = JSON.parse(rendered.stdout);
const panel = readFileSync(path.join(project, 'templates/panel.html'), 'utf8');
const shell = panel.match(/<aside class="md-admin-drawer md-drawer"[\s\S]+?<\/aside>/)[0].replace(/<#LANG_CLOSE#>/g, 'Закрыть');
const fakeApi = `
window.fixtureNotes = Array.from({length: 63}, (_, i) => ({id: i + 1, module: i < 60 ? 'tuya' : 'mqtt', module_title: i < 60 ? 'Устройства Tuya' : 'MQTT', message: i === 0 ? '<img src=x onerror=window.fixtureXss=1> Нет связи с устройством' : 'Сообщение ' + (i + 1), type: i === 0 ? 'danger' : i < 3 ? 'warning' : 'info', added: '2026-10-25 12:34:56', title: i === 0 ? 'Проверьте подключение' : '', details: i === 0 ? '<script>window.fixtureXss=2</scr' + 'ipt> Подробности ошибки' : '', url: i === 0 ? '/panel/tuya.html' : '', label: 'Настроить', module_url: '/panel/' + (i < 60 ? 'tuya' : 'mqtt') + '.html'}));
window.fixtureReads = [];
window.fixtureFail = false;
window.MDJAdminApi = {
  notifications: async function (module, offset) {
    if (window.fixtureFail) throw new Error('Тестовый сбой сети');
    var items = window.fixtureNotes.filter(n => !module || n.module === module);
    var counts = {}; var levels = {};
    window.fixtureNotes.forEach(n => {counts[n.module] = (counts[n.module] || 0) + 1; if (!levels[n.module] || n.type === 'danger') levels[n.module] = n.type;});
    return {items: items.slice(offset || 0, (offset || 0) + 50), total: items.length, counts, levels, severity: items.some(n => n.type === 'danger') ? 'danger' : 'info'};
  },
  dismissNotification: async function (id) {if (window.fixtureFail) throw new Error('Тестовый сбой сети'); window.fixtureReads.push(id); window.fixtureNotes = window.fixtureNotes.filter(n => n.id !== id); return {dismissed: 1};}
};
window.MDJAdminPanel = {addBootTask: function (name, callback) {callback(document);}};
window.MDJAdminUI = {registerModuleUI: function (name, module) {window.fixtureUI = module; module.init();}};
window.fixtureErrors = [];
window.mdjShowErrorToast = function (text) {window.fixtureErrors.push(text);};
`;
const source = name => readFileSync(path.join(project, 'resources/js/modules/admin_panel', name), 'utf8');
const assets = pathToFileURL(path.join(project, 'public')).href + '/';
const html = `<!doctype html><html data-bs-theme="light"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><link rel="stylesheet" href="${assets}assets/admin/admin.css"></head>
<body class="md-admin-shell"><div class="md-admin-layout"><div class="row">${parts.sidebar.replaceAll('src="/assets/', 'src="' + assets + 'assets/').replaceAll('srcset="/assets/', 'srcset="' + assets + 'assets/')}<main class="col-md-9 p-3 ms-auto">${parts.dashboard}<div data-md-notifications-module="tuya" class="md-notifications-list mt-3"></div></main></div></div>
<div class="md-admin-drawer-backdrop" hidden></div>${shell}${parts.drawer}
<script>${fakeApi}</script><script>${source('20_drawer.js')}</script><script>${source('61_module_notifications.js')}</script></body></html>`;
const fixture = path.join(temporary, 'preview.html');
await writeFile(fixture, html);
const listener = createServer();
await new Promise(resolve => listener.listen(0, '127.0.0.1', resolve));
const port = listener.address().port;
await new Promise(resolve => listener.close(resolve));
const profile = path.join(temporary, 'browser-profile');
const browser = spawn(browserPath, ['--headless=new', '--disable-gpu', '--no-first-run', '--no-default-browser-check', '--disable-extensions', '--remote-debugging-port=' + port, '--user-data-dir=' + profile, 'about:blank'], {stdio: 'ignore', windowsHide: true});
const pause = ms => new Promise(resolve => setTimeout(resolve, ms));
let socket;
let checks = 0;
const check = (condition, message) => {assert.ok(condition, message); checks++;};
try {
    let page;
    for (let i = 0; i < 100; i++) {
        try {page = await (await fetch('http://127.0.0.1:' + port + '/json/new?about:blank', {method: 'PUT'})).json(); break;}
        catch {await pause(100);}
    }
    assert.ok(page?.webSocketDebuggerUrl, 'Browser debugger required');
    socket = new WebSocket(page.webSocketDebuggerUrl);
    await new Promise((resolve, reject) => {socket.addEventListener('open', resolve, {once: true}); socket.addEventListener('error', reject, {once: true});});
    let sequence = 0;
    const pending = new Map();
    const errors = [];
    socket.addEventListener('message', event => {
        const response = JSON.parse(event.data);
        if (response.id) {
            const entry = pending.get(response.id);
            if (!entry) return;
            pending.delete(response.id); clearTimeout(entry.timer);
            if (response.error) entry.reject(new Error(response.error.message)); else entry.resolve(response.result);
        } else if (response.method === 'Runtime.exceptionThrown') errors.push(response.params.exceptionDetails.exception?.description || response.params.exceptionDetails.text);
    });
    const send = (method, params = {}) => new Promise((resolve, reject) => {
        const id = ++sequence;
        const timer = setTimeout(() => {pending.delete(id); reject(new Error('CDP timeout: ' + method));}, 15000);
        pending.set(id, {resolve, reject, timer}); socket.send(JSON.stringify({id, method, params}));
    });
    const evaluate = async expression => {
        const value = await send('Runtime.evaluate', {expression, returnByValue: true, awaitPromise: true});
        if (value.exceptionDetails) throw new Error(value.exceptionDetails.exception?.description || value.exceptionDetails.text);
        return value.result.value;
    };
    const waitFor = async expression => {
        for (let i = 0; i < 100; i++) {if (await evaluate(expression)) return; await pause(50);}
        throw new Error('UI timeout: ' + expression + '; ' + errors.join('; '));
    };
    await send('Page.enable'); await send('Runtime.enable');
    for (const theme of ['light', 'dark']) {
        for (const width of [1440, 390]) {
            await send('Emulation.setDeviceMetricsOverride', {width, height: 1000, deviceScaleFactor: 1, mobile: false});
            await send('Page.navigate', {url: pathToFileURL(fixture).href + '?' + theme + width});
            await waitFor('document.querySelectorAll("[data-md-notifications-preview] [data-md-notification-id]").length === 5');
            await evaluate('document.documentElement.dataset.bsTheme=' + JSON.stringify(theme));
            check(await evaluate('window.fixtureReads.length === 0 && window.fixtureNotes.length === 63'), 'Просмотр главной не читает сообщения');
            check(await evaluate('!window.fixtureXss && document.querySelectorAll("[data-md-notifications-preview] img").length === 0'), 'Текст уведомления не исполняет HTML');
            await evaluate('fixtureUI.init(); fixtureUI.init()');
            check(await evaluate('document.querySelector("[data-md-notifications-module-open]").getBoundingClientRect().width >= 44'), 'Счётчик модуля доступен для нажатия');
            await pause(250);
            if (width === 1440) {
                const overview = await send('Page.captureScreenshot', {format: 'png', captureBeyondViewport: false});
                await writeFile(path.join(temporary, theme + '-overview.png'), Buffer.from(overview.data, 'base64'));
            }
            await evaluate('document.querySelector("[data-md-notifications-dashboard] [data-md-notifications-open]").focus(); document.querySelector("[data-md-notifications-dashboard] [data-md-notifications-open]").click()');
            await waitFor('document.querySelectorAll("[data-md-notifications-list] [data-md-notification-id]").length === 50');
            check(await evaluate('window.fixtureReads.length === 0 && MDJAdminDrawerHost.getCurrentOwner() === "module-notifications"'), 'Открытие центра не отмечает прочтение');
            check(await evaluate('document.documentElement.scrollWidth <= innerWidth'), 'Нет горизонтальной прокрутки при ' + width);
            check(await evaluate('!window.fixtureXss && !document.querySelector("[data-md-notifications-list] script")'), 'Подробности остаются обычным текстом');
            const capture = await send('Page.captureScreenshot', {format: 'png', captureBeyondViewport: false});
            await writeFile(path.join(temporary, theme + '-' + width + '.png'), Buffer.from(capture.data, 'base64'));
            await evaluate('document.querySelector("[data-md-notifications-page=\\"1\\"]").click()');
            await waitFor('document.querySelectorAll("[data-md-notifications-list] [data-md-notification-id]").length === 13');
            check(await evaluate('document.querySelector("[data-md-notifications-range]").textContent === "51–63 из 63"'), 'Пагинация списка');
            await evaluate('document.querySelector("[data-md-notifications-page=\\"-1\\"]").click()');
            await waitFor('document.querySelectorAll("[data-md-notifications-list] [data-md-notification-id]").length === 50');
            await evaluate('document.querySelector("[data-md-notifications-list] [data-md-notification-read]").click()');
            await waitFor('document.querySelector("[data-md-notifications-count]").textContent === "62"');
            check(await evaluate('window.fixtureReads.length === 1 && !document.querySelector("[data-md-notification-id=\\"1\\"]")'), 'Прочтение синхронизирует все поверхности без повторных обработчиков после partLoad');
            await send('Input.dispatchKeyEvent', {type: 'keyDown', key: 'Escape', code: 'Escape'});
            await waitFor('MDJAdminDrawerHost.getCurrentOwner() === ""');
            check(await evaluate('document.activeElement === document.querySelector("[data-md-notifications-dashboard] [data-md-notifications-open]")'), 'Esc возвращает фокус на открывшую кнопку');
            await evaluate('MDJModuleNotifications.open("mqtt")');
            await waitFor('document.querySelectorAll("[data-md-notifications-list] [data-md-notification-id]").length === 3');
            check(await evaluate('Array.from(document.querySelectorAll("[data-md-notifications-list] .md-badge")).every(n => n.textContent === "MQTT")'), 'Фильтр модуля');
            await evaluate('window.fixtureFail=true; document.querySelector("[data-md-notifications-list] [data-md-notification-read]").click()');
            await waitFor('window.fixtureErrors.length === 1');
            check(await evaluate('!document.querySelector("[data-md-notifications-list] [data-md-notification-read]").disabled && fixtureReads.length === 1'), 'Неудачное прочтение не скрывает сообщение');
            await evaluate('window.fixtureFail=false; window.fixtureNotes=[]; MDJModuleNotifications.refresh()');
            await waitFor('document.querySelector("[data-md-notifications-list]").textContent.includes("Непрочитанных уведомлений нет")');
            check(await evaluate('document.querySelector("[data-md-notifications-count]").hidden && document.querySelector("[data-md-notifications-module-open]").hidden'), 'Пустое состояние скрывает счётчики');
            check(errors.length === 0, 'Нет ошибок JavaScript');
        }
    }
    console.log('Module notifications UI: ' + checks + ' checks passed. Screenshots: ' + temporary);
} finally {
    if (socket) socket.close();
    browser.kill();
    await pause(400);
    if (path.resolve(path.dirname(profile)) !== path.resolve(temporary) || !path.basename(temporary).startsWith('mjd-notifications-ui-')) throw new Error('Unsafe cleanup path');
    await rm(profile, {recursive: true, force: true, maxRetries: 10, retryDelay: 150});
}
