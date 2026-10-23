import asyncio
import json
import logging
from fastapi import APIRouter, Depends
from fastapi.responses import StreamingResponse
from sqlalchemy.orm import Session
from app.core.database import get_db, set_schema
from app.models.schemas import ChatRequest, ChatResponse
from app.services.rag_service import run_rag_pipeline, run_rag_pipeline_stream
from app.services.llm_provider_service import _redis

logger = logging.getLogger(__name__)
router = APIRouter()

# The structural backstop against a retried HTTP call re-running the whole
# pipeline (and any write-tool it calls) a second time — see the
# 2026-10-22 incident: Laravel's client-side timeout fired while python_ai
# was still genuinely working on a slow tool-calling turn, and its blind
# retry() sent an identical request while the first attempt was still
# executing server-side, each one unaware of the other. The first attempt
# had already called add_to_cart successfully by the time the retry fired.
#
# request_id is the SAME value across any retry of one logical message
# (see ChatService::gatewayPayload() on the Laravel side, which generates
# it once per incoming customer message and never regenerates it for a
# retry of that same call) — that's what makes this work at all.
_IDEMPOTENCY_KEY_PREFIX = "haman:chat_idem:"
# Comfortably more than the tool-calling loop's own 20s budget plus the
# slowest fallback LLM call measured in the field (~28s) — long enough
# that a legitimately slow turn never has its lock expire while it's
# still genuinely running.
_IDEMPOTENCY_LOCK_TTL_SECONDS = 90
# Long enough to absorb a legitimate retry (or the customer's own double
# click/resend), short enough not to let Redis accumulate chat results
# forever.
_IDEMPOTENCY_RESULT_TTL_SECONDS = 300
_IDEMPOTENCY_POLL_SECONDS = 0.5
_IDEMPOTENCY_MAX_WAIT_SECONDS = 90
_PROCESSING_MARKER = "__processing__"


async def _dedupe_chat_request(request_id: str | None, run) -> dict:
    """
    - no request_id (an older caller) -> no dedup, just run.
    - first time this id is seen -> take the lock, run for real, cache the
      real result under the same key so the key then means "done", not
      "processing".
    - the id is mid-flight right now (another attempt holds the lock) ->
      wait for that attempt's real result instead of starting a second,
      independent one.
    - the id already has a cached real result -> return it immediately,
      without ever touching the pipeline (and without ever calling a
      write-tool) again.
    """
    if not request_id:
        return await run()

    key = f"{_IDEMPOTENCY_KEY_PREFIX}{request_id}"
    try:
        took_lock = _redis.set(key, _PROCESSING_MARKER, nx=True, ex=_IDEMPOTENCY_LOCK_TTL_SECONDS)
    except Exception as e:
        # Redis itself is down: fail OPEN (run normally) — the same
        # best-effort posture every other Redis-touching helper in this
        # app takes. A cache outage must never be the reason a real
        # customer's chat breaks, even though it means this one specific
        # safety net is unavailable for as long as Redis is down.
        logger.warning(f"Idempotency check failed (failing open): {e}")
        return await run()

    if took_lock:
        try:
            result = await run()
        except Exception:
            # Don't leave a poisoned "processing forever" lock behind for
            # a request that actually failed — release it so a genuine
            # retry (or the customer trying again) gets a real attempt
            # instead of waiting out the full lock TTL for nothing.
            try:
                _redis.delete(key)
            except Exception:
                pass
            raise
        try:
            _redis.set(key, json.dumps(result), ex=_IDEMPOTENCY_RESULT_TTL_SECONDS)
        except Exception as e:
            logger.warning(f"Failed to cache idempotent chat result (continuing anyway): {e}")
        return result

    # Someone else already holds this id — wait for their real result
    # rather than starting a second, independent pipeline run.
    waited = 0.0
    while waited < _IDEMPOTENCY_MAX_WAIT_SECONDS:
        await asyncio.sleep(_IDEMPOTENCY_POLL_SECONDS)
        waited += _IDEMPOTENCY_POLL_SECONDS
        try:
            cached = _redis.get(key)
        except Exception as e:
            logger.warning(f"Idempotency poll failed (failing open): {e}")
            return await run()
        if cached is None:
            # The other attempt finished and its cached result already
            # expired (an implausibly slow poller), or it failed and
            # released the lock — either way nobody is going to produce a
            # result for this id anymore, so this caller runs for real.
            break
        if cached != _PROCESSING_MARKER:
            try:
                return json.loads(cached)
            except (TypeError, ValueError):
                break

    return await run()


@router.post("/chat/complete", response_model=ChatResponse)
async def chat_complete(req: ChatRequest, db: Session = Depends(get_db)):
    set_schema(db, req.schema_name)

    async def run():
        return await run_rag_pipeline(
            db=db, chatbot_id=req.chatbot_id, query=req.query, history=req.history,
            system_prompt=req.system_prompt, fallback_resp=req.fallback_response,
            llm_model=req.llm_model, top_k=req.top_k, threshold=req.threshold,
            temperature=req.temperature, max_tokens=req.max_tokens, language=req.language,
            rerank_enabled=req.rerank_enabled, rerank_threshold=req.rerank_threshold,
            business_name=req.business_name, conversation_id=req.conversation_id,
            enabled_tools=req.enabled_tools,
            authenticity_unknown_message=req.authenticity_unknown_message,
        )

    result = await _dedupe_chat_request(req.request_id, run)
    return ChatResponse(**result)


@router.post("/chat/stream")
async def chat_stream(req: ChatRequest, db: Session = Depends(get_db)):
    """Server-Sent Events counterpart to /chat/complete — used by ChatController::
    sendMessageStream() on the Laravel side when the caller's Accept header asks
    for text/event-stream. `db` (a generator-based FastAPI dependency) stays open
    for the whole streamed response, not just until this function returns — that's
    the standard, supported FastAPI lifecycle for a yield-dependency used with
    StreamingResponse, not something fragile being relied on here.

    No idempotency wrapper here (unlike /chat/complete): AiGatewayService::
    chatStream() never uses Laravel's ->retry() at all, so the specific
    double-execution risk this guards against doesn't exist on this path today.
    """
    set_schema(db, req.schema_name)

    async def event_source():
        async for kind, payload in run_rag_pipeline_stream(
            db=db, chatbot_id=req.chatbot_id, query=req.query, history=req.history,
            system_prompt=req.system_prompt, fallback_resp=req.fallback_response,
            llm_model=req.llm_model, top_k=req.top_k, threshold=req.threshold,
            temperature=req.temperature, max_tokens=req.max_tokens, language=req.language,
            rerank_enabled=req.rerank_enabled, rerank_threshold=req.rerank_threshold,
            business_name=req.business_name, conversation_id=req.conversation_id,
            enabled_tools=req.enabled_tools,
        ):
            if kind == "delta":
                yield f"data: {json.dumps({'delta': payload})}\n\n"
            else:  # "done"
                yield f"event: done\ndata: {json.dumps(payload)}\n\n"

    return StreamingResponse(
        event_source(),
        media_type="text/event-stream; charset=utf-8",
        headers={"Cache-Control": "no-cache", "X-Accel-Buffering": "no"},
    )
