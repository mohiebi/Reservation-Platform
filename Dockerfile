FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --no-scripts

FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY resources ./resources
COPY public ./public
COPY components.json vite.config.ts tsconfig.json ./
RUN npm run build

FROM php:8.4-fpm-alpine AS app

RUN apk add --no-cache \
    bash \
    icu-dev \
    libzip-dev \
    oniguruma-dev \
    postgresql-dev \
    supervisor \
    && docker-php-ext-install intl mbstring opcache pcntl pdo pdo_mysql pdo_pgsql zip

WORKDIR /var/www/html

COPY --chown=www-data:www-data . .
COPY --from=vendor --chown=www-data:www-data /app/vendor ./vendor
COPY --from=assets --chown=www-data:www-data /app/public/build ./public/build
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/opcache.ini
COPY docker/supervisor/worker.conf /etc/supervisor/conf.d/worker.conf

RUN chmod -R ug+rw storage bootstrap/cache

USER www-data

CMD ["php-fpm"]
