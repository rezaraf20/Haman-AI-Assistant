from fastapi import APIRouter, Depends
from sqlalchemy.orm import Session
from app.core.database import get_db, set_schema
from app.models.schemas import ChatRequest, ChatResponse
from app.services.rag_service import run_rag_pipeline

router = APIRouter()

@router.post("/chat/complete", response_model=ChatResponse)
async def chat_complete(req: ChatRequest, db: Session = Depends(get_db)):
    set_schema(db, req.schema_name)
    result = await run_rag_pipeline(
        db=db, chatbot_id=req.chatbot_id, query=req.query, history=req.history,
        system_prompt=req.system_prompt, fallback_resp=req.fallback_response,
        llm_model=req.llm_model, top_k=req.top_k, threshold=req.threshold,
        temperature=req.temperature, max_tokens=req.max_tokens, language=req.language,
    )
    return ChatResponse(**result)
