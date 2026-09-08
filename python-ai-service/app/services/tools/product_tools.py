"""
Live stock/price/variant tools — doc-04's "Very high" value item: the
concrete answer to "do you have this in stock right now?" / "what does it
cost?" / "what sizes/colors does this come in?" that the retrieval-only
pipeline structurally can't give reliably (indexed content is a snapshot
from the last sync, not live).

  get_product_availability  stock, current price, sale price, publish
                             status — by SKU or numeric product_id.
  get_product_variants      every variation of a variable product (size,
                             color, etc.), each with its own stock/price.
  search_products            STRUCTURED live catalog search (category,
                             price range, brand, in-stock) — not a
                             semantic/similarity search, that's what the
                             regular vector retrieval pipeline is for.

All three call the WordPress plugin's own live-query REST endpoint (more
control than WooCommerce's own REST API, no extra customer-supplied
consumer key, reuses the webhook_secret trust relationship that already
exists for outbound sync webhooks) with a short timeout.

PRICING RULE — deliberately stricter than an ordinary "best effort" tool:
a wrong price is the worst possible mistake for a shop, worse than no
answer at all. So none of these three tools fall back to the last-synced
index on failure the way the sync-era check_product_availability once did.
On ANY failure (site down, timed out, no domain on file) they return an
explicit "live check unavailable" result with no price/stock field at
all, plus a product_url (built from the numeric product ID, never a slug —
slugs change, IDs don't) when one is knowable, so the model has nothing to
misreport and is steered toward "check the product page" instead. The
matching prompt-side rule lives in rag_service._live_pricing_rule().
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
MAX_SEARCH_LIMIT = 10

# A part number/SKU is never free-form text — mirrors App\Support\
# SkuNormalizer's own alphabet on the Laravel side. Rejected outright
# (never merely "escaped") if it doesn't match, before the value goes
# anywhere near a URL, an HMAC payload, or a SQL parameter — regardless of
# what type the model's tool-call arguments claim it is.
_SAFE_SKU_RE = re.compile(r'^[A-Za-z0-9\-_. ]{1,64}$')
_SAFE_FILTER_RE = re.compile(r'^[^\x00-\x1f]{1,100}$')  # category/brand: any printable text, capped


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
            logger.warning(f"Live query ({action}) to {domain} returned HTTP {resp.status_code}: {resp.text[:200]}")
            return None
        return resp.json()
    except _requests.RequestException as e:
        logger.warning(f"Live query ({action}) to {domain} failed: {e}")
        return None


def _product_url(domain: Optional[str], product_id: Optional[int]) -> Optional[str]:
    """By numeric ID, never by slug — WordPress always resolves ?p=<id>
    regardless of the site's permalink structure, and a slug can change out
    from under a link while the ID never does."""
    if not domain or not product_id:
        return None
    return f"https://{domain}/?p={product_id}"


def _unavailable_result(domain: Optional[str], known_product_id: Optional[int] = None) -> dict:
    """The one shape returned whenever a live check could not be
    performed, for ALL THREE tools — deliberately carries no price/stock
    field at all, so there is nothing for the model to misreport. See the
    module docstring's PRICING RULE."""
    return {
        "live": False,
        "error": "live_check_unavailable",
        "note": (
            "Live store data is unavailable right now. Do NOT state a price, "
            "stock status, or availability — tell the customer you can't "
            "confirm it at this moment and point them to the product page "
            "for the exact current price and availability."
        ),
        "product_url": _product_url(domain, known_product_id),
    }


def _validate_sku(sku) -> Optional[str]:
    if not isinstance(sku, str) or not _SAFE_SKU_RE.match(sku):
        return None
    return sku


def _validate_product_id(product_id) -> Optional[int]:
    try:
        pid = int(product_id)
    except (TypeError, ValueError):
        return None
    return pid if pid > 0 else None


def get_product_availability(db: Session, chatbot_id: str, sku: Optional[str] = None, product_id: Optional[int] = None) -> dict:
    if not sku and not product_id:
        return {"error": "Either sku or product_id is required."}

    params: dict = {}
    if product_id is not None:
        pid = _validate_product_id(product_id)
        if pid is None:
            return {"error": "Invalid product_id."}
        params = {"product_id": pid}
    elif sku is not None:
        clean_sku = _validate_sku(sku)
        if clean_sku is None:
            return {"error": "Invalid SKU format."}
        params = {"sku": clean_sku}

    known_pid = params.get("product_id")
    site = _resolve_site(db, chatbot_id)
    if not site:
        return _unavailable_result(None, known_pid)

    live = _query_live(site["domain"], site["secret"], "get_availability", params)
    if live is None:
        return _unavailable_result(site["domain"], known_pid)

    if not live.get("found"):
        return {"found": False, "live": True, "note": "This product was not found in the live store."}

    return {
        "found": True, "live": True,
        "product_id": live.get("product_id"), "name": live.get("name"), "sku": live.get("sku"),
        "status": live.get("status"),
        "stock_status": live.get("stock_status"), "stock_quantity": live.get("stock_quantity"),
        "price": live.get("price"), "regular_price": live.get("regular_price"),
        "sale_price": live.get("sale_price"), "on_sale": live.get("on_sale"),
        "is_variable": live.get("is_variable", False),
        "currency": live.get("currency", "IRT"),
        "product_url": _product_url(site["domain"], live.get("product_id")),
    }


