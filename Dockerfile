# Campanella development/test environment: PHP 8.3 + Apache.
#
# The project goes under /var/www/html and the web root is the public/ folder,
# so src/, config/, vendor/ are not reachable from outside.
FROM php:8.3-apache

# pdo_mysql: for the database; mod_rewrite: for the .htaccess URL rewriting.
RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install pdo_mysql \
    && a2enmod rewrite

# Moving the web root as described in the official PHP image documentation.
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Dependencies first, so Docker can cache this step.
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-interaction --no-scripts --no-autoloader --prefer-dist

COPY . .
RUN composer dump-autoload --optimize --no-dev \
    && mkdir -p var/cache \
    && chown -R www-data:www-data var
