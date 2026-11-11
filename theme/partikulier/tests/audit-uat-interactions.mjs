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

  // =========================================================================
  // Safe non-destructive live UAT E2E checks (desktop + mobile interactions)
  // =========================================================================
  const uatCtx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  await uatCtx.addInitScript(() => {
    if (!navigator.clipboard) {
      Object.defineProperty(navigator, 'clipboard', {
        value: { writeText: async (txt) => { window.__pkUatCopied = txt; } },
        configurable: true,
      });
    } else {
      const orig = navigator.clipboard.writeText?.bind(navigator.clipboard);
      navigator.clipboard.writeText = async (txt) => {
        window.__pkUatCopied = txt;
        if (orig) return orig(txt).catch(() => {});
      };
    }
  });
  const uatPage = await uatCtx.newPage();
  openPages.push({ context: uatCtx, page: uatPage, label: 'uat-desktop-e2e' });
  uatPage.on('pageerror', error => pageErrors.push(`uat-desktop-e2e: ${error.message}`));

  const clearUatModals = async (pg) => {
    const g = pg.locator('[data-pk-lang-gate]');
    if (await g.isVisible().catch(() => false)) {
      await g.locator('[data-pk-lang-dismiss]').click({ timeout: 5000 }).catch(() => {});
      await g.waitFor({ state: 'hidden', timeout: 5000 }).catch(() => {});
    }
    const c = pg.locator('[data-pk-cookie] [data-no]');
    if (await c.isVisible().catch(() => false)) {
      await c.click({ timeout: 5000 }).catch(() => {});
      await pg.locator('[data-pk-cookie]').waitFor({ state: 'hidden', timeout: 5000 }).catch(() => {});
    }
  };

  // 1. Live Hero Search: pk_find_count + pk_places_search autocomplete + submit to /fr/annonces/
  await uatPage.goto(`${BASE}/fr/?pk_audit=219`, { waitUntil: 'domcontentloaded', timeout: 30000 });
  await clearUatModals(uatPage);
  try {
    const fcPromise = uatPage.waitForResponse(
      r => r.url().includes('/admin-ajax.php') && r.request().postData()?.includes('action=pk_find_count'),
      { timeout: 15000 }
    );
    await uatPage.locator('.pk-find-tab[for="pk-find-rent"]').click();
    const fcRes = await fcPromise;
    const fcJson = await fcRes.json().catch(() => null);
    record('interaction', 'UAT Hero tab click opens popup and runs live pk_find_count',
      fcRes.status() === 200 && fcJson?.success === true && typeof fcJson?.data?.count === 'number',
      `http=${fcRes.status()}; count=${fcJson?.data?.count}`);

    const psPromise = uatPage.waitForResponse(
      r => r.url().includes('action=pk_places_search') && r.url().includes('Casa'),
      { timeout: 15000 }
    );
    await uatPage.locator('#pk-s-city-input').fill('Casa');
    const psRes = await psPromise;
    const psJson = await psRes.json().catch(() => null);
    const firstCitySuggest = uatPage.locator('#pk-s-city-suggestions .pk-suggest-item').first();
    await firstCitySuggest.waitFor({ state: 'visible', timeout: 8000 });
    await firstCitySuggest.dispatchEvent('mousedown');
    const selectedCitySlug = await uatPage.locator('#pk-s-city-value').inputValue();
    record('interaction', 'UAT Autocomplete pk_places_search resolves city slug',
      psRes.status() === 200 && psJson?.success === true && selectedCitySlug.length > 0,
      `http=${psRes.status()}; results=${psJson?.data?.results?.length ?? 0}; es_city=${selectedCitySlug}`);

    await uatPage.locator('.pk-find-tx-btn[data-pk-intent="a-vendre"]').click();
    await Promise.all([
      uatPage.waitForURL(u => u.pathname.includes('/annonces'), { timeout: 15000 }),
      uatPage.locator('.pk-find-results').click(),
    ]);
    const landedArchiveCards = await uatPage.locator('.pk-archive-content .pk-card').count();
    record('interaction', 'UAT Search submit lands on filtered archive',
      uatPage.url().includes('/annonces') && landedArchiveCards >= 1,
      `url=${uatPage.url()}; cards=${landedArchiveCards}`);
  } catch (err) {
    record('interaction', 'UAT Hero search and autocomplete', false, err.message);
  }

  // 2. Live Archive AJAX Swaps (Sort, Places modal, Chip removal, Post-swap wishlist) & Card/Footer audit
  await uatPage.goto(`${BASE}/fr/annonces/?pk_audit=219`, { waitUntil: 'domcontentloaded', timeout: 30000 });
  await clearUatModals(uatPage);
  try {
    await uatPage.evaluate(() => { window.__pkUatAlive = 'yes'; });
    await uatPage.locator('[data-pk-open-sort]:visible').first().click();
    const sortOpen = await uatPage.locator('[data-pk-sort]').evaluate(el => !el.hidden);
    const sortReq = uatPage.waitForResponse(
      r => r.url().includes('pk_order=price-asc') && r.request().headers()['x-requested-with'] === 'pk-results',
      { timeout: 15000 }
    );
    await uatPage.locator('.pk-sort-list a[href*="pk_order=price-asc"]').first().click();
    const sortRes = await sortReq;
    await uatPage.waitForFunction(() => location.search.includes('pk_order=price-asc'), { timeout: 10000 });
    const aliveSort = await uatPage.evaluate(() => window.__pkUatAlive === 'yes');
    record('interaction', 'UAT Archive sort modal performs AJAX swap without reload',
      sortOpen && sortRes.status() === 200 && aliveSort,
      `sortOpen=${sortOpen}; http=${sortRes.status()}; noReload=${aliveSort}`);

    if (await uatPage.locator('[data-pk-open-places]:visible').count()) {
      await uatPage.locator('[data-pk-open-places]:visible').first().click();
      const placesOpen = await uatPage.locator('[data-pk-places]').evaluate(el => !el.hidden);
      const firstPlace = uatPage.locator('[data-pk-places] .pk-places-list a[href]').first();
      if (await firstPlace.count()) {
        const placeReq = uatPage.waitForResponse(
          r => r.request().headers()['x-requested-with'] === 'pk-results',
          { timeout: 15000 }
        );
        await firstPlace.click();
        const placeRes = await placeReq;
        await uatPage.waitForTimeout(300);
        const chipsAfterPlace = await uatPage.locator('.pk-chosen-chip').count();
        record('interaction', 'UAT Archive places modal filters via AJAX swap',
          placesOpen && placeRes.status() === 200 && chipsAfterPlace >= 1,
          `placesOpen=${placesOpen}; http=${placeRes.status()}; chips=${chipsAfterPlace}`);
      } else {
        await uatPage.locator('[data-pk-places-close]').first().click().catch(() => {});
      }
    }

    const chipRm = uatPage.locator('.pk-chosen-chip[href], .pk-chosen-chip a[href]').first();
    if (await chipRm.count()) {
      const beforeChips = await uatPage.locator('.pk-chosen-chip').count();
      const rmReq = uatPage.waitForResponse(
        r => r.request().headers()['x-requested-with'] === 'pk-results',
        { timeout: 15000 }
      );
      await chipRm.click();
      const rmRes = await rmReq;
      await uatPage.waitForTimeout(300);
      const afterChips = await uatPage.locator('.pk-chosen-chip').count();
      record('interaction', 'UAT Archive filter chip removal performs AJAX swap',
        rmRes.status() === 200 && afterChips < beforeChips,
        `http=${rmRes.status()}; chips=${beforeChips} -> ${afterChips}`);
    }

    const postSwapHeart = uatPage.locator('.pk-archive-content .pk-card-wishlist').first();
    if (await postSwapHeart.count()) {
      const before = await postSwapHeart.getAttribute('aria-pressed');
      await postSwapHeart.click();
      await uatPage.waitForTimeout(200);
      const after = await postSwapHeart.getAttribute('aria-pressed');
      const ok = before !== after;
      record('finding', 'UAT Wishlist stays bound after archive AJAX swap', ok,
        ok ? `toggled ${before} -> ${after}` : `aria-pressed stayed ${before} after AJAX swap (pkBindWishlist not re-called)`,
        'warning');
      if (ok) await postSwapHeart.click();
    }
  } catch (err) {
    record('interaction', 'UAT Archive AJAX swaps', false, err.message);
  }

  // Card consistency + footer links check on live /fr/annonces/ & /ar/annonces/
  await uatPage.goto(`${BASE}/fr/annonces/?pk_audit=219`, { waitUntil: 'domcontentloaded', timeout: 30000 });
  const frArchiveAudit = await uatPage.evaluate(() => {
    const cards = Array.from(document.querySelectorAll('.pk-card')).slice(0, 10).map(c => ({
      title: c.querySelector('.pk-card-title')?.textContent?.trim() || '',
      badge: c.querySelector('.pk-card-badge, .pk-badge')?.textContent?.trim() || '',
      price: c.querySelector('.pk-card-price')?.textContent?.trim() || '',
      href: c.querySelector('a[href]')?.getAttribute('href') || '',
    }));
    const mismatched = cards.filter(c => /à vendre/i.test(c.title) && /à louer/i.test(c.badge));
    const hashLinks = document.querySelectorAll('footer a[href="#"]').length;
    return { cardsCount: cards.length, mismatched, hashLinks, firstFiveHrefs: cards.slice(0, 5).map(c => c.href) };
  });
  record('finding', 'UAT FR archive card badges match sale titles', frArchiveAudit.mismatched.length === 0,
    frArchiveAudit.mismatched.length === 0
      ? '0 mismatched badges'
      : `${frArchiveAudit.mismatched.length} card(s) have "à vendre" title with "À louer" badge: ${frArchiveAudit.mismatched.map(m => `${m.title} (${m.price})`).join('; ')}`,
    'warning');
  record('finding', 'UAT Footer has no placeholder href="#" links', frArchiveAudit.hashLinks === 0,
    `footer a[href="#"] count=${frArchiveAudit.hashLinks}`, 'warning');

  // 3. Live Single Listing: Gallery + Lightbox + Share + WhatsApp href check across FR/EN/AR + Favorites round-trip
  try {
    let singleUrl = frArchiveAudit.firstFiveHrefs.find(Boolean);
    for (const candidate of frArchiveAudit.firstFiveHrefs) {
      if (!candidate) continue;
      await uatPage.goto(candidate, { waitUntil: 'domcontentloaded', timeout: 30000 });
      const slideCount = await uatPage.locator('.pk-carousel-slide').count();
      if (slideCount >= 2) {
        singleUrl = candidate;
        break;
      }
    }
    if (singleUrl) {
      await clearUatModals(uatPage);
      const countStart = (await uatPage.locator('.pk-carousel-count').textContent().catch(() => ''))?.trim() || '';
      if (await uatPage.locator('.pk-carousel-next').count()) {
        await uatPage.locator('.pk-carousel-next').click();
      }
      const countNext = (await uatPage.locator('.pk-carousel-count').textContent().catch(() => ''))?.trim() || '';
      await uatPage.locator('.pk-carousel-slide.is-active').click();
      const lbVisible = await uatPage.locator('.pk-gallery-lightbox').evaluate(el => !el.hidden);
      await uatPage.keyboard.press('Escape');
      const lbHidden = await uatPage.locator('.pk-gallery-lightbox').evaluate(el => el.hidden);
      record('interaction', 'UAT Single listing gallery carousel and lightbox',
        Boolean(countStart) && countStart !== countNext && lbVisible && lbHidden,
        `url=${singleUrl}; count=${countStart} -> ${countNext}; lbVisible=${lbVisible}; lbClosedEsc=${lbHidden}`);

      const shareBtn = uatPage.locator('.pk-single-share');
      await shareBtn.click();
      await uatPage.waitForTimeout(200);
      const copied = await uatPage.evaluate(() => window.__pkUatCopied || '');
      record('interaction', 'UAT Single listing share button',
        copied.includes('http'),
        `copied=${copied}`);

      // Toggle wishlist on single -> verify on /fr/favoris/ -> remove cleanly -> inspect /en/favoris/
      const heartBtn = uatPage.locator('.pk-single-actions .pk-card-wishlist');
      const favId = await heartBtn.getAttribute('data-post-id');
      await heartBtn.click();
      await uatPage.waitForTimeout(400);
      const favReq = uatPage.waitForResponse(
        r => r.url().includes('/admin-ajax.php') && r.request().postData()?.includes('action=pk_favorites_list'),
        { timeout: 15000 }
      );
      await uatPage.goto(`${BASE}/fr/favoris/?pk_audit=219`, { waitUntil: 'domcontentloaded', timeout: 30000 });
      const favRes = await favReq;
      const favCards = await uatPage.locator('#pk-favorites-grid .pk-card').count();
      record('interaction', 'UAT Wishlist + /fr/favoris/ live pk_favorites_list',
        favRes.status() === 200 && favCards >= 1,
        `postId=${favId}; http=${favRes.status()}; cards=${favCards}`);

      // Remove favorite cleanly so UAT server counter returns to baseline
      if (await uatPage.locator('#pk-favorites-grid .pk-card-wishlist').count()) {
        await uatPage.locator('#pk-favorites-grid .pk-card-wishlist').first().click();
        await uatPage.waitForTimeout(400);
      }
      await uatPage.goto(`${BASE}/en/favoris/?pk_audit=219`, { waitUntil: 'domcontentloaded', timeout: 30000 });
      await uatPage.waitForTimeout(400);
      const enFavText = (await uatPage.locator('#pk-fav-count').textContent().catch(() => ''))?.trim() || '';
      const enFavHardcodedFr = /Aucune annonce enregistrée/i.test(enFavText);
      record('finding', 'UAT /en/favoris/ empty text is localized', !enFavHardcodedFr,
        `#pk-fav-count="${enFavText}"`, 'warning');

      // Inspect live wa.me links across FR, EN, AR single pages
      for (const locale of ['fr', 'en', 'ar']) {
        await uatPage.goto(`${BASE}/${locale}/annonces/?pk_audit=219`, { waitUntil: 'domcontentloaded', timeout: 30000 });
        if (locale === 'ar') {
          const arBadges = await uatPage.evaluate(() =>
            Array.from(document.querySelectorAll('.pk-card-badge, .pk-badge')).slice(0, 6).map(el => el.textContent?.trim() || '')
          );
          const hasMissingAr = arBadges.some(t => /للإيجار|للبيع/.test(t));
          record('finding', 'UAT AR archive badges inspected', !hasMissingAr,
            `ar sample badges=${JSON.stringify(arBadges)}`, 'warning');
        }
        const firstCardUrl = await uatPage.locator('.pk-card a[href]').first().getAttribute('href');
        if (firstCardUrl) {
          await uatPage.goto(firstCardUrl, { waitUntil: 'domcontentloaded', timeout: 30000 });
          const waHref = await uatPage.locator('a.pk-btn-whatsapp[href]').first().getAttribute('href').catch(() => '');
          const waText = waHref ? (new URL(waHref).searchParams.get('text') || '') : '';
          const glued = /PK-[A-Z0-9-]+\.(?:Lien|Link|الرابط)/i.test(waText);
          record('finding', `UAT ${locale.toUpperCase()} wa.me link includes newline separator`,
            Boolean(waHref) && !glued,
            `url=${firstCardUrl}; text=${JSON.stringify(waText)}`, 'warning');
        }
      }
    }
  } catch (err) {
    record('interaction', 'UAT Single & Favorites flows', false, err.message);
  }

  // 4. Safe non-submitting checks: /ar/contact/, /fr/connexion/ toggles, /fr/deposer/ Step 1->3 UI
  try {
    await uatPage.goto(`${BASE}/ar/contact/?pk_audit=219`, { waitUntil: 'domcontentloaded', timeout: 30000 });
    const arContact = await uatPage.evaluate(() => ({
      formLang: document.querySelector('.pk-contact-form')?.getAttribute('lang') || '',
      submitText: document.querySelector('.pk-contact-form button[type="submit"]')?.textContent?.trim() || '',
      consentText: document.querySelector('label[for="pk-contact-consent"]')?.textContent?.trim() || '',
    }));
    const contactFrFallback = arContact.submitText === 'Envoyer' || /J’accepte/.test(arContact.consentText);
    record('finding', 'UAT /ar/contact/ localized instead of FR fallback', !contactFrFallback,
      JSON.stringify(arContact), 'warning');

    await uatPage.goto(`${BASE}/fr/connexion/?auth_item=login-form&pk_audit=219`, { waitUntil: 'domcontentloaded', timeout: 30000 });
    await clearUatModals(uatPage);
    let authToggleOk = false;
    const rstLink = uatPage.locator('.pk-auth-card [data-auth-item="reset-form"]').first();
    if (await rstLink.count()) {
      await rstLink.click();
      const rstOn = await uatPage.locator('.es-auth__reset-form').evaluate(el => !el.classList.contains('es-auth__item--hidden'));
      await uatPage.locator('.pk-auth-card [data-auth-item="login-form"]').first().click();
      const loginOn = await uatPage.locator('.es-auth__login-form').evaluate(el => !el.classList.contains('es-auth__item--hidden'));
      authToggleOk = rstOn && loginOn;
    }
    record('interaction', 'UAT /fr/connexion/ auth screen toggles work', authToggleOk, `authToggleOk=${authToggleOk}`);

    // Safety belt on /fr/deposer/: explicitly block any hypothetical pk_submit_listing POST on live UAT
    let deposerSubmitPosts = 0;
    await uatPage.route('**/admin-ajax.php', async (route) => {
      const postData = route.request().postData() || '';
      if (postData.includes('pk_submit_listing')) {
        deposerSubmitPosts++;
        await route.abort();
        return;
      }
      await route.continue();
    });
    await uatPage.goto(`${BASE}/fr/deposer/?pk_audit=219`, { waitUntil: 'domcontentloaded', timeout: 30000 });
    await uatPage.locator('#role [data-val="proprietaire"]').click();
    await uatPage.locator('#transaction [data-val="vente"]').click();
    await uatPage.locator('#famille [data-val="bureau"]').click();
    await uatPage.fill('#ville', 'Casablanca');
    await uatPage.locator('#ville-list li').first().waitFor({ state: 'visible', timeout: 5000 });
    await uatPage.locator('#ville-list li').first().dispatchEvent('mousedown');
    await uatPage.fill('#quartier', 'Maarif');
    await uatPage.locator('#quartier-list li').first().waitFor({ state: 'visible', timeout: 5000 });
    await uatPage.locator('#quartier-list li').first().dispatchEvent('mousedown');
    await uatPage.locator('#next').click();
    const s2 = await uatPage.locator('#page-2').evaluate(el => !el.hidden);

    await uatPage.locator('#bu-type [data-val="plateau"]').click();
    await uatPage.fill('#bu-surface', '120');
    await uatPage.locator('#bu-etage [data-val="rdc"]').click();
    await uatPage.locator('#bu-places [data-dir="1"]').click();
    await uatPage.locator('#bu-amenagement [data-val="ouvert"]').click();
    await uatPage.fill('#bu-prix', '2400000');
    await uatPage.setInputFiles('#photos-input', path.resolve('assets/img/pep-logo.png'));
    await uatPage.locator('#photos-liste > *').first().waitFor({ state: 'visible', timeout: 10000 });
    await uatPage.locator('#next').click();
    const s3 = await uatPage.locator('#page-3').evaluate(el => !el.hidden);
    const s3Title = await uatPage.locator('#annonce-titre').inputValue();
    await uatPage.fill('#annonce-nom', 'Audit UAT Lecture Seule');
    await uatPage.locator('#next').click();
    await uatPage.waitForTimeout(300);
    const s3Disabled = await uatPage.locator('#next').isDisabled();
    record('interaction', 'UAT /fr/deposer/ Steps 1 to 3 UI progression works', s2 && s3,
      `step2=${s2}; step3=${s3}; generatedTitle="${s3Title}"`);
    record('finding', 'UAT /fr/deposer/ Step 3 #next submits pk_submit_listing', !(s3Disabled && deposerSubmitPosts === 0),
      `nextDisabled=${s3Disabled}; submitPostsAttempted=${deposerSubmitPosts} (prototype dead-end in apercu-tunnel.html)`, 'warning');
  } catch (err) {
    record('interaction', 'UAT Contact / Connexion / Deposer checks', false, err.message);
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
  '## Constats (non bloquants / défauts préexistants confirmés)',
  '',
  ...checks.filter(item => item.category === 'finding').map(item => `- ${item.status} — ${item.check}: ${item.detail}`),
  '',
].join('\n');
await writeFile(path.join(OUT, 'interaction-web-vitals.md'), md);
console.log(md);
if (report.status === 'FAIL') process.exitCode = 1;
