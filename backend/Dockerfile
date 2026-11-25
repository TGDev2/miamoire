# Image de production de l'API Miamoire.
#
# FrankenPHP — Caddy avec PHP embarqué — remplace à la fois le serveur intégré
# `php -S`, que la documentation PHP réserve au développement, et l'attelage
# nginx + PHP-FPM + superviseur. Un seul processus, un seul conteneur.
#
# Aucune instruction propre à un hébergeur : la même image se déploie sur
# Fly.io, Railway, Render, Koyeb ou Clever Cloud. Elle attend seulement un
# DATABASE_URL et écoute sur $PORT.

# ---------------------------------------------------------------- dépendances
FROM composer:2 AS vendor

WORKDIR /app

# Les dépendances ne changent qu'avec ces deux fichiers : le cache Docker évite
# de retélécharger tout composer à chaque modification du code.
COPY composer.json composer.lock ./

RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --no-interaction

COPY . .

RUN composer dump-autoload --no-dev --optimize --classmap-authoritative

# ------------------------------------------------------------------- exécution
FROM dunglas/frankenphp:1-php8.3

RUN install-php-extensions \
        pdo_pgsql \
        intl \
        zip \
        opcache

# L'image officielle donne au binaire la capacité CAP_NET_BIND_SERVICE pour
# écouter sur les ports 80 et 443. Certains hébergeurs (Render) refusent
# d'exécuter un binaire porteur de capacités : « exec: frankenphp: Operation not
# permitted ». L'API écoute sur $PORT, toujours supérieur à 1024 : la capacité
# est inutile, on la retire pour que l'image démarre partout.
RUN setcap -r /usr/local/bin/frankenphp

# OPcache : sans lui, chaque requête recompile toute l'application.
COPY docker/php.ini /usr/local/etc/php/conf.d/app.ini

WORKDIR /app

COPY --from=vendor /app /app

ENV APP_ENV=prod \
    APP_DEBUG=0

# Le cache Symfony est construit ici plutôt qu'au démarrage : le conteneur
# répond dès la première requête, sans pénalité de démarrage à froid.
RUN php bin/console cache:warmup --env=prod \
    && mkdir -p var/cache var/log \
    && chown -R www-data:www-data var

COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

# Les hébergeurs imposent le port par leur variable PORT ; 8080 est le repli.
ENV PORT=8080 \
    SERVER_NAME=:8080

EXPOSE 8080

ENTRYPOINT ["entrypoint"]
