import { chromium } from 'playwright';
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import path from 'node:path';
import process from 'node:process';

const BASE = (process.env.PK_BASE || '').replace(/\/$/, '');
const OUT = process.env.AUDIT_OUT_DIR || 'audit-artifacts';
const AXE_FILE = path.resolve('node_modules/axe-core/axe.min.js');
const LOCALES = ['fr', 'en', 'ar'];
const WIDTHS = [360, 390, 768, 1440];
const ROUTES = ['/', '/annonces/', '/deposer/', '/contact/'];
const results = [];
const findings = [];

if (!/^https:\/\//.test(BASE)) throw new Error('PK_BASE must be the HTTPS UAT URL');
await mkdir(path.join(OUT, 'screenshots'), { recursive: true });

const record = (category, check, ok, detail, severity = 'error') => {
  const row = { category, check, status: ok ? 'PASS' : 'FAIL', severity, detail: String(detail ?? '') };
  results.push(row);
  if (!ok) findings.push(row);
};

let axeSource = '';
try { axeSource = await readFile(AXE_FILE, 'utf8'); } catch { /* axe is optional for local invocation */ }
const browser = await chromium.launch({ headless: true });

try {
  for (const locale of LOCALES) {
    for (const width of WIDTHS) {
      const context = await browser.newContext({
        viewport: { width, height: width < 500 ? 844 : 900 },
        isMobile: width < 500,
        deviceScaleFactor: width < 500 ? 2 : 1,
      });
      const page = await context.newPage();
      const pageErrors = [];
      const failedRequests = [];
      page.on('pageerror', error => pageErrors.push(error.message));
      page.on('requestfailed', request => {
        const url = request.url();
        if (url.startsWith(BASE) && !url.includes('/favicon.ico')) failedRequests.push(`${request.method()} ${url}: ${request.failure()?.errorText || 'failed'}`);
      });

      for (const route of ROUTES) {
        const url = `${BASE}/${locale}${route}`.replace(/\/{2,}/g, '/').replace('https:/', 'https://');
        let response;
        try {
          response = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 30000 });
          await page.evaluate(() => document.fonts?.ready);
          await page.waitForTimeout(150);
        } catch (error) {
          record('availability', `${locale} ${route} ${width}px`, false, error.message);
          continue;
        }

        const state = await page.evaluate(() => {
          const meta = selector => document.querySelector(selector)?.getAttribute('content') || '';
          const canonical = document.querySelector('link[rel="canonical"]')?.href || '';
          const h1 = [...document.querySelectorAll('h1')].map(node => node.innerText.trim()).filter(Boolean);
          const inputs = [...document.querySelectorAll('input:not([type="hidden"]), select, textarea')];
          const unlabeled = inputs.filter(input => {
            if (input.getAttribute('aria-label') || input.getAttribute('aria-labelledby')) return false;
            if (input.id && document.querySelector(`label[for="${CSS.escape(input.id)}"]`)) return false;
            return !input.closest('label');
          }).length;
          const unnamedLinks = [...document.querySelectorAll('a[href]')].filter(node =>
            !node.getAttribute('aria-label') && !node.innerText.trim() && !node.querySelector('img[alt], svg[aria-label], svg[title]')
          ).length;
          const unnamedButtons = [...document.querySelectorAll('button')].filter(node =>
            !node.getAttribute('aria-label') && !node.innerText.trim() && !node.querySelector('svg[aria-label], svg[title]')
          ).length;
          const missingAlt = [...document.querySelectorAll('img')].filter(node => !node.hasAttribute('alt')).length;
          const cards = [...document.querySelectorAll('.pk-card')].map(card => ({
            title: card.querySelector('.pk-card-title')?.innerText.trim() || card.querySelector('h2,h3')?.innerText.trim() || '',
            badge: card.querySelector('.pk-card-badge:not(.pk-card-badge-premium)')?.innerText.trim() || '',
            href: card.querySelector('a[href*="/annonce/"]')?.href || '',
          }));
          const whatsapp = [...document.querySelectorAll('a[href^="https://wa.me/"]')].map(link => {
            const target = new URL(link.href).pathname.replaceAll('/', '');
            return { text: link.innerText.trim(), hasRecipient: /^\d{8,15}$/.test(target), href: link.href };
          });
          return {
            title: document.title,
            description: meta('meta[name="description"]'),
            robots: meta('meta[name="robots"]'),
            canonical,
            hreflangCount: document.querySelectorAll('link[rel="alternate"][hreflang]').length,
            htmlLang: document.documentElement.lang,
            dir: document.documentElement.dir,
            clientWidth: document.documentElement.clientWidth,
            scrollWidth: document.documentElement.scrollWidth,
            h1,
            formFields: inputs.length,
            unlabeledFields: unlabeled,
            unnamedLinks,
            unnamedButtons,
            missingAlt,
            cards,
            whatsapp,
            lcp: 0,
            cls: 0,
            navTiming: (() => {
              const entry = performance.getEntriesByType('navigation')[0];
              return entry ? { responseStart: entry.responseStart, domContentLoaded: entry.domContentLoadedEventEnd, load: entry.loadEventEnd, transferSize: entry.transferSize } : null;
            })(),
          };
        });

        record('availability', `${locale} ${route} ${width}px HTTP`, response?.status() === 200, `HTTP ${response?.status() ?? 'no response'}; final URL ${page.url()}`);
        record('responsive', `${locale} ${route} ${width}px no horizontal overflow`, state.scrollWidth <= state.clientWidth + 1, `scrollWidth=${state.scrollWidth}, clientWidth=${state.clientWidth}`);
        record('i18n', `${locale} ${route} document language`, state.htmlLang.toLowerCase().startsWith(locale), `lang=${state.htmlLang}, dir=${state.dir}`);
        record('seo', `${locale} ${route} title`, state.title.length >= 10, state.title || '(empty)');
        record('seo', `${locale} ${route} canonical present`, !!state.canonical, state.canonical || '(missing)', 'warning');
        record('seo', `${locale} ${route} hreflang alternates`, state.hreflangCount >= 3, `${state.hreflangCount} alternate links`, 'warning');
        record('accessibility', `${locale} ${route} images have alt attributes`, state.missingAlt === 0, `${state.missingAlt} images missing alt`, 'warning');
        record('accessibility', `${locale} ${route} form controls have labels`, state.unlabeledFields === 0, `${state.unlabeledFields} controls without a programmatic label`, 'warning');
        record('accessibility', `${locale} ${route} links have names`, state.unnamedLinks === 0, `${state.unnamedLinks} unnamed links`, 'warning');
        record('accessibility', `${locale} ${route} buttons have names`, state.unnamedButtons === 0, `${state.unnamedButtons} unnamed buttons`, 'warning');
        if (route !== '/deposer/' && route !== '/contact/') {
          record('seo', `${locale} ${route} meta description`, state.description.length >= 50, state.description || '(missing/short)', 'warning');
        }
        if (state.canonical && !state.canonical.startsWith(BASE)) {
          record('seo', `${locale} ${route} canonical points to UAT host`, false, state.canonical, 'warning');
        }
        if (route === '/deposer/') {
          record('ux', `${locale} deposit form has fields`, state.formFields >= 10, `${state.formFields} form fields`);
          const bodyText = await page.locator('body').innerText();
          record('ux', `${locale} deposit page has no prototype placeholder`, !/questions détaillées de cette fiche ne sont pas encore|detailed questions.*not yet/i.test(bodyText), 'Prototype-copy scan on rendered text', 'warning');
        }
        if (route === '/annonces/' && state.cards.length) {
          for (const card of state.cards) {
            const titleMode = /\bà vendre\b|\bfor sale\b|للبيع/i.test(card.title) ? 'sale'
              : /\bà louer\b|\bfor rent\b|للكراء/i.test(card.title) ? 'rent' : '';
            const badgeMode = /à louer|for rent|للكراء/i.test(card.badge) ? 'rent'
              : /à vendre|for sale|للبيع/i.test(card.badge) ? 'sale' : '';
            if (titleMode && badgeMode && titleMode !== badgeMode) {
              record('data-integrity', `${locale} card title/status alignment`, false, `${card.title} | badge=${card.badge} | ${card.href}`);
            }
          }
        }
        if (route === '/annonce/' || (route === '/annonces/' && width === 390)) {
          for (const link of state.whatsapp) {
            record('ux', `${locale} WhatsApp CTA has a recipient`, link.hasRecipient, `${link.text}: ${link.href}`, 'warning');
          }
        }
        if (width === 390 && (route === '/' || route === '/deposer/')) {
          const shot = path.join(OUT, 'screenshots', `${locale}-${route === '/' ? 'home' : 'deposit'}-390.png`);
          await page.screenshot({ path: shot, fullPage: true });
        }
        if (axeSource && width === 390 && ['/', '/annonces/', '/deposer/'].includes(route)) {
          try {
            await page.addScriptTag({ content: axeSource });
            const axe = await page.evaluate(async () => window.axe.run(document, {
              runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'] },
            }));
            for (const violation of axe.violations) {
              const severe = ['critical', 'serious'].includes(violation.impact);
              record('accessibility', `${locale} ${route} axe ${violation.id}`, false,
                `${violation.impact}: ${violation.help}; nodes=${violation.nodes.length}; ${violation.nodes.slice(0, 3).map(node => node.target.join(',')).join(' | ')}`,
                severe ? 'error' : 'warning');
            }
            record('accessibility', `${locale} ${route} axe scan`, axe.violations.length === 0,
              `${axe.violations.length} violation groups; ${axe.passes.length} checks passed`, 'warning');
          } catch (error) {
            record('accessibility', `${locale} ${route} axe execution`, false, error.message, 'warning');
          }
        }
      }

      if (pageErrors.length) record('javascript', `${locale} ${width}px browser errors`, false, pageErrors.join(' | '));
      if (failedRequests.length) record('network', `${locale} ${width}px failed first-party requests`, false, failedRequests.join(' | '));
      await context.close();
    }

    // A real property detail (first listing visible in the current archive).
    const context = await browser.newContext({ viewport: { width: 390, height: 844 }, isMobile: true });
    const page = await context.newPage();
    try {
      await page.goto(`${BASE}/${locale}/annonces/`, { waitUntil: 'domcontentloaded', timeout: 30000 });
      const detailHref = await page.locator('a[href*="/annonce/"]').first().getAttribute('href').catch(() => null);
      if (!detailHref) {
        record('listing', `${locale} detail reachable from archive`, false, 'No detail link found');
      } else {
        const detailResponse = await page.goto(new URL(detailHref, BASE).href, { waitUntil: 'domcontentloaded', timeout: 30000 });
        const detail = await page.evaluate(() => ({
          title: document.querySelector('h1')?.innerText.trim() || '',
          badge: document.querySelector('.pk-card-badge:not(.pk-card-badge-premium)')?.innerText.trim() || '',
          wa: [...document.querySelectorAll('a[href^="https://wa.me/"]')].map(a => ({ text: a.innerText.trim(), href: a.href, recipient: /^\d{8,15}$/.test(new URL(a.href).pathname.replaceAll('/', '')) })),
          canonical: document.querySelector('link[rel="canonical"]')?.href || '',
          titleTag: document.title,
        }));
        record('listing', `${locale} detail HTTP`, detailResponse?.status() === 200, `HTTP ${detailResponse?.status() ?? 'none'}`);
        record('seo', `${locale} detail title`, !!detail.titleTag, detail.titleTag || '(empty)');
        const titleMode = /\bà vendre\b|\bfor sale\b|للبيع/i.test(detail.title) ? 'sale'
          : /\bà louer\b|\bfor rent\b|للكراء/i.test(detail.title) ? 'rent' : '';
        const badgeMode = /à louer|for rent|للكراء/i.test(detail.badge) ? 'rent'
          : /à vendre|for sale|للبيع/i.test(detail.badge) ? 'sale' : '';
        if (titleMode && badgeMode) record('data-integrity', `${locale} detail title/status alignment`, titleMode === badgeMode, `title=${detail.title}; badge=${detail.badge}; meta=${detail.titleTag}`);
        for (const link of detail.wa) record('ux', `${locale} detail WhatsApp recipient`, link.recipient, `${link.text}: ${link.href}`, 'warning');
      }
    } catch (error) {
      record('listing', `${locale} live listing detail`, false, error.message);
    } finally {
      await context.close();
    }
  }

  // SEO directives and sitemap on the actual UAT origin.
  try {
    const robotsResponse = await browser.newPage();
    const response = await robotsResponse.request.get(`${BASE}/robots.txt`, { timeout: 20000 });
    const body = await response.text();
    const wildcardGroup = body.split(/(?=^User-agent:)/im).find(group => /^User-agent:\s*\*/im.test(group)) || '';
    const wildcardBlocks = /^Disallow:\s*\/\s*$/im.test(wildcardGroup);
    record('seo', 'UAT robots.txt blocks all crawlers', response.status() === 200 && wildcardBlocks,
      `HTTP ${response.status()}; ${body.trim().replaceAll('\n', ' | ')}`);
    const sitemap = await robotsResponse.request.get(`${BASE}/sitemap.xml`, { timeout: 30000 });
    const xml = await sitemap.text();
    record('seo', 'Custom sitemap.xml is valid XML response', sitemap.status() === 200 && /<\?xml|<urlset|<sitemapindex/i.test(xml),
      `HTTP ${sitemap.status}; ${xml.length} bytes`);
    record('seo', 'Sitemap excludes default Hello World post', !/\/hello-world\//i.test(xml),
      /\/hello-world\//i.test(xml) ? 'Found /hello-world/ in sitemap' : 'No default post URL found', 'warning');
    await robotsResponse.close();
  } catch (error) {
    record('seo', 'Robots and sitemap probes', false, error.message);
  }

  // XSS reflection probe: GET only; the unique global must never execute.
  const xssPage = await browser.newPage();
  try {
    const payload = encodeURIComponent('\"><script>window.__uat_xss=1</script>');
    await xssPage.goto(`${BASE}/fr/annonces/?q=${payload}`, { waitUntil: 'domcontentloaded', timeout: 30000 });
    const executed = await xssPage.evaluate(() => window.__uat_xss === 1);
    record('security', 'search query reflected XSS (GET)', !executed, executed ? 'Injected script executed' : 'No injected script execution observed');
  } catch (error) {
    record('security', 'search query reflected XSS (GET)', false, error.message);
  } finally {
    await xssPage.close();
  }
} finally {
  await browser.close();
}

const summary = {
  target: BASE,
  checked_at: new Date().toISOString(),
  viewports: WIDTHS,
  locales: LOCALES,
  axe_available: !!axeSource,
  status: findings.some(row => row.severity === 'error') ? 'FAIL' : 'PASS_WITH_FINDINGS',
  total_checks: results.length,
  failed_checks: findings.length,
  results,
};
await writeFile(path.join(OUT, 'browser-audit.json'), JSON.stringify(summary, null, 2));
console.log(JSON.stringify({ ...summary, results: undefined }, null, 2));
if (summary.status === 'FAIL') process.exitCode = 1;
