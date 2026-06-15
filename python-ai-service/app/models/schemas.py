from pydantic import BaseModel
from typing import Optional, List

class ChatRequest(BaseModel):
    chatbot_id: str
    session_id: str
    query: str
    history: List[dict] = []
    schema_name: str
    top_k: int = 8
    threshold: float = 0.60
    temperature: float = 0.3
    max_tokens: int = 800
    llm_model: str = "gemini-1.5-flash"
    language: str = "auto"
    system_prompt: Optional[str] = None
    fallback_response: Optional[str] = None

class ChatResponse(BaseModel):
    response: str
    chunk_ids: List[str] = []
    scores: List[float] = []
    sources: List[dict] = []
    prompt_tokens: int = 0
    completion_tokens: int = 0
    total_tokens: int = 0
    model: str = ""
    latency_ms: int = 0
    is_fallback: bool = False
    finish_reason: str = "stop"

class EmbedRequest(BaseModel):
    document_id: str
    chatbot_id: str
    schema_name: str

class SemanticSearchRequest(BaseModel):
    chatbot_id: str
    schema_name: str
    query: str
    top_k: int = 5
    threshold: float = 0.50
