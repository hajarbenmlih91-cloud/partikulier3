import { chromium } from 'playwright';
import { mkdir, writeFile } from 'node:fs/promises';
import path from 'node:path';

const BASE = (process.env.PK_BASE || '').replace(/\/$/, '');
const EMAIL = process.env.AUDIT_LOGIN_EMAIL || '';
const PASSWORD = process.env.AUDIT_LOGIN_PASSWORD || '';
const OUT = process.env.AUDIT_OUT_DIR || 'audit-artifacts';
if (!BASE || !EMAIL || !PASSWORD) throw new Error('PK_BASE, AUDIT_LOGIN_EMAIL and AUDIT_LOGIN_PASSWORD are required');
await mkdir(OUT, { recursive: true });
const checks = [];
const check = (name, ok, detail = '') => {
  checks.push({ name, status: ok ? 'PASS' : 'FAIL', detail: String(detail) });
  console.log(`${ok ? 'PASS' : 'FAIL'} ${name}${detail ? ` — ${detail}` : ''}`);
};
const browser = await chromium.launch({ headless: true });
const context = await browser.newContext({ viewport: { width: 390, height: 844 }, isMobile: true });
const page = await context.newPage();
const jsErrors = [];
page.on('pageerror', error => jsErrors.push(error.message));

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
  check('Localized login page loads', loginResponse?.status() === 200, `HTTP ${loginResponse?.status() ?? 'none'}`);

  const email = page.locator('.pk-auth-card input[type="email"]:visible, .pk-auth-card input[name*="email" i]:visible, .pk-auth-card input[type="text"]:visible').first();
  const password = page.locator('.pk-auth-card input[type="password"]:visible').first();
  const submit = page.locator('.pk-auth-card button[type="submit"]:visible, .pk-auth-card input[type="submit"]:visible').first();
  check('Login form exposes email, password and submit controls', await email.count() > 0 && await password.count() > 0 && await submit.count() > 0,
    `email=${await email.count()}, password=${await password.count()}, submit=${await submit.count()}`);

  await email.fill(EMAIL);
  await password.fill(PASSWORD);
  await submit.click();
  await page.waitForTimeout(1800);

  // Follow the intended destination explicitly in case the auth plugin uses AJAX.
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
  check('Authenticated journey has no browser JS errors', jsErrors.length === 0, jsErrors.join(' | ') || 'none');

  await page.screenshot({ path: path.join(OUT, 'owner-dashboard-mobile.png'), fullPage: true });
} finally {
  await context.close();
  await browser.close();
}

const report = { target: BASE, checked_at: new Date().toISOString(), checks, status: checks.every(item => item.status === 'PASS') ? 'PASS' : 'FAIL' };
await writeFile(path.join(OUT, 'owner-journey.json'), JSON.stringify(report, null, 2));
if (report.status !== 'PASS') process.exitCode = 1;
