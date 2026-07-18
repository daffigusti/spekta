# Stage 1: build aset Vite (mandiri — tidak butuh vendor/, lihat docs/superpowers/specs/2026-07-19-dokploy-docker-deploy-design.md)
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY vite.config.js tsconfig.json ./
COPY public ./public
COPY resources ./resources
RUN npm run build

# Stage 2: runtime PHP (nginx + fpm, healthcheck bawaan, listen 8080 sebagai www-data)
FROM serversideup/php:8.4-fpm-nginx AS app
USER root
# pdo_pgsql: Postgres; gd: dompdf; zip: phpspreadsheet/phpword
RUN install-php-extensions pdo_pgsql gd zip intl bcmath
USER www-data
WORKDIR /var/www/html

COPY --chown=www-data:www-data composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --no-autoloader

COPY --chown=www-data:www-data . .
COPY --chown=www-data:www-data --from=assets /app/public/build ./public/build
# dump-autoload memicu post-autoload-dump (package:discover)
RUN composer dump-autoload --optimize \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs storage/app/public storage/app/private
