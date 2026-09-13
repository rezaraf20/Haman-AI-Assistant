"""
Tool-calling loop: gives the model real, live-data capabilities (see
app/services/tools/) on top of the regular grounded-answer pipeline,
instead of trying to parse tool intent out of free text — Groq's
OpenAI-compatible /chat/completions endpoint accepts the same
tools/tool_choice fields OpenAI does, so this is real native tool calling,
not a text-parsing hack.

Entirely opt-in per chatbot (chatbots.enabled_tools) and requires a
provider profile an admin has explicitly marked capable of it
(LlmProviderProfile.supports_tool_calling) — llama-3.1-8b-instant, the
default fast/cheap chat model, is known-weak at tool calling, so this
deliberately does NOT reuse the regular chat failover chain.

run_tool_calling_pipeline() returns None whenever tool calling doesn't or
can't apply (no tools enabled for this chatbot, no tool-calling-capable
provider configured, the LLM call itself fails, or the time budget runs
out) — the caller (rag_service.run_rag_pipeline()) treats None as "fall
through to the normal retrieval pipeline unchanged", so a tool-calling
failure degrades to today's existing behavior, never a broken response.
"""
import json
import logging
import time
from typing import List, Optional

import requests as _requests
from sqlalchemy.orm import Session

from app.services import llm_provider_service
from app.services.llm_provider_service import _redis
from app.services.tools.registry import get_enabled_tools, to_openai_schema, Tool

logger = logging.getLogger(__name__)

# Real tool executions, not LLM calls — the loop always allows exactly one
# more LLM call after the budget is hit (with tools disabled) so the model
# still produces a real text answer instead of the turn just dying.
MAX_TOOL_CALLS_PER_MESSAGE = 3
MAX_TOTAL_SECONDS = 12.0

# Separate from every other rate limit in this app (chat-session/chat-
# message on the Laravel side, the WordPress plugin's own live-query
# limiter) — this one specifically protects against runaway *cost*: the
# tool-calling path uses a deliberately stronger/pricier model
# (LlmProviderProfile.supports_tool_calling) than a plain answer. Checked
# before anything else in the pipeline, including before even looking at
# whether this chatbot has any tools enabled at all.
TOOL_RATE_LIMIT_MAX_PER_MINUTE = 10
TOOL_RATE_LIMIT_KEY_PREFIX = "hamman:tool_rate_limit:"

# Tools whose successful result must render as an actual widget UI element
# (product cards / a comparison table / one-click "add to cart" controls)
# rather than as text the model paraphrases — see hamman-widget.js's
# renderProductCards()/renderCompareTable()/renderCartLinks()/
# renderAddToCartIntent(). Every product shown this way also gets a
# conversation_event (product_mentioned or cart_link_generated —
# revenue-attribution input, doc-04's acceptance criterion), logged for
# exactly what actually ended up in the rendered block, never merely
# fetched/considered.
#
# add_to_cart and create_payment_link are the exceptions: they log
# NOTHING here. Both results are pure intent/preview (see their own
# docstrings in product_tools.py) — nothing has actually happened yet.
# add_to_cart's real Store API call only fires in the customer's own
# browser after a click; create_payment_link's real order is only ever
# created after a click on the widget's "Confirm & Pay" button, via
# ChatController::createPaymentLink() — a completely separate code path
# from this tool call, with its own from-scratch security checks. The
# matching cart_add_succeeded/payment_link_created events are logged
# separately, only once each real outcome is confirmed — never from
# these tool calls, which would overcount offers never acted on.
_RENDERABLE_TOOLS = {"recommend_products", "compare_products", "build_cart_url", "add_to_cart",
                     "create_payment_link", "get_order_status"}


