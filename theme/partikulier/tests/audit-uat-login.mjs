import { chromium } from 'playwright';
import { mkdir, writeFile } from 'node:fs/promises';
import path from 'node:path';

const BASE = (process.env.PK_BASE || '').replace(/\/$/, '');
const EMAIL = process.env.AUDIT_LOGIN_EMAIL || '';
const PASSWORD = process.env.AUDIT_LOGIN_PASSWORD || '';
const OUT = process.env.AUDIT_OUT_DIR || 'audit-artifacts';
const AUTHORIZED = Boolean(EMAIL && PASSWORD);
const CONSENT_LABEL = 'Accepter la mesure';
if (!/^https:\/\//.test(BASE)) throw new Error('PK_BASE must be the HTTPS UAT URL');
await mkdir(OUT, { recursive: true });
const checks = [];
const check = (name, ok, detail = '') => {
  checks.push({ name, status: ok ? 'PASS' : 'FAIL', detail: String(detail) });
  console.log(`${ok ? 'PASS' : 'FAIL'} ${name}${detail ? ` — ${detail}` : ''}`);
};
const browser = await chromium.launch({ headless: true });
const context = await browser.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });
const page = await context.newPage();
const jsErrors = [];
page.on('pageerror', error => jsErrors.push(error.message));

async function shot(name) {
  await page.screenshot({ path: path.join(OUT, name), fullPage: true }).catch(() => {});
}

