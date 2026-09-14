# Haman AI Assistant

A multi-tenant SaaS platform that puts a retrieval-grounded chat assistant on
Iranian e-commerce sites. A shop installs a WordPress plugin, its catalogue
and pages sync across, and the widget answers customer questions from that
shop's own content — in Persian or English — rather than from a general model's
guesses.

![Architecture](https://github.com/user-attachments/assets/b29da0b6-df24-4489-9ce2-f236c78da46a)

## What it does

**Grounded answers.** Questions are answered from the shop's synced products,
pages and FAQs via pgvector retrieval with optional reranking. When retrieval
finds nothing above threshold the bot says so and the exchange is recorded as
unanswered, instead of inventing an answer.

**Tools, not just chat.** The assistant can look up live stock and pricing on
the merchant's site, build a cart URL, add to cart, compare products, produce a
payment link, and report an order's status behind an SMS one-time code. Every
tool is opt-in per chatbot.

**Reports the merchant can act on.** Trends over 30 days to a year, what
customers asked for that the shop does not sell, what was compared against
what, captured leads, restock requests, and rule-based suggestions derived from
thresholds rather than from another model call.

**A platform side.** Tenant and chatbot administration, wallet and token
accounting, support staff with a role separate from any tenant role, and an
append-only log of everything platform staff do.

## Layout

| Path | What lives there |
| --- | --- |
| `laravel-backend/` | API, admin panel, customer portal, billing, scheduling |
| `python-ai-service/` | FastAPI RAG service: embedding, retrieval, tool calling |
| `wordpress-plugin/` | The plugin merchants install: sync, widget, live queries |
| `nginx/`, `docker-compose.yml` | How it all runs |

Laravel 11 with Filament 3 for both panels. PostgreSQL 16 with pgvector, one
schema per tenant. Redis for cache, sessions and queues, with Horizon.

## Running it

```bash
cp laravel-backend/.env.example laravel-backend/.env   # set APP_KEY, DB and AI_SERVICE_SECRET
docker compose up -d
docker compose exec laravel php artisan migrate
```

The admin panel is at `/admin`, the customer portal at `/portal`.

Note that `nginx` resolves the `laravel` container's address once at start, so
recreating that container requires restarting nginx alongside it:

```bash
docker compose up -d laravel && docker compose restart nginx
```

## Configuration

Almost nothing that an operator needs to change lives in `.env`. The admin
settings page holds payment gateways, SMTP, the SMS provider, pricing, rate
limits and quotas, retention windows and backups — each with a declared
default and a reset. Credentials are encrypted at rest and never rendered back
into the page; a configured secret shows as "configured", not as its value.

## Tests

```bash
cd laravel-backend   && vendor/bin/phpunit          # 329 tests
cd python-ai-service && python -m pytest tests -q   # 317 tests
./scripts/preflight.sh                              # lint, i18n and cross-language checks
```

`preflight.sh` also fails on hardcoded Persian outside `lang/`, and on a
setting whose default disagrees between the PHP registry and the Python
service that reads the same row.

Two operational commands worth knowing:

```bash
php artisan hamman:abuse-audit      # every public endpoint: who can call it, its cap, what it costs
php artisan hamman:verify-backup    # restores the latest backup into a scratch database and counts rows
```

## Backups

A nightly `pg_dump` runs at 02:30 and a real restore is attempted at 03:45 —
an untested backup is not a backup. Retention is seven daily copies plus one
per week for four weeks. Off-server storage is S3-compatible and configured
from the settings page; until a destination is set the admin panel says so,
because a copy on the machine it protects is not a backup either.

## Licence

All rights reserved. See [LICENSE](LICENSE).
