/**
 * Contrat front-assets — SE-018 (E-1803/E-1804, campagne post-audit).
 *
 * Invariant produit verrouillé au niveau HTML (robuste aux réorganisations
 * internes d'Estatik) : les pages éditoriales ne contiennent AUCUN script
 * jQuery / jquery-migrate / select2 / jQuery-UI / datetimepicker /
 * wp-color-picker. Périmètre mesuré par l'audit (conforme au design du
 * thème) : accueil, ville, annonces, faq, contact, connexion — l'extension
 * aux pages propriétaires relève de DP-6 (option b : migration vanilla,
 * backlog SE-021).
 *
 * E-1804 : garde de compatibilité de version — toute version installée
 * hors 4.3.x fait ÉCHOUER volontairement le contrat : le dequeue doit être
 * repassé en revue avant toute montée majeure (fin de la dérive silencieuse
 * dénoncée par l'audit).
 *
 *   PK_BASE=http://127.0.0.1:8091 node tests/front-assets.mjs
 *
 * Sortie : JSON (même convention que les contrats PHP), exit 0/1.
 */
import { readFile } from 'fs/promises';

const BASE = (process.env.PK_BASE || 'http://127.0.0.1:8091').replace(/\/$/, '');

const PAGES = [
  ['accueil', '/'],
  ['ville', '/location/casablanca/'],
  ['annonces', '/annonces/'],
  ['faq', '/faq/'],
  ['contact', '/contact/'],
  ['connexion', '/connexion/'],
];

const INTERDITS = [
  ['jquery', /jquery(\.min)?\.js/i],
  ['jquery-migrate', /jquery-migrate/i],
  ['select2', /select2/i],
  ['jquery-ui', /jquery-ui/i],
  ['datetimepicker', /datetimepicker/i],
  ['wp-color-picker', /wp-color-picker/i],
];

const results = [];
const assert = (id, ok, detail = '') => {
  results.push({ test_id: id, status: ok ? 'PASS' : 'FAIL', detail });
};

const started = new Date().toISOString();

for (const [nom, chemin] of PAGES) {
  try {
    const reponse = await fetch(BASE + chemin, { redirect: 'follow' });
    const html = await reponse.text();
    const trouves = [];
    for (const [libelle, motif] of INTERDITS) {
      // Ne juger que les balises <script src=...> : l'invariant porte sur les
      // scripts CHARGÉS, pas sur d'éventuels noms dans le contenu textuel.
      const scripts = [...html.matchAll(/<script\b[^>]*\bsrc=["']([^"']+)["'][^>]*>/gi)].map((m) => m[1]);
      if (scripts.some((src) => motif.test(src))) {
        trouves.push(libelle);
      }
    }
    assert(
      'FA-' + nom.toUpperCase(),
      reponse.status === 200 && trouves.length === 0,
      `page ${chemin} : HTTP ${reponse.status}, scripts interdits : ${trouves.length === 0 ? 'aucun' : trouves.join(', ')}`
    );
  } catch (erreur) {
    assert('FA-' + nom.toUpperCase(), false, `page ${chemin} injoignable : ${erreur.message}`);
  }
}

/* E-1804 — garde de version Estatik (4.3.x attendue). La version vit dans
 * l'en-tête du fichier principal du plugin (readme.txt des canaux SVN peut
 * porter « trunk ») : PK_ESTATIK_FILE la fournit (défaut relatif au banc). */
const fichierPlugin =
  process.env.PK_ESTATIK_FILE ||
  new URL('../../../plugins/estatik/estatik.php', import.meta.url).pathname;
try {
  const contenu = await readFile(fichierPlugin, 'utf8');
  const version = (contenu.match(/^\s*\*\s*Version:\s*(.+)$/m) || contenu.match(/^Version:\s*(.+)$/m) || [])[1] || 'inconnue';
  assert('FA-VERSION-ESTATIK', /^4\.3\./.test(version.trim()),
    `Estatik installé : ${version.trim()} (4.3.x attendue — toute montée majeure impose la revue du dequeue, E-1804)`);
} catch (erreur) {
  assert('FA-VERSION-ESTATIK', false, `version Estatik illisible (${fichierPlugin}) : ${erreur.message}`);
}

const echecs = results.filter((r) => r.status !== 'PASS');
const sortie = {
  suite: 'front-assets-contract (SE-018 — campagne post-audit)',
  started_at: started,
  finished_at: new Date().toISOString(),
  command: 'node tests/front-assets.mjs',
  fixture: 'serveur de recette vivant (PK_BASE), HTML des pages éditoriales',
  results,
  status: echecs.length === 0 ? 'PASS' : 'FAIL',
  total: results.length,
  passed: results.length - echecs.length,
  failed: echecs.length,
  limitations: ['pages propriétaires hors périmètre (DP-6 option a — exception documentée, extension = option b, backlog SE-021)'],
};
console.log(JSON.stringify(sortie, null, 2));
process.exit(echecs.length === 0 ? 0 : 1);
