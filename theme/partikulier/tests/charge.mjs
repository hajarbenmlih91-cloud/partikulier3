/**
 * Test de montee en charge : le site tient-il avec du volume reel ?
 *
 * Mesure temps de reponse, nombre de requetes SQL et memoire sur les pages
 * critiques, puis la tenue sous requetes concurrentes.
 *
 *   node tests/charge.mjs
 */
import { chromium } from 'playwright';

const BASE = process.env.PK_BASE || 'http://localhost:8090';

const PAGES = [
  ['accueil', '/'],
  ['archive p1', '/property/'],
  ['archive p2', '/property/page/2/'],
  ['filtre type', '/property/?es_type=appartement'],
  ['filtre prix', '/property/?es_price_max=300000'],
];

const nav = await chromium.launch();
const page = await nav.newPage({ viewport: { width: 1440, height: 1000 } });

console.log('page'.padEnd(16), 'ms'.padStart(6), 'SQL'.padStart(5), 'mem Mo'.padStart(8), 'cartes'.padStart(7));

const lignes = [];
for (const [nom, url] of PAGES) {
  // trois mesures, on garde la mediane
  const temps = [];
  let sql = null, mem = null, cartes = 0;
  for (let i = 0; i < 3; i++) {
    const t0 = Date.now();
    await page.goto(BASE + url + (url.includes('?') ? '&' : '?') + 'pk_perf=1&b=' + Math.random(),
      { waitUntil: 'domcontentloaded' });
    temps.push(Date.now() - t0);
    if (i === 2) {
      const html = await page.content();
      const m = html.match(/PKPERF sql=(\d+) mem=([\d.]+) time=([\d.]+)/);
      if (m) { sql = +m[1]; mem = +m[2]; }
      cartes = await page.locator('.pk-card-title').count();
    }
  }
  temps.sort((a, b) => a - b);
  const median = temps[1];
  lignes.push({ nom, median, sql, mem, cartes });
  console.log(
    nom.padEnd(16),
    String(median).padStart(6),
    String(sql ?? '-').padStart(5),
    String(mem ?? '-').padStart(8),
    String(cartes).padStart(7)
  );
}

// --- Concurrence : 10 requetes simultanees sur l'archive ---
console.log('\n--- 10 requetes simultanees sur /property/ ---');
const t0 = Date.now();
const codes = await Promise.all(
  Array.from({ length: 10 }, (_, i) =>
    page.evaluate(async (n) => {
      const r = await fetch('/property/?conc=' + n, { credentials: 'same-origin' });
      return r.status;
    }, i)
  )
);
const duree = Date.now() - t0;
const ok = codes.filter(c => c === 200).length;
console.log(`${ok}/10 en HTTP 200, total ${duree} ms (${Math.round(duree / 10)} ms/requete)`);

await nav.close();

// --- Verdict ---
console.log('\n--- lecture ---');
const lent = lignes.filter(l => l.median > 1500);
const sqlLourd = lignes.filter(l => l.sql && l.sql > 100);
if (lent.length) console.log('Pages > 1,5 s : ' + lent.map(l => `${l.nom} (${l.median} ms)`).join(', '));
else console.log('Aucune page au-dessus de 1,5 s.');
if (sqlLourd.length) console.log('Requetes SQL > 100 : ' + sqlLourd.map(l => `${l.nom} (${l.sql})`).join(', '));
else console.log('Aucune page au-dessus de 100 requetes SQL.');