def _build_widget_block(fn_name: str, result: dict) -> Optional[dict]:
    if fn_name not in _RENDERABLE_TOOLS or not isinstance(result, dict):
        return None
    if fn_name in ("build_cart_url", "add_to_cart"):
        if "error" in result:
            return None
        items = result.get("items") or []
        if not items:
            return None
        block_type = "cart_links" if fn_name == "build_cart_url" else "add_to_cart"
        return {"type": block_type, "items": items}
    if fn_name == "create_payment_link":
        if "error" in result:
            return None
        items = result.get("items") or []
        if not items or result.get("total") is None:
            return None
        return {
            "type": "payment_link_preview", "items": items,
            "total": result["total"], "currency": result.get("currency", "IRT"),
            "customer": result.get("customer"),
        }
    if fn_name == "get_order_status":
        if "error" in result or not result.get("contact"):
            return None
        return {"type": "order_status_otp", "contact": result["contact"]}
    if not result.get("live"):
        return None
    products = result.get("products") or []
    if not products:
        return None
    if fn_name == "recommend_products":
        return {"type": "product_cards", "products": products}
    return {"type": "product_compare", "products": products, "attribute_rows": result.get("attribute_rows", [])}


_IN_STOCK_STATUSES = {"instock", "onbackorder"}


def _detect_lead_signal(fn_name: str, args: dict, result: dict) -> Optional[dict]:
    """Spots the two moments where a shop loses a sale it could still save:
    the customer wanted something real that happens to be out of stock, and
    the customer wanted something the shop does not carry at all.

    Both are worth a callback, and they are NOT the same thing — "we'll
    text you when it's back" is a promise the shop can keep; for something
    it never stocked, the honest version is "we'll let you know if we get
    it". Hence two modes rather than one, with their own copy and their own
    off switch (LeadCaptureService on the Laravel side owns both).

    Returns {"mode": ..., "item": ...} or None. `item` is what the customer
    actually asked for, because a lead that only says "someone asked about
    something" tells the merchant nothing they can act on.
    """
    if not isinstance(result, dict) or "error" in result:
        return None

    if fn_name == "get_product_availability":
        # A live lookup that came back empty: the store genuinely has no
        # such product. Only trust this when the check really ran live —
        # an unreachable store must never be reported as "not stocked".
        if result.get("live") and result.get("found") is False:
            asked = args.get("sku") or args.get("product_id")
            return {"mode": "not_in_catalog", "item": str(asked)} if asked else None

        if result.get("found") and result.get("live"):
            status = str(result.get("stock_status") or "").strip().lower()
            if status and status not in _IN_STOCK_STATUSES:
                name = result.get("name") or args.get("sku")
                if not name:
                    return None
                # The numeric id travels with the signal so a restock can
                # later be matched exactly, rather than by comparing a
                # product name the customer never typed.
                signal = {"mode": "out_of_stock", "item": str(name)}
                if result.get("product_id"):
                    signal["product_id"] = result["product_id"]
                return signal

    if fn_name == "search_products":
        if not result.get("live"):
            return None
        asked = args.get("brand") or args.get("query") or args.get("category")
        results = result.get("results") or []
        if not results:
            return {"mode": "not_in_catalog", "item": str(asked)} if asked else None

        # Everything that matched is out of stock — the demand is real and
        # the shop simply cannot fulfil it today.
        statuses = [str(r.get("stock_status") or "").strip().lower() for r in results if isinstance(r, dict)]
        if statuses and all(s and s not in _IN_STOCK_STATUSES for s in statuses):
            first = next((r for r in results if isinstance(r, dict) and r.get("name")), None)
            item = (first or {}).get("name") or asked
            if not item:
                return None
            signal = {"mode": "out_of_stock", "item": str(item)}
            # Only when exactly one product matched is the id unambiguous —
            # with several, "the one they meant" is a guess.
            if len(results) == 1 and first and first.get("product_id"):
                signal["product_id"] = first["product_id"]
            return signal

    return None


def _log_product_mentions(db: Session, conversation_id: Optional[str], chatbot_id: str, source: str, products: List[dict]) -> None:
    from app.services.rag_service import _log_event
    for p in products:
        if not isinstance(p, dict) or not p.get("product_id"):
            continue
        if p.get("found") is False:  # compare_products marks a missing id this way — nothing real was shown
            continue
        _log_event(db, conversation_id, chatbot_id, "product_mentioned", {
            "product_id": p.get("product_id"), "name": p.get("name"), "source": source,
        })


