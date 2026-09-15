# TEST DYNAMIQUE — TRAIN 2 v2.10.6-6.20.5 (commit 21498fd)

> Document retransmis verbatim depuis le canal commanditaire (2026-09-15).
> Tableaux restitués en markdown ; contenu non modifié.

**Méthode** : WordPress 7.1 / PHP 8.4.24 frais dans la sandbox, zips de la Release GitHub
publiée téléchargés par mes soins et installés via wp-cli (installation équivalente
production — pas de copie du repo). Batterie de tests réels par HTTP + wp eval.

**Date** : 2026-09-15. Environnement détruit après tests (scripts/pk-env.sh conservé).

**Verdict global : ✅ Train 2 confirmé bon pour la production**

- **SE-022 (idempotence par cycle de requête)** : prouvé en exécution réelle HTTP sur les
  deux gardes (/erase-lead et HMAC n8n) — le bug P2 de Train 1 est bien corrigé.
- **SE-019 (passe style-only)** : aucune régression front observée.
- Un fait résiduel nouveau caractérisé (§3) : les requêtes OPTIONS sur une route gardée
  exécutent la garde une fois (classe de défaut pré-existante, P3, backlog Train 3 proposé).
  Aucune faille : le comportement est plus strict que spécifié.

## 1. Publication & packaging ✅ (preuve de bout en bout)

