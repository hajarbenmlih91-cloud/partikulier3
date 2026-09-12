# Correction sécurité du lot E — cible-lien et délai d’exécution

## Périmètre

La correction du lot E protège le point d’appel système AVIF contre les liens symboliques sur la cible et contre les exécutables qui resteraient bloqués indéfiniment. Le chemin physique de la cible est contrôlé après retrait du suffixe de qualité `[Q=n]`, et les refus sont journalisés avec le motif `REFUS:cible-lien`.

## Délai d’exécution

Le délai par défaut de la passerelle `Partikulier_Exec_Whitelist` est **de 30 secondes**, défini par `EXEC_TIMEOUT = 30`. Cette valeur est filtrable via `partikulier_exec_timeout` afin que les tests et l’exploitation puissent appliquer une fenêtre plus courte ou adaptée sans modifier le code métier.

Au dépassement, le processus est interrompu par `SIGKILL`, le journal reçoit `EXEC:timeout`, les tuyaux sont drainés avant `proc_close()`, et le premier état post-exit est utilisé pour éviter d’interpréter à tort un code de retour `-1`.

Le contrat de sécurité utilise volontairement un binaire `sleep 60` avec un filtre de test à **1 seconde**. La valeur `60` décrit la durée du binaire dormant, et non le délai par défaut de la passerelle.

## Vérification

Les assertions `SE-013` et `SE-014` couvrent respectivement le refus d’une cible lien symbolique et l’expiration bornée du processus. Le résultat attendu de la recette complète est **20 suites et 236 assertions passantes**.

Cette formulation distingue explicitement les trois valeurs afin d’éviter toute ambiguïté documentaire :

| Élément | Valeur |
|---|---:|
| Délai par défaut de la passerelle | **30 secondes** |
| Délai utilisé par le filtre du test SE-014 | **1 seconde** |
| Durée du binaire dormant de test | **60 secondes** |
