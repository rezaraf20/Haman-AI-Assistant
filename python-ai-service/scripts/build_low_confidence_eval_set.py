"""
Builds a candidate eval set from real retrieval failures — the exact
"recall gap" eval_retrieval.py's own docstring admits it can't measure
(that script's ground truth only comes from *successfully* answered
messages, so it has nothing to say about questions the pipeline already
knew it couldn't confidently answer).

Reads conversation_events rows logged by rag_service.py's hybrid_retrieve()
whenever a query's best score came in under the chatbot's own retrieval/
rerank threshold (event_type='unanswered', payload={query, best_score,
reason}) — computed once, correctly, against that chatbot's real
admin-configured threshold at the moment retrieval ran, not re-derived
here.

This script does NOT invent ground truth — a failed retrieval by
definition has no chunk id already known to be right. It surfaces the
queries + their worst observed score, deduped and sorted worst-first, for
a human to review and manually fill in the chunk id(s) that *should* have
come back. That's what the empty ground_truth_chunk_ids array in the
output is for — once filled in by hand, the same score_result() function
eval_retrieval.py already uses can score this set the same way.

Run inside the container:
    docker exec haman_python_ai python scripts/build_low_confidence_eval_set.py
    docker exec haman_python_ai python scripts/build_low_confidence_eval_set.py --days 30 --limit 50 --out /tmp/low_confidence_eval.jsonl
"""
import sys
import os
import json
import argparse
sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from sqlalchemy import text
from app.core.database import SessionLocal


def get_tenant_schemas(db):
    rows = db.execute(text("SELECT DISTINCT schema_name FROM chatbot_index WHERE schema_name IS NOT NULL")).fetchall()
    return [r[0] for r in rows]


def collect_low_confidence_queries(db, schema, days):
    db.execute(text(f"SET search_path TO {schema}, public"))
    db.commit()
    rows = db.execute(text("""
        SELECT chatbot_id, payload->>'query' AS query,
               (payload->>'best_score')::float AS best_score,
               payload->>'reason' AS reason, created_at
        FROM conversation_events
        WHERE event_type = 'unanswered'
          AND created_at >= now() - make_interval(days => :days)
          AND payload->>'query' IS NOT NULL
        ORDER BY created_at DESC
    """), {"days": days}).fetchall()
    db.execute(text("SET search_path TO public"))
    db.commit()

    # Same query asked more than once still counts as one eval item — keep
    # the worst score seen (the most damning evidence something is missing
    # from the catalog, not the least) and the most recent timestamp/reason.
    grouped = {}
    for chatbot_id, query, best_score, reason, created_at in rows:
        key = (str(chatbot_id), query.strip())
        item = grouped.setdefault(key, {"occurrences": 0, "best_score": None, "reason": reason, "last_seen": created_at})
        item["occurrences"] += 1
        if best_score is not None and (item["best_score"] is None or best_score < item["best_score"]):
            item["best_score"] = best_score
        if created_at > item["last_seen"]:
            item["last_seen"] = created_at
            item["reason"] = reason

    items = []
    for (chatbot_id, query), stats in grouped.items():
        items.append({
            "schema": schema,
            "chatbot_id": chatbot_id,
            "query": query,
            "best_score": stats["best_score"],
            "reason": stats["reason"],
            "occurrences": stats["occurrences"],
            "last_seen": stats["last_seen"].isoformat() if stats["last_seen"] else None,
            # Fill this in by hand with the chunk id(s) that *should* have
            # been retrieved, then score with the same recall@N/MRR logic
            # eval_retrieval.py's score_result() already implements.
            "ground_truth_chunk_ids": [],
        })
    return items


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--days", type=int, default=30, help="How far back to look for unanswered events")
    parser.add_argument("--limit", type=int, default=50, help="Max eval items to output, worst-scoring first")
    parser.add_argument("--out", type=str, default=None, help="Write JSONL here instead of stdout")
    args = parser.parse_args()

    db = SessionLocal()
    try:
        schemas = get_tenant_schemas(db)
        print(f"Found {len(schemas)} tenant schema(s): {schemas}", file=sys.stderr)

        all_items = []
        for schema in schemas:
            items = collect_low_confidence_queries(db, schema, args.days)
            print(f"  {schema}: {len(items)} unique low-confidence quer{'y' if len(items) == 1 else 'ies'} in the last {args.days} day(s)", file=sys.stderr)
            all_items.extend(items)

        if not all_items:
            print("\nNo 'unanswered' events found in that window — either nothing was actually unanswered "
                  "(good), or no real traffic has hit the pipeline since this logging shipped. Not the same thing "
                  "— check conversation_events row counts before concluding retrieval is fine.", file=sys.stderr)
            return

        # Worst (lowest) score first — those are the clearest catalog gaps,
        # most useful to a human doing the labeling first.
        all_items.sort(key=lambda x: (x["best_score"] if x["best_score"] is not None else -1))
        all_items = all_items[:args.limit]

        out = open(args.out, "w", encoding="utf-8") if args.out else sys.stdout
        try:
            for item in all_items:
                out.write(json.dumps(item, ensure_ascii=False) + "\n")
        finally:
            if args.out:
                out.close()

        if args.out:
            print(f"\nWrote {len(all_items)} candidate eval item(s) to {args.out}", file=sys.stderr)
        else:
            print(f"\n{len(all_items)} candidate eval item(s) printed above (JSONL).", file=sys.stderr)
    finally:
        db.close()


if __name__ == "__main__":
    main()
