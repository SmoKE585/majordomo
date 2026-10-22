// Real browser verification against auth_http_smoke.php's isolated fixture.
import {spawn} from 'node:child_process';
import {existsSync} from 'node:fs';
import {writeFile} from 'node:fs/promises';
import {createServer} from 'node:net';
import path from 'node:path';

const [base, fixtureRoot] = process.argv.slice(2);
if (!/^http:\/\/127\.0\.0\.1:\d+$/.test(base || '') || !/^mjd-auth-http-[a-f0-9]+$/.test(path.basename(fixtureRoot || ''))) throw new Error('Only isolated loopback fixture is allowed');
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
    const loginResponses = [];
    socket.addEventListener('message', event => {
        const response = JSON.parse(event.data);
        if (response.id) {
            const entry = pending.get(response.id);
            if (!entry) return;
            pending.delete(response.id); clearTimeout(entry.timer);
            if (response.error) entry.reject(new Error(response.error.message)); else entry.resolve(response.result);
        } else if (response.method === 'Runtime.exceptionThrown') errors.push(response.params.exceptionDetails.text + ': ' + (response.params.exceptionDetails.exception?.description || ''));
        else if (response.method === 'Network.responseReceived' && response.params.response.url.includes('auth=login')) {
            const r = response.params.response;
            loginResponses.push({status:r.status, guard:r.headers['X-Fixture-Guard'], csrf:r.headers['X-Fixture-Csrf'], origin:r.headers['X-Fixture-Origin']});
        }
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
        const diagnostics = await evaluate('({ready:document.readyState, url:location.href, text:document.body.innerText.slice(0,800)})');
        throw new Error('UI condition did not become true: ' + expression + '; ' + JSON.stringify(diagnostics) + '; checks: ' + JSON.stringify(loginResponses) + '; exceptions: ' + errors.join('; '));
    };
    await send('Page.enable'); await send('Runtime.enable');
    await send('Network.enable');
    const output = path.join(process.cwd(), 'docs/ui-redesign/auth');
    const {mkdir} = await import('node:fs/promises');
    await mkdir(output, {recursive:true});
    for (const theme of ['light', 'dark']) {
        await send('Network.setCookie', {name:'theme', value:theme, url:base, path:'/'});
        for (const width of [1440, 390]) {
            await send('Emulation.setDeviceMetricsOverride', {width, height:1000, deviceScaleFactor:1, mobile:false});
            await send('Page.navigate', {url:base + '/admin.php?auth=login'});
            await waitFor('document.querySelector("#password") && window.Alpine && document.readyState === "complete"');
            await evaluate('document.fonts.ready.then(() => true)');
            check(await evaluate('document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1'), theme + ' ' + width + ' has no overflow');
            check(await evaluate('document.querySelector("#password").autocomplete === "current-password" && document.querySelector("#login").autocomplete === "username"'), 'Password manager attributes');
            check(await evaluate('document.querySelector("button[type=submit]").getBoundingClientRect().height >= 44'), 'Accessible submit target');
            await evaluate('document.querySelector(".md-auth__reveal input").click()');
            await pause(80);
            check(await evaluate('document.querySelector("#password").type === "text"'), 'Password reveal works through Alpine');
            await evaluate('document.querySelector(".md-auth__reveal input").click()');
            await pause(80);
            const shot = await send('Page.captureScreenshot', {format:'png', captureBeyondViewport:false});
            await writeFile(path.join(output, 'login-' + theme + '-' + width + '.png'), Buffer.from(shot.data,'base64'));
        }
    }
    await evaluate('document.querySelector("#login").value="admin"; document.querySelector("#password").value="new-secret"; document.querySelector("form").requestSubmit()');
    await waitFor('location.pathname === "/panel/" && document.querySelector("meta[name=md-csrf-token]")');
    await waitFor('typeof window.MDJAdminUI === "object"');
    const result = await evaluate('fetch("/api/v1/data/Lamp.level", {method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({data:27})}).then(r=>r.json())');
    check(result.ok, 'Browser fetch automatically sends session CSRF');
    const xhrStatus = await evaluate('new Promise(resolve=>{const x=new XMLHttpRequest();x.open("POST","/api/v1/data/Lamp.level");x.setRequestHeader("Content-Type","application/json");x.setRequestHeader("X-CSRF-Token",document.querySelector("meta[name=md-csrf-token]").content);x.onload=()=>resolve(x.status);x.send(JSON.stringify({data:28}));})');
    check(xhrStatus===200,'XHR preserves explicit CSRF without duplicate header');
    await evaluate('document.querySelector("form").requestSubmit()');
    await waitFor('location.search === "?auth=login" && document.querySelector("#login")');
    check(errors.length===0, 'No browser exceptions: '+errors.join('; '));
    console.log('Auth UI smoke: '+checks+' passed (light/dark, 390/1440 px, fetch/XHR/logout).');
} finally {
    if (socket?.readyState === WebSocket.OPEN) socket.close();
    browser.kill();
    await new Promise(resolve => { if (browser.exitCode !== null) resolve(); else { browser.once('exit', resolve); setTimeout(resolve, 2000); } });
}
