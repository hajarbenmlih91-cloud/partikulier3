<?php
/**
 * Peremption du cache de pages MESUREE sur la page d'accueil (Arena, 25/09).
 * Question : apres une transition DP-9, l'annonce disparait-elle (vendue) puis
 * revient-elle (reactivee) POUR LE VISITEUR ?
 */
declare(strict_types=1);
$wpDir=(string)getenv('PK_WP_DIR'); $base=rtrim((string)getenv('PK_BASE'),'/');
require $wpDir.'/wp-load.php'; require_once __DIR__.'/dp9-http.php'; require_once __DIR__.'/fixtures/dp9-fixtures.php';
$g=static function(string $u):array{$c=curl_init($u);$h=[];curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADERFUNCTION=>static function($c,$l)use(&$h):int{$p=strpos($l,':');if($p)$h[strtolower(trim(substr($l,0,$p)))]=trim(substr($l,$p+1));return strlen($l);},CURLOPT_TIMEOUT=>60]);$b=curl_exec($c);curl_close($c);return[(string)$b,$h];};
$carte=dp9_seed_fixtures(); $A3=(int)$carte['fixtures']['A3_actif']; $S=dp9_session((int)$carte['owner']);
$slug=(string)get_post_field('post_name',$A3); $mes=[];
$lire=static function(string $e) use($g,$base,$slug,&$mes,$A3){ $g($base.'/fr/'); list($b,$h)=$g($base.'/fr/');
	$mes[]=['etape'=>$e,'base'=>dp9_state($A3)[$A3]['pk']??'?','annonce_visible_accueil'=>substr_count($b,$slug),'cache'=>$h['x-litespeed-cache']??'-']; };
$lire('0 départ (actif, accueil chaud)');
dp9_ajax($base,$S,$A3,'deactivate','vendu'); $lire('1 après désactivation par le propriétaire');
dp9_ajax($base,$S,$A3,'reactivate');          $lire('2 après réactivation par le propriétaire');
$attendu_ok = (0 === $mes[1]['annonce_visible_accueil']) && ($mes[2]['annonce_visible_accueil'] > 0);
echo json_encode(['suite'=>'litespeed-accueil-peremption','date'=>gmdate('c'),
 'verdict'=>$attendu_ok?'PASS':'FAIL','slug'=>$slug,'mesures'=>$mes,
 'lecture'=>'vendue = l annonce doit disparaitre de l accueil ; reactivee = elle doit revenir'],
 JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),"\n";
