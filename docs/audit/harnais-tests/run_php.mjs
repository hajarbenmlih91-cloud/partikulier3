// Série D — le contrat de cache exécuté par un vrai PHP (WebAssembly).
//
// Prérequis : npm install @php-wasm/node   (dans ce répertoire)
// Usage     : node run_php.mjs <répertoire contenant contract_*.php>
//
// Le transport HTTP est simulé par gen_php.py ; la logique de verdict, elle, est
// celle du fichier réellement patché.
import fs from 'node:fs';
import path from 'node:path';
import { loadNodeRuntime } from '@php-wasm/node';
import { PHP } from '@php-wasm/universal';

const dir = process.argv[2] || '.';
const id = await loadNodeRuntime('8.2', { emscriptenOptions: { processId: 1 } });
const php = new PHP(id);

const attendus = {
  ls_hit_replay: { rc: 0, re: /ATT\s+cache thème :.*NON PROBANT/, nom: 'rejoué par LiteSpeed -> ATT (code 0)' },
  // L'en-tête LiteSpeed disparaît au 2ᵉ GET : le contrôle LiteSpeed échoue (code 1, à juste titre),
  // mais le cache du thème doit être déclaré NON VÉRIFIÉ et non « rendu par PHP ».
  ls_header_gone: { rc: 1, re: /ATT\s+cache thème : échantillon NON VÉRIFIÉ/, nom: 'en-tête présent puis absent -> cache thème NON VÉRIFIÉ' },
  php_two_render: { rc: 0, re: /OK\s+cache thème : x-partikulier-cache = HIT au 2ᵉ GET \(deux réponses rendues par PHP\)/, nom: 'deux réponses PHP, 2ᵉ HIT -> OK' },
  php_two_render_bad: { rc: 1, re: /KO\s+cache thème : x-partikulier-cache = MISS/, nom: 'deux réponses PHP, 2ᵉ MISS -> KO (code 1)' },
};

let echecs = 0;
for (const [sc, attendu] of Object.entries(attendus)) {
  const file = path.join(dir, `contract_${sc}.php`);
  if (!fs.existsSync(file)) {
    console.log(`  FAIL  ${sc} : ${file} absent`);
    echecs++;
    continue;
  }
  let r;
  try {
    r = await php.run({ code: fs.readFileSync(file, 'utf8') });
  } catch (e) {
    r = e.response
      ? { text: Buffer.from(e.response.bytes).toString('utf8'), exitCode: e.response.exitCode }
      : { text: String(e), exitCode: -1 };
  }
  const okRc = r.exitCode === attendu.rc;
  const okRe = attendu.re.test(r.text || '');
  if (okRc && okRe) {
    console.log(`  PASS  ${sc} : ${attendu.nom}`);
  } else {
    echecs++;
    console.log(`  FAIL  ${sc} : exit attendu ${attendu.rc}, obtenu ${r.exitCode} ; motif ${okRe ? 'ok' : 'absent'}`);
  }
}
console.log(`\n=== SÉRIE D : ${Object.keys(attendus).length - echecs} PASS · ${echecs} FAIL ===`);
process.exit(echecs ? 1 : 0);
