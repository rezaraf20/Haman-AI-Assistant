import json
import base64
import logging
from typing import List, Optional
from sqlalchemy.orm import Session
from sqlalchemy import text
import redis as redis_lib
from cryptography.hazmat.primitives.ciphers.aead import AESGCM
from app.core.config import settings

logger = logging.getLogger(__name__)

CACHE_KEY = "hamman:llm_provider_profiles:active"
CACHE_TTL_SECONDS = 45

_redis = redis_lib.from_url(settings.REDIS_URL, socket_timeout=2, decode_responses=True)


def _decrypt_api_key(value: str) -> str:
    """Mirrors app/Support/LlmKeyCrypto.php's AES-256-GCM envelope
    (nonce:ciphertext+tag, both base64). Falls back to the raw value on any
    failure — malformed input, missing key, pre-migration plaintext rows —
    the same fallback posture as the PHP side, so neither language's read
    path hard-fails while the other half of a rollout is still in flight."""
    if not value or ":" not in value or not settings.HAMMAN_ENCRYPTION_KEY:
        return value
    try:
        nonce_b64, data_b64 = value.split(":", 1)
        nonce = base64.b64decode(nonce_b64)
        data = base64.b64decode(data_b64)
        key = base64.b64decode(settings.HAMMAN_ENCRYPTION_KEY)
        return AESGCM(key).decrypt(nonce, data, None).decode("utf-8")
    except Exception:
        return value


def _fetch_from_db(db: Session) -> List[dict]:
    rows = db.execute(text("""
        SELECT name, provider, base_url, model_name, api_key, priority,
               max_tokens_response, timeout_seconds,
               input_price_per_1m_toman, output_price_per_1m_toman
        FROM public.llm_provider_profiles
        WHERE is_active = true
        ORDER BY priority ASC
    """)).mappings().fetchall()
    profiles = [dict(r) for r in rows]
    for p in profiles:
        p["api_key"] = _decrypt_api_key(p["api_key"])
        # Postgres NUMERIC columns come back as Decimal via psycopg2, which
        # json.dumps() can't serialize — this silently broke the Redis cache
        # below on every single call (caught, logged, degrades to querying
        # the DB fresh every time — harmless but pointless caching) ever
        # since these price columns were added. _compute_cost_toman() already
        # does float(...) on read, so this is no less correct there.
        if p.get("input_price_per_1m_toman") is not None:
            p["input_price_per_1m_toman"] = float(p["input_price_per_1m_toman"])
        if p.get("output_price_per_1m_toman") is not None:
            p["output_price_per_1m_toman"] = float(p["output_price_per_1m_toman"])
    return profiles


def get_active_profiles(db: Session) -> List[dict]:
    """Ordered list of active LLM provider profiles, Redis-cached (shared across
    worker processes) with a short TTL so Filament priority/activation changes
    propagate without a service restart."""
    try:
        cached = _redis.get(CACHE_KEY)
        if cached is not None:
            return json.loads(cached)
    except Exception as e:
        logger.warning(f"llm_provider_profiles cache read failed, querying DB: {e}")

    profiles = _fetch_from_db(db)
    try:
        _redis.set(CACHE_KEY, json.dumps(profiles), ex=CACHE_TTL_SECONDS)
    except Exception as e:
        logger.warning(f"llm_provider_profiles cache write failed: {e}")
    return profiles


def record_outcome(db: Session, profile_name: str, success: bool) -> None:
    """Best-effort health tracking for the Filament provider list — never let a
    failure here break the actual chat response."""
    try:
        if success:
            db.execute(text("""
                UPDATE public.llm_provider_profiles
                SET last_success_at = now(), consecutive_failures = 0
                WHERE name = :name
            """), {"name": profile_name})
        else:
            db.execute(text("""
                UPDATE public.llm_provider_profiles
                SET last_failure_at = now(), consecutive_failures = consecutive_failures + 1
                WHERE name = :name
            """), {"name": profile_name})
        db.commit()
    except Exception as e:
        logger.warning(f"Failed to record provider outcome for {profile_name}: {e}")
        db.rollback()
