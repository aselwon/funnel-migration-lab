FROM node:22-alpine AS ui
WORKDIR /build
COPY frontend/package*.json ./
RUN npm ci
COPY frontend/ ./
RUN npm run build

FROM php:8.3-cli AS base
RUN apt-get update && apt-get install -y --no-install-recommends libonig-dev libxml2-dev unzip && docker-php-ext-install pdo_mysql mbstring dom xml xmlwriter && rm -rf /var/lib/apt/lists/*
RUN mkdir -p /var/lib/php/sessions && echo 'session.save_path=/var/lib/php/sessions' > /usr/local/etc/php/conf.d/session.ini
WORKDIR /var/www/html
COPY src ./src
COPY public ./public
COPY docker/schema.sql ./docker/schema.sql
EXPOSE 8080
CMD ["php", "-S", "0.0.0.0:8080", "-t", "public", "public/router.php"]

FROM base AS test
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist
COPY tests ./tests
COPY phpunit.xml ./
CMD ["vendor/bin/phpunit"]

FROM base AS legacy

FROM base AS hybrid
COPY --from=ui /public/app ./public/app
