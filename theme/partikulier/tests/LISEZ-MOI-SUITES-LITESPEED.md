# Suites de mesure du cache de pages — mode d'emploi

Ces cinq suites **exigent un serveur LiteSpeed** (elles lisent l'en-tête `X-LiteSpeed-Cache` et la purge
de l'hébergeur). **Ne pas les brancher dans la CI GitHub** : elle tourne sur `php -S`, où la purge hôte est
délibérément inactive (partie 5 du correctif) — les suites y échoueraient pour une mauvaise raison.
La liste des suites exécutées par `.github/workflows/contrats-recette.yml` est explicite : n'y rien ajouter.

| Suite | Ce qu'elle vérifie |
|---|---|
| `litespeed-cache-acceptation.php` | ACC-01…04 : purge réellement honorée, les deux sens (désactivation / réactivation), note privée jamais publique. **ACC-05 = observation** (`INFO`, hors décompte) |
| `litespeed-cache-coherence.php` | LC-01…07 : cohérence entre ce que voit l'anonyme, le propriétaire et l'admin (dont LC-06 : tags de cache distincts par langue) |
| `litespeed-cache-isolation.php` | ISO-01…06 : un espace connecté n'est jamais servi depuis le cache public |
| `litespeed-accueil-peremption.php` | la page d'accueil suit l'état de l'annonce (les deux sens) |
| `litespeed-peremption-reactivation.php` | une annonce vendue puis réactivée réapparaît correctement |

Conventions de sortie : `PASS`/`FAIL` sont **calculés** ; `INFO` = observation non bloquante, exclue du décompte
(le résumé JSON expose `passe`, `total` et `observations` séparément).

Exécution (exemple, port et hôte à adapter) : `PK_BASE=http://127.0.0.1:8096 php litespeed-cache-acceptation.php`
— chaque suite écrit un JSON et un code de sortie 0/1.
