import time
import logging
import requests as _requests
from typing import List, Optional
from sqlalchemy.orm import Session
from sqlalchemy import text
from app.core.config import settings

logger = logging.getLogger(__name__)

GEMINI_V1_EMBED_URL = "https://generativelanguage.googleapis.com/v1/models/gemini-embedding-001:embedContent"

DEFAULT_SYSTEM = "You are a helpful AI assistant. Answer ONLY from the provided context. If unsure, say so."


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


def _groq_chat(prompt: str, max_tokens: int, temperature: float) -> str:
    resp = _requests.post(
        "https://api.groq.com/openai/v1/chat/completions",
        headers={
            "Authorization": f"Bearer {settings.GROQ_API_KEY}",
            "Content-Type": "application/json",
        },
        json={
            "model": "llama-3.1-8b-instant",
            "messages": [{"role": "user", "content": prompt}],
            "max_tokens": max_tokens,
            "temperature": temperature,
        },
        timeout=30,
    )
    resp.raise_for_status()
    return resp.json()["choices"][0]["message"]["content"]


def _xai_chat(prompt: str, max_tokens: int, temperature: float) -> str:
    resp = _requests.post(
        "https://api.x.ai/v1/chat/completions",
        headers={
            "Authorization": f"Bearer {settings.XAI_API_KEY}",
            "Content-Type": "application/json",
        },
        json={
            "model": settings.XAI_CHAT_MODEL,
            "messages": [{"role": "user", "content": prompt}],
            "max_tokens": max_tokens,
            "temperature": temperature,
        },
        timeout=30,
    )
    resp.raise_for_status()
    return resp.json()["choices"][0]["message"]["content"]


def _chat_completion(prompt: str, max_tokens: int, temperature: float) -> tuple[str, str]:
    """Try Groq first; fall back to xAI (e.g. on Groq rate limits) if configured."""
    try:
        return _groq_chat(prompt, max_tokens, temperature), "groq/llama-3.1-8b-instant"
    except Exception as e:
        if not settings.XAI_API_KEY:
            raise
        logger.warning(f"Groq LLM error, falling back to xAI: {e}")
        return _xai_chat(prompt, max_tokens, temperature), f"xai/{settings.XAI_CHAT_MODEL}"


async def run_rag_pipeline(
    db: Session, chatbot_id: str, query: str, history: List[dict],
    system_prompt: Optional[str], fallback_resp: Optional[str], llm_model: str,
    top_k: int, threshold: float, temperature: float, max_tokens: int, language: str
) -> dict:

    start = time.time()

    try:
        query_embedding = _embed_query(query)
    except Exception as e:
        logger.error(f"Query embedding error: {e}")
        return {
            "response": fallback_resp or "Sorry, I cannot process your request right now.",
            "chunk_ids": [], "scores": [], "sources": [],
            "prompt_tokens": 0, "completion_tokens": 0, "total_tokens": 0,
            "model": "groq/llama-3.1-8b-instant",
            "latency_ms": int((time.time() - start) * 1000),
            "is_fallback": True, "finish_reason": "error",
        }

    chunks = retrieve_chunks(db, chatbot_id, query_embedding, top_k, threshold)
    is_fallback = len(chunks) == 0

    if is_fallback and fallback_resp:
        return {
            "response": fallback_resp, "chunk_ids": [], "scores": [], "sources": [],
            "prompt_tokens": 0, "completion_tokens": 0, "total_tokens": 0,
            "model": "groq/llama-3.1-8b-instant",
            "latency_ms": int((time.time() - start) * 1000),
            "is_fallback": True, "finish_reason": "fallback",
        }

    context_parts = []
    for i, chunk in enumerate(chunks, 1):
        meta = chunk.get("metadata", {})
        title = meta.get("title", "")
        header = f"[Source {i}]" + (f" {title}" if title else "")
        context_parts.append(f"{header}\n{chunk['content']}")
    context = "\n\n---\n\n".join(context_parts)

    sys_p = system_prompt or DEFAULT_SYSTEM
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

    model_used = "groq/llama-3.1-8b-instant"
    try:
        answer, model_used = _chat_completion(full_prompt, max_tokens, temperature)
    except Exception as e:
        logger.error(f"LLM error (all providers failed): {e}")
        answer = fallback_resp or "Sorry, I could not generate a response."
        is_fallback = True

    sources = []
    for c in chunks[:3]:
        m = c.get("metadata", {})
        src = {}
        if m.get("title"): src["title"] = m["title"]
        if m.get("url"): src["url"] = m["url"]
        if m.get("type"): src["type"] = m["type"]
        if src: sources.append(src)

    return {
        "response": answer,
        "chunk_ids": [c["id"] for c in chunks],
        "scores": [round(c["similarity"], 4) for c in chunks],
        "sources": sources,
        "prompt_tokens": 0, "completion_tokens": 0, "total_tokens": 0,
        "model": model_used,
        "latency_ms": int((time.time() - start) * 1000),
        "is_fallback": is_fallback,
        "finish_reason": "stop",
    }