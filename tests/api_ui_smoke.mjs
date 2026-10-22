// Real browser verification against api_http_smoke.php's isolated fixture.
import {spawn} from 'node:child_process';
import {existsSync} from 'node:fs';
import {writeFile} from 'node:fs/promises';
import {createServer} from 'node:net';
import path from 'node:path';

const [base, fixtureRoot] = process.argv.slice(2);
if (!/^http:\/\/127\.0\.0\.1:\d+$/.test(base || '') || !/^mjd-api-[a-f0-9]+$/.test(path.basename(fixtureRoot || ''))) throw new Error('Only isolated loopback fixture is allowed');
const chrome = ['C:/Program Files/Google/Chrome/Application/chrome.exe', 'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe', 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', 'C:/Program Files/Microsoft/Edge/Application/msedge.exe'].find(existsSync);
if (!chrome) throw new Error('Chrome/Edge unavailable');
const listener = createServer();
await new Promise(resolve => listener.listen(0, '127.0.0.1', resolve));
const port = listener.address().port;
await new Promise(resolve => listener.close(resolve));
const browser = spawn(chrome, ['--headless=new', '--disable-gpu', '--no-first-run', '--no-default-browser-check', '--disable-extensions', '--remote-debugging-port=' + port, '--user-data-dir=' + path.join(fixtureRoot, 'browser-profile'), 'about:blank'], {stdio: 'ignore', windowsHide: true});
let socket;
let checks = 0;
const check = (condition, message) => { if (!condition) throw new Error(message); checks++; };
const pause = ms => new Promise(resolve => setTimeout(resolve, ms));
try {
    let page;
    for (let i = 0; i < 100; i++) {
        try { page = await (await fetch('http://127.0.0.1:' + port + '/json/new?about:blank', {method: 'PUT'})).json(); break; }
        catch { await pause(100); }
    }
    if (!page?.webSocketDebuggerUrl) throw new Error('Browser debugger did not start');
    socket = new WebSocket(page.webSocketDebuggerUrl);
    await new Promise((resolve, reject) => { socket.addEventListener('open', resolve, {once: true}); socket.addEventListener('error', reject, {once: true}); });
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
        } else if (response.method === 'Runtime.exceptionThrown') errors.push(response.params.exceptionDetails.text + ': ' + (response.params.exceptionDetails.exception?.description || ''));
    });
    const send = (method, params = {}) => new Promise((resolve, reject) => {
        const id = ++sequence;
        const timer = setTimeout(() => { pending.delete(id); reject(new Error('CDP timeout: ' + method)); }, 15000);
        pending.set(id, {resolve, reject, timer}); socket.send(JSON.stringify({id, method, params}));
    });
    const evaluate = async expression => {
        const value = await send('Runtime.evaluate', {expression, returnByValue: true, awaitPromise: true});
        if (value.exceptionDetails) throw new Error(value.exceptionDetails.exception?.description || value.exceptionDetails.text);
        return value.result.value;
    };
    const waitFor = async expression => {
        for (let i = 0; i < 100; i++) { if (await evaluate(expression)) return; await pause(75); }
        const diagnostics = await evaluate('({feedback:document.querySelector("[data-api-feedback]")?.textContent, hookFeedback:document.querySelector("[data-api-hook-feedback]")?.textContent, ready:document.readyState, url:location.pathname})');
        throw new Error('UI condition did not become true: ' + expression + '; ' + JSON.stringify(diagnostics) + '; exceptions: ' + errors.join('; '));
    };
    await send('Page.enable'); await send('Runtime.enable');
    for (const theme of ['light', 'dark']) {
        for (const width of [1440, 390]) {
            await send('Emulation.setDeviceMetricsOverride', {width, height: 1000, deviceScaleFactor: 1, mobile: false});
            await send('Page.navigate', {url: base + '/preview?theme=' + theme});
            await waitFor('document.querySelector("[data-md-api]")?.dataset.apiBound === "1"');
            await evaluate('document.fonts.ready.then(() => true)');
            await pause(200);
            const dimensions = await evaluate('({scroll: document.documentElement.scrollWidth, client: document.documentElement.clientWidth, tabs: [...document.querySelectorAll(".tab-pane")].filter(e => getComputedStyle(e).display !== "none").length, operations: document.querySelectorAll("[data-api-operation]").length})');
            check(dimensions.scroll <= dimensions.client + 1, theme + ' ' + width + ': no horizontal page overflow');
            check(dimensions.tabs === 1 && dimensions.operations >= 20, 'Only selected tab visible, all operation cards rendered');
            check(await evaluate('(() => { const h = document.querySelector("#md-api-tab-tokens").getAttribute("href"); return h.includes("tab=tokens") && !h.includes("pd="); })()'), 'Tabs are clean and deep-linkable');
            await evaluate('document.querySelector("[data-api-operation] summary").click()');
            check(await evaluate('document.querySelector("[data-api-operation]").open'), 'Native disclosure opens');
            await evaluate('document.querySelector("[data-api-search]").value = "no_such_operation"; document.querySelector("[data-api-search]").dispatchEvent(new Event("input", {bubbles:true}))');
            check(await evaluate('document.querySelector("[data-api-empty]").hidden === false && [...document.querySelectorAll("[data-api-operation]")].every(e => e.hidden)'), 'Search and empty state');
            check(await evaluate('[...document.querySelectorAll("[data-api-group]")].every(e => e.hidden) && [...document.querySelectorAll("[data-api-nav]")].every(e => e.hidden)'), 'Search hides empty groups and index rows');
            await evaluate('document.querySelector("[data-api-search]").value = "period"; document.querySelector("[data-api-search]").dispatchEvent(new Event("input", {bubbles:true}))');
            check(await evaluate('[...document.querySelectorAll("[data-api-group]")].filter(e => !e.hidden).length === 1 && [...document.querySelectorAll("[data-api-operation]")].filter(e => !e.hidden).length === 1'), 'Filter matches parameter names and keeps one group');
            await evaluate('document.querySelector("[data-api-search]").value = ""; document.querySelector("[data-api-search]").dispatchEvent(new Event("input", {bubbles:true})); document.querySelector("#md-api-tab-tokens").click()');
            await waitFor('document.querySelector("#md-api-tokens").classList.contains("active")');
            await pause(180);
            check(await evaluate('new URL(location.href).searchParams.get("tab") === "tokens"'), 'Selected tab is reflected in the URL');
            const shot = await send('Page.captureScreenshot', {format: 'png', captureBeyondViewport: false});
            await writeFile(path.join(fixtureRoot, 'api-' + theme + '-' + width + '.png'), Buffer.from(shot.data, 'base64'));
        }
    }
    const tokenCount = await evaluate('Number(document.querySelector("[data-api-tab-count]").textContent)');
    await evaluate('document.querySelector("#md-api-token-name").value = "Browser integration"; document.querySelector("form[data-api-action]").requestSubmit()');
    await waitFor('!document.querySelector("[data-api-secret]").hidden');
    check(await evaluate('document.querySelector("[data-api-token-list]").textContent.includes("Browser integration")'), 'Issue token updates list');
    check(await evaluate('/mdm_[a-f0-9]{64}/.test(document.querySelector("[data-api-secret]").textContent)'), 'Full token shown once');
    check(await evaluate('document.activeElement === document.querySelector("[data-api-secret]")'), 'Secret receives focus after issue');
    check(await evaluate('Number(document.querySelector("[data-api-tab-count]").textContent) === ' + (tokenCount + 1)), 'Tab badge follows token count');
    check(await evaluate('document.querySelector("[data-api-tab-count]").hidden === false'), 'Tab badge becomes visible after the first issue');
    check(await evaluate('document.querySelector("[data-api-stat=\\"total\\"] .md-kpi__value").textContent === String(' + (tokenCount + 1) + ')'), 'Token KPI follows token count');
    check(await evaluate('document.querySelector(".md-api-hero .md-section-head__description").textContent.includes(' + JSON.stringify(String(tokenCount + 1) + ' токен') + ')'), 'Hero summary follows token count');
    const firstSecret = await evaluate('document.querySelector("[data-api-secret]").textContent');
    await evaluate('[...document.querySelectorAll("[data-api-token-list] form")].find(f => f.elements.operation.value === "rotate").requestSubmit()');
    await waitFor('document.querySelector("#md-api-confirm").classList.contains("show")');
    await waitFor('document.querySelector("#md-api-confirm").contains(document.activeElement)');
    check(await evaluate('document.querySelector("#md-api-confirm").contains(document.activeElement)'), 'Confirmation traps focus');
    check(await evaluate('document.querySelector("#md-api-confirm .modal-title").textContent.includes("Перевыпустить")'), 'Rotate confirmation names the action');
    await evaluate('document.querySelector("[data-api-confirm-submit]").click()');
    await waitFor('!document.querySelector("#md-api-confirm").classList.contains("show") && document.querySelector("[data-api-secret]").textContent.includes("mdm_") && document.querySelector("[data-api-secret]").textContent !== ' + JSON.stringify(firstSecret));
    await pause(400);
    await evaluate('[...document.querySelectorAll("[data-api-token-list] form")].find(f => f.elements.operation.value === "revoke").requestSubmit()');
    await waitFor('document.querySelector("#md-api-confirm").classList.contains("show")');
    check(await evaluate('document.querySelector("#md-api-confirm .modal-title").textContent.includes("Отозвать")'), 'Revoke confirmation names the action');
    await pause(200);
    await evaluate('document.querySelector("[data-api-confirm-submit]").click()');
    await waitFor('document.querySelector("[data-api-token-list]").textContent.includes("Browser integration") && document.querySelector("[data-api-secret]").hidden');
    check(await evaluate('document.querySelector("[data-api-token-list]").textContent.includes("Отозван")'), 'Revoke updates status');
    await send('Page.navigate', {url: base + '/preview?tab=tokens'});
    await waitFor('document.querySelector("[data-md-api]")?.dataset.apiBound === "1" && document.querySelector("#md-api-tokens").classList.contains("active")');
    check(await evaluate('document.querySelector("#md-api-tab-tokens").getAttribute("aria-selected") === "true"'), 'Tab deep link opens tokens pane');
    await send('Page.navigate', {url: base + '/preview?tab=hooks'});
    await waitFor('document.querySelector("[data-md-api]")?.dataset.apiBound === "1" && document.querySelector("#md-api-hooks").classList.contains("active")');
    check(await evaluate('document.querySelector("[data-api-tab-count=\\"hooks\\"]") !== null'), 'Hooks tab has its own counter');
    check(await evaluate(`(() => {
        const form = document.querySelector('#md-api-hooks form[data-api-action]');
        form.elements.name.value = 'UI fixture hook';
        form.elements.module.value = 'fixturehook';
        form.elements.module.dispatchEvent(new Event('change', {bubbles: true}));
        form.elements.handler.value = 'webhook.php';
        form.requestSubmit();
        return true;
    })()`), 'Hook creation form submits');
    await waitFor('!document.querySelector("[data-api-hook-secret]").hidden');
    check(await evaluate('/\\/api\\/hooks\\/[a-f0-9]{64}/.test(document.querySelector("[data-api-hook-secret]").textContent)'), 'Full hook link shown once');
    check(await evaluate('document.activeElement === document.querySelector("[data-api-hook-secret]")'), 'Hook secret receives focus');
    check(await evaluate('document.querySelector("[data-api-hook-list]").textContent.includes("UI fixture hook")'), 'Hook list updates after create');
    await pause(300);
    await evaluate('document.querySelector("[data-hook-edit]").click()');
    await waitFor('document.querySelector("#md-api-hook-edit").classList.contains("show")');
    check(await evaluate('document.querySelector("#md-api-hook-edit-name").value === "UI fixture hook" && document.querySelector("#md-api-hook-edit-handler").value === "webhook.php"'), 'Edit modal is prefilled');
    await pause(500);
    await evaluate(`(() => {
        const form = document.querySelector('#md-api-hook-edit-form');
        form.elements.name.value = 'UI fixture renamed';
        form.requestSubmit();
        return true;
    })()`);
    await waitFor('!document.querySelector("#md-api-hook-edit").classList.contains("show") && document.querySelector("[data-api-hook-list]").textContent.includes("UI fixture renamed")');
    check(await evaluate('document.querySelector("[data-api-hook-secret]").hidden'), 'Secret is cleared after a later action');
    check(await evaluate('document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1'), 'Hooks tab has no horizontal overflow at 390 px');
    await send('Page.navigate', {url: base + '/preview'});
    await waitFor('document.querySelector("[data-md-api]")?.dataset.apiBound === "1"');
    check(await evaluate('document.querySelector("[data-api-secret]").hidden && !/mdm_[a-f0-9]{64}/.test(document.body.textContent)'), 'Reload never redisplays plaintext token');
    check(errors.length === 0, 'No browser exceptions: ' + errors.join('\n'));
    console.log('API UI smoke: ' + checks + ' passed (light/dark, 390/1440 px, token lifecycle).');
} finally {
    if (socket?.readyState === WebSocket.OPEN) socket.close();
    browser.kill();
    await new Promise(resolve => { if (browser.exitCode !== null) resolve(); else { browser.once('exit', resolve); setTimeout(resolve, 2000); } });
}
