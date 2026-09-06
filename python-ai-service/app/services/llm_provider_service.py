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

# A dead provider left active in the failover chain doesn't just do nothing —
# every request routed to it (before falling through to the next one) pays
# its full timeout/retry cost first. 5 consecutive *request-level* failures
# (each already survived _chat_completion's own internal retry) is a solid
# "this is structural, not a transient blip" signal — found a real profile
# that had been silently failing 61 times in a row with nothing to catch it.
AUTO_DISABLE_THRESHOLD = 5

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
            row = db.execute(text("""
                UPDATE public.llm_provider_profiles
                SET last_failure_at = now(), consecutive_failures = consecutive_failures + 1
                WHERE name = :name
                RETURNING consecutive_failures, is_active
            """), {"name": profile_name}).mappings().first()

            # >= the threshold, not ==: a profile already past it when this
            # code first shipped (found one sitting at 61 straight failures)
            # must still get caught on its very next failure, not only if
            # the count happened to land exactly on the threshold value.
            # is_active guards against re-firing on every failure after
            # that — once disabled, it stays false until someone
            # re-activates it, so this block only ever runs once per
            # disable event, keeping disabled_notified_at meaningful as
            # "this specific event was alerted on" for the Laravel side.
            if row and row["is_active"] and row["consecutive_failures"] >= AUTO_DISABLE_THRESHOLD:
                reason = (
                    f"Auto-disabled after {AUTO_DISABLE_THRESHOLD} consecutive failures "
                    f"(last error logged separately above)."
                )
                db.execute(text("""
                    UPDATE public.llm_provider_profiles
                    SET is_active = false, disabled_reason = :reason
                    WHERE name = :name
                """), {"name": profile_name, "reason": reason})
                logger.error(f"LLM provider '{profile_name}' auto-disabled: {reason}")
                # The in-process failover chain (get_active_profiles) is
                # Redis-cached for CACHE_TTL_SECONDS — without invalidating
                # it here, requests would keep routing to a provider this
                # same function just disabled for up to that long.
                try:
                    _redis.delete(CACHE_KEY)
                except Exception as cache_err:
                    logger.warning(f"Failed to invalidate provider cache after auto-disable: {cache_err}")
        db.commit()
    except Exception as e:
        logger.warning(f"Failed to record provider outcome for {profile_name}: {e}")
        db.rollback()
