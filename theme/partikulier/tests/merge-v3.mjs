import { chromium, firefox, webkit } from 'playwright';
import assert from 'node:assert/strict';
import fs from 'node:fs';

const base = process.env.PK_BASE || 'http://localhost:8099';
const fixture = JSON.parse(fs.readFileSync(process.env.PK_MERGE_FIXTURE, 'utf8').replace(/^\uFEFF/, ''));
const languages = (process.env.PK_LANGUAGES || 'fr,en,ar').split(',');
const results = [];
const required = {
  fr: 'Veuillez vérifier les champs obligatoires.',
  en: 'Please check the required fields.',
  ar: 'يرجى التحقق من الحقول المطلوبة.',
};

function ratio(color, background) {
  const values = value => value.match(/[\d.]+/g).map(Number);
  const bg = values(background);
  const fg = values(color);
  const alpha = fg[3] ?? 1;
  const rgb = fg.slice(0, 3).map((channel, i) => channel * alpha + bg[i] * (1 - alpha));
  const luminance = channels => channels.slice(0, 3).map(channel => {
    const value = channel / 255;
    return value <= 0.04045 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4;
  }).reduce((sum, channel, i) => sum + channel * [0.2126, 0.7152, 0.0722][i], 0);
  const a = luminance(rgb);
  const b = luminance(bg);
  return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
}

