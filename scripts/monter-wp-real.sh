#!/bin/bash
set -e

echo "=== Démarrage de l'environnement de test WordPress réel ==="
sudo service mariadb status >/dev/null 2>&1 || sudo service mariadb start >/dev/null 2>&1 || sudo /usr/bin/mariadbd-safe --user=mysql &
sleep 2

mariadb -u root -proot -e "CREATE DATABASE IF NOT EXISTS partikulier;"

if [ ! -f /home/user/wp-real/wp-load.php ]; then
    echo "Téléchargement / Initialisation de WordPress Core..."
    mkdir -p /home/user/wp-real
    cd /home/user/wp-real
    wp core download --version=6.6 --locale=fr_FR --allow-root || wp core download --version=6.6 --allow-root
    wp config create --dbname=partikulier --dbuser=root --dbpass=root --dbhost=localhost --allow-root
    wp core install --url="http://localhost" --title="Partikulier" --admin_user="admin" --admin_password="password123" --admin_email="admin@partikulier.local" --allow-root
fi

echo "Liaison des sources du thème et du plugin..."
mkdir -p /home/user/wp-real/wp-content/themes /home/user/wp-real/wp-content/plugins
rm -rf /home/user/wp-real/wp-content/themes/partikulier /home/user/wp-real/wp-content/plugins/partikulier-core
cp -r /home/user/partikulier3/theme/partikulier /home/user/wp-real/wp-content/themes/
cp -r /home/user/partikulier3/plugin/partikulier-core /home/user/wp-real/wp-content/plugins/

cd /home/user/wp-real
wp theme activate partikulier --allow-root || true
wp plugin activate partikulier-core --allow-root || true

php -r "require '/home/user/wp-real/wp-load.php'; \$m = new Partikulier\Core\Database\Migrator(); \$m->migrate();"

echo "=== Environnement WordPress opérationnel ==="
