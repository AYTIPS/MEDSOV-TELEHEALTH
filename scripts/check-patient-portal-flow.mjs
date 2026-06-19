import { spawn } from 'node:child_process';
import { setTimeout as delay } from 'node:timers/promises';
import { mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const chromePath = process.env.CHROME_PATH || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const baseUrl = process.env.OPENEMR_URL || 'http://localhost:8080';
const site = process.env.OPENEMR_SITE || 'default';
const username = process.env.PORTAL_USER || 'amina.demo';
const password = process.env.PORTAL_PASS || 'MedsovDemo!1';
const email = process.env.PORTAL_EMAIL || 'amina.demo@example.com';
const debuggingPort = process.env.CHROME_DEBUG_PORT || '9224';

const userDataDir = await mkdtemp(join(tmpdir(), 'medsov-portal-check-'));
const chrome = spawn(chromePath, [
  '--headless=new',
  '--disable-gpu',
  '--no-first-run',
  '--no-default-browser-check',
  `--remote-debugging-port=${debuggingPort}`,
  `--user-data-dir=${userDataDir}`,
  '--use-fake-device-for-media-stream',
  '--use-fake-ui-for-media-stream',
  `--unsafely-treat-insecure-origin-as-secure=${baseUrl}`,
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
  await send('Page.navigate', { url: `${baseUrl}/portal/index.php?site=${site}` });
  await waitFor('document.readyState === "complete" && !!document.querySelector("#uname")');

  await evaluate(`
    document.querySelector('#uname').value = ${JSON.stringify(username)};
    document.querySelector('#pass').value = ${JSON.stringify(password)};
    if (document.querySelector('#passaddon')) {
      document.querySelector('#passaddon').value = ${JSON.stringify(email)};
    }
    document.querySelector('button[type="submit"]').click();
    true;
  `);
  try {
    await waitFor('location.href.includes("/portal/home.php") || document.body.innerText.includes("Appointments")', 20000);
    await send('Page.navigate', { url: `${baseUrl}/portal/home.php?site=${site}` });
    await waitFor('document.readyState === "complete"', 15000);
    await waitFor('!!document.getElementById("medsov-telehealth-go") || document.body.innerText.trim().length > 0', 15000);
  } catch (error) {
    const loginFailure = await evaluate(`JSON.stringify({
      url: location.href,
      title: document.title,
      body: document.body.innerText.slice(0, 1000)
    })`);
    throw new Error(`Patient portal login did not reach home: ${loginFailure}`);
  }

  const home = JSON.parse(await evaluate(`JSON.stringify((() => {
    const tile = document.getElementById('medsov-telehealth-go');
    return {
      url: location.href,
      hasTelehealthTile: !!tile,
      tileText: tile ? tile.textContent.trim().replace(/\\s+/g, ' ') : null,
      tileHref: tile?.href || null,
      bodyHasMedsovTelehealth: document.body.innerText.includes('medsov-telehealth-go') || document.body.innerText.includes('Telehealth'),
      bodySnippet: document.body.innerText.slice(0, 1500),
      title: document.title
    };
  })())`));

  if (!home.tileHref) {
    throw new Error(`Patient portal telehealth tile did not render: ${JSON.stringify(home)}`);
  }

  await send('Page.navigate', { url: home.tileHref });
  await waitFor('document.readyState === "complete"');

  const appointments = JSON.parse(await evaluate(`JSON.stringify((() => {
    const joinLinks = Array.from(document.querySelectorAll('a'))
      .filter((link) => link.textContent && (
        link.textContent.includes('Join Visit') || link.textContent.includes('Enter Waiting Room')
      ));
    return {
      url: location.href,
      title: document.title,
      heading: document.querySelector('h1')?.textContent?.trim() || null,
      joinCount: joinLinks.length,
      firstJoinHref: joinLinks[0]?.href || null,
      bodyHasRawRoom: document.body.innerText.includes('medsov-3a990ac')
    };
  })())`));

  if (!appointments.firstJoinHref) {
    throw new Error('No patient telehealth join/waiting-room link found');
  }

  await send('Page.navigate', { url: appointments.firstJoinHref });
  await waitFor('document.readyState === "complete" && !!document.getElementById("checkDevices")', 15000);

  const before = JSON.parse(await evaluate(`JSON.stringify({
    url: location.href,
    audio: document.getElementById('audioStatus')?.textContent?.trim(),
    video: document.getElementById('videoStatus')?.textContent?.trim(),
    joinDisabled: document.getElementById('joinMeeting')?.classList?.contains('disabled')
  })`));

  await evaluate(`document.getElementById('checkDevices').click(); true;`);

  const after = JSON.parse(await waitFor(`(() => {
    const audio = document.getElementById('audioStatus')?.textContent?.trim();
    const video = document.getElementById('videoStatus')?.textContent?.trim();
    const joinDisabled = document.getElementById('joinMeeting')?.classList?.contains('disabled');
    if (
      audio && video
      && audio !== 'Pending'
      && video !== 'Pending'
      && !audio.startsWith('Checking')
      && !video.startsWith('Checking')
    ) {
      return JSON.stringify({ audio, video, joinDisabled });
    }
    return false;
  })()`, 15000));

  const joinHref = await evaluate(`document.getElementById('joinMeeting')?.href || null`);
  let launchBlockedBeforeAdmit = null;
  if (joinHref) {
    await send('Page.navigate', { url: joinHref });
    await waitFor('document.readyState === "complete"', 10000);
    launchBlockedBeforeAdmit = JSON.parse(await evaluate(`JSON.stringify({
      url: location.href,
      title: document.title,
      hasJitsiContainer: !!document.getElementById('jitsiContainer'),
      body: document.body.innerText.trim().slice(0, 500)
    })`));
    if (launchBlockedBeforeAdmit.hasJitsiContainer || !launchBlockedBeforeAdmit.body.includes('not admitted')) {
      throw new Error(`Patient launch should be blocked before provider admission: ${JSON.stringify(launchBlockedBeforeAdmit)}`);
    }
  }

  console.log(JSON.stringify({ home, appointments, waitingRoom: { before, after }, launchBlockedBeforeAdmit }, null, 2));
} finally {
  cleanup();
  await delay(500);
  await rm(userDataDir, { recursive: true, force: true }).catch(() => {});
}
