import { chromium } from 'playwright';
import { mkdir, writeFile } from 'node:fs/promises';
import path from 'node:path';
import process from 'node:process';

const BASE = (process.env.PK_BASE || '').replace(/\/$/, '');
const OUT = process.env.AUDIT_OUT_DIR || 'audit-artifacts';
if (!/^https:\/\//.test(BASE)) throw new Error('PK_BASE must be the HTTPS UAT URL');
await mkdir(path.join(OUT, 'screenshots'), { recursive: true });

const checks = [];
const metrics = [];
const record = (category, check, ok, detail, severity = 'error') => {
  const item = { category, check, status: ok ? 'PASS' : 'FAIL', severity, detail: String(detail ?? '') };
  checks.push(item);
  console.log(`${ok ? 'PASS' : severity === 'warning' ? 'WARN' : 'FAIL'} ${check} — ${item.detail}`);
};

const installVitals = () => {
  const state = { lcp: null, cls: 0, interactions: {}, eventTimingSupported: false };
  Object.defineProperty(window, '__pkAuditVitals', { value: state, configurable: true });
  if (!('PerformanceObserver' in window)) return;
  try {
    if (PerformanceObserver.supportedEntryTypes.includes('largest-contentful-paint')) {
      new PerformanceObserver(list => {
        for (const entry of list.getEntries()) state.lcp = entry.renderTime || entry.loadTime || entry.startTime;
      }).observe({ type: 'largest-contentful-paint', buffered: true });
    }
  } catch {}
  try {
    if (PerformanceObserver.supportedEntryTypes.includes('layout-shift')) {
      new PerformanceObserver(list => {
        for (const entry of list.getEntries()) if (!entry.hadRecentInput) state.cls += entry.value;
      }).observe({ type: 'layout-shift', buffered: true });
    }
  } catch {}
  try {
    if (PerformanceObserver.supportedEntryTypes.includes('event')) {
      state.eventTimingSupported = true;
      new PerformanceObserver(list => {
        for (const entry of list.getEntries()) {
          if (!entry.interactionId || entry.interactionId <= 0) continue;
          const key = String(entry.interactionId);
          state.interactions[key] = Math.max(state.interactions[key] || 0, entry.duration);
        }
      }).observe({ type: 'event', buffered: true, durationThreshold: 16 });
    }
  } catch { state.eventTimingSupported = false; }
};

const browser = await chromium.launch({ headless: true });
const openPages = [];
const pageErrors = [];
const failedRequests = [];

async function visit(label, locale, route, width, height, screenshotName) {
  const context = await browser.newContext({
    viewport: { width, height },
    isMobile: width < 500,
    deviceScaleFactor: width < 500 ? 2 : 1,
  });
  const page = await context.newPage();
  let measurementRequests = 0;
  page.on('request', request => {
    if (request.url().includes('/admin-ajax.php') && request.postData()?.includes('action=pk_visit_count')) measurementRequests++;
  });
  await page.addInitScript(installVitals);
  page.on('pageerror', error => pageErrors.push(`${label}: ${error.message}`));
  page.on('requestfailed', request => {
    if (request.url().startsWith(BASE) && !request.url().includes('/favicon.ico')) {
      failedRequests.push(`${label}: ${request.method()} ${request.url()} — ${request.failure()?.errorText || 'failed'}`);
    }
  });
  const url = (() => {
    const fresh = new URL(`${BASE}/${locale}${route}`.replace(/\/{2,}/g, '/').replace('https:/', 'https://'));
    fresh.searchParams.set('pk_audit', '212');
    return fresh.href;
  })();
  const response = await page.goto(url, { waitUntil: 'load', timeout: 30000 });
  const consentBar = page.locator('[data-pk-cookie]');
  const declineMeasurement = consentBar.locator('[data-no]');
  const languageGate = page.locator('[data-pk-lang-gate]');
  const expectedLabel = { fr: 'Continuer sans mesure', en: 'Continue without measurement', ar: 'المتابعة دون قياس' }[locale];

  async function declineIfReachable() {
    try {
      if (!(await declineMeasurement.count()) || !(await declineMeasurement.isVisible().catch(() => false))) return false;
      const actualLabel = (await declineMeasurement.innerText()).trim();
      await declineMeasurement.click({ timeout: 8000 });
      await consentBar.waitFor({ state: 'hidden', timeout: 8000 });
      const choice = (await context.cookies(BASE)).find(cookie => cookie.name === 'pk_measure')?.value || '';
      record('privacy', `${label} visit measurement can be declined`, choice === 'no' && measurementRequests === 0,
        `choice=${choice}; counter requests=${measurementRequests}`);
      record('i18n', `${locale} consent refusal is localized`, actualLabel === expectedLabel,
        `expected=${expectedLabel}; actual=${actualLabel}`);
      return true;
    } catch (error) {
      return false;
    }
  }

  let declined = await declineIfReachable();
  if (!declined && await languageGate.count() && await languageGate.isVisible().catch(() => false)) {
    const closeGate = languageGate.locator('[data-pk-lang-dismiss]');
    if (await closeGate.count()) {
      await closeGate.click({ timeout: 8000 }).catch(() => {});
    }
    await languageGate.waitFor({ state: 'hidden', timeout: 8000 }).catch(() => {});
    await declineMeasurement.waitFor({ state: 'visible', timeout: 8000 }).catch(() => {});
    declined = await declineIfReachable();
  }
  if (!declined && await consentBar.isVisible().catch(() => false)) {
    record('privacy', `${label} consent has a visible refusal action`, false, 'Consent banner is visible but has no usable [data-no] action');
  }
  await page.evaluate(() => document.fonts?.ready);
  // Give late image/font candidates time to settle before reading lab LCP/CLS.
  await page.waitForTimeout(1200);
  record('availability', `${label} loads in Chromium`, response?.status() === 200,
    `HTTP ${response?.status() ?? 'no response'}; ${page.url()}`);

  const vitals = await page.evaluate(() => ({
    lcp: window.__pkAuditVitals?.lcp ?? null,
    cls: window.__pkAuditVitals?.cls ?? null,
    eventTimingSupported: window.__pkAuditVitals?.eventTimingSupported ?? false,
    interactions: window.__pkAuditVitals?.interactions ?? {},
    nav: (() => {
      const nav = performance.getEntriesByType('navigation')[0];
      return nav ? { ttfb: nav.responseStart, domContentLoaded: nav.domContentLoadedEventEnd, load: nav.loadEventEnd } : null;
    })(),
  }));
  const lcpMs = Number.isFinite(vitals.lcp) ? Math.round(vitals.lcp) : null;
  const cls = Number.isFinite(vitals.cls) ? Number(vitals.cls.toFixed(4)) : null;
  metrics.push({ label, locale, route, width, height, lcp_ms: lcpMs, cls, navigation: vitals.nav });
  record('web-vitals', `${label} LCP observed`, lcpMs !== null,
    lcpMs === null ? 'Largest Contentful Paint not exposed by this browser/page' : `${lcpMs} ms`, 'warning');
  record('web-vitals', `${label} CLS observed`, cls !== null,
    cls === null ? 'Cumulative Layout Shift not exposed by this browser/page' : String(cls), 'warning');
  if (lcpMs !== null) record('web-vitals', `${label} LCP within 2.5 s`, lcpMs <= 2500, `${lcpMs} ms (lab; target ≤ 2500 ms)`, 'warning');
  if (cls !== null) record('web-vitals', `${label} CLS within 0.1`, cls <= 0.1, `${cls} (lab; target ≤ 0.1)`, 'warning');

  if (screenshotName) await page.screenshot({ path: path.join(OUT, 'screenshots', screenshotName), fullPage: true });
  const item = { context, page, label };
  openPages.push(item);
  return item;
}

try {
  // Real Chromium mobile/desktop samples for LCP/CLS and screenshot evidence.
  const homeFr = await visit('fr-home-mobile-390', 'fr', '/', 390, 844, 'fr-home-mobile-390.png');
  const archiveFr = await visit('fr-listings-mobile-390', 'fr', '/annonces/', 390, 844, 'fr-listings-mobile-390.png');
  await visit('fr-deposit-mobile-390', 'fr', '/deposer/', 390, 844, 'fr-deposit-mobile-390.png');
  await visit('en-home-mobile-390', 'en', '/', 390, 844, 'en-home-mobile-390.png');
  await visit('ar-home-mobile-390', 'ar', '/', 390, 844, 'ar-home-mobile-390.png');
  await visit('fr-home-desktop-1440', 'fr', '/', 1440, 900, null);

  // Exercise actual site JavaScript controls with trusted Playwright input events.
  const navToggle = homeFr.page.locator('.pk-nav-toggle').first();
  const navCount = await navToggle.count();
  if (!navCount) {
    record('interaction', 'Mobile navigation toggle exists', false, 'No .pk-nav-toggle on the mobile home page');
  } else {
    const cookieNo = homeFr.page.locator('[data-pk-cookie] [data-no]');
    if (await cookieNo.isVisible().catch(() => false)) {
      await cookieNo.click({ timeout: 8000 }).catch(() => {});
    }
    const languageGate = homeFr.page.locator('[data-pk-lang-gate]');
    if (await languageGate.isVisible().catch(() => false)) {
      await languageGate.locator('[data-pk-lang-dismiss]').click({ timeout: 8000 });
      await languageGate.waitFor({ state: 'hidden', timeout: 8000 });
    }
    await navToggle.click();
    const menuOpen = await homeFr.page.evaluate(() => ({
      expanded: document.querySelector('.pk-nav-toggle')?.getAttribute('aria-expanded'),
      hidden: document.getElementById('pk-mobile-menu')?.hidden,
      links: document.querySelectorAll('#pk-mobile-menu a[href]').length,
    }));
    record('interaction', 'Mobile navigation opens', menuOpen.expanded === 'true' && menuOpen.hidden === false,
      `aria-expanded=${menuOpen.expanded}; hidden=${menuOpen.hidden}; links=${menuOpen.links}`);
    await homeFr.page.screenshot({ path: path.join(OUT, 'screenshots', 'fr-mobile-menu-open-390.png'), fullPage: true });
    await homeFr.page.keyboard.press('Escape');
    const menuClosed = await homeFr.page.evaluate(() => ({
      expanded: document.querySelector('.pk-nav-toggle')?.getAttribute('aria-expanded'),
      hidden: document.getElementById('pk-mobile-menu')?.hidden,
    }));
    record('interaction', 'Escape closes mobile navigation', menuClosed.expanded === 'false' && menuClosed.hidden === true,
      `aria-expanded=${menuClosed.expanded}; hidden=${menuClosed.hidden}`);
  }

  const langToggle = homeFr.page.locator('.pk-lang-toggle').first();
  if (await langToggle.count()) {
    await langToggle.click();
    const open = await homeFr.page.evaluate(() => ({
      expanded: document.querySelector('.pk-lang-toggle')?.getAttribute('aria-expanded'),
      hidden: document.querySelector('.pk-lang-menu')?.hidden,
      choices: document.querySelectorAll('.pk-lang-menu a[hreflang]').length,
    }));
    record('interaction', 'Language dropdown opens', open.expanded === 'true' && open.hidden === false && open.choices >= 2,
      `aria-expanded=${open.expanded}; hidden=${open.hidden}; choices=${open.choices}`);
    await homeFr.page.keyboard.press('Escape');
    const closed = await homeFr.page.evaluate(() => ({
      expanded: document.querySelector('.pk-lang-toggle')?.getAttribute('aria-expanded'),
      hidden: document.querySelector('.pk-lang-menu')?.hidden,
    }));
    record('interaction', 'Escape closes language dropdown', closed.expanded === 'false' && closed.hidden === true,
      `aria-expanded=${closed.expanded}; hidden=${closed.hidden}`);
  } else {
    record('interaction', 'Language selector exists', false, 'No .pk-lang-toggle on the mobile home page');
  }

  // The old sheet toggle is hidden on purpose. The shipped mobile control is the
  // chip bar, which only appears after scroll, and it opens the centered find popup.
  await archiveFr.page.evaluate(() => window.scrollTo(0, 700));
  const filterChip = archiveFr.page.locator('.pk-scroll-actions.is-on .pk-filter-chip').first();
  try {
    await filterChip.waitFor({ state: 'visible', timeout: 8000 });
    await filterChip.click({ timeout: 8000 });
    const open = await archiveFr.page.evaluate(() => {
      const opener = document.querySelector('.pk-find-open');
      const card = document.querySelector('.pk-find-card');
      const box = card ? card.getBoundingClientRect() : null;
      return {
        checked: !!opener?.checked,
        visible: !!box && box.width > 0 && box.height > 0,
      };
    });
    record('interaction', 'Mobile archive filters open', open.checked && open.visible,
      `find popup checked=${open.checked}; card visible=${open.visible}`);
    await archiveFr.page.screenshot({ path: path.join(OUT, 'screenshots', 'fr-mobile-filters-open-390.png'), fullPage: true });
    await archiveFr.page.keyboard.press('Escape');
    const closed = await archiveFr.page.evaluate(() => !document.querySelector('.pk-find-open')?.checked);
    record('interaction', 'Escape closes mobile archive filters', closed, `find popup closed=${closed}`);
  } catch (error) {
    record('interaction', 'Mobile archive filters open', false, error.message);
  }

  const typeToggle = homeFr.page.locator('.pk-find-type-btn:visible').first();
  if (await typeToggle.count()) {
    await typeToggle.click({ timeout: 8000 });
    const typeOpen = await homeFr.page.evaluate(() => {
      const opener = document.querySelector('.pk-find-open');
      const card = document.querySelector('.pk-find-card');
      const box = card ? card.getBoundingClientRect() : null;
      return { checked: !!opener?.checked, visible: !!box && box.width > 0 && box.height > 0 };
    });
    record('interaction', 'Search type selector opens', typeOpen.checked && typeOpen.visible,
      `find popup checked=${typeOpen.checked}; card visible=${typeOpen.visible}`);
    await homeFr.page.keyboard.press('Escape');
    const typeClosed = await homeFr.page.evaluate(() => !document.querySelector('.pk-find-open')?.checked);
    record('interaction', 'Escape closes search type selector', typeClosed, `find popup closed=${typeClosed}`);
  } else {
    record('interaction', 'Search type selector visible', false, 'No visible .pk-find-type-btn on mobile home');
  }

  await homeFr.page.waitForTimeout(250);
  await archiveFr.page.waitForTimeout(250);
  const eventGroups = [];
  for (const item of [homeFr, archiveFr]) {
    const state = await item.page.evaluate(() => window.__pkAuditVitals || null);
    if (!state) continue;
    const durations = Object.values(state.interactions || {}).map(Number).filter(Number.isFinite);
    eventGroups.push(...durations);
  }
  const inpLabMs = eventGroups.length ? Math.round(Math.max(...eventGroups)) : null;
  const eventTimingSupported = await homeFr.page.evaluate(() => !!window.__pkAuditVitals?.eventTimingSupported);
  record('web-vitals', 'Interaction Event Timing available', eventTimingSupported,
    eventTimingSupported ? `${eventGroups.length} interaction event group(s) observed` : 'PerformanceEventTiming unsupported', 'warning');
  record('web-vitals', 'Lab INP sample captured', inpLabMs !== null,
    inpLabMs === null ? 'No trusted interaction entries available; see note on field INP' : `${inpLabMs} ms max across exercised interactions (lab proxy, not field p98)`, 'warning');
  if (inpLabMs !== null) record('web-vitals', 'Lab INP proxy within 200 ms', inpLabMs <= 200,
    `${inpLabMs} ms (lab max; field INP requires real-user data)`, 'warning');

  for (const message of pageErrors) record('javascript', 'Browser JavaScript errors', false, message);
  for (const message of failedRequests) record('network', 'Failed first-party browser requests', false, message, 'warning');
} catch (error) {
  record('interaction', 'Interaction script completed', false, error.message);
} finally {
  for (const item of openPages) await item.context.close();
  await browser.close();
}

const report = {
  target: BASE,
  checked_at: new Date().toISOString(),
  browser: 'Chromium via Playwright; live UAT',
  metric_notes: {
    lcp_cls: 'Lab measurements from PerformanceObserver after page load and a 1.2 s settling window; not field CrUX data.',
    inp: 'Maximum PerformanceEventTiming interaction duration across tested controls; a small lab proxy, not the field p98 INP metric.',
  },
  metrics,
  interaction_inp_lab_max_ms: null,
  checks,
  failed_checks: checks.filter(item => item.status === 'FAIL').length,
  warnings: checks.filter(item => item.severity === 'warning' && item.status === 'FAIL').length,
  status: checks.some(item => item.status === 'FAIL' && item.severity === 'error') ? 'FAIL' : 'PASS_WITH_FINDINGS',
};
report.interaction_inp_lab_max_ms = (() => {
  const item = checks.find(check => check.check === 'Lab INP sample captured');
  const match = item?.detail.match(/^(\d+) ms/);
  return match ? Number(match[1]) : null;
})();
await writeFile(path.join(OUT, 'interaction-web-vitals.json'), JSON.stringify(report, null, 2));
const md = [
  '# Interactions JavaScript et Web Vitals — Chromium UAT',
  '',
  `Cible : ${BASE}`,
  `Mesuré : ${report.checked_at}`,
  '',
  '| Vue | Largeur | LCP lab (ms) | CLS lab |',
  '|---|---:|---:|---:|',
  ...metrics.map(item => `| ${item.label} | ${item.width} | ${item.lcp_ms ?? 'n/a'} | ${item.cls ?? 'n/a'} |`),
  '',
  `INP lab proxy max : ${report.interaction_inp_lab_max_ms ?? 'n/a'} ms`,
  '',
  'Note : cet INP est une mesure de laboratoire des interactions testées; l’INP terrain/p98 requiert des données d’utilisateurs réels (CrUX/RUM). Lighthouse de navigation ne remplace pas cette mesure.',
  '',
  `Verdict fonctionnel : ${report.status}; ${report.failed_checks} échec(s), ${report.warnings} avertissement(s).`,
  '',
  '## Interactions',
  '',
  ...checks.filter(item => item.category === 'interaction').map(item => `- ${item.status} — ${item.check}: ${item.detail}`),
  '',
].join('\n');
await writeFile(path.join(OUT, 'interaction-web-vitals.md'), md);
console.log(md);
if (report.status === 'FAIL') process.exitCode = 1;
