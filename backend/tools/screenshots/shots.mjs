// Erzeugt die Doku-Screenshots (docs/img/) mit Headless-Chrome über das DevTools-Protokoll. Node >= 22 und Google Chrome nötig.
//
// Voraussetzung: laufende Demo-Umgebung mit fiktiven Daten (Tenant 1 mit Formular "bs", Logo "Beispielschule", Anmeldung #12).
// Das Bestätigungs-PDF vorher laden: curl -b <cookie> -o real.pdf "<BASE>/pdf/admin_download.php?id=12" und PDF_DIR setzen.
//
//   BASE=http://localhost:9090 USER_NAME=<admin> USER_PASS=<passwort> OUT=docs/img \
//   PDF_DIR=file:///pfad/zum/ordner/ node backend/tools/screenshots/shots.mjs backend/tools/screenshots/shots.json
//
// Zugangsdaten nur über Umgebungsvariablen übergeben, nie in Dateien schreiben.
// shots.json: [{name, url, full?, height?, width?, scale?, wait?, base?, maxHeight?, js?}]; "${PDF_DIR}" wird durch die Umgebungsvariable ersetzt.
import { spawn } from 'node:child_process';
import { readFileSync, writeFileSync, mkdirSync, rmSync } from 'node:fs';

const BASE = process.env.BASE || 'http://localhost:9090';
const OUT = process.env.OUT || '.';
const PORT = 9333;
const profile = process.env.PROFILE || '/tmp/ondisos-shots-profile';
mkdirSync(OUT, { recursive: true });
rmSync(profile, { recursive: true, force: true });

const chrome = spawn('/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', [
  '--headless=new', `--remote-debugging-port=${PORT}`, `--user-data-dir=${profile}`,
  '--hide-scrollbars', '--no-first-run', '--disable-gpu', '--lang=de-DE', 'about:blank',
], { stdio: 'ignore' });
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

let targets;
for (let i = 0; i < 50; i++) {
  try { targets = await (await fetch(`http://127.0.0.1:${PORT}/json`)).json(); if (targets.length) break; } catch {}
  await sleep(200);
}
const page = targets.find((t) => t.type === 'page');
const ws = new WebSocket(page.webSocketDebuggerUrl);
await new Promise((r) => (ws.onopen = r));
let id = 0; const pending = new Map(); const waiters = [];
ws.onmessage = (m) => {
  const d = JSON.parse(m.data);
  if (d.id && pending.has(d.id)) { pending.get(d.id)(d); pending.delete(d.id); }
  else if (d.method) waiters.forEach((w) => w(d));
};
const send = (method, params = {}) => new Promise((res) => { const i = ++id; pending.set(i, res); ws.send(JSON.stringify({ id: i, method, params })); });
const evalJs = async (expression) => (await send('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true })).result?.result?.value;
async function go(url) {
  const loaded = new Promise((r) => { const w = (d) => { if (d.method === 'Page.loadEventFired') { waiters.splice(waiters.indexOf(w), 1); r(); } }; waiters.push(w); setTimeout(r, 15000); });
  await send('Page.navigate', { url });
  await loaded; await sleep(600);
}
await send('Page.enable'); await send('Runtime.enable');
await send('Emulation.setDeviceMetricsOverride', { width: 1280, height: 800, deviceScaleFactor: 1, mobile: false });

if (process.env.USER_NAME) {
  await go(`${BASE}/login.php`);
  await evalJs(`(()=>{const f=document.querySelector('form');f.querySelector('[name=username]').value=${JSON.stringify(process.env.USER_NAME)};f.querySelector('[name=password]').value=${JSON.stringify(process.env.USER_PASS)};f.submit();})()`);
  await sleep(1500);
}

const shots = JSON.parse(readFileSync(process.argv[2], 'utf8').replaceAll('${PDF_DIR}', process.env.PDF_DIR || ''));
for (const s of shots) {
  const w = s.width || 1280;
  await send('Emulation.setDeviceMetricsOverride', { width: w, height: s.height || 800, deviceScaleFactor: s.scale || 1, mobile: false });
  await go((s.base || BASE) + s.url);
  if (s.js) await evalJs(s.js);
  await sleep(s.wait ?? 500);
  let params = { format: 'png' };
  if (s.full) {
    const h = await evalJs('Math.ceil(document.documentElement.scrollHeight)');
    await send('Emulation.setDeviceMetricsOverride', { width: w, height: Math.min(h, s.maxHeight || 2400), deviceScaleFactor: s.scale || 1, mobile: false });
    await sleep(300);
  }
  const r = await send('Page.captureScreenshot', params);
  writeFileSync(`${OUT}/${s.name}.png`, Buffer.from(r.result.data, 'base64'));
  console.log('ok', s.name, await evalJs('location.pathname+location.search'));
}
chrome.kill(); process.exit(0);
