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
const debuggingPort = process.env.CHROME_DEBUG_PORT || '9223';

const userDataDir = await mkdtemp(join(tmpdir(), 'medsov-appointment-check-'));
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

  const appointmentUrl = `${baseUrl}/interface/main/calendar/add_edit_event.php?eid=${appointmentId}&site=${site}`;
  await send('Page.navigate', { url: appointmentUrl });
  await waitFor('document.readyState === "complete" && !!document.querySelector(".medsov-telehealth-card")', 15000);

  const appointmentResult = JSON.parse(await evaluate(`JSON.stringify((() => {
    const panel = document.querySelector('.medsov-telehealth-card');
    const link = Array.from(document.querySelectorAll('a, button'))
      .find((el) => el.textContent && el.textContent.includes('Start Telehealth'));
    const fullScreenLink = Array.from(document.querySelectorAll('a'))
      .find((el) => el.textContent && el.textContent.includes('Open Full Screen'));
    const copyButton = panel?.querySelector('[data-medsov-copy-room]');
    return {
      appointmentUrl: location.href,
      hasBrandedPanel: !!panel,
      hasStartButton: !!link,
      buttonText: link ? link.textContent.trim().replace(/\\s+/g, ' ') : null,
      href: link && link.href ? link.href : null,
      hasOpenFullScreenLink: !!fullScreenLink,
      fullScreenHref: fullScreenLink?.href || null,
      fullScreenTarget: fullScreenLink?.target || null,
      panelTitle: panel?.querySelector('.medsov-telehealth-title')?.textContent?.trim() || null,
      statusText: panel?.querySelector('.medsov-telehealth-status')?.textContent?.trim() || null,
      hasCopyRoomButton: !!copyButton,
      room: copyButton?.getAttribute('data-medsov-copy-room') || null,
      pageTitle: document.title
    };
  })())`));

  let launchResult = null;
  if (appointmentResult.href) {
    await send('Page.navigate', { url: appointmentResult.href });
    await waitFor('document.readyState === "complete" && !!document.getElementById("jitsiContainer")', 15000);
    launchResult = JSON.parse(await evaluate(`JSON.stringify({
      launchUrl: location.href,
      hasFullscreenButton: !!document.getElementById('medsovFullscreen'),
      fullscreenText: document.getElementById('medsovFullscreen')?.textContent?.trim().replace(/\\s+/g, ' ') || null,
      hasJitsiContainer: !!document.getElementById('jitsiContainer'),
      pageTitle: document.title
    })`));
  }

  console.log(JSON.stringify({ appointment: appointmentResult, launch: launchResult }));
} finally {
  cleanup();
  await delay(500);
  await rm(userDataDir, { recursive: true, force: true }).catch(() => {});
}
