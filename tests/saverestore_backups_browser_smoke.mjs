// 2026-11-11 — поиск, фильтры, 20 копий на страницу и мобильная таблица в обеих темах.
import assert from 'node:assert/strict';
import {execFileSync, spawn} from 'node:child_process';
import {createServer} from 'node:http';
import {readFile, writeFile, mkdtemp, rm} from 'node:fs/promises';
import {existsSync} from 'node:fs';
import {tmpdir} from 'node:os';
import path from 'node:path';

const root = path.resolve(import.meta.dirname, '..');
const output = execFileSync('php', ['tests/saverestore_backups_smoke.php', '--export-ui'], {cwd: root, encoding: 'utf8'});
const fixturePath = output.match(/UI_FIXTURE=(.+)/)[1].trim();
assert.ok(path.resolve(fixturePath).startsWith(path.resolve(tmpdir()) + path.sep));
const fixture = await readFile(fixturePath, 'utf8');
const artifacts = await mkdtemp(path.join(tmpdir(), 'mjd-backups-browser-'));
const server = createServer(async (req, res) => {
    try {
        const url = new URL(req.url, 'http://127.0.0.1');
        if (url.pathname === '/') {
            res.writeHead(200, {'Content-Type': 'text/html; charset=utf-8'});
            return res.end(`<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/public/assets/admin/admin.css"><link rel="stylesheet" href="/public/assets/modules/saverestore/saverestore.css"></head><body class="md-admin-shell"><main style="max-width:900px;margin:16px;padding:16px" class="md-card md-ui-page">${fixture}</main><script>window.MDJAdminUI={registerModuleUI(n,ui){ui.init(document)}};</script><script src="/resources/js/modules/admin_panel/118_data_table.js"></script></body></html>`);
        }
        const files = new Set(['/public/assets/admin/admin.css', '/public/assets/modules/saverestore/saverestore.css', '/resources/js/modules/admin_panel/118_data_table.js']);
        const font = /^\/public\/assets\/admin\/fonts\/[a-z0-9.-]+\.(woff2?|ttf)$/i.test(url.pathname);
        if (!files.has(url.pathname) && !font) { res.writeHead(404); return res.end(); }
        res.writeHead(200, {'Content-Type': font ? 'font/woff2' : (url.pathname.endsWith('.js') ? 'application/javascript' : 'text/css')});
        res.end(await readFile(path.join(root, url.pathname.slice(1))));
    } catch (error) { res.writeHead(500); res.end(String(error)); }
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
const chromePath = ['C:/Program Files/Google/Chrome/Application/chrome.exe', 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'].find(existsSync);
assert.ok(chromePath, 'Для UI-проверки нужен Chrome/Edge.');
const profile = await mkdtemp(path.join(tmpdir(), 'mjd-backups-chrome-'));
const chrome = spawn(chromePath, ['--headless=new', '--disable-gpu', '--no-first-run', '--no-default-browser-check', '--remote-debugging-port=0', `--user-data-dir=${profile}`, 'about:blank'], {stdio: 'ignore', windowsHide: true});
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
let ws;
try {
    let debug;
    for (let i = 0; i < 100; i++) {
        try { debug = (await readFile(path.join(profile, 'DevToolsActivePort'), 'utf8')).split('\n')[0]; break; } catch { await sleep(50); }
    }
    assert.ok(debug);
    const target = await fetch(`http://127.0.0.1:${debug}/json/new?about:blank`, {method: 'PUT'}).then(res => res.json());
    ws = new WebSocket(target.webSocketDebuggerUrl);
    await new Promise(resolve => ws.addEventListener('open', resolve, {once: true}));
    let seq = 0; const pending = new Map(), errors = [];
    ws.addEventListener('message', ({data}) => {
        const event = JSON.parse(data);
        if (event.id && pending.has(event.id)) {
            const {resolve, reject} = pending.get(event.id); pending.delete(event.id);
            event.error ? reject(event.error) : resolve(event.result);
        } else if (event.method === 'Runtime.exceptionThrown') errors.push(event.params.exceptionDetails.exception?.description || event.params.exceptionDetails.text);
    });
    const call = (method, params = {}) => new Promise((resolve, reject) => { const id = ++seq; pending.set(id, {resolve, reject}); ws.send(JSON.stringify({id, method, params})); });
    const evaluate = async expression => {
        const result = await call('Runtime.evaluate', {expression, awaitPromise: true, returnByValue: true});
        if (result.exceptionDetails) throw new Error(result.exceptionDetails.exception?.description || result.exceptionDetails.text);
        return result.result.value;
    };
    const waitFor = async expression => {
        for (let i = 0; i < 100; i++) { if (await evaluate(expression)) return; await sleep(30); }
        throw new Error('UI не достиг состояния: ' + expression);
    };
    await call('Runtime.enable'); await call('Page.enable');
    await call('Emulation.setDeviceMetricsOverride', {width: 1360, height: 1000, deviceScaleFactor: 1, mobile: false});
    await call('Page.navigate', {url: `http://127.0.0.1:${server.address().port}`});
    const visible = 'document.querySelectorAll("[data-md-row]:not([hidden])").length';
    await waitFor(visible + ' === 20');
    assert.equal(await evaluate('document.querySelector("[data-md-table-count]").textContent'), 'Показано 1–20 из 31');
    await evaluate('document.querySelectorAll("[data-md-table-page]")[2].click()');
    assert.equal(await evaluate(visible), 11);
    assert.equal(await evaluate('document.querySelector("[data-md-table-count]").textContent'), 'Показано 21–31 из 31');
    await evaluate('document.querySelector("[data-md-table-search]").value="10.11.2026";document.querySelector("[data-md-table-search]").dispatchEvent(new Event("input"))');
    await waitFor(visible + ' === 1');
    assert.ok(await evaluate('document.querySelector("[data-md-row]:not([hidden])").textContent.includes("База данных перед обновлением")'));
    await evaluate('document.querySelector("[data-md-table-search]").value="нет такой копии";document.querySelector("[data-md-table-search]").dispatchEvent(new Event("input"))');
    await waitFor('document.querySelector("[data-md-table-empty]").classList.contains("is-visible")');
    assert.equal(await evaluate(visible), 0);
    await evaluate('document.querySelector("[data-md-table-search]").value="";document.querySelector("[data-md-table-search]").dispatchEvent(new Event("input"));document.querySelector("[data-md-table-filter=kind]").value="archive";document.querySelector("[data-md-table-filter=kind]").dispatchEvent(new Event("change"))');
    await waitFor(visible + ' === 2');
    await evaluate('document.querySelector("[data-md-table-filter=source]").value="storage";document.querySelector("[data-md-table-filter=source]").dispatchEvent(new Event("change"))');
    await waitFor(visible + ' === 1');
    assert.ok(await evaluate('document.querySelector("[data-md-row]:not([hidden])").textContent.includes("custom.tar.gz")'));
    assert.ok(await evaluate('document.querySelector("[data-md-row]:not([hidden]) a[download]").href.includes("mode=download_backup&source=storage")'));
    for (const [theme, width] of [['light', 1360], ['dark', 390]]) {
        await call('Emulation.setDeviceMetricsOverride', {width, height: 1000, deviceScaleFactor: 1, mobile: width < 600});
        await evaluate(`document.documentElement.dataset.bsTheme=${JSON.stringify(theme)}`); await sleep(100);
        assert.ok(await evaluate('document.documentElement.scrollWidth <= window.innerWidth'), 'Таблица не растягивает страницу: ' + theme);
        assert.ok(await evaluate('document.querySelector("[data-md-row]:not([hidden]) a[download]").getAttribute("download") === "custom.tar.gz"'));
        const screenshot = await call('Page.captureScreenshot', {format: 'png'});
        await writeFile(path.join(artifacts, theme + '.png'), Buffer.from(screenshot.data, 'base64'));
    }
    assert.deepEqual(errors, []);
    console.log('Резервные копии, браузер: пагинация 20/11, поиск, фильтры, скачивание, light/dark и 390 px — OK. Снимки: ' + artifacts);
} finally {
    ws?.close(); chrome.kill(); server.closeAllConnections(); await new Promise(resolve => server.close(resolve));
    await sleep(250); await rm(profile, {recursive: true, force: true}); await rm(fixturePath, {force: true});
}
