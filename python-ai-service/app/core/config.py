from pydantic_settings import BaseSettings

class Settings(BaseSettings):
    APP_ENV: str = "production"
    INTERNAL_SECRET: str = "change-me"
    # Laravel's raw APP_KEY bytes (base64), used only to decrypt
    # llm_provider_profiles.api_key — see app/Support/LlmKeyCrypto.php on the
    # Laravel side for the encryption format. Must be kept equal to the
    # laravel-backend .env's APP_KEY whenever that's rotated.
    HAMAN_ENCRYPTION_KEY: str = ""
    DATABASE_URL: str = "postgresql://haman_user:secret@postgres:5432/haman_saas"
    REDIS_URL: str = "redis://:secret@redis:6379/0"
    GEMINI_API_KEY: str = ""
    GEMINI_CHAT_MODEL: str = "gemini-2.5-flash"
    GEMINI_EMBEDDING_MODEL: str = "models/gemini-embedding-001"
    GEMINI_EMBEDDING_DIMS: int = 3072
    # Admin-set, same "unpriced defaults to 0" philosophy as
    # LlmProviderProfile's per-1M-token prices (see rag_service.py's
    # _compute_cost_toman()) — a PDF-heavy catalog's embedding cost is
    # real money (one embedding-API call per chunk), and this is what
    # lets the customer portal show it instead of silently absorbing it.
    EMBEDDING_PRICE_PER_1M_TOMAN: float = 0
    GROQ_API_KEY: str = ""
    XAI_API_KEY: str = ""
    XAI_CHAT_MODEL: str = "grok-2-latest"
    LOG_LEVEL: str = "info"
    MAX_TOKENS_RESPONSE: int = 800
    DEFAULT_TOP_K: int = 8
    DEFAULT_THRESHOLD: float = 0.60
    HOST: str = "0.0.0.0"
    PORT: int = 8001
    WORKERS: int = 2

    class Config:
        env_file = ".env"
        extra = "ignore"

settings = Settings()