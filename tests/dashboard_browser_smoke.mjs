// 2026-10-24 — реальный ApexCharts, Redis, повторный partLoad и обе темы на локальных фикстурах.
import assert from 'node:assert/strict';
import {execFileSync, spawn} from 'node:child_process';
import {createServer} from 'node:http';
import {readFile, writeFile, mkdtemp, rm} from 'node:fs/promises';
import {existsSync} from 'node:fs';
import {tmpdir} from 'node:os';
import path from 'node:path';

const root = path.resolve(import.meta.dirname, '..');
const output = execFileSync('php', ['tests/dashboard_smoke.php', '--export-ui'], {cwd: root, encoding: 'utf8'});
const fixturePath = output.match(/UI_FIXTURE=(.+)/)[1].trim();
assert.ok(path.resolve(fixturePath).startsWith(path.resolve(tmpdir()) + path.sep));
const fixture = JSON.parse(await readFile(fixturePath, 'utf8'));
const artifacts = await mkdtemp(path.join(tmpdir(), 'mjd-dashboard-browser-'));
const payload = {
    generated_ts: 1, generated_at: '2026-10-24 00:05:30', activity: fixture.activity,
    counts: {}, cycles: {items: [], counts: {total: 0}}, errors: {active: 0, total: 0, items: []}, attention: [],
    system: {cpu: {percent: 15}, memory: {percent: 30}, disk: {percent: 40}},
    db: {qps: 18, connections: {max: 100, connected: 3}, status_text: 'Нагрузка в норме'},
    redis: {enabled: true, available: true, level: 'ok', status_text: 'Доступен', latency_ms: 0.15,
        memory_used: 1048576, memory_max: 0, clients: 3, ops: 12, keys: 42, uptime_label: '1 ч 10 мин',
        evicted_keys: 0, note: 'Показатели нагрузки — по всему серверу Redis.'}
};
const setup = `window.payload=${JSON.stringify(payload)};
window.uiModules={};window.MDJAdminUI={registerModuleUI:(name,ui)=>{uiModules[name]=ui;ui.init(document);}};
window.MDJAdminApi={dashboard:async()=>payload,updates:async()=>({needUpdate:0}),xray:{content:async()=>({LIST:[],TOTAL:0})}};`;
const server = createServer(async (req, res) => {
    try {
        const url = new URL(req.url, 'http://127.0.0.1');
        if (url.pathname === '/') {
            res.writeHead(200, {'Content-Type': 'text/html; charset=utf-8'});
            return res.end(`<!doctype html><html data-bs-theme="light"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="/assets/admin/admin.css"><script>${setup}</script>
<script src="/runtime.js"></script><body class="md-admin-shell"><main id="fixture">${fixture.html}</main></body></html>`);
        }
        const file = url.pathname === '/runtime.js' ? path.join(root, 'resources/js/modules/admin_panel/100_chart_runtime.js')
            : path.resolve(root, 'public', '.' + url.pathname);
        if (url.pathname !== '/runtime.js' && !file.startsWith(path.join(root, 'public/assets') + path.sep)) {
            res.writeHead(404); return res.end();
        }
        res.writeHead(200, {'Content-Type': file.endsWith('.js') ? 'application/javascript' : file.endsWith('.css') ? 'text/css' : 'application/octet-stream'});
        res.end(await readFile(file));
    } catch (e) { res.writeHead(500); res.end(String(e)); }
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
const chromePath = ['C:/Program Files/Google/Chrome/Application/chrome.exe', 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'].find(existsSync);
assert.ok(chromePath, 'Для проверки нужен Chrome/Edge');
const profile = await mkdtemp(path.join(tmpdir(), 'mjd-dashboard-chrome-'));
const chrome = spawn(chromePath, ['--headless=new', '--disable-gpu', '--no-first-run', '--no-default-browser-check', '--remote-debugging-port=0', `--user-data-dir=${profile}`, 'about:blank'], {stdio: 'ignore', windowsHide: true});
const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
let ws;
try {
    let port;
    for (let i = 0; i < 100; i++) {
        try { port = (await readFile(path.join(profile, 'DevToolsActivePort'), 'utf8')).split('\n')[0]; break; } catch { await sleep(50); }
    }
    assert.ok(port);
    const target = await fetch(`http://127.0.0.1:${port}/json/new?about:blank`, {method: 'PUT'}).then(r => r.json());
    ws = new WebSocket(target.webSocketDebuggerUrl);
    await new Promise(resolve => ws.addEventListener('open', resolve, {once: true}));
    let sequence = 0; const pending = new Map(), errors = [];
    ws.addEventListener('message', ({data}) => {
        const event = JSON.parse(data);
        if (pending.has(event.id)) { const entry = pending.get(event.id); pending.delete(event.id); event.error ? entry.reject(event.error) : entry.resolve(event.result); }
        else if (event.method === 'Runtime.exceptionThrown') errors.push(event.params.exceptionDetails.exception?.description || event.params.exceptionDetails.text);
    });
    const call = (method, params = {}) => new Promise((resolve, reject) => { const id = ++sequence; pending.set(id, {resolve, reject}); ws.send(JSON.stringify({id, method, params})); })
        .catch(error => { throw new Error(method + ': ' + JSON.stringify(error) + ' ' + (params.expression || '')); });
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
    await waitFor('document.querySelector("[data-md-dash-chart=activity] .apexcharts-svg") && document.querySelector("[data-md-dash-field=activity-methods]").textContent === "3"');
    assert.equal(await evaluate('document.querySelector("[data-md-dash-field=redis-keys]").textContent'), '42');
    assert.equal(await evaluate('document.querySelector("[data-md-dash-field=redis-limit]").textContent'), 'Без лимита');
    const chart = 'Apex._chartInstances.find(x=>x.chart.el.matches("[data-md-dash-chart=activity]")).chart';
    assert.deepEqual(await evaluate(`${chart}.w.config.series.map(s=>s.name)`), ['Изменения свойств', 'Вызовы методов', 'Запуски сценариев']);
    assert.equal(await evaluate(`${chart}.w.config.tooltip.x.formatter('', {dataPointIndex:144})`), fixture.activity.ranges[144]);
    assert.equal(await evaluate(`${chart}.w.config.stroke.curve`), 'straight');
    for (const width of [1440, 390, 360]) for (const theme of ['light', 'dark']) {
        await call('Emulation.setDeviceMetricsOverride', {width, height: 1000, deviceScaleFactor: 1, mobile: false});
        await evaluate(`document.documentElement.setAttribute('data-bs-theme', '${theme}')`);
        await sleep(450);
        assert.ok(await evaluate('document.documentElement.scrollWidth <= innerWidth + 1'), `Нет горизонтальной прокрутки ${width} ${theme}`);
        assert.equal(await evaluate('document.querySelectorAll("[data-md-dash-chart=activity] .apexcharts-svg").length'), 1);
        await writeFile(path.join(artifacts, `${width}-${theme}.png`), Buffer.from((await call('Page.captureScreenshot', {format: 'png', captureBeyondViewport: true})).data, 'base64'));
    }
    await evaluate(`payload.redis={enabled:true,available:false,level:'danger',status_text:'Недоступен'};document.querySelector('[data-md-dash-refresh]').click()`);
    await waitFor('document.querySelector("[data-md-dash-redis-status]").textContent === "Недоступен"');
    assert.equal(await evaluate('document.querySelector("[data-md-dash-field=redis-keys]").textContent'), '—');
    await evaluate(`window.savedModule=uiModules.dashboard;document.querySelector('#fixture').innerHTML=${JSON.stringify(fixture.html)};uiModules.dashboard.init(document)`);
    await waitFor('document.querySelector("[data-md-dash-chart=activity] .apexcharts-svg")');
    await evaluate(`Promise.all(['dashboard-charts.js','dashboard.js'].map(name=>new Promise(resolve=>{let s=document.createElement('script');s.src='/assets/modules/panel/'+name;s.onload=()=>resolve(true);document.head.appendChild(s)})))`);
    assert.ok(await evaluate('uiModules.dashboard === savedModule'), 'Повторный partLoad не создаёт второй runtime');
    await evaluate(`payload.redis={enabled:false,available:false,level:'neutral',status_text:'Отключён'};payload.activity.available=false;MDJDashboardCharts.renderActivity(payload.activity);document.querySelector('[data-md-dash-refresh]').click()`);
    await waitFor('document.querySelector("[data-md-dash-redis-status]").textContent === "Отключён"');
    assert.equal(await evaluate('document.querySelector("[data-md-dash-field=activity-methods]").textContent'), '—');
    assert.deepEqual(errors, []);
    console.log('Dashboard browser OK: ApexCharts, Redis, partLoad, 1440/390/360 px, light/dark. Снимки: ' + artifacts);
} finally {
    ws?.close(); chrome.kill(); server.closeAllConnections(); await new Promise(resolve => server.close(resolve));
    await sleep(250); await rm(profile, {recursive: true, force: true}); await rm(fixturePath, {force: true});
}