try {
  const anonDashboard = await context.request.get(`${BASE}/wp-json/partikulier/v1/owner/dashboard`);
  check('Owner dashboard REST denies anonymous access', [401, 403].includes(anonDashboard.status()), `HTTP ${anonDashboard.status()}`);
  const anonMutation = await context.request.post(`${BASE}/wp-json/partikulier/v1/owner/listings/1/action`, {
    data: { action: 'pause' },
    headers: { 'Content-Type': 'application/json' },
  });
  check('Owner listing POST denies anonymous access', [401, 403, 404].includes(anonMutation.status()), `HTTP ${anonMutation.status()}`);

  const login = `${BASE}/fr/connexion/?auth_item=login-form&redirect_url=${encodeURIComponent(`${BASE}/fr/mes-annonces/`)}`;
  const loginResponse = await page.goto(login, { waitUntil: 'domcontentloaded', timeout: 30000 });
  check('Localized login page loads on mobile', loginResponse?.status() === 200, `HTTP ${loginResponse?.status() ?? 'none'}`);
  const email = page.locator('.pk-auth-card input[type="email"]:visible, .pk-auth-card input[name*="email" i]:visible, .pk-auth-card input[type="text"]:visible').first();
  const password = page.locator('.pk-auth-card input[type="password"]:visible').first();
  const submit = page.locator('.pk-auth-card button[type="submit"]:visible, .pk-auth-card input[type="submit"]:visible').first();
  check('Login form exposes email, password and submit controls', await email.count() > 0 && await password.count() > 0 && await submit.count() > 0,
    `email=${await email.count()}, password=${await password.count()}, submit=${await submit.count()}`);

  const bar = page.locator('[data-pk-cookie]');
  await bar.waitFor({ state: 'visible', timeout: 5000 }).catch(() => {});
  const bannerVisible = await bar.isVisible().catch(() => false);
  let consentReady = false;
  if (!bannerVisible) {
    consentReady = true;
    check('Mobile consent banner is already closed', true, 'not visible before login');
  } else {
    const accept = bar.locator('button[data-yes]');
    const label = ((await accept.innerText().catch(() => '')) || '').trim();
    const labelOk = label === CONSENT_LABEL;
    check('Consent uses the site measurement acceptance button', labelOk, `expected=${CONSENT_LABEL}; actual=${label}`);
    if (labelOk) {
      try {
        await accept.click({ timeout: 8000 });
        await bar.waitFor({ state: 'hidden', timeout: 8000 });
      } catch (error) {
        check('Consent button click closes the banner without a forced click', false, error.message.split('\n')[0]);
      }
    }
    const closed = await page.evaluate(() => {
      const node = document.querySelector('[data-pk-cookie]');
      const button = document.querySelector('.pk-auth-card button[type="submit"], .pk-auth-card input[type="submit"]');
      let intercepts = 'no-submit';
      if (button) {
        const rect = button.getBoundingClientRect();
        const top = document.elementFromPoint(rect.left + Math.min(rect.width / 2, Math.max(rect.width - 1, 0)), rect.top + Math.min(rect.height / 2, Math.max(rect.height - 1, 0)));
        intercepts = top?.closest?.('[data-pk-cookie]') ? 'cookie-intercepts' : 'clear';
      }
      return {
        hidden: !node || node.hidden === true,
        bodyOpen: document.body.classList.contains('pk-cookie-open'),
        intercepts,
      };
    });
    const choice = (await context.cookies(BASE)).find(cookie => cookie.name === 'pk_measure')?.value || '';
    consentReady = labelOk && closed.hidden && !closed.bodyOpen && closed.intercepts === 'clear' && choice === 'yes';
    check('Consent closes the mobile banner before Connexion', consentReady,
      `hidden=${closed.hidden}; bodyOpen=${closed.bodyOpen}; intercepts=${closed.intercepts}; choice=${choice}`);
    await shot(consentReady ? 'owner-consent-closed.png' : 'owner-consent-blocked.png');
  }

  if (!consentReady) {
    check('Temporary contributor login is not attempted while the banner intercepts', false, 'banner still blocking after the real consent button');
  } else if (AUTHORIZED) {
    await email.fill(EMAIL);
    await password.fill(PASSWORD);
    const submitLabel = ((await submit.innerText().catch(() => '')) || '').replace(/\s+/g, ' ').trim();
    check('Login submit is the Connexion button', submitLabel === 'Connexion', submitLabel);
    let clickable = false;
    try {
      await submit.click({ trial: true, timeout: 8000 });
      clickable = true;
    } catch (error) {
      clickable = false;
      check('Connexion is clickable after consent', false, error.message.split('\n')[0]);
      await shot('owner-login-blocked.png');
    }
    if (clickable) {
      check('Connexion is clickable after consent', true, 'trial click was not intercepted');
      await submit.click({ timeout: 8000 });
      let loggedIn = false;
      const deadline = Date.now() + 15000;
      while (Date.now() < deadline) {
        const cookies = await context.cookies(BASE);
        if (cookies.some(cookie => cookie.name.startsWith('wordpress_logged_in_'))) {
          loggedIn = true;
          break;
        }
        await page.waitForTimeout(250);
      }
      check('Temporary contributor login sets a WordPress session', loggedIn, loggedIn ? 'session cookie present' : 'no wordpress_logged_in cookie');

      await page.goto(`${BASE}/fr/mes-annonces/`, { waitUntil: 'domcontentloaded', timeout: 30000 });
      await page.waitForTimeout(400);
      const state = await page.evaluate(() => ({
        url: location.href,
        title: document.title,
        h1: document.querySelector('h1')?.innerText.trim() || '',
        loginFormVisible: !!document.querySelector('.pk-auth-card input[type="password"]'),
        bodyText: document.body.innerText.slice(0, 1500),
      }));
      check('Temporary contributor reaches owner dashboard', /mes-annonces/.test(new URL(state.url).pathname) && !state.loginFormVisible && /Bonjour|vos annonces|annonces publiées/i.test(state.bodyText),
        `URL=${state.url}; H1=${state.h1}; loginForm=${state.loginFormVisible}`);
      check('Dashboard does not show unrelated owner data', !/Sofia|Marc|autre propriétaire/i.test(state.bodyText), 'Fresh account has no seeded listings');
      await shot('owner-dashboard-mobile.png');
    }
  } else {
    console.log('SKIPPED Connected owner journey: protected UAT credentials are unavailable to this public-only workflow.');
  }
  check('Authentication boundary journey has no browser JS errors', jsErrors.length === 0, jsErrors.join(' | ') || 'none');
} finally {
  await context.close();
  await browser.close();
}

const report = {
  target: BASE,
  checked_at: new Date().toISOString(),
  authentication: AUTHORIZED ? 'connected journey executed' : 'connected journey skipped: no protected UAT credentials',
  checks,
  status: checks.every(item => item.status === 'PASS') ? 'PASS' : 'FAIL',
};
await writeFile(path.join(OUT, 'owner-journey.json'), JSON.stringify(report, null, 2));
if (report.status !== 'PASS') process.exitCode = 1;
