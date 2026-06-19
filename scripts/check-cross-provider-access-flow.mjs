import { spawn } from 'node:child_process';
import { setTimeout as delay } from 'node:timers/promises';
import { mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const chromePath = process.env.CHROME_PATH || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const baseUrl = process.env.OPENEMR_URL || 'http://localhost:8080';
const site = process.env.OPENEMR_SITE || 'default';
const otherProviderUser = process.env.OTHER_PROVIDER_USER || 'Ayomide';
const otherProviderPass = process.env.OTHER_PROVIDER_PASS;
const appointmentId = process.env.OPENEMR_APPOINTMENT_ID || '7';
const sessionId = process.env.OPENEMR_SESSION_ID || '9';
const room = process.env.OPENEMR_ROOM || 'medsov-3a990ac4631746ea9210c2ef6d4d8486';
const debuggingPort = process.env.CHROME_DEBUG_PORT || '9251';

if (!otherProviderPass) {
  throw new Error('Set OTHER_PROVIDER_PASS before running this script.');
}

const userDataDir = await mkdtemp(join(tmpdir(), 'medsov-cross-provider-check-'));
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
    document.querySelector('#authUser').value = ${JSON.stringify(otherProviderUser)};
    document.querySelector('#clearPass').value = ${JSON.stringify(otherProviderPass)};
    document.querySelector('#login-button').click();
    true;
  `);
  await waitFor('location.href.includes("/interface/main/") || document.body.innerText.includes("Calendar")', 20000);

  const appointmentUrl = `${baseUrl}/interface/main/calendar/add_edit_event.php?eid=${appointmentId}&site=${site}`;
  await send('Page.navigate', { url: appointmentUrl });
  await waitFor('document.readyState === "complete"', 15000);
  const appointmentCheck = JSON.parse(await evaluate(`JSON.stringify((() => {
    const panel = document.querySelector('.medsov-telehealth-card');
    const startButton = Array.from(document.querySelectorAll('a, button'))
      .find((el) => el.textContent && el.textContent.includes('Start Telehealth'));
    const admitButton = document.querySelector('[data-medsov-admit-patient]');
    return {
      url: location.href,
      title: document.title,
      hasMedsovTelehealthCard: !!panel,
      hasStartTelehealth: !!startButton,
      hasAdmitButton: !!admitButton,
      bodySnippet: document.body.innerText.slice(0, 600)
    };
  })())`));

  if (appointmentCheck.hasMedsovTelehealthCard || appointmentCheck.hasStartTelehealth || appointmentCheck.hasAdmitButton) {
    throw new Error(`Unauthorized provider saw telehealth controls: ${JSON.stringify(appointmentCheck)}`);
  }

  const statusUrl = `${baseUrl}/interface/modules/custom_modules/oe-module-medsov-telehealth/templates/session_status.php?site=${site}&eid=${appointmentId}&sid=${sessionId}`;
  await send('Page.navigate', { url: statusUrl });
  await waitFor('document.readyState === "complete"', 15000);
  const statusCheck = JSON.parse(await evaluate(`JSON.stringify({
    url: location.href,
    body: document.body.innerText.trim()
  })`));
  if (!statusCheck.body.includes('Not authorized')) {
    throw new Error(`Unauthorized provider was not blocked from session status: ${JSON.stringify(statusCheck)}`);
  }

  const launchUrl = `${baseUrl}/interface/modules/custom_modules/oe-module-medsov-telehealth/templates/launch.php?site=${site}&eid=${appointmentId}&sid=${sessionId}&room=${encodeURIComponent(room)}&role=provider`;
  await send('Page.navigate', { url: launchUrl });
  await waitFor('document.readyState === "complete"', 15000);
  const launchCheck = JSON.parse(await evaluate(`JSON.stringify({
    url: location.href,
    title: document.title,
    hasJitsiContainer: !!document.getElementById('jitsiContainer'),
    body: document.body.innerText.trim().slice(0, 800)
  })`));
  if (launchCheck.hasJitsiContainer || !launchCheck.body.includes('Not Authorized')) {
    throw new Error(`Unauthorized provider was not blocked from launch: ${JSON.stringify(launchCheck)}`);
  }

  console.log(JSON.stringify({
    otherProviderUser,
    appointmentId,
    sessionId,
    appointmentCheck,
    statusCheck,
    launchCheck,
  }, null, 2));
} finally {
  cleanup();
  await delay(500);
  await rm(userDataDir, { recursive: true, force: true }).catch(() => {});
}
