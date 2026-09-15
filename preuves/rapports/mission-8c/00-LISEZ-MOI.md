# PACK 15 — ADDENDAU DE CLÔTURE 8C / SE-019 : BATTERIE DYNAMIQUE + ARBITRAGE CHAÎNE OUTIL

**Date d'assemblage : 2026-09-15. Assemblé par : Z/AI (préparateur).**

Ce dossier complète le pack 14 : il verse la **vérification dynamique finale du
Train 2** (critère 12 de l'étape 8) et **l'arbitrage de la chaîne outil SE-019**
(l'observation laissée indéterminée au scellé du pack 14).

## Ce que ce dossier prouve

1. **Le critère 12 est satisfait par l'exécution** : batterie dynamique sur les zips
   **publiés** (téléchargés depuis la Release, empreintes vérifiées), WordPress 7.1
   frais à base vide, health canonique conforme (2.10.6 / 2.6.0 / 0 collision /
   8 domaines plugin / 20 tables), 6 pages front 200 (dont RTL ar), fiche JSON-LD
   RealEstateListing + hreflang fr/en/x-default, smokes recherche + favori (protocole
   réel AJAX), hygiène propre (0 erreur PHP, 0 asset 404, versions servies exactes).
2. **SE-022 est prouvé en dynamique réel** : idempotence par cycle de requête sur les
   deux gardes (/erase-lead : 401 ×10 puis 429 à la 11ᵉ, audits uniques ; HMAC n8n :
   audit d'échec unique en mode log) — le bug P2 de Train 1 est corrigé.
3. **Un résidu P3 est caractérisé** (OPTIONS exécute la garde une fois — classe
   pré-existante, plus strict que spécifié) : confirmé structurellement par le code
   publié, versé au backlog Train 3.
4. **L'observation A (chaîne outil) est tranchée par métadonnées** : la contre-allégation
   « PHPCSUtils 1.1.1 / PHPCSExtra 1.5.0 » est insoluble par composer avec wpcs 3.4.1
   (vérifié à la source Packagist indépendamment par le préparateur) — les notes de
   Release (1.2.3 / 1.5.1) sont confirmées comme combinaison minimale résoluble ;
   la re-dérivation SE-023 reste la voie de certitude absolue.

## Contenu

| Entrée | Rôle |
|---|---|
| `00-LISEZ-MOI.md` | Le présent fichier |
| `01-rapport-8c-batterie.md` | **Rapport 8C verbatim** du vérificateur indépendant (critère 12) — verdict CONFORME, 2 faits d'environnement et 4 corrections de mission documentés |
| `02-batterie-8c-brute.txt` | **Sorties brutes** de la batterie (étapes 0→8, tentatives et reprises comprises) |
| `03-test-dynamique-train2.md` | **Batterie SE-022/HMAC/E-16xx/Theme Check verbatim** (même session) — verdict « bon pour la production », résidu OPTIONS §3, backlog Train 3 |
| `04-preuve-chaine-outil.md` | **Arbitrage chaîne outil verbatim** : réinstallation à l'identique + insolubilité de la contre-allégation + retrait |
| `05-audit-preparateur-8c.md` | **Audit du préparateur** : 10 croisements code sur le commit publié 21498fd (tous verts), nuances, limites, vérification Packagist indépendante, verdict |
| `06-verif-packagist.txt` | Sortie brute de la vérification Packagist du préparateur (source primaire, regénérable : `bash scripts/51-verif-packagist.sh`) |
| `07-erratum-mission-8c.md` | Corrections de la mission 8C pour les futurs rejeux (les 4 points du rapport) |
| `08-erratum-observation-A-resolu.md` | **Mise à jour de l'erratum du pack 14** : observation A tranchée par métadonnées + registre consolidé des 3 écarts Train 2 |
| `09-pack-14/` | Le pack 14 complet (dossier de clôture SE-019) et son sidecar — chaînage par empreinte |
| `MANIFESTE-SHA256.txt` | Empreintes de tous les fichiers de fond de ce dossier |

## Chaîne d'archive

```
pack-15 (ce dossier) ⊃ pack-14 ⊃ pack-13 ⊃ commit 21498fd = main = tag v2.10.6-6.20.5 (publié)
```

Le pack 14 documentait la préparation/publication/audit statique ; le pack 15 ajoute
l'audit **dynamique** et le tranchage de la dernière observation. L'erratum du pack 14
n'est pas modifié dans son fichier (état des connaissances au scellé) : sa mise à jour
vit ici (08), avec traçabilité explicite.

## Protocole de vérification

```bash
sha256sum -c MANIFESTE-SHA256.txt      # depuis la racine extraite → tous OK
# pack-14 embarqué : dézipper 09-pack-14/ puis rejouer SON manifeste interne
# l'empreinte du zip de ce pack figure dans le sidecar pack-15-8c-fermeture.zip.sha256
# la vérification Packagist (06) est regénérable si le banc survit :
#   bash scripts/51-verif-packagist.sh <sortie>
```

## Fidélité de retranscription

Les documents 01–04 ont été reçus via le canal IM du commanditaire et retransmis
**verbatim** (tableaux restitués en markdown, contenu non modifié). Les exécutions
elles-mêmes ne sont pas rejouables (banc du vérificateur détruit après tests) — la
crédibilité repose sur (a) les sorties brutes, (b) les 10 croisements code de l'audit
préparateur (05), (c) les empreintes publiques déjà auditées (Task 48).

## Reste à faire (hors périmètre de ce dossier)

1. **SE-019 : FERMÉE** de bout en bout (préparation → publication → audit statique →
   audit dynamique → observations arbitrées).
2. **Train 3 / SE-023** : ouverture avec le protocole de gel de chaîne (composer.json +
   composer.lock versionnés, `phpcs -i` brut dans chaque rapport, re-dérivation
   `1a126e0` → `53e2df5` octet pour octet) ; au menu : backlog #1 (résidu OPTIONS P3),
   #2 (fixers token-transformants), report de l'erratum readme.txt.
3. **n8n** : la bascule de transition reste à prouver côté orchestrateur avant tout
   passage du flag (bloquant, inchangé depuis Train 1).
