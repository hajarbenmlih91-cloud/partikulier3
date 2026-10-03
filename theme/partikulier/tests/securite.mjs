/**
 * Tests dynamiques de securite : on ATTAQUE le site, on ne lit pas le code.
 *
 * Scenarios (IDOR, nonce, elevation de privileges, injection) executes avec
 * de vraies sessions authentifiees. Chaque test attend un refus.
 *
 *   node tests/securite.mjs
 */
import { chromium } from 'playwright';
import fs from 'fs';

const BASE = process.env.PK_BASE || 'http://localhost:8090';
const [MARC_ID, SOFIA_ID, POST_MARC, POST_SOFIA] =
  fs.readFileSync('/tmp/ids.txt', 'utf8').trim().split(/\s+/).map(Number);

const nav = await chromium.launch();
const resultats = [];

function verdict(nom, attendu, obtenu, detail = '') {
  const ok = attendu === obtenu;
  resultats.push({ nom, attendu, obtenu, ok, detail });
  console.log(`${ok ? 'OK  ' : 'FAIL'}  ${nom}  (attendu ${attendu}, obtenu ${obtenu}) ${detail}`);
  return ok;
}

async function connexion(user, pass) {
  const ctx = await nav.newContext();
  const page = await ctx.newPage();
  await page.goto(BASE + '/wp-login.php', { waitUntil: 'networkidle' });
  await page.fill('#user_login', user);
  await page.fill('#user_pass', pass);
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), page.click('#wp-submit')]);
  const connecte = !(await page.locator('#user_login').count());
  return { ctx, page, connecte };
}

/** Recupere le nonce expose par wp_localize_script (cle manageNonce). */
async function nonceDe(page) {
  const html = await page.content();
  const m = html.match(/"manageNonce"\s*:\s*"([a-z0-9]+)"/i);
  return m ? m[1] : null;
}

async function ajax(page, params) {
  return page.evaluate(async (p) => {
    const body = new URLSearchParams(p);
    const r = await fetch('/wp-admin/admin-ajax.php', {
      method: 'POST',
      body,
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    });
    let j = null;
    try { j = await r.json(); } catch (e) { /* reponse non JSON */ }
    return { statut: r.status, corps: j };
  }, params);
}

// ---------------------------------------------------------------- Marc
const marc = await connexion('marc', 'marc123');
console.log(`\n=== session marc : ${marc.connecte ? 'connecte' : 'ECHEC'} ===`);

await marc.page.goto(BASE + '/mes-annonces/', { waitUntil: 'networkidle' });
const nonce = await nonceDe(marc.page);
console.log(`nonce recupere : ${nonce ? nonce.slice(0, 6) + '...' : 'AUCUN'}\n`);

// 1. IDOR : Marc supprime l'annonce de Sofia (avec un nonce valide)
{
  const r = await ajax(marc.page, {
    action: 'pk_manage_listing', nonce: nonce || '', post_id: POST_SOFIA, manage_action: 'delete',
  });
  verdict('IDOR suppression annonce d autrui', 403, r.statut,
    r.corps?.data?.message ? '- ' + r.corps.data.message.slice(0, 48) : '');
}

// 2. IDOR : Marc met en pause l'annonce de Sofia
{
  const r = await ajax(marc.page, {
    action: 'pk_manage_listing', nonce: nonce || '', post_id: POST_SOFIA, manage_action: 'pause',
  });
  verdict('IDOR modification annonce d autrui', 403, r.statut);
}

// 3. Nonce invalide sur sa propre annonce
{
  const r = await ajax(marc.page, {
    action: 'pk_manage_listing', nonce: 'faux0000', post_id: POST_MARC, manage_action: 'pause',
  });
  verdict('Nonce invalide refuse', 403, r.statut);
}

// 4. Action non prevue dans la liste blanche
{
  const r = await ajax(marc.page, {
    action: 'pk_manage_listing', nonce: nonce || '', post_id: POST_MARC, manage_action: 'drop_database',
  });
  verdict('Action hors liste blanche refusee', 400, r.statut);
}

// 5. Action legitime sur sa propre annonce : doit REUSSIR
{
  const r = await ajax(marc.page, {
    action: 'pk_manage_listing', nonce: nonce || '', post_id: POST_MARC, manage_action: 'pause',
  });
  verdict('Action legitime sur sa propre annonce', 200, r.statut);
}

