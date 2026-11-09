import fs from 'node:fs/promises';
import { chromium } from 'playwright';

const [base, scenariosFile, themeJs, resultFile, signalFile] = process.argv.slice(2);
if (![base, scenariosFile, themeJs, resultFile, signalFile].every(Boolean)) {
  throw new Error('usage: <base> <scenarios.json> <main.js> <result.json> <signal-file>');
}
const scenarios = JSON.parse(await fs.readFile(scenariosFile, 'utf8'));
const cases = [
  { key: 'deposit', label: 'Dépôt', kind: 'deposit' },
  { key: 'creation', label: 'Création de compte', kind: 'auth' },
  { key: 'login', label: 'Connexion existante', kind: 'auth' },
];
const browser = await chromium.launch({ headless: true, args: ['--no-sandbox', '--host-resolver-rules=MAP wordpress 127.0.0.1'] });
const origin = new URL(base).origin;
const pages = new Map();
const polls = Object.fromEntries(cases.map(({ key }) => [key, []]));
const pollFailures = [];
let blockedExternalRequests = 0;
let submissions = 0;
const isPollUrl = (value) => {
  try { return new URL(value).searchParams.get('action') === 'pk_whatsapp_match_status'; } catch { return false; }
};

try {
  for (const scenario of cases) {
    const fixture = scenarios[scenario.key];
    if (!fixture?.code || !fixture?.phone || !fixture?.status_url || !fixture?.url) throw new Error(`Fixture missing for ${scenario.key}`);
    const context = await browser.newContext();
    const page = await context.newPage();
    const state = { context, page, fixture, navigationUrl: '', pageErrors: [] };
    pages.set(scenario.key, state);
    page.on('pageerror', (error) => state.pageErrors.push(error.name));
    page.on('framenavigated', (frame) => { if (frame === page.mainFrame()) state.navigationUrl = frame.url(); });
    page.on('requestfailed', (request) => { if (isPollUrl(request.url())) pollFailures.push(`${scenario.key}:${request.failure()?.errorText || 'failed'}`); });

    await page.route('**/*', async (route) => {
      const requestUrl = route.request().url();
      if (isPollUrl(requestUrl)) {
        try {
          const target = new URL(requestUrl);
          const host = target.host;
          target.hostname = '127.0.0.1';
          const response = await fetch(target, { headers: { Host: host }, redirect: 'manual', signal: AbortSignal.timeout(8000) });
          const body = await response.text();
          try {
            const json = JSON.parse(body);
            const matched = json?.data?.matched === true;
            const requestHeaders = await route.request().allHeaders();
            const authCookieInRequest = /(?:^|;\s*)(?:wordpress_logged_in_|wordpress_sec_)/i.test(requestHeaders.cookie || '');
            const responseCookies = typeof response.headers.getSetCookie === 'function'
              ? response.headers.getSetCookie()
              : [response.headers.get('set-cookie')].filter(Boolean);
            const authCookieInResponse = responseCookies.some((cookie) => /^(?:wordpress_logged_in_|wordpress_sec_)/i.test(cookie));
            if (!matched && (authCookieInRequest || authCookieInResponse)) pollFailures.push(`${scenario.key}:session-cookie-before-match`);
            polls[scenario.key].push({ http: response.status, matched, url: json?.data?.url || '' });
          } catch {
            pollFailures.push(`${scenario.key}:non-json-${response.status}`);
          }
          const headers = { 'content-type': response.headers.get('content-type') || 'text/plain' };
          const cookies = typeof response.headers.getSetCookie === 'function'
            ? response.headers.getSetCookie()
            : [response.headers.get('set-cookie')].filter(Boolean);
          if (cookies.length) headers['set-cookie'] = cookies;
          await route.fulfill({ status: response.status, headers, body });
        } catch (error) {
          pollFailures.push(`${scenario.key}:${error.name}`);
          await route.abort();
        }
        return;
      }
      if (new URL(requestUrl).origin !== origin) {
        blockedExternalRequests++;
        return route.abort();
      }
      return route.continue();
    });

    await page.route('**/wp-admin/admin-ajax.php', async (route) => {
      if (route.request().method() !== 'POST') return route.fallback();
      submissions++;
      const payload = scenario.kind === 'deposit'
        ? { status: 'pending_whatsapp', url: fixture.url, whatsapp_url: 'https://wa.me/212640424412?text=fixture', status_url: fixture.status_url, verification_code: fixture.code, photo_errors: [] }
        : { status_url: fixture.status_url, whatsapp_url: 'https://wa.me/212640424412?text=fixture', profile_url: fixture.url };
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, data: payload }) });
    });

    if (scenario.kind === 'deposit') {
      const harnessUrl = new URL('/__ci/whatsapp-journey', base).href;
      await page.route(harnessUrl, (route) => route.fulfill({
        status: 200,
        contentType: 'text/html; charset=utf-8',
        body: '<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>Dépôt éphémère</title></head><body><main><form id="pk-submit-form" novalidate><button id="pk-submit-btn" type="submit">Continuer</button></form><p id="pk-form-status"></p></main></body></html>',
      }));
      await page.goto(harnessUrl, { waitUntil: 'domcontentloaded' });
      await page.evaluate(({ ajaxUrl }) => {
        window.pkConfig = { ajaxUrl, homeUrl: location.origin, language: 'fr', i18n: {} };
        window.__pkFetchCalls = [];
        const nativeFetch = window.fetch.bind(window);
        window.fetch = (...args) => { window.__pkFetchCalls.push(String(args[0])); return nativeFetch(...args); };
      }, { ajaxUrl: `${origin}/wp-admin/admin-ajax.php` });
      await page.addScriptTag({ url: themeJs });
      await page.locator('#pk-submit-form').evaluate((form) => form.requestSubmit());
      await page.locator('.pk-whatsapp-verification').waitFor({ timeout: 8000 });
    } else {
      if (!fixture.auth_url) throw new Error(`${scenario.key}: auth page URL missing`);
      await page.goto(fixture.auth_url, { waitUntil: 'domcontentloaded' });
      await page.locator('#pk-whatsapp-auth-form').waitFor({ timeout: 10000 });
      await page.locator('#pk-whatsapp-auth-phone').fill(fixture.phone);
      await page.locator('#pk-whatsapp-auth-form').evaluate((form) => form.requestSubmit());
      await page.getByRole('link', { name: 'Ouvrir WhatsApp' }).waitFor({ timeout: 8000 });
    }
    for (let attempt = 0; attempt < 120 && !polls[scenario.key].some((poll) => poll.http === 200 && poll.matched === false); attempt++) {
      await new Promise((resolve) => setTimeout(resolve, 50));
    }
    if (!polls[scenario.key].some((poll) => poll.http === 200 && poll.matched === false)) throw new Error(`${scenario.key}: no initial HTTP 200 matched=false poll`);
    const authCookies = (await context.cookies(base)).filter((cookie) => /^(wordpress_logged_in_|wordpress_sec_)/.test(cookie.name));
    if (authCookies.length) throw new Error(`${scenario.key}: browser was authenticated before n8n confirmation`);
  }

  const initial = Object.fromEntries(cases.map(({ key }) => [key, polls[key][0]]));
  await fs.writeFile(resultFile, JSON.stringify({ ready: true, initial, submissions }, null, 2));
  for (let attempt = 0; attempt < 1800; attempt++) {
    try { await fs.access(signalFile); break; } catch { await new Promise((resolve) => setTimeout(resolve, 100)); }
  }

  const results = {};
  for (const scenario of cases) {
    const { key, label } = scenario;
    const state = pages.get(key);
    const { page, context, fixture } = state;
    await page.waitForURL((url) => url.href === fixture.url, { waitUntil: 'commit', timeout: 30000 });
    await page.locator('.pk-dashboard').waitFor({ timeout: 15000 });
    const matchedIndex = polls[key].findIndex((poll) => poll.http === 200 && poll.matched === true && poll.url === fixture.url);
    const falseBeforeTrue = matchedIndex < 0 ? 0 : polls[key].slice(0, matchedIndex).filter((poll) => poll.http === 200 && !poll.matched).length;
    if (matchedIndex < 0 || falseBeforeTrue < 2) throw new Error(`${key}: missing false-then-true browser poll sequence`);
    const cookies = (await context.cookies(base)).filter((cookie) => /^(wordpress_logged_in_|wordpress_sec_)/.test(cookie.name));
    if (!cookies.length) throw new Error(`${key}: WordPress browser session cookie missing after correct confirmation`);
    const screen = await page.locator('body').innerText();
    if (key === 'deposit' && (!screen.includes('Annonce éphémère en attente') || !screen.includes('En attente de validation WhatsApp'))) {
      throw new Error('deposit: pending listing not visible on profile listings screen');
    }
    results[key] = {
      screen: key === 'deposit' ? 'annonces du profil' : (key === 'creation' ? 'accueil du profil — nouveau compte' : 'accueil du profil — compte existant'),
      http: polls[key][matchedIndex].http,
      matched: polls[key][matchedIndex].matched,
      falseBeforeTrue,
      navigated: state.navigationUrl === fixture.url,
      dashboard: true,
      authenticatedAfter: cookies.length > 0,
      pendingListingVisible: key === 'deposit',
      url: fixture.url,
      externalRequestsBlocked: blockedExternalRequests,
      pageErrors: state.pageErrors.length,
    };
    if (!results[key].navigated) throw new Error(`${key}: browser did not navigate to its expected profile screen`);
  }
  if (submissions !== 3) throw new Error('Expected three browser-side journey starts');
  if (pollFailures.length) throw new Error(`poll transport failures: ${pollFailures.length}`);
  if (blockedExternalRequests !== 0) throw new Error('Browser attempted an external request; all egress must remain inside the disposable job');
  await fs.writeFile(resultFile, JSON.stringify({ ready: true, initial, journeys: results, submissions, pollFailures, blockedExternalRequests }, null, 2));
} finally {
  await browser.close();
}
