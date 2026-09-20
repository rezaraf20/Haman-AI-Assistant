#!/bin/bash
# Install the whole platform from nothing and prove it works.
#
# Everything this project runs on was configured by hand over months, then
# copied forward. Nobody had ever started it from an empty volume, which is
# how docker-compose came to name a Postgres role -- haman_user, one m -- that
# no .env file mentions. On the live volume that value is ignored, so the
# mistake stayed invisible for as long as nobody did what a new deployment
# does.
#
# This does exactly that: a fresh clone, .env files written from .env.example,
# empty volumes, migrations from zero. It runs beside the live stack under its
# own project name, its own container names and its own ports.
#
# It clones into a temporary directory and never touches the deployment it is
# run from. That is deliberate and worth keeping: an earlier draft wrote the
# .env files in place, which on this host means overwriting the production
# ones and relying on a trap to put them back.
#
# Usage:  bash deploy/scratch-install/run.sh [--keep]
#         --keep leaves the stack and its clone in place for poking at.
set -euo pipefail

PROJECT=haman_scratch
REPO=https://github.com/rezaraf20/Haman-AI-Assistant.git
BRANCH=master
KEEP="${1:-}"
WORK=$(mktemp -d /tmp/haman-scratch-XXXXXX)

compose() { docker compose -p "$PROJECT" -f docker-compose.yml -f deploy/scratch-install/docker-compose.scratch.yml "$@"; }

cleanup() {
    cd "$WORK" 2>/dev/null || return 0
    if [ "$KEEP" = "--keep" ]; then
        echo
        echo "Left running (--keep):  $WORK"
        echo "Remove with:  cd $WORK && docker compose -p $PROJECT -f docker-compose.yml -f deploy/scratch-install/docker-compose.scratch.yml down -v && rm -rf $WORK"
        return 0
    fi
    echo
    echo "== Tearing down, volumes included =="
    compose down -v --remove-orphans >/dev/null 2>&1 || true
    cd /
    rm -rf "$WORK"
    echo "Removed $WORK and every volume it created."
}
trap cleanup EXIT

echo "== Cloning $BRANCH into $WORK =="
git clone -q --depth 1 --branch "$BRANCH" "$REPO" "$WORK"
cd "$WORK"
echo "  at commit $(git rev-parse --short HEAD)"

[ -f .env.example ] || { echo "FAIL: .env.example is missing — the documented starting point does not exist."; exit 1; }

# ── The three .env files, from .env.example ──────────────────────────
# Real generated values, because the point is to prove the documented set of
# variables is sufficient, not that a hand-tuned config works.
# The trailing "|| true" is load-bearing. Under `set -o pipefail`, tr is killed
# by SIGPIPE the moment head has taken its bytes, and that non-zero status
# propagates and kills the script -- silently, between the clone and the first
# echo, which is exactly how this failed the first time it ran.
secret() { LC_ALL=C tr -dc 'A-Za-z0-9' < /dev/urandom 2>/dev/null | head -c "${1:-32}" || true; }
DB_PASS=$(secret 32)
REDIS_PASS=$(secret 32)
AI_SECRET=$(secret 40)
ENC_KEY=$(head -c 32 /dev/urandom | base64)

echo "== Writing the three .env files =="
cat > .env <<EOF
DB_PASSWORD=${DB_PASS}
REDIS_PASSWORD=${REDIS_PASS}
EOF

cat > laravel-backend/.env <<EOF
APP_NAME=HamanScratch
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=http://localhost:58080
APP_TIMEZONE=UTC
LOG_CHANNEL=stderr
LOG_LEVEL=warning
DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=haman_saas
DB_USERNAME=hamman_user
DB_PASSWORD=${DB_PASS}
CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
REDIS_HOST=redis
REDIS_PORT=6379
REDIS_PASSWORD=${REDIS_PASS}
AI_SERVICE_URL=http://python_ai:8001
AI_SERVICE_SECRET=${AI_SECRET}
GEMINI_API_KEY=scratch-not-a-real-key
MAIL_MAILER=log
ZARINPAL_MERCHANT_ID=
ZARINPAL_SANDBOX=true
SANCTUM_STATEFUL_DOMAINS=localhost
HAMAN_WP_PLUGIN_LATEST_VERSION=2.0.0
EOF

cat > python-ai-service/.env <<EOF
APP_ENV=production
LOG_LEVEL=warning
INTERNAL_SECRET=${AI_SECRET}
DATABASE_URL=postgresql://hamman_user:${DB_PASS}@postgres:5432/haman_saas
REDIS_URL=redis://:${REDIS_PASS}@redis:6379/0
GEMINI_API_KEY=scratch-not-a-real-key
GEMINI_EMBEDDING_MODEL=gemini-embedding-001
GEMINI_EMBEDDING_DIMS=768
GEMINI_CHAT_MODEL=gemini-3.5-flash-lite
GROQ_API_KEY=scratch-not-a-real-key
HAMAN_ENCRYPTION_KEY=${ENC_KEY}
EOF
echo "  OK — three files written, no value copied from the live deployment."

echo
echo "== Bringing the stack up on empty volumes =="
compose up -d --build 2>&1 | tail -6

echo
echo "== Waiting for Postgres and Redis to report healthy =="
for _ in $(seq 1 60); do
    healthy=$(compose ps --format '{{.Status}}' 2>/dev/null | grep -c healthy || true)
    [ "${healthy:-0}" -ge 2 ] && break
    sleep 2
done
compose ps --format '  {{.Service}}  {{.Status}}'

echo
echo "== The role compose creates must be the one the .env files name =="
compose exec -T postgres psql -U hamman_user -d haman_saas -t -A \
  -c "select 'connected as ' || current_user;" 2>&1 | tail -1

echo
echo "== Migrating from zero =="
compose exec -T laravel php artisan key:generate --force 2>&1 | tail -2
compose exec -T laravel php artisan migrate --force 2>&1 | tail -12

echo
echo "== Seeding =="
compose exec -T laravel php artisan db:seed --force 2>&1 | tail -8

echo
echo "== Verifying =="
compose exec -T postgres psql -U hamman_user -d haman_saas -t -A -F' ' -c "
  select 'role=' || current_user,
         'tables=' || (select count(*) from information_schema.tables where table_schema='public'),
         'plans='  || (select count(*) from public.plans);" 2>/dev/null | sed 's/^/  /'

echo -n "  pgvector: "
compose exec -T postgres psql -U hamman_user -d haman_saas -t -A \
  -c "select 'v' || extversion from pg_extension where extname='vector';" 2>/dev/null | tr -d ' \r'
echo

# Wait for the web tier before asking it anything. The laravel entrypoint
# caches config and routes before it starts php-fpm, which takes the better
# part of half a minute on a cold image -- curling straight after the seeder
# reported 502 for all three URLs and looked like a broken install when the
# stack was merely still starting.
echo -n "  waiting for php-fpm to accept requests"
ready=0
for _ in $(seq 1 45); do
    code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 5 http://127.0.0.1:58080/up 2>/dev/null || true)
    if [ "$code" = "200" ]; then ready=1; break; fi
    echo -n "."
    sleep 2
done
echo
[ "$ready" = "1" ] || echo "  WARNING: never became ready -- the codes below are a real failure, not a race"

for path in / /up /portal/login; do
    code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "http://127.0.0.1:58080${path}" || true)
    echo "  $path -> ${code:-no response}"
done

echo
echo "== Done =="
