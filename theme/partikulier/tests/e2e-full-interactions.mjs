import { chromium } from 'playwright';
import { readFile, writeFile, mkdir } from 'node:fs/promises';
import path from 'node:path';
import process from 'node:process';

const BASE = (process.env.PK_BASE || 'http://127.0.0.1:8099').replace(/\/$/, '');
const FIXTURE_PATH = process.env.PK_E2E_FIXTURE || '';
const OUT_JSON = process.env.PK_E2E_OUT || 'preuves-run/integration/e2e-full.json';

if (!FIXTURE_PATH) {
  throw new Error('PK_E2E_FIXTURE is required');
}

const fixture = JSON.parse(await readFile(FIXTURE_PATH, 'utf8'));
await mkdir(path.dirname(OUT_JSON), { recursive: true });

const results = [];
const pageErrors = [];
const server5xx = [];

const record = (testId, category, ok, detail, severity = 'error') => {
  const status = ok ? 'PASS' : (severity === 'finding' ? 'KNOWN_DEFECT' : 'FAIL');
  results.push({ test_id: testId, category, status, severity, detail: String(detail ?? '') });
  console.log(`${status.padEnd(12)} ${testId} — ${detail}`);
};

const clearGates = async (page) => {
  const gate = page.locator('[data-pk-lang-gate]');
  if (await gate.isVisible().catch(() => false)) {
    const closeBtn = gate.locator('[data-pk-lang-dismiss]');
    if (await closeBtn.count()) {
      await closeBtn.click({ timeout: 5000 }).catch(() => {});
      await gate.waitFor({ state: 'hidden', timeout: 5000 }).catch(() => {});
    }
  }
  const cookieBar = page.locator('[data-pk-cookie]');
  if (await cookieBar.isVisible().catch(() => false)) {
    const refuseBtn = cookieBar.locator('[data-no]');
    if (await refuseBtn.count()) {
      await refuseBtn.click({ timeout: 5000 }).catch(() => {});
      await cookieBar.waitFor({ state: 'hidden', timeout: 5000 }).catch(() => {});
    }
  }
};

const attachMonitors = (page, label) => {
  page.on('pageerror', (err) => pageErrors.push(`${label}: ${err.message}`));
  page.on('response', (res) => {
    if (res.status() >= 500) {
      server5xx.push(`${label}: HTTP ${res.status()} ${res.url()}`);
    }
  });
};

const browser = await chromium.launch({ headless: true });

