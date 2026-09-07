import json
import logging
from fastapi import APIRouter, Depends, HTTPException
from sqlalchemy.orm import Session
from sqlalchemy import text
from app.core.config import settings
from app.core.database import get_db, set_schema
from app.models.schemas import EmbedRequest
from app.services.embedding_service import chunk_text, get_embeddings, count_tokens
from app.services import pdf_service

logger = logging.getLogger(__name__)
router = APIRouter()

@router.post("/embed/document")
def embed_document(req: EmbedRequest, db: Session = Depends(get_db)):
    set_schema(db, req.schema_name)

    row = db.execute(text(
        "SELECT id, raw_content, source_type, title, metadata, language, source_url "
        "FROM documents WHERE id = CAST(:id AS uuid) AND chatbot_id = CAST(:cid AS uuid)"
    ), {"id": req.document_id, "cid": req.chatbot_id}).fetchone()

    if not row:
        raise HTTPException(404, "Document not found")

    doc_id, content, src_type, title, metadata, doc_language, source_url = row
    # Postgres ships no Persian stemmer/dictionary — 'simple' (tokenize +
    # lowercase, no stemming) is the honest option for fa; 'english' gets
    # real stemming for everything else, matching this app's fa/en-only
    # convention elsewhere (WidgetDefaults, SetLocale, ...).
    tsv_config = 'simple' if doc_language == 'fa' else 'english'

    if src_type == 'product_attachment':
        return _embed_pdf_attachment(db, doc_id, req.chatbot_id, source_url, title, metadata, tsv_config)

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
            INSERT INTO chunks (id, document_id, chatbot_id, chunk_index, content, embedding, content_tsv, metadata, token_count, embedding_model, created_at)
            VALUES (gen_random_uuid(), CAST(:did AS uuid), CAST(:cid AS uuid), :idx, :content, CAST(:emb AS vector), to_tsvector(CAST(:tsconfig AS regconfig), :content), CAST(:meta AS jsonb), :toks, :model, now())
        """), {
            "did": str(doc_id), "cid": req.chatbot_id, "idx": i,
            "content": chunk_val, "emb": emb_str, "tsconfig": tsv_config, "meta": json.dumps(chunk_meta),
            "toks": tok_count, "model": "text-embedding-004",
        })

    db.execute(text("UPDATE documents SET status='indexed', chunk_count=:cnt, indexed_at=now() WHERE id=CAST(:id AS uuid)"),
               {"cnt": len(chunks), "id": str(doc_id)})
    db.commit()

    logger.info(f"Embedded document {doc_id}: {len(chunks)} chunks")
    return {"document_id": str(doc_id), "chunks": len(chunks), "status": "indexed"}

def _skip_document(db: Session, doc_id, reason: str) -> dict:
    """A deliberate business-rule skip (too large, too many pages, no
    extractable text) — never raised as an exception, so
    EmbedDocumentJob.php doesn't treat it as a transient failure worth
    retrying. Logged both here and in documents.error_message so the
    customer portal's attachment list can show *why* a file was skipped."""
    logger.warning(f"Skipping PDF document {doc_id}: {reason}")
    db.execute(text("""
        UPDATE documents SET status='skipped', chunk_count=0, error_message=:msg, indexed_at=now()
        WHERE id=CAST(:id AS uuid)
    """), {"id": str(doc_id), "msg": reason})
    db.commit()
    return {"document_id": str(doc_id), "chunks": 0, "status": "skipped", "reason": reason}


