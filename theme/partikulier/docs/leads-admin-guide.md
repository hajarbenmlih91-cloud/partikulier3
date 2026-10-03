# Gérer les leads WhatsApp

## Accéder au tableau

Dans WordPress, ouvrez **Leads WhatsApp** dans le menu latéral. La page est réservée aux comptes disposant de la capacité `manage_options` : un éditeur, un propriétaire ou un visiteur ne peut ni voir les numéros, ni modifier le suivi.

## Lire les indicateurs

| Indicateur | Signification |
| --- | --- |
| Leads connus | Nombre de numéros qui ont formulé une demande qualifiée. |
| À traiter | Leads sans suivi finalisé. |
| Accord annonces similaires | Personnes ayant donné un consentement volontaire encore actif. |
| Contacts propriétaires aujourd’hui | Mises en relation distinctes consommées sur la journée. |

## Traiter un lead

Chaque ligne affiche le numéro, l’annonce ou la référence, le budget, les quartiers, la configuration recherchée, le type de transaction, le consentement et le quota du jour. Utilisez les filtres de statut, de consentement ou de référence pour réduire la liste.

Sélectionnez ensuite le statut adéquat : **À traiter**, **En cours**, **Contact propriétaire transmis**, **Qualifié** ou **Clos**. Renseignez une note interne, puis cliquez sur **Enregistrer**. L’historique est journalisé et ne doit contenir aucun commentaire inutilement sensible. Lorsqu’une personne envoie `STOP`, son opposition est affichée séparément dans la colonne de consentement ; passez son suivi à **Clos** et n’envoyez plus d’annonces promotionnelles.

Le bouton **Ouvrir WhatsApp** ne doit être utilisé que dans le cadre autorisé par la conversation en cours. Les campagnes d’annonces similaires nécessitent un consentement actif et, hors de la fenêtre de conversation WhatsApp, un modèle approuvé configuré dans WhatsApp Business.

## Règles à respecter

Le nombre et les critères d’un lead sont affichés uniquement aux administrateurs. Le tableau n’accorde pas de dérogation à la limite de deux propriétaires distincts par jour : cette règle est appliquée dans le traitement serveur. Lorsqu’une personne envoie `STOP`, son consentement aux annonces similaires est supprimé et ses coordonnées ne doivent plus être utilisées pour une relance promotionnelle.