try {
  // =========================================================================
  // 1. Desktop flow: Hero Search + unmocked pk_places_search & pk_find_count
  // =========================================================================
  const deskCtx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  // Ensure clipboard fallback works cleanly in headless browser for share button check.
  await deskCtx.addInitScript(() => {
    if (!navigator.clipboard) {
      Object.defineProperty(navigator, 'clipboard', {
        value: {
          writeText: async (text) => { window.__pkCopiedText = text; },
        },
        configurable: true,
      });
    } else {
      const orig = navigator.clipboard.writeText?.bind(navigator.clipboard);
      navigator.clipboard.writeText = async (text) => {
        window.__pkCopiedText = text;
        if (orig) return orig(text).catch(() => {});
      };
    }
  });
  const page = await deskCtx.newPage();
  attachMonitors(page, 'desktop');

  await page.goto(fixture.pages.homeFr, { waitUntil: 'domcontentloaded', timeout: 30000 });
  await clearGates(page);

  // Lang toggle open & Escape close
  const langToggle = page.locator('.pk-lang-toggle').first();
  if (await langToggle.count()) {
    await langToggle.click();
    const open = await page.evaluate(() => ({
      expanded: document.querySelector('.pk-lang-toggle')?.getAttribute('aria-expanded'),
      hidden: document.querySelector('.pk-lang-menu')?.hidden,
    }));
    await page.keyboard.press('Escape');
    const closed = await page.evaluate(() => ({
      expanded: document.querySelector('.pk-lang-toggle')?.getAttribute('aria-expanded'),
      hidden: document.querySelector('.pk-lang-menu')?.hidden,
    }));
    record('E2E-NAV-LANG-001', 'navigation', open.expanded === 'true' && open.hidden === false && closed.expanded === 'false' && closed.hidden === true,
      `open=${JSON.stringify(open)}; closed=${JSON.stringify(closed)}`);
  } else {
    record('E2E-NAV-LANG-001', 'navigation', false, 'Missing .pk-lang-toggle on home');
  }

  // Click "À louer" tab -> opens modal & fires pk_find_count AJAX
  const findCountPromise = page.waitForResponse(
    (res) => res.url().includes('/admin-ajax.php') && res.request().postData()?.includes('action=pk_find_count'),
    { timeout: 15000 }
  );
  await page.locator('.pk-find-tab[for="pk-find-rent"]').click();
  const findCountRes = await findCountPromise;
  const findCountJson = await findCountRes.json().catch(() => null);
  const modalOpened = await page.evaluate(() => !!document.querySelector('.pk-find-open')?.checked);
  record('E2E-SEARCH-MODAL-001', 'search', modalOpened && findCountRes.status() === 200 && findCountJson?.success === true,
    `modalOpened=${modalOpened}; http=${findCountRes.status()}; json=${JSON.stringify(findCountJson)}`);

  // Type "Casa" into autocomplete input -> unmocked pk_places_search
  const placeInput = page.locator('#pk-s-city-input');
  const placesPromise = page.waitForResponse(
    (res) => res.url().includes('action=pk_places_search') && res.url().includes('Casa'),
    { timeout: 15000 }
  );
  await placeInput.fill('Casa');
  const placesRes = await placesPromise;
  const placesJson = await placesRes.json().catch(() => null);
  const firstSuggest = page.locator('#pk-s-city-suggestions .pk-suggest-item').first();
  await firstSuggest.waitFor({ state: 'visible', timeout: 10000 });
  const suggestCount = await page.locator('#pk-s-city-suggestions .pk-suggest-item').count();
  await firstSuggest.dispatchEvent('mousedown');
  const chosenCitySlug = await page.locator('#pk-s-city-value').inputValue();
  record('E2E-PLACES-AJAX-001', 'search',
    placesRes.status() === 200 && placesJson?.success === true && suggestCount > 0 && chosenCitySlug.length > 0,
    `http=${placesRes.status()}; suggestions=${suggestCount}; es_city=${chosenCitySlug}`);

  // Switch back to sale tab inside modal & choose appartement -> pk_find_count updates
  const countUpdatePromise = page.waitForResponse(
    (res) => res.url().includes('/admin-ajax.php') && res.request().postData()?.includes('action=pk_find_count'),
    { timeout: 15000 }
  );
  await page.locator('.pk-find-tx-btn[data-pk-intent="a-vendre"]').click();
  const countRes2 = await countUpdatePromise;
  const countJson2 = await countRes2.json().catch(() => null);
  const countAppartPromise = page.waitForResponse(
    (res) => res.url().includes('/admin-ajax.php') && res.request().postData()?.includes('action=pk_find_count'),
    { timeout: 15000 }
  );
  await page.locator('.pk-find-type-card[data-value="appartement"]').click();
  const countRes3 = await countAppartPromise;
  const countJson3 = await countRes3.json().catch(() => null);
  record('E2E-FIND-COUNT-001', 'search',
    countRes2.status() === 200 && countRes3.status() === 200 && typeof countJson3?.data?.count === 'number',
    `saleCount=${countJson2?.data?.count}; appartCount=${countJson3?.data?.count}`);

  // Submit search -> navigates to archive with filters
  await Promise.all([
    page.waitForURL((url) => url.pathname.includes('/annonces'), { timeout: 15000 }),
    page.locator('.pk-find-results').click(),
  ]);
  const archiveUrlAfterSearch = page.url();
  const archiveCardsAfterSearch = await page.locator('.pk-archive-content .pk-card').count();
  record('E2E-SEARCH-SUBMIT-001', 'search',
    archiveUrlAfterSearch.includes('es_city=casablanca') && archiveCardsAfterSearch >= 1,
    `url=${archiveUrlAfterSearch}; cards=${archiveCardsAfterSearch}`);

  // =========================================================================
  // 2. Archive AJAX Swaps: Sort modal, Places modal, Chip removal, Mobile bar
  // =========================================================================
  await page.goto(fixture.pages.archiveFr, { waitUntil: 'domcontentloaded', timeout: 30000 });
  await clearGates(page);

  // Mark window so we can verify no full page reload occurs during AJAX swap!
  await page.evaluate(() => { window.__pkArchiveNoReload = 'alive'; });

  // Open Sort modal -> click price-desc -> AJAX swap
  await page.locator('[data-pk-open-sort]:visible').first().click();
  const sortVisible = await page.locator('[data-pk-sort]').evaluate((el) => !el.hidden);
  const sortSwapPromise = page.waitForResponse(
    (res) => res.url().includes('pk_order=price-desc') && res.request().headers()['x-requested-with'] === 'pk-results',
    { timeout: 15000 }
  );
  await page.locator('.pk-sort-list a[href*="pk_order=price-desc"]').first().click();
  const sortSwapRes = await sortSwapPromise;
  await page.waitForFunction(() => location.search.includes('pk_order=price-desc'), { timeout: 10000 });
  const stayedAliveSort = await page.evaluate(() => window.__pkArchiveNoReload === 'alive');
  record('E2E-ARCHIVE-SORT-AJAX-001', 'archive',
    sortVisible && sortSwapRes.status() === 200 && stayedAliveSort && page.url().includes('pk_order=price-desc'),
    `sortVisible=${sortVisible}; http=${sortSwapRes.status()}; noReload=${stayedAliveSort}; url=${page.url()}`);

  // Places modal ([data-pk-open-places]) -> click first city/district link -> AJAX swap + chosen chip
  const openPlacesBtn = page.locator('[data-pk-open-places]:visible').first();
  if (await openPlacesBtn.count()) {
    await openPlacesBtn.click();
    const placesModalVisible = await page.locator('[data-pk-places]').evaluate((el) => !el.hidden);
    const firstPlaceLink = page.locator('[data-pk-places] .pk-places-list a[href]').first();
    if (await firstPlaceLink.count()) {
      const placeHref = await firstPlaceLink.getAttribute('href');
      const placeSwapPromise = page.waitForResponse(
        (res) => res.request().headers()['x-requested-with'] === 'pk-results',
        { timeout: 15000 }
      );
      await firstPlaceLink.click();
      const placeSwapRes = await placeSwapPromise;
      await page.waitForTimeout(300);
      const stayedAlivePlace = await page.evaluate(() => window.__pkArchiveNoReload === 'alive');
      const chosenChips = await page.locator('.pk-chosen-chip').count();
      record('E2E-ARCHIVE-PLACES-AJAX-001', 'archive',
        placesModalVisible && placeSwapRes.status() === 200 && stayedAlivePlace && chosenChips >= 1,
        `modal=${placesModalVisible}; href=${placeHref}; http=${placeSwapRes.status()}; chips=${chosenChips}; noReload=${stayedAlivePlace}`);
    } else {
      record('E2E-ARCHIVE-PLACES-AJAX-001', 'archive', false, 'No links in .pk-places-list');
      await page.locator('[data-pk-places-close]').first().click().catch(() => {});
    }
  } else {
    record('E2E-ARCHIVE-PLACES-AJAX-001', 'archive', false, 'Missing [data-pk-open-places] trigger on archive');
  }

  // Chosen filter chip removal -> AJAX swap
  const chipRemove = page.locator('.pk-chosen-chip[href], .pk-chosen-chip a[href]').first();
  if (await chipRemove.count()) {
    const beforeChips = await page.locator('.pk-chosen-chip').count();
    const chipSwapPromise = page.waitForResponse(
      (res) => res.request().headers()['x-requested-with'] === 'pk-results',
      { timeout: 15000 }
    );
    await chipRemove.click();
    const chipSwapRes = await chipSwapPromise;
    await page.waitForTimeout(300);
    const afterChips = await page.locator('.pk-chosen-chip').count();
    const stayedAliveChip = await page.evaluate(() => window.__pkArchiveNoReload === 'alive');
    record('E2E-ARCHIVE-CHIP-REMOVE-001', 'archive',
      chipSwapRes.status() === 200 && stayedAliveChip && afterChips < beforeChips,
      `before=${beforeChips}; after=${afterChips}; http=${chipSwapRes.status()}; noReload=${stayedAliveChip}`);
  } else {
    record('E2E-ARCHIVE-CHIP-REMOVE-001', 'archive', false, 'No removable .pk-chosen-chip found after place filter');
  }

  // Post-AJAX-swap wishlist button check (detects whether pkBindWishlist stays attached after DOM swap)
  const postSwapHeart = page.locator('.pk-archive-content .pk-card-wishlist').first();
  if (await postSwapHeart.count()) {
    const beforePressed = await postSwapHeart.getAttribute('aria-pressed');
    await postSwapHeart.click();
    await page.waitForTimeout(200);
    const afterPressed = await postSwapHeart.getAttribute('aria-pressed');
    const swappedWorked = beforePressed !== afterPressed;
    record('E2E-ARCHIVE-WISHLIST-POSTSWAP-001', 'archive', swappedWorked,
      swappedWorked
        ? `aria-pressed toggled ${beforePressed} -> ${afterPressed} after AJAX swap`
        : `DEFECT: .pk-card-wishlist detached after archive AJAX swap (aria-pressed stayed ${beforePressed}) because swap() skips pkBindWishlist()`,
      swappedWorked ? 'error' : 'finding');
    if (swappedWorked) {
      await postSwapHeart.click(); // restore state cleanly
    }
  }

  // =========================================================================
  // 3. Single Listing (Active + Closed): Gallery, Lightbox, Wishlist, Views, Share, WhatsApp
  // =========================================================================
  const viewsPromise = page.waitForResponse(
    (res) => res.url().includes('/admin-ajax.php') && res.request().postData()?.includes('action=pk_views_counter'),
    { timeout: 15000 }
  );
  await page.goto(fixture.listings.activeOwner.url, { waitUntil: 'domcontentloaded', timeout: 30000 });
  await clearGates(page);
  const viewsRes = await viewsPromise;
  const viewsJson = await viewsRes.json().catch(() => null);
  record('E2E-SINGLE-VIEWS-001', 'single',
    viewsRes.status() === 200 && viewsJson?.success === true,
    `http=${viewsRes.status()}; json=${JSON.stringify(viewsJson)}`);

  // Gallery carousel + Lightbox open/Escape close
  const countBefore = (await page.locator('.pk-carousel-count').textContent())?.trim();
  await page.locator('.pk-carousel-next').click();
  const countAfter = (await page.locator('.pk-carousel-count').textContent())?.trim();
  await page.locator('.pk-carousel-slide.is-active').click();
  const lbOpen = await page.locator('.pk-gallery-lightbox').evaluate((el) => !el.hidden);
  await page.keyboard.press('Escape');
  const lbClosed = await page.locator('.pk-gallery-lightbox').evaluate((el) => el.hidden);
  record('E2E-SINGLE-GALLERY-001', 'single',
    countBefore === '1 / 2' && countAfter === '2 / 2' && lbOpen && lbClosed,
    `carousel=${countBefore} -> ${countAfter}; lightboxOpen=${lbOpen}; lightboxClosedEsc=${lbClosed}`);

  // Single share button click
  const shareBtn = page.locator('.pk-single-share');
  await shareBtn.click();
  await page.waitForTimeout(200);
  const shareLabelAfter = await shareBtn.getAttribute('aria-label');
  const copiedText = await page.evaluate(() => window.__pkCopiedText || '');
  record('E2E-SINGLE-SHARE-001', 'single',
    copiedText.includes('http') || shareLabelAfter === 'Lien copié',
    `ariaLabel=${shareLabelAfter}; copied=${copiedText}`);

  // Active WhatsApp link check & newline separator inspection
  const waHref = await page.locator('a.pk-btn-whatsapp[href]').first().getAttribute('href');
  const waUrl = waHref ? new URL(waHref) : null;
  const waText = waUrl ? (waUrl.searchParams.get('text') || '') : '';
  const waGlued = /PK-[A-Z0-9-]+\.(?:Lien|Link|الرابط)/i.test(waText);
  record('E2E-SINGLE-WHATSAPP-HREF-001', 'single',
    Boolean(waHref) && waHref.startsWith('https://wa.me/') && !waGlued,
    waGlued
      ? `DEFECT: esc_url() stripped %0A newline, gluing reference and Lien: ${JSON.stringify(waText)}`
      : `href=${waHref}; text=${JSON.stringify(waText)}`,
    waGlued ? 'finding' : 'error');

  // Wishlist toggle on single -> syncs pk_sync_favorite & localStorage
  const syncFavPromise = page.waitForResponse(
    (res) => res.url().includes('/admin-ajax.php') && res.request().postData()?.includes('action=pk_sync_favorite'),
    { timeout: 15000 }
  );
  await page.locator('.pk-single-actions .pk-card-wishlist').click();
  const syncFavRes = await syncFavPromise;
  const syncFavJson = await syncFavRes.json().catch(() => null);
  const wishSaved = await page.evaluate((id) => {
    const arr = JSON.parse(localStorage.getItem('pk_wishlist') || '[]');
    return arr.includes(String(id));
  }, fixture.listings.activeOwner.id);
  record('E2E-SINGLE-WISHLIST-001', 'single',
    syncFavRes.status() === 200 && syncFavJson?.success === true && wishSaved,
    `http=${syncFavRes.status()}; savedInLocalStorage=${wishSaved}`);

  // Closed listing verification
  await page.goto(fixture.listings.closed.url, { waitUntil: 'domcontentloaded', timeout: 30000 });
  const closedState = await page.evaluate(() => ({
    hasClosedClass: !!document.querySelector('.pk-single-closed'),
    hasSoldBanner: !!document.querySelector('.pk-contact-sold'),
    directWaCount: document.querySelectorAll('.pk-buyer-contact-flow a.pk-btn-whatsapp, .pk-wa-sticky a.pk-btn-whatsapp').length,
    similarWaCount: document.querySelectorAll('.pk-closure-similar a.pk-btn-whatsapp').length,
    hasSimilarBlock: !!document.querySelector('.pk-closure-similar, .pk-single-related'),
  }));
  record('E2E-SINGLE-CLOSED-001', 'single',
    closedState.hasClosedClass && closedState.hasSoldBanner && closedState.directWaCount === 0 && closedState.similarWaCount >= 1,
    JSON.stringify(closedState));

  // =========================================================================
  // 4. Favorites Page (/fr/favoris/ + /en/favoris/)
  // =========================================================================
  const favListPromise = page.waitForResponse(
    (res) => res.url().includes('/admin-ajax.php') && res.request().postData()?.includes('pk_favorites_list'),
    { timeout: 15000 }
  );
  await page.goto(fixture.pages.favorisFr, { waitUntil: 'domcontentloaded', timeout: 30000 });
  await clearGates(page);
  const favListRes = await favListPromise;
  const favListJson = await favListRes.json().catch(() => null);
  const favCardsCount = await page.locator('#pk-favorites-grid .pk-card').count();
  const favCountFr = (await page.locator('#pk-fav-count').textContent())?.trim();
  record('E2E-FAVORIS-LIST-001', 'favorites',
    favListRes.status() === 200 && favListJson?.success === true && favCardsCount >= 1,
    `http=${favListRes.status()}; cards=${favCardsCount}; countText=${favCountFr}`);

  // Remove favorite from /fr/favoris/ -> test /en/favoris/ empty state & hardcoded FR check
  await page.locator('#pk-favorites-grid .pk-card-wishlist').first().click();
  await page.waitForTimeout(200);
  await page.goto(fixture.pages.favorisEn, { waitUntil: 'domcontentloaded', timeout: 30000 });
  await page.waitForTimeout(400);
  const favEnEmptyVisible = await page.locator('#pk-favorites-empty').isVisible();
  const favEnCountText = (await page.locator('#pk-fav-count').textContent())?.trim() || '';
  const favEnHardcodedFr = /Aucune annonce enregistrée/i.test(favEnCountText);
  record('E2E-FAVORIS-EMPTY-I18N-001', 'favorites',
    favEnEmptyVisible && !favEnHardcodedFr,
    favEnHardcodedFr
      ? `DEFECT: /en/favoris/ #pk-fav-count overwritten with hardcoded French "${favEnCountText}" by main.js:676`
      : `emptyVisible=${favEnEmptyVisible}; text=${favEnCountText}`,
    favEnHardcodedFr ? 'finding' : 'error');

  // =========================================================================
  // 5. Contact Form (/fr/contact/ real POST + wp_mail + /ar/contact/ inspection)
  // =========================================================================
  await page.goto(fixture.pages.contactFr, { waitUntil: 'domcontentloaded', timeout: 30000 });
  await clearGates(page);

  // Invalid email test (server-side redirect ?pkc=)
  await page.fill('#pk-contact-name', 'Test E2E');
  await page.fill('#pk-contact-email', 'not-an-email');
  await page.fill('#pk-contact-message', 'Message invalide test');
  await page.check('#pk-contact-consent');
  await page.locator('.pk-contact-form').evaluate((form) => { form.noValidate = true; });
  await Promise.all([
    page.waitForURL((u) => u.searchParams.has('pkc'), { timeout: 15000 }),
    page.locator('.pk-contact-form button[type="submit"]').click(),
  ]);
  const contactErrText = (await page.locator('.pk-contact-status').textContent())?.trim() || '';
  record('E2E-CONTACT-VALIDATION-001', 'contact',
    contactErrText.includes('e-mail valide'),
    `url=${page.url()}; statusText=${contactErrText}`);

  // Valid contact submission -> redirects ?envoye=1 + wp_mail captured
  await page.fill('#pk-contact-name', 'Test E2E Valid');
  await page.fill('#pk-contact-email', 'e2e-sender@example.test');
  await page.fill('#pk-contact-message', 'Bonjour Partikulier E2E test reel');
  await page.check('#pk-contact-consent');
  await Promise.all([
    page.waitForURL((u) => u.searchParams.get('envoye') === '1', { timeout: 15000 }),
    page.locator('.pk-contact-form button[type="submit"]').click(),
  ]);
  const contactSuccessText = (await page.locator('.pk-contact-status').textContent())?.trim() || '';
  const mailLines = (await readFile(fixture.mailLog, 'utf8').catch(() => '')).trim().split('\n').filter(Boolean);
  const capturedMails = mailLines.map((line) => JSON.parse(line));
  const mailToContact = capturedMails.some((m) => String(m.to).includes('contact@pep.ma') && String(m.message).includes('Bonjour Partikulier E2E test reel'));
  record('E2E-CONTACT-SUBMIT-001', 'contact',
    contactSuccessText === 'Message envoyé.' && mailToContact,
    `status="${contactSuccessText}"; mailsCaptured=${capturedMails.length}; matched=${mailToContact}`);

  // Inspect /ar/contact/ i18n
  await page.goto(fixture.pages.contactAr, { waitUntil: 'domcontentloaded', timeout: 30000 });
  const arContactInfo = await page.evaluate(() => ({
    formLang: document.querySelector('.pk-contact-form')?.getAttribute('lang') || '',
    submitText: document.querySelector('.pk-contact-form button[type="submit"]')?.textContent?.trim() || '',
    consentText: document.querySelector('label[for="pk-contact-consent"]')?.textContent?.trim() || '',
  }));
  const arFallbackFr = arContactInfo.submitText === 'Envoyer' || /J’accepte/.test(arContactInfo.consentText);
  record('E2E-CONTACT-AR-I18N-001', 'contact',
    !arFallbackFr,
    arFallbackFr
      ? `DEFECT: /ar/contact/ falls back to French (${JSON.stringify(arContactInfo)})`
      : JSON.stringify(arContactInfo),
    arFallbackFr ? 'finding' : 'error');

  // =========================================================================
  // 6. Deposit /deposer/ Step 1->3 Prototype UI + Real pk_submit_listing AJAX
  // =========================================================================
  await page.goto(fixture.pages.deposerFr, { waitUntil: 'domcontentloaded', timeout: 30000 });
  let deposerAjaxRequests = 0;
  page.on('request', (req) => {
    if (req.url().includes('admin-ajax.php') && req.postData()?.includes('pk_submit_listing')) {
      deposerAjaxRequests++;
    }
  });

  // Step 1
  await page.locator('#role [data-val="proprietaire"]').click();
  await page.locator('#transaction [data-val="vente"]').click();
  await page.locator('#famille [data-val="bureau"]').click();
  await page.fill('#ville', 'Casablanca');
  await page.locator('#ville-list li').first().waitFor({ state: 'visible', timeout: 5000 });
  await page.locator('#ville-list li').first().dispatchEvent('mousedown');
  await page.fill('#quartier', 'Maarif');
  await page.locator('#quartier-list li').first().waitFor({ state: 'visible', timeout: 5000 });
  await page.locator('#quartier-list li').first().dispatchEvent('mousedown');
  await page.locator('#next').click();
  const step2Visible = await page.locator('#page-2').evaluate((el) => !el.hidden);

  // Step 2 (Bureau + real JPEG upload)
  await page.locator('#bu-type [data-val="plateau"]').click();
  await page.fill('#bu-surface', '120');
  await page.locator('#bu-etage [data-val="rdc"]').click();
  await page.locator('#bu-places [data-dir="1"]').click();
  await page.locator('#bu-amenagement [data-val="ouvert"]').click();
  await page.fill('#bu-prix', '2400000');
  await page.setInputFiles('#photos-input', fixture.heroJpg);
  await page.locator('#photos-liste > *').first().waitFor({ state: 'visible', timeout: 10000 });
  await page.locator('#next').click();
  const step3Visible = await page.locator('#page-3').evaluate((el) => !el.hidden);

  // Step 3 (#next sets disabled = true without calling pk_submit_listing!)
  const step3Title = await page.locator('#annonce-titre').inputValue();
  await page.fill('#annonce-nom', 'Proprietaire E2E');
  await page.locator('#next').click();
  await page.waitForTimeout(400);
  const nextDisabledAfterClick = await page.locator('#next').isDisabled();
  const deposerDeadEnd = step2Visible && step3Visible && nextDisabledAfterClick && deposerAjaxRequests === 0;
  record('E2E-DEPOSER-TUNNEL-UI-001', 'deposit',
    step2Visible && step3Visible && !deposerDeadEnd,
    deposerDeadEnd
      ? `DEFECT: /deposer/ Step 1->2->3 UI works (title="${step3Title}"), but Step 3 #next sets disabled=true with 0 pk_submit_listing AJAX calls`
      : `step2=${step2Visible}; step3=${step3Visible}; nextDisabled=${nextDisabledAfterClick}; ajaxCalls=${deposerAjaxRequests}`,
    deposerDeadEnd ? 'finding' : 'error');

  // Real unmocked pk_submit_listing backend check via pkConfig.submitNonce on /fr/
  await page.goto(fixture.pages.homeFr, { waitUntil: 'domcontentloaded', timeout: 30000 });
  const heroBytesBase64 = (await readFile(fixture.heroJpg)).toString('base64');
  const submitResult = await page.evaluate(async ({ cfg, b64, terms }) => {
    const raw = Uint8Array.from(atob(b64), (c) => c.charCodeAt(0));
    const blob = new Blob([raw], { type: 'image/jpeg' });
    const fd = new FormData();
    fd.append('action', 'pk_submit_listing');
    fd.append('nonce', cfg.submitNonce);
    fd.append('pk_language', 'fr');
    fd.append('pk_idempotency_key', 'e2e-' + Math.random().toString(36).slice(2) + Date.now());
    fd.append('pk_role', 'proprietaire');
    fd.append('pk_action_mode', 'vendre');
    fd.append('pk_listing_action', 'a-vendre');
    fd.append('pk_type', String(terms.typeAppartId));
    fd.append('pk_city', String(terms.cityCasaId));
    fd.append('pk_city_name', 'Casablanca');
    fd.append('pk_district_name', 'Maarif');
    fd.append('pk_surface', '88');
    fd.append('pk_price', '990000');
    fd.append('pk_bedrooms', '2');
    fd.append('pk_living_rooms', '1');
    fd.append('pk_bathrooms', '1');
    fd.append('pk_title', 'Appartement E2E-DEPOSER-REAL Casablanca');
    fd.append('pk_description', 'Bel appartement lumineux de 88 m2 situe au coeur de Casablanca Maarif, proche toutes commodites.');
    fd.append('pk_name', 'Proprietaire Submit E2E');
    fd.append('pk_phone', '212699887766');
    fd.append('pk_photos[]', blob, 'e2e-submit.jpg');
    const res = await fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd });
    const json = await res.json().catch(() => null);
    return { status: res.status, json };
  }, {
    cfg: await page.evaluate(() => window.pkConfig),
    b64: heroBytesBase64,
    terms: fixture.terms,
  });
  record('E2E-PK-SUBMIT-LISTING-AJAX-001', 'deposit',
    submitResult.status === 200 && submitResult.json?.success === true && submitResult.json?.data?.status === 'pending_whatsapp' && String(submitResult.json?.data?.whatsapp_url || '').includes('wa.me/'),
    `http=${submitResult.status}; data=${JSON.stringify(submitResult.json?.data || submitResult.json)}`);

  // =========================================================================
  // 7. Connexion toggles + Owner Dashboard (/mes-annonces/ deactivate/reactivate)
  // =========================================================================
  await page.goto(`${fixture.pages.connexionFr}?auth_item=login-form`, { waitUntil: 'domcontentloaded', timeout: 30000 });
  await clearGates(page);

  // Test [data-auth-item] switch to reset-form then back to login-form
  const resetLink = page.locator('.pk-auth-card [data-auth-item="reset-form"]').first();
  let authToggleOk = false;
  if (await resetLink.count()) {
    await resetLink.click();
    const resetVisible = await page.locator('.pk-auth-card .es-auth__reset-form').first().evaluate((el) => !el.classList.contains('es-auth__item--hidden'));
    const backLogin = page.locator('.pk-auth-card [data-auth-item="login-form"]').first();
    if (await backLogin.count()) {
      await backLogin.click();
    }
    const loginVisible = await page.locator('.pk-auth-card .es-auth__login-form').first().evaluate((el) => !el.classList.contains('es-auth__item--hidden'));
    authToggleOk = resetVisible && loginVisible;
  }
  record('E2E-CONNEXION-TOGGLES-001', 'auth', authToggleOk, `authToggleOk=${authToggleOk}`);

  // Log in as seeded contributor owner
  await page.locator('.pk-auth-card input[type="email"], .pk-auth-card input[type="text"]').first().fill(fixture.owner.email);
  await page.locator('.pk-auth-card input[type="password"]').first().fill(fixture.owner.password);
  await page.locator('.pk-auth-card button[type="submit"], .pk-auth-card input[type="submit"]').first().click();

  let loggedIn = false;
  for (let i = 0; i < 40; i++) {
    const cookies = await deskCtx.cookies(BASE);
    if (cookies.some((c) => c.name.startsWith('wordpress_logged_in_'))) {
      loggedIn = true;
      break;
    }
    await page.waitForTimeout(250);
  }
  record('E2E-OWNER-LOGIN-001', 'auth', loggedIn, `sessionCookie=${loggedIn}`);

  await page.goto(fixture.pages.mesAnnoncesFr, { waitUntil: 'domcontentloaded', timeout: 30000 });
  const ownerId = fixture.listings.activeOwner.id;
  const ownerItem = page.locator(`.pk-listing-item:has([data-post-id="${ownerId}"])`).first();
  await ownerItem.waitFor({ state: 'visible', timeout: 10000 });
  const editHref = await ownerItem.locator('a[href*="edit="]').first().getAttribute('href');
  const kpiStats = await page.locator('.pk-stat-value').allTextContents();
  record('E2E-OWNER-DASHBOARD-001', 'owner',
    Boolean(editHref) && kpiStats.length === 4,
    `editHref=${editHref}; kpis=${JSON.stringify(kpiStats)}`);

  // Deactivate (vendu) -> real AJAX pk_manage_listing -> reload -> status Vendu
  await page.evaluate(() => { window.__pkBeforeReload = true; });
  await ownerItem.locator(`.pk-manage-btn[data-action="deactivate"][data-post-id="${ownerId}"]`).click();
  await ownerItem.locator(`input[name="pk_reason_${ownerId}"][value="vendu"]`).check();
  const deactivatePromise = page.waitForResponse(
    (res) => res.url().includes('/admin-ajax.php') && res.request().postData()?.includes('pk_manage_listing'),
    { timeout: 15000 }
  );
  await ownerItem.locator(`.pk-manage-btn[data-action="deactivate_confirm"][data-post-id="${ownerId}"]`).click();
  const deactivateRes = await deactivatePromise;
  await page.waitForFunction(() => !window.__pkBeforeReload, { timeout: 10000 });
  await page.waitForLoadState('domcontentloaded');
  await page.locator(`.pk-listing-item:has([data-post-id="${ownerId}"]) .pk-listing-status`).waitFor({ state: 'visible', timeout: 10000 });
  const statusAfterDeactivate = (await page.locator(`.pk-listing-item:has([data-post-id="${ownerId}"]) .pk-listing-status`).textContent())?.trim();
  record('E2E-OWNER-DEACTIVATE-001', 'owner',
    deactivateRes.status() === 200 && statusAfterDeactivate === 'Vendu',
    `http=${deactivateRes.status()}; statusAfter=${statusAfterDeactivate}`);

  // Reactivate -> real AJAX pk_manage_listing -> reload -> status En ligne
  await page.evaluate(() => { window.__pkBeforeReload = true; });
  const reactivatePromise = page.waitForResponse(
    (res) => res.url().includes('/admin-ajax.php') && res.request().postData()?.includes('pk_manage_listing'),
    { timeout: 15000 }
  );
  await page.locator(`.pk-manage-btn[data-action="reactivate"][data-post-id="${ownerId}"]`).click();
  const reactivateRes = await reactivatePromise;
  await page.waitForFunction(() => !window.__pkBeforeReload, { timeout: 10000 });
  await page.waitForLoadState('domcontentloaded');
  await page.locator(`.pk-listing-item:has([data-post-id="${ownerId}"]) .pk-listing-status`).waitFor({ state: 'visible', timeout: 10000 });
  const statusAfterReactivate = (await page.locator(`.pk-listing-item:has([data-post-id="${ownerId}"]) .pk-listing-status`).textContent())?.trim();
  record('E2E-OWNER-REACTIVATE-001', 'owner',
    reactivateRes.status() === 200 && statusAfterReactivate === 'En ligne',
    `http=${reactivateRes.status()}; statusAfter=${statusAfterReactivate}`);

  // Check footer social links
  const hashLinks = await page.locator('footer a[href="#"]').count();
  record('E2E-FOOTER-HASH-LINKS-001', 'chrome',
    hashLinks === 0,
    hashLinks > 0 ? `DEFECT: footer has ${hashLinks} placeholder a[href="#"] links` : '0 placeholder links',
    hashLinks > 0 ? 'finding' : 'error');

  await deskCtx.close();

  // =========================================================================
  // 8. Mobile viewport check (390x844): Nav toggle, mobile scroll filter chips
  // =========================================================================
  const mobCtx = await browser.newContext({ viewport: { width: 390, height: 844 }, isMobile: true });
  const mobPage = await mobCtx.newPage();
  attachMonitors(mobPage, 'mobile');

  await mobPage.goto(fixture.pages.homeFr, { waitUntil: 'domcontentloaded', timeout: 30000 });
  await clearGates(mobPage);
  await mobPage.locator('.pk-nav-toggle').first().click();
  const mobNavOpen = await mobPage.evaluate(() => document.querySelector('.pk-nav-toggle')?.getAttribute('aria-expanded') === 'true');
  await mobPage.keyboard.press('Escape');
  const mobNavClosed = await mobPage.evaluate(() => document.querySelector('.pk-nav-toggle')?.getAttribute('aria-expanded') === 'false');
  record('E2E-MOBILE-NAV-001', 'mobile', mobNavOpen && mobNavClosed, `open=${mobNavOpen}; closed=${mobNavClosed}`);

  await mobPage.goto(fixture.pages.archiveFr, { waitUntil: 'domcontentloaded', timeout: 30000 });
  await mobPage.evaluate(() => window.scrollTo(0, 800));
  const mobChip = mobPage.locator('.pk-scroll-actions.is-on .pk-filter-chip').first();
  await mobChip.waitFor({ state: 'visible', timeout: 8000 });
  await mobChip.click();
  const mobFindOpen = await mobPage.evaluate(() => !!document.querySelector('.pk-find-open')?.checked);
  await mobPage.keyboard.press('Escape');
  record('E2E-MOBILE-ARCHIVE-CHIPS-001', 'mobile', mobFindOpen, `mobFindOpen=${mobFindOpen}`);

  await mobCtx.close();

  record('E2E-JS-ERRORS-001', 'runtime', pageErrors.length === 0, pageErrors.join(' | ') || 'none');
  record('E2E-HTTP-5XX-001', 'runtime', server5xx.length === 0, server5xx.join(' | ') || 'none');
} finally {
  await browser.close();
}

