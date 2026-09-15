# ERRATUM DE MISSION 8C — corrections pour les futurs rejeux

**Origine** : les 4 corrections rapportées par le vérificateur (rapport 8C §8) après
exécution de la mission sur le Train 2. La mission remplissait son rôle (protocole,
attendus, interdits) ; ces points corrigent ses formulations pour qu'un rejeu ne
rencontre pas les mêmes ambiguïtés.

## 1. Étape 1 — dépendances requises (remplace « Aucun autre plugin »)

Formulation d'origine : « **Aucun autre plugin** que partikulier-core. »

Constat : contradictoire avec l'étape 3 (le CPT `properties` est fourni par Estatik,
requis par le thème) et les étapes 5/6 (pages `/en|ar/annonces/` + hreflang = Polylang).

**Formulation corrigée** :

> WordPress frais, PHP ≥ 8.0, base vide. Plugins : partikulier-core **et les
> dépendances requises par le thème uniquement** — Estatik (CPT `properties`) et
> Polylang (pages multilingues / hreflang). Tout autre plugin reste interdit
> (page builder, cache…). Signaler les versions installées dans le rapport.

## 2. Étape 4 — structure du health (remplace « avec leurs tables » sous routes)

Constat : les 8 domaines sont à la **racine** du JSON (`alerts`, `automation`, `leads`,
`listings`, `owner_stats`, `payments`, `premium`, `translation_variants`), chacun avec
`owner` / `lot` / `tables` ; le bloc `routes` ne porte que `namespace` + `collisions`.
Vérifié dans le code (`DomainRegistry::health()`).

**Formulation corrigée** :

> - `"routes":{"namespace":"partikulier/v1","collisions":0}`
> - **8 domaines à la racine du JSON**, tous `"owner":"plugin"`, avec leurs tables à `true`.

## 3. Étape 7 — protocole réel du favori anonyme (remplace l'appel REST anonyme)

Constat : le produit n'expose **pas** de favori REST anonyme. La route REST
`/favorites` est `guardPrivate` (`canReadPrivate()` = `is_user_logged_in()`) → un
appel anonyme renvoie une 401 structurée (`rest_forbidden`) : c'est le comportement
attendu, pas un échec. Le chemin anonyme réel du produit est l'AJAX front.

**Formulation corrigée** :

> - Favori anonyme **par le protocole réel du bouton front** : `POST /wp-admin/admin-ajax.php`
>   action `pk_sync_favorite` (nonce public + `visitor_id`) — save / remove / re-save,
>   persistance en base avec pseudonymisation (visitor_hash, pas d'ID brut).
> - Contrôle complémentaire : `POST /wp-json/partikulier/v1/favorites` en anonyme →
>   **401 structurée attendue** (route privée) — le succès anonyme serait l'anomalie.
> - La bascule REST **authentifiée** est optionnelle : si la mécanique cookie/nonce du
>   banc n'est pas résolue dans le temps imparti, documenter l'omission (déjà admise
>   pour ce Train ; le code `canReadPrivate()` est vérifiable statiquement).

## 4. Étape 7 — le smoke recherche n'a pas de contrat `?locale=` garanti

Constat rapporté : `GET /wp-json/partikulier/v1/listings?q=<terme>` est le contrat ;
`?locale=fr` a fonctionné sur ce banc mais n'était pas un attendu de mission — le
noter comme observation, pas comme assertion.

---

**Statut** : ces corrections s'appliquent aux **futurs rejeux** de la mission 8C
(Train 3+). Le rapport du Train 2 reste valable tel quel : les écarts constatés
étaient des défauts de formulation de la mission, pas du produit.
