from pydantic import BaseModel
from typing import Optional, List

class ChatRequest(BaseModel):
    chatbot_id: str
    # Optional only for backward compatibility with any in-flight request
    # from before this field existed — every real caller (ChatService::
    # gatewayPayload()) always sends it. Needed to write conversation_events
    # rows (retrieval/response/unanswered/product_mentioned) with a real
    # conversation_id instead of none at all.
    conversation_id: Optional[str] = None
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
    rerank_enabled: bool = False
    rerank_threshold: float = 0.500
    business_name: Optional[str] = None
    # Which tool-registry tools (app/services/tools/registry.py) this
    # chatbot may call — empty by default, matching every chatbot that
    # existed before tool calling shipped. Names the model-supplied
    # arguments can never widen: chatbot_id/schema_name for every tool
    # handler always come from this request's own fields, never from the
    # model's tool-call arguments (see run_tool_calling_pipeline()).
    enabled_tools: List[str] = []

class ChatResponse(BaseModel):
    response: str
    chunk_ids: List[str] = []
    scores: List[float] = []
    sources: List[dict] = []
    prompt_tokens: int = 0
    completion_tokens: int = 0
    total_tokens: int = 0
    cost_toman: float = 0
    model: str = ""
    latency_ms: int = 0
    is_fallback: bool = False
    is_unanswered: bool = False
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
