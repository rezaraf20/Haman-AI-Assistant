import time
import logging
import requests as _requests
from typing import List, Optional
from sqlalchemy.orm import Session
from sqlalchemy import text
import google.generativeai as genai
from app.core.config import settings
<<<<<<< Updated upstream
from app.services.embedding_service import get_embeddings
=======
from app.services import llm_provider_service
>>>>>>> Stashed changes

logger = logging.getLogger(__name__)
genai.configure(api_key=settings.GEMINI_API_KEY)

GEMINI_V1_EMBED_URL = "https://generativelanguage.googleapis.com/v1/models/gemini-embedding-001:embedContent"

GROUNDING_RULES = (
    "You are a helpful AI assistant. Answer using facts explicitly stated in the "
    "provided context below. Never invent, estimate, or guess specific facts — prices, "
    "phone numbers, emails, dates, or figures of any kind — that are not explicitly "
    "present in the context, even if the user asks directly and even if a plausible "
    "number would be helpful. If a specific detail (e.g. an exact price) isn't in the "
    "context, don't give a blanket refusal — stay helpful: share whatever related "
    "information the context does contain, then note plainly that this specific detail "
    "isn't listed and suggest the user contact the business directly for it. Do not "
    "present estimates or made-up figures as if they were real.\n\n"
    "You are part of this business's own team, not an outside assistant describing "
    "it from afar. Always speak as 'we'/'our' ('we offer...', 'our team can...', "
    "'we're based in...') — never refer to the business by name in the third person "
    "as if it were some other company you're telling the user about (wrong: 'Company "
    "X can help you with...' or 'working with Company X is the best option'; right: "
    "'we can help you with...'). Never recommend, compare against, or promote any "
    "other company, product, or tool as an alternative — even one mentioned in the "
    "context purely as background/comparison information. If the context happens to "
    "mention a competing option, do not repeat or suggest it; redirect the "
    "conversation back to what this business itself offers."
)
DEFAULT_SYSTEM = GROUNDING_RULES

def retrieve_chunks(db: Session, chatbot_id: str, query_embedding: List[float], top_k: int = 8, threshold: float = 0.60) -> List[dict]:
    try:
        emb_str = "[" + ",".join(str(x) for x in query_embedding) + "]"
        rows = db.execute(text("""
            SELECT id::text, content, metadata,
                   1 - (embedding <=> CAST(:emb AS vector)) AS similarity
            FROM chunks
            WHERE chatbot_id = CAST(:cid AS uuid)
              AND embedding IS NOT NULL
              AND 1 - (embedding <=> CAST(:emb AS vector)) > :threshold
            ORDER BY embedding <=> CAST(:emb AS vector)
            LIMIT :top_k
        """), {"emb": emb_str, "cid": chatbot_id, "threshold": threshold, "top_k": top_k}).fetchall()
        return [{"id": str(r[0]), "content": r[1], "metadata": r[2] or {}, "similarity": float(r[3])} for r in rows]
    except Exception as e:
        logger.error(f"Vector search error: {e}")
        return []

def _embed_query(query: str) -> List[float]:
    resp = _requests.post(
        f"{GEMINI_V1_EMBED_URL}?key={settings.GEMINI_API_KEY}",
        json={
            "model": "models/gemini-embedding-001",
            "content": {"parts": [{"text": query}]},
            "taskType": "RETRIEVAL_QUERY",
        },
        timeout=30,
    )
    resp.raise_for_status()
    return resp.json()["embedding"]["values"]

