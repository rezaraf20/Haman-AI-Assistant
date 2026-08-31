FROM php:8.3-fpm-alpine

RUN apk add --no-cache git curl libpq-dev libzip-dev zip unzip linux-headers icu-dev $PHPIZE_DEPS

RUN docker-php-ext-install pdo pdo_pgsql pgsql zip bcmath opcache pcntl intl
RUN pecl install redis && docker-php-ext-enable redis

RUN { \
    echo "opcache.enable=1"; \
    echo "memory_limit=256M"; \
    echo "upload_max_filesize=15M"; \
    echo "post_max_size=15M"; \
} > /usr/local/etc/php/conf.d/custom.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Composer's advisory-blocking policy currently flags essentially every release
# of laravel/framework (5.x through 13.x) as affected by *some* historical
# advisory, which makes it impossible to install anything — not specific to
# this app's actual exposure. Report advisories via `composer audit` instead of
# gating installs on them. Must be set globally (-g): no project composer.json
# exists yet at this point, and create-project itself is what gets blocked.
RUN composer config -g policy.advisories.block false

# Create fresh Laravel project — pinned to the 11.x line this app's code targets
# (bootstrap/app.php's fluent config, etc.); an unpinned create-project silently
# tracks whatever Laravel major is newest at build time, which has drifted this
# image to Laravel 13 in the past and broken package resolution.
RUN composer create-project laravel/laravel:^11.0 . --prefer-dist --no-interaction --quiet

# Install required packages
RUN composer require \
    laravel/sanctum:^4.0 \
    laravel/horizon:^5.0 \
    predis/predis:^2.0 \
    guzzlehttp/guzzle:^7.0 \
    filament/filament:^3.0 \
    --no-interaction --quiet

# Remove default Laravel migrations that conflict with ours
RUN rm -f database/migrations/0001_01_01_000000_create_users_table.php \
         database/migrations/0001_01_01_000001_create_cache_table.php \
         database/migrations/0001_01_01_000002_create_jobs_table.php

# Copy our custom files
COPY app/ app/
COPY bootstrap/app.php bootstrap/app.php
COPY bootstrap/providers.php bootstrap/providers.php
COPY config/ config/
COPY database/ database/
COPY routes/ routes/
COPY resources/ resources/
COPY .env .env
COPY docker-entrypoint.sh /docker-entrypoint.sh

# Publish Sanctum
RUN php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider" --force --quiet

# Publish Filament's admin panel assets (CSS/JS) into public/ — no live volume
# mount for this image, so these must be baked in at build time.
RUN php artisan filament:assets

# Remove any auto-generated Sanctum migration (we have our own, kept by name below)
RUN find database/migrations -name '*_create_personal_access_tokens_table.php' \
    ! -name '2025_01_01_000006_create_personal_access_tokens_table.php' -delete || true

# Permissions
RUN mkdir -p storage/logs storage/framework/{cache,sessions,views} bootstrap/cache
RUN chown -R www-data:www-data /var/www/html
RUN chmod -R 775 storage bootstrap/cache
RUN chmod +x /docker-entrypoint.sh

# /shared-assets is the mount point for the laravel_public_assets volume nginx
# reads Filament's css/js from (see docker-compose.yml). A brand-new named
# volume is created with root:root ownership by Docker, but Docker also seeds
# it from whatever already exists at that path in the image on first mount —
# pre-creating it here as www-data (the user the entrypoint runs as, below)
# means that first mount comes up writable instead of failing on `mkdir`.
RUN mkdir -p /shared-assets && chown www-data:www-data /shared-assets

USER www-data
EXPOSE 9000
ENTRYPOINT ["/docker-entrypoint.sh"]
