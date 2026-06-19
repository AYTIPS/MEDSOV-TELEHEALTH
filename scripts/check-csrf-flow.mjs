import { spawn } from 'node:child_process';
import { setTimeout as delay } from 'node:timers/promises';
import { mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const chromePath = process.env.CHROME_PATH || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const baseUrl = process.env.OPENEMR_URL || 'http://localhost:8080';
const site = process.env.OPENEMR_SITE || 'default';
const username = process.env.OPENEMR_USER || 'admin';
const password = process.env.OPENEMR_PASS || 'pass';
const appointmentId = process.env.OPENEMR_APPOINTMENT_ID || '7';
const sessionId = process.env.OPENEMR_SESSION_ID || '9';
const debuggingPort = process.env.CHROME_DEBUG_PORT || '9453';

const userDataDir = await mkdtemp(join(tmpdir(), 'medsov-csrf-check-'));
const chrome = spawn(chromePath, [
  '--headless=new',
  '--disable-gpu',
  '--no-first-run',
  '--no-default-browser-check',
  `--remote-debugging-port=${debuggingPort}`,
  `--user-data-dir=${userDataDir}`,
  'about:blank',
], { stdio: ['ignore', 'pipe', 'pipe'] });

let ws;
let nextId = 1;
const pending = new Map();

function cleanup() {
  try { ws?.close(); } catch {}
  try { chrome.kill(); } catch {}
}

async function getJson(url, timeoutMs = 10000) {
  const start = Date.now();
  let lastError;
  while (Date.now() - start < timeoutMs) {
    try {
      const response = await fetch(url);
      if (response.ok) return await response.json();
    } catch (error) {
      lastError = error;
    }
    await delay(150);
  }
  throw lastError || new Error(`Timed out fetching ${url}`);
}

function send(method, params = {}) {
  const id = nextId++;
  ws.send(JSON.stringify({ id, method, params }));
  return new Promise((resolve, reject) => {
    pending.set(id, { resolve, reject });
  });
}

async function evaluate(expression, timeoutMs = 10000) {
  const start = Date.now();
  let lastError;
  while (Date.now() - start < timeoutMs) {
    try {
      const response = await send('Runtime.evaluate', {
        expression,
        awaitPromise: true,
        returnByValue: true,
      });
      if (response.exceptionDetails) {
        throw new Error(response.exceptionDetails.text || 'Runtime.evaluate failed');
      }
      return response.result.value;
    } catch (error) {
      lastError = error;
      await delay(200);
    }
  }
  throw lastError || new Error(`Timed out evaluating ${expression}`);
}

async function waitFor(expression, timeoutMs = 15000) {
  const start = Date.now();
  while (Date.now() - start < timeoutMs) {
    const value = await evaluate(expression, 2000).catch(() => false);
    if (value) return value;
    await delay(250);
  }
  throw new Error(`Timed out waiting for ${expression}`);
}

try {
  const targets = await getJson(`http://127.0.0.1:${debuggingPort}/json/list`);
  const pageTarget = targets.find((target) => target.type === 'page' && target.webSocketDebuggerUrl);
  if (!pageTarget) throw new Error('No Chrome page target found');

  ws = new WebSocket(pageTarget.webSocketDebuggerUrl);
  await new Promise((resolve, reject) => {
    ws.addEventListener('open', resolve, { once: true });
    ws.addEventListener('error', reject, { once: true });
    ws.addEventListener('message', (event) => {
      const message = JSON.parse(event.data);
      if (message.id && pending.has(message.id)) {
        const waiter = pending.get(message.id);
        pending.delete(message.id);
        message.error ? waiter.reject(new Error(message.error.message)) : waiter.resolve(message.result || {});
      }
    });
  });

  await send('Page.enable');
  await send('Runtime.enable');
  await send('Page.navigate', { url: `${baseUrl}/interface/login/login.php?site=${site}` });
  await waitFor('document.readyState === "complete" && !!document.querySelector("#authUser")');
  await evaluate(`
    document.querySelector('#authUser').value = ${JSON.stringify(username)};
    document.querySelector('#clearPass').value = ${JSON.stringify(password)};
    document.querySelector('#login-button').click();
    true;
  `);
  await waitFor('location.href.includes("/interface/main/") || document.body.innerText.includes("Calendar")', 20000);

  const result = await evaluate(`(async () => {
    const response = await fetch(${JSON.stringify(`${baseUrl}/interface/modules/custom_modules/oe-module-medsov-telehealth/templates/admit_patient.php?site=${site}`)}, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ eid: ${JSON.stringify(appointmentId)}, sid: ${JSON.stringify(sessionId)} }).toString()
    });
    return JSON.stringify({
      status: response.status,
      contentType: response.headers.get('content-type'),
      body: await response.text()
    });
  })()`);

  const parsed = JSON.parse(result);
  console.log(JSON.stringify(parsed, null, 2));

  if (parsed.status !== 403 || !parsed.body.includes('Security token is invalid')) {
    throw new Error('Expected authenticated POST without CSRF token to be rejected with 403.');
  }
} finally {
  cleanup();
  await delay(500);
  await rm(userDataDir, { recursive: true, force: true }).catch(() => {});
}
