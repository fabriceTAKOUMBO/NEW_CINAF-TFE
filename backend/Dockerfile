# Image applicative du backend Symfony (production).
# FrankenPHP = serveur web + PHP dans un seul conteneur ; il sert /app/public.
FROM dunglas/frankenphp:1-php8.3

# Extensions PHP requises : PostgreSQL (Doctrine), intl (API Platform), opcache/apcu (perf), zip (Composer).
RUN install-php-extensions pdo_pgsql intl opcache apcu zip

# Configuration PHP de production (opcache activé, erreurs masquées).
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# OPcache prod : le code ne change jamais à chaud dans l'image (tout déploiement
# = nouvelle image), donc pas de re-vérification des timestamps — sinon PHP fait
# un stat() par fichier inclus (~900) à chaque requête. Cache mémoire élargi et
# cache des chemins réels allongé pour la même raison.
RUN { \
      echo 'opcache.validate_timestamps=0'; \
      echo 'opcache.memory_consumption=256'; \
      echo 'opcache.max_accelerated_files=20000'; \
      echo 'realpath_cache_size=4096K'; \
      echo 'realpath_cache_ttl=600'; \
    } > "$PHP_INI_DIR/conf.d/zz-opcache-prod.ini"

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
