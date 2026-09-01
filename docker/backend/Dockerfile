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

EXPOSE 80

ENTRYPOINT ["entrypoint.sh"]
CMD ["apache2-foreground"]
