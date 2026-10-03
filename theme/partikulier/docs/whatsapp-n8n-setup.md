# Connexion WhatsApp Business et n8n

Le thème Partikulier expose une couche de qualification dans WordPress ; il ne contacte jamais WhatsApp directement. Cette séparation maintient le portail comme source de vérité des annonces, des limites, du consentement et de l’historique. **n8n** orchestre seulement les messages, via l’API officielle WhatsApp Business Cloud.

## 1. Réglages WordPress

Dans **Apparence → Personnaliser → Partikulier — Textes du site → Validation WhatsApp**, renseigner :

| Réglage | Valeur attendue |
| --- | --- |
| Numéro WhatsApp Business des demandes acquéreurs | Numéro international sans `+` ni espace, par exemple `221770000000` |
| Secret API n8n / WhatsApp Business | Secret long, aléatoire et unique, communiqué seulement à n8n |

Le secret n’est jamais injecté dans le navigateur. Le lien public contient une référence d’annonce telle que `PK-123-ABCD`, mais aucune coordonnée propriétaire.

## 2. Workflow n8n à construire

> Utiliser une instance n8n maintenue, avec HTTPS, sauvegardes chiffrées et une URL de webhook stable. Le webhook Meta ne doit pas pointer vers WordPress.

| Étape | Nœud n8n | Comportement |
| --- | --- | --- |
| 1 | Webhook Meta WhatsApp | Reçoit un message entrant et son `message_id` |
| 2 | Code | Extrait la référence `PK-…` du texte et normalise `wa_id` |
| 3 | HTTP Request | `POST /wp-json/partikulier/v1/contact-authorization` avec l’en-tête `X-Partikulier-Automation` |
| 4 | IF | Si `allowed=true`, envoie nom et téléphone du propriétaire ; sinon explique la limite ou l’indisponibilité |
| 5 | WhatsApp Cloud | Demande budget, zones et configuration du logement |
| 6 | HTTP Request | `POST /wp-json/partikulier/v1/preferences` après la réponse explicite de l’acquéreur |
| 7 | WhatsApp Cloud | Demande séparément : « Souhaitez-vous recevoir des biens similaires ? Répondez OUI ou NON. » |
| 8 | HTTP Request | `POST /wp-json/partikulier/v1/consent` avec `granted=true` seulement après un **OUI** clair |
| 9 | Code + HTTP Request | Si le message est `STOP`, appeler `POST /wp-json/partikulier/v1/opt-out` avant toute autre action |

Les appels WordPress doivent utiliser `Content-Type: application/json` et l’en-tête :

\`\`\`http
X-Partikulier-Automation: <secret configuré dans WordPress>
\`\`\`

## 3. Contrats d’API

### Autoriser une mise en relation

\`\`\`json
POST /wp-json/partikulier/v1/contact-authorization
{
  "wa_id": "221770000000",
  "reference": "PK-123-ABCD",
  "provider_message_id": "wamid.…"
}
\`\`\`

Une réponse `allowed: true` retourne les coordonnées propriétaire. Une réponse `reason: daily_limit` signifie que l’acquéreur a déjà reçu les coordonnées de **deux propriétaires distincts** ce jour-là. Le même propriétaire peut avoir plusieurs annonces sans consommer une place supplémentaire. `duplicate_message` est un rejeu sûr du même webhook.

### Enregistrer les critères déclarés

\`\`\`json
POST /wp-json/partikulier/v1/preferences
{
  "wa_id": "221770000000",
  "budget_max": 280000,
  "areas": ["Plateau", "Almadies"],
  "layout": "2 chambres + salon",
  "transaction": "Achat"
}
\`\`\`

### Consentement et opposition

\`\`\`json
POST /wp-json/partikulier/v1/consent
{ "wa_id": "221770000000", "scope": "similar_listings", "granted": true, "provider_message_id": "wamid.…" }

POST /wp-json/partikulier/v1/opt-out
{ "wa_id": "221770000000", "provider_message_id": "wamid.…" }
\`\`\`

`STOP` doit retirer le consentement aux annonces similaires et empêcher de nouvelles mises en relation jusqu’à une nouvelle demande explicite. Ne pas envoyer d’annonces similaires sans consentement enregistré. En dehors de la fenêtre de conversation applicable à WhatsApp Business, utiliser uniquement un modèle Meta approuvé.

## 4. Exploitation et sécurité

Le numéro WhatsApp est stocké sous forme de hachage pour les limites et, lorsque OpenSSL est disponible, chiffré pour l’audit restreint. Les messages entrants, consentements et transmissions sont journalisés. Ne pas mettre les coordonnées propriétaire dans l’URL, dans des champs front-end ou dans les journaux n8n non chiffrés.

Avant production, vérifier les règles Meta applicables, publier une politique de confidentialité, définir les durées de conservation, limiter les accès n8n et tester l’opposition `STOP` sur le vrai numéro Business.
---

## Envoi des identifiants à la validation d'une annonce

Ce flux est distinct de la qualification des acquéreurs décrite ci-dessus. Il se déclenche quand un administrateur valide un dépôt depuis **Partikulier → Valider les annonces**.

### Réglages

| Réglage | Emplacement |
| --- | --- |
| URL du webhook n8n | *Apparence → Personnaliser → Validation WhatsApp → URL du webhook n8n* |
| Secret API | même écran, réutilisé comme en-tête `X-Partikulier-Automation` |

### Ce que WordPress envoie

`POST` sur l'URL configurée, en-tête `X-Partikulier-Automation: <secret>` :

```json
{
  "event": "listing_approved",
  "listing": { "id": 38, "title": "…", "url": "…", "price": "6500" },
  "owner":   { "name": "…", "phone": "0669876543", "email": "…" },
  "account": {
    "login": "sara2749216",
    "password": "Uu67enJN2P",
    "login_url": "https://exemple.ma/wp-login.php",
    "send_credentials": true
  },
  "sent_at": "2026-08-19 05:31:19"
}
```

**Le champ `send_credentials` pilote le message à envoyer.**

- `true` : l'annonceur reçoit ses identifiants pour la première fois. Envoyer identifiant, mot de passe et adresse de connexion.
- `false` : l'annonceur a déjà reçu ses accès (`password` est alors vide). Envoyer un simple message de mise en ligne, **sans identifiants**.

Ignorer ce champ enverrait un message avec un mot de passe vide.

### Rattrapage si n8n était indisponible

```
GET /wp-json/partikulier/v1/approved-listings
En-tête : X-Partikulier-Automation: <secret>
```

Renvoie les validations des 72 dernières heures, chacune avec un drapeau `webhook_sent` permettant de ne traiter que ce qui a été manqué. Sans en-tête valide, la route répond `401`.

**Cette route ne renvoie jamais de mot de passe.** Un identifiant interrogeable pendant 72 heures annulerait la confidentialité. Si des identifiants doivent être renvoyés, l'administrateur utilise le bouton « Nouveau mot de passe » de l'écran de validation : un mot de passe est régénéré et un nouveau webhook part.

### Note de sécurité

Le mot de passe transmis reste lisible dans la conversation WhatsApp de l'annonceur. C'est un choix produit assumé : il retrouve ses accès à tout moment sans dépendre d'un lien expiré. Côté site, seul le condensat est stocké ; le mot de passe en clair n'existe qu'en mémoire, le temps de l'appel à n8n.