def get_product_variants(db: Session, chatbot_id: str, product_id) -> dict:
    pid = _validate_product_id(product_id)
    if pid is None:
        return {"error": "Invalid product_id."}

    site = _resolve_site(db, chatbot_id)
    if not site:
        return _unavailable_result(None, pid)

    live = _query_live(site["domain"], site["secret"], "get_variants", {"product_id": pid})
    if live is None:
        return _unavailable_result(site["domain"], pid)

    if not live.get("found"):
        return {"found": False, "live": True, "note": "This product was not found in the live store."}
    if not live.get("is_variable"):
        return {
            "found": True, "live": True, "is_variable": False,
            "note": "This product has no variants (it isn't a variable product).",
            "product_url": _product_url(site["domain"], live.get("product_id")),
        }

    variants = live.get("variants", [])
    if not isinstance(variants, list):
        variants = []

    return {
        "found": True, "live": True, "is_variable": True,
        "product_id": live.get("product_id"), "name": live.get("name"),
        "currency": live.get("currency", "IRT"),
        "variants": variants,
        "product_url": _product_url(site["domain"], live.get("product_id")),
    }


def search_products(
    db: Session, chatbot_id: str, query: Optional[str] = None, category: Optional[str] = None,
    price_min: Optional[float] = None, price_max: Optional[float] = None,
    brand: Optional[str] = None, in_stock_only: Optional[bool] = None,
) -> dict:
    params: dict = {}
    if query is not None:
        if not isinstance(query, str):
            return {"error": "Invalid query."}
        params["query"] = query[:200]
    for field_name, val in (("category", category), ("brand", brand)):
        if val is not None:
            if not isinstance(val, str) or not _SAFE_FILTER_RE.match(val):
                return {"error": f"Invalid {field_name}."}
            params[field_name] = val
    for field_name, val in (("price_min", price_min), ("price_max", price_max)):
        if val is not None:
            try:
                params[field_name] = float(val)
            except (TypeError, ValueError):
                return {"error": f"Invalid {field_name}."}
    if in_stock_only is not None:
        params["in_stock_only"] = bool(in_stock_only)
    params["limit"] = MAX_SEARCH_LIMIT

    site = _resolve_site(db, chatbot_id)
    if not site:
        return {"live": False, "error": "live_check_unavailable",
                "note": "Live search is unavailable right now. Please check the shop page directly.", "results": []}

    live = _query_live(site["domain"], site["secret"], "search_products", params)
    if live is None:
        return {"live": False, "error": "live_check_unavailable",
                "note": "Live search is unavailable right now. Please check the shop page directly.", "results": []}

    results = live.get("results", [])
    if not isinstance(results, list):
        results = []
    for r in results:
        if isinstance(r, dict):
            r["product_url"] = _product_url(site["domain"], r.get("product_id"))

    return {"live": True, "count": live.get("count", len(results)), "currency": live.get("currency", "IRT"), "results": results}


register(Tool(
    name="get_product_availability",
    description=(
        "Get a product's REAL-TIME stock status, current price, sale price, and publish "
        "status, by SKU or numeric product ID — read directly from the store's live system. "
        "Use this whenever a customer asks whether something is in stock or what it "
        "currently costs; the regular indexed catalog content can be out of date and must "
        "never be used to state a price."
    ),
    parameters={
        "type": "object",
        "properties": {
            "sku": {"type": "string", "description": "The exact SKU / part number, if known.", "maxLength": 64},
            "product_id": {"type": "integer", "description": "The numeric WooCommerce product ID, if known (preferred over sku when both are known)."},
        },
        "additionalProperties": False,
    },
    handler=get_product_availability,
    access_level="read",
))

register(Tool(
    name="get_product_variants",
    description=(
        "Get every variant (size, color, or any other variable attribute) of a variable "
        "product, each with its OWN live stock status and price — use this for products "
        "sold in multiple sizes/colors/volumes (e.g. cosmetics or parts with package "
        "options) instead of quoting a single price for the whole product."
    ),
    parameters={
        "type": "object",
        "properties": {
            "product_id": {"type": "integer", "description": "The numeric WooCommerce product ID of the parent (variable) product."},
        },
        "required": ["product_id"],
        "additionalProperties": False,
    },
    handler=get_product_variants,
    access_level="read",
))

register(Tool(
    name="search_products",
    description=(
        "Search the store's LIVE catalog with structured filters — category, price range, "
        "brand, in-stock-only — not a meaning/similarity search (use this for 'show me "
        "shoes under 500000 toman that are in stock', not for open-ended questions the "
        "regular knowledge base already answers)."
    ),
    parameters={
        "type": "object",
        "properties": {
            "query": {"type": "string", "description": "Free-text search term (product name/description keywords), if any.", "maxLength": 200},
            "category": {"type": "string", "description": "Category name or slug to filter by, if any."},
            "price_min": {"type": "number", "description": "Minimum price, if the customer gave one."},
            "price_max": {"type": "number", "description": "Maximum price, if the customer gave one."},
            "brand": {"type": "string", "description": "Brand name to filter by, if any (only works if this store has a brand taxonomy)."},
            "in_stock_only": {"type": "boolean", "description": "True if the customer only wants in-stock results."},
        },
        "additionalProperties": False,
    },
    handler=search_products,
    access_level="read",
))
