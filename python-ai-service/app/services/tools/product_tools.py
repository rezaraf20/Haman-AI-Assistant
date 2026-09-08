"""
check_product_availability — the first real tool, and the concrete answer
to "do you have this in stock right now?" / "what does it cost?" that the
retrieval-only pipeline structurally can't give reliably (indexed content
is a snapshot from the last sync, not live).

Calls the WordPress plugin's own live-query REST endpoint (option A from
the spec: more control than WooCommerce's own REST API, no extra customer-
supplied consumer key, reuses the webhook_secret trust relationship that
already exists for outbound sync webhooks) with a short timeout. On ANY
failure — site down, timed out, no domain on file for this chatbot — it
falls back to the last-synced indexed products row and says so explicitly
in the result, rather than either crashing the chat turn or silently
presenting stale data as if it were current.
"""
import hashlib
import hmac
import json as _json
import logging
import re
from typing import Optional

import requests as _requests
from sqlalchemy import text
from sqlalchemy.orm import Session

from .registry import Tool, register

logger = logging.getLogger(__name__)

LIVE_QUERY_TIMEOUT_SECONDS = 3

# A part number/SKU is never free-form text — mirrors App\Support\
# SkuNormalizer's own alphabet on the Laravel side. Rejected outright
# (never merely "escaped") if it doesn't match, before the value goes
# anywhere near a URL, an HMAC payload, or a SQL parameter — regardless of
# what type the model's tool-call arguments claim it is.
_SAFE_SKU_RE = re.compile(r'^[A-Za-z0-9\-_. ]{1,64}$')


def _resolve_site(db: Session, chatbot_id: str) -> Optional[dict]:
    """The chatbot's own WordPress domain + webhook secret, looked up
    fresh every call — never cached on this path. Tool calls are rare
    enough (opt-in, only when a customer explicitly asks about stock/
    price) that this isn't a hot path worth the staleness risk a cache of
    a security secret would add. Public-schema join: chatbot_id here is
    always the server's own value for *this* request (see
    tool_calling_service.py), never a model-supplied one — this is the
    actual tenant-isolation boundary: a chatbot can only ever resolve its
    own domain/secret, there is no parameter that lets it ask for another
    tenant's."""
    row = db.execute(text("""
        SELECT ci.primary_domain, t.settings->>'webhook_secret' AS webhook_secret
        FROM public.chatbot_index ci
        JOIN public.tenants t ON t.id = ci.tenant_id
        WHERE ci.chatbot_id = CAST(:cid AS uuid)
    """), {"cid": chatbot_id}).fetchone()
    if not row or not row.primary_domain or not row.webhook_secret:
        return None
    return {"domain": row.primary_domain, "secret": row.webhook_secret}


def _query_live(domain: str, secret: str, action: str, params: dict) -> Optional[dict]:
    body = _json.dumps({"action": action, **params}, separators=(",", ":"))
    signature = "sha256=" + hmac.new(secret.encode(), body.encode(), hashlib.sha256).hexdigest()
    try:
        resp = _requests.post(
            f"https://{domain}/wp-json/hamman/v1/live-query",
            data=body.encode(),
            headers={"Content-Type": "application/json", "X-Hamman-Signature": signature},
            timeout=LIVE_QUERY_TIMEOUT_SECONDS,
        )
        if resp.status_code != 200:
            logger.warning(f"Live query to {domain} returned HTTP {resp.status_code}: {resp.text[:200]}")
            return None
        return resp.json()
    except _requests.RequestException as e:
        logger.warning(f"Live query to {domain} failed: {e}")
        return None


def _fallback_from_index(db: Session, chatbot_id: str, sku: str, reason: str) -> dict:
    normalized = sku.upper().replace(" ", "").replace("-", "")
    row = db.execute(text("""
        SELECT name, sku, price, currency, stock_status
        FROM products
        WHERE chatbot_id = CAST(:cid AS uuid) AND sku_normalized = :sku
        LIMIT 1
    """), {"cid": chatbot_id, "sku": normalized}).fetchone()
    if not row:
        return {
            "found": False, "live": False,
            "note": f"{reason} This part number was not found in the last-synced catalog either.",
        }
    return {
        "found": True, "live": False,
        "name": row.name, "sku": row.sku, "price": row.price, "currency": row.currency,
        "stock_status": row.stock_status,
        "note": f"{reason} This is from the last catalog sync, not a live check — it may not be current.",
    }


def check_product_availability(db: Session, chatbot_id: str, sku: str) -> dict:
    if not isinstance(sku, str) or not _SAFE_SKU_RE.match(sku):
        return {"error": "Invalid part number format."}

    site = _resolve_site(db, chatbot_id)
    if not site:
        return _fallback_from_index(db, chatbot_id, sku, "No live store connection is on file for this chatbot.")

    live = _query_live(site["domain"], site["secret"], "check_stock", {"sku": sku})
    if live is None:
        return _fallback_from_index(db, chatbot_id, sku, "The live store did not respond in time.")

    if not live.get("found"):
        return {"found": False, "live": True, "note": "This part number was not found in the live store."}

    return {
        "found": True, "live": True,
        "name": live.get("name"), "sku": live.get("sku"),
        "price": live.get("price"), "currency": live.get("currency", "IRT"),
        "stock_status": live.get("stock_status"),
    }


register(Tool(
    name="check_product_availability",
    description=(
        "Check a product's REAL-TIME stock status and current price by its SKU/part number, "
        "read directly from the store's live system — use this whenever a customer asks whether "
        "something is in stock or what it currently costs, since the regular indexed catalog "
        "content can be out of date."
    ),
    parameters={
        "type": "object",
        "properties": {
            "sku": {
                "type": "string",
                "description": "The exact SKU / part number the customer mentioned, or that was identified for their product.",
                "maxLength": 64,
            },
        },
        "required": ["sku"],
        "additionalProperties": False,
    },
    handler=check_product_availability,
    access_level="read",
))