<<<<<<< Updated upstream
async def run_rag_pipeline(db: Session, chatbot_id: str, query: str, history: List[dict],
=======

def _openai_compatible_chat(profile: dict, prompt: str, max_tokens: int, temperature: float) -> tuple[str, dict]:
    """groq / xai / openai_compatible all share this same request/response shape
    (choices[0].message.content, usage{prompt_tokens,completion_tokens,total_tokens})
    — one function serves all three provider types, parameterized by the DB row."""
    resp = _requests.post(
        f"{profile['base_url'].rstrip('/')}/chat/completions",
        headers={
            "Authorization": f"Bearer {profile['api_key']}",
            "Content-Type": "application/json",
        },
        json={
            "model": profile['model_name'],
            "messages": [{"role": "user", "content": prompt}],
            "max_tokens": profile.get('max_tokens_response') or max_tokens,
            "temperature": temperature,
        },
        timeout=profile.get('timeout_seconds') or 30,
    )
    resp.raise_for_status()
    body = resp.json()
    return body["choices"][0]["message"]["content"], body.get("usage", {})


def _chat_completion(db: Session, prompt: str, max_tokens: int, temperature: float) -> tuple[str, str, dict]:
    """Try each active provider profile in priority order (admin-configured via
    Filament), falling through to the next on any failure — a rate-limited or
    down provider no longer takes the whole product down.

    Returns (answer, model_label, usage) — usage is the provider's OpenAI-
    compatible {prompt_tokens, completion_tokens, total_tokens} dict, empty if
    it didn't include one.
    """
    profiles = llm_provider_service.get_active_profiles(db)
    if not profiles:
        raise RuntimeError("No active LLM provider profiles configured")

    last_error = None
    for profile in profiles:
        # One retry per provider before moving on — Groq in particular has
        # shown transient failures (network blip, brief overload) that
        # succeed a second later; a customer shouldn't get a dead-end "could
        # not generate a response" for something that would've worked on
        # retry. Skipped for 429s specifically: the quota that just got
        # rejected won't have refilled a second later, so retrying just
        # burns time before falling through to the next provider anyway.
        attempts = 2
        for attempt in range(attempts):
            try:
                answer, usage = _openai_compatible_chat(profile, prompt, max_tokens, temperature)
                llm_provider_service.record_outcome(db, profile['name'], success=True)
                return answer, f"{profile['provider']}/{profile['model_name']}", usage
            except Exception as e:
                last_error = e
                status = getattr(getattr(e, 'response', None), 'status_code', None)
                is_last_attempt = attempt == attempts - 1
                if status == 429 or is_last_attempt:
                    logger.warning(f"LLM provider '{profile['name']}' failed, trying next: {e}")
                    llm_provider_service.record_outcome(db, profile['name'], success=False)
                    break
                logger.warning(f"LLM provider '{profile['name']}' failed (attempt {attempt + 1}/{attempts}), retrying: {e}")
                time.sleep(1)

    raise last_error


async def run_rag_pipeline(
    db: Session, chatbot_id: str, query: str, history: List[dict],
>>>>>>> Stashed changes
    system_prompt: Optional[str], fallback_resp: Optional[str], llm_model: str,
    top_k: int, threshold: float, temperature: float, max_tokens: int, language: str) -> dict:

    start = time.time()
    model = genai.GenerativeModel(settings.GEMINI_CHAT_MODEL)

    # Embed query using v1 REST API
    try:
        query_embedding = _embed_query(query)
    except Exception as e:
        logger.error(f"Query embedding error: {e}")
        return {
            "response": fallback_resp or "Sorry, I cannot process your request right now.",
            "chunk_ids": [], "scores": [], "sources": [],
            "prompt_tokens": 0, "completion_tokens": 0, "total_tokens": 0,
<<<<<<< Updated upstream
            "model": settings.GEMINI_CHAT_MODEL,
            "latency_ms": int((time.time()-start)*1000),
=======
            "model": "n/a",
            "latency_ms": int((time.time() - start) * 1000),
>>>>>>> Stashed changes
            "is_fallback": True, "finish_reason": "error",
        }

    # Retrieve
    chunks = retrieve_chunks(db, chatbot_id, query_embedding, top_k, threshold)
    is_fallback = len(chunks) == 0

    if is_fallback and fallback_resp:
        return {
            "response": fallback_resp, "chunk_ids": [], "scores": [], "sources": [],
            "prompt_tokens": 0, "completion_tokens": 0, "total_tokens": 0,
<<<<<<< Updated upstream
            "model": settings.GEMINI_CHAT_MODEL,
            "latency_ms": int((time.time()-start)*1000),
=======
            "model": "n/a",
            "latency_ms": int((time.time() - start) * 1000),
>>>>>>> Stashed changes
            "is_fallback": True, "finish_reason": "fallback",
        }

    # Build context
    context_parts = []
    for i, chunk in enumerate(chunks, 1):
        meta  = chunk.get("metadata", {})
        title = meta.get("title", "")
        header = f"[Source {i}]" + (f" {title}" if title else "")
        context_parts.append(f"{header}\n{chunk['content']}")
    context = "\n\n---\n\n".join(context_parts)

<<<<<<< Updated upstream
    # Build prompt
    sys_p = system_prompt or DEFAULT_SYSTEM
=======
    # The grounding rules must always apply, regardless of any per-chatbot custom
    # prompt — previously a custom system_prompt fully *replaced* DEFAULT_SYSTEM
    # instead of adding to it, silently dropping the "don't invent facts" rule and
    # leaving small models free to fabricate plausible-sounding prices/contacts.
    sys_p = GROUNDING_RULES
    if system_prompt:
        sys_p += f"\n\n{system_prompt}"
>>>>>>> Stashed changes
    if context:
        sys_p += f"\n\n=== CONTEXT ===\n{context}\n=== END CONTEXT ==="

    lang_map = {"fa": "Persian/Farsi", "ar": "Arabic", "en": "English"}
    if language and language != "auto" and language in lang_map:
        sys_p += f"\n\nIMPORTANT: Always respond in {lang_map[language]}."

    hist_text = ""
    for h in history[-12:]:
        if h.get("role") in ("user", "assistant") and h.get("content"):
            role_label = "User" if h["role"] == "user" else "Assistant"
            hist_text += f"{role_label}: {h['content']}\n"

    full_prompt = f"{sys_p}\n\n{hist_text}User: {query}\nAssistant:"

<<<<<<< Updated upstream
    try:
        resp = model.generate_content(
            full_prompt,
            generation_config=genai.types.GenerationConfig(
                temperature=temperature,
                max_output_tokens=max_tokens,
            ),
        )
        answer = resp.text or ""
        usage  = getattr(resp, "usage_metadata", None)
        p_toks = getattr(usage, "prompt_token_count", 0) if usage else 0
        c_toks = getattr(usage, "candidates_token_count", 0) if usage else 0
        t_toks = p_toks + c_toks
=======
    model_used = "n/a"
    usage = {}
    try:
        answer, model_used, usage = _chat_completion(db, full_prompt, max_tokens, temperature)
>>>>>>> Stashed changes
    except Exception as e:
        logger.error(f"Gemini LLM error: {e}")
        answer = fallback_resp or "Sorry, I could not generate a response."
        p_toks = c_toks = t_toks = 0
        is_fallback = True

    sources = []
    for c in chunks[:3]:
        m = c.get("metadata", {})
        src = {}
        if m.get("title"): src["title"] = m["title"]
        if m.get("url"):   src["url"]   = m["url"]
        if m.get("type"):  src["type"]  = m["type"]
        if src: sources.append(src)

    return {
        "response": answer,
        "chunk_ids": [c["id"] for c in chunks],
        "scores": [round(c["similarity"], 4) for c in chunks],
        "sources": sources,
<<<<<<< Updated upstream
        "prompt_tokens": p_toks,
        "completion_tokens": c_toks,
        "total_tokens": t_toks,
        "model": settings.GEMINI_CHAT_MODEL,
        "latency_ms": int((time.time()-start)*1000),
=======
        "prompt_tokens": usage.get("prompt_tokens", 0),
        "completion_tokens": usage.get("completion_tokens", 0),
        "total_tokens": usage.get("total_tokens", 0),
        "model": model_used,
        "latency_ms": int((time.time() - start) * 1000),
>>>>>>> Stashed changes
        "is_fallback": is_fallback,
        "finish_reason": "stop",
    }