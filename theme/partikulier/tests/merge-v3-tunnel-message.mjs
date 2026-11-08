import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const htmlPath = path.join(here, '..', 'apercu-tunnel.html');

export const tunnelErrors = {
  fr: ['Choisissez Propriétaire pour continuer.', 'Choisissez vendre, louer ou la location saisonnière.'],
  en: ['Choose Owner to continue.', 'Choose sell, rent or seasonal rental.'],
  ar: ['اختر المالك للمتابعة.', 'اختر البيع أو الكراء أو الكراء اليومي.'],
};

function dictionary(html, name) {
  const marker = `var ${name} = `;
  const start = html.indexOf(marker);
  assert.ok(start >= 0, `${name} missing`);
  const brace = html.indexOf('{', start);
  let depth = 0;
  for (let i = brace; i < html.length; i++) {
    if (html[i] === '{') depth++;
    else if (html[i] === '}') {
      depth--;
      if (depth === 0) return JSON.parse(html.slice(brace, i + 1));
    }
  }
  throw new Error(`${name} object not closed`);
}

export function assertTunnelContract(html = fs.readFileSync(htmlPath, 'utf8')) {
  const handlerAt = html.indexOf('document.getElementById("next").addEventListener("click"');
  assert.ok(handlerAt >= 0, 'next handler missing');
  const handler = html.slice(handlerAt, handlerAt + 700);
  assert.ok(handler.includes(`aller(document.getElementById("role"), ${JSON.stringify(tunnelErrors.fr[0])})`), 'first tunnel message is the owner sentence');
  assert.ok(handler.includes(`aller(document.getElementById("transaction"), ${JSON.stringify(tunnelErrors.fr[1])})`), 'second tunnel message is the seasonal sentence');
  assert.equal(handler.includes('Choisissez vendre ou louer.'), false, 'page 1 no longer stops on the old sell-or-rent sentence');
  const ar = dictionary(html, 'PK_AR');
  const en = dictionary(html, 'PK_EN');
  assert.equal(ar[tunnelErrors.fr[0]], tunnelErrors.ar[0]);
  assert.equal(en[tunnelErrors.fr[0]], tunnelErrors.en[0]);
  assert.equal(ar[tunnelErrors.fr[1]], tunnelErrors.ar[1]);
  assert.equal(en[tunnelErrors.fr[1]], tunnelErrors.en[1]);
  assert.equal(ar['Choisissez vendre ou louer.'], 'اختر البيع أو الكراء.');
  assert.equal(en['Choisissez vendre ou louer.'], 'Choose sell or rent.');
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  assertTunnelContract();
  console.log('PASS merge-v3-tunnel-message');
}