def _embed_pdf_attachment(db: Session, doc_id, chatbot_id: str, source_url: str, title: str, metadata: dict, tsv_config: str) -> dict:
    """The product-attachment counterpart to the generic chunk/embed loop
    above — the differences are exactly what a PDF needs and a plain-text
    document doesn't: download the file, extract per-page text (so every
    chunk can cite an exact page), and skip loudly (not crash) on a file
    that's oversized, has too many pages, or turns out to be a scan with no
    text layer. See pdf_service.py's module docstring for the full
    rationale — this function is the DB-writing orchestration around it.
    """
    if not source_url:
        return _skip_document(db, doc_id, "No source URL for this attachment")

    try:
        pdf_bytes = pdf_service.download_pdf(source_url)
    except pdf_service.PdfTooLargeError:
        return _skip_document(db, doc_id, f"PDF exceeds the {pdf_service.MAX_PDF_BYTES // (1024*1024)}MB size limit")

    try:
        pages = pdf_service.extract_pdf_pages(pdf_bytes)
    except pdf_service.PdfTooManyPagesError:
        return _skip_document(db, doc_id, f"PDF exceeds the {pdf_service.MAX_PDF_PAGES}-page limit")

    if not pdf_service.is_extractable(pages):
        return _skip_document(db, doc_id, "No extractable text found (likely a scanned PDF) — OCR is not currently supported")

    db.execute(text("DELETE FROM chunks WHERE document_id = CAST(:id AS uuid)"), {"id": str(doc_id)})
    db.commit()

    pdf_chunks = pdf_service.chunk_pdf_pages(pages)
    if not pdf_chunks:
        return _skip_document(db, doc_id, "No text content after chunking")

    embeddings = get_embeddings([c["content"] for c in pdf_chunks])

    base_meta = dict(metadata or {})
    base_meta.update({"title": title, "source_type": "product_attachment", "url": source_url})

    for i, (pdf_chunk, embedding) in enumerate(zip(pdf_chunks, embeddings)):
        emb_str = "[" + ",".join(str(x) for x in embedding) + "]"
        chunk_meta = dict(base_meta)
        chunk_meta["chunk_index"] = i
        chunk_meta["page"] = pdf_chunk["page"]
        db.execute(text("""
            INSERT INTO chunks (id, document_id, chatbot_id, chunk_index, content, embedding, content_tsv, metadata, token_count, embedding_model, created_at)
            VALUES (gen_random_uuid(), CAST(:did AS uuid), CAST(:cid AS uuid), :idx, :content, CAST(:emb AS vector), to_tsvector(CAST(:tsconfig AS regconfig), :content), CAST(:meta AS jsonb), :toks, :model, now())
        """), {
            "did": str(doc_id), "cid": chatbot_id, "idx": i,
            "content": pdf_chunk["content"], "emb": emb_str, "tsconfig": tsv_config, "meta": json.dumps(chunk_meta),
            "toks": count_tokens(pdf_chunk["content"]), "model": "text-embedding-004",
        })

    cost_toman = pdf_service.compute_embedding_cost_toman(pdf_chunks, settings.EMBEDDING_PRICE_PER_1M_TOMAN)
    doc_meta = dict(metadata or {})
    doc_meta.update({
        "page_count": len(pages),
        "file_size_bytes": len(pdf_bytes),
        "embedding_cost_toman": round(cost_toman, 2),
    })
    db.execute(text("""
        UPDATE documents
        SET status='indexed', chunk_count=:cnt, indexed_at=now(), metadata=CAST(:meta AS jsonb), error_message=NULL
        WHERE id=CAST(:id AS uuid)
    """), {"cnt": len(pdf_chunks), "id": str(doc_id), "meta": json.dumps(doc_meta)})
    db.commit()

    logger.info(f"Embedded PDF document {doc_id}: {len(pdf_chunks)} chunks across {len(pages)} pages, cost {cost_toman:.2f} toman")
    return {"document_id": str(doc_id), "chunks": len(pdf_chunks), "status": "indexed", "pages": len(pages)}


@router.delete("/embed/chatbot/{chatbot_id}")
def delete_chatbot_embeddings(chatbot_id: str, schema_name: str, db: Session = Depends(get_db)):
    set_schema(db, schema_name)
    db.execute(text("DELETE FROM chunks WHERE chatbot_id = CAST(:cid AS uuid)"), {"cid": chatbot_id})
    db.commit()
    return {"status": "deleted", "chatbot_id": chatbot_id}