for (const [engineName, engine] of Object.entries({ chromium, firefox, webkit })) {
  const browser = await engine.launch();
  try {
    for (const width of [1280, 390]) {
      const context = await browser.newContext({ viewport: { width, height: 900 } });
      try {
        for (const language of languages) {
          assert.ok(fixture.listings[language], `Missing ${language} listing fixture`);
          const page = await context.newPage();
          const errors = [];
          page.on('pageerror', error => errors.push({ message: error.message, stack: error.stack, url: page.url() }));
          try {
            for (const path of [`/${language}/`, `/${language}/annonces/`, fixture.deposits[language], fixture.listings[language]]) {
              const url = new URL(path, base).href;
              const response = await page.goto(url, { waitUntil: 'domcontentloaded' });
              assert.equal(response.status(), 200, url);
              await page.evaluate(() => document.fonts.ready);
              const state = await page.evaluate(() => ({
                rtl: document.documentElement.dir,
                width: document.documentElement.clientWidth,
                scrollWidth: document.documentElement.scrollWidth,
                home: document.querySelector('.pk-logo')?.href || document.querySelector('.pk-header-brand a')?.href,
                links: [...document.querySelectorAll('.pk-lang-menu a')].map(link => link.href),
              }));
              assert.ok(state.scrollWidth <= state.width + 1, `Horizontal overflow on ${url}: ${JSON.stringify(state)}`);
              if (path === fixture.deposits[language]) {
                const deposit = await page.evaluate(() => ({
                  siteChrome: document.querySelectorAll('#site-header, .pk-site-header, #site-footer, .pk-site-footer, .pk-header-search').length,
                  formBar: document.querySelectorAll('.pk-lang-bar').length,
                  lang: document.documentElement.lang,
                  heading: document.querySelector('.pk-submit-title')?.textContent || '',
                }));
                assert.equal(deposit.siteChrome, 0, 'Deposit form has no site header, footer, or search');
                assert.equal(deposit.formBar, 1, 'Deposit form keeps its own language bar');
                assert.equal(deposit.lang, language, `Deposit form language follows the URL: ${deposit.lang}`);
                const headings = { fr: 'Votre bien mérite', en: 'Your property deserves', ar: 'عقارك يستحق' };
                assert.ok(deposit.heading.includes(headings[language]), `Deposit heading in ${language}: ${deposit.heading}`);
                if (language !== 'fr') assert.ok(!deposit.heading.includes('Votre bien mérite'), `French heading remains on ${language}`);
                if (language === 'ar') assert.equal(state.rtl, 'rtl', url);
              } else {
                if (language === 'ar') assert.equal(state.rtl, 'rtl', url);
                assert.ok(new URL(state.home).pathname.startsWith(`/${language}/`), `Language-specific logo on ${url}: ${state.home}`);
                assert.equal(state.links.length, 3, 'All configured languages are available');
                assert.ok(state.links.every(link => !new URL(link).searchParams.has('lang')), 'Language switcher uses canonical translated URLs');
              }
              results.push({ engine: engineName, width, language, url, check: 'navigation/layout', status: 'PASS' });
            }

            const listingState = await page.evaluate(() => {
              const card = document.querySelector('.pk-contact-card--dark');
              return {
                gallery: [...document.querySelectorAll('.pk-single-gallery img')].map(image => getComputedStyle(image).objectFit),
                titleSize: parseFloat(getComputedStyle(document.querySelector('.pk-single-title')).fontSize),
                colors: card ? [...card.querySelectorAll('.pk-contact-kicker, .pk-contact-legal, .pk-contact-owner span, .pk-contact-city, .pk-buyer-contact-flow small')]
                  .map(node => ({ color: getComputedStyle(node).color, background: getComputedStyle(card).backgroundColor })) : [],
              };
            });
            assert.ok(listingState.gallery.length > 0 && listingState.gallery.every(value => value === 'cover'), 'Gallery images cover their containers');
            if (width === 390) assert.equal(listingState.titleSize, 22, 'Mobile listing title is 22px');
            assert.ok(listingState.colors.length >= 2, 'Contact-card contrast elements are present');
            for (const colors of listingState.colors) assert.ok(ratio(colors.color, colors.background) >= 4.5, `WCAG text contrast: ${JSON.stringify(colors)}`);
            const modal = await page.evaluate(() => {
              const node = document.createElement('div');
              node.className = 'mfp-wrap';
              node.style.display = 'block';
              document.body.append(node);
              const visible = getComputedStyle(node).display !== 'none';
              node.remove();
              return visible;
            });
            assert.ok(modal, 'Unrelated Magnific Popup wrappers are not globally suppressed');
            results.push({ engine: engineName, width, language, check: 'gallery/contrast/modal', status: 'PASS' });

            await page.goto(fixture.deposits[language], { waitUntil: 'domcontentloaded' });
            let submits = 0;
            await page.route('**/admin-ajax.php', route => {
              if (route.request().postData()?.includes('pk_submit_listing')) {
                submits++;
                return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: false, data: { message: 'Fixture rejection' } }) });
              }
              return route.continue();
            });
            const legacyForm = await page.locator('#pk-name').count();
            if (legacyForm) {
              await page.evaluate(() => {
                for (const section of document.querySelectorAll('.pk-step')) section.hidden = section.dataset.step !== '3';
              });
              await page.locator('#pk-name').fill('A');
              await page.locator('#pk-phone').fill('0612345678');
              await page.locator('#pk-submit-btn').click();
              assert.equal(submits, 0, 'Invalid minlength cannot submit');
              await page.waitForFunction(text => document.querySelector('#pk-form-status').textContent.includes(text), required[language]);
              const original = await page.locator('#pk-submit-btn').textContent();
              await page.locator('#pk-name').fill('Fixture Owner');
              await page.locator('#pk-submit-btn').click();
              await page.waitForFunction(() => document.querySelector('#pk-form-status').textContent.includes('Fixture rejection'));
              assert.equal(submits, 1, 'Valid visible fields submit once');
              assert.equal(await page.locator('#pk-submit-btn').isEnabled(), true, 'Application error re-enables submit');
              assert.equal(await page.locator('#pk-submit-btn').textContent(), original, 'Application error restores original CTA');
            } else {
              assert.equal(await page.locator('#next').count(), 1, 'Deposit page shows the owner tunnel');
              await page.locator('#next').click();
              assert.equal(submits, 0, 'Incomplete tunnel cannot submit a listing');
              const tunnelErrors = {
                fr: ['Propriétaire', 'vendre ou louer'],
                en: ['Choose Owner to continue.', 'Choose sell or rent.'],
                ar: ['اختر المالك للمتابعة.', 'اختر البيع أو الكراء.'],
              };
              await page.waitForFunction(text => {
                const err = document.querySelector('#err');
                return Boolean(err && !err.hidden && err.textContent.includes(text));
              }, tunnelErrors[language][0]);
              const original = await page.locator('#next').textContent();
              await page.locator('#role [data-val="proprietaire"]').click();
              await page.locator('#next').click();
              await page.waitForFunction(text => {
                const err = document.querySelector('#err');
                return Boolean(err && err.textContent.includes(text));
              }, tunnelErrors[language][1]);
              assert.equal(submits, 0, 'Partial tunnel still does not submit');
              assert.equal(await page.locator('#next').isEnabled(), true, 'Validation error keeps the CTA usable');
              assert.equal(await page.locator('#next').textContent(), original, 'Validation error keeps the original CTA');
            }
            assert.deepEqual(errors, [], `No browser JavaScript errors: ${engineName}/${width}/${language}`);
            results.push({ engine: engineName, width, language, check: 'localized validation/error recovery', status: 'PASS' });
          } finally {
            await page.close();
          }
        }
      } finally {
        await context.close();
      }
    }
  } finally {
    await browser.close();
  }
}
console.log(JSON.stringify({ status: 'PASS', total: results.length, results }, null, 2));
