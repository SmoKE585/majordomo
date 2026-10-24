// 2026-10-24 — голосовой ввод, свёрнутые этапы, прокрутка потока и единый фон кода в обеих темах.
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
let run = null, runResult = null, decisionCount = 0, lastSend = null, sendCount = 0;
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
            if (op === 'run_status') return json({run, result: runResult});
            if (op === 'editor_state') return json({enabled: true, agents: [{id: 1, title: 'Редактор', profile: 'editor'}], default_agent: 1});
            if (op === 'dialog_create') return json({dialog: {id: fixture.dialog_id}});
            if (op === 'action_decide') { decisionCount++; return json({action: {...fixture.action, status: 'created', result: 'Создано. Код не запускался.'}}); }
            if (op === 'cancel') { if (run) run.STATUS = 'cancelled'; return json({cancelled: true}); }
            if (op === 'send') {
                sendCount++;
                lastSend = data;
                runResult = null;
                run = {ID: data.request_id, STATUS: 'running'};
                res.writeHead(200, {'Content-Type': 'text/event-stream'});
                const event = (name, value) => res.write(`event: ${name}\ndata: ${JSON.stringify(value)}\n\n`);
                event('run', {run_id: run.ID});
                event('tool', {tool: {name: 'find_objects', arguments: '{"query":"свет в туалете"}'}});
                event('tool_result', {name: 'find_objects'});
                event('token', {text: 'Часть ответа'});
                if (data.prompt === 'обрыв') {
                    run.STATUS = 'error'; run.ERROR = 'Истёк лимит времени агента. Проверка восстановления.';
                    return res.end();
                }
                if (data.prompt === 'потерянный ответ') {
                    const interrupted = run;
                    setTimeout(() => {
                        interrupted.STATUS = 'done';
                        runResult = {status: 'done', html: fixture.answer_html, content: 'Полный сохранённый ответ', actions: []};
                    }, 200);
                    return res.end();
                }
                if (data.prompt === 'ожидание после обрыва') return res.end();
                if (data.prompt === 'длинный') {
                    for (let part = 1; part <= 10; part++) {
                        await sleep(70);
                        event('token', {text: `\nЧасть ${part}: ` + 'Объяснение кода и найденных устройств. '.repeat(25)});
                    }
                }
                event('done', {html: (data.prompt === 'длинный' ? '<p>Подробный ответ.</p>'.repeat(35) : '') + fixture.answer_html, actions: []});
                run.STATUS = 'done'; return res.end();
            }
            return json({});
        }
        if (url.pathname === '/') {
            const host = (await readFile(path.join(root, 'templates/panel.html'), 'utf8')).match(/<aside class="md-admin-drawer md-drawer"[\s\S]*?<\/aside>/)[0];
            const scripts = ['20_drawer', '123_code_sample', '129_ai_runtime', '130_ai_editor'].map(name => `<script src="/resources/js/modules/admin_panel/${name}.js"></script>`).join('');
            res.writeHead(200, {'Content-Type': 'text/html; charset=utf-8'});
            return res.end(`<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="md-csrf-token" content="fixture"><link rel="stylesheet" href="/assets/admin/admin.css"></head><body class="md-admin-shell"><div class="md-admin-drawer-backdrop" hidden></div>${host}<main style="padding:16px"><a href="/panel/ai_assistant.html" data-ai-open>Спросить помощника</a>${fixture.page}</main><script>class SpeechRecognitionFixture { constructor() { window.lastSpeechRecognition=this; } start() {} stop() { this.onend(); } abort() { this.onend(); } emit(text, isFinal) { this.onresult({resultIndex: 0, results: [{0: {transcript: text}, isFinal: isFinal}]}); } } window.SpeechRecognition=SpeechRecognitionFixture;window.webkitSpeechRecognition=SpeechRecognitionFixture;window.ROOTHTML='/';window.MDJAdminPanel={addBootTask(n,fn){window.drawerInit=fn}};window.MDJAdminUI={registerModuleUI(n,ui){ui.init(document)},openDrawer(o){return window.MDJAdminDrawerHost.open(o)},closeDrawer(){return window.MDJAdminDrawerHost.close()}};</script>${scripts}<script>window.drawerInit(document);</script></body></html>`);
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
    assert.equal(await evaluate('document.querySelector("[data-ai-voice]").hidden'), false, 'Голосовой ввод доступен в поддерживаемом браузере.');
    assert.equal(await evaluate('typeof window.webkitSpeechRecognition'), 'function');
    assert.equal(await evaluate('document.querySelector("[data-ai-voice]").disabled'), false);
    await evaluate('document.querySelector("[data-ai-prompt]").value="сохрани ";document.querySelector("[data-ai-voice]").click()');
    assert.equal(await evaluate('document.querySelector("[data-ai-voice]").getAttribute("aria-pressed")'), 'true');
    await evaluate('window.lastSpeechRecognition.emit("включи свет", true);window.lastSpeechRecognition.stop()');
    await waitFor('document.querySelector("[data-ai-voice]").getAttribute("aria-pressed") === "false"');
    assert.equal(await evaluate('document.querySelector("[data-ai-prompt]").value'), 'сохрани включи свет');
    await evaluate('document.querySelector("[data-ai-action] .md-btn--primary").click()');
    await waitFor('document.querySelector(".md-ai-action__status").textContent.includes("Создано")');
    assert.equal(decisionCount, 1);
    await evaluate('document.querySelector("[data-ai-prompt]").value="обрыв";document.querySelector("[data-ai-composer]").requestSubmit()');
    await waitFor('document.querySelector("[data-ai-messages]").textContent.includes("Истёк лимит времени агента") && !document.querySelector("[data-ai-send]").disabled');
    assert.equal(run.STATUS, 'error', 'Серверная ошибка не заменяется сетевой или отменой.');
    assert.ok(await evaluate('document.querySelector("[data-ai-messages]").textContent.includes("Часть ответа")'));
    const sendsBeforeRecovery = sendCount;
    await evaluate('document.querySelector("[data-ai-prompt]").value="потерянный ответ";document.querySelector("[data-ai-composer]").requestSubmit()');
    await waitFor('document.querySelector("[data-ai-status]").textContent === "Ответ получен" && !document.querySelector("[data-ai-send]").disabled');
    assert.equal(sendCount, sendsBeforeRecovery + 1, 'Восстановление не запускает повторную генерацию.');
    assert.ok(await evaluate('document.querySelector("[data-ai-messages]").lastElementChild.previousElementSibling.querySelector("[data-ai-copy-source]").textContent === "Полный сохранённый ответ"'));
    await evaluate('document.querySelector("[data-ai-prompt]").value="ожидание после обрыва";document.querySelector("[data-ai-composer]").requestSubmit()');
    await waitFor('document.querySelector("[data-ai-status]").textContent.includes("Проверяю результат")');
    await evaluate('document.querySelector("[data-ai-stop]").click()');
    await waitFor('!document.querySelector("[data-ai-send]").disabled');
    assert.equal(run.STATUS, 'cancelled', 'Ожидание восстановления можно остановить.');
    await evaluate('document.querySelector("[data-ai-prompt]").value="продолжить";document.querySelector("[data-ai-composer]").requestSubmit()');
    await waitFor('document.querySelector("[data-ai-status]").textContent === "Ответ получен"');
    await evaluate('document.querySelector("[data-ai-open]").click()');
    await waitFor('document.querySelector("[data-ai-drawer] [data-ai-prompt]") && !document.querySelector("[data-ai-drawer] [data-ai-send]").disabled');
    assert.equal(await evaluate('document.querySelector("#mdAdminDrawerHost").hidden'), false);
    assert.equal(await evaluate('document.querySelectorAll("[data-ai-drawer] .md-ai-chat__side,[data-ai-drawer] .md-ai-chat__top,[data-ai-drawer] a").length'), 0, 'В быстром drawer нет истории, настроек и служебных переходов.');
    await sleep(60); // Drawer завершает собственную установку фокуса.
    await evaluate(`{
        const root = document.createElement('div'); root.id = 'progressFixture';
        document.querySelector('[data-ai-drawer] [data-ai-messages]').append(root);
        window.testSteps = window.MDAssistant.progress(root);
        window.testSteps.event('tool', {tool: {name: 'search_knowledge', arguments: '{"query":"история"}'}});
    }`);
    assert.equal(await evaluate('document.querySelector("#progressFixture details").open'), false);
    assert.equal(await evaluate('document.querySelector("#progressFixture summary").textContent'), 'Ищу в справке');
    assert.equal(await evaluate('document.querySelector("#progressFixture ol").checkVisibility()'), false, 'История скрыта по умолчанию.');
    await evaluate('document.querySelector("#progressFixture summary").click();window.testSteps.event("tool_result",{})');
    assert.equal(await evaluate('document.querySelector("#progressFixture details").open'), true, 'Новые шаги не закрывают раскрытую историю.');
    assert.ok(await evaluate('document.querySelector("#progressFixture ol").checkVisibility()'));
    await evaluate('document.querySelector("#progressFixture summary").focus()');
    assert.ok(await evaluate('document.activeElement === document.querySelector("#progressFixture summary")'));
    await call('Input.dispatchKeyEvent', {type: 'keyDown', key: 'Enter', code: 'Enter', windowsVirtualKeyCode: 13, text: '\r'});
    await call('Input.dispatchKeyEvent', {type: 'keyUp', key: 'Enter', code: 'Enter', windowsVirtualKeyCode: 13});
    assert.equal(await evaluate('document.querySelector("#progressFixture details").open'), false, 'Раскрытие доступно с клавиатуры.');
    await evaluate('window.testSteps.event("error",{});window.testSteps.finish(false)');
    assert.equal(await evaluate('document.querySelector("#progressFixture summary").textContent'), 'Работа прервана');
    await evaluate('document.querySelector("#progressFixture").remove()');
    const atBottom = selector => `(() => {const s=document.querySelector(${JSON.stringify(selector)});return s.scrollHeight>s.clientHeight && s.scrollHeight-s.clientHeight-s.scrollTop<3})()`;
    await evaluate('document.querySelector("[data-ai-drawer] [data-ai-prompt]").value="длинный";document.querySelector("[data-ai-drawer] [data-ai-composer]").requestSubmit()');
    await waitFor('document.querySelector("[data-ai-drawer] [data-ai-messages]").textContent.includes("Часть 3:")');
    await waitFor(atBottom('[data-ai-drawer] [data-ai-messages]'));
    assert.ok(await evaluate('document.querySelector("[data-ai-drawer] [data-ai-send]").disabled'), 'Прокрутка происходит во время потока.');
    await waitFor('!document.querySelector("[data-ai-drawer] [data-ai-send]").disabled');
    await waitFor(atBottom('[data-ai-drawer] [data-ai-messages]'));
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
    await evaluate('document.querySelector(".md-ai-editor__prompt").value="длинный";document.querySelector(".md-ai-editor__bar .md-btn--primary").click()');
    await waitFor('document.querySelector(".md-ai-editor__answer").textContent.includes("Часть 3:")');
    await waitFor(atBottom('#mdAdminDrawerBody'));
    assert.ok(await evaluate('document.querySelector(".md-ai-editor__bar .md-btn--primary").disabled'), 'Редактор прокручивается во время потока.');
    await waitFor('!document.querySelector(".md-ai-editor__bar .md-btn--primary").disabled');
    await waitFor(atBottom('#mdAdminDrawerBody'));
    // Многострочный код воспроизводит конфликт фона и двойную прокрутку со снимка.
    await evaluate(`{
        const code = document.querySelector('.md-ai-editor__answer .md-code-sample__code');
        code.textContent = '// Пример сценария\\n' + ('$value = getGlobal(\\'Light.status\\'); // Значение свойства\\n').repeat(30);
        delete code.dataset.mdHighlighted; delete code.dataset.highlighted;
        window.MDCodeSample.init(document.querySelector('.md-ai-editor__answer'));
    }`);
    await waitFor('document.querySelector(".md-ai-editor__answer .md-code-sample__code").dataset.mdHighlighted === "1"');
    for (const theme of ['light', 'dark']) {
        await evaluate(`document.documentElement.dataset.bsTheme=${JSON.stringify(theme)}`);
        const styles = await evaluate(`(() => {
            const pre=document.querySelector('.md-ai-editor__answer .md-code-sample__pre');
            const scroll=pre.closest('.md-code-sample__scroll');
            return {background:getComputedStyle(pre).backgroundColor,overflow:getComputedStyle(pre).overflowX,
                codeColor:getComputedStyle(pre.querySelector('code')).color,scrollBackground:getComputedStyle(scroll).backgroundColor,
                gutterBackground:getComputedStyle(scroll.querySelector('.md-code-sample__gutter')).backgroundColor};
        })()`);
        assert.equal(styles.background, 'rgba(0, 0, 0, 0)', 'Фон примера не переопределяется ответом: ' + theme);
        assert.equal(styles.overflow, 'visible', 'У pre нет второй прокрутки: ' + theme);
        assert.equal(styles.scrollBackground, styles.gutterBackground, 'Единый фон кода и номеров: ' + theme);
        assert.notEqual(styles.codeColor, styles.scrollBackground);
        await sleep(60);
        const screenshot = await call('Page.captureScreenshot', {format: 'png'});
        await writeFile(path.join(artifactDir, 'editor-' + theme + '.png'), Buffer.from(screenshot.data, 'base64'));
    }
    assert.deepEqual(errors, []);
    console.log('ИИ-помощник браузер: свёрнутые этапы, прокрутка потока, код light/dark, drawer и 390 px — OK. Снимки: ' + artifactDir);
} finally {
    ws?.close(); chrome.kill(); server.closeAllConnections(); await new Promise(resolve => server.close(resolve));
    await sleep(250); await rm(profile, {recursive: true, force: true}); await rm(fixturePath, {force: true});
}
