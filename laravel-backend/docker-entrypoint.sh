#!/bin/sh
set -e
echo "=== Haman AI Platform Starting ==="

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

# Generate app key only if one isn't already set. No .env file exists in
# this image or container (see Dockerfile) — config comes entirely from the
# real process environment via docker-compose's env_file:, so the check and
# the fallback both work directly against $APP_KEY, never a file.
#
# `php artisan key:generate` itself is not used for the fallback: it writes
# its result into a .env file, which doesn't exist here and wouldn't persist
# across the next `--force-recreate` even if it did (a fresh container from
# the image, not the same writable layer) — silently generating a key that
# vanishes on the next deploy is exactly the "invalidates every session on
# restart" failure mode this is trying to avoid, just from a different
# cause. Instead: if APP_KEY is genuinely unset, generate one directly and
# say loudly that it won't survive a restart, since there's nowhere durable
# to put it from inside this container — a permanent one belongs in the
# host's laravel-backend/.env (generate with `php artisan key:generate
# --show` once, paste it in).
if [ -z "$APP_KEY" ]; then
    echo "WARNING: APP_KEY is not set in the environment."
    echo "WARNING: Generating a temporary key for this container only — it will NOT survive the next restart/deploy, invalidating all sessions."
    echo "WARNING: Set a permanent APP_KEY in laravel-backend/.env (generate one with: php artisan key:generate --show)."
    export APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')"
fi

# Run migrations
php artisan migrate --force

# Fix all existing tenant schemas (adds missing columns)
php artisan haman:fix-tenants 2>/dev/null || true

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

# The WordPress plugin the setup guide links to. Same reasoning as the assets
# above: nginx serves /downloads/ straight from the volume, and it has no
# other way to see this container's filesystem. Without this the download link
# falls through to index.php and answers 404.
mkdir -p /shared-assets/downloads
cp -a public/downloads/. /shared-assets/downloads/ 2>/dev/null || true

echo "=== Haman AI Ready ==="

if [ "$#" -gt 0 ]; then
    exec "$@"
else
    exec php-fpm
fi
