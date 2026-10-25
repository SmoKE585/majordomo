// 2026-10-25 — реальная страница jobs, CodeMirror, drawer и partLoad без рабочего сервера.
import assert from 'node:assert/strict';
import {execFileSync, spawn} from 'node:child_process';
import {createServer} from 'node:http';
import {readFile, writeFile, mkdtemp, rm} from 'node:fs/promises';
import {existsSync} from 'node:fs';
import {tmpdir} from 'node:os';
import path from 'node:path';

const root = path.resolve(import.meta.dirname, '..');
const output = execFileSync('php', ['tests/scheduler_smoke.php', '--export-ui'], {cwd: root, encoding: 'utf8'});
const fixturePath = output.match(/UI_FIXTURE=(.+)/)[1].trim();
assert.ok(path.resolve(fixturePath).startsWith(path.resolve(tmpdir()) + path.sep));
const fixture = JSON.parse(await readFile(fixturePath, 'utf8'));
const artifacts = await mkdtemp(path.join(tmpdir(), 'mjd-jobs-browser-'));
const setup = `window.ROOTHTML='/';window.tasks=[];window.uiModules={};window.MDJAdminPanel={addBootTask:(name,fn)=>tasks.push(fn)};
window.MDJAdminUI={registerModuleUI:(name,ui)=>{uiModules[name]=ui},showToast:()=>{}};
window.boot=()=>{tasks.forEach(fn=>fn(document));Object.values(uiModules).forEach(ui=>ui.init(document));};`;
const drawer = `<div class="md-admin-drawer-backdrop" hidden></div><aside class="md-admin-drawer md-drawer" id="mdAdminDrawerHost" tabindex="-1" hidden>
<div class="md-drawer__header"><div><div id="mdAdminDrawerEyebrow"></div><h2 id="mdAdminDrawerTitle"></h2><div id="mdAdminDrawerSubtitle"></div></div><button data-md-drawer-close aria-label="Закрыть">×</button></div>
<div class="md-admin-drawer__body md-drawer__body" id="mdAdminDrawerBody"></div><div class="md-admin-drawer__footer md-drawer__footer" id="mdAdminDrawerFooter"></div></aside>`;
const scripts = {'/drawer.js': 'resources/js/modules/admin_panel/20_drawer.js', '/trace.js': 'resources/js/modules/admin_panel/41_trace.js'};
const server = createServer(async (req, res) => {
    try {
        const url = new URL(req.url, 'http://127.0.0.1');
        if (url.pathname === '/') {
            res.writeHead(200, {'Content-Type': 'text/html; charset=utf-8'});
            return res.end(`<!doctype html><html data-bs-theme="light"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="/assets/admin/admin.css"><link rel="stylesheet" href="/assets/admin/codeeditor.css"><script>${setup}</script>
<script defer src="/drawer.js"></script><script defer src="/trace.js"></script><script defer src="/assets/admin/codeeditor.js"></script>
<body class="md-admin-shell"><main id="fixture" style="padding:24px;max-width:1440px;margin:auto">${fixture.html}</main>${drawer}<script>document.addEventListener('DOMContentLoaded',()=>boot())</script></body></html>`);
        }
        if (url.pathname === '/admin.php') {
            res.writeHead(200, {'Content-Type': 'application/json'});
            return res.end(JSON.stringify(fixture.trace));
        }
        const file = scripts[url.pathname] ? path.join(root, scripts[url.pathname]) : path.resolve(root, 'public', '.' + url.pathname);
        if (!scripts[url.pathname] && !file.startsWith(path.join(root, 'public/assets') + path.sep)) { res.writeHead(404); return res.end(); }
        res.writeHead(200, {'Content-Type': file.endsWith('.js') ? 'application/javascript' : file.endsWith('.css') ? 'text/css' : 'application/octet-stream'});
        res.end(await readFile(file));
    } catch (error) { res.writeHead(500); res.end(String(error)); }
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
const chromePath = ['C:/Program Files/Google/Chrome/Application/chrome.exe', 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'].find(existsSync);
assert.ok(chromePath, 'Для проверки нужен Chrome/Edge');
const profile = await mkdtemp(path.join(tmpdir(), 'mjd-jobs-chrome-'));
const chrome = spawn(chromePath, ['--headless=new', '--disable-gpu', '--no-first-run', '--no-default-browser-check', '--remote-debugging-port=0', `--user-data-dir=${profile}`, 'about:blank'], {stdio: 'ignore', windowsHide: true});
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
let ws;
try {
    let port;
    for (let i = 0; i < 100; i++) {
        try { port = (await readFile(path.join(profile, 'DevToolsActivePort'), 'utf8')).split('\n')[0]; break; } catch { await sleep(50); }
    }
    assert.ok(port);
    const target = await fetch(`http://127.0.0.1:${port}/json/new?about:blank`, {method: 'PUT'}).then(response => response.json());
    ws = new WebSocket(target.webSocketDebuggerUrl);
    await new Promise(resolve => ws.addEventListener('open', resolve, {once: true}));
    let sequence = 0;
    const pending = new Map(), errors = [];
    ws.addEventListener('message', ({data}) => {
        const event = JSON.parse(data);
        if (pending.has(event.id)) { const entry = pending.get(event.id); pending.delete(event.id); event.error ? entry.reject(event.error) : entry.resolve(event.result); }
        else if (event.method === 'Runtime.exceptionThrown') errors.push(event.params.exceptionDetails.exception?.description || event.params.exceptionDetails.text);
    });
    const call = (method, params = {}) => new Promise((resolve, reject) => { const id = ++sequence; pending.set(id, {resolve, reject}); ws.send(JSON.stringify({id, method, params})); });
    const evaluate = async expression => {
        const result = await call('Runtime.evaluate', {expression, awaitPromise: true, returnByValue: true});
        if (result.exceptionDetails) throw new Error(result.exceptionDetails.exception?.description || result.exceptionDetails.text);
        return result.result.value;
    };
    const waitFor = async expression => {
        for (let i = 0; i < 100; i++) { if (await evaluate(`Boolean(${expression})`)) return; await sleep(50); }
        throw new Error('Не дождались: ' + expression);
    };
    await call('Runtime.enable');
    await call('Page.navigate', {url: `http://127.0.0.1:${server.address().port}/`});
    await waitFor('document.querySelector(".cm-editor") && window.MDJAdminDrawerHost');
    for (const width of [1440, 390]) for (const theme of ['light', 'dark']) {
        await call('Emulation.setDeviceMetricsOverride', {width, height: 1000, deviceScaleFactor: 1, mobile: false});
        await evaluate(`document.documentElement.setAttribute('data-bs-theme','${theme}')`);
        await sleep(250);
        assert.ok(await evaluate('document.documentElement.scrollWidth <= innerWidth + 1'), `Нет прокрутки страницы ${width} ${theme}: ` + JSON.stringify(await evaluate('Array.from(document.querySelectorAll(".md-table__scroll,.md-card,.md-card__body,.md-code-editor,.cm-editor,.md-field")).map(n=>({class:n.className,right:n.getBoundingClientRect().right,width:n.getBoundingClientRect().width,overflow:getComputedStyle(n).overflowX,scroll:n.scrollWidth}))')));
        await writeFile(path.join(artifacts, `${width}-${theme}.png`), Buffer.from((await call('Page.captureScreenshot', {format: 'png', captureBeyondViewport: true})).data, 'base64'));
    }
    await evaluate('document.querySelector("[data-md-trace-open]").click()');
    await waitFor('document.querySelector("#mdAdminDrawerBody .md-trace__run")');
    assert.equal(await evaluate('document.querySelector("#mdAdminDrawerTitle").textContent'), fixture.trace.meta.property_name);
    await evaluate('MDJAdminDrawerHost.close()');
    await evaluate(`document.querySelector('#fixture').innerHTML=${JSON.stringify(fixture.html)};boot();boot()`);
    await waitFor('document.querySelector(".cm-editor")');
    assert.equal(await evaluate('document.querySelectorAll(".cm-editor").length'), 1, 'partLoad не дублирует редактор');
    await evaluate('document.querySelector("[data-md-trace-open]").click()');
    await waitFor('document.querySelector("#mdAdminDrawerBody .md-trace__run")');
    assert.deepEqual(errors, []);
    console.log('Jobs browser OK: CodeMirror, trace drawer, partLoad, 1440/390 px, light/dark. Снимки: ' + artifacts);
} finally {
    ws?.close(); chrome.kill(); server.closeAllConnections(); await new Promise(resolve => server.close(resolve));
    await sleep(250); await rm(profile, {recursive: true, force: true}); await rm(fixturePath, {force: true});
}
