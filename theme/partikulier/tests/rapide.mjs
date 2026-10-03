/**
 * Verification rapide : 4 vues seulement, pour trier un grand nombre de
 * candidats sans payer le cout du harnais complet. La validation finale
 * reste tests/visual.mjs.
 *
 * Sortie : code 0 si conforme, 1 sinon.
 */
import { chromium } from 'playwright';
import { PNG } from 'pngjs';
import fs from 'fs';
import path from 'path';

const BASE = process.env.PK_BASE || 'http://localhost:8090';
const MODE = process.argv[2] || 'check';
const DIR = path.join(process.cwd(), 'tests', '__screens__', 'rapide');
const SEUIL = 0.1;

const VUES = [
  ['accueil', '/', 1440, 1000],
  ['deposer', '/deposer/', 1440, 1000],
  ['annonces', '/annonces/', 1440, 1000],
  ['accueil-m', '/', 390, 844],
];

fs.mkdirSync(DIR, { recursive: true });

function diff(a, b) {
  if (a.width !== b.width || a.height !== b.height) return 100;
  let n = 0;
  for (let i = 0; i < a.data.length; i += 4) {
    if (Math.abs(a.data[i] - b.data[i])
      + Math.abs(a.data[i + 1] - b.data[i + 1])
      + Math.abs(a.data[i + 2] - b.data[i + 2]) > 30) n++;
  }
  return (n / (a.width * a.height)) * 100;
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
let ko = 0;
const lignes = [];

for (const [nom, url, w, h] of VUES) {
  const page = await nav.newPage({ viewport: { width: w, height: h }, isMobile: h === 844 });
  // double chargement : le premier amorce les caches (serveur + mémoire du
  // navigateur), le second est l'état stable capturé — élimine la variance du
  // premier passage constatée sur /deposer/ et la vue mobile.
  await page.goto(BASE + url, { waitUntil: 'networkidle' });
  await page.goto(BASE + url, { waitUntil: 'networkidle' });
  await figerRendu(page);
  const shot = await page.screenshot({ fullPage: true });
  const ref = path.join(DIR, nom + '.png');

  if (MODE === 'baseline') {
    fs.writeFileSync(ref, shot);
  } else if (!fs.existsSync(ref)) {
    lignes.push(`${nom}: reference absente`); ko++;
  } else {
    const d = diff(PNG.sync.read(fs.readFileSync(ref)), PNG.sync.read(shot));
    if (d > SEUIL) { lignes.push(`${nom}: ${d.toFixed(2)}%`); ko++; }
  }
  await page.close();
}

await nav.close();
if (MODE === 'baseline') { console.log('references rapides enregistrees'); process.exit(0); }
if (ko) { console.log('ECART ' + lignes.join(' | ')); process.exit(1); }
console.log('ok');
