import { spawn } from 'node:child_process';
import { mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { setTimeout as delay } from 'node:timers/promises';

const chromePath = process.env.CHROME_PATH || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const baseUrl = process.env.OPENEMR_URL || 'http://localhost:8080';
const site = process.env.OPENEMR_SITE || 'default';
const mailpitUrl = process.env.MAILPIT_URL || 'http://localhost:8026';
const providerUser = process.env.OPENEMR_USER || 'admin';
const providerPass = process.env.OPENEMR_PASS || 'pass';
const portalUser = process.env.PORTAL_USER || 'amina.demo';
const portalPass = process.env.PORTAL_PASS || 'MedsovDemo!1';
const portalEmail = process.env.PORTAL_EMAIL || 'amina.demo@example.com';

if (typeof WebSocket === 'undefined') {
  throw new Error('Run this script with: node --experimental-websocket scripts/check-provider-notification-flow.mjs');
}

function makeBrowser(name, debuggingPort, media = false) {
  return {
    name,
    debuggingPort,
    media,
    userDataDir: null,
    chrome: null,
    ws: null,
    nextId: 1,
    pending: new Map(),
  };
}

async function startBrowser(browser) {
  browser.userDataDir = await mkdtemp(join(tmpdir(), `medsov-${browser.name}-`));
  const args = [
    '--headless=new',
    '--disable-gpu',
    '--no-first-run',
    '--no-default-browser-check',
    `--remote-debugging-port=${browser.debuggingPort}`,
    `--user-data-dir=${browser.userDataDir}`,
  ];

  if (browser.media) {
    args.push(
      '--use-fake-device-for-media-stream',
      '--use-fake-ui-for-media-stream',
      `--unsafely-treat-insecure-origin-as-secure=${baseUrl}`,
    );
  }

  args.push('about:blank');
  browser.chrome = spawn(chromePath, args, { stdio: ['ignore', 'pipe', 'pipe'] });

  const targets = await getJson(`http://127.0.0.1:${browser.debuggingPort}/json/list`);
  const pageTarget = targets.find((target) => target.type === 'page' && target.webSocketDebuggerUrl);
  if (!pageTarget) {
    throw new Error(`No Chrome page target found for ${browser.name}`);
  }

  browser.ws = new WebSocket(pageTarget.webSocketDebuggerUrl);
  await new Promise((resolve, reject) => {
    browser.ws.addEventListener('open', resolve, { once: true });
    browser.ws.addEventListener('error', reject, { once: true });
    browser.ws.addEventListener('message', (event) => {
      const message = JSON.parse(event.data);
      if (message.id && browser.pending.has(message.id)) {
        const waiter = browser.pending.get(message.id);
        browser.pending.delete(message.id);
        message.error ? waiter.reject(new Error(message.error.message)) : waiter.resolve(message.result || {});
      }
    });
  });

  await send(browser, 'Page.enable');
  await send(browser, 'Runtime.enable');
}

async function cleanupBrowser(browser) {
  try {
    browser.ws?.close();
  } catch {}
  try {
    browser.chrome?.kill();
  } catch {}
  if (browser.userDataDir) {
    await rm(browser.userDataDir, { recursive: true, force: true }).catch(() => {});
  }
}

async function getJson(url, timeoutMs = 10000) {
  const start = Date.now();
  let lastError;
  while (Date.now() - start < timeoutMs) {
    try {
      const response = await fetch(url);
      if (response.ok) {
        return await response.json();
      }
    } catch (error) {
      lastError = error;
    }
    await delay(150);
  }
  throw lastError || new Error(`Timed out fetching ${url}`);
}

function send(browser, method, params = {}) {
  const id = browser.nextId++;
  browser.ws.send(JSON.stringify({ id, method, params }));
  return new Promise((resolve, reject) => {
    browser.pending.set(id, { resolve, reject });
  });
}

async function evaluate(browser, expression, timeoutMs = 10000) {
  const start = Date.now();
  let lastError;
  while (Date.now() - start < timeoutMs) {
    try {
      const response = await send(browser, 'Runtime.evaluate', {
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

async function waitFor(browser, expression, timeoutMs = 15000) {
  const start = Date.now();
  while (Date.now() - start < timeoutMs) {
    const value = await evaluate(browser, expression, 2000).catch(() => false);
    if (value) {
      return value;
    }
    await delay(250);
  }
  throw new Error(`Timed out waiting for ${expression}`);
}

async function loginProvider(browser) {
  await send(browser, 'Page.navigate', { url: `${baseUrl}/interface/login/login.php?site=${site}` });
  await waitFor(browser, 'document.readyState === "complete" && !!document.querySelector("#authUser")');
  await evaluate(browser, `
    document.querySelector('#authUser').value = ${JSON.stringify(providerUser)};
    document.querySelector('#clearPass').value = ${JSON.stringify(providerPass)};
    document.querySelector('#login-button').click();
    true;
  `);
  await waitFor(browser, 'location.href.includes("/interface/main/") || document.body.innerText.includes("Calendar")', 20000);
}

async function loginPatient(browser) {
  await send(browser, 'Page.navigate', { url: `${baseUrl}/portal/index.php?site=${site}` });
  await waitFor(browser, 'document.readyState === "complete" && !!document.querySelector("#uname")');
  await evaluate(browser, `
    document.querySelector('#uname').value = ${JSON.stringify(portalUser)};
    document.querySelector('#pass').value = ${JSON.stringify(portalPass)};
    if (document.querySelector('#passaddon')) {
      document.querySelector('#passaddon').value = ${JSON.stringify(portalEmail)};
    }
    document.querySelector('button[type="submit"]').click();
    true;
  `);
  await waitFor(browser, 'location.href.includes("/portal/home.php") || document.body.innerText.includes("Appointments")', 20000);
}

async function waitForMailpitMessage() {
  const start = Date.now();
  while (Date.now() - start < 20000) {
    const data = await getJson(`${mailpitUrl}/api/v1/messages`, 3000).catch(() => null);
    const messages = Array.isArray(data?.messages) ? data.messages : [];
    const message = messages.find((item) => String(item.Subject || item.subject || '').includes('Telehealth patient waiting'));
    if (message) {
      return {
        id: message.ID || message.id,
        subject: message.Subject || message.subject,
        to: message.To || message.to,
      };
    }
    await delay(500);
  }
  throw new Error('No Mailpit provider notification email was captured.');
}

const provider = makeBrowser('provider-notification', process.env.PROVIDER_CHROME_DEBUG_PORT || '9241');
const patient = makeBrowser('patient-notification', process.env.PATIENT_CHROME_DEBUG_PORT || '9242', true);

try {
  await Promise.all([startBrowser(provider), startBrowser(patient)]);
  await Promise.all([loginProvider(provider), loginPatient(patient)]);

  await send(patient, 'Page.navigate', {
    url: `${baseUrl}/interface/modules/custom_modules/oe-module-medsov-telehealth/templates/portal_appointments.php?site=${site}`,
  });
  await waitFor(patient, 'document.readyState === "complete"');

  const joinHref = await evaluate(patient, `
    Array.from(document.querySelectorAll('a'))
      .find((link) => link.textContent && (
        link.textContent.includes('Join Visit') || link.textContent.includes('Enter Waiting Room')
      ))?.href || null
  `);
  if (!joinHref) {
    throw new Error('No patient portal telehealth join/waiting-room link found.');
  }

  await send(patient, 'Page.navigate', { url: joinHref });
  await waitFor(patient, 'document.readyState === "complete" && !!document.getElementById("checkDevices")');
  await evaluate(patient, `document.getElementById('checkDevices').click(); true;`);

  const patientWaiting = JSON.parse(await waitFor(patient, `(() => {
    const audio = document.getElementById('audioStatus')?.textContent?.trim();
    const video = document.getElementById('videoStatus')?.textContent?.trim();
    const visit = document.getElementById('visitStatus')?.textContent?.trim();
    const joinDisabled = document.getElementById('joinMeeting')?.classList?.contains('disabled');
    if (
      audio && video && visit
      && audio !== 'Pending'
      && video !== 'Pending'
      && !audio.startsWith('Checking')
      && !video.startsWith('Checking')
    ) {
      return JSON.stringify({ audio, video, visit, joinDisabled });
    }
    return false;
  })()`, 15000));

  if (!patientWaiting.joinDisabled || !patientWaiting.visit.includes('Waiting')) {
    throw new Error(`Patient did not stay in waiting room: ${JSON.stringify(patientWaiting)}`);
  }

  const providerAlert = JSON.parse(await waitFor(provider, `(() => {
    const root = document.getElementById('medsovProviderWaitingNotifier');
    if (!root || root.classList.contains('is-hidden')) {
      return false;
    }
    const count = root.querySelector('[data-medsov-waiting-count]')?.textContent?.trim();
    const patientName = root.querySelector('.medsov-provider-alert__patient')?.textContent?.trim();
    const admit = root.querySelector('[data-admit-session]');
    const open = root.querySelector('[data-open-url]:not([data-admit-session])');
    if (count && patientName && admit && open) {
      return JSON.stringify({
        count,
        patientName,
        admitDisabled: admit.disabled,
        openText: open.textContent.trim().replace(/\\s+/g, ' '),
        admitText: admit.textContent.trim().replace(/\\s+/g, ' '),
      });
    }
    return false;
  })()`, 20000));

  if (providerAlert.admitDisabled) {
    throw new Error(`Provider admit button is disabled in alert: ${JSON.stringify(providerAlert)}`);
  }

  const mailpitMessage = await waitForMailpitMessage();

  await evaluate(provider, `
    document.querySelector('#medsovProviderWaitingNotifier [data-admit-session]').click();
    true;
  `);

  const providerAlertCleared = await waitFor(provider, `(() => {
    const root = document.getElementById('medsovProviderWaitingNotifier');
    return root && root.classList.contains('is-hidden');
  })()`, 15000);

  const patientLaunch = JSON.parse(await waitFor(patient, `(() => {
    const container = document.getElementById('jitsiContainer');
    if (container) {
      return JSON.stringify({
        url: location.href,
        title: document.title,
        hasJitsiContainer: true,
        hasFullscreenButton: !!document.getElementById('medsovFullscreen'),
        heading: document.querySelector('h1')?.textContent?.trim() || null
      });
    }
    return false;
  })()`, 20000));

  console.log(JSON.stringify({
    patientWaiting,
    providerAlert,
    mailpitMessage,
    providerAlertCleared,
    patientLaunch,
  }, null, 2));
} finally {
  await Promise.all([cleanupBrowser(provider), cleanupBrowser(patient)]);
}
