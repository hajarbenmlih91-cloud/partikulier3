# Rotation opérationnelle des clés n8n

Ce guide décrit le comportement du code du dépôt. Il ne confirme pas les secrets, le mode HMAC ni les workflows effectivement déployés sur un environnement hébergé.

## Avant une rotation

- Préparer le nouveau secret dans un gestionnaire de secrets, avec au moins 32 octets aléatoires; ne pas le coller dans un ticket, un journal ou un workflow exporté.
- Préparer n8n à accepter la nouvelle clé et le nouveau Key ID pour les webhooks WordPress sortants, puis planifier le basculement du secret utilisé pour les requêtes entrantes.
- La période de chevauchement est de **24 heures**. L’écran affiche les Key IDs et l’expiration UTC, jamais les secrets.
- La vérification HMAC entrante lie la méthode, le chemin, l’horodatage, le corps exact et le Key ID. La fenêtre d’horodatage demeure de 300 secondes; la rotation ne remplace pas les identifiants/idempotency keys métier requis par chaque endpoint.

## Secret géré dans WordPress

1. Dans **Outils → Réglages n8n**, laissez le champ secret vide si vous ne changez rien. Saisissez une nouvelle clé aléatoire pour démarrer la rotation.
2. WordPress rend immédiatement la nouvelle clé active et conserve l’ancienne sous son Key ID actuel pendant 24 heures. Copiez le nouveau Key ID affiché; le secret ne sera pas réaffiché.
3. Mettez à jour le credential n8n de façon coordonnée : accepter la nouvelle clé pour les webhooks sortants et signer les appels entrants avec la nouvelle clé/Key ID.
4. Avant l’échéance affichée, vérifiez avec une requête de staging non destructive que les deux sens fonctionnent et surveillez les erreurs HMAC. Après l’échéance, l’ancienne clé n’est plus acceptée.
5. Une deuxième rotation est refusée pendant le chevauchement. À la première lecture de clés après l’échéance, l’ancienne valeur est retirée de l’option; vérifiez l’état puis enquêtez sur toute erreur avant de recommencer.

## Secret géré par l’environnement

1. Dans le même écran, cliquez **Préparer la rotation environnement**. WordPress conserve la valeur actuellement injectée comme clé précédente pour 24 heures, conserve son Key ID, et affiche un nouveau Key ID à configurer. La valeur du secret n’est jamais affichée.
2. Préparez et stockez un nouveau secret dans le gestionnaire de secrets de l’hébergement. Configurez `PARTIKULIER_N8N_SECRET` et `PARTIKULIER_N8N_KEY_ID` avec le nouveau secret et le Key ID affiché; coordonnez aussi n8n pour accepter les nouvelles signatures dans les deux sens.
3. Appliquez le changement selon la procédure de déploiement de l’hébergeur. Pendant le chevauchement, WordPress accepte l’ancien Key ID/secret et le nouveau Key ID/secret; les signatures sortantes utilisent le nouveau secret actif.
4. Vérifiez le basculement en staging et avant l’expiration. Si la plateforme ne permet pas de définir `PARTIKULIER_N8N_KEY_ID`, ne commencez pas cette procédure : utilisez d’abord une version/configuration qui permet un Key ID distinct.

Ne lancez pas la rotation en production sans fenêtre de changement, préparation de n8n et voie de retour opérable. Aucune rotation hébergée ni vérification UAT n’est affirmée par ce dépôt.
