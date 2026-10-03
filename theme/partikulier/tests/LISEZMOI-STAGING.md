# Outils de validation staging — LISEZ-MOI

Ces quatre outils mesurent **depuis l'extérieur** ce qu'un dev senior vérifie
sur un vrai staging (Hostinger) avant une mise en prod. Ils sont dans le thème
`tests/`, ne chargent **rien** sur les pages publiques (le dossier `tests/`
n'est jamais inclus par WordPress), et refusent de s'exécuter par HTTP
(garde CLI : `403` + message) — impossible de transformer le test de charge en
auto-DDoS de votre propre site.

## Prérequis

- PHP en ligne de commande 7.4+ (`php -v`) et curl — sur n'importe quelle
  machine (la vôtre ou celle du dev). Sur le serveur lui-même en SSH, ça marche
  aussi (c'est même la mesure « origine », la plus juste).
- L'URL de base du staging, ex. `https://blanchedalmond-….hostingersite.com`.

## 1. Charge : est-ce que 100 req/s le font tomber ?

```bash
php tests/staging-charge.php https://MON-STAGING 10 10   # ⚅ sondage d'abord, TOUJOURS
php tests/staging-charge.php https://MON-STAGING 100 30  # le vrai test
```

Tire des requêtes au rythme demandé (open-loop : le rythme est tenu côté
client quoi qu'il arrive, donc on OBSERVE la saturation au lieu de la cacher),
mesure p50/p95/p99, compte 2xx/3xx/4xx/5xx et les erreurs réseau, puis rend un
verdict :

- **TENU** — 0 erreur notable et p95 ≤ 800 ms : le site encaisse.
- **TENU-DÉGRADÉ** — il répond, mais la latence s'envole (cache froid ? page
  non cachée ?) : regarder quel chemin ralentit.
- **TOMBÉ** — 5xx ou requêtes sans réponse : noter le seuil qui tient
  (relancer avec la moitié du rps).

Chemins testés par défaut : `/annonces/`, `/`, filtre ville. On peut les
changer en arguments supplémentaires. Lecture importante : ce test frappe le
**chemin chaud** (pages publiques, sans cookie = servies par le cache
LiteSpeed/HCDN) — c'est le contrat ; les pages d'admin ou de formulaire ne sont
pas cachables et ne doivent pas être mises sous charge.

## 2. Cache et rapidité (contrat LOT 3)

```bash
php tests/staging-cache.php https://MON-STAGING
```

Vérifie que le 2ᵉ GET de `/annonces/` est un HIT (`x-litespeed-cache: hit`)
**avec les cartes dedans** (un HIT sur un catalogue vide, c'est le bug 6.17.24
— on vérifie donc le contenu, pas seulement l'en-tête), qu'une fiche répond
200 avec son `og:image` absolue, et la médiane/p95 du TTFB sur 20 GET.
Cible CDC : **< 800 ms mesuré de l'extérieur**, **< 200 ms mesuré à l'origine**
(SSH sur le serveur : `curl -w '%{time_starttransfer}' -o /dev/null URL`).
Si le 1er GET est déjà HIT : purgez dans hPanel (LiteSpeed + HCDN) avant de
relancer, sinon vous ne verrez jamais le MISS.

## 3. Sécurité (contrat LOT 2 + portes classiques)

```bash
PK_SECRET='<le secret n8n>' php tests/staging-securite.php https://MON-STAGING
```

Vérifie en lecture seule : POST automation sans signature = **401**, avec le
secret seul = **401** (l'ancien trou), replay de signature = **401**, HMAC
valide = 200 (métier inchangé) ; sonde absente du front anonyme ; outils de
test inoffensifs par HTTP ; pas d'énumération d'utilisateurs REST ; wp-config,
`.env`, debug.log inaccessibles ; en-têtes de sécurité présents ; santé du
plugin Core. Le secret ne quitte pas la machine qui lance le test. Sans
secret, seuls les tests HMAC dépendants sont sautés.

## 4. Tout d'un coup + rapport

```bash
bash tests/staging-tout.sh https://MON-STAGING 100 30
```

Enchaîne sécurité → cache → charge et écrit `rapport-staging-<date>.md`
à renvoyer tel quel au dev. Codes de sortie utilisables en automatisation.

## Les règles d'or

1. **Sondage d'abord** : `10 req/s pendant 10 s` avant toute charge complète.
2. **Une seule session à la fois**, jamais deux tests en parallèle.
3. **Hors heures de pointe** si le site est public — et prévenir l'hébergeur
   si vous comptez dépasser ~200 reqps soutenus (limites anti-abus Hostinger).
4. Les mesures faites depuis votre machine **incluent votre réseau** ; la
   mesure qui fait foi pour le CDC (< 200 ms) se prend **à l'origine**
   (SSH sur le serveur) ou dans l'onglet Réseau du navigateur.
5. Après toute purge ou correction : relancer `staging-cache.php` avant
   `staging-charge.php` (charger un cache froid mesure le froid, pas le chaud).

## Ce que ces outils ne font pas (volontairement)

- Ils ne purgent rien (la purge est dans hPanel : LiteSpeed + HCDN).
- Ils ne touchent pas aux annonces (le rejeu « validation d'une annonce → HIT
  frais » se fait en admin, cf. RAPPORT-VALIDATION-LOTS-0-3.md § LOT 3).
- Ils n'écrivent rien sur le site : lecture seule, sauf le POST de test HMAC
  (idempotent, journalisé comme doublon par le thème).
