FROM php:8.4-apache

# --- Dépendances système --------------------------------------------------
RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libpq-dev \
        libicu-dev \
        libzip-dev \
        zip \
        libpng-dev \
        libjpeg-dev \
        libfreetype6-dev \
    && rm -rf /var/lib/apt/lists/*

# --- Extensions PHP --------------------------------------------------------
# gd: hard requirement of phpoffice/phpspreadsheet (A2.3, XLSX export) -
# not otherwise used by this app.
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo pdo_pgsql intl opcache zip gd

# --- Apache : mod_rewrite + vhost pointant vers public/ --------------------
RUN a2enmod rewrite
COPY docker/backend/vhost.conf /etc/apache2/sites-available/000-default.conf
# Audit pre-production 2026-09-23 (P2) : masque les en-tetes Server/
# X-Powered-By (fingerprinting de version). Prefixe "zzz-" necessaire :
# conf-enabled/*.conf se charge par ordre alphabetique, et le
# security.conf par defaut de l'image (deja active, ServerTokens OS) sort
# AVANT "security-nev.conf" (le tiret '-' < le point '.' en ASCII) - sans
# ce prefixe, le reglage par defaut ecrasait silencieusement le notre.
COPY docker/backend/security.conf /etc/apache2/conf-available/zzz-security-nev.conf
RUN a2enconf zzz-security-nev
COPY docker/backend/security.ini /usr/local/etc/php/conf.d/zz-security-nev.ini

# --- Composer ----------------------------------------------------------------
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copie des seuls manifestes Composer d'abord (cache de layer Docker)
COPY backend/composer.json backend/composer.lock* ./
RUN composer install --no-scripts --no-interaction --no-progress --prefer-dist

# Copie du reste du code applicatif
COPY backend/ ./

RUN composer dump-autoload --optimize \
    && mkdir -p var/cache var/log \
    && chown -R www-data:www-data var

COPY docker/backend/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Audit pre-production 2026-09-23 (P2) : contrairement a ai/Dockerfile/
# pipeline/Dockerfile/docker/db-backup/Dockerfile (corriges le meme jour),
# aucun USER non-root n'est ajoute ici deliberement. Apache doit demarrer
# root pour se lier au port privilegie 80 (<1024) ; il degrade ensuite
# lui-meme ses processus worker vers "www-data" en interne (directive
# "User www-data" du paquet Debian, deja active par defaut) - c'est deja
# une separation de privileges reelle, juste invisible comme un "USER"
# Docker explicite. Passer ce Dockerfile en non-root demanderait de faire
# ecouter Apache sur un port >1024 (ex. 8080) et d'ajuster le mapping de
# port cote docker-compose.yml/production (Render) en consequence - un
# changement plus large, non fait ici pour rester dans le perimetre de
# cette passe de durcissement.
EXPOSE 80

ENTRYPOINT ["entrypoint.sh"]
CMD ["apache2-foreground"]
