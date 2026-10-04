# Local test image for Partikulier (plugin partikulier-core 2.10.16 + theme 6.20.11).
# Mirrors the "Contrats de recette (WordPress)" CI job: WordPress 6.6, PHP 8.3,
# MySQL 8.0, extensions mbstring/mysqli/curl/apcu, WP-CLI, site served on :8099.
ARG WP_IMAGE_TAG=6.6-php8.3-apache
FROM wordpress:${WP_IMAGE_TAG}

# Optional AVIF/HEIC toolchain used by theme/inc/class-avif.php through the
# exec whitelist (avifenc / vips). Off by default: the theme degrades cleanly.
ARG WITH_AVIF_TOOLS=0

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends less default-mysql-client unzip git; \
    if [ "${WITH_AVIF_TOOLS}" = "1" ]; then \
        apt-get install -y --no-install-recommends libavif-bin libvips-tools; \
    fi; \
    pecl install apcu; \
    docker-php-ext-enable apcu; \
    rm -rf /var/lib/apt/lists/* /tmp/pear

# WP-CLI (same tool the CI uses to install and drive WordPress).
RUN set -eux; \
    curl -fsSL https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar -o /usr/local/bin/wp; \
    chmod +x /usr/local/bin/wp; \
    wp --allow-root --info

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-partikulier.ini
COPY docker/wp-init.sh /usr/local/bin/pk-wp-init
COPY docker/local-entrypoint.sh /usr/local/bin/pk-local-entrypoint
RUN sed -i 's/\r$//' /usr/local/bin/pk-wp-init /usr/local/bin/pk-local-entrypoint \
    && chmod +x /usr/local/bin/pk-wp-init /usr/local/bin/pk-local-entrypoint

ENTRYPOINT ["pk-local-entrypoint"]

# Serve on 8099 INSIDE the container too, so home_url() (http://localhost:8099)
# is reachable both from the host and from the HTTP contract suites run in the
# container (redirect_canonical keeps the port — see contrats-recette.yml).
RUN set -eux; \
    sed -ri 's/^Listen 80$/Listen 8099/' /etc/apache2/ports.conf; \
    sed -ri 's/<VirtualHost \*:80>/<VirtualHost *:8099>/' /etc/apache2/sites-available/000-default.conf

EXPOSE 8099
CMD ["apache2-foreground"]
