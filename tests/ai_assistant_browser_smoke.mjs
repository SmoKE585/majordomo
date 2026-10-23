// 2026-10-23 — реальный Chrome, PHP-разметка SQLite и локальный HTTP без приложения.
import assert from 'node:assert/strict';
import {execFileSync, spawn} from 'node:child_process';
import {createServer} from 'node:http';
import {readFile, writeFile, mkdtemp, rm} from 'node:fs/promises';
import {existsSync} from 'node:fs';
import {tmpdir} from 'node:os';
import path from 'node:path';

const root = path.resolve(import.meta.dirname, '..');
const output = execFileSync('php', ['tests/ai_assistant_panel_smoke.php', '--export-ui'], {cwd: root, encoding: 'utf8'});
const fixturePath = output.match(/UI_FIXTURE=(.+)/)[1].trim();
assert.ok(path.resolve(fixturePath).startsWith(path.resolve(tmpdir()) + path.sep));
const fixture = JSON.parse(await readFile(fixturePath, 'utf8'));
const artifactDir = await mkdtemp(path.join(tmpdir(), 'mjd-ai-browser-'));
let run = null, decisionCount = 0, lastSend = null;
const server = createServer(async (req, res) => {
    try {
        const url = new URL(req.url, 'http://127.0.0.1');
        const json = (value) => { res.writeHead(200, {'Content-Type': 'application/json'}); res.end(JSON.stringify({ok: true, ...value})); };
        if (req.method === 'POST') {
            let body = ''; for await (const chunk of req) body += chunk;
            const data = Object.fromEntries(new URLSearchParams(body));
            const op = url.searchParams.get('op');
            if (op === 'drawer') return json({html: fixture.drawer});
            if (op === 'messages') return json({dialog: {id: fixture.dialog_id, title: 'Проверка интерфейса', agent_id: 1}, messages: fixture.messages, actions: [fixture.action], run});
            if (op === 'run_status') return json({run});
            if (op === 'editor_state') return json({enabled: true, agents: [{id: 1, title: 'Редактор', profile: 'editor'}], default_agent: 1});
            if (op === 'dialog_create') return json({dialog: {id: fixture.dialog_id}});
            if (op === 'action_decide') { decisionCount++; return json({action: {...fixture.action, status: 'created', result: 'Создано. Код не запускался.'}}); }
            if (op === 'cancel') { if (run) run.STATUS = 'cancelled'; return json({cancelled: true}); }
            if (op === 'send') {
                lastSend = data;
                run = {ID: data.request_id, STATUS: 'running'};
                res.writeHead(200, {'Content-Type': 'text/event-stream'});
                const event = (name, value) => res.write(`event: ${name}\ndata: ${JSON.stringify(value)}\n\n`);
                event('run', {run_id: run.ID});
                event('tool', {tool: {name: 'find_objects', arguments: '{"query":"свет в туалете"}'}});
                event('tool_result', {name: 'find_objects'});
                event('token', {text: 'Часть ответа'});
                if (data.prompt === 'обрыв') return res.end();
                event('done', {html: fixture.answer_html, actions: []});
                run.STATUS = 'done'; return res.end();
            }
            return json({});
        }
        if (url.pathname === '/') {
            const host = (await readFile(path.join(root, 'templates/panel.html'), 'utf8')).match(/<aside class="md-admin-drawer md-drawer"[\s\S]*?<\/aside>/)[0];
            const scripts = ['20_drawer', '123_code_sample', '129_ai_runtime', '130_ai_editor'].map(name => `<script src="/resources/js/modules/admin_panel/${name}.js"></script>`).join('');
            res.writeHead(200, {'Content-Type': 'text/html; charset=utf-8'});
            return res.end(`<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="md-csrf-token" content="fixture"><link rel="stylesheet" href="/assets/admin/admin.css"></head><body class="md-admin-shell"><div class="md-admin-drawer-backdrop" hidden></div>${host}<main style="padding:16px"><a href="/panel/ai_assistant.html" data-ai-open>Спросить помощника</a>${fixture.page}</main><script>window.ROOTHTML='/';window.MDJAdminPanel={addBootTask(n,fn){window.drawerInit=fn}};window.MDJAdminUI={registerModuleUI(n,ui){ui.init(document)},openDrawer(o){return window.MDJAdminDrawerHost.open(o)},closeDrawer(){return window.MDJAdminDrawerHost.close()}};</script>${scripts}<script>window.drawerInit(document);</script></body></html>`);
        }
        const relative = decodeURIComponent(url.pathname).replace(/^\//, '');
        const base = relative.startsWith('assets/') ? path.join(root, 'public') : root;
        const file = path.resolve(base, relative);
        if (!(relative.startsWith('assets/') || /^resources\/js\/modules\/admin_panel\/\d+_[\w]+\.js$/.test(relative)) || !file.startsWith(base + path.sep)) {
            res.writeHead(404); return res.end();
        }
        const type = file.endsWith('.js') ? 'application/javascript' : file.endsWith('.css') ? 'text/css' : 'application/octet-stream';
        res.writeHead(200, {'Content-Type': type}); res.end(await readFile(file));
    } catch (error) { res.writeHead(500); res.end(String(error)); }
});
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
const address = `http://127.0.0.1:${server.address().port}`;
const chromePath = ['C:/Program Files/Google/Chrome/Application/chrome.exe', 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe'].find(existsSync);
assert.ok(chromePath, 'Для UI-проверки нужен Chrome/Edge.');
const profile = await mkdtemp(path.join(tmpdir(), 'mjd-ai-chrome-'));
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
        if (event.id && pending.has(event.id)) { const {resolve, reject} = pending.get(event.id); pending.delete(event.id); event.error ? reject(event.error) : resolve(event.result); }
        else if (event.method === 'Runtime.exceptionThrown') errors.push(event.params.exceptionDetails.exception?.description || event.params.exceptionDetails.text);
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
    await call('Page.navigate', {url: address});
    await waitFor('window.MDAIPage && document.querySelector("[data-ai-send]") && !document.querySelector("[data-ai-send]").disabled');
    assert.ok(await evaluate('document.querySelector("[data-md-code-sample]") !== null'));
    await evaluate('document.querySelector("[data-ai-action] .md-btn--primary").click()');
    await waitFor('document.querySelector(".md-ai-action__status").textContent.includes("Создано")');
    assert.equal(decisionCount, 1);
    await evaluate('document.querySelector("[data-ai-prompt]").value="обрыв";document.querySelector("[data-ai-composer]").requestSubmit()');
    await waitFor('!document.querySelector("[data-ai-recovery]").hidden && !document.querySelector("[data-ai-send]").disabled');
    assert.equal(run.STATUS, 'cancelled');
    assert.ok(await evaluate('document.querySelector("[data-ai-messages]").textContent.includes("Часть ответа")'));
    await evaluate('document.querySelector("[data-ai-prompt]").value="продолжить";document.querySelector("[data-ai-composer]").requestSubmit()');
    await waitFor('document.querySelector("[data-ai-status]").textContent === "Ответ получен"');
    await evaluate('document.querySelector("[data-ai-open]").click()');
    await waitFor('document.querySelector("[data-ai-drawer] [data-ai-prompt]") && !document.querySelector("[data-ai-drawer] [data-ai-send]").disabled');
    assert.equal(await evaluate('document.querySelector("#mdAdminDrawerHost").hidden'), false);
    assert.equal(await evaluate('document.querySelectorAll("[data-ai-drawer] .md-ai-chat__side,[data-ai-drawer] .md-ai-chat__top,[data-ai-drawer] a").length'), 0, 'В быстром drawer нет истории, настроек и служебных переходов.');
    for (const [theme, width] of [['light', 1360], ['dark', 390]]) {
        await call('Emulation.setDeviceMetricsOverride', {width, height: 1000, deviceScaleFactor: 1, mobile: width < 600});
        await evaluate(`document.documentElement.dataset.bsTheme=${JSON.stringify(theme)}`); await sleep(150);
        assert.ok(await evaluate('document.documentElement.scrollWidth <= window.innerWidth'), 'Нет горизонтального переполнения: ' + theme);
        assert.ok(await evaluate('document.querySelector("[data-ai-drawer] [data-ai-send]").getBoundingClientRect().bottom <= window.innerHeight'), 'Отправка доступна без прокрутки оболочки.');
        const screenshot = await call('Page.captureScreenshot', {format: 'png'});
        await writeFile(path.join(artifactDir, theme + '.png'), Buffer.from(screenshot.data, 'base64'));
    }
    await evaluate('document.querySelector("[data-ai-drawer] [data-ai-new-dialog]").click()');
    await waitFor('document.querySelector("[data-ai-drawer] [data-ai-messages]").textContent.trim() === ""');
    await evaluate(`window.MDJAdminUI.closeDrawer();window.testEditor={value:'$x = 1;',getValue(){return this.value},focus(){},view:{state:{selection:{main:{from:0,to:7,empty:false}},sliceDoc(){return window.testEditor.value}},dispatch(change){window.testEditor.value=change.changes.insert}}};document.dispatchEvent(new CustomEvent('md:code-editor-ai',{detail:{editor:window.testEditor,context:{language:'php',title:'Метод',code:'старый текст',object:'',method:''}}}));`);
    await waitFor('document.querySelector(".md-ai-editor__prompt")');
    await evaluate('document.querySelector(".md-ai-editor__prompt").value="объясни";document.querySelector(".md-ai-editor__bar .md-btn--primary").click()');
    await waitFor('document.querySelector(".md-ai-editor__answer [data-md-code-sample]")');
    assert.equal(JSON.parse(lastSend.context).code, '$x = 1;');
    await evaluate('Array.from(document.querySelectorAll(".md-ai-editor__answer button")).find(b => b.textContent === "Вставить в редактор").click()');
    assert.equal(await evaluate('window.testEditor.value'), "cm('LigthToilet.turnOn');");
    assert.deepEqual(errors, []);
    console.log('ИИ-помощник браузер: drawer, обрыв/повтор, подтверждение, код и 390 px — OK. Снимки: ' + artifactDir);
} finally {
    ws?.close(); chrome.kill(); server.closeAllConnections(); await new Promise(resolve => server.close(resolve));
    await sleep(250); await rm(profile, {recursive: true, force: true}); await rm(fixturePath, {force: true});
}
