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
  recommend_products         up to 3 in-stock products matching a
                             described need (doc-04 "Product compare",
                             Very high — "what product is right for me?"
                             was one of a cosmetics shop's three most
                             common questions).
  compare_products            a feature-by-feature table for 2-5 products.
  build_cart_url              one-click WooCommerce "add to cart" links —
                             a plain nonce-free URL, no live call at all.
  add_to_cart                 the richer sibling of build_cart_url: real
                             intent for the widget to add to the cart via
                             WooCommerce's own Store API, same-origin,
                             also zero server-side HTTP calls (see its own
                             docstring).
  create_payment_link         preview-only order summary + live total for
                             a customer who wants to pay directly — the
                             actual order/payment link is only ever
                             created after an explicit widget-rendered
                             confirmation click, never by this tool call
                             (see ChatController::createPaymentLink()'s
                             own from-scratch security checks).

get_product_availability/get_product_variants/search_products/
recommend_products/compare_products/create_payment_link call the
WordPress plugin's own live-query REST endpoint (more control than
WooCommerce's own REST API, no extra customer-supplied consumer key,
reuses the webhook_secret trust relationship that already exists for
outbound sync webhooks) with a short timeout. build_cart_url and
add_to_cart make NO server-side HTTP calls at all — see their own
docstrings for why.

PRICING RULE — deliberately stricter than an ordinary "best effort" tool:
a wrong price is the worst possible mistake for a shop, worse than no
answer at all. So none of these tools fall back to the last-synced index
on failure the way the sync-era check_product_availability once did.
On ANY failure (site down, timed out, no domain on file) they return an
explicit "live check unavailable" result with no price/stock field at
all, plus a product_url (built from the numeric product ID, never a slug —
slugs change, IDs don't) when one is knowable, so the model has nothing to
misreport and is steered toward "check the product page" instead. The
matching prompt-side rule lives in rag_service._live_pricing_rule().

recommend_products additionally enforces "never suggest an out-of-stock
item" at the QUERY level (WordPress's stock_status='instock' filter is
hardcoded, not a model-controlled parameter) rather than relying on the
model to comply with a prompt instruction — see the acceptance criterion
this was built against.

