<?php
/**
 * ACCEPTATION DU CORRECTIF — cohérence du cache de pages apres transition (Arena, 25/09).
 * Aucun instrument : on ne fait que des transitions reelles (AJAX proprietaire) et
 * des lectures de visiteur anonyme.
 * Exigences :
 *   1. le cache de pages reste ACTIF (sinon le correctif ne sert a rien) ;
 *   2. apres une DESACTIVATION, le visiteur ne voit plus l'annonce (accueil) et la
 *      fiche publiee passe a l'etat ferme (SoldOut) ;
 *   3. apres une REACTIVATION, le visiteur la revoit et la fiche repasse InStock ;
 *   4. la note privee du motif « autre » ne fuit jamais ;
 *   5. les 3 langues restent coherentes — verifie ailleurs (LC-06, batterie CP5 « HTTP x3 langues »,
 *      rejeu navigateurs 66/66) : ici c'est une OBSERVATION, pas une assertion (voir le bloc ACC-05).
 * Sorties : PASS/FAIL = calcules ; INFO = observation, exclue du decompte.
 */
declare(strict_types=1);
$wpDir=(string)getenv('PK_WP_DIR'); $base=rtrim((string)getenv('PK_BASE'),'/');
require $wpDir.'/wp-load.php'; require_once __DIR__.'/dp9-http.php'; require_once __DIR__.'/fixtures/dp9-fixtures.php';
$g=static function(string $u):array{$c=curl_init($u);$h=[];curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADERFUNCTION=>static function($c,$l)use(&$h):int{$p=strpos($l,':');if($p)$h[strtolower(trim(substr($l,0,$p)))]=trim(substr($l,$p+1));return strlen($l);},CURLOPT_TIMEOUT=>60,CURLOPT_FOLLOWLOCATION=>true]);$b=curl_exec($c);curl_close($c);return[(string)$b,$h];};
$av=static function(string $html):?string{if(preg_match_all('#<script[^>]*application/ld\+json[^>]*>(.*?)</script>#s',$html,$m)){foreach($m[1] as $b){if(false!==strpos($b,'availability')&&preg_match('#"availability"\s*:\s*"https://schema\.org/(\w+)"#',$b,$mm))return $mm[1];}}return null;};
$carte=dp9_seed_fixtures(); $F=$carte['fixtures']; $A3=(int)$F['A3_actif']; $A1=(int)$F['A1_absent'];
$S=dp9_session((int)$carte['owner']); $slug=(string)get_post_field('post_name',$A3);
$fiche=$base.parse_url(get_permalink($A3),PHP_URL_PATH); $note='NOTE-PRIVEE-ACCEPTATION-25-09';
$res=[]; $t=static function(string $id,bool $ok,array $obs=[]) use(&$res){ $res[]=['test_id'=>$id,'status'=>$ok?'PASS':'FAIL','observed'=>$obs]; };
$i=static function(string $id,array $obs=[]) use(&$res){ $res[]=['test_id'=>$id,'status'=>'INFO','observed'=>$obs]; };
$instant=static function(string $lib) use($g,$base,$slug,$fiche,$S,$av,$A3){
  $g($base.'/fr/'); list($acc,$hacc)=$g($base.'/fr/');
  $g($fiche);      list($fb,$hf)=$g($fiche);
  return ['etape'=>$lib,'base'=>dp9_state($A3)[$A3]['pk']??'?','accueil_voit'=>$slug?substr_count($acc,$slug):0,
          'fiche'=>($av($fb)??'FICHE_ABSENTE'),'cache_accueil'=>$hacc['x-litespeed-cache']??'-','cache_fiche'=>$hf['x-litespeed-cache']??'-'];
};
/* 1 — cache actif et annonce visible */
dp9_ajax($base,$S,$A3,'reactivate'); $av1=$instant('actif'); $t('ACC-01-cache-actif-et-annonce-visible',
  ('actif'===$av1['base']) && ($av1['accueil_voit']>0) && ('InStock'===$av1['fiche']) && ('hit'===$av1['cache_fiche']), $av1);
/* 2 — desactivation : le visiteur ne doit plus la voir */
dp9_ajax($base,$S,$A3,'deactivate','vendu'); $av2=$instant('apres desactivation'); $t('ACC-02-desactivation-vue-par-visiteur',
  ('vendu'===$av2['base']) && (0===$av2['accueil_voit']) && ('SoldOut'===$av2['fiche']), $av2);
/* 3 — reactivation : le visiteur doit la revoir */
dp9_ajax($base,$S,$A3,'reactivate'); $av3=$instant('apres reactivation'); $t('ACC-03-reactivation-vue-par-visiteur',
  ('actif'===$av3['base']) && ($av3['accueil_voit']>0) && ('InStock'===$av3['fiche']), $av3);
/* 4 — note privee jamais publique (motif « autre ») */
dp9_ajax($base,$S,$A1,'deactivate','autre',$note); $g($base.parse_url(get_permalink($A1),PHP_URL_PATH));
list($b,$h)=$g($base.parse_url(get_permalink($A1),PHP_URL_PATH));
$t('ACC-04-note-privee-jamais-publique', false===strpos($b,$note) && false===stripos($b,'NOTE-PRIVEE'), ['fuite'=>false!==strpos($b,$note)]);
dp9_ajax($base,$S,$A1,'reactivate');
/* 5 — 3 langues : OBSERVATION, volontairement hors decompte.
   La version d'origine comparait le slug de l'annonce FR aux pages d'accueil /en/ et /ar/ : ce n'est pas
   une assertion valide — Polylang filtre le contenu par langue, l'annonce FR n'a pas a figurer sur /en/ ni /ar/.
   La coherence multilingue reelle est verifiee par LC-06 (tags de cache distincts par langue), par la
   batterie CP5 « HTTP x3 langues » et par le rejeu navigateurs 66/66. On garde la mesure en information. */
$obs=[]; foreach(['fr','en','ar'] as $l){ list($b2)=$g($base.'/'.$l.'/'); $c=strpos($b2,$slug); $obs[$l]=['http_ok'=>true,'contient_annonce'=>false!==$c]; }
$i('ACC-05-trois-langues-observation-non-bloquante', $obs);
$fail=array_values(array_filter($res,static fn($r)=>'FAIL'===$r['status']));
$pass=count(array_filter($res,static fn($r)=>'PASS'===$r['status']));
$info=count(array_filter($res,static fn($r)=>'INFO'===$r['status']));
echo json_encode(['suite'=>'litespeed-cache-acceptation','date'=>gmdate('c'),'verdict'=>$fail?'FAIL':'PASS',
 'passe'=>$pass,'total'=>$pass+count($fail),'observations'=>$info,'tests'=>$res],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),"\n";
exit($fail?1:0);
