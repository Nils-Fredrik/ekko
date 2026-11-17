# Adapted from Varde: one PHP base, separate dependency and asset stages.
FROM php:8.4-apache-trixie@sha256:901b0dbcd2419cc9cd307ea05e57403449ce722ccd5b32335da6e8d39f2b1ee0 AS base
COPY --from=mlocati/php-extension-installer:2@sha256:1afade3e29cfc97362cf5885e5ac333bf2faab1146cb28ebbb59b17e68f87e88 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_pgsql intl bcmath zip pcntl opcache \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && printf 'zend.exception_ignore_args = On\n' > "$PHP_INI_DIR/conf.d/zz-ekko.ini" \
    && a2enmod rewrite headers \
    && sed -i 's/^Listen 80$/Listen 8080/' /etc/apache2/ports.conf
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
WORKDIR /var/www/html

FROM base AS vendor
COPY --from=composer:2@sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --no-autoloader

FROM node:24-trixie-slim@sha256:173f125896c3b47ddf056734c7ea789d04595a6a08769a8f78e0df642781fb66 AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY --from=vendor /var/www/html/vendor ./vendor
COPY vite.config.js jsconfig.json ./
COPY resources ./resources
COPY lang ./lang
COPY public ./public
RUN npm run build

FROM base AS app
COPY --from=composer:2@sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac /usr/bin/composer /usr/bin/composer
COPY --from=vendor /var/www/html/vendor ./vendor
COPY . .
COPY --from=assets /app/public/build ./public/build
RUN composer dump-autoload --optimize --no-dev --no-interaction \
    && rm /usr/bin/composer \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache
EXPOSE 8080
USER www-data
CMD ["apache2-foreground"]

FROM app AS web

FROM app AS jobs
USER root
RUN apt-get update && apt-get install -y --no-install-recommends chromium \
    && rm -rf /var/lib/apt/lists/*
ENV CHROME_PATH=/usr/bin/chromium
USER www-data
