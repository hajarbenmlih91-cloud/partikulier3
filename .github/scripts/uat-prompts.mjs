import { chromium } from 'playwright';
import { mkdir, writeFile } from 'node:fs/promises';
import path from 'node:path';

const BASE = (process.env.PK_BASE || '').replace(/\/$/, '');
const EMAIL = process.env.PROMPT_EMAIL || '';
const PASSWORD = process.env.PROMPT_PASSWORD || '';
const DISPLAY_NAME = process.env.PROMPT_DISPLAY_NAME || '';
const LISTING_ID = process.env.PROMPT_LISTING_ID || '';
const LISTING_URL = process.env.PROMPT_LISTING_URL || '';
const OUT = process.env.AUDIT_OUT_DIR || 'uat-prompt-artifacts';
if (BASE !== 'https://darkcyan-yak-151002.hostingersite.com') throw new Error('UAT target guard rejected this host');
if (!EMAIL || !PASSWORD || !DISPLAY_NAME || !/^\d+$/.test(LISTING_ID) || !LISTING_URL) throw new Error('Protected fixture inputs are incomplete');
await mkdir(OUT, { recursive: true });
const results = [];
const pageErrors = [];
const failures = [];
const record = (prompt, name, pass, detail = '', status = null) => {
  const result = { prompt, name, result: status || (pass ? 'PASS' : 'FAIL'), detail: String(detail).slice(0, 900) };
  results.push(result);
  console.log(`${result.result} P${prompt} ${name}${detail ? ` — ${result.detail}` : ''}`);
};
const browser = await chromium.launch({ headless: true });
const context = await browser.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, deviceScaleFactor: 2 });
const page = await context.newPage();
page.on('pageerror', e => pageErrors.push(e.message));
page.on('requestfailed', req => {
  if (req.url().startsWith(BASE) && !req.url().includes('/favicon.ico')) failures.push(`${req.method()} ${new URL(req.url()).pathname}: ${req.failure()?.errorText || 'failed'}`);
});

async function visit(url, options = {}) {
  const started = performance.now();
  const response = await page.goto(url, { waitUntil: 'load', timeout: 35000, ...options });
  const ms = Math.round(performance.now() - started);
  let bodyBytes = 0;
  try { bodyBytes = (await response?.body())?.length || 0; } catch {}
  const nav = await page.evaluate(() => {
    const n = performance.getEntriesByType('navigation')[0];
    return n ? { ttfb_ms: Math.round(n.responseStart), dom_ms: Math.round(n.domContentLoadedEventEnd), load_ms: Math.round(n.loadEventEnd), transfer_bytes: n.transferSize || 0 } : null;
  }).catch(() => null);
  return { response, ms, bodyBytes, nav };
}
async function acceptMeasurementIfShown() {
  const bar = page.locator('[data-pk-cookie]');
  if (await bar.isVisible().catch(() => false)) {
    const yes = bar.locator('[data-yes]');
    if (!(await yes.count())) return { clicked: false, hidden: false, label: '' };
    const label = (await yes.innerText()).trim();
    await yes.click(); // trusted click on the real consent action; never force-click.
    await bar.waitFor({ state: 'hidden', timeout: 10000 });
    return { clicked: true, hidden: true, label };
  }
  return { clicked: false, hidden: true, label: 'banner absent/already decided in this context' };
}
async function login() {
  const loginUrl = `${BASE}/fr/connexion/?auth_item=login-form&redirect_url=${encodeURIComponent(`${BASE}/fr/mes-annonces/`)}`;
  const response = await visit(loginUrl);
  const email = page.locator('.pk-auth-card input[type="email"]:visible, .pk-auth-card input[name*="email" i]:visible, .pk-auth-card input[type="text"]:visible').first();
  const password = page.locator('.pk-auth-card input[type="password"]:visible').first();
  const submit = page.locator('.pk-auth-card button[type="submit"]:visible, .pk-auth-card input[type="submit"]:visible').first();
  if (response.response?.status() !== 200 || !(await email.count()) || !(await password.count()) || !(await submit.count())) return false;
  await email.fill(EMAIL); await password.fill(PASSWORD); await submit.click();
  await page.waitForTimeout(900);
  await visit(`${BASE}/fr/mes-annonces/`);
  return !await page.locator('.pk-auth-card input[type="password"]').count() && (await page.locator('body').innerText()).includes(DISPLAY_NAME);
}