def _log_compared_pairs(
    db: Session, conversation_id: Optional[str], chatbot_id: str,
    products: List[dict], event_type: str,
) -> None:
    """Records which products were put in front of a customer together.

    Two event types, deliberately not merged:
      compared_pair — the customer explicitly asked for a comparison.
      co_presented  — the bot showed several options side by side. That is
                      an implicit comparison and worth knowing about, but
                      it is a weaker signal than someone actually asking
                      "which of these two", so a merchant reading the
                      report should be able to tell them apart.

    Every pair in the set is recorded, not just the first two: with three
    products on screen the customer is weighing three pairings, and a
    report that only ever saw (A,B) would miss that (B,C) is the matchup
    that actually decides things.

    Ids are sorted within a pair so (A,B) and (B,A) aggregate as one
    matchup rather than two.
    """
    from app.services.rag_service import _log_event

    ids = []
    for p in products:
        if not isinstance(p, dict) or not p.get("product_id"):
            continue
        if p.get("found") is False:  # a requested id that does not exist
            continue
        ids.append((p["product_id"], p.get("name")))

    if len(ids) < 2:
        return

    for i in range(len(ids)):
        for j in range(i + 1, len(ids)):
            a, b = ids[i], ids[j]
            pair = sorted([a, b], key=lambda x: str(x[0]))
            _log_event(db, conversation_id, chatbot_id, event_type, {
                "product_ids": [pair[0][0], pair[1][0]],
                "names": [pair[0][1], pair[1][1]],
            })


def _log_cart_links(db: Session, conversation_id: Optional[str], chatbot_id: str, items: List[dict]) -> None:
    from app.services.rag_service import _log_event
    for item in items:
        if not isinstance(item, dict) or not item.get("product_id"):
            continue
        _log_event(db, conversation_id, chatbot_id, "cart_link_generated", {
            "product_id": item.get("product_id"), "quantity": item.get("quantity"), "url": item.get("url"),
        })


def _tool_rate_limited(chatbot_id: str) -> bool:
    """Fixed 1-minute bucket per chatbot, Redis INCR+EXPIRE. Fails OPEN
    (never rate-limited) on any Redis error — the same best-effort posture
    every other Redis-touching helper in this app takes; a cache outage
    must not be the reason a real customer's chat breaks."""
    try:
        key = f"{TOOL_RATE_LIMIT_KEY_PREFIX}{chatbot_id}:{int(time.time() // 60)}"
        count = _redis.incr(key)
        if count == 1:
            _redis.expire(key, 70)
        return count > TOOL_RATE_LIMIT_MAX_PER_MINUTE
    except Exception as e:
        logger.warning(f"Tool rate-limit check failed (failing open): {e}")
        return False


def _openai_tool_chat(profile: dict, messages: List[dict], tools_schema: Optional[List[dict]], max_tokens: int, temperature: float) -> tuple[dict, dict]:
    body = {
        "model": profile["model_name"],
        "messages": messages,
        "max_tokens": profile.get("max_tokens_response") or max_tokens,
        "temperature": temperature,
    }
    if tools_schema:
        body["tools"] = tools_schema
        body["tool_choice"] = "auto"
    resp = _requests.post(
        f"{profile['base_url'].rstrip('/')}/chat/completions",
        headers={
            "Authorization": f"Bearer {profile['api_key']}",
            "Content-Type": "application/json",
        },
        json=body,
        timeout=profile.get("timeout_seconds") or 30,
    )
    resp.raise_for_status()
    response_body = resp.json()
    return response_body["choices"][0]["message"], response_body.get("usage", {})


def _tool_calling_chat(db: Session, messages: List[dict], tools_schema: Optional[List[dict]], max_tokens: int, temperature: float) -> tuple[dict, str, dict, float]:
    # Local import — avoids a circular import at module load time (rag_service
    # imports this module too, from inside a function, at call time, by
    # which point rag_service is already fully loaded).
    from app.services.rag_service import _compute_cost_toman

    profiles = llm_provider_service.get_active_tool_calling_profiles(db)
    if not profiles:
        raise RuntimeError("No active tool-calling-capable LLM provider profile is configured")

    last_error = None
    for profile in profiles:
        try:
            message, usage = _openai_tool_chat(profile, messages, tools_schema, max_tokens, temperature)
            cost_toman = _compute_cost_toman(profile, usage)
            llm_provider_service.record_outcome(db, profile["name"], success=True)
            return message, f"{profile['provider']}/{profile['model_name']}", usage, cost_toman
        except Exception as e:
            last_error = e
            llm_provider_service.record_outcome(db, profile["name"], success=False)
            logger.warning(f"Tool-calling provider '{profile['name']}' failed, trying next: {e}")
            continue
    raise last_error


