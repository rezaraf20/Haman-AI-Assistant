"""
Retrieval quality evaluation: recall@5 and MRR@5, before (pure vector
similarity — retrieve_chunks(), unchanged) vs. after (hybrid search + RRF +
optional rerank — hybrid_retrieve()).

Ground truth is built from real production data, not a curated gold set:
for every historically *successfully answered* message (role=assistant,
is_fallback=false, retrieved_chunk_ids non-empty) across the platform's
tenant schemas, the paired user question is an eval item and the chunk
id(s) that message actually cited are its ground truth. This is an honest,
reproducible methodology — it measures "does the new pipeline still find
what the old pipeline actually used to answer this", not an idealized
benchmark — and is stated as such in the printed report rather than
presented as more than it is.

Run inside the python-ai-service container (needs DATABASE_URL,
GEMINI_API_KEY, and at least one active LLM provider profile for the rerank
leg):
    docker exec hamman_python_ai python scripts/eval_retrieval.py
"""
import sys
import os
import time
sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from sqlalchemy import text
from app.core.database import SessionLocal
from app.services.rag_service import retrieve_chunks, hybrid_retrieve, _embed_query

MAX_ITEMS_PER_TENANT = 25
TOP_N = 5


def get_tenant_schemas(db):
    rows = db.execute(text("SELECT DISTINCT schema_name FROM chatbot_index WHERE schema_name IS NOT NULL")).fetchall()
    return [r[0] for r in rows]


def collect_eval_items(db, schema):
    db.execute(text(f"SET search_path TO {schema}, public"))
    db.commit()
    rows = db.execute(text("""
        SELECT u.content AS question, a.retrieved_chunk_ids, a.chatbot_id
        FROM messages a
        JOIN messages u ON u.conversation_id = a.conversation_id
            AND u.role = 'user'
            AND u.created_at = (
                SELECT MAX(created_at) FROM messages u2
                WHERE u2.conversation_id = a.conversation_id
                  AND u2.role = 'user'
                  AND u2.created_at < a.created_at
            )
        WHERE a.role = 'assistant'
          AND a.is_fallback = false
          AND a.retrieved_chunk_ids IS NOT NULL
          AND jsonb_array_length(a.retrieved_chunk_ids) > 0
        ORDER BY a.created_at DESC
        LIMIT :limit
    """), {"limit": MAX_ITEMS_PER_TENANT}).fetchall()

    items = []
    for question, chunk_ids, chatbot_id in rows:
        gt = set(chunk_ids) if isinstance(chunk_ids, list) else set()
        if not gt or not question:
            continue
        items.append({"schema": schema, "question": question, "ground_truth": gt, "chatbot_id": str(chatbot_id)})
    db.execute(text("SET search_path TO public"))
    db.commit()
    return items


def get_chatbot_settings(db, schema, chatbot_id):
    db.execute(text(f"SET search_path TO {schema}, public"))
    db.commit()
    row = db.execute(text("""
        SELECT retrieval_top_k, retrieval_threshold, response_language, reranker_enabled, rerank_threshold
        FROM chatbots WHERE id = CAST(:id AS uuid)
    """), {"id": chatbot_id}).fetchone()
    db.execute(text("SET search_path TO public"))
    db.commit()
    if not row:
        return {"top_k": 8, "threshold": 0.60, "language": "en", "rerank_enabled": False, "rerank_threshold": 0.5}
    return {
        "top_k": row[0], "threshold": float(row[1]),
        "language": row[2] if row[2] and row[2] != "auto" else "en",
        "rerank_enabled": row[3], "rerank_threshold": float(row[4]),
    }


def score_result(retrieved_ids: list, ground_truth: set, top_n: int = TOP_N):
    top = retrieved_ids[:top_n]
    recall = 1.0 if any(rid in ground_truth for rid in top) else 0.0
    mrr = 0.0
    for rank, rid in enumerate(top, start=1):
        if rid in ground_truth:
            mrr = 1.0 / rank
            break
    return recall, mrr


