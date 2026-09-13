# Note de migration — authentification de l'effacement de lead (SE-016)

**S'applique à** : partikulier-core ≥ 2.10.5 · **Concerne** : les workflows n8n
qui appellent `POST /wp-json/partikulier/v1/erase-lead`.
**Nature** : BREAKING CHANGE assumé, avec fenêtre de transition d'une version.

## Ce qui change

La route d'effacement de lead exige désormais une **preuve de possession d'un
secret** avant d'exécuter la moindre suppression. Auparavant, la route était
servie sur la seule confiance réseau (l'orchestrateur était supposé être le
seul appelant) ; la défense en profondeur impose désormais une garde, comme
sur toutes les autres routes d'écriture de l'API.

Les appels **sans secret valide** reçoivent `401` ; les tentatives répétées
sont bornées (voir « Limiteur » ci-dessous).

## Ce que n8n doit envoyer

Deux formes équivalentes :

```
POST /wp-json/partikulier/v1/erase-lead
Content-Type: application/json
X-Partikulier-Lead-Erase: <secret>
```

ou

```
POST /wp-json/partikulier/v1/erase-lead
Content-Type: application/json
Authorization: Bearer <secret>
```

Le corps est inchangé : `{"wa_id": "<numéro WhatsApp>"}`. La sémantique de la
réponse est inchangée : `400` si `wa_id` est absent, `200 {"erased": true}`
dans tous les autres cas (idempotence RGPD conservée — la réponse ne révèle
pas si le numéro était connu).

## Où poser le secret

Le secret dédié vit dans l'option WordPress `lead_erase_api_secret` :

```bash
wp option update lead_erase_api_secret '<secret généré>'
```

Recommandation de génération : 32 octets aléatoires minimum, par exemple
`openssl rand -base64 48` ou
`wp eval 'echo bin2hex(random_bytes(32));'`.

Ce secret est **indépendant** du secret n8n (`automation_api_secret`) : il
peut tourner sans toucher l'automatisation sortante, et son périmètre se
limite à cette seule opération d'effacement.

## Fenêtre de transition (une version)

Pour ne pas casser les workflows existants avant que le nouveau secret soit
posé, l'hébergement peut activer une transition explicite :

```bash
wp option update lead_erase_transition_active 1
```

Tant que cette option vaut `1` :

- l'ancien secret n8n (`automation_api_secret`) reste **accepté** sur cette
  route — chaque usage est journalisé comme **déprécié** dans le registre
  d'audit (`lead_erase_secret_deprecated`) ;
- le seuil du limiteur d'échecs est relevé de 10 à 100 échecs/heure, pour que
  les redispositions de l'orchestrateur ne soient pas bloquées pendant la
  bascule ; chaque requête traitée sous le régime assoupli est journalisée
  (`lead_erase_transition_relaxed`).

Dès que les workflows n8n envoient le nouveau header :

```bash
wp option delete lead_erase_transition_active
```

La version suivante retirera l'acceptation du secret n8n (rejet strict) et
l'assouplissement du limiteur ne pourra plus être réactivé que volontairement.

## Limiteur anti-forçage

10 échecs d'authentification par heure et par adresse IP → `429` au-delà.
Le seuil, la fenêtre et une éventuelle liste d'IP de confiance sont filtrables
(utile derrière un reverse proxy, où l'IP vue par WordPress est celle du
proxy) :

```php
add_filter('partikulier_lead_erase_rate_limit', function ($policy) {
    $policy['exempt_ips'] = ['10.0.0.5']; // orchestrateur
    return $policy;
});
```

Un appel **réussi** remet le compteur d'échecs de l'IP à zéro : l'orchestrateur
légitime n'est jamais pénalisé par les échecs d'un tiers.

## Journal d'audit

Chaque tentative est consignée dans le registre d'audit du domaine leads
(`pk_audit_log`) :

| Action | Sens |
| --- | --- |
| `lead_erase_authorized` | appel accepté (avec la via : `dedicated` ou `automation_transition`) |
| `lead_erase_auth_failed` | secret absent ou invalide |
| `lead_erase_flood` | refus de flux (429) |
| `lead_erase_secret_deprecated` | secret n8n accepté pendant la transition |
| `lead_erase_transition_relaxed` | requête traitée sous le seuil assoupli |

Ces entrées permettent l'audit a posteriori de la fenêtre de transition et le
suivi des tentatives suspectes.
