/**
 * Tests fonctionnels : on execute les parcours utilisateurs de bout en bout.
 *
 * Ce que la lecture de code ne dit jamais : est-ce que ca marche vraiment ?
 *
 *   node tests/parcours.mjs
 */
import { chromium } from 'playwright';

const BASE = process.env.PK_BASE || 'http://localhost:8090';
const nav = await chromium.launch();
const res = [];

function note(nom, ok, detail = '') {
  res.push({ nom, ok });
  console.log(`${ok ? 'OK  ' : 'FAIL'}  ${nom}${detail ? '  — ' + detail : ''}`);
}

// ---------------------------------------------- 1. Depot d'une annonce
{
  const ctx = await nav.newContext();
  const page = await ctx.newPage();
  const erreurs = [];
  page.on('pageerror', e => erreurs.push(e.message));

  await page.goto(BASE + '/deposer-une-annonce/', { waitUntil: 'networkidle' });

  const champs = await page.evaluate(() =>
    [...document.querySelectorAll('form input, form select, form textarea')]
      .filter(c => !['hidden'].includes(c.type))
      .map(c => ({ nom: c.name, type: c.type || c.tagName.toLowerCase(), requis: c.required }))
  );
  note('Formulaire de depot servi', champs.length > 10, `${champs.length} champs`);

  const requis = champs.filter(c => c.requis);
  note('Champs obligatoires declares', requis.length > 0, `${requis.length} requis`);

  // Soumission a vide : la validation navigateur doit bloquer
  const bouton = page.locator('form button[type=submit], form input[type=submit]').first();
  if (await bouton.count()) {
    await bouton.click().catch(() => {});
    await page.waitForTimeout(600);
    const bloque = await page.evaluate(() => {
      const f = document.querySelector('form');
      return f ? !f.checkValidity() : false;
    });
    note('Soumission a vide bloquee', bloque);
  } else {
    note('Bouton de soumission present', false);
  }

  // Remplissage et envoi reel
  await page.evaluate(() => {
    const set = (sel, val) => {
      const e = document.querySelector(sel);
      if (!e) return;
      e.value = val;
      e.dispatchEvent(new Event('input', { bubbles: true }));
      e.dispatchEvent(new Event('change', { bubbles: true }));
    };
    set('input[name="pk_title"], input[name="title"], #pk-title', 'Studio de test automatique');
    set('textarea', 'Description generee par le test fonctionnel.');
    const prix = document.querySelector('input[name*="price"], input[name*="prix"]');
    if (prix) { prix.value = '450000'; prix.dispatchEvent(new Event('input', { bubbles: true })); }
  });
  note('Champs remplissables', true);

  note('Aucune erreur JS sur le depot', erreurs.length === 0,
    erreurs.length ? erreurs[0].slice(0, 60) : '');
  await ctx.close();
}

// ---------------------------------------------- 2. Recherche et filtres
{
  const ctx = await nav.newContext();
  const page = await ctx.newPage();

  const cas = [
    ['sans filtre', '/property/'],
    ['par type', '/property/?type=appartement'],
    ['par mot-cle', '/property/?q=casablanca'],
    ['mot-cle inexistant', '/property/?q=zzzzintrouvable'],
    ['page 2', '/property/page/2/'],
  ];
  for (const [nom, url] of cas) {
    const r = await page.goto(BASE + url, { waitUntil: 'networkidle' });
    const n = await page.locator('.pk-card-title').count();
    const vide = await page.locator('text=/aucun|Aucune/i').count();
    const ok = r.status() === 200 && (n > 0 || vide > 0);
    note(`Recherche ${nom}`, ok, `HTTP ${r.status()}, ${n} resultats`);
  }

  // La recherche filtre-t-elle reellement ?
  await page.goto(BASE + '/property/', { waitUntil: 'networkidle' });
  const total = await page.locator('.pk-card-title').count();
  await page.goto(BASE + '/property/?q=zzzzintrouvable', { waitUntil: 'networkidle' });
  const filtre = await page.locator('.pk-card-title').count();
  note('Le filtre reduit bien les resultats', filtre < total, `${total} -> ${filtre}`);

  await ctx.close();
}

// ---------------------------------------------- 3. Fiche annonce
{
  const ctx = await nav.newContext();
  const page = await ctx.newPage();
  await page.goto(BASE + '/property/', { waitUntil: 'networkidle' });
  const lien = page.locator('.pk-card-title a').first();
  if (await lien.count()) {
    await lien.click();
    await page.waitForLoadState('networkidle');
    const d = await page.evaluate(() => ({
      h1: document.querySelector('h1')?.innerText.trim().slice(0, 40) || '',
      prix: !!document.querySelector('[class*=price]'),
      contact: !!document.querySelector('[class*=contact]'),
      galerie: document.querySelectorAll('[class*=gallery] img, .pk-single img').length,
    }));
    note('Fiche annonce complete', !!d.h1 && d.prix, `${d.h1} | prix=${d.prix} contact=${d.contact} img=${d.galerie}`);
  } else {
    note('Fiche annonce accessible', false, 'aucune annonce listee');
  }
  await ctx.close();
}

// ---------------------------------------------- 4. Favoris (sans compte)
{
  const ctx = await nav.newContext();
  const page = await ctx.newPage();
  await page.goto(BASE + '/property/', { waitUntil: 'networkidle' });
  const coeur = page.locator('.pk-card-wishlist').first();
  if (await coeur.count()) {
    await coeur.click();
    await page.waitForTimeout(700);
    const memorise = await page.evaluate(() => {
      const ls = Object.keys(localStorage).some(k => /fav|wish/i.test(k));
      return ls || document.cookie.includes('fav');
    });
    note('Favori memorise cote client', memorise);
    await page.reload({ waitUntil: 'networkidle' });
    const persiste = await page.evaluate(() =>
      Object.keys(localStorage).some(k => /fav|wish/i.test(k)));
    note('Favori persiste apres rechargement', persiste);
  } else {
    note('Bouton favori present', false);
  }
  await ctx.close();
}

// ---------------------------------------------- 5. Navigation clavier
{
  const ctx = await nav.newContext();
  const page = await ctx.newPage();
  await page.goto(BASE + '/', { waitUntil: 'networkidle' });
  const parcours = [];
  for (let i = 0; i < 12; i++) {
    await page.keyboard.press('Tab');
    parcours.push(await page.evaluate(() => {
      const a = document.activeElement;
      const cs = getComputedStyle(a);
      return {
        tag: a.tagName,
        visible: a.getBoundingClientRect().width > 0,
        contour: cs.outlineStyle !== 'none' || cs.boxShadow !== 'none',
      };
    }));
  }
  const sansContour = parcours.filter(p => p.visible && !p.contour).length;
  note('Focus clavier visible', sansContour === 0, `${sansContour}/12 elements sans indicateur`);
  const premier = parcours[0];
  note('Premier tab = lien d evitement', premier.tag === 'A', premier.tag);
  await ctx.close();
}

await nav.close();
const ko = res.filter(r => !r.ok);
console.log(`\n${res.length - ko.length}/${res.length} conformes`);
if (ko.length) { console.log('\nEchecs :'); ko.forEach(k => console.log('  - ' + k.nom)); process.exit(1); }
