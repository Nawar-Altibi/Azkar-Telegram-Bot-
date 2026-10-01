FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./
COPY . .

RUN composer install \
        --no-dev \
        --no-interaction \
        --prefer-dist \
        --optimize-autoloader \
        --no-scripts \
        --ignore-platform-req=ext-gd \
    && rm -f bootstrap/cache/packages.php bootstrap/cache/services.php bootstrap/cache/config.php \
    && php -r "file_exists('.env') || copy('.env.example', '.env');" \
    && php artisan key:generate --force --ansi \
    && php artisan package:discover --ansi \
    && rm -f .env bootstrap/cache/config.php

FROM php:8.3-fpm-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        curl \
        libfreetype6 \
        libfreetype6-dev \
        libjpeg62-turbo \
        libjpeg62-turbo-dev \
        libpng16-16 \
        libpng-dev \
        libpq5 \
        libpq-dev \
        nginx \
        procps \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_mysql pdo_pgsql bcmath opcache gd \
    && apt-get purge -y --auto-remove libpq-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
    && rm -rf /var/lib/apt/lists/* \
    && rm -f /etc/nginx/sites-enabled/default

WORKDIR /var/www/html

COPY --from=vendor /app /var/www/html
COPY docker/nginx.conf /etc/nginx/sites-available/azkar.conf
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-azkar.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/azkar.ini
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh

RUN ln -sf /etc/nginx/sites-available/azkar.conf /etc/nginx/sites-enabled/azkar.conf \
    && sed -i 's/\r$//' /usr/local/bin/entrypoint.sh \
    && chmod +x /usr/local/bin/entrypoint.sh \
    && mkdir -p \
        storage/app/public \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwx storage bootstrap/cache

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["web"]
