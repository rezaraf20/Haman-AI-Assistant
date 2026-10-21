import json
import base64
import logging
import time
from typing import List, Optional
from sqlalchemy.orm import Session
from sqlalchemy import text
import redis as redis_lib
from cryptography.hazmat.primitives.ciphers.aead import AESGCM
from app.core.config import settings

logger = logging.getLogger(__name__)

CACHE_KEY = "haman:llm_provider_profiles:active"
TOOL_CALLING_CACHE_KEY = "haman:llm_provider_profiles:tool_calling"
CACHE_TTL_SECONDS = 45

# Set when every active provider in a chain failed for one single request —
# a customer just got the fallback_response/_default_error_response text
# instead of a real answer. This service has no email/Filament access of
# its own (see NotifyProviderOutageCommand.php on the Laravel side, which
# polls this key on a schedule and does the actual notifying) — a plain
# Redis key with a TTL is the whole hand-off, and the TTL is generous
# rather than tied to the notifier's own poll interval, so a late-running
# scheduler still finds a real, recent signal instead of a stale one.
ALL_PROVIDERS_FAILED_KEY = "haman:alerts:all_providers_failed_at"
ALL_PROVIDERS_FAILED_TTL_SECONDS = 3600

# Several profiles sharing the same priority (e.g. four Gemini keys from
# separate projects/quota pools, each with its own TPM limit) were still
# all hammering whichever one sorted first in that tier every single
# request -- same-priority was never actually spreading load, it was just
# a longer failover chain. These two prefixes back a least-recently-used
# ordering within a tier: order_for_attempt() reads them, mark_attempted()
# writes the LRU timestamp, mark_rate_limited() sets a short cooldown that
# pulls a 429'd profile out of its tier's rotation without touching
# consecutive_failures/auto-disable at all -- a profile that's merely
# rate-limited this minute is not "structurally broken".
LRU_KEY_PREFIX = "haman:llm_rr:last_used:"
COOLDOWN_KEY_PREFIX = "haman:llm_rr:cooldown:"
COOLDOWN_SECONDS = 60

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
    if not value or ":" not in value or not settings.HAMAN_ENCRYPTION_KEY:
        return value
    try:
        nonce_b64, data_b64 = value.split(":", 1)
        nonce = base64.b64decode(nonce_b64)
        data = base64.b64decode(data_b64)
        key = base64.b64decode(settings.HAMAN_ENCRYPTION_KEY)
        return AESGCM(key).decrypt(nonce, data, None).decode("utf-8")
    except Exception:
        return value


