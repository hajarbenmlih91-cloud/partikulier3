# Journal des Modifications (Changelog) — Thème Partikulier

Toutes les modifications notables apportées au thème Partikulier sont consignées dans ce document.
Le format est basé sur [Keep a Changelog](https://keepachangelog.com/fr/1.0.0/),
et ce projet adhère au [Semantic Versioning](https://semver.org/lang/fr/).

---

## [6.20.8] - 2026-10-03

### Modifié
- Alignement de version avec le plugin Partikulier Core 2.10.9 (release outillage CI/CD et documentation, aucune évolution fonctionnelle).
- Contrats de recette alignés sur la paire 2.10.9 / 6.20.8.

## [6.20.7-FINAL] - 2026-09-27 (Homologation Complète 18 Scénarios de Recette)

### Ajouté
- **Option B (100% WhatsApp Strict / SIM-07)** : Remplacement complet du formulaire web par le contact direct propriétaire par clic WhatsApp sécurisé sur toutes les fiches publiques singulières.
- **Constructeur de Champs SE-042c** : Gestion dynamique des caractéristiques par type de bien avec plafonnement strict à 8 champs maximum, rejet côté serveur en cas de dépassement et alignement responsive mobile/tablette sur la maquette.
- **Statut Premium Public à 0 MAD (SE-048-R)** : Activation immédiate du statut Premium offert pour le lancement (`pk_premium_public_enabled = 1`), affichage du bandeau informatif en 3 langues et purge automatique du cache à l'expiration.
- **Cycle de Vie & SEO Fiches Clôturées (SE-054)** : Maintien en ligne des annonces vendues ou désactivées en HTTP 200 (préservation SEO Google), filigranes dédiés (*Vendu*, *Loué*, *Indisponible*), masquage des coordonnées et affichage systématique de 3 annonces similaires actives avec redirection WhatsApp sur toutes les annonces.
- **Moteur Toponymique Bilingue (SE-036 / DP-5)** : Normalisation `strip_alif_lam` (`صويرة` $\leftrightarrow$ `الصويرة`), réconciliation des requêtes franco-arabes, autocomplétion Hero dès la 1ère lettre et contraste strict vérifié (WCAG AA).
- **Routage Trilingue & RTL (SE-025 / SE-027)** : Prise en charge native du français, anglais et arabe littéraire avec inversion RTL automatique (`dir="rtl"`, `lang="ar"`), cluster hreflang 4 entrées et réécriture v5 sans double-préfixation.
- **Traçabilité CI WebKit** : Intégration documentée de `npx playwright install --with-deps webkit` pour la couverture complète Safari / iOS.

### Corrigé
- **Sécurité d'Exécution Système (SE-002)** : Suppression de l'appel direct `@exec` dans `inc/class-cache.php` au profit de suppressions récursives natives PHP du cache Nginx, rétablissant la conformité absolue avec la passerelle unique `class-exec-whitelist.php`.
- **Performance Front-Assets (DP-6 / SE-018)** : Application d'un déréférencement double (`wp_dequeue_script` + `wp_deregister_script`) sur 17 poignées d'assets lourds Estatik/jQuery sur toutes les pages publiques, avec exception documentée confinée aux pages propriétaires (`/deposer/`, `/wp-admin/`).
- **Couture des Transitions d'Annonces (DP-9)** : Sécurisation de l'appel conditionnel aux méthodes de variantes pour éliminer toute erreur 500 lors du passage d'une annonce en statut vendu ou désactivé.
- **Isolation des Fixtures de Test** : Exclusion des fixtures préfixées (`SE-`, `B3A-`, `banc-`) des blocs d'annonces similaires et de l'annuaire public.

---

## [6.20.7-r6] - 2026-09-27
- Refonte des règles de réécriture Polylang v5 sans collision de préfixe.
- Redirection 301 pérenne de l'ancien endpoint `/property/` vers `/fr/annonces/`.
- Durcissement XML-RPC (SE-041) et protection contre les injections de tableaux scalaires (E-5105).

## [6.20.7-r5] - 2026-09-27
- Intégration de la toponymie arabe et filtrage géographique avancé.
- Validation des contrastes sur la barre de recherche Hero.

## [6.20.7-r4] - 2026-09-27
- Déploiement du Statut Premium public à 0 MAD.
- Mise en conformité du bandeau de lancement en français, anglais et arabe.

## [6.20.7-r3] - 2026-09-26
- Intégration du constructeur de champs personnalisés SE-042c (plafond 8 champs max).
- Suppression du formulaire web sur fiches singulières (Option B SIM-07 100% WhatsApp).

## [6.20.7-r2] - 2026-09-26
- Correction de la couture `class-listing-transitions.php` avec Partikulier Core 2.10.8.
- Validation des 5 suites de cohérence et de peremption LiteSpeed Cache.

## [6.17.35] - 2026-08-25
- Zéro jQuery initial sur pages éditoriales.
- Cache de page intégré et conversion AVIF automatique des images.
