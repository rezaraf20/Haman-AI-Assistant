from fastapi import APIRouter
from sqlalchemy import text
from app.core.database import engine
from app.core.config import settings
import redis as redis_lib

router = APIRouter()

@router.get("/health")
def health():
    db_ok    = "ok"
    redis_ok = "ok"
    try:
        with engine.connect() as conn:
            conn.execute(text("SELECT 1"))
    except Exception as e:
        db_ok = f"error: {str(e)[:50]}"
    try:
        r = redis_lib.from_url(settings.REDIS_URL, socket_timeout=2)
        r.ping()
    except Exception as e:
        redis_ok = f"error: {str(e)[:50]}"
    status = "ok" if db_ok == "ok" and redis_ok == "ok" else "degraded"
    return {"status": status, "database": db_ok, "redis": redis_ok,
            "gemini": "configured" if settings.GEMINI_API_KEY else "missing",
            "version": "1.0.0"}
