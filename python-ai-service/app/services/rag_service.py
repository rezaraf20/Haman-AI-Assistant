import time
import logging
import requests as _requests
from typing import List, Optional
from sqlalchemy.orm import Session
from sqlalchemy import text
import google.generativeai as genai
from app.core.config import settings
from app.services.embedding_service import get_embeddings

logger = logging.getLogger(__name__)
genai.configure(api_key=settings.GEMINI_API_KEY)

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

async def run_rag_pipeline(db: Session, chatbot_id: str, query: str, history: List[dict],
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
            "model": settings.GEMINI_CHAT_MODEL,
            "latency_ms": int((time.time()-start)*1000),
            "is_fallback": True, "finish_reason": "error",
        }

    # Retrieve
    chunks = retrieve_chunks(db, chatbot_id, query_embedding, top_k, threshold)
    is_fallback = len(chunks) == 0

    if is_fallback and fallback_resp:
        return {
            "response": fallback_resp, "chunk_ids": [], "scores": [], "sources": [],
            "prompt_tokens": 0, "completion_tokens": 0, "total_tokens": 0,
            "model": settings.GEMINI_CHAT_MODEL,
            "latency_ms": int((time.time()-start)*1000),
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

    # Build prompt
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
        "prompt_tokens": p_toks,
        "completion_tokens": c_toks,
        "total_tokens": t_toks,
        "model": settings.GEMINI_CHAT_MODEL,
        "latency_ms": int((time.time()-start)*1000),
        "is_fallback": is_fallback,
        "finish_reason": "stop",
    }