def main():
    db = SessionLocal()
    try:
        schemas = get_tenant_schemas(db)
        print(f"Found {len(schemas)} tenant schema(s): {schemas}")

        items = []
        for schema in schemas:
            schema_items = collect_eval_items(db, schema)
            print(f"  {schema}: {len(schema_items)} eval item(s) from real answered messages")
            items.extend(schema_items)

        if not items:
            print("No eval items found (no tenant has a successfully answered message with recorded chunk ids yet). Aborting.")
            return

        print(f"\nTotal eval set: {len(items)} real questions\n")

        before_recalls, before_mrrs = [], []
        after_recalls, after_mrrs = [], []
        newly_answered = []  # items where "before" missed but "after" hit

        for i, item in enumerate(items, start=1):
            db.execute(text(f"SET search_path TO {item['schema']}, public"))
            db.commit()

            try:
                emb = _embed_query(item["question"])
            except Exception as e:
                print(f"  [{i}] embed failed, skipping: {e}")
                continue

            settings = get_chatbot_settings(db, item["schema"], item["chatbot_id"])

            # get_chatbot_settings() resets search_path to public when it
            # returns (mirrors collect_eval_items()'s own cleanup) — must be
            # switched back to the tenant schema before every query that
            # touches tenant-schema tables (chunks, chatbots), here and in
            # the AFTER block below.
            db.execute(text(f"SET search_path TO {item['schema']}, public"))
            db.commit()

            # BEFORE: retrieve_chunks() is untouched — the exact pre-hybrid-
            # search pure vector query, still live in rag_service.py for
            # /search/semantic.
            before_chunks = retrieve_chunks(db, item["chatbot_id"], emb, settings["top_k"], settings["threshold"])
            before_ids = [c["id"] for c in before_chunks]
            b_recall, b_mrr = score_result(before_ids, item["ground_truth"])
            before_recalls.append(b_recall)
            before_mrrs.append(b_mrr)

            # AFTER: hybrid search + RRF + rerank (using this chatbot's own
            # real, admin-configured settings — not a forced comparison mode).
            db.execute(text(f"SET search_path TO {item['schema']}, public"))
            db.commit()
            result = hybrid_retrieve(
                db, item["chatbot_id"], item["question"], emb,
                settings["top_k"], settings["threshold"], settings["language"],
                rerank_enabled=True, rerank_threshold=settings["rerank_threshold"],
            )
            after_ids = [c["id"] for c in result["chunks"]]
            a_recall, a_mrr = score_result(after_ids, item["ground_truth"])
            after_recalls.append(a_recall)
            after_mrrs.append(a_mrr)

            marker = ""
            if b_recall == 0.0 and a_recall == 1.0:
                marker = "  <-- newly answered by hybrid+rerank"
                newly_answered.append(item["question"])

            print(f"  [{i}/{len(items)}] before: recall={b_recall:.0f} mrr={b_mrr:.2f} | after: recall={a_recall:.0f} mrr={a_mrr:.2f}{marker}")

            # 25 back-to-back rerank calls is a self-inflicted burst no real
            # traffic pattern produces — space them out enough to stay under
            # Groq's per-minute rate limit rather than exhausting it against
            # ourselves and only ever exercising the failover/fallback path.
            time.sleep(2)

        n = len(before_recalls)
        print("\n" + "=" * 60)
        print(f"RESULTS  (n={n} real questions, top_{TOP_N})")
        print("=" * 60)
        print(f"BEFORE (pure vector similarity):")
        print(f"  recall@{TOP_N} = {sum(before_recalls)/n:.3f}")
        print(f"  MRR@{TOP_N}    = {sum(before_mrrs)/n:.3f}")
        print(f"AFTER (hybrid search + RRF + rerank):")
        print(f"  recall@{TOP_N} = {sum(after_recalls)/n:.3f}")
        print(f"  MRR@{TOP_N}    = {sum(after_mrrs)/n:.3f}")
        print("=" * 60)

        if newly_answered:
            print(f"\n{len(newly_answered)} question(s) previously missed (before) but now correctly retrieved (after):")
            for q in newly_answered:
                print(f"  - {q}")
        else:
            print("\nNo question in this eval set flipped from miss to hit — see report for interpretation.")

    finally:
        db.close()


if __name__ == "__main__":
    main()
