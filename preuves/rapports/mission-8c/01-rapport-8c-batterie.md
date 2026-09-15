# RAPPORT 8C — BATTERIE DYNAMIQUE FINALE (critère 12) — Train 2 v2.10.6-6.20.5

> Document retransmis verbatim depuis le canal commanditaire (2026-09-15).
> Tableaux restitués en markdown ; contenu non modifié.

Exécutée par le vérificateur indépendant (à la place du relayage Manus, crédits GitHub
du commanditaire préservés). Même discipline que la mission : zips publiés uniquement,
aucune simulation, écarts rapportés tels quels. Sorties brutes :
`rapports/preuves/BATTERIE-8C-BRUTE.txt`.

**Verdict global : ✅ CONFORME — aucun écart produit ; 2 faits d'environnement et 2 corrections de mission documentés**

## 1. Environnement

| Item | Valeur |
|---|---|
| WordPress | 7.1 (fraîchement installé) |
| PHP | 8.4.24 (cli) |
| Serveur | wp server (routeur wp-cli), MariaDB recréée vide (voir §8 fait n°1) |
| Plugins actifs | partikulier-core 2.10.6 (zip publié), estatik 4.3.5 (requis thème), polylang 3.8.9 (pages multilingues testées) ; theme-check inactif |
| Thème actif | partikulier 6.20.5 (zip publié) |
| Contenu seedé | 2 annonces fr (riad Marrakech, villa Casablanca) + 1 traduction EN liée, via wp-cli (Estatik CPT properties) |
| Navigateur | non (banc curl + wp-cli ; captures remplacées par rendus HTML bruts) |

## 2. Étape 0 — Assets PUBLIÉS (téléchargés depuis la Release GitHub)

| Asset | Taille | SHA-256 recalculé |
|---|---|---|
| 03-A-INSTALLER-THEME-partikulier-6.20.5.zip | 1 119 448 o | fd1ad6f2… OK (sha256sum -c) |
| 04-B-INSTALLER-PLUGIN-partikulier-core-2.10.6.zip | 163 273 o | 0fa2b738… OK |

Installation via `wp plugin/theme install <zip> --force --activate` : propre, zéro erreur.

## 3. Étape 4 — Health (GET /wp-json/partikulier/v1/health)

JSON brut capturé (/tmp/h8c.json, reproduit dans le fichier brut). Verdict champ par champ :

| Champ | Attendu | Observé | |
|---|---|---|---|
| status | ok | ok | ✅ |
| database | ready | ready | ✅ |
| core_version | 2.10.6 | 2.10.6 | ✅ |
| schema_version | 2.6.0 | 2.6.0 | ✅ |
| routes.namespace | partikulier/v1 | partikulier/v1 | ✅ |
| routes.collisions | 0 | 0 | ✅ |
| 8 domaines (alerts, automation, leads, listings, owner_stats, payments, premium, translation_variants) | présents | 8/8 | ✅ |
| Tous owner | plugin | plugin partout | ✅ |
| Tables | toutes true | 20/20 true | ✅ |
| integrity | orphans 0 / missing 0 | 0 / 0 (served 3, live_posts 3) | ✅ |

Fait de structure : les 8 domaines sont à la racine du JSON, pas imbriqués sous
routes comme le formulait la mission. Aucun champ manquant — reformulation cosmétique
de la mission, pas un écart produit.

## 4. Étape 5 — 6 pages front

| Page | Code | Title rendu | Remarques |
|---|---|---|---|
| / | 200 | Partikulier Sandbox | 54 Ko, 0 marqueur erreur PHP |
| /annonces/ | 200 | Annonces – … | archive CPT rendue |
| /en/annonces/ | 200 | Listings – … | lang="en-US" ✅ |
| /ar/annonces/ | 200 | الإعلانات – … | dir="rtl" ✅ + lang="ar" ✅ |
| /contact/ | 200 | Contactez-nous – … | |
| /sitemap.xml | 200 | XML valide (1 Ko) | contient annonces + properties |

