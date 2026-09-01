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

# Generate app key only if one isn't already set. `key:generate --force`
# unconditionally on every start (the old behavior) both invalidates every
# session/cookie on every restart, and — because Laravel's .env replace regex
# doesn't handle an empty existing value cleanly — kept appending a new key
# next to the old one instead of replacing it, corrupting APP_KEY into a
# multi-key string that made Filament's cookie encryption fail outright.
if ! grep -q '^APP_KEY=base64:' .env 2>/dev/null; then
    php artisan key:generate --force
fi

# Run migrations
php artisan migrate --force

# Fix all existing tenant schemas (adds missing columns)
php artisan hamman:fix-tenants 2>/dev/null || true

# Seed plans
php artisan db:seed --force 2>/dev/null || true

# Clear and rebuild cache
php artisan config:cache 2>/dev/null || true
php artisan route:cache  2>/dev/null || true

# Publish this image's static assets (Filament's css/js, published at build
# time into public/) into the volume nginx reads from — nginx has no other
# access to this container's filesystem. Always re-copy (not a one-time
# thing) so a rebuilt image's updated assets replace stale ones on every
# restart instead of only the volume's first-ever population.
mkdir -p /shared-assets/css /shared-assets/js
cp -a public/css/. /shared-assets/css/ 2>/dev/null || true
cp -a public/js/.  /shared-assets/js/  2>/dev/null || true

echo "=== Hamman AI Ready ==="

if [ "$#" -gt 0 ]; then
    exec "$@"
else
    exec php-fpm
fi
