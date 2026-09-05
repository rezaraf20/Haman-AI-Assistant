import json
from fastapi import APIRouter, Depends
from fastapi.responses import StreamingResponse
from sqlalchemy.orm import Session
from app.core.database import get_db, set_schema
from app.models.schemas import ChatRequest, ChatResponse
from app.services.rag_service import run_rag_pipeline, run_rag_pipeline_stream

router = APIRouter()

@router.post("/chat/complete", response_model=ChatResponse)
async def chat_complete(req: ChatRequest, db: Session = Depends(get_db)):
    set_schema(db, req.schema_name)
    result = await run_rag_pipeline(
        db=db, chatbot_id=req.chatbot_id, query=req.query, history=req.history,
        system_prompt=req.system_prompt, fallback_resp=req.fallback_response,
        llm_model=req.llm_model, top_k=req.top_k, threshold=req.threshold,
        temperature=req.temperature, max_tokens=req.max_tokens, language=req.language,
        rerank_enabled=req.rerank_enabled, rerank_threshold=req.rerank_threshold,
        business_name=req.business_name,
    )
    return ChatResponse(**result)


@router.post("/chat/stream")
async def chat_stream(req: ChatRequest, db: Session = Depends(get_db)):
    """Server-Sent Events counterpart to /chat/complete — used by ChatController::
    sendMessageStream() on the Laravel side when the caller's Accept header asks
    for text/event-stream. `db` (a generator-based FastAPI dependency) stays open
    for the whole streamed response, not just until this function returns — that's
    the standard, supported FastAPI lifecycle for a yield-dependency used with
    StreamingResponse, not something fragile being relied on here.
    """
    set_schema(db, req.schema_name)

    async def event_source():
        async for kind, payload in run_rag_pipeline_stream(
            db=db, chatbot_id=req.chatbot_id, query=req.query, history=req.history,
            system_prompt=req.system_prompt, fallback_resp=req.fallback_response,
            llm_model=req.llm_model, top_k=req.top_k, threshold=req.threshold,
            temperature=req.temperature, max_tokens=req.max_tokens, language=req.language,
            rerank_enabled=req.rerank_enabled, rerank_threshold=req.rerank_threshold,
            business_name=req.business_name,
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
