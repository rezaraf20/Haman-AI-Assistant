#!/bin/sh
set -e
echo "=== Hamman AI Platform Starting ==="

# Remove Sanctum/Laravel auto-generated migrations that conflict with ours
rm -f database/migrations/2026_*.php

# Wait for postgres
until php artisan db:show --json 2>/dev/null | grep -q "driver"; do
    echo "Waiting for database..."
    sleep 3
done

# Generate app key if needed
php artisan key:generate --force 2>/dev/null || true

# Run migrations
php artisan migrate --force

# Fix all existing tenant schemas (adds missing columns)
php artisan hamman:fix-tenants 2>/dev/null || true

# Seed plans
php artisan db:seed --force 2>/dev/null || true

# Clear and rebuild cache
php artisan config:cache 2>/dev/null || true
php artisan route:cache  2>/dev/null || true

echo "=== Hamman AI Ready ==="
exec php-fpm