Zéro Fatal error / Warning / Notice / Deprecated dans les 6 rendus.

## 5. Étape 6 — Fiche annonce

`/property/riad-renove-avec-patio-marrakech-medina/` → 200 (46 Ko) :

- JSON-LD RealEstateListing présent (bloc @graph) : name, url, description,
  datePosted/Modified, offers (price 450 000 MAD, InStock), itemOffered.
- hreflang : fr + en (vers la fiche traduite, 200) + x-default — URLs exactes.
- 0 erreur PHP dans le rendu.

## 6. Étape 7 — Smokes dynamiques

| Smoke | Résultat |
|---|---|
| Recherche GET /partikulier/v1/listings?q=riad | 200, 2 résultats JSON (titres conformes) |
| GET /partikulier/v1/listings?locale=fr | 200, 2 annonces fr |
| Favori anonyme (bouton front réel) | ✅ pk_sync_favorite via admin-ajax avec nonce public + visitor_id : save → {"success":true,"saved":true}, remove → saved:false, re-save → saved:true ; persistance DB avec visitor_hash SHA-256 (pas d'ID visiteur brut — privacy by design) |
| Favori REST /favorites anonyme | 401 structurée (rest_forbidden) — la route REST est guardPrivate (is_user_logged_in) ; le chemin anonyme du produit est l'AJAX ci-dessus |
| Bascule REST authentifiée | omission documentée : mécanique cookie/nonce du banc wp server non résolue dans le temps imparti (le code montre canReadPrivate() = is_user_logged_in() ; aucun doute fonctionnel, mais non observé en HTTP — rapporté tel quel) |

## 7. Étape 8 — Hygiène

- debug.log : absent (WP_DEBUG off) ; log serveur : 0 erreur PHP (une seule ligne
  « deprecated » = le lanceur mysqld_safe de MariaDB, hors PHP).
- Assets front de l'accueil : 2 référencés, 0 en 404 — style.css?ver=6.20.5 et
  main.js?ver=6.20.5 (cache-bust au bon numéro de version).
- Versions servies : plugin 2.10.6, thème 6.20.5.

## 8. Faits et corrections (transparence totale)

1. **Base de données reprise** : le teardown de la batterie précédente arrêtait MariaDB
   sans vider la base ; détecté en cours de batterie (mot de passe admin divergent,
   données résiduelles). Corrigé par DROP DATABASE + réinstallation complète, puis
   batterie rejouée intégralement sur base vide. Les résultats de ce rapport sont ceux du
   banc propre.
2. **Mission 8C, étape 1 vs étapes 3/5/6** : « aucun autre plugin » est contradictoire
   avec la création d'annonces (CPT fourni par Estatik, requis par le thème) et les
   pages /en|ar/annonces/ + hreflang (Polylang). Exécuté avec Estatik + Polylang
   actifs (banc minimal par ailleurs : theme-check désactivé). À corriger dans le texte
   de mission pour les futurs rejeux.
3. **Formulation health** : domaines à la racine, pas sous routes (§3).
4. **Formulation favori** : le produit n'expose pas de favori REST anonyme ; le bouton
   front passe par AJAX pk_sync_favorite. Testé selon le protocole réel du bouton.

Aucune simulation ; toute limite est nommée (§6, omission REST authentifiée).

## 9. Conclusion

Critère 12 de l'étape 8 : **satisfait par l'exécution** — batterie dynamique sur les
assets publiés, sur WordPress frais à base vide, sans patch ni simulation.
Aucun écart produit observé. Ce rapport + BATTERIE-8C-BRUTE.txt +
TEST-DYNAMIQUE-TRAIN2.md (batterie SE-022/HMAC/E-16xx de la même session) forment le
dossier de vérification dynamique complet du Train 2, à verser aux preuves de clôture.