| Vérification | Résultat |
|---|---|
| Assets téléchargés depuis l'URL GitHub publique (pas le pack) | ✅ |
| SHA-256 recalculés vs valeurs pack-13 déclarées | ✅ fd1ad6f2… (thème) et 0fa2b738… (plugin) identiques |
| sha256sum -c 05-SHA256SUMS-TRAIN2.txt sur mes téléchargements | ✅ OK / OK |
| 05-SHA256SUMS-TRAIN2.txt : exactement 2 entrées zips | ✅ (reco Train 1 #2 « entrées patch fantômes » soldée) |
| Zips : 0 entrée .git/, 0 tests/, 0 *.sh parasite | ✅ |
| Installation/activation wp-cli | ✅ propre, versions actives 2.10.6 / 6.20.5 |
| /wp-json/partikulier/v1/health | ✅ status ok, core_version 2.10.6, schema_version 2.6.0 |

## 2. SE-022 — idempotence réelle, prouvée par HTTP ✅

### 2.1 Garde /erase-lead — rate-limit nominal restauré

| Mesure | Train 1 (2.10.5) | Train 2 (2.10.6) |
|---|---|---|
| 14 POST anonymes consécutifs | 401 ×5 puis 429 dès la 6ᵉ | 401 ×10 puis 429 à la 11ᵉ ✅ |
| Audits lead_erase_auth_failed | ×2 par échec | 10 lignes pour 10 échecs ✅ |
| Audits lead_erase_flood | doublés | 1 par rejet (4 rejets = 4 lignes) ✅ |

E-1604 (10 échecs / fenêtre, seuil vérifié avant incrément) est désormais nominale.

### 2.2 Garde HMAC n8n (/automation-event) — audit d'échec unique en mode log

Mode log + signature invalide : requête acceptée, exactement 1 ligne dans
wp_pk_n8n_hmac_audit par requête (la ré-exécution Allow-header est neutralisée par
RequestCycle::first_run($request,'hmac_audit_failure')) ✅. Signature valide : zéro
audit d'échec ✅.

### 2.3 Mécanisme vérifié dans le code installé

RequestCycle (module dédié SE-022) : WeakMap<WP_REST_Request,…> — le verdict est
mis en cache sur l'objet requête (recall/remember) ; la passe Allow-header restitue
le verdict de la passe réelle, les effets de bord sont protégés par first_run.
Conforme au design CDC v4.1 §8B (pas de booléen statique nu, pas de recyclage
d'identifiant).

## 3. 🟡 Fait résiduel nouveau — OPTIONS exécute la garde (P3, pré-existant)

**Mesure** : OPTIONS /erase-lead (réponse 200 discovery) → compteur d'échecs +1
et 1 audit lead_erase_auth_failed ; GET/PATCH/HEAD sur la même route :
aucun effet. Attribuée formellement à OPTIONS (une seule requête OPTIONS dans
la fenêtre = exactement une ligne d'audit horodatée correspondante).

**Cause** : la construction de l'en-tête Allow / discovery ré-exécute le
permission_callback alors qu'aucune passe de dispatch n'a eu lieu — le cache
RequestCycle est vide, l'évaluation complète (avec effets de bord) s'exécute une fois.

**Portée** : pré-existant à SE-022 (Train 1 inclus). SE-022 garantit « au plus une fois
par cycle » — c'est bien le cas ici (une seule passe) ; le résidu est que des requêtes
non dispatchées déclenchent des effets. Pas de faille (plus strict que spécifié), mais
consommation du budget anti-forçage par IP : derrière un reverse proxy à IP partagée,
des OPTIONS répétés peuvent épuiser le budget d'un appelant légitime (même classe de
risque que le lockout n8n de Train 1, à coût d'exploitation faible).

**Recommandation Train 3** : neutraliser les effets de bord hors passe de dispatch
(marqueur RequestCycle posé par le dispatcher, ou garde no-op quand la méthode de la
requête ne matche pas la route).

## 4. Matrice sécurité E-16xx — /erase-lead ✅

| Exigence | Test réel | Résultat |
|---|---|---|
| E-1601 | secret faux / absent | ✅ 401 + audit unique |
| E-1604 | flooding | ✅ 429 à la 11ᵉ (voir §2.1) |
| E-1605 | wa_id inconnu + secret valide | ✅ 200 {"erased":true}, pas de 404 |
| — | lead réel inséré puis effacé | ✅ 200 + disparition physique (lead_erased, tables: 8) |
| — | 2ᵉ erase du même wa_id | ✅ 200 idempotent |
| E-1609 | wa_id manquant | ✅ 400 |
| E-1603 | flag transition OFF + ancien secret n8n | ✅ 401 (rejet strict) |
| E-1606 | flag ON + ancien secret | ✅ accepté + audit lead_erase_secret_deprecated ×1 |
| — | flag transition par défaut | ✅ OFF |

## 5. Pont HMAC n8n (lot B4) ✅

| Cas | Résultat |
|---|---|
| Pas de secret | ✅ 401 |
| Mode off + secret configuré | ✅ sémantique « secret présent + off = enforce » (LOT 2 Isolation) |
| Mode log + signature invalide | ✅ accepté + 1 audit d'échec (SE-022) |
| Mode log + signature valide | ✅ accepté, zéro audit d'échec |
| Mode enforce + signature invalide | ✅ 401 |
| Mode enforce + signature valide | ✅ passe la garde (calcul : METHODE\nroute\ntimestamp\ncorps, clé = dérivée base64 du secret) |

## 6. Thème — Theme Check : 16 → 14 REQUIRED ✅

Les 2 cibles SE-022 sont tombées : register_sidebar sans dynamic_sidebar (0 sidebar
enregistrée désormais) et casse « WordPress » dans pk-diagnostic.php (0 occurrence
fautive, 24 correctes). Les 14 REQUIRED restants = résidus documentés de Train 1
(plugin-territory remove_action, proc_open passerelle exec, base64 AES-GCM,
post_class/wp_link_pages absents). INFO 3 / RECOMMENDED 40 / WARNING 46.

## 7. Front & régressions SE-019 ✅

- Accueil 200 (45,7 Ko), 1 seul script : main.js?ver=6.20.5 (cache-bust bumpé) ✅
- jQuery / select2 / datetimepicker / wp-color-picker : 0 référence (E-1803 tenu) ✅
- /contact/, /faq/, /connexion/ : 200 ✅
- main.js, submit-steps.js, style.css?ver=6.20.5 servis 200 ✅
- CSS majoritairement inline (stratégie perf du thème) + feuille style.css ✅
- 0 erreur PHP (fatal/warning/notice/deprecated) sur toute la batterie
  (pas de debug.log, log serveur propre) ✅

## 8. Deltas honnêtes avec l'environnement CI

- Sous le routeur wp server, une méthode non autorisée rend 404 là où un
  serveur classique rend 405+Allow (constaté sur /erase-lead et /health). Les
  contrats B1–B6 sont assertés par HTTP réel dans le workflow et verts sur ce
  commit exact (run 34920185631) — artefact du routeur local, pas du code.
- /health n'implémente ni filtre ?fields= ni paramètre _pk_param : c'étaient des
  hypothèses de ma batterie initiale, pas des contrats réels — corrigé sans impact.

## Limites (identiques à Train 1)

- Fenêtre de transition n8n côté orchestrateur non prouvable ici (flag OFF = garde-fou).
- Multi-nav, cache page, SEO complet non rejoués (hors diff Train 2).
- Contenu Estatik non seedé : page annonce non rejouée (couverte par le contrat
  front-assets CI vert sur ce commit).

## Backlog proposé Train 3

| # | Item | Sévérité |
|---|---|---|
| 1 | Neutraliser les effets de bord des gardes hors passe de dispatch (résidu OPTIONS, §3) | P3 |
| 2 | SE-023 — fixers PHPCS token-transformants (backlog existant) | P3 |
| 3 | Trancher la chaîne outil canonique (notes Train 2 : PHPCSUtils 1.2.3/PHPCSExtra 1.5.1 vs outillage vérifié 1.1.1/1.5.0) | Doc |
| 4 | Prouver la bascule n8n avant tout rejet strict (inchangé depuis Train 1) | Bloquant avant flag ON |
