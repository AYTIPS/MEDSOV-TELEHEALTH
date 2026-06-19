import { spawn } from 'node:child_process';
import { mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { setTimeout as delay } from 'node:timers/promises';

const chromePath = process.env.CHROME_PATH || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const baseUrl = process.env.CLEAN_OPENEMR_URL || 'http://localhost:18080';
const site = process.env.OPENEMR_SITE || 'default';
const username = process.env.OPENEMR_USER || 'admin';
const password = process.env.OPENEMR_PASS || 'pass';
const moduleName = process.env.MODULE_NAME || 'oe-module-medsov-telehealth';
const moduleDisplayName = process.env.MODULE_DISPLAY_NAME || 'Medsov Telehealth Module';
const debuggingPort = process.env.CHROME_DEBUG_PORT || '9464';

const userDataDir = await mkdtemp(join(tmpdir(), 'medsov-clean-module-install-'));
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

async function getModuleRow() {
  return JSON.parse(await evaluate(`JSON.stringify((() => {
    const rows = Array.from(document.querySelectorAll('table tbody tr'));
    const row = rows.find((item) => item.innerText.includes(${JSON.stringify(moduleDisplayName)}));
    if (!row) return null;
    const cells = Array.from(row.children).map((cell) => cell.innerText.trim().replace(/\\s+/g, ' '));
    return {
      id: row.id,
      cells,
      module: cells[1] || null,
      release: cells[2] || null,
      status: cells[3] || null,
      menuText: cells[4] || null,
      type: cells[6] || null,
      hasInstall: !!row.querySelector('.install'),
      hasEnable: !!row.querySelector('.inactive'),
      hasDisable: !!row.querySelector('.deactivate')
    };
  })())`));
}

async function installerAction(modId, action) {
  const url = `${baseUrl}/interface/modules/zend_modules/public/Installer/manage`;
  return JSON.parse(await evaluate(`(async () => {
    const response = await fetch(${JSON.stringify(url)}, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: new URLSearchParams({
        modId: ${JSON.stringify(modId)},
        modAction: ${JSON.stringify(action)},
        mod_enc_menu: '',
        mod_nick_name: ''
      }).toString()
    });
    return await response.text();
  })()`));
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
  await waitFor('document.readyState === "complete" && !!document.querySelector("#authUser")', 30000);
  await evaluate(`
    document.querySelector('#authUser').value = ${JSON.stringify(username)};
    document.querySelector('#clearPass').value = ${JSON.stringify(password)};
    document.querySelector('#login-button').click();
    true;
  `);
  await waitFor('location.href.includes("/interface/main/") || document.body.innerText.includes("Calendar")', 30000);

  await send('Page.navigate', { url: `${baseUrl}/interface/modules/zend_modules/public/Installer` });
  await waitFor('document.readyState === "complete" && document.body.innerText.includes("Custom Module Listings")', 30000);
  let moduleRow = await getModuleRow();
  if (!moduleRow) {
    throw new Error(`Module ${moduleDisplayName} was not listed by Module Manager.`);
  }

  const actions = [];
  if (moduleRow.status === 'Registered' || moduleRow.hasInstall) {
    const installResult = await installerAction(moduleRow.id, 'install');
    actions.push({ action: 'install', result: installResult });
    if (String(installResult.status || '').toUpperCase() !== 'SUCCESS') {
      throw new Error(`Install failed: ${JSON.stringify(installResult)}`);
    }
  }

  await send('Page.navigate', { url: `${baseUrl}/interface/modules/zend_modules/public/Installer` });
  await waitFor('document.readyState === "complete" && document.body.innerText.includes("Custom Module Listings")', 30000);
  moduleRow = await getModuleRow();

  if (moduleRow.status === 'Inactive' || moduleRow.hasEnable) {
    const enableResult = await installerAction(moduleRow.id, 'enable');
    actions.push({ action: 'enable', result: enableResult });
    if (String(enableResult.status || '').toUpperCase() !== 'SUCCESS') {
      throw new Error(`Enable failed: ${JSON.stringify(enableResult)}`);
    }
  }

  await send('Page.navigate', { url: `${baseUrl}/interface/modules/zend_modules/public/Installer` });
  await waitFor('document.readyState === "complete" && document.body.innerText.includes("Custom Module Listings")', 30000);
  const finalRow = await getModuleRow();
  if (!finalRow || finalRow.status !== 'Active') {
    throw new Error(`Module was not active after install/enable: ${JSON.stringify(finalRow)}`);
  }

  await send('Page.navigate', {
    url: `${baseUrl}/interface/modules/custom_modules/${moduleName}/templates/setup.php?site=${site}`,
  });
  await waitFor('document.readyState === "complete"', 30000);
  const setupCheck = JSON.parse(await evaluate(`JSON.stringify({
    url: location.href,
    title: document.title,
    hasConfigHeading: document.body.innerText.includes('Telehealth Configuration'),
    hasJitsiDomain: document.body.innerText.includes('Jitsi Domain'),
    hasSmsDisabledCopy: document.body.innerText.includes('SMS') && document.body.innerText.includes('not active')
  })`));
  if (!setupCheck.hasConfigHeading || !setupCheck.hasJitsiDomain) {
    throw new Error(`Setup page did not load after clean install: ${JSON.stringify(setupCheck)}`);
  }

  console.log(JSON.stringify({
    moduleName,
    moduleDisplayName,
    initialRow: moduleRow,
    actions,
    finalRow,
    setupCheck,
  }, null, 2));
} finally {
  cleanup();
  await delay(500);
  await rm(userDataDir, { recursive: true, force: true }).catch(() => {});
}
