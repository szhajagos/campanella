# Campanella fejlesztői/teszt környezet: PHP 8.3 + Apache.
#
# A projekt a /var/www/html alá kerül, a webgyökér pedig a public/ mappa,
# így a src/, config/, vendor/ kívülről nem érhető el.
FROM php:8.3-apache

# pdo_mysql: az adatbázishoz; mod_rewrite: a .htaccess útvonal-átirányításához.
RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install pdo_mysql \
    && a2enmod rewrite

# A hivatalos PHP image dokumentációja szerinti módszer a webgyökér áthelyezésére.
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Előbb csak a függőségek, hogy a Docker gyorsítótárazni tudja ezt a lépést.
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-interaction --no-scripts --no-autoloader --prefer-dist

COPY . .
RUN composer dump-autoload --optimize --no-dev \
    && mkdir -p var/cache \
    && chown -R www-data:www-data var
