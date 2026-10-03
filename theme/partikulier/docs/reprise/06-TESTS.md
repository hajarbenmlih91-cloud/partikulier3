# Tester son travail

## Monter un environnement local

Le projet a été développé avec un WordPress jetable servi par le serveur intégré de PHP. Le script `setup-stack.sh` (à la racine de l'espace de travail) installe WordPress, Estatik, le thème et des données de démonstration.

```bash
bash setup-stack.sh          # installe la pile complète
```

Il faut ensuite un routeur, car `php -S` ne connaît pas les jolies URL :

```php
<?php // wp/router.php
$u = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$f = __DIR__ . $u;
if ($u !== '/' && file_exists($f) && !is_dir($f)) return false;
$_SERVER['SCRIPT_NAME'] = '/index.php';
require __DIR__ . '/index.php';
```

```bash
cd wp && nohup php -S 0.0.0.0:8090 router.php >/dev/null 2>&1 &
```

Identifiants d'administration : `admin` / `admin`.

## Le cycle de travail

Le code source vit dans `theme/`, le site de test lit dans `wp/wp-content/themes/partikulier/`. **Après chaque modification :**

```bash
rsync -a --delete --exclude '.git' --exclude 'node_modules' \
  /home/user/theme/ /home/user/wp/wp-content/themes/partikulier/
```

Oublier ce `rsync`, c'est tester l'ancienne version et croire que le correctif ne fonctionne pas.

## Configuration minimale après installation

Sans cela, le formulaire refuse tous les dépôts :

```bash
cd wp && php $(which wp) --allow-root eval '
$o = get_option("pk_theme_options", []);
$o["whatsapp_validation_number"] = "212612345678";
$o["n8n_webhook_url"] = "http://127.0.0.1:9099";
$o["automation_api_secret"] = "secret-test";
update_option("pk_theme_options", $o);
do_action("after_switch_theme");
Partikulier_Listing_URLs::flush();
'
```

## Contrôles avant livraison

```bash
cd theme
# PHP : aucune erreur de syntaxe
for f in $(find . -name '*.php' -not -path './.git/*'); do php -l "$f" >/dev/null || echo "ERREUR $f"; done
# JavaScript
node --check assets/js/main.js && node --check assets/js/submit-steps.js
```

Le thème embarque aussi son propre diagnostic : **Partikulier › Diagnostic des pages › Analyser tout le site**.

## Simuler n8n

Un faux serveur suffit à vérifier le webhook sortant :

```php
<?php // n8n/hook.php
file_put_contents(__DIR__.'/received.json', file_get_contents('php://input'));
http_response_code(200); echo '{"ok":true}';
```

```bash
cd n8n && nohup php -S 127.0.0.1:9099 hook.php >/dev/null 2>&1 &
```

Après une validation d'annonce, lire `n8n/received.json` pour voir la charge utile réelle.

## Tests de bout en bout

Playwright est utilisé pour les parcours navigateur. À réinstaller après chaque purge du poste :

```bash
npm install playwright
npx playwright install chromium
npx playwright install-deps chromium
```

Le dossier `theme/tests/` contient des scripts existants : `parcours.mjs`, `audit.mjs`, `securite.mjs`, `visual.mjs`, ainsi que des utilitaires PHP (`diagnostic-site.php`, `reparer-taxonomies.php`, `traduire-annonces.php`).

## Ce qu'il faut vérifier selon le sujet touché

**URL et SEO** — les redirections doivent renvoyer 301, pas 302 :

```bash
for u in "/annonce/casablanca/maarif/mon-bien/" "/property/mon-bien/" "/annonce/faux/mon-bien/"; do
  curl -s -o /dev/null -w "%{http_code} -> %{redirect_url}\n" "http://localhost:8090$u"
done
```

Contrôler aussi la balise canonical, le JSON-LD et la présence des annonces dans `/sitemap.xml`.

**Identifiants** — après une validation, vérifier que le mot de passe transmis fonctionne réellement (`wp_check_password`), qu'il est bien haché en base, et qu'une seconde validation ne le régénère pas.

**Favoris** — cliquer le cœur, recharger la page (il doit rester rouge), recliquer (il doit se vider). Surveiller la console : zéro erreur attendue côté public.

**Écran d'administration** — les erreurs JS de l'admin WordPress en serveur de test sont normales. Comparer avec `/wp-admin/index.php` pour trancher.

## Générer une livraison

```bash
cd theme && zip -rq ../partikulier-X.Y.Z.zip . -x '.git/*' 'node_modules/*' '.DS_Store'
cd .. && unzip -t partikulier-X.Y.Z.zip && sha256sum partikulier-X.Y.Z.zip
```

Penser à aligner la version dans les **quatre** fichiers : `style.css`, `functions.php` (`PARTIKULIER_VERSION`), `package.json`, `readme.txt` (+ changelog).