def _execute_tool_call(db: Session, chatbot_id: str, conversation_id: Optional[str], call: dict, tools: List[Tool]) -> dict:
    from app.services.rag_service import _log_event

    tool_map = {t.name: t for t in tools}
    fn_name = call.get("function", {}).get("name", "")
    start = time.time()

    try:
        args = json.loads(call.get("function", {}).get("arguments") or "{}")
        if not isinstance(args, dict):
            args = {}
    except (json.JSONDecodeError, TypeError):
        args = {}

    # SECURITY: chatbot_id/schema/db are NEVER taken from the model's own
    # tool-call arguments, even if it tries to supply them (a manipulated
    # or hallucinated value) — stripped unconditionally before anything
    # else touches `args`, so the handler call below always uses this
    # request's own real chatbot_id, never one an attacker/model supplied.
    for forbidden_key in ("chatbot_id", "schema_name", "db", "tenant_id"):
        args.pop(forbidden_key, None)

    tool = tool_map.get(fn_name)
    if not tool:
        result, success = {"error": f"Unknown tool: {fn_name}"}, False
    elif tool.access_level == "write":
        # No real in-conversation confirmation flow exists yet — refusing
        # unconditionally is the safe default, not a half-built dangerous
        # feature. See the module docstring / task spec on write tools.
        result, success = {"error": "This action requires explicit user confirmation, which isn't supported yet."}, False
    else:
        try:
            result = tool.handler(db=db, chatbot_id=chatbot_id, **args)
            success = "error" not in result
        except TypeError as e:
            # Wrong/missing arguments for this handler's real signature —
            # a model hallucinating a parameter name, not a server bug.
            logger.warning(f"Tool '{fn_name}' called with invalid arguments {args}: {e}")
            result, success = {"error": "Invalid arguments for this tool."}, False
        except Exception as e:
            logger.warning(f"Tool '{fn_name}' handler raised: {e}")
            result, success = {"error": "Internal error executing this tool."}, False

    latency_ms = int((time.time() - start) * 1000)
    _log_event(db, conversation_id, chatbot_id, "tool_called", {
        "tool": fn_name,
        "arguments": args,
        "result_summary": {k: v for k, v in result.items() if k in ("found", "live", "error", "stock_status")},
        "success": success,
    }, latency_ms=latency_ms)

    return result