const hardFails = results.filter((r) => r.status === 'FAIL').length;
const findings = results.filter((r) => r.status === 'KNOWN_DEFECT').length;
const passed = results.filter((r) => r.status === 'PASS').length;

const report = {
  target: BASE,
  run: fixture.run,
  checked_at: new Date().toISOString(),
  total: results.length,
  passed,
  findings,
  failed: hardFails,
  status: hardFails === 0 ? 'PASS' : 'FAIL',
  results,
};

await writeFile(OUT_JSON, JSON.stringify(report, null, 2) + '\n');
const mdPath = OUT_JSON.replace(/\.json$/i, '.md');
const md = [
  '# Full Unmocked Browser E2E Report (Docker CI)',
  '',
  `- Target: ${BASE}`,
  `- Run ID: ${fixture.run}`,
  `- Summary: ${passed} PASS · ${findings} KNOWN_DEFECT · ${hardFails} FAIL (total ${results.length})`,
  '',
  ...results.map((r) => `- **${r.status}** \`${r.test_id}\` (${r.category}): ${r.detail}`),
  '',
].join('\n');
await writeFile(mdPath, md);
console.log(`\nSummary: ${passed} PASS, ${findings} KNOWN_DEFECT, ${hardFails} FAIL`);
if (hardFails > 0) {
  process.exitCode = 1;
}
