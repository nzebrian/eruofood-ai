# syntax=docker/dockerfile:1.7
# =============================================================================
# EruoFood AI — PHP-FPM 8.4 image for the Laravel modular monolith.
# Multi-stage: (1) composer deps, (2) hardened runtime. Non-root, OPcache+JIT.
# The same image runs the API (fpm), queue workers, scheduler, and Reverb.
# =============================================================================

ARG PHP_IMAGE_TAG=8.4-fpm-alpine

# ---- Stage 1: system + PHP extensions base ----------------------------------
FROM php:${PHP_IMAGE_TAG} AS base

# Build deps for extensions (removed after install to keep image small)
RUN apk add --no-cache \
        bash git icu-libs libpq libzip postgresql-libs \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS icu-dev libzip-dev postgresql-dev linux-headers \
    && docker-php-ext-configure intl \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql pgsql intl zip bcmath pcntl opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps

# Composer binary (from official image)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Production PHP + OPcache config
COPY infra/docker/php/php.ini /usr/local/etc/php/php.ini
COPY infra/docker/php/opcache.ini /usr/local/etc/php/conf.d/opcache.ini
COPY infra/docker/php/www.conf /usr/local/etc/php-fpm.d/www.conf

WORKDIR /var/www/html

# ---- Stage 2: vendor dependencies -------------------------------------------
# Install production dependencies from the committed composer.lock. We defer the
# autoloader (--no-autoloader) because the optimized classmap needs the app
# source (app/, database/, routes/), which is copied in the runtime stage. Fail
# loudly — no "|| true": a broken vendor tree must break the build, not ship a
# half-populated image that only explodes at `artisan` runtime.
FROM base AS vendor
COPY apps/api/composer.json apps/api/composer.lock ./
RUN --mount=type=cache,target=/tmp/composer-cache \
    COMPOSER_CACHE_DIR=/tmp/composer-cache \
    composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --no-interaction \
        --no-progress

# ---- Stage 3: runtime -------------------------------------------------------
FROM base AS runtime

# Create unprivileged user matching typical host UID for bind mounts
ARG UID=1000
ARG GID=1000
RUN addgroup -g ${GID} app 2>/dev/null || true \
    && adduser -D -u ${UID} -G app app 2>/dev/null || true

# Bring in the resolved dependency tree, then the application source.
COPY --from=vendor /var/www/html/vendor ./vendor
COPY apps/api ./

# Generate the optimized, authoritative classmap now that both vendor/ and the
# app source are present. --no-scripts keeps the build hermetic (package:discover
# boots artisan, which needs runtime env); Laravel rediscovers packages from
# vendor/composer/installed.json at boot. No "|| true": a failed dump must fail
# the build. Verify the autoloader exists before the image is allowed to finish.
RUN composer dump-autoload --optimize --no-dev --no-scripts \
    && test -f /var/www/html/vendor/autoload.php \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R app:app /var/www/html

USER app

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD php -v > /dev/null 2>&1 || exit 1

EXPOSE 9000
CMD ["php-fpm"]
