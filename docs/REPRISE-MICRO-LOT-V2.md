# Reprise du micro-lot pré-prod 2.10.8 / 6.20.7 — exécution v2

**Date** : 18 septembre 2026 · **Branche** : `release/preprod-2.10.8-6.20.7-v2` · **Base** : `main` @ `f0c5596` (lot sécurité 2.10.7 / 6.20.6, oracle 297/297 — 27 suites)

## 1. Pourquoi cette branche existe

Une première exécution du micro-lot a été menée du 16 au 17 septembre 2026
sur la branche `release/preprod-2.10.8-6.20.7` (11 commits, plage
`f0c5596..edab4b5`, jamais poussés sur GitHub). Cette tentative a été perdue
intégralement : la remise à zéro de l'environnement d'exécution a détruit le
dépôt local qui contenait les seuls objets Git de ces commits. Aucun bundle,
aucun push, aucune copie n'en subsiste ; les SHA de cette plage sont
définitivement introuvables.

## 2. Décision prise (commanditaire, 18 septembre 2026)

- **On ne reconstruit pas une fausse histoire Git.** Aucun commit « ressemblant »
  ne sera fabriqué pour imiter les 11 commits perdus.
- **On refait le micro-lot proprement**, comme un travail neuf et légitime,
  depuis l'état réel de `main` (`f0c5596`), avec de vrais commits dans l'ordre
  du plan d'exécution — pas un commit unique.
- **Sources de vérité** : le CDC (v5.7.1) fait foi pour les exigences ; le plan
  d'exécution du micro-lot fixe l'ordre et les contrats. Les zips du pack
  rév. 2 (thème 6.20.7 `eea9fb67…`, plugin 2.10.8 `ed6ce0a9…`), vérifiés
  intacts, servent **exclusivement de référence de contrôle en fin de travail**
  (diff de résultat fonctionnel) — jamais de source de copie.
- Les artefacts de travail de la tentative perdue (scripts d'application,
  journaux) retrouvés sur l'image disque antérieure sont **écartés du chemin
  d'exécution** pour la même raison : le travail doit être refait, non rejoué.

## 3. Périmètre (lots, dans l'ordre d'exécution)

| # | Lot | Artefact | Contenu (exigences CDC) |
|---|---|---|---|
| 0 | Reprise | repo | le présent document (premier commit de la branche) |
| 1 | SE-026 | plugin | warning `hmac_mode` à la sauvegarde des réglages (E-2601/E-2602) |
| 2 | Schéma 2.7.0 | plugin | table `pk_slug_redirects` + API SlugRedirects (E-4303, prérequis de SE-043) |
| 3 | SE-024 | thème | dédoublonnage des dictionnaires i18n + inventaire DP-1 (E-2401→E-2403) |
| 4 | SE-043 | thème | résolution géo : 404/410 explicites, 301 via `pk_slug_redirects`, jamais de cache des échecs (E-4301→E-4304) |
| 5 | SE-034 | thème | idempotence du canal public de dépôt (E-3401→E-3404) |
| 6 | SE-048-U | plugin + thème | audit `premium_granted` (E-4802) + espace premium découvrable et honnête (E-4803, DP-8 = maintien) |
| 7 | SE-035 | plugin + thème | catalogues EN/AR complétés (E-3501) + génération/pkConfig (E-3502/E-3503) |
| 8 | SE-036 | thème | écran « Villes & quartiers » trilingue + référentiel intégré + import CSV (E-3601→E-3604) |
| 9 | SE-037 | thème | SEO multilingue : sitemap ×3 langues, title filter, hreflang (E-3701/E-3702, non-régression E-3703) |
| 10 | SE-054 | thème + plugin | expérience « annonce vendue » : filigrane ×3 langues, 3 annonces similaires, WhatsApp prérempli, filet de sécurité variantes (E-5401→E-5403) |
| 11 | SE-025 | CI | workflow trilingue Polylang + 9 URLs + démo négative (E-2501→E-2504) + intégration des nouveaux contrats à l'oracle |
| 12 | Bump | les deux | 2.10.8 / 6.20.7 (5 points) + rejeu complet |

Décisions produit confirmées : **DP-5 = oui** (trilingue FR/EN/AR jour 1),
**DP-8 = maintien** de la promesse premium, **DP-1 = défaut « dernière
occurrence »** (inventaire des clés divergentes soumis avec SE-024).

## 4. Discipline d'exécution (inchangée par rapport aux trains précédents)

- **R1** — reproduction de chaque défaut avant tout correctif ;
- **R2** — verrou contractuel par correctif : chaque lot ajoute ses contrats à
  l'oracle CI (`contrats-recette.yml`) ;
- **R6** — le diff de chaque lot est soumis au commanditaire **avant** le
  commit ; aucun pack « tout fait » en fin de chaîne ;
- oracle cible en fin de micro-lot : **343/343 assertions — 35 suites**
  (base 297/297 — 27) ; lint complet vert à chaque lot ;
- aucun push, aucun tag, aucune écriture distante sans feu vert explicite.

## 5. Trace d'honnêteté

Chaque commit de cette branche porte du travail réellement exécuté dans cette
session. Les numéros d'exigence (E-xxxx) font foi vers le CDC. Lorsque le
résultat fonctionnel différera de la tentative perdue (formulations, données
du référentiel de lieux, traductions), l'écart sera consigné dans le rapport
de lot plutôt que masqué : la tentative perdue n'est pas une norme, le CDC
l'est.
