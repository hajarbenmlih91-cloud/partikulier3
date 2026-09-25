<?php
/**
 * Peremption, sens INVERSE (Arena, 25/09) : une annonce que le proprietaire
 * VIENT de reactiver redevient-elle visible pour les visiteurs ?
 * (cache froid au depart ; instrument de mesure = mu-plugin de sonde, hors lot)
 */
declare(strict_types=1);
$wpDir=(string)getenv('PK_WP_DIR'); $base=rtrim((string)getenv('PK_BASE'),'/');
require $wpDir.'/wp-load.php'; require_once __DIR__.'/dp9-http.php'; require_once __DIR__.'/fixtures/dp9-fixtures.php';
$g=static function(string $u):array{$c=curl_init($u);$h=[];curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADERFUNCTION=>static function($c,$l)use(&$h):int{$p=strpos($l,':');if($p)$h[strtolower(trim(substr($l,0,$p)))]=trim(substr($l,$p+1));return strlen($l);},CURLOPT_TIMEOUT=>60]);$b=curl_exec($c);curl_close($c);return[(string)$b,$h];};
$carte=dp9_seed_fixtures(); $A3=(int)$carte['fixtures']['A3_actif']; $S=dp9_session((int)$carte['owner']);
$slug=(string)get_post_field('post_name',$A3); $mes=[];
$lire=static function(string $e) use($g,$base,$slug,&$mes,$A3){ $g($base.'/fr/'); list($b,$h)=$g($base.'/fr/');
	$mes[]=['etape'=>$e,'base'=>dp9_state($A3)[$A3]['pk']??'?','annonce_visible_accueil'=>substr_count($b,$slug),'cache'=>$h['x-litespeed-cache']??'-']; };
/* Ordre deterministe : on rend la copie « vendue » a l'accueil AVANT de reactiver. */
$g($base.'/?pk_ls_sonde=entete&t='.random_int(1,999999)); // cache froid (instrument)
dp9_ajax($base,$S,$A3,'deactivate','vendu'); $lire('0 après désactivation (le visiteur ne doit plus la voir)');
dp9_ajax($base,$S,$A3,'reactivate');          $lire('1 après réactivation (le visiteur doit la revoir)');
$ok = (0 === $mes[0]['annonce_visible_accueil']) && ($mes[1]['annonce_visible_accueil'] > 0);
echo json_encode(['suite'=>'litespeed-peremption-reactivation','date'=>gmdate('c'),
 'verdict'=>$ok?'PASS':'FAIL','slug'=>$slug,'mesures'=>$mes,
 'lecture'=>'étape 0 : annonce vendue → invisible ; étape 1 : annonce réactivée → doit redevenir visible'],
 JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),"\n";
