FROM php:8.3-fpm-alpine

RUN apk add --no-cache git curl libpq-dev libzip-dev zip unzip linux-headers $PHPIZE_DEPS

RUN docker-php-ext-install pdo pdo_pgsql pgsql zip bcmath opcache pcntl
RUN pecl install redis && docker-php-ext-enable redis

RUN { \
    echo "opcache.enable=1"; \
    echo "memory_limit=256M"; \
    echo "upload_max_filesize=15M"; \
    echo "post_max_size=15M"; \
} > /usr/local/etc/php/conf.d/custom.ini

COPY --from=composer:2.7 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Create fresh Laravel project
RUN composer create-project laravel/laravel . --prefer-dist --no-interaction --quiet

# Install required packages
RUN composer require \
    laravel/sanctum:^4.0 \
    laravel/horizon:^5.0 \
    predis/predis:^2.0 \
    guzzlehttp/guzzle:^7.0 \
    --no-interaction --quiet

# Remove default Laravel migrations that conflict with ours
RUN rm -f database/migrations/0001_01_01_000000_create_users_table.php \
         database/migrations/0001_01_01_000001_create_cache_table.php \
         database/migrations/0001_01_01_000002_create_jobs_table.php

# Copy our custom files
COPY app/ app/
COPY bootstrap/app.php bootstrap/app.php
COPY config/ config/
COPY database/ database/
COPY routes/ routes/
COPY resources/ resources/
COPY .env .env
COPY docker-entrypoint.sh /docker-entrypoint.sh

# Publish Sanctum
RUN php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider" --force --quiet

# Remove any auto-generated Sanctum migration (we have our own)
RUN rm -f database/migrations/2025_*_create_personal_access_tokens_table.php || true

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
