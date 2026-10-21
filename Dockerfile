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

# ---- Runtime ---------------------------------------------------------------
FROM serversideup/php:8.3-fpm-nginx-alpine AS base

ENV PHP_OPCACHE_ENABLE=1 \
    AUTORUN_ENABLED=false \
    AUTORUN_LARAVEL_MIGRATION=false \
    NGINX_WEBROOT=/var/www/html/public \
    SSL_MODE=off

USER root
RUN install-php-extensions bcmath intl gd zip pdo_mysql redis pcntl exif \
    && apk add --no-cache mysql-client

WORKDIR /var/www/html
COPY --chown=www-data:www-data . .
COPY --from=vendor --chown=www-data:www-data /app/vendor ./vendor

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
