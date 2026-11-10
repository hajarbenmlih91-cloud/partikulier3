#!/usr/bin/env python3
"""Génère les variantes de test du contrat de cache (série D).

Usage : gen_php.py <theme/partikulier/tests/staging-cache.php> <répertoire de sortie>

Le fichier testé est repris tel quel ; seules trois choses changent dans la copie :
  1. `$argv` est fixé (le runtime WebAssembly n'a pas de ligne de commande) ;
  2. la garde `PHP_SAPI !== 'cli'` est neutralisée (exécution in-process) ;
  3. `get_http()` est remplacé par un stub scénarisé : le transport est simulé, la
     logique de verdict est celle du fichier réel.
"""
import pathlib
import sys

src = pathlib.Path(sys.argv[1]).read_text()
out = pathlib.Path(sys.argv[2])
out.mkdir(parents=True, exist_ok=True)

STUB = '''function get_http( string $url ): array {
        static $appels = array();
        $appels[$url] = ( $appels[$url] ?? 0 ) + 1;
        $rang = $appels[$url];
        $scenario = '__SC__';
        $ls = 'miss'; $pk = 'MISS';
        if ( strpos( $url, '/annonce/' ) !== false ) {
                $ls = 'hit';
        } else {
                switch ( $scenario ) {
                        case 'ls_hit_replay':
                                if ( $rang >= 2 ) { $ls = 'hit'; }
                                break;
                        case 'ls_header_gone':
                                if ( $rang >= 2 ) { $ls = null; }
                                break;
                        case 'ls_both_absent':
                                $ls = null;
                                break;
                        case 'php_two_render':
                                // PHP rend les deux réponses : en-tête présent et sans HIT.
                                if ( $rang >= 2 ) { $pk = 'HIT'; }
                                break;
                        case 'php_two_render_bad':
                                break;
                }
        }
        $corps = '<!doctype html><html><body>' . str_repeat( '<div class="pk-card-title">Annonce</div>', 3 )
               . '<a href="https://exemple.test/annonce/abc-123/">fiche</a>'
               . '<meta property="og:image" content="https://exemple.test/x.jpg"></body></html>';
        $entetes = array( 'x-partikulier-cache: ' . $pk );
        if ( $ls !== null ) { $entetes[] = 'x-litespeed-cache: ' . $ls; }
        return array( 'code' => 200, 'entetes' => $entetes,
                      'corps' => $corps, 'total' => 120.0, 'ttfb' => 90.0, 'errno' => 0 );
}'''

i = src.index('function get_http( string $url ): array {')
j = src.index('\n}\n', i) + 3
base = src[:i] + STUB + src[j:]
base = base.replace(
    '<?php\n',
    "<?php\n$argv = array( 'staging-cache.php', 'https://exemple.test', '/fr/annonces/', '3' );\n",
    1,
)
base = base.replace("if ( PHP_SAPI !== 'cli' ) {", "if ( false ) { // harnais : pas de SAPI cli")

for sc in ('ls_hit_replay', 'ls_header_gone', 'ls_both_absent', 'php_two_render', 'php_two_render_bad'):
    (out / f'contract_{sc}.php').write_text(base.replace('__SC__', sc))
print('contrats générés dans', out)
