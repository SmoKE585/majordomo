// Dev-only screenshot helper: launches headless Chrome and captures a page via CDP.
// Usage: node tools/screenshot.mjs --url http://127.0.0.1:8080/admin.php --out docs/ui-redesign/home.png [--width 1600] [--height 1000] [--cookie prj=...] [--full] [--auth user:pass] [--wait 3000]
import { spawn } from 'node:child_process';
import { mkdir, writeFile, rm } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import path from 'node:path';

const args = Object.fromEntries(
  process.argv.slice(2).reduce((acc, cur, i, arr) => {
    if (cur.startsWith('--')) {
      const key = cur.slice(2);
      const val = arr[i + 1] && !arr[i + 1].startsWith('--') ? arr[i + 1] : 'true';
      acc.push([key, val]);
    }
    return acc;
  }, [])
);

const url = args.url || 'http://127.0.0.1:8080/admin.php';
const out = args.out || 'docs/ui-redesign/screenshot.png';
const width = Number(args.width || 1600);
const height = Number(args.height || 1000);
const waitMs = Number(args.wait || 3500);
const fullPage = args.full === 'true';
const cookie = args.cookie || '';
const auth = args.auth || '';

const chromeCandidates = [
  'C:/Program Files/Google/Chrome/Application/chrome.exe',
  'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe',
  'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
  'C:/Program Files/Microsoft/Edge/Application/msedge.exe',
];
const chromePath = chromeCandidates.find((p) => existsSync(p));
if (!chromePath) throw new Error('Chrome/Edge not found');

const port = 9222 + Math.floor(Math.random() * 500);
const profile = path.join(process.env.TEMP || 'C:/Windows/Temp', `mdj-chrome-${port}`);
const chrome = spawn(
  chromePath,
  [
    '--headless=new',
    '--disable-gpu',
    '--hide-scrollbars',
    '--no-first-run',
    '--no-default-browser-check',
    '--disable-extensions',
    '--force-device-scale-factor=1',
    `--remote-debugging-port=${port}`,
    `--user-data-dir=${profile}`,
    '--window-size=' + width + ',' + height,
    'about:blank',
  ],
  { stdio: 'ignore', windowsHide: true }
);

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

async function getJson(p) {
  const res = await fetch(`http://127.0.0.1:${port}${p}`);
  return res.json();
}

async function waitForDebugger() {
  for (let i = 0; i < 100; i++) {
    try {
      const v = await getJson('/json/version');
      if (v.webSocketDebuggerUrl) return v;
    } catch {}
    await sleep(150);
  }
  throw new Error('Chrome DevTools endpoint did not start');
}

class Cdp {
  constructor(ws) {
    this.ws = ws;
    this.id = 0;
    this.pending = new Map();
    this.events = [];
    ws.addEventListener('message', (ev) => {
      const msg = JSON.parse(ev.data);
      if (msg.id && this.pending.has(msg.id)) {
        const { resolve, reject } = this.pending.get(msg.id);
        this.pending.delete(msg.id);
        if (msg.error) reject(new Error(JSON.stringify(msg.error)));
        else resolve(msg.result);
      } else if (msg.method) {
        this.events.push(msg);
      }
    });
  }
  send(method, params = {}) {
    const id = ++this.id;
    return new Promise((resolve, reject) => {
      this.pending.set(id, { resolve, reject });
      this.ws.send(JSON.stringify({ id, method, params }));
    });
  }
}

let ws;
try {
  await waitForDebugger();
  // Create a fresh tab.
  const target = await fetch(`http://127.0.0.1:${port}/json/new?about:blank`, { method: 'PUT' }).then((r) => r.json());
  ws = new WebSocket(target.webSocketDebuggerUrl);
  await new Promise((resolve, reject) => {
    ws.addEventListener('open', resolve, { once: true });
    ws.addEventListener('error', reject, { once: true });
  });
  const cdp = new Cdp(ws);

  await cdp.send('Page.enable');
  await cdp.send('Network.enable');
  await cdp.send('Log.enable');
  await cdp.send('Runtime.enable');
  await cdp.send('Emulation.setDeviceMetricsOverride', {
    width,
    height,
    deviceScaleFactor: 1,
    mobile: false,
  });

  if (cookie) {
    for (const pair of cookie.split(';').map((s) => s.trim()).filter(Boolean)) {
      const eq = pair.indexOf('=');
      const name = pair.slice(0, eq);
      const value = pair.slice(eq + 1);
      const u = new URL(url);
      await cdp.send('Network.setCookie', {
        name,
        value,
        domain: u.hostname,
        path: '/',
        secure: u.protocol === 'https:',
      });
    }
  }

  if (auth) {
    await cdp.send('Network.setExtraHTTPHeaders', {
      headers: { Authorization: 'Basic ' + Buffer.from(auth).toString('base64') },
    });
  }

  const loaded = new Promise((resolve) => {
    const timer = setTimeout(resolve, 15000);
    const check = () => {
      const idx = cdp.events.findIndex((e) => e.method === 'Page.loadEventFired');
      if (idx >= 0) {
        clearTimeout(timer);
        resolve();
      } else {
        setTimeout(check, 100);
      }
    };
    check();
  });

  await cdp.send('Page.navigate', { url });
  await loaded;
  await sleep(waitMs);

  if (args.eval) {
    const evaluated = await cdp.send('Runtime.evaluate', { expression: args.eval, awaitPromise: true, returnByValue: true });
    if (evaluated.result && typeof evaluated.result.value !== 'undefined') {
      console.log('EVAL: ' + JSON.stringify(evaluated.result.value));
    }
    await sleep(Number(args['eval-wait'] || 3000));
  }

  let clip;
  if (fullPage) {
    const metrics = await cdp.send('Page.getLayoutMetrics');
    const size = metrics.cssContentSize || metrics.contentSize;
    clip = { x: 0, y: 0, width: size.width, height: size.height, scale: 1 };
  }

  const shot = await cdp.send('Page.captureScreenshot', {
    format: 'png',
    captureBeyondViewport: fullPage,
    ...(clip ? { clip } : {}),
  });

  await mkdir(path.dirname(out), { recursive: true });
  await writeFile(out, Buffer.from(shot.data, 'base64'));

  const errors = cdp.events
    .filter((e) => e.method === 'Log.entryAdded' && ['error', 'warning'].includes(e.params.entry.level))
    .map((e) => `${e.params.entry.level}: ${e.params.entry.text}`)
    .slice(0, 12);
  const exceptions = cdp.events
    .filter((e) => e.method === 'Runtime.exceptionThrown')
    .map((e) => e.params.exceptionDetails.exception?.description || e.params.exceptionDetails.text)
    .slice(0, 8);
  const failed = cdp.events
    .filter((e) => e.method === 'Network.responseReceived' && e.params.response.status >= 400)
    .map((e) => `${e.params.response.status} ${e.params.response.url}`)
    .slice(0, 10);
  console.log(JSON.stringify({ ok: true, out, url, errors, exceptions, failed }, null, 2));
} finally {
  try { ws?.close(); } catch {}
  chrome.kill();
  await sleep(300);
  try { await rm(profile, { recursive: true, force: true }); } catch {}
}

