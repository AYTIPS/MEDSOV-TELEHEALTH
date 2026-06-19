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
const appointmentId = process.env.OPENEMR_APPOINTMENT_ID || '7';
const maxLaunchMs = Number(process.env.MEDSOV_MAX_LAUNCH_MS || 10000);
const maxNotificationMs = Number(process.env.MEDSOV_MAX_NOTIFICATION_MS || 30000);

if (typeof WebSocket === 'undefined') {
  throw new Error('Run with: node --experimental-websocket scripts/check-performance-flow.mjs');
}

function makeBrowser(name, debuggingPort, media = false) {
  return { name, debuggingPort, media, userDataDir: null, chrome: null, ws: null, nextId: 1, pending: new Map() };
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
  if (!pageTarget) throw new Error(`No Chrome page target found for ${browser.name}`);

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
  try { browser.ws?.close(); } catch {}
  try { browser.chrome?.kill(); } catch {}
  if (browser.userDataDir) {
    await rm(browser.userDataDir, { recursive: true, force: true }).catch(() => {});
  }
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
      const response = await send(browser, 'Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true });
      if (response.exceptionDetails) throw new Error(response.exceptionDetails.text || 'Runtime.evaluate failed');
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
    if (value) return value;
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

async function clearMailpit() {
  await fetch(`${mailpitUrl}/api/v1/messages`, { method: 'DELETE' }).catch(() => {});
}

async function waitForMailpitMessage(startedAt) {
  while (Date.now() - startedAt < maxNotificationMs) {
    const data = await getJson(`${mailpitUrl}/api/v1/messages`, 3000).catch(() => null);
    const messages = Array.isArray(data?.messages) ? data.messages : [];
    const message = messages.find((item) => String(item.Subject || item.subject || '').includes('Telehealth patient waiting'));
    if (message) {
      return Date.now() - startedAt;
    }
    await delay(500);
  }
  return null;
}

async function waitForProviderQueueNotification(browser, startedAt) {
  const queueUrl = `${baseUrl}/interface/modules/custom_modules/oe-module-medsov-telehealth/templates/provider_waiting_queue.php?site=${site}`;
  let lastProbe = null;
  while (Date.now() - startedAt < maxNotificationMs) {
    const rawProbe = await evaluate(browser, `
      fetch(${JSON.stringify(queueUrl)}, { credentials: 'same-origin' })
        .then(async (response) => {
          const text = await response.text();
          let count = -1;
          try {
            const data = JSON.parse(text);
            count = Number(data.count || 0);
          } catch (error) {}
          return JSON.stringify({ status: response.status, count, text: text.slice(0, 500) });
        })
        .catch((error) => JSON.stringify({ error: String(error) }))
    `, 3000).catch(() => null);

    if (rawProbe) {
      lastProbe = JSON.parse(rawProbe);
      if (lastProbe.status === 200 && Number(lastProbe.count || 0) > 0) {
        return { ms: Date.now() - startedAt, lastProbe };
      }
    }
    await delay(500);
  }

  return { ms: null, lastProbe };
}

async function waitForProviderFloatingAlert(browser, startedAt) {
  while (Date.now() - startedAt < maxNotificationMs) {
    const visible = await evaluate(browser, `(() => {
      const root = document.getElementById('medsovProviderWaitingNotifier');
      return !!(root && !root.classList.contains('is-hidden'));
    })()`, 3000).catch(() => false);
    if (visible) {
      return Date.now() - startedAt;
    }
    await delay(500);
  }

  return null;
}

const provider = makeBrowser('provider-performance', process.env.PROVIDER_CHROME_DEBUG_PORT || '9461');
const patient = makeBrowser('patient-performance', process.env.PATIENT_CHROME_DEBUG_PORT || '9462', true);

try {
  await clearMailpit();
  await Promise.all([startBrowser(provider), startBrowser(patient)]);
  await Promise.all([loginProvider(provider), loginPatient(patient)]);

  await send(provider, 'Page.navigate', {
    url: `${baseUrl}/interface/main/calendar/add_edit_event.php?eid=${appointmentId}&site=${site}`,
  });
  await waitFor(provider, 'document.readyState === "complete" && !!document.querySelector(".medsov-telehealth-card")', 15000);
  const launchHref = await evaluate(provider, `
    Array.from(document.querySelectorAll('a'))
      .find((link) => link.textContent && link.textContent.includes('Start Telehealth'))?.href || null
  `);
  if (!launchHref) throw new Error('No provider Start Telehealth launch link found.');

  const launchStartedAt = Date.now();
  await send(provider, 'Page.navigate', { url: launchHref });
  await waitFor(provider, 'document.readyState === "complete" && !!document.getElementById("jitsiContainer")', maxLaunchMs);
  const launchMs = Date.now() - launchStartedAt;

  await loginProvider(provider);

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
  if (!joinHref) throw new Error('No patient portal waiting-room link found.');

  const notificationStartedAt = Date.now();
  await send(patient, 'Page.navigate', { url: joinHref });
  const [providerQueueResult, providerEmailNotificationMs, providerFloatingAlertMs] = await Promise.all([
    waitForProviderQueueNotification(provider, notificationStartedAt),
    waitForMailpitMessage(notificationStartedAt),
    waitForProviderFloatingAlert(provider, notificationStartedAt),
  ]);
  const providerQueueNotificationMs = providerQueueResult.ms;

  const result = {
    launchMs,
    providerQueueNotificationMs,
    providerEmailNotificationMs,
    providerFloatingAlertMs,
    providerQueueProbe: providerQueueResult.lastProbe,
    thresholds: {
      meetingLaunchMs: maxLaunchMs,
      notificationMs: maxNotificationMs,
    },
    passed: {
      meetingLaunchUnderThreshold: launchMs <= maxLaunchMs,
      providerQueueNotificationUnderThreshold: providerQueueNotificationMs !== null && providerQueueNotificationMs <= maxNotificationMs,
      providerEmailNotificationUnderThreshold: providerEmailNotificationMs !== null && providerEmailNotificationMs <= maxNotificationMs,
      providerFloatingAlertUnderThreshold: providerFloatingAlertMs !== null && providerFloatingAlertMs <= maxNotificationMs,
    },
  };

  console.log(JSON.stringify(result, null, 2));

  if (!Object.values(result.passed).every(Boolean)) {
    throw new Error(`Performance thresholds failed: ${JSON.stringify(result)}`);
  }
} finally {
  await Promise.all([cleanupBrowser(provider), cleanupBrowser(patient)]);
}
