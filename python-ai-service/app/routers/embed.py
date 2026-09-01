import json
import logging
from fastapi import APIRouter, Depends, HTTPException
from sqlalchemy.orm import Session
from sqlalchemy import text
from app.core.database import get_db, set_schema
from app.models.schemas import EmbedRequest
from app.services.embedding_service import chunk_text, get_embeddings, count_tokens

logger = logging.getLogger(__name__)
router = APIRouter()

@router.post("/embed/document")
def embed_document(req: EmbedRequest, db: Session = Depends(get_db)):
    set_schema(db, req.schema_name)

    row = db.execute(text(
        "SELECT id, raw_content, source_type, title, metadata "
        "FROM documents WHERE id = CAST(:id AS uuid) AND chatbot_id = CAST(:cid AS uuid)"
    ), {"id": req.document_id, "cid": req.chatbot_id}).fetchone()

    if not row:
        raise HTTPException(404, "Document not found")

    doc_id, content, src_type, title, metadata = row
    if not content or not content.strip():
        db.execute(text("UPDATE documents SET status='indexed', chunk_count=0, indexed_at=now() WHERE id=CAST(:id AS uuid)"),
                   {"id": str(doc_id)})
        db.commit()
        return {"document_id": str(doc_id), "chunks": 0}

    # Delete old chunks
    db.execute(text("DELETE FROM chunks WHERE document_id = CAST(:id AS uuid)"), {"id": str(doc_id)})
    db.commit()

    # Chunk
    chunks = chunk_text(content)
    if not chunks:
        db.execute(text("UPDATE documents SET status='indexed', chunk_count=0, indexed_at=now() WHERE id=CAST(:id AS uuid)"),
                   {"id": str(doc_id)})
        db.commit()
        return {"document_id": str(doc_id), "chunks": 0}

    # Embed
    embeddings = get_embeddings(chunks)

    base_meta = dict(metadata or {})
    base_meta.update({"title": title, "source_type": src_type})

    for i, (chunk_val, embedding) in enumerate(zip(chunks, embeddings)):
        emb_str   = "[" + ",".join(str(x) for x in embedding) + "]"
        tok_count = count_tokens(chunk_val)
        chunk_meta = dict(base_meta)
        chunk_meta["chunk_index"] = i
        db.execute(text("""
            INSERT INTO chunks (id, document_id, chatbot_id, chunk_index, content, embedding, metadata, token_count, embedding_model, created_at)
            VALUES (gen_random_uuid(), CAST(:did AS uuid), CAST(:cid AS uuid), :idx, :content, CAST(:emb AS vector), CAST(:meta AS jsonb), :toks, :model, now())
        """), {
            "did": str(doc_id), "cid": req.chatbot_id, "idx": i,
            "content": chunk_val, "emb": emb_str, "meta": json.dumps(chunk_meta),
            "toks": tok_count, "model": "text-embedding-004",
        })

    db.execute(text("UPDATE documents SET status='indexed', chunk_count=:cnt, indexed_at=now() WHERE id=CAST(:id AS uuid)"),
               {"cnt": len(chunks), "id": str(doc_id)})
    db.commit()

    logger.info(f"Embedded document {doc_id}: {len(chunks)} chunks")
    return {"document_id": str(doc_id), "chunks": len(chunks), "status": "indexed"}

@router.delete("/embed/chatbot/{chatbot_id}")
def delete_chatbot_embeddings(chatbot_id: str, schema_name: str, db: Session = Depends(get_db)):
    set_schema(db, schema_name)
    db.execute(text("DELETE FROM chunks WHERE chatbot_id = CAST(:cid AS uuid)"), {"cid": chatbot_id})
    db.commit()
    return {"status": "deleted", "chatbot_id": chatbot_id}
