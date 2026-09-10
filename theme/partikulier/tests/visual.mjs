/**
 * Test de non-regression visuelle.
 *
 *   node tests/visual.mjs baseline   -> enregistre les references
 *   node tests/visual.mjs check      -> compare et echoue si ecart > seuil
 *
 * Compare aussi un jeu d'assertions structurelles (les 9 bugs deja corriges),
 * pour qu'une refonte CSS ne puisse pas les reintroduire silencieusement.
 */
import { chromium } from 'playwright';
import { PNG } from 'pngjs';
import fs from 'fs';
import path from 'path';

const BASE = process.env.PK_BASE || 'http://localhost:8090';
const MODE = process.argv[2] || 'check';
const DIR = path.join(process.cwd(), 'tests', '__baseline__');
const SEUIL = 0.2; // % de pixels differents tolere

const PAGES = [
  ['accueil', '/'],
  ['annonces', '/annonces/'],
  ['annonces-filtre', '/annonces/?type=appartement'],
  ['deposer', '/deposer/'],
  ['mes-annonces', '/mes-annonces/'],
  ['404', '/page-inexistante-xyz/'],
];
const TAILLES = [['desktop', 1440, 1000], ['mobile', 390, 844]];

fs.mkdirSync(DIR, { recursive: true });

/** Compare deux PNG de meme taille : renvoie le % de pixels differents. */
function diff(a, b) {
  if (a.width !== b.width || a.height !== b.height) return 100;
  let n = 0;
  for (let i = 0; i < a.data.length; i += 4) {
    const d = Math.abs(a.data[i] - b.data[i])
            + Math.abs(a.data[i + 1] - b.data[i + 1])
            + Math.abs(a.data[i + 2] - b.data[i + 2]);
    if (d > 30) n++;
  }
  return (n / (a.width * a.height)) * 100;
}

/** Assertions structurelles : les regressions deja vecues. */
async function invariants(page) {
  return page.evaluate(() => {
    const px = el => el ? Math.round(el.getBoundingClientRect().width) : 0;
    const q = s => document.querySelector(s);
    const out = {};

    // Ecran servi par WordPress (login, admin) : le theme n'y est pas responsable.
    if (document.body.classList.contains('login') || document.body.classList.contains('wp-admin')) {
      return out;
    }

    // bug 8 : la marque est du texte, jamais une image
    out.logo_texte = !!q('.pk-logo-text') && document.querySelectorAll('.pk-header-brand img').length === 0;

    // bug 5 : aucun emoji dans le rendu
    out.sans_emoji = !/[\u{1F300}-\u{1FAFF}\u{2600}-\u{27BF}]/u.test(document.body.innerText);

    if (q('.pk-site-header')) {
      // bug 2 : le bouton d'action du header est visible en desktop
      const cta = q('.pk-header-cta');
      out.cta_visible = window.innerWidth < 769
        ? true
        : !!cta && getComputedStyle(cta).display !== 'none' && px(cta) > 100;

      // bug 3 : le champ de recherche n'est pas ecrase
      const inp = q('.pk-search-bar input');
      out.recherche_large = window.innerWidth < 641 ? true : px(inp) > 200;

      // bug 4 : selecteur de langue compact, pas la liste qui deborde
      out.langue_compacte = !q('.pk-language-switcher');

      // bug 7 : les entrees de menu sont espacees. Design actuel : en desktop,
      // espacement inter-entrees > 8 px ; en mobile (< 768 px), la nav devient
      // une grille compacte 3 colonnes pleine largeur (redesign sticky v1.8) —
      // l'espacement ne s'applique plus, on verifie que les entrees remplissent
      // bien la largeur (>= 80 px chacune) au lieu de l'ignorer.
      const nav = q('.pk-main-nav');
      const navVisible = nav && getComputedStyle(nav).display !== 'none';
      const it = [...document.querySelectorAll('.pk-menu-item > a')];
      out.menu_espace = (!navVisible || it.length < 2)
        ? true
        : window.innerWidth < 768
          ? it.every(a => a.getBoundingClientRect().width >= 80)
          : (it[1].getBoundingClientRect().left - it[0].getBoundingClientRect().right) > 8;
    }

    // bug 9 + 6 : cartes de role alignees a gauche, texte lisible sur fond fonce
    const role = q('.pk-role-btn');
    if (role) {
      out.role_aligne = getComputedStyle(role).alignItems === 'flex-start';
      const actif = q('.pk-role-btn:has(input:checked)');
      if (actif) {
        const t = actif.querySelector('strong');
        out.role_contraste = t && getComputedStyle(t).color !== getComputedStyle(actif).backgroundColor;
      }
    }
    return out;
  });
}