def _fetch_from_db(db: Session, tool_calling_only: bool = False) -> List[dict]:
    where_clause = "WHERE is_active = true"
    if tool_calling_only:
        where_clause += " AND supports_tool_calling = true"
    rows = db.execute(text(f"""
        SELECT name, provider, base_url, model_name, api_key, priority,
               max_tokens_response, timeout_seconds,
               input_price_per_1m_toman, output_price_per_1m_toman
        FROM public.llm_provider_profiles
        {where_clause}
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


def get_active_tool_calling_profiles(db: Session) -> List[dict]:
    """Same shape and Redis-caching pattern as get_active_profiles(), but
    filtered to profiles an admin has explicitly marked capable of tool
    calling (LlmProviderProfileResource's "Tool Calling" toggle) — a real,
    separate lever from the regular chat failover priority order, since
    the cheapest/fastest model for a plain answer (llama-3.1-8b-instant,
    notably) is known-weak at tool calling specifically."""
    try:
        cached = _redis.get(TOOL_CALLING_CACHE_KEY)
        if cached is not None:
            return json.loads(cached)
    except Exception as e:
        logger.warning(f"tool-calling profiles cache read failed, querying DB: {e}")

    profiles = _fetch_from_db(db, tool_calling_only=True)
    try:
        _redis.set(TOOL_CALLING_CACHE_KEY, json.dumps(profiles), ex=CACHE_TTL_SECONDS)
    except Exception as e:
        logger.warning(f"tool-calling profiles cache write failed: {e}")
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
                    _redis.delete(CACHE_KEY, TOOL_CALLING_CACHE_KEY)
                except Exception as cache_err:
                    logger.warning(f"Failed to invalidate provider cache after auto-disable: {cache_err}")
        db.commit()
    except Exception as e:
        logger.warning(f"Failed to record provider outcome for {profile_name}: {e}")
        db.rollback()


def mark_attempted(profile_name: str) -> None:
    """Call the moment a profile is actually about to be tried (not only on
    success) -- the point is "whoever went most recently, regardless of
    outcome, goes to the back of its tier's queue next time", so every
    same-priority sibling gets a turn instead of one eating every request
    that happens to make it past routing."""
    try:
        _redis.set(f"{LRU_KEY_PREFIX}{profile_name}", str(time.time()))
    except Exception as e:
        logger.warning(f"Failed to record LRU timestamp for {profile_name}: {e}")


def mark_rate_limited(profile_name: str) -> None:
    """A 429 means this profile's own quota window is spent, not that it's
    broken -- pull it out of rotation for COOLDOWN_SECONDS rather than
    counting it toward auto-disable (same reasoning _chat_completion()
    already applies to 429s individually)."""
    try:
        _redis.set(f"{COOLDOWN_KEY_PREFIX}{profile_name}", "1", ex=COOLDOWN_SECONDS)
    except Exception as e:
        logger.warning(f"Failed to record rate-limit cooldown for {profile_name}: {e}")


def order_for_attempt(profiles: List[dict]) -> List[dict]:
    """Reorders an already priority-ascending list (as returned by
    get_active_profiles()/get_active_tool_calling_profiles()) for one
    attempt: within each priority tier, least-recently-used first instead
    of always the same one, and a profile currently in its 429 cooldown is
    dropped from this attempt entirely -- it simply isn't offered a turn
    until the cooldown clears, same as it not being in this tier at all.
    If that empties a tier, the next-priority tier is still tried, which is
    exactly the desired behavior under a real rate-limit burst rather than
    an error.

    Falls back to the plain input order on any Redis failure -- ordering is
    an optimization, never a reason to refuse an otherwise-active profile.
    """
    if not profiles:
        return profiles
    try:
        names = [p["name"] for p in profiles]
        pipe = _redis.pipeline()
        for n in names:
            pipe.get(f"{COOLDOWN_KEY_PREFIX}{n}")
        for n in names:
            pipe.get(f"{LRU_KEY_PREFIX}{n}")
        results = pipe.execute()
        cooldowns = {n for n, v in zip(names, results[: len(names)]) if v}
        lru_times = {n: float(v) for n, v in zip(names, results[len(names) :]) if v}
    except Exception as e:
        logger.warning(f"Failed to read LRU/cooldown state, using plain priority order: {e}")
        return profiles

    available = [p for p in profiles if p["name"] not in cooldowns]

    ordered: List[dict] = []
    i = 0
    while i < len(available):
        j = i
        tier_priority = available[i]["priority"]
        while j < len(available) and available[j]["priority"] == tier_priority:
            j += 1
        tier = sorted(available[i:j], key=lambda p: lru_times.get(p["name"], 0.0))
        ordered.extend(tier)
        i = j
    return ordered


def record_all_providers_failed(reason: str) -> None:
    """Call once a failover chain is exhausted — every active profile tried,
    none gave a usable answer. Best-effort like record_outcome(): if Redis
    itself is down, the customer still gets their fallback text either way,
    this only loses the alert about it."""
    try:
        import json as _json
        import time as _time
        _redis.set(
            ALL_PROVIDERS_FAILED_KEY,
            _json.dumps({"at": _time.time(), "reason": reason}),
            ex=ALL_PROVIDERS_FAILED_TTL_SECONDS,
        )
    except Exception as e:
        logger.warning(f"Failed to record all-providers-failed alert: {e}")
