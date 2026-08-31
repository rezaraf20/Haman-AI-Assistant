import json
import logging
from typing import List, Optional
from sqlalchemy.orm import Session
from sqlalchemy import text
import redis as redis_lib
from app.core.config import settings

logger = logging.getLogger(__name__)

CACHE_KEY = "hamman:llm_provider_profiles:active"
CACHE_TTL_SECONDS = 45

_redis = redis_lib.from_url(settings.REDIS_URL, socket_timeout=2, decode_responses=True)


def _fetch_from_db(db: Session) -> List[dict]:
    rows = db.execute(text("""
        SELECT name, provider, base_url, model_name, api_key, priority,
               max_tokens_response, timeout_seconds
        FROM public.llm_provider_profiles
        WHERE is_active = true
        ORDER BY priority ASC
    """)).mappings().fetchall()
    return [dict(r) for r in rows]


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