def run_tool_calling_pipeline(
    db: Session, chatbot_id: str, conversation_id: Optional[str], query: str,
    history: List[dict], system_prompt_text: str, max_tokens: int, temperature: float,
    enabled_tool_names: List[str],
) -> Optional[dict]:
    tools = get_enabled_tools(enabled_tool_names)
    if not tools:
        return None

    if _tool_rate_limited(chatbot_id):
        logger.warning(f"Tool-calling rate limit hit for chatbot {chatbot_id}")
        from app.services.rag_service import _log_event
        _log_event(db, conversation_id, chatbot_id, "tool_rate_limited", {
            "limit_per_minute": TOOL_RATE_LIMIT_MAX_PER_MINUTE,
        })
        return None

    tools_schema = to_openai_schema(tools)
    messages = [{"role": "system", "content": system_prompt_text}]
    for h in history[-12:]:
        if h.get("role") in ("user", "assistant") and h.get("content"):
            messages.append({"role": h["role"], "content": h["content"]})
    messages.append({"role": "user", "content": query})

    start = time.time()
    total_usage = {"prompt_tokens": 0, "completion_tokens": 0, "total_tokens": 0}
    total_cost = 0.0
    model_used = "n/a"
    executed = 0
    widget_blocks: List[dict] = []
    # First signal wins: if a turn touched several products, the merchant
    # gets one callback offer about one thing, not a pile of them.
    lead_signal: Optional[dict] = None

    # +1: guarantees one final call with tools disabled once the execution
    # budget is spent, so the turn always ends in a real text answer
    # rather than silently falling through to None on the very call that
    # would have produced one.
    for _ in range(MAX_TOOL_CALLS_PER_MESSAGE + 1):
        if time.time() - start > MAX_TOTAL_SECONDS:
            logger.warning(f"Tool-calling time budget exceeded for chatbot {chatbot_id}")
            from app.services.rag_service import _log_event
            _log_event(db, conversation_id, chatbot_id, "tool_budget_exceeded", {"reason": "time"})
            return None

        offer_tools = executed < MAX_TOOL_CALLS_PER_MESSAGE
        try:
            message, model_used, usage, cost = _tool_calling_chat(
                db, messages, tools_schema if offer_tools else None, max_tokens, temperature
            )
        except Exception as e:
            logger.error(f"Tool-calling LLM call failed, falling back to normal pipeline: {e}")
            return None

        total_usage["prompt_tokens"] += usage.get("prompt_tokens", 0) or 0
        total_usage["completion_tokens"] += usage.get("completion_tokens", 0) or 0
        total_usage["total_tokens"] += usage.get("total_tokens", 0) or 0
        total_cost += cost

        tool_calls = message.get("tool_calls") if offer_tools else None
        if not tool_calls:
            latency_ms = int((time.time() - start) * 1000)
            return {
                "response": message.get("content") or "",
                "chunk_ids": [], "scores": [], "sources": [],
                "prompt_tokens": total_usage["prompt_tokens"],
                "completion_tokens": total_usage["completion_tokens"],
                "total_tokens": total_usage["total_tokens"],
                "cost_toman": round(total_cost, 4),
                "model": model_used, "latency_ms": latency_ms,
                "is_fallback": False, "is_unanswered": False, "finish_reason": "tool_stop",
                "widget_blocks": widget_blocks,
                "lead_signal": lead_signal,
            }

        messages.append({"role": "assistant", "content": message.get("content"), "tool_calls": tool_calls})
        for call in tool_calls:
            if executed >= MAX_TOOL_CALLS_PER_MESSAGE:
                messages.append({
                    "role": "tool", "tool_call_id": call.get("id", ""),
                    "content": json.dumps({"error": "Tool call limit reached for this message."}),
                })
                continue
            executed += 1
            result = _execute_tool_call(db, chatbot_id, conversation_id, call, tools)
            fn_name = call.get("function", {}).get("name", "")

            if lead_signal is None:
                try:
                    call_args = json.loads(call.get("function", {}).get("arguments") or "{}")
                except (ValueError, TypeError):
                    call_args = {}
                if isinstance(call_args, dict):
                    lead_signal = _detect_lead_signal(fn_name, call_args, result)

            block = _build_widget_block(fn_name, result)
            if block:
                widget_blocks.append(block)
                if block["type"] == "cart_links":
                    _log_cart_links(db, conversation_id, chatbot_id, block["items"])
                elif block["type"] == "add_to_cart":
                    pass  # intent only — see cartEvent()/cart_add_succeeded for the real outcome
                elif block["type"] == "payment_link_preview":
                    pass  # preview only — see createPaymentLink()/payment_link_created for the real outcome
                elif block["type"] == "order_status_otp":
                    pass  # intent only — no SMS and no lookup happened yet; see order_status_viewed
                else:
                    _log_product_mentions(db, conversation_id, chatbot_id, fn_name, block["products"])
                    # An explicit comparison and a set of options shown side
                    # by side are both matchups, but only one of them is the
                    # customer asking — see _log_compared_pairs().
                    _log_compared_pairs(
                        db, conversation_id, chatbot_id, block["products"],
                        "compared_pair" if fn_name == "compare_products" else "co_presented",
                    )
            messages.append({
                "role": "tool",
                "tool_call_id": call.get("id", ""),
                "content": json.dumps(result, ensure_ascii=False),
            })

    logger.warning(f"Tool-calling loop exhausted {MAX_TOOL_CALLS_PER_MESSAGE + 1} iterations without a final answer for chatbot {chatbot_id}")
    return None
