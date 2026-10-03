/**
 * Audit de pre-cahier des charges : mesure ce qui sert a decider.
 *
 *   node tests/audit.mjs            audit complet, sortie JSON
 *
 * Ne juge rien : releve des faits (poids, requetes, accessibilite, SEO,
 * Core Web Vitals approximes). Les seuils d'acceptation se decident ensuite.
 */
import { chromium } from 'playwright';

const BASE = process.env.PK_BASE || 'http://localhost:8090';

const PAGES = [
  ['accueil', '/'],
  ['annonces', '/property/'],
  ['deposer', '/deposer-une-annonce/'],
];

const nav = await chromium.launch();
const rapport = {};

for (const [nom, url] of PAGES) {
  const ctx = await nav.newContext({ viewport: { width: 1440, height: 1000 } });
  const page = await ctx.newPage();

  const requetes = [];
  const erreurs = [];
  page.on('response', async r => {
    try {
      const h = r.headers();
      requetes.push({
        url: r.url(),
        type: r.request().resourceType(),
        statut: r.status(),
        taille: parseInt(h['content-length'] || '0', 10),
      });
    } catch (e) { /* ignore */ }
  });
  page.on('pageerror', e => erreurs.push(e.message));
  page.on('console', m => { if (m.type() === 'error') erreurs.push('console: ' + m.text().slice(0, 120)); });

  const t0 = Date.now();
  await page.goto(BASE + url, { waitUntil: 'networkidle' });
  const chargement = Date.now() - t0;

  // --- Poids par type ---
  const parType = {};
  for (const r of requetes) {
    const t = r.type;
    parType[t] = parType[t] || { n: 0, ko: 0 };
    parType[t].n++;
    parType[t].ko += Math.round(r.taille / 1024);
  }

  // --- Metriques de rendu ---
  const perf = await page.evaluate(() => {
    const nav0 = performance.getEntriesByType('navigation')[0] || {};
    const paints = {};
    for (const p of performance.getEntriesByType('paint')) paints[p.name] = Math.round(p.startTime);
    return {
      dom_interactif: Math.round(nav0.domInteractive || 0),
      dom_complet: Math.round(nav0.domComplete || 0),
      premier_rendu: paints['first-contentful-paint'] || null,
      noeuds_dom: document.getElementsByTagName('*').length,
      profondeur_max: (() => {
        let max = 0;
        const walk = (el, d) => { if (d > max) max = d; for (const c of el.children) walk(c, d + 1); };
        walk(document.body, 0);
        return max;
      })(),
    };
  });

  // --- Accessibilite : controles mesurables sans dependance externe ---
  const a11y = await page.evaluate(() => {
    const imgs = [...document.images];
    const liens = [...document.querySelectorAll('a')];
    const champs = [...document.querySelectorAll('input, select, textarea')]
      .filter(c => !['hidden', 'submit', 'button'].includes(c.type));
    const titres = [...document.querySelectorAll('h1,h2,h3,h4,h5,h6')].map(h => +h.tagName[1]);

    // hierarchie de titres : un saut de plus de 1 niveau
    let sauts = 0;
    for (let i = 1; i < titres.length; i++) if (titres[i] - titres[i - 1] > 1) sauts++;

    const sansLabel = champs.filter(c => {
      if (c.getAttribute('aria-label') || c.getAttribute('aria-labelledby')) return false;
      if (c.id && document.querySelector(`label[for="${CSS.escape(c.id)}"]`)) return false;
      return !c.closest('label');
    });

    return {
      images: imgs.length,
      images_sans_alt: imgs.filter(i => !i.hasAttribute('alt')).length,
      liens: liens.length,
      liens_sans_intitule: liens.filter(a =>
        !a.textContent.trim() && !a.getAttribute('aria-label') && !a.querySelector('img[alt]:not([alt=""])')
      ).length,
      champs: champs.length,
      champs_sans_label: sansLabel.length,
      h1: document.querySelectorAll('h1').length,
      sauts_de_titre: sauts,
      lang_html: document.documentElement.lang || null,
      lien_evitement: !!document.querySelector('.pk-skip-link, .skip-link, [href="#content"], [href="#main"]'),
      boutons_sans_intitule: [...document.querySelectorAll('button')].filter(b =>
        !b.textContent.trim() && !b.getAttribute('aria-label')
      ).length,
    };
  });

  // --- SEO / donnees structurees ---
  const seo = await page.evaluate(() => {
    const meta = n => document.querySelector(`meta[name="${n}"]`)?.content || null;
    const og = n => document.querySelector(`meta[property="og:${n}"]`)?.content || null;
    let jsonld = [];
    for (const s of document.querySelectorAll('script[type="application/ld+json"]')) {
      try {
        const d = JSON.parse(s.textContent);
        jsonld = jsonld.concat(Array.isArray(d) ? d : [d]);
      } catch (e) { jsonld.push({ ERREUR: 'JSON invalide' }); }
    }
    return {
      title: document.title,
      title_longueur: document.title.length,
      description: meta('description'),
      description_longueur: (meta('description') || '').length,
      canonical: document.querySelector('link[rel="canonical"]')?.href || null,
      og_title: og('title'),
      og_image: og('image'),
      jsonld_types: jsonld.map(d => d['@type'] || d['@graph']?.map(g => g['@type']).join('+') || '?'),
      jsonld_invalide: jsonld.some(d => d.ERREUR),
      hreflang: document.querySelectorAll('link[rel="alternate"][hreflang]').length,
    };
  });

  // --- Zones cliquables trop petites (norme : 24x24 minimum) ---
  const tactile = await page.evaluate(() => {
    const cibles = [...document.querySelectorAll('a, button, input[type=submit], [role=button]')];
    const petites = cibles.filter(e => {
      const r = e.getBoundingClientRect();
      return r.width > 0 && r.height > 0 && (r.width < 24 || r.height < 24);
    });
    return { total: cibles.length, trop_petites: petites.length };
  });

  rapport[nom] = {
    chargement_ms: chargement,
    requetes: requetes.length,
    poids_total_ko: Math.round(requetes.reduce((s, r) => s + r.taille, 0) / 1024),
    par_type: parType,
    erreurs_404: requetes.filter(r => r.statut >= 400).map(r => r.url.split('/').pop()),
    perf,
    a11y,
    seo,
    tactile,
    erreurs_js: erreurs.length,
    detail_erreurs: erreurs.slice(0, 3),
  };

  await ctx.close();
}

await nav.close();
console.log(JSON.stringify(rapport, null, 1));
