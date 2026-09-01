from fastapi import APIRouter, Depends
from sqlalchemy.orm import Session
import google.generativeai as genai
from app.core.database import get_db, set_schema
from app.core.config import settings
from app.models.schemas import SemanticSearchRequest
from app.services.rag_service import retrieve_chunks

router  = APIRouter()
genai.configure(api_key=settings.GEMINI_API_KEY)

@router.post("/search/semantic")
def semantic_search(req: SemanticSearchRequest, db: Session = Depends(get_db)):
    set_schema(db, req.schema_name)
    emb = genai.embed_content(
        model=settings.GEMINI_EMBEDDING_MODEL,
        content=req.query,
        task_type="retrieval_query",
    )["embedding"]
    chunks = retrieve_chunks(db, req.chatbot_id, emb, req.top_k, req.threshold)
    return {
        "query": req.query,
        "results": [{"id": c["id"], "content": c["content"][:300], "metadata": c["metadata"], "similarity": c["similarity"]} for c in chunks],
        "count": len(chunks),
    }
