import { spawn } from 'node:child_process';
import { setTimeout as delay } from 'node:timers/promises';
import { mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const chromePath = process.env.CHROME_PATH || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const baseUrl = process.env.OPENEMR_URL || 'http://localhost:8080';
const site = process.env.OPENEMR_SITE || 'default';
const providerUser = process.env.OPENEMR_USER || 'admin';
const providerPass = process.env.OPENEMR_PASS || 'pass';
const portalUser = process.env.PORTAL_USER || 'amina.demo';
const portalPass = process.env.PORTAL_PASS || 'MedsovDemo!1';
const portalEmail = process.env.PORTAL_EMAIL || 'amina.demo@example.com';
const appointmentId = process.env.OPENEMR_APPOINTMENT_ID || '7';

function makeBrowser(name, debuggingPort, media = false) {
  return {
    name,
    debuggingPort,
    userDataDir: null,
    chrome: null,
    ws: null,
    nextId: 1,
    pending: new Map(),
    media,
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

const provider = makeBrowser('provider-check', process.env.PROVIDER_CHROME_DEBUG_PORT || '9231');
const patient = makeBrowser('patient-check', process.env.PATIENT_CHROME_DEBUG_PORT || '9232', true);

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
  if (!joinHref) throw new Error('No patient portal telehealth join/waiting-room link found');

  await send(patient, 'Page.navigate', { url: joinHref });
  await waitFor(patient, 'document.readyState === "complete" && !!document.getElementById("checkDevices")');

  const blockedLaunchHref = await evaluate(patient, `document.getElementById('joinMeeting')?.href || null`);
  await send(patient, 'Page.navigate', { url: blockedLaunchHref });
  await waitFor(patient, 'document.readyState === "complete"', 10000);
  const blockedBeforeAdmit = JSON.parse(await evaluate(patient, `JSON.stringify({
    url: location.href,
    hasJitsiContainer: !!document.getElementById('jitsiContainer'),
    body: document.body.innerText.trim().slice(0, 500)
  })`));
  if (blockedBeforeAdmit.hasJitsiContainer || !blockedBeforeAdmit.body.includes('not admitted')) {
    throw new Error(`Patient launch was not blocked before admission: ${JSON.stringify(blockedBeforeAdmit)}`);
  }

  await send(patient, 'Page.navigate', { url: joinHref });
  await waitFor(patient, 'document.readyState === "complete" && !!document.getElementById("checkDevices")');
  await evaluate(patient, `document.getElementById('checkDevices').click(); true;`);
  const patientWaiting = JSON.parse(await waitFor(patient, `(() => {
    const audio = document.getElementById('audioStatus')?.textContent?.trim();
    const video = document.getElementById('videoStatus')?.textContent?.trim();
    const visit = document.getElementById('visitStatus')?.textContent?.trim();
    const joinText = document.getElementById('joinMeeting')?.textContent?.trim();
    const joinDisabled = document.getElementById('joinMeeting')?.classList?.contains('disabled');
    if (
      audio && video && visit
      && audio !== 'Pending'
      && video !== 'Pending'
      && !audio.startsWith('Checking')
      && !video.startsWith('Checking')
    ) {
      return JSON.stringify({ audio, video, visit, joinText, joinDisabled });
    }
    return false;
  })()`, 15000));
  if (!patientWaiting.joinDisabled || !patientWaiting.visit.includes('Waiting')) {
    throw new Error(`Patient did not remain waiting before admission: ${JSON.stringify(patientWaiting)}`);
  }

  const appointmentUrl = `${baseUrl}/interface/main/calendar/add_edit_event.php?eid=${appointmentId}&site=${site}`;
  await send(provider, 'Page.navigate', { url: appointmentUrl });
  await waitFor(provider, 'document.readyState === "complete" && !!document.querySelector(".medsov-telehealth-card")');
  const providerBeforeAdmit = JSON.parse(await waitFor(provider, `(() => {
    const panel = document.querySelector('.medsov-telehealth-card');
    const label = panel?.querySelector('[data-medsov-waiting-label]')?.textContent?.trim();
    const button = panel?.querySelector('[data-medsov-admit-patient]');
    const launchHref = panel?.querySelector('.medsov-telehealth-start')?.href || null;
    if (label && label.includes('Patient waiting')) {
      return JSON.stringify({ label, admitDisabled: button?.disabled ?? null, hasAdmitButton: !!button, launchHref });
    }
    return false;
  })()`, 12000));
  if (!providerBeforeAdmit.hasAdmitButton || providerBeforeAdmit.admitDisabled || !providerBeforeAdmit.launchHref) {
    throw new Error(`Provider admit button was not enabled: ${JSON.stringify(providerBeforeAdmit)}`);
  }

  await send(provider, 'Page.navigate', { url: providerBeforeAdmit.launchHref });
  await waitFor(provider, 'document.readyState === "complete" && !!document.getElementById("medsovAdmitPatient")', 15000);
  const providerLaunchBeforeAdmit = JSON.parse(await waitFor(provider, `(() => {
    const pill = document.getElementById('medsovAdmissionStatus');
    const button = document.getElementById('medsovAdmitPatient');
    const label = pill?.textContent?.trim().replace(/\\s+/g, ' ');
    if (label && label.includes('Patient waiting')) {
      return JSON.stringify({
        label,
        admitDisabled: button?.disabled ?? null,
        hasAdmitButton: !!button,
        hasJitsiContainer: !!document.getElementById('jitsiContainer')
      });
    }
    return false;
  })()`, 12000));
  if (!providerLaunchBeforeAdmit.hasAdmitButton || providerLaunchBeforeAdmit.admitDisabled) {
    throw new Error(`Meeting page admit button was not enabled: ${JSON.stringify(providerLaunchBeforeAdmit)}`);
  }

  await evaluate(provider, `document.getElementById('medsovAdmitPatient').click(); true;`);
  const providerAfterAdmit = JSON.parse(await waitFor(provider, `(() => {
    const pill = document.getElementById('medsovAdmissionStatus');
    const label = pill?.textContent?.trim().replace(/\\s+/g, ' ');
    const button = document.getElementById('medsovAdmitPatient');
    const buttonText = button?.textContent?.trim().replace(/\\s+/g, ' ');
    if (label && label.includes('Patient admitted')) {
      return JSON.stringify({ label, admitDisabled: button?.disabled ?? null, buttonText });
    }
    return false;
  })()`, 12000));

  await waitFor(patient, '!!document.getElementById("jitsiContainer") && !!document.getElementById("medsovFullscreen")', 20000);
  const patientLaunch = JSON.parse(await evaluate(patient, `JSON.stringify({
    url: location.href,
    title: document.title,
    hasJitsiContainer: !!document.getElementById('jitsiContainer'),
    hasFullscreenButton: !!document.getElementById('medsovFullscreen'),
    heading: document.querySelector('h1')?.textContent?.trim() || null
  })`));

  console.log(JSON.stringify({
    blockedBeforeAdmit,
    patientWaiting,
    providerBeforeAdmit,
    providerLaunchBeforeAdmit,
    providerAfterAdmit,
    patientLaunch,
  }, null, 2));
} finally {
  await Promise.all([cleanupBrowser(provider), cleanupBrowser(patient)]);
}
