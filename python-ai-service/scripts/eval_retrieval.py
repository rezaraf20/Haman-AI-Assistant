"""
Retrieval quality evaluation: recall@5 and MRR@5 across three pipelines —
(a) pure vector similarity (retrieve_chunks(), unchanged), (b) hybrid search
+ RRF with no rerank, (c) hybrid + RRF + rerank — plus the rerank leg's own
extra token cost per message.

Ground truth is built from real production data, not a curated gold set:
for every historically *successfully answered* message (role=assistant,
is_fallback=false, retrieved_chunk_ids non-empty) across the platform's
tenant schemas, the paired user question is an eval item and the chunk
id(s) that message actually cited are its ground truth. This is an honest,
reproducible methodology — it measures "does the new pipeline still find
what the old pipeline actually used to answer this", not an idealized
benchmark — and is stated as such in the printed report rather than
presented as more than it is.

Token cost, not Toman: _compute_cost_toman() multiplies token counts by
each LLM provider profile's admin-entered input/output price per 1M
tokens (LlmProviderProfileResource), and that price defaults to, and on
every profile active at the time this was last run WAS, 0 — so a Toman
figure here would read as "rerank is free" when it actually means "nobody
has priced this profile yet". Reporting raw prompt/completion tokens per
rerank call instead is the number that stays true regardless of whether
pricing was ever filled in.

Run inside the python-ai-service container (needs DATABASE_URL,
GEMINI_API_KEY, and at least one active LLM provider profile for the rerank
leg):
    docker exec haman_python_ai python scripts/eval_retrieval.py
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

        a_recalls, a_mrrs = [], []  # (a) pure vector
        b_recalls, b_mrrs = [], []  # (b) hybrid + RRF, no rerank
        c_recalls, c_mrrs = [], []  # (c) hybrid + RRF + rerank
        rerank_prompt_tokens, rerank_completion_tokens = [], []
        rerank_fallback_count = 0
        newly_answered_b = []  # (a) missed, (b) hit
        newly_answered_c = []  # (b) missed, (c) hit

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
            # touches tenant-schema tables (chunks, chatbots), here and
            # before each retrieval call below.
            db.execute(text(f"SET search_path TO {item['schema']}, public"))
            db.commit()

            # (a) pure vector similarity — retrieve_chunks() is untouched,
            # the exact pre-hybrid-search query still live for /search/semantic.
            a_chunks = retrieve_chunks(db, item["chatbot_id"], emb, settings["top_k"], settings["threshold"])
            a_ids = [c["id"] for c in a_chunks]
            a_recall, a_mrr = score_result(a_ids, item["ground_truth"])
            a_recalls.append(a_recall)
            a_mrrs.append(a_mrr)

            # (b) hybrid + RRF, rerank forced off — isolates what fusion
            # alone buys over pure vector, before rerank's own cost/benefit
            # is added on top.
            db.execute(text(f"SET search_path TO {item['schema']}, public"))
            db.commit()
            b_result = hybrid_retrieve(
                db, item["chatbot_id"], None, item["question"], emb,
                settings["top_k"], settings["threshold"], settings["language"],
                rerank_enabled=False, rerank_threshold=settings["rerank_threshold"],
            )
            b_ids = [c["id"] for c in b_result["chunks"]]
            b_recall, b_mrr = score_result(b_ids, item["ground_truth"])
            b_recalls.append(b_recall)
            b_mrrs.append(b_mrr)

            # (c) hybrid + RRF + rerank — forced ON regardless of this
            # chatbot's own saved setting, specifically so every item in
            # the eval set exercises the rerank leg and its token cost,
            # rather than silently skipping it for chatbots that currently
            # have it off (the whole point of this run is to decide
            # whether to turn it on).
            db.execute(text(f"SET search_path TO {item['schema']}, public"))
            db.commit()
            c_result = hybrid_retrieve(
                db, item["chatbot_id"], None, item["question"], emb,
                settings["top_k"], settings["threshold"], settings["language"],
                rerank_enabled=True, rerank_threshold=settings["rerank_threshold"],
            )
            c_ids = [c["id"] for c in c_result["chunks"]]
            c_recall, c_mrr = score_result(c_ids, item["ground_truth"])
            c_recalls.append(c_recall)
            c_mrrs.append(c_mrr)

            usage = c_result.get("rerank_usage") or {}
            if usage:
                rerank_prompt_tokens.append(usage.get("prompt_tokens", 0) or 0)
                rerank_completion_tokens.append(usage.get("completion_tokens", 0) or 0)
            else:
                # Empty usage means every active provider failed the
                # rerank call and hybrid_retrieve() fell back to plain RRF
                # order (see _rerank_llm()) — (c) for this item is really
                # just (b) again, not a genuine rerank measurement.
                rerank_fallback_count += 1

            marker = ""
            if a_recall == 0.0 and b_recall == 1.0:
                marker += "  <-- (a)->(b) newly answered by hybrid+RRF"
                newly_answered_b.append(item["question"])
            if b_recall == 0.0 and c_recall == 1.0:
                marker += "  <-- (b)->(c) newly answered by rerank"
                newly_answered_c.append(item["question"])

            print(f"  [{i}/{len(items)}] (a) r={a_recall:.0f} mrr={a_mrr:.2f} | (b) r={b_recall:.0f} mrr={b_mrr:.2f} | (c) r={c_recall:.0f} mrr={c_mrr:.2f}{marker}")

            # 25 back-to-back rerank calls (now on EVERY item, not just the
            # chatbots that already had it on) is a self-inflicted burst no
            # real traffic pattern produces — space them out enough to stay
            # under Groq's per-minute rate limit rather than exhausting it
            # against ourselves and only ever exercising the failover path.
            time.sleep(2)

        n = len(a_recalls)
        print("\n" + "=" * 70)
        print(f"RESULTS  (n={n} real questions, top_{TOP_N})")
        print("=" * 70)
        print(f"(a) pure vector similarity:")
        print(f"  recall@{TOP_N} = {sum(a_recalls)/n:.3f}")
        print(f"  MRR@{TOP_N}    = {sum(a_mrrs)/n:.3f}")
        print(f"(b) hybrid search + RRF, no rerank:")
        print(f"  recall@{TOP_N} = {sum(b_recalls)/n:.3f}  (delta vs a: {sum(b_recalls)/n - sum(a_recalls)/n:+.3f})")
        print(f"  MRR@{TOP_N}    = {sum(b_mrrs)/n:.3f}  (delta vs a: {sum(b_mrrs)/n - sum(a_mrrs)/n:+.3f})")
        print(f"(c) hybrid search + RRF + rerank:")
        print(f"  recall@{TOP_N} = {sum(c_recalls)/n:.3f}  (delta vs b: {sum(c_recalls)/n - sum(b_recalls)/n:+.3f})")
        print(f"  MRR@{TOP_N}    = {sum(c_mrrs)/n:.3f}  (delta vs b: {sum(c_mrrs)/n - sum(b_mrrs)/n:+.3f})")
        print("=" * 70)

        n_priced = len(rerank_prompt_tokens)
        if n_priced:
            avg_prompt = sum(rerank_prompt_tokens) / n_priced
            avg_completion = sum(rerank_completion_tokens) / n_priced
            print(f"\nRerank leg token cost per message (n={n_priced} genuine rerank calls, "
                  f"{rerank_fallback_count} fell back to plain RRF and are excluded from this average):")
            print(f"  avg prompt tokens     = {avg_prompt:.0f}")
            print(f"  avg completion tokens = {avg_completion:.0f}")
            print(f"  avg total tokens      = {avg_prompt + avg_completion:.0f}")
            print(f"  (reported as tokens, not Toman: every active LLM provider profile currently has "
                  f"input/output price per 1M tokens left at 0, so _compute_cost_toman() would print "
                  f"\"0 Toman\" regardless of real usage — that is an unpriced profile, not a free call.)")
        else:
            print(f"\nEvery rerank call fell back to plain RRF order ({rerank_fallback_count}/{n}) — "
                  f"no genuine rerank token usage was observed. Check that at least one active LLM "
                  f"provider profile is actually reachable before trusting the (c) numbers above: a "
                  f"100% fallback rate means (c) silently measured (b) again.")

        if newly_answered_b:
            print(f"\n{len(newly_answered_b)} question(s) hybrid+RRF alone fixed over pure vector:")
            for q in newly_answered_b:
                print(f"  - {q}")
        if newly_answered_c:
            print(f"\n{len(newly_answered_c)} question(s) rerank fixed over hybrid+RRF alone:")
            for q in newly_answered_c:
                print(f"  - {q}")
        if not newly_answered_b and not newly_answered_c:
            print("\nNo question in this eval set flipped from miss to hit at either stage — see report for interpretation.")

    finally:
        db.close()


if __name__ == "__main__":
    main()
