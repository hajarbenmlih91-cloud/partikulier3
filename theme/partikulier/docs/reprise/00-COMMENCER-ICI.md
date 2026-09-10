# Partikulier — reprise du développement

**Thème WordPress · version 6.13.0 · août 2026**

Ce dossier est fait pour qu'un développeur puisse reprendre le projet sans qu'on lui explique quoi que ce soit. Lis ce fichier en entier, puis va voir le document qui correspond à ce que tu dois faire.

---

## Ce qu'est ce projet

Partikulier est un portail immobilier marocain **réservé aux propriétaires particuliers**. Les agences et agents immobiliers sont refusés — ce n'est pas un oubli, c'est le positionnement du produit.

Un propriétaire dépose son annonce depuis le site, sans créer de compte. L'annonce part en attente. Un administrateur la valide depuis l'admin WordPress. À la validation, l'annonce est publiée en trois langues et l'annonceur reçoit ses identifiants par WhatsApp via n8n.

Le thème dépend d'**un seul plugin : Estatik**. Tout le reste (SEO, cache, AVIF, traductions, formulaire, tableaux de bord) est dans le thème.

---

## Les documents de ce dossier

| Fichier | À lire quand |
| --- | --- |
| **00-COMMENCER-ICI.md** | Maintenant. Vue d'ensemble et démarrage. |
| **01-ARCHITECTURE.md** | Avant de toucher au code. Modules, données, conventions. |
| **02-DECISIONS.md** | **Le plus important.** Pourquoi le code est comme il est. À lire avant de « corriger » quoi que ce soit. |
| **03-CHANGELOG.md** | Historique des versions 5.5.2 → 6.13.0. |
| **04-PIEGES.md** | Bugs déjà rencontrés et résolus. Fait gagner des heures. |
| **05-CHANTIERS-RESTANTS.md** | Ce qui reste à faire, avec le contexte. |
| **06-TESTS.md** | Monter un environnement local et vérifier son travail. |

---

## Démarrage rapide

### Installer le thème

1. Uploader le zip dans *Apparence › Thèmes › Ajouter*.
2. Activer. Le thème crée automatiquement ses pages obligatoires (`deposer-une-annonce`, `mes-annonces`, `favoris`).
3. Installer et activer **Estatik**.
4. Aller dans *Réglages › Permaliens* et cliquer **Enregistrer** sans rien changer. **Cette étape n'est pas optionnelle** : sans elle, les URL d'annonces renvoient des 404 sur certains hébergements.
5. Renseigner le numéro WhatsApp dans *Apparence › Personnaliser › Validation WhatsApp*. Sans ce numéro, **le formulaire de dépôt refuse toutes les annonces** avec le message « dépôt momentanément indisponible ».

### Vérifier que tout va bien

Le thème embarque son propre outil de diagnostic : **Partikulier › Diagnostic des pages › Analyser tout le site**. Il vérifie les 6 pages clés et signale les problèmes bloquants. C'est le premier réflexe quand quelque chose ne marche pas.

---

## Les cinq règles à respecter

Ces règles viennent du client. Les enfreindre, c'est du travail à refaire.

1. **Jamais le mot « mandataire ».** Le vocabulaire est « Propriétaire » ou « Agent immobilier » (ce dernier étant refusé à l'inscription).
2. **Le logo est du texte, jamais une image, et sans symbole.** Le client a été explicite là-dessus.
3. **Toute la langue visible est en français.** Le code et les commentaires aussi.
4. **Une page = une entrée en base.** Pas de pages virtuelles générées à la volée. Elles sont créées à l'activation du thème et vérifiées par le diagnostic.
5. **La variation SEO est déterministe, jamais aléatoire.** Deux annonces identiques doivent produire le même texte. Pas de `rand()` dans la génération de contenu.

---

## L'état actuel en une phrase

Le thème est fonctionnel et livré : dépôt en 3 étapes, validation admin, publication trilingue, URL géographiques avec redirections 301, favoris, diagnostic intégré. Il reste trois chantiers de performance et de configuration, décrits dans `05-CHANTIERS-RESTANTS.md`.


## Dernière campagne d’audit — 26 août 2026

Le rapport **`07-AUDIT-10-PROMPTS-SIMULATIONS-2026-08-26.md`** contient les résultats réels des 10 audits et des 10 simulations exécutés sur le commit `2f626a3`. La décision reste **NO-GO** pour la certification finale. Avant toute nouvelle fonctionnalité, corriger les blocages P0 : accessibilité `aria-hidden-focus`, course de chargement du popup, source de vérité Estatik Vendre/Louer, performance, `package-lock.json` et CI d’installation froide. Le rapport distingue les tests passés, les échecs confirmés, les limitations de dataset et les problèmes de timing du navigateur.

La campagne n’a créé, supprimé ni modifié aucune annonce et n’a utilisé aucun secret de production.

| Document | Utilité |
| --- | --- |
| **07-AUDIT-10-PROMPTS-SIMULATIONS-2026-08-26.md** | Résultats de la dernière campagne réelle et corrections obligatoires |
| **05-AUDIT-SENIOR-2026-08-25.md** | Audit senior initial et état de référence |
| **Cahier-des-charges-correction-audit-Partikulier-v1.0.md** | CDC complet des corrections, tests et critères Go/No-Go |
