import re
import logging
import requests
from typing import List
from app.core.config import settings

logger = logging.getLogger(__name__)

GEMINI_V1_EMBED_URL = "https://generativelanguage.googleapis.com/v1/models/gemini-embedding-001:embedContent"

def count_tokens(text: str) -> int:
    return max(1, int(len(text.split()) * 1.3))

def chunk_text(text: str, chunk_size: int = 512, overlap: int = 64) -> List[str]:
    if not text or not text.strip():
        return []
    sentences = re.split(r'(?<=[.!?؟])\s+', text.strip())
    chunks, current, cur_toks = [], [], 0
    for sentence in sentences:
        s_toks = count_tokens(sentence)
        if cur_toks + s_toks > chunk_size and current:
            chunks.append(" ".join(current))
            overlap_sents, ol_toks = [], 0
            for s in reversed(current):
                st = count_tokens(s)
                if ol_toks + st <= overlap:
                    overlap_sents.insert(0, s)
                    ol_toks += st
                else:
                    break
            current, cur_toks = overlap_sents, ol_toks
        current.append(sentence)
        cur_toks += s_toks
    if current:
        chunks.append(" ".join(current))
    return [c for c in chunks if c.strip()]

def _embed_via_rest(text: str, task_type: str = "RETRIEVAL_DOCUMENT") -> List[float]:
    resp = requests.post(
        f"{GEMINI_V1_EMBED_URL}?key={settings.GEMINI_API_KEY}",
        json={
            "model": "models/gemini-embedding-001",
            "content": {"parts": [{"text": text}]},
            "taskType": task_type,
        },
        timeout=30,
    )
    resp.raise_for_status()
    return resp.json()["embedding"]["values"]

def get_embeddings(texts: List[str]) -> List[List[float]]:
    if not texts:
        return []
    results = []
    for t in texts:
        t = t.replace("\n", " ").strip()[:8000]
        try:
            results.append(_embed_via_rest(t, "RETRIEVAL_DOCUMENT"))
        except Exception as e:
            logger.error(f"Embedding error: {e}")
            raise
    return results