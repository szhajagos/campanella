# Campanella development/test environment: PHP 8.3 + Apache.
#
# The project goes under /var/www/html and the web root is the public/ folder,
# so src/, config/, vendor/ are not reachable from outside.
FROM php:8.3-apache

# pdo_mysql: for the database; mod_rewrite and mod_headers: for the .htaccess files.
# gd (with JPEG, PNG and WebP) and exif: for re-encoding uploaded images.
RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip libjpeg62-turbo-dev libpng-dev libwebp-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install pdo_mysql gd exif \
    && rm -rf /var/lib/apt/lists/* \
    && a2enmod rewrite headers

# Upload and memory limits for images (see docker/php.ini).
COPY docker/php.ini /usr/local/etc/php/conf.d/campanella.ini

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
    && mkdir -p var/cache var/backups \
    && chown -R www-data:www-data var
