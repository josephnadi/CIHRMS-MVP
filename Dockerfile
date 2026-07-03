# syntax=docker/dockerfile:1

# ---- Stage 1: build front-end assets with Vite ----
FROM node:20-bookworm-slim AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
RUN npm run build

# ---- Stage 2: install PHP dependencies ----
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-scripts --no-autoloader --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --optimize --no-dev --classmap-authoritative

# ---- Stage 3: runtime (php-fpm + nginx) ----
FROM php:8.3-fpm-bookworm AS app

# PHP extensions via mlocati installer (pulls the right system libs automatically).
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/bin/
RUN install-php-extensions \
        pdo_pgsql \
        bcmath \
        gd \
        zip \
        intl \
        exif \
        pcntl \
        opcache

# nginx + tini for clean signal handling.
RUN apt-get update \
    && apt-get install -y --no-install-recommends nginx tini \
    && rm -rf /var/lib/apt/lists/*

# Production PHP config.
RUN { \
        echo "memory_limit=512M"; \
        echo "upload_max_filesize=25M"; \
        echo "post_max_size=26M"; \
        echo "opcache.enable=1"; \
        echo "opcache.validate_timestamps=0"; \
        echo "expose_php=Off"; \
    } > /usr/local/etc/php/conf.d/zz-cihrms.ini

WORKDIR /var/www/html

# App code with optimized autoloader (vendor stage already ran dump-autoload).
COPY --from=vendor /app /var/www/html
# Built assets (public/build) from the node stage.
COPY --from=assets /app/public/build /var/www/html/public/build

COPY docker/nginx.conf /etc/nginx/conf.d/default.conf
RUN rm -f /etc/nginx/sites-enabled/default 2>/dev/null || true
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

# Recreate framework scratch dirs (their .gitignore contents are stripped by .dockerignore).
RUN mkdir -p \
        storage/framework/cache \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache

# Writable dirs for the web server user.
RUN chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache

# Web-role process manager: run php-fpm and nginx together; if EITHER exits,
# the script exits so tini reaps and Docker (restart: unless-stopped) restarts
# the whole container — avoids a half-dead web service returning 502s.
RUN printf '#!/bin/bash\nphp-fpm &\nnginx -g "daemon off;" &\nwait -n\nexit $?\n' > /usr/local/bin/serve-web \
    && chmod +x /usr/local/bin/serve-web

EXPOSE 80
ENTRYPOINT ["/usr/bin/tini", "--", "/usr/local/bin/entrypoint"]
CMD ["/usr/local/bin/serve-web"]