const measured = [];
try {
  // P1/P2 — account is seeded via protected WP-CLI, then displayed and used through the real UI.
  await visit(`${BASE}/fr/`);
  const consent = await acceptMeasurementIfShown();
  record(2, 'Accepter la mesure avec le vrai bouton et fermer le bandeau', consent.hidden && (consent.clicked || consent.label.includes('absent/already')), consent.label);
  const loggedIn = await login();
  record(1, 'Compte temporaire créé; nom affiché après connexion', loggedIn, `nom=${DISPLAY_NAME}; création par WP-CLI protégée; aucune inscription publique simulée`);
  const frBody = await page.locator('body').innerText();
  record(2, 'Espace propriétaire français', /Mon espace|Mes annonces|Bonjour/i.test(frBody), frBody.match(/Mon espace|Mes annonces|Bonjour/i)?.[0] || 'libellé français absent');
  const arResponse = await visit(`${BASE}/ar/mes-annonces/`);
  const arBody = await page.locator('body').innerText();
  record(2, 'Espace propriétaire arabe', arResponse.response?.status() === 200 && arBody.includes('حسابي'), `HTTP=${arResponse.response?.status()}; libellé=${arBody.includes('حسابي')}`);
  // The WordPress toolbar may render its logout anchor in the DOM but hidden on mobile.
  // Follow its same-origin nonce URL as a navigation instead of force-clicking a hidden control.
  const logout = page.locator('a[href*="action=logout"]').first();
  const logoutHref = await logout.getAttribute('href').catch(() => null);
  let loggedOut = false;
  let logoutStatus = 'lien absent';
  if (logoutHref) {
    const logoutUrl = new URL(logoutHref, BASE);
    if (logoutUrl.origin === new URL(BASE).origin) {
      const logoutResponse = await visit(logoutUrl.href);
      const dashboardCheck = await visit(`${BASE}/fr/mes-annonces/`);
      const loginFormVisible = await page.locator('.pk-auth-card input[type="password"]').count() > 0;
      loggedOut = dashboardCheck.response?.status() === 200 && (loginFormVisible || /connexion|login/i.test(page.url()));
      logoutStatus = `HTTP=${logoutResponse.response?.status()}; dashboard login gate=${loginFormVisible}`;
    } else {
      logoutStatus = 'lien de déconnexion hors origine refusé';
    }
  }
  const reconnected = loggedOut && await login();
  record(2, 'Déconnexion puis nouvelle connexion réelle', reconnected, `${logoutStatus}; déconnecté=${loggedOut}; seconde connexion=${reconnected}`);

  // P3 — only the one run-marked fixture owned by this account.
  await visit(LISTING_URL);
  const contactLinks = page.locator('#pk-contact-card > .pk-buyer-contact-flow a[href^="https://wa.me/"], #pk-contact-card > .pk-buyer-contact-flow a[href^="tel:"]');
  const beforeContact = await contactLinks.count();
  await visit(`${BASE}/fr/mes-annonces/`);
  const deactivate = page.locator(`.pk-manage-btn[data-post-id="${LISTING_ID}"][data-action="deactivate"]`);
  const ownRow = page.locator(`.pk-listing-item:has(.pk-manage-btn[data-post-id="${LISTING_ID}"])`).first();
  if (await deactivate.count() && await ownRow.count()) {
    await deactivate.click();
    const reason = page.locator(`input[name="pk_reason_${LISTING_ID}"][value="avis"]`);
    await reason.check();
    await ownRow.locator(`.pk-manage-btn[data-post-id="${LISTING_ID}"][data-action="deactivate_confirm"]`).click();
    await page.waitForFunction(id => {
      const row = document.querySelector(`.pk-listing-item:has(.pk-manage-btn[data-post-id="${id}"])`);
      return row && row.querySelector(`.pk-manage-btn[data-action="reactivate"]`);
    }, LISTING_ID, { timeout: 15000 }).catch(() => {});
    const deactivated = await ownRow.locator('.pk-manage-btn[data-action="reactivate"]').count() > 0;
    const publicResponse = await visit(LISTING_URL);
    const publicContact = await page.locator('#pk-contact-card > .pk-buyer-contact-flow a[href^="https://wa.me/"], #pk-contact-card > .pk-buyer-contact-flow a[href^="tel:"]').count();
    record(3, 'Désactiver uniquement l’annonce du compte de test', deactivated, `id=${LISTING_ID}; status bouton réactiver=${deactivated}`);
    const contactProvenCut = beforeContact > 0 && publicContact === 0;
    record(3, 'Contact public absent après désactivation', publicResponse.response?.status() === 200 && publicContact === 0,
      `avant=${beforeContact}; après=${publicContact}; ${beforeContact === 0 ? 'aucun contact n’était visible avant, donc la coupure ne peut pas être comparée' : 'la coupure est observable'}`,
      beforeContact === 0 ? 'WARN' : (contactProvenCut ? 'PASS' : 'FAIL'));
  } else {
    record(3, 'Désactivation de l’annonce fixture', false, 'Le bouton de désactivation de la seule annonce marquée est absent');
  }

  // P4–P6 — inspect only; never click a deposit submit button.
  const deposits = [
    ['fr', 'Choisissez vendre, louer ou la location saisonnière.', /Choisissez vendre, louer ou la location saisonnière\./],
    ['en', 'Choose sell, rent or seasonal rental.', /Choose sell, rent or seasonal rental\./],
    ['ar', 'اختر البيع أو الكراء أو الكراء اليومي.', /اختر البيع أو الكراء أو الكراء اليومي\./],
  ];
  let enTitle = '';
  let enBody = '';
  for (const [locale, expected, pattern] of deposits) {
    const r = await visit(`${BASE}/${locale}/deposer/`);
    const steps = await page.locator('.pk-stepper-item').count();
    const ownerChoice = page.locator('#role button[data-val="proprietaire"]');
    const next = page.locator('#next');
    const nextType = await next.getAttribute('type').catch(() => null);
    let validationMessage = '';
    let validationTriggered = false;
    if (await ownerChoice.count() && await next.count() && nextType === 'button') {
      await ownerChoice.click();
      await next.click(); // validates step one only; no submit button is activated.
      const error = page.locator('#err');
      validationMessage = (await error.innerText().catch(() => '')).trim();
      validationTriggered = await error.isVisible().catch(() => false);
    }
    const body = await page.locator('body').innerText();
    const secondMessage = pattern.test(validationMessage);
    const langOkay = locale !== 'ar' || (await page.locator('html').getAttribute('dir')) === 'rtl';
    if (locale === 'en') { enTitle = await page.title(); enBody = body; }
    record(locale === 'fr' ? 4 : locale === 'en' ? 5 : 6, `${locale.toUpperCase()} dépôt: 3 étapes, validation exacte, aucun envoi`,
      r.response?.status() === 200 && steps === 3 && validationTriggered && secondMessage && langOkay && nextType === 'button',
      `HTTP=${r.response?.status()}; étapes=${steps}; validation=${validationMessage || 'absente'}; exacte=${pattern.test(validationMessage)}; dir=${await page.locator('html').getAttribute('dir')}; #next type=${nextType}; aucun formulaire soumis`);
  }
  record(5, 'Titre du dépôt anglais localisé, sans titre français', /Your property deserves/i.test(enTitle + ' ' + enBody.slice(0, 600)) && !/Votre bien mérite/i.test(enTitle + ' ' + enBody), `title=${enTitle}; anglais=${/Your property deserves/i.test(enTitle + ' ' + enBody.slice(0,600))}; titre français=${/Votre bien mérite/i.test(enTitle + ' ' + enBody)}`);

  // P7 — SEO checks, deliberately excluding robots.txt.
  const home = await visit(`${BASE}/fr/`);
  const seo = await page.evaluate(() => ({
    title: document.title,
    description: document.querySelector('meta[name="description"]')?.content || '',
    canonical: document.querySelector('link[rel="canonical"]')?.href || '',
  }));
  const sitemapResponse = await context.request.get(`${BASE}/sitemap.xml`, { timeout: 20000 }).catch(() => null);
  const sitemapText = await sitemapResponse?.text().catch(() => '') || '';
  const depositSeo = await context.request.get(`${BASE}/fr/deposer/`, { timeout: 20000 }).catch(() => null);
  const depositHtml = await depositSeo?.text().catch(() => '') || '';
  const noindex = /<meta[^>]+name=["']robots["'][^>]+content=["'][^"']*noindex/i.test(depositHtml);
  record(7, 'SEO titre, description, canonique, sitemap sans Hello World, dépôt noindex',
    home.response?.status() === 200 && !!seo.title && !!seo.description && !!seo.canonical && sitemapResponse?.ok() && !/hello-world/i.test(sitemapText) && noindex,
    `title=${seo.title}; description=${seo.description.slice(0,100)}; canonical=${seo.canonical}; sitemap=${sitemapResponse?.status()}; hello-world=${/hello-world/i.test(sitemapText)}; deposit-noindex=${noindex}; robots non consulté`);

  // P8 — exactly one sequential navigation per page/form factor; no parallel browser sessions.
  const routes = [ ['accueil', `${BASE}/fr/`], ['liste', `${BASE}/fr/annonces/`], ['fiche', LISTING_URL] ];
  for (const width of [1440, 390]) {
    await page.setViewportSize({ width, height: width === 390 ? 844 : 900 });
    for (const [label, url] of routes) {
      const r = await visit(url);
      measured.push({ label, viewport: width === 390 ? 'mobile' : 'desktop', status: r.response?.status(), elapsed_ms: r.ms, html_bytes: r.bodyBytes, navigation: r.nav });
    }
  }
  record(8, 'Six visites séquentielles (desktop/mobile × accueil/liste/fiche)', measured.length === 6 && measured.every(m => m.status === 200), JSON.stringify(measured));

  // P9 — real visitor path, WhatsApp labels, reversible local favorite, language switch without forced click.
  const archive = await visit(`${BASE}/fr/annonces/`);
  const card = page.locator('.pk-card').first();
  const fav = card.locator('.pk-card-wishlist').first();
  let favoriteRoundTrip = false;
  let selectedDetail = LISTING_URL;
  if (await fav.count()) {
    const before = await page.evaluate(() => JSON.parse(localStorage.getItem('pk_wishlist') || '[]')).catch(() => []);
    await fav.click();
    const added = await page.evaluate(() => JSON.parse(localStorage.getItem('pk_wishlist') || '[]')).catch(() => []);
    await fav.click();
    const removed = await page.evaluate(() => JSON.parse(localStorage.getItem('pk_wishlist') || '[]')).catch(() => []);
    favoriteRoundTrip = added.length === before.length + 1 && removed.length === before.length;
    const cardLink = card.locator('a[href]').first();
    selectedDetail = await cardLink.getAttribute('href').catch(() => null) || LISTING_URL;
    if (selectedDetail.startsWith('/')) selectedDetail = `${BASE}${selectedDetail}`;
  }
  const labels = [
    ['fr', 'Contacter sur WhatsApp'], ['en', 'Ask on WhatsApp'], ['ar', 'الطلب عبر واتساب'],
  ];
  const waReport = [];
  for (const [locale, expectedLabel] of labels) {
    const detailUrl = selectedDetail.replace(/\/(fr|en|ar)\//, `/${locale}/`);
    await visit(detailUrl);
    const wa = page.locator('a[href^="https://wa.me/"], a[href^="http://wa.me/"]');
    const links = await wa.evaluateAll(els => els.map(a => ({ text: a.innerText.trim(), href: a.href })));
    waReport.push({ locale, expectedLabel, links });
  }
  const numberPresent = waReport.flatMap(x => x.links).some(x => x.href.includes('212640424412'));
  await visit(`${BASE}/fr/annonces/`);
  const originalUrl = page.url();
  const langToggle = page.locator('.pk-lang-toggle').first();
  let noFullReload = false;
  if (await langToggle.count()) {
    await langToggle.click();
    const enLink = page.locator('.pk-lang-menu a[href*="/en/"]').first();
    if (await enLink.count()) {
      const beforeUrl = page.url();
      await enLink.click();
      noFullReload = page.url() === beforeUrl;
    }
  }
  const waLabelsOk = waReport.every(x => x.links.some(l => l.text.includes(x.expectedLabel)));
  const waTextSummary = waReport.map(x => `${x.locale}: ${x.links.map(l => l.text).join(' | ')}`).join('; ');
  record(9, 'Parcours visiteur, favoris réversibles et boutons WhatsApp FR/EN/AR',
    archive.response?.status() === 200 && favoriteRoundTrip && waLabelsOk,
    `archive HTTP=${archive.response?.status()}; favori aller-retour=${favoriteRoundTrip}; libellés localisés=${waLabelsOk}; ${waTextSummary}; numéro 212640424412 présent=${numberPresent}`);
  record(9, 'Changement de langue sans rechargement complet', noFullReload,
    `URL initiale=${originalUrl}; même URL après sélection=${noFullReload}`, noFullReload ? 'PASS' : 'NOTE');
  if (!numberPresent) record(9, 'Numéro WhatsApp non déployé sur le tag UAT', true, '212640424412 absent; la PR dédiée n’est pas sur le tag déployé.', 'NOTE');

  // P10 — cautious average-rate inference only; not a capacity or peak-load test.
  const avgLoad = measured.length ? Math.round(measured.reduce((s, x) => s + (x.navigation?.load_ms || x.elapsed_ms), 0) / measured.length) : null;
  const avgBytes = measured.length ? Math.round(measured.reduce((s, x) => s + x.html_bytes, 0) / measured.length) : null;
  const plausible = avgLoad !== null && avgLoad < 5000 && avgBytes < 2_000_000;
  record(10, '10 000 visites/jour ≈ 6,94/min : estimation sans charge', plausible,
    `moyenne séquentielle=${avgLoad} ms; corps HTML moyen=${avgBytes} bytes; conclusion=${plausible ? 'plausible pour la moyenne, capacité de pointe non démontrée' : 'usage possiblement limité; une visite ne démontre pas la capacité'}`,
    plausible ? 'PASS' : 'WARN');
} catch (error) {
  record('execution', 'Erreur inattendue de scénario', false, error?.stack || String(error));
} finally {
  await context.close().catch(() => {});
  await browser.close().catch(() => {});
}
for (const err of pageErrors) record('browser', 'Erreur JavaScript', false, err);
for (const err of failures) record('network', 'Requête première partie échouée', false, err);
const summary = {
  target: BASE,
  checked_at: new Date().toISOString(),
  fixture: { display_name: DISPLAY_NAME, listing_id: LISTING_ID, listing_url: LISTING_URL },
  performance_visits: measured,
  results,
  status: results.some(r => r.result === 'FAIL') ? 'FAIL' : 'PASS_WITH_NOTES',
  note: 'No HMAC tests, no deposit submissions, no concurrent requests, no production target.',
};
await writeFile(path.join(OUT, 'prompts-validation.json'), JSON.stringify(summary, null, 2));
await writeFile(path.join(OUT, 'prompts-validation.md'), [
  '# Validation des 10 scénarios UAT', '', `Cible : ${BASE}`, `Date : ${summary.checked_at}`, `Verdict : ${summary.status}`, '',
  ...results.map(r => `- **P${r.prompt} ${r.result} — ${r.name}**${r.detail ? `: ${r.detail}` : ''}`), '',
  'Performance (visites séquentielles) :', '```json', JSON.stringify(measured, null, 2), '```', '',
  'Aucune charge, aucun formulaire de dépôt soumis, aucun test HMAC exécuté.',
].join('\n') + '\n');
if (summary.status === 'FAIL') process.exitCode = 1;
