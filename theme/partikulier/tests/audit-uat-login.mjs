import { chromium } from 'playwright';
import { mkdir, writeFile } from 'node:fs/promises';
import path from 'node:path';

const BASE = (process.env.PK_BASE || '').replace(/\/$/, '');
const EMAIL = process.env.AUDIT_LOGIN_EMAIL || '';
const PASSWORD = process.env.AUDIT_LOGIN_PASSWORD || '';
const OUT = process.env.AUDIT_OUT_DIR || 'audit-artifacts';
const AUTHORIZED = Boolean(EMAIL && PASSWORD);
if (!/^https:\/\//.test(BASE)) throw new Error('PK_BASE must be the HTTPS UAT URL');
await mkdir(OUT, { recursive: true });

const checks = [];
const journeys = [];
const check = (name, ok, detail = '') => {
  checks.push({ name, status: ok ? 'PASS' : 'FAIL', detail: String(detail) });
  console.log(`${ok ? 'PASS' : 'FAIL'} ${name}${detail ? ` — ${detail}` : ''}`);
};
const firstLine = error => String(error?.message || error).split('\n')[0];
const browser = await chromium.launch({ headless: true });
const profiles = [
  { id: 'mobile', viewport: { width: 390, height: 844 }, isMobile: true },
  { id: 'desktop', viewport: { width: 1440, height: 900 }, isMobile: false },
];
const choices = [
  { id: 'accept', label: 'Accepter la mesure' },
  { id: 'refuse', label: 'Continuer sans mesure' },
];
const loginUrl = `${BASE}/fr/connexion/?auth_item=login-form&redirect_url=${encodeURIComponent(`${BASE}/fr/mes-annonces/`)}`;

async function shot(page, name) {
  await page.screenshot({ path: path.join(OUT, name), fullPage: true }).catch(() => {});
}

async function consentClosedState(page) {
  const bar = page.locator('.pk-cookie-bar');
  return {
    visible: await bar.isVisible().catch(() => false),
    bodyOpen: await page.evaluate(() => document.body.classList.contains('pk-cookie-open')),
  };
}

async function runOwnerJourney(profile, choice) {
  const label = `${profile.id}/${choice.id}`;
  const context = await browser.newContext({ viewport: profile.viewport, isMobile: profile.isMobile });
  const page = await context.newPage();
  const jsErrors = [];
  const result = { profile: profile.id, choice: choice.id, status: 'FAIL' };
  page.on('pageerror', error => jsErrors.push(error.message));

  try {
    const loginResponse = await page.goto(loginUrl, { waitUntil: 'domcontentloaded', timeout: 30000 });
    check(`${label}: localized login page loads`, loginResponse?.status() === 200, `HTTP ${loginResponse?.status() ?? 'none'}`);

    const email = page.locator('.pk-auth-card input[type="email"]:visible, .pk-auth-card input[name*="email" i]:visible, .pk-auth-card input[type="text"]:visible').first();
    const password = page.locator('.pk-auth-card input[type="password"]:visible').first();
    const submit = page.locator('.pk-auth-card button[type="submit"]:visible, .pk-auth-card input[type="submit"]:visible').first();
    const formReady = await email.count() > 0 && await password.count() > 0 && await submit.count() > 0;
    check(`${label}: login form exposes email, password and submit controls`, formReady,
      `email=${await email.count()}, password=${await password.count()}, submit=${await submit.count()}`);
    if (!formReady) throw new Error('Login form controls unavailable');

    // Un contexte neuf doit présenter le consentement : le choix est obligatoire,
    // jamais contourné par un cookie injecté, un masquage ou un clic forcé.
    const bar = page.locator('.pk-cookie-bar');
    let bannerVisible = true;
    try {
      await bar.waitFor({ state: 'visible', timeout: 5000 });
    } catch {
      bannerVisible = false;
    }
    check(`${label}: fresh context displays the consent banner`, bannerVisible);
    if (!bannerVisible) throw new Error('Consent banner absent in a fresh context');

    // Sélection exclusivement par rôle et nom accessible, identique sur mobile/desktop.
    const consent = page.getByRole('button', { name: choice.label, exact: true });
    const consentReady = await consent.count() === 1 && await consent.isVisible() && await consent.isEnabled();
    check(`${label}: consent choice is a visible enabled button with its accessible name`, consentReady, choice.label);
    if (!consentReady) throw new Error('Named consent button unavailable');

    await consent.click({ timeout: 8000 });
    let closed = false;
    try {
      await bar.waitFor({ state: 'hidden', timeout: 8000 });
      const state = await consentClosedState(page);
      closed = !state.visible && !state.bodyOpen;
    } catch {
      closed = false;
    }
    check(`${label}: consent choice really closes the dialogue without force`, closed);
    if (!closed) throw new Error('Consent dialogue remains open');

    // Une réapparition au rechargement maintient le parcours en échec.
    await page.reload({ waitUntil: 'domcontentloaded', timeout: 30000 });
    await page.waitForTimeout(400);
    const afterReload = await consentClosedState(page);
    check(`${label}: consent dialogue stays closed after reload`, !afterReload.visible && !afterReload.bodyOpen);
    if (afterReload.visible || afterReload.bodyOpen) throw new Error('Consent dialogue reappeared');

    await email.fill(EMAIL);
    await password.fill(PASSWORD);
    const submitLabel = ((await submit.innerText().catch(() => '')) || '').replace(/\s+/g, ' ').trim();
    check(`${label}: login submit is the Connexion button`, submitLabel === 'Connexion', submitLabel);

    // Le trial vérifie l'actionnabilité ; le clic normal suivant est le clic réellement reçu.
    await submit.click({ trial: true, timeout: 8000 });
    check(`${label}: Connexion is actionable after consent`, true, 'trial click was not intercepted');
    await submit.click({ timeout: 8000 });

    let loggedIn = false;
    const deadline = Date.now() + 15000;
    while (Date.now() < deadline) {
      // Seuls les noms de cookies sont lus ; jamais leurs valeurs.
      const cookies = await context.cookies(BASE);
      if (cookies.some(cookie => cookie.name.startsWith('wordpress_logged_in_'))) {
        loggedIn = true;
        break;
      }
      await page.waitForTimeout(250);
    }
    check(`${label}: actual Connexion click sets a WordPress session`, loggedIn,
      loggedIn ? 'session cookie name present' : 'no wordpress_logged_in cookie name');

    await page.goto(`${BASE}/fr/mes-annonces/`, { waitUntil: 'domcontentloaded', timeout: 30000 });
    await page.waitForTimeout(400);
    const state = await page.evaluate(() => ({
      url: location.href,
      h1: document.querySelector('h1')?.innerText.trim() || '',
      loginFormVisible: !!document.querySelector('.pk-auth-card input[type="password"]'),
      dashboardVisible: !!document.querySelector('.pk-dashboard'),
      dashboardTitle: document.querySelector('.pk-dashboard-title')?.innerText.trim() || '',
      emptyDashboardVisible: !!document.querySelector('.pk-dashboard-empty'),
      unrelatedOwnerData: /Sofia|Marc|autre propriétaire/i.test(document.body.innerText),
    }));
    const afterDashboard = await consentClosedState(page);
    check(`${label}: consent dialogue does not reappear on dashboard`, !afterDashboard.visible && !afterDashboard.bodyOpen);

    const reached = /mes-annonces/.test(new URL(state.url).pathname)
      && !state.loginFormVisible
      && state.dashboardVisible
      && /Bonjour/i.test(state.dashboardTitle)
      && state.emptyDashboardVisible;
    check(`${label}: contributor reaches owner dashboard URL and explicit dashboard elements`, reached,
      `URL=${state.url}; H1=${state.h1}; dashboard=${state.dashboardVisible}; empty=${state.emptyDashboardVisible}; loginForm=${state.loginFormVisible}`);
    check(`${label}: dashboard does not show unrelated owner data`, !state.unrelatedOwnerData, 'Fresh account has no seeded listings');

    result.url = state.url;
    result.dashboardVisible = state.dashboardVisible;
    result.dashboardTitle = state.dashboardTitle;
    result.emptyDashboardVisible = state.emptyDashboardVisible;
    result.loggedIn = loggedIn;
    result.status = reached && loggedIn && !afterReload.visible && !afterDashboard.visible ? 'PASS' : 'FAIL';
    await shot(page, `owner-dashboard-${profile.id}-${choice.id}.png`);
  } catch (error) {
    result.error = firstLine(error);
    check(`${label}: owner journey completes without an unhandled error`, false, result.error);
    await shot(page, `owner-login-blocked-${profile.id}-${choice.id}.png`);
  } finally {
    check(`${label}: authentication journey has no browser JS errors`, jsErrors.length === 0, jsErrors.join(' | ') || 'none');
    if (jsErrors.length) result.status = 'FAIL';
    journeys.push(result);
    await context.close();
  }
}

try {
  const publicContext = await browser.newContext({ viewport: { width: 390, height: 844 }, isMobile: true });
  try {
    const anonDashboard = await publicContext.request.get(`${BASE}/wp-json/partikulier/v1/owner/dashboard`);
    check('Owner dashboard REST denies anonymous access', [401, 403].includes(anonDashboard.status()), `HTTP ${anonDashboard.status()}`);
    const anonMutation = await publicContext.request.post(`${BASE}/wp-json/partikulier/v1/owner/listings/1/action`, {
      data: { action: 'pause' },
      headers: { 'Content-Type': 'application/json' },
    });
    check('Owner listing POST denies anonymous access', [401, 403, 404].includes(anonMutation.status()), `HTTP ${anonMutation.status()}`);
  } finally {
    await publicContext.close();
  }

  if (AUTHORIZED) {
    for (const choice of choices) {
      for (const profile of profiles) {
        await runOwnerJourney(profile, choice);
      }
    }
  } else {
    console.log('SKIPPED Connected owner journeys: protected UAT credentials are unavailable to this public-only workflow.');
  }
} finally {
  await browser.close();
}

const report = {
  target: BASE,
  checked_at: new Date().toISOString(),
  authentication: AUTHORIZED ? 'four connected journeys executed: accept/refuse × mobile/desktop' : 'connected journeys skipped: no protected UAT credentials',
  checks,
  journeys,
  status: checks.every(item => item.status === 'PASS') && (!AUTHORIZED || journeys.length === 4 && journeys.every(item => item.status === 'PASS')) ? 'PASS' : 'FAIL',
};
await writeFile(path.join(OUT, 'owner-journey.json'), JSON.stringify(report, null, 2));
if (report.status !== 'PASS') process.exitCode = 1;
