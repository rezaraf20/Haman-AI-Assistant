"""
Hamman AI Platform - Python AI Microservice
Company: شرکت هامان فناوران پیشرو
Author: Reza Rafiei
"""
from fastapi import FastAPI, Request, HTTPException, Depends
from fastapi.middleware.cors import CORSMiddleware
from contextlib import asynccontextmanager
import logging
import os

from app.core.config import settings
from app.routers import chat, embed, search, health

logging.basicConfig(
    level=settings.LOG_LEVEL.upper(),
    format="%(asctime)s %(levelname)s %(name)s: %(message)s"
)
logger = logging.getLogger(__name__)

@asynccontextmanager
async def lifespan(app: FastAPI):
    logger.info("Hamman AI Service starting...")
    yield
    logger.info("Hamman AI Service shutting down")

app = FastAPI(
    title="Hamman AI Service",
    version="1.0.0",
    lifespan=lifespan,
    docs_url="/docs" if os.getenv("APP_ENV") != "production" else None,
)

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_methods=["*"],
    allow_headers=["*"],
)

def verify_token(request: Request):
    auth  = request.headers.get("Authorization", "")
    token = auth.replace("Bearer ", "").strip()
    if token != settings.INTERNAL_SECRET:
        raise HTTPException(status_code=401, detail="Invalid token")

app.include_router(health.router, prefix="/ai", tags=["health"])
app.include_router(chat.router,   prefix="/ai", tags=["chat"],   dependencies=[Depends(verify_token)])
app.include_router(embed.router,  prefix="/ai", tags=["embed"],  dependencies=[Depends(verify_token)])
app.include_router(search.router, prefix="/ai", tags=["search"], dependencies=[Depends(verify_token)])

@app.get("/")
def root():
    return {"service": "Hamman AI", "status": "running", "version": "1.0.0"}
