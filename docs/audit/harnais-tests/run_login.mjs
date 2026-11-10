// Série E — tests du parcours connecté (audit-uat-login.mjs) sans réseau ni UAT.
//
// Usage : node run_login.mjs <chemin/vers/theme/partikulier/tests/audit-uat-login.mjs>
//
// Le fichier testé est exécuté tel quel via un hook ESM Node (`node:module` register)
// qui remplace uniquement l'import `'playwright'` par un navigateur simulé scénarisé.
// L'évaluation DOM (`page.evaluate`) exécute réellement les sélecteurs et assertions de
// `audit-uat-login.mjs`.
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const TARGET = path.resolve(process.argv[2] || '');
if (!TARGET || !fs.existsSync(TARGET)) {
  console.error('Usage: node run_login.mjs <chemin/vers/audit-uat-login.mjs>');
  process.exit(2);
}

const REGISTER_URL = pathToFileURL(path.join(HERE, 'playwright_mock_register.mjs')).href;
const CANARY_COOKIE_VALUE = 'CANARY_COOKIE_SECRET_VALUE_987654';

function runScenario(scenario) {
  const outDir = fs.mkdtempSync(path.join(os.tmpdir(), `serie-e-${scenario}-`));
  const traceFile = path.join(outDir, 'cookie-trace.json');
  fs.writeFileSync(traceFile, JSON.stringify({ cookie_value_read: false, accessed_keys: [] }));

  const res = spawnSync(
    process.execPath,
    ['--import', REGISTER_URL, TARGET],
    {
      encoding: 'utf8',
      env: {
        ...process.env,
        PK_BASE: 'https://uat.example.test',
        AUDIT_LOGIN_EMAIL: 'owner@example.invalid',
        AUDIT_LOGIN_PASSWORD: 'test-password-only',
        AUDIT_OUT_DIR: outDir,
        SERIE_E_SCENARIO: scenario,
        SERIE_E_COOKIE_TRACE: traceFile,
      },
    },
  );

  const reportPath = path.join(outDir, 'owner-journey.json');
  const reportText = fs.existsSync(reportPath) ? fs.readFileSync(reportPath, 'utf8') : '';
  const report = reportText ? JSON.parse(reportText) : null;
  const cookieTrace = JSON.parse(fs.readFileSync(traceFile, 'utf8'));
  fs.rmSync(outDir, { recursive: true, force: true });

  return {
    rc: res.status ?? -1,
    stdout: res.stdout || '',
    stderr: res.stderr || '',
    reportText,
    report,
    cookieTrace,
  };
}

let pass = 0;
let fail = 0;
function verify(id, label, ok, detail = '') {
  if (ok) {
    pass++;
    console.log(`  PASS  ${id} : ${label}`);
  } else {
    fail++;
    console.log(`  FAIL  ${id} : ${label}${detail ? ` — ${detail}` : ''}`);
  }
}

console.log('=== E. Parcours connecté et consentement (audit-uat-login.mjs réel) ===');

// E1: URL avec ?redirect_url -> préfixe /mes-annonces accepté
{
  const r = runScenario('e1_redirect_url');
  const ok = r.rc === 0
    && r.report?.status === 'PASS'
    && r.report?.journeys?.length === 4
    && r.report.journeys.every(j => j.status === 'PASS' && /\?redirect_url=/.test(j.url || ''));
  verify('E1', 'URL avec ?redirect_url -> préfixe /mes-annonces accepté', ok, `rc=${r.rc} status=${r.report?.status}`);
}

// E2: URL sans paramètre -> préfixe accepté
{
  const r = runScenario('e2_no_params');
  const ok = r.rc === 0
    && r.report?.status === 'PASS'
    && r.report?.journeys?.length === 4
    && r.report.journeys.every(j => j.status === 'PASS' && /\/mes-annonces$/.test(j.url || ''));
  verify('E2', 'URL sans paramètre (/mes-annonces) -> préfixe accepté', ok, `rc=${r.rc} status=${r.report?.status}`);
}

// E3: .pk-dashboard absent -> le parcours échoue explicitement
{
  const r = runScenario('e3_dashboard_absent');
  const explicitFail = (r.report?.checks || []).some(
    c => c.status === 'FAIL'
      && /explicit dashboard elements/.test(c.name)
      && /dashboard=false/.test(c.detail),
  );
  const ok = r.rc !== 0
    && r.report?.status === 'FAIL'
    && r.report?.journeys?.length === 4
    && r.report.journeys.every(j => j.status === 'FAIL' && j.dashboardVisible === false)
    && explicitFail;
  verify('E3', '.pk-dashboard absent -> le parcours échoue explicitement', ok, `rc=${r.rc} status=${r.report?.status}`);
}

// E4: bandeau absent -> le parcours échoue explicitement
{
  const r = runScenario('e4_banner_absent');
  const explicitFail = (r.report?.checks || []).some(
    c => c.status === 'FAIL' && /fresh context displays the consent banner/.test(c.name),
  );
  const errLogged = (r.report?.journeys || []).every(
    j => j.status === 'FAIL' && /Consent banner absent/.test(j.error || ''),
  );
  const ok = r.rc !== 0
    && r.report?.status === 'FAIL'
    && r.report?.journeys?.length === 4
    && explicitFail
    && errLogged;
  verify('E4', 'bandeau absent -> le parcours échoue explicitement', ok, `rc=${r.rc} status=${r.report?.status}`);
}

// E5: .pk-dashboard-empty présent -> tableau de bord validé (et absent -> échec)
{
  const pos = runScenario('e5_empty_present');
  const neg = runScenario('e5_empty_absent');
  const posOk = pos.rc === 0
    && pos.report?.status === 'PASS'
    && pos.report?.journeys?.length === 4
    && pos.report.journeys.every(j => j.status === 'PASS' && j.emptyDashboardVisible === true);
  const negOk = neg.rc !== 0
    && neg.report?.status === 'FAIL'
    && neg.report?.journeys?.length === 4
    && neg.report.journeys.every(j => j.status === 'FAIL' && j.emptyDashboardVisible === false);
  verify('E5', '.pk-dashboard-empty présent -> tableau de bord validé (et absent refusé)', posOk && negOk,
    `pos=${pos.rc}/${pos.report?.status} neg=${neg.rc}/${neg.report?.status}`);
}

// E6: cookie de session nommé mais valeur non lue
{
  const r = runScenario('e6_cookie_name_only');
  const namedInChecks = (r.report?.checks || []).some(
    c => c.status === 'PASS'
      && /sets a WordPress session/.test(c.name)
      && /session cookie name present/.test(c.detail),
  );
  const noSecretLeak = !r.reportText.includes(CANARY_COOKIE_VALUE)
    && !r.stdout.includes(CANARY_COOKIE_VALUE)
    && !r.stderr.includes(CANARY_COOKIE_VALUE);
  const ok = r.rc === 0
    && r.report?.status === 'PASS'
    && namedInChecks
    && r.cookieTrace.cookie_value_read === false
    && r.cookieTrace.accessed_keys.includes('name')
    && noSecretLeak;
  verify('E6', 'cookie de session nommé mais valeur non lue', ok,
    `read=${r.cookieTrace.cookie_value_read} keys=${r.cookieTrace.accessed_keys.join(',')}`);
}

console.log(`\n=== SÉRIE E : ${pass} PASS · ${fail} FAIL ===`);
process.exit(fail ? 1 : 0);