/** Rendu déterministe avant capture pleine page :
 *  - force les images différées (loading="lazy") ET déroule la page (le seuil
 *    de préchargement différé de Chromium varie selon le contexte réseau : des
 *    images à 1500-2000 px sous la ligne de flottaison partent parfois, parfois
 *    non — 5 à 10 % d'écart aléatoire constaté sur l'accueil) ;
 *  - attend le décodage de toutes les images ;
 *  - épingle le lien d'évitement hors écran (élément position:fixed que
 *    Chromium repositionne parfois à l'identité en rendu pleine page) ;
 *  - laisse 250 ms poser le rendu. */
async function figerRendu(page) {
  await page.evaluate(() => {
    for (const img of document.querySelectorAll('img[loading="lazy"]')) img.loading = 'eager';
  });
  await page.evaluate(async () => {
    for (let y = 0; y < document.body.scrollHeight; y += 700) {
      window.scrollTo(0, y);
      await new Promise(r => setTimeout(r, 50));
    }
    window.scrollTo(0, 0);
  });
  await page.waitForLoadState('networkidle');
  await page.evaluate(() => Promise.all(
    [...document.images].map(img => (img.complete && img.naturalWidth > 0) ? null :
      new Promise(r => { img.onload = img.onerror = r; }))));
  await page.evaluate(() => window.scrollTo(0, 0));
  await page.addStyleTag({ content: '.pk-skip-link { transform: translateY(-200%) !important; }' });
  await page.waitForTimeout(250);
}

const nav = await chromium.launch();
const erreurs = [];
const resultats = [];

for (const [tname, w, h] of TAILLES) {
  const page = await nav.newPage({ viewport: { width: w, height: h }, isMobile: tname === 'mobile' });
  const jsErr = [];
  page.on('pageerror', e => jsErr.push(e.message));

  for (const [pname, url] of PAGES) {
    const cle = `${pname}-${tname}`;
    // double chargement : le premier amorce les caches (serveur + mémoire du
    // navigateur), le second est l'état stable capturé.
    await page.goto(BASE + url, { waitUntil: 'networkidle' });
    const rep = await page.goto(BASE + url, { waitUntil: 'networkidle' });

    // bug 1 : la page de depot doit servir son formulaire, pas un gabarit vide
    if (pname === 'deposer') {
      const n = await page.evaluate(() => document.querySelectorAll('form input, form select, form textarea').length);
      if (n < 20) erreurs.push(`${cle} : formulaire de depot incomplet (${n} champs)`);
    }
    if (pname !== '404' && rep.status() !== 200) {
      erreurs.push(`${cle} : HTTP ${rep.status()}`);
    }

    const inv = await invariants(page);
    for (const [k, v] of Object.entries(inv)) {
      if (v === false) erreurs.push(`${cle} : invariant "${k}" rompu`);
    }

    await figerRendu(page);
    const shot = await page.screenshot({ fullPage: true });
    const ref = path.join(DIR, cle + '.png');

    if (MODE === 'baseline') {
      fs.writeFileSync(ref, shot);
      resultats.push(`  reference  ${cle}`);
    } else if (!fs.existsSync(ref)) {
      erreurs.push(`${cle} : reference absente (lancer 'baseline')`);
    } else {
      const d = diff(PNG.sync.read(fs.readFileSync(ref)), PNG.sync.read(shot));
      const ok = d <= SEUIL;
      resultats.push(`  ${ok ? 'ok  ' : 'ECART'}  ${cle}  ${d.toFixed(2)}%`);
      if (!ok) {
        fs.writeFileSync(path.join(DIR, cle + '.actuel.png'), shot);
        erreurs.push(`${cle} : ${d.toFixed(2)} % de pixels differents (seuil ${SEUIL} %)`);
      }
    }
  }
  if (jsErr.length) erreurs.push(`${tname} : ${jsErr.length} erreur(s) JS — ${jsErr[0]}`);
  await page.close();
}

await nav.close();
console.log(resultats.join('\n'));

if (erreurs.length) {
  console.log('\nECHEC :');
  erreurs.forEach(e => console.log('  - ' + e));
  process.exit(1);
}
console.log(`\nOK — ${PAGES.length * TAILLES.length} vues conformes.`);