compare_products never invents a value for an attribute a product doesn't
have: WordPress returns only the real attributes each product actually
carries, and this module's own attribute_rows builder fills a product's
cell with None (rendered as a blank/dash by the widget) wherever that
product simply has no matching key, rather than inferring anything.
"""
import hashlib
import hmac
import json as _json
import logging
import re
from typing import List, Optional

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


def _resolve_domain(db: Session, chatbot_id: str) -> Optional[str]:
    """Domain-only lookup, deliberately lighter than _resolve_site() — used
    by build_cart_url(), which never makes a live HTTP call to the store at
    all (see its own docstring), so it has no use for webhook_secret and
    shouldn't fail for a chatbot that has a domain on file but hasn't
    necessarily completed the webhook/live-query setup yet."""
    row = db.execute(text("""
        SELECT primary_domain FROM public.chatbot_index WHERE chatbot_id = CAST(:cid AS uuid)
    """), {"cid": chatbot_id}).fetchone()
    if not row or not row.primary_domain:
        return None
    return row.primary_domain


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


MAX_RECOMMEND_RESULTS = 3
MIN_COMPARE_PRODUCTS = 2
MAX_COMPARE_PRODUCTS = 5


def recommend_products(
    db: Session, chatbot_id: str, need: str, category: Optional[str] = None,
    price_min: Optional[float] = None, price_max: Optional[float] = None,
) -> dict:
    if not isinstance(need, str) or not need.strip():
        return {"error": "need is required."}
    params: dict = {"need": need.strip()[:300]}
    for field_name, val in (("category", category),):
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

    site = _resolve_site(db, chatbot_id)
    if not site:
        return {"live": False, "error": "live_check_unavailable",
                "note": "Live recommendations are unavailable right now. Do not recommend any specific product.", "products": []}

    live = _query_live(site["domain"], site["secret"], "recommend_products", params)
    if live is None:
        return {"live": False, "error": "live_check_unavailable",
                "note": "Live recommendations are unavailable right now. Do not recommend any specific product.", "products": []}

    products = live.get("products", [])
    if not isinstance(products, list):
        products = []
    # Hard cap here too, even though WordPress already caps at 3 — defense
    # in depth against a future WP-side change, same posture as every other
    # server-enforced limit in this module.
    products = products[:MAX_RECOMMEND_RESULTS]
    for p in products:
        if isinstance(p, dict):
            p["product_url"] = _product_url(site["domain"], p.get("product_id"))

    return {"live": True, "currency": live.get("currency", "IRT"), "products": products}


def compare_products(db: Session, chatbot_id: str, product_ids: List[int]) -> dict:
    if not isinstance(product_ids, list):
        return {"error": "product_ids must be a list."}
    clean_ids: List[int] = []
    for raw in product_ids:
        pid = _validate_product_id(raw)
        if pid is None:
            return {"error": "Invalid product_id in product_ids."}
        if pid not in clean_ids:
            clean_ids.append(pid)
    clean_ids = clean_ids[:MAX_COMPARE_PRODUCTS]
    if len(clean_ids) < MIN_COMPARE_PRODUCTS:
        return {"error": f"At least {MIN_COMPARE_PRODUCTS} distinct product_ids are required."}

    site = _resolve_site(db, chatbot_id)
    if not site:
        return {"live": False, "error": "live_check_unavailable",
                "note": "Live comparison is unavailable right now. Do not compare these products from memory.", "products": [], "attribute_rows": []}

    live = _query_live(site["domain"], site["secret"], "compare_products", {"product_ids": clean_ids})
    if live is None:
        return {"live": False, "error": "live_check_unavailable",
                "note": "Live comparison is unavailable right now. Do not compare these products from memory.", "products": [], "attribute_rows": []}

    raw_products = live.get("products", [])
    if not isinstance(raw_products, list):
        raw_products = []

    # Union of attribute labels across every product that actually has any,
    # in first-seen order — a product with no key for a given row simply
    # never had that attribute, never inferred as a blank/empty value.
    attribute_names: List[str] = []
    for p in raw_products:
        if not isinstance(p, dict) or not p.get("found"):
            continue
        for label in (p.get("attributes") or {}).keys():
            if label not in attribute_names:
                attribute_names.append(label)

    products_out = []
    for p in raw_products:
        if not isinstance(p, dict):
            continue
        products_out.append({
            "product_id": p.get("product_id"), "found": bool(p.get("found")),
            "name": p.get("name"), "price": p.get("price"), "stock_status": p.get("stock_status"),
            "image": p.get("image"),
            "product_url": _product_url(site["domain"], p.get("product_id")),
        })

    attribute_rows = []
    for label in attribute_names:
        values = {}
        for p in raw_products:
            if not isinstance(p, dict) or not p.get("found"):
                continue
            values[str(p.get("product_id"))] = (p.get("attributes") or {}).get(label)  # None if this product lacks it
        attribute_rows.append({"attribute": label, "values": values})

    return {"live": True, "currency": live.get("currency", "IRT"), "products": products_out, "attribute_rows": attribute_rows}


MAX_CART_ITEMS = 5
MAX_CART_QUANTITY = 20


def build_cart_url(db: Session, chatbot_id: str, items: List[dict]) -> dict:
    """The deliberately SAFE half of doc-04's "Add to cart + cart URL" item
    — actually adding to a cart needs a cart token/nonce from Woo's Store
    API and opens the question of who the "cart" even belongs to, both
    explicitly deferred. This tool does something much simpler and lower-
    risk instead: it builds WooCommerce's own native, nonce-free
    ?add-to-cart=<id>&quantity=<n> GET link, the same URL scheme WooCommerce
    themes have always used for their own "Add to cart" buttons — the
    customer's own click is what actually adds it, WooCommerce handles the
    whole thing itself. No live call to the store at all (unlike every
    other tool in this module) — just the domain already on file plus IDs
    the model already knows, so this needs no webhook/live-query setup.
    """
    if not isinstance(items, list) or not items:
        return {"error": "items must be a non-empty list."}
    if len(items) > MAX_CART_ITEMS:
        return {"error": f"At most {MAX_CART_ITEMS} items are supported."}

    domain = _resolve_domain(db, chatbot_id)
    if not domain:
        return {"error": "no_site_domain", "note": "No store domain is on file for this chatbot — a cart link can't be built.", "items": []}

    result_items = []
    for raw in items:
        if not isinstance(raw, dict):
            return {"error": "Each item must be an object with a product_id."}
        pid = _validate_product_id(raw.get("product_id"))
        if pid is None:
            return {"error": "Invalid product_id in items."}
        qty = raw.get("quantity", 1)
        try:
            qty = int(qty)
        except (TypeError, ValueError):
            return {"error": "Invalid quantity in items."}
        qty = max(1, min(qty, MAX_CART_QUANTITY))  # never 0/negative, never absurdly large
        result_items.append({
            "product_id": pid, "quantity": qty,
            "url": f"https://{domain}/?add-to-cart={pid}&quantity={qty}",
        })

    return {"items": result_items}


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

register(Tool(
    name="recommend_products",
    description=(
        "Recommend up to 3 IN-STOCK products from the live catalog that fit a customer's "
        "described need (e.g. 'something for oily skin', 'a gift under 300000 toman'). "
        "Always returns only in-stock products. For each product returned, base your one-"
        "sentence explanation of why it fits ONLY on the short_description the tool gives "
        "you — never invent a reason. If the tool returns fewer than 3 products, recommend "
        "only that many; never pad the list with something not returned here."
    ),
    parameters={
        "type": "object",
        "properties": {
            "need": {"type": "string", "description": "The customer's need/goal in their own words (e.g. 'oily skin', 'gift for a beginner').", "maxLength": 300},
            "category": {"type": "string", "description": "Category name or slug to narrow to, if known."},
            "price_min": {"type": "number", "description": "Minimum price, if the customer gave one."},
            "price_max": {"type": "number", "description": "Maximum price, if the customer gave one."},
        },
        "required": ["need"],
        "additionalProperties": False,
    },
    handler=recommend_products,
    access_level="read",
))

register(Tool(
    name="compare_products",
    description=(
        "Build a feature-by-feature comparison table for 2 to 5 specific products, by "
        "numeric product ID — use this when a customer wants to compare products they (or "
        "you, from an earlier search/recommendation) already identified. Only ever state a "
        "feature value the tool actually returned for that product; if a feature is missing "
        "for one of them, say it isn't listed — never guess it from the other product or from "
        "general knowledge."
    ),
    parameters={
        "type": "object",
        "properties": {
            "product_ids": {
                "type": "array", "items": {"type": "integer"}, "minItems": 2, "maxItems": 5,
                "description": "The numeric WooCommerce product IDs to compare (2 to 5 of them).",
            },
        },
        "required": ["product_ids"],
        "additionalProperties": False,
    },
    handler=compare_products,
    access_level="read",
))

register(Tool(
    name="build_cart_url",
    description=(
        "Build one-click links that add specific products directly to the customer's "
        "WooCommerce cart — the customer clicks the link/button and WooCommerce itself adds "
        "the item, no login or checkout step from you. Use this when a customer wants to buy "
        "or add products they (or you, from an earlier search/recommendation) already "
        "identified by numeric product ID. This does NOT check stock or price — call "
        "get_product_availability first if you need to confirm those, and don't offer a cart "
        "link for a product you haven't confirmed is in stock."
    ),
    parameters={
        "type": "object",
        "properties": {
            "items": {
                "type": "array",
                "items": {
                    "type": "object",
                    "properties": {
                        "product_id": {"type": "integer", "description": "The numeric WooCommerce product ID."},
                        "quantity": {"type": "integer", "description": "How many of this product to add (default 1)."},
                    },
                    "required": ["product_id"],
                    "additionalProperties": False,
                },
                "minItems": 1, "maxItems": 5,
                "description": "The products (and optional quantities) to build cart links for.",
            },
        },
        "required": ["items"],
        "additionalProperties": False,
    },
    handler=build_cart_url,
    access_level="read",
))


def add_to_cart(db: Session, chatbot_id: str, items: List[dict]) -> dict:
    """The richer sibling of build_cart_url — the widget itself runs on the
    shop's own domain, so it's same-origin with the cart and can call
    WooCommerce's Store API (wp-json/wc/store/v1/cart/add-item) directly
    with the browser's own cookies. No cart token, no buyer-identity
    question, no nonce handling here at all: this tool makes ZERO HTTP
    calls to the store (unlike every live-query tool in this module) and
    returns pure INTENT — product_id, variation_id, quantity, and a
    display name — for the widget to render as a real "Add to cart" button.
    The actual add only happens in the customer's own browser, and only
    after they click that button (see hamman-widget.js's
    handleAddToCartClick()); this function never adds anything itself.

    name is required from the model rather than looked up here, since a
    live lookup would defeat the "zero HTTP calls" property this tool is
    for — the model already knows the product's name from whatever
    context (recommend_products, search_products, get_product_availability,
    conversation history) it identified this product_id from in the first
    place.
    """
    if not isinstance(items, list) or not items:
        return {"error": "items must be a non-empty list."}
    if len(items) > MAX_CART_ITEMS:
        return {"error": f"At most {MAX_CART_ITEMS} items are supported."}

    result_items = []
    for raw in items:
        if not isinstance(raw, dict):
            return {"error": "Each item must be an object with product_id and name."}
        pid = _validate_product_id(raw.get("product_id"))
        if pid is None:
            return {"error": "Invalid product_id in items."}

        variation_id = raw.get("variation_id")
        if variation_id is not None:
            variation_id = _validate_product_id(variation_id)
            if variation_id is None:
                return {"error": "Invalid variation_id in items."}

        qty = raw.get("quantity", 1)
        try:
            qty = int(qty)
        except (TypeError, ValueError):
            return {"error": "Invalid quantity in items."}
        qty = max(1, min(qty, MAX_CART_QUANTITY))

        name = raw.get("name")
        if not isinstance(name, str) or not name.strip():
            return {"error": "Each item requires a name for display."}

        result_items.append({
            "product_id": pid, "variation_id": variation_id,
            "quantity": qty, "name": name.strip()[:200],
        })

    return {"items": result_items}


register(Tool(
    name="add_to_cart",
    description=(
        "Offer to add specific products directly to the customer's cart, inline in the chat — "
        "the customer sees a real 'Add to cart' button and must click it themselves; nothing is "
        "added until they do. Use this instead of build_cart_url whenever it's enabled, for "
        "products already identified by numeric product ID (from an earlier search/recommendation "
        "or the conversation). For a VARIABLE product (one with size/color/etc. options), you "
        "must know the specific variation_id before calling this — if you don't have it yet, ask "
        "the customer which option they want (or call get_product_variants) FIRST, never guess a "
        "variation. Always include the product's real name for display, and never call this for a "
        "product you haven't confirmed is in stock."
    ),
    parameters={
        "type": "object",
        "properties": {
            "items": {
                "type": "array",
                "items": {
                    "type": "object",
                    "properties": {
                        "product_id": {"type": "integer", "description": "The numeric WooCommerce product ID."},
                        "variation_id": {"type": "integer", "description": "The specific variation ID, required for a variable product — never guessed."},
                        "quantity": {"type": "integer", "description": "How many of this product to add (default 1)."},
                        "name": {"type": "string", "description": "The product's real name, for display on the Add to Cart button.", "maxLength": 200},
                    },
                    "required": ["product_id", "name"],
                    "additionalProperties": False,
                },
                "minItems": 1, "maxItems": 5,
                "description": "The products (and optional quantities/variations) to offer adding to the cart.",
            },
        },
        "required": ["items"],
        "additionalProperties": False,
    },
    handler=add_to_cart,
    access_level="read",
))


MAX_PAYMENT_LINK_ITEMS = 10  # matches Hamman_Live_Query_Handler::MAX_ORDER_ITEMS on the plugin side


def _validate_customer(customer) -> Optional[dict]:
    if not isinstance(customer, dict):
        return None
    out = {}
    for key, max_len in (("name", 255), ("phone", 50), ("email", 255)):
        val = customer.get(key)
        if isinstance(val, str) and val.strip():
            out[key] = val.strip()[:max_len]
    return out or None


def create_payment_link(db: Session, chatbot_id: str, items: List[dict], customer: Optional[dict] = None) -> dict:
    """create_payment_link (doc-04) — the model-callable half of this
    feature is entirely READ-ONLY: it only ever previews a proposed order
    (live price/name/stock per item, via the SAME preview_order action
    Laravel re-checks fresh at confirm time) — it never creates an order
    or a payment link itself. The real order is only ever created after a
    real customer click on the widget's rendered "Confirm & Pay" button,
    which goes through ChatController::createPaymentLink() — a completely
    separate code path with its own from-scratch security checks
    (enabled, per-chatbot amount cap, per-conversation/per-IP-per-day
    limits) that this tool call never touches and cannot bypass.

    customer is passed straight through unused by this function — it only
    exists so the widget's eventual Confirm click can forward it to
    Laravel, which is the first place it's ever actually used (to fill in
    billing details on the real order, if the customer volunteered any).
    """
    if not isinstance(items, list) or not items:
        return {"error": "items must be a non-empty list."}
    if len(items) > MAX_PAYMENT_LINK_ITEMS:
        return {"error": f"At most {MAX_PAYMENT_LINK_ITEMS} items are supported."}

    clean_items = []
    for raw in items:
        if not isinstance(raw, dict):
            return {"error": "Each item must be an object with a product_id."}
        pid = _validate_product_id(raw.get("product_id"))
        if pid is None:
            return {"error": "Invalid product_id in items."}
        item = {"product_id": pid}
        if raw.get("variation_id") is not None:
            vid = _validate_product_id(raw.get("variation_id"))
            if vid is None:
                return {"error": "Invalid variation_id in items."}
            item["variation_id"] = vid
        qty = raw.get("quantity", 1)
        try:
            qty = int(qty)
        except (TypeError, ValueError):
            return {"error": "Invalid quantity in items."}
        item["quantity"] = max(1, min(qty, MAX_CART_QUANTITY))
        clean_items.append(item)

    clean_customer = _validate_customer(customer)

    site = _resolve_site(db, chatbot_id)
    if not site:
        return {"error": "live_check_unavailable",
                "note": "Live order preview is unavailable right now. Do not offer a payment link.", "items": []}

    preview = _query_live(site["domain"], site["secret"], "preview_order", {"items": clean_items})
    if preview is None:
        return {"error": "live_check_unavailable",
                "note": "Live order preview is unavailable right now. Do not offer a payment link.", "items": []}
    if "error" in preview:
        return {"error": preview["error"]}

    return {
        "items": preview.get("items", []),
        "total": preview.get("total"),
        "currency": preview.get("currency", "IRT"),
        "customer": clean_customer,
    }


register(Tool(
    name="create_payment_link",
    description=(
        "Preview a proposed order — live price, name, and stock for each item, plus the real "
        "total — for a customer who wants to pay directly. Use this when a customer has said "
        "what they want to buy and is ready to pay now. This ONLY shows a preview; it never "
        "creates an order or a payment link itself. The customer must see the order summary and "
        "total (rendered by the widget, not your own text) and explicitly click a real 'Confirm "
        "& Pay' button before anything is actually created. Include any name/phone/email the "
        "customer has already volunteered as 'customer', but never ask for it just to use this "
        "tool — it's entirely optional. Never call this for a product you haven't confirmed is in "
        "stock, and never state a total yourself — only this tool's own live total is ever correct."
    ),
    parameters={
        "type": "object",
        "properties": {
            "items": {
                "type": "array",
                "items": {
                    "type": "object",
                    "properties": {
                        "product_id": {"type": "integer", "description": "The numeric WooCommerce product ID."},
                        "variation_id": {"type": "integer", "description": "The specific variation ID, for a variable product — never guessed."},
                        "quantity": {"type": "integer", "description": "How many of this product (default 1)."},
                    },
                    "required": ["product_id"],
                    "additionalProperties": False,
                },
                "minItems": 1, "maxItems": 10,
                "description": "The products (and optional quantities/variations) the customer wants to buy.",
            },
            "customer": {
                "type": "object",
                "properties": {
                    "name": {"type": "string", "maxLength": 255},
                    "phone": {"type": "string", "maxLength": 50},
                    "email": {"type": "string", "maxLength": 255},
                },
                "additionalProperties": False,
                "description": "Any name/phone/email the customer has already volunteered in this conversation, if any.",
            },
        },
        "required": ["items"],
        "additionalProperties": False,
    },
    handler=create_payment_link,
    access_level="read",
))