// 6. Elevation : un author accede-t-il aux ecrans d administration ?
{
  const r = await marc.page.goto(BASE + '/wp-admin/users.php', { waitUntil: 'domcontentloaded' });
  const refuse = r.status() === 403 || (await marc.page.content()).includes('Vous n’avez pas');
  verdict('Acces admin refuse a un author', true, refuse);
}

// 7. Octroi de premium reserve a l administrateur
{
  const r = await marc.page.evaluate(async (pid) => {
    const b = new URLSearchParams({ action: 'pk_grant_premium', post_id: pid, _wpnonce: 'x' });
    const res = await fetch('/wp-admin/admin-post.php', { method: 'POST', body: b, credentials: 'same-origin' });
    return res.status;
  }, POST_MARC);
  verdict('Octroi premium refuse a un non-admin', true, r === 403 || r === 400 || r === 302, `statut ${r}`);
}

await marc.ctx.close();

// ---------------------------------------------------------------- Anonyme
const ctxA = await nav.newContext();
const anon = await ctxA.newPage();
await anon.goto(BASE + '/', { waitUntil: 'networkidle' });

// 8. Anonyme tentant de gerer une annonce
{
  const r = await ajax(anon, {
    action: 'pk_manage_listing', nonce: 'x', post_id: POST_MARC, manage_action: 'delete',
  });
  // 400 = nonce rejete avant meme le controle de session : refus valide.
  verdict('Anonyme ne peut pas gerer une annonce', true, r.statut >= 400, `statut ${r.statut}`);
}

// 9. Depot d annonce en anonyme : le handler nopriv existe, verifions le garde-fou
{
  const r = await ajax(anon, { action: 'pk_submit_listing', nonce: 'x', title: 'Squat' });
  verdict('Depot anonyme sans nonce refuse', true, r.statut >= 400, `statut ${r.statut}`);
}

// 10. Injection SQL dans la recherche
{
  const charge = "' OR 1=1 -- ";
  const r = await anon.goto(BASE + '/property/?q=' + encodeURIComponent(charge), { waitUntil: 'networkidle' });
  const html = await anon.content();
  const erreurSql = /SQL syntax|mysqli|wpdb|Warning: /i.test(html);
  verdict('Injection SQL dans la recherche', false, erreurSql, `HTTP ${r.status()}`);
}

// 11. XSS reflechi dans la recherche
{
  const charge = '"><script>window.__xss=1</script>';
  await anon.goto(BASE + '/property/?q=' + encodeURIComponent(charge), { waitUntil: 'networkidle' });
  const execute = await anon.evaluate(() => window.__xss === 1);
  verdict('XSS reflechi dans la recherche', false, execute);
}

// 12. Enumeration des utilisateurs par ?author=
{
  const r = await anon.goto(BASE + '/?author=' + MARC_ID, { waitUntil: 'domcontentloaded' });
  const url = anon.url();
  const fuite = /\/author\/[a-z]/i.test(url);
  verdict('Enumeration des utilisateurs', false, fuite, fuite ? url : `HTTP ${r.status()}`);
}

// 13. Fichiers sensibles accessibles
for (const f of ['/wp-config.php', '/wp-content/debug.log', '/wp-content/themes/partikulier/package.json']) {
  const r = await anon.goto(BASE + f, { waitUntil: 'domcontentloaded' }).catch(() => null);
  const txt = r ? (await anon.content()).slice(0, 400) : '';
  const expose = r && r.status() === 200 && (txt.includes('DB_NAME') || txt.includes('PHP Fatal') || txt.includes('devDependencies'));
  verdict(`Fichier protege ${f}`, false, !!expose, `HTTP ${r ? r.status() : '-'}`);
}

await ctxA.close();
await nav.close();

const echecs = resultats.filter(r => !r.ok);
console.log(`\n${resultats.length - echecs.length}/${resultats.length} tests conformes`);
if (echecs.length) {
  console.log('\nA CORRIGER :');
  echecs.forEach(e => console.log(`  - ${e.nom} : attendu ${e.attendu}, obtenu ${e.obtenu} ${e.detail}`));
  process.exit(1);
}
