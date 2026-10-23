# syntax=docker/dockerfile:1.7
#
# Fleetbase Registry Service
#   app      : local development / generic container (nginx + php-fpm)
#   aws-app  : same image wrapped with ssm-parent so env comes from SSM on ECS
#
FROM ghcr.io/springload/ssm-parent:1.8.3 AS ssm-parent

# ---- Composer dependencies -------------------------------------------------
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN --mount=type=cache,target=/tmp/cache \
    composer install --no-dev --no-interaction --no-scripts --prefer-dist --no-progress --ignore-platform-reqs

# ---- Website assets ---------------------------------------------------------
# Vite builds the Inertia (React) client into public/build and the server-side renderer into
# bootstrap/ssr (one self-contained file; the runtime runs it with Node, no node_modules).
FROM node:22-bookworm-slim AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN --mount=type=cache,target=/root/.npm \
    npm ci --no-audit --no-fund
COPY vite.config.js ./
COPY resources ./resources
RUN npm run build

# ---- Runtime ---------------------------------------------------------------
FROM serversideup/php:8.3-fpm-nginx-alpine AS base

# Release uploads: two 50 MB archives plus a 100 MB prebuilt dist (config/registry.php).
ENV PHP_OPCACHE_ENABLE=1 \
    PHP_UPLOAD_MAX_FILE_SIZE=110M \
    PHP_POST_MAX_SIZE=220M \
    AUTORUN_ENABLED=false \
    AUTORUN_LARAVEL_MIGRATION=false \
    NGINX_WEBROOT=/var/www/html/public \
    SSL_MODE=off

USER root
RUN install-php-extensions bcmath intl gd zip pdo_mysql redis pcntl exif \
    && apk add --no-cache mysql-client nodejs

# Inertia SSR as an s6 service beside nginx and php-fpm (docker/s6/inertia-ssr/run).
COPY --chmod=755 docker/s6/inertia-ssr /etc/s6-overlay/s6-rc.d/inertia-ssr
RUN touch /etc/s6-overlay/user-bundles.d/user/contents.d/inertia-ssr

WORKDIR /var/www/html
COPY --chown=www-data:www-data . .
COPY --from=vendor --chown=www-data:www-data /app/vendor ./vendor
COPY --from=assets --chown=www-data:www-data /app/public/build ./public/build
COPY --from=assets --chown=www-data:www-data /app/bootstrap/ssr ./bootstrap/ssr

RUN composer dump-autoload --optimize --classmap-authoritative --no-dev 2>/dev/null || true \
    && mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

USER www-data
EXPOSE 8080

# ---- Local / generic -------------------------------------------------------
FROM base AS app
ENV AUTORUN_ENABLED=true \
    AUTORUN_LARAVEL_MIGRATION=true

# ---- AWS ECS ---------------------------------------------------------------
FROM base AS aws-app
USER root
COPY --from=ssm-parent /usr/bin/ssm-parent /usr/bin/ssm-parent
USER www-data
ENV AUTORUN_ENABLED=true \
    AUTORUN_LARAVEL_MIGRATION=true
ENTRYPOINT ["/usr/bin/ssm-parent", "run", "-e", "-n", "$SSM_PARAMETER", "--", "docker-php-serversideup-entrypoint"]
CMD ["s6-svscan", "/etc/s6-overlay/s6-rc.d"]
