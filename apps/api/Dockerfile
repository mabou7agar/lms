# syntax=docker/dockerfile:1
# HElbaron API — production image (PHP 8.3 FPM, OPcache, no dev deps).

# ---- Stage 1: composer deps (no dev) ----
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
# Download-only stage: the composer:2 image ships a minimal PHP without ext-intl/ext-pcntl (required
# by filament/support and laravel/horizon), so platform checks are ignored HERE. The runtime stage
# below installs those extensions (intl, pcntl, …), so the actual image satisfies every requirement.
RUN composer install --no-dev --prefer-dist --no-scripts --no-autoloader --no-interaction --ignore-platform-reqs

# ---- Stage 2: runtime ----
FROM php:8.3-fpm-alpine AS runtime

# Patch OS packages to their latest security-fixed versions (addresses fixable CRITICAL/HIGH OS
# CVEs the base image may lag on; the image is Trivy-scanned with ignore-unfixed).
RUN apk --no-cache upgrade

# Pinned installer release (supply-chain: never track "latest").
ADD https://github.com/mlocati/docker-php-extension-installer/releases/download/2.7.14/install-php-extensions /usr/local/bin/
RUN chmod +x /usr/local/bin/install-php-extensions \
    && install-php-extensions pdo_pgsql pgsql mbstring bcmath intl zip pcntl opcache redis \
    && apk add --no-cache fcgi

WORKDIR /var/www/html

# App code + vendored deps, then optimized autoloader.
COPY --from=vendor /app/vendor ./vendor
COPY . .
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN composer dump-autoload --optimize --no-dev --classmap-authoritative \
    && chown -R www-data:www-data storage bootstrap/cache

# Production PHP + OPcache tuning.
COPY infra/php/opcache.ini /usr/local/etc/php/conf.d/zz-opcache.ini
COPY infra/php/php.ini /usr/local/etc/php/conf.d/zz-helbaron.ini

# Simple FPM ping healthcheck.
HEALTHCHECK --interval=15s --timeout=5s --retries=3 \
    CMD SCRIPT_NAME=/ping SCRIPT_FILENAME=/ping REQUEST_METHOD=GET cgi-fcgi -bind -connect 127.0.0.1:9000 || exit 1

EXPOSE 9000

# Run the FPM master as www-data (storage/ and bootstrap/cache are chowned above).
USER www-data
CMD ["php-fpm"]
