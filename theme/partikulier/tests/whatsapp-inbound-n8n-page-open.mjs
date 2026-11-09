import fs from 'node:fs/promises';
import path from 'node:path';
import { chromium } from 'playwright';

const [base, listingUrl, statusUrl, code, themeJs, resultFile, signalFile] = process.argv.slice(2);
if (![base, listingUrl, statusUrl, code, themeJs, resultFile, signalFile].every(Boolean)) {
  throw new Error('usage: <base> <listing-url> <status-url> <code> <main.js> <result.json> <signal-file>');
}

const browser = await chromium.launch({ headless: true, args: ['--no-sandbox', '--host-resolver-rules=MAP wordpress 127.0.0.1'] });
const page = await browser.newPage();
const origin = new URL(base).origin;
let blockedExternalRequests = 0;
await page.route('**/*', (route) => {
  if (new URL(route.request().url()).origin !== origin) {
    blockedExternalRequests++;
    return route.abort();
  }
  return route.continue();
});
const polls = [];
const pollRequests = [];
const pollFailures = [];
let submitIntercepted = false;
let navigationUrl = '';
const isPollUrl = (value) => {
  try { return new URL(value).searchParams.get('action') === 'pk_whatsapp_match_status'; } catch { return false; }
};
page.on('request', (request) => { if (isPollUrl(request.url())) pollRequests.push(request.url()); });
page.on('requestfailed', (request) => { if (isPollUrl(request.url())) pollFailures.push(request.failure()?.errorText || 'request failed'); });
page.on('response', async (response) => {
  if (!isPollUrl(response.url())) return;
  try {
    const json = await response.json();
    polls.push({ http: response.status(), matched: json?.data?.matched === true, url: json?.data?.url || '' });
  } catch { pollFailures.push(`HTTP ${response.status()} returned non-JSON poll data`); }
});
page.on('framenavigated', (frame) => {
  if (frame === page.mainFrame()) navigationUrl = frame.url();
});

try {
  const harnessUrl = new URL('/__ci/whatsapp-page-open', base).href;
  await page.route(harnessUrl, (route) => route.fulfill({ status: 200, contentType: 'text/html; charset=utf-8', body: '<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>Essai page ouverte</title></head><body><main></main></body></html>' }));
  await page.goto(harnessUrl, { waitUntil: 'domcontentloaded' });
  await page.evaluate(() => {
    document.body.innerHTML = '<main><form id="pk-submit-form" novalidate><button id="pk-submit-btn" type="submit">Continuer</button></form><p id="pk-form-status"></p></main>';
    window.pkConfig = { ajaxUrl: `${location.origin}/wp-admin/admin-ajax.php`, homeUrl: location.origin, language: 'fr', i18n: {} };
  });
  await page.route('**/wp-admin/admin-ajax.php', async (route) => {
    if (route.request().method() !== 'POST') return route.continue();
    submitIntercepted = true;
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({
      success: true,
      data: {
        status: 'pending_whatsapp', url: listingUrl, whatsapp_url: '#no-send',
        status_url: statusUrl, verification_code: code, photo_errors: [],
      },
    }) });
  });
  await page.addScriptTag({ url: themeJs });
  await page.locator('#pk-submit-form').evaluate((form) => form.requestSubmit());
  await page.getByText(/Cette page reste ouverte|This page stays open|تبقى هذه الصفحة مفتوحة/i).waitFor({ timeout: 5000 }).catch(() => {});
  await page.waitForFunction(() => document.querySelector('.pk-whatsapp-verification'), null, { timeout: 5000 });

  // Wait for the production poller to make an anonymous request before releasing n8n.
  for (let i = 0; i < 220 && !polls.some((poll) => poll.matched === false); i++) await new Promise((resolve) => setTimeout(resolve, 50));
  const pre = polls.find((poll) => poll.matched === false);
  if (!submitIntercepted || !pre || pre.http !== 200) {
    const lastRequest = pollRequests.at(-1) || 'no poll request observed';
    const lastFailure = pollFailures.at(-1) || 'no response diagnostic';
    throw new Error(`No HTTP 200 matched=false browser poll before n8n execution; ${lastRequest}; ${lastFailure}`);
  }
  await fs.writeFile(resultFile, JSON.stringify({ before: pre, submitted: submitIntercepted, initialUrl: navigationUrl, polls }, null, 2));

  // The workflow runner starts n8n only after this file exists, keeping this browser page open.
  for (let i = 0; i < 1200; i++) {
    try { await fs.access(signalFile); break; } catch { await new Promise((resolve) => setTimeout(resolve, 100)); }
  }
  await page.waitForURL((url) => url.href === listingUrl, { waitUntil: 'commit', timeout: 30000 });
  for (let i = 0; i < 100 && !polls.some((poll) => poll.matched === true); i++) await new Promise((resolve) => setTimeout(resolve, 50));
  const after = polls.find((poll) => poll.matched === true && poll.http === 200 && poll.url === listingUrl);
  const firstTrue = polls.findIndex((poll) => poll.matched === true);
  const falseBeforeTrue = polls.slice(0, firstTrue).filter((poll) => poll.http === 200 && poll.matched === false).length;
  if (!after) throw new Error('Browser navigated without observing matched=true and the expected listing URL');
  if (falseBeforeTrue < 2) throw new Error('The page did not retain its waiting screen for polls after non-matching events');
  await fs.writeFile(resultFile, JSON.stringify({
    before: pre, after, submitted: submitIntercepted, screenChanged: navigationUrl === listingUrl,
    navigatedUrl: navigationUrl, pollCount: polls.length, falseBeforeTrue, blockedExternalRequests,
  }, null, 2));
  if (navigationUrl !== listingUrl) throw new Error('The browser screen did not navigate to the matched listing URL');
} finally {
  await browser.close();
}
