#!/bin/sh
set -e
echo "=== Hamman AI Platform Starting ==="

# Remove Sanctum's auto-generated personal_access_tokens migration (we have our
# own, 2025_01_01_000006_..., kept below) — matched by exact suffix and excluding
# our own file by name, not by a year-prefixed wildcard like "2026_*.php": that
# silently deletes any other real, intentionally-dated migration the moment the
# calendar reaches that year (it deleted this project's own 2026_07_27_* migrations).
find database/migrations -name '*_create_personal_access_tokens_table.php' \
    ! -name '2025_01_01_000006_create_personal_access_tokens_table.php' -delete

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

if [ "$#" -gt 0 ]; then
    exec "$@"
else
    exec php-fpm
fi
