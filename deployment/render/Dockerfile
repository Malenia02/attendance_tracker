FROM composer:2 AS composer

FROM php:8.3-apache-bookworm

ENV APP_ENV=production \
    APP_DEBUG=false \
    COMPOSER_ALLOW_SUPERUSER=1

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        default-mysql-client \
        git \
        libonig-dev \
        libxml2-dev \
        libzip-dev \
        unzip \
    && docker-php-ext-install -j"$(nproc)" dom mbstring opcache pdo_mysql xml zip \
    && a2enmod expires headers rewrite \
    && sed -ri 's/^ServerTokens .*/ServerTokens Prod/' /etc/apache2/conf-available/security.conf \
    && sed -ri 's/^ServerSignature .*/ServerSignature Off/' /etc/apache2/conf-available/security.conf \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/html

COPY backend/composer.json backend/composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --no-scripts \
    --prefer-dist \
    --optimize-autoloader

COPY backend ./
COPY deployment/render/apache-vhost.conf /etc/apache2/sites-available/000-default.conf
COPY deployment/render/php-production.ini /usr/local/etc/php/conf.d/zz-dilg-production.ini
COPY deployment/render/start.sh /usr/local/bin/dilg-start

RUN composer dump-autoload --no-dev --optimize \
    && mkdir -p \
        storage/app/private/personnel-photos \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod +x /usr/local/bin/dilg-start

EXPOSE 10000

CMD ["dilg-start"]
