"""Reads the platform settings row that the Laravel admin page writes.

Same pattern as llm_provider_service: a direct, cached read of a table Laravel
owns, rather than an HTTP round-trip for values needed on every request.

Only settings that differ from their declared default are stored in the JSONB
bag, so the defaults below must stay equal to the ones in
laravel-backend/app/Support/SettingsRegistry.php. They are repeated rather
than fetched because this service has to keep working when the settings row
is unreachable — falling back to a hardcoded number is right, falling over is
not.
"""

import json
import logging
from typing import Any

import redis as redis_lib
from sqlalchemy import text
from sqlalchemy.orm import Session

from app.core.config import settings

logger = logging.getLogger(__name__)

CACHE_KEY = "hamman:platform_settings:values"
CACHE_TTL_SECONDS = 60

# Must match SettingsRegistry.php. Verified by a test on the PHP side that
# reads this file, so the two cannot drift silently.
DEFAULTS: dict[str, Any] = {
    "limits.pdf_max_mb": 10,
    "limits.pdf_max_pages": 100,
    "limits.max_tool_calls_per_message": 3,
}
# The retrieval and rerank thresholds are deliberately absent: they are
# per-chatbot columns, and Laravel already resolves the platform default
# before it sends the payload (ChatService). Reading them again here would
# give two places for the same number to come from.

_redis = redis_lib.from_url(settings.REDIS_URL, socket_timeout=2, decode_responses=True)


def _load(db: Session) -> dict:
    cached = None
    try:
        cached = _redis.get(CACHE_KEY)
    except Exception as exc:  # noqa: BLE001 - cache must never be fatal
        logger.debug("platform settings cache read failed: %s", exc)

    if cached is not None:
        try:
            return json.loads(cached)
        except ValueError:
            pass

    try:
        row = db.execute(text('SELECT "values" FROM platform_settings LIMIT 1')).first()
        values = row[0] if row and row[0] else {}
        if isinstance(values, str):
            values = json.loads(values)
    except Exception as exc:  # noqa: BLE001
        logger.warning("platform settings read failed, using defaults: %s", exc)
        values = {}

    # Anything that is not a JSON object is not settings. A hand-edited
    # column, a driver that hands back something unexpected, or a test double
    # would otherwise flow straight into int() and come out as a plausible-
    # looking limit that nobody configured.
    if not isinstance(values, dict):
        logger.warning("platform settings column is %s, not an object - using defaults", type(values).__name__)
        values = {}

    try:
        _redis.setex(CACHE_KEY, CACHE_TTL_SECONDS, json.dumps(values))
    except Exception as exc:  # noqa: BLE001
        logger.debug("platform settings cache write failed: %s", exc)

    return values


def get(db: Session, key: str) -> Any:
    """The configured value, or the default when it was never overridden."""
    if key not in DEFAULTS:
        raise KeyError(f"Unknown setting {key!r} - declare it in DEFAULTS and SettingsRegistry.php")

    value = _load(db).get(key, DEFAULTS[key])

    # Every setting Python reads is numeric. Refusing a non-number here is
    # what keeps a bad row from turning into a limit of 1.
    if isinstance(value, bool) or not isinstance(value, (int, float)):
        logger.warning("setting %s is %r, not a number - using the default", key, value)
        return DEFAULTS[key]

    return value


def get_int(db: Session, key: str) -> int:
    return int(get(db, key))


def get_float(db: Session, key: str) -> float:
    return float(get(db, key))


def invalidate() -> None:
    try:
        _redis.delete(CACHE_KEY)
    except Exception as exc:  # noqa: BLE001
        logger.debug("platform settings cache invalidate failed: %s", exc)
