# Image applicative du backend Symfony (production).
# FrankenPHP = serveur web + PHP dans un seul conteneur ; il sert /app/public.
FROM dunglas/frankenphp:1-php8.3

# Extensions PHP requises : PostgreSQL (Doctrine), intl (API Platform), opcache/apcu (perf), zip (Composer).
RUN install-php-extensions pdo_pgsql intl opcache apcu zip

# Configuration PHP de production (opcache activé, erreurs masquées).
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# Binaire Composer.
COPY --from=composer/composer:2-bin /composer /usr/bin/composer

WORKDIR /app
ENV APP_ENV=prod
# FrankenPHP écoute en HTTP interne (port 80) ; le HTTPS est terminé par Caddy en frontal.
ENV SERVER_NAME=:80

# 1) Dépendances PHP d'abord (couche cache Docker), sans les paquets de dev ni les scripts.
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-progress --prefer-dist --optimize-autoloader

# 2) Code applicatif.
COPY . .

# 3) Autoload optimisé + dossiers inscriptibles (le cache prod se construit au 1er démarrage).
RUN composer dump-autoload --no-dev --classmap-authoritative \
 && mkdir -p var/cache var/log config/jwt \
 && chown -R www-data:www-data var config/jwt

EXPOSE 80
