"""
Decide which tool runs from the intent, instead of letting the model choose.

Free choice was unreliable: offered nine tools, the model picked the wrong one,
or answered without calling anything at all. The intent classifier is about 80%
accurate on real messages and is already computed for every question, so it is
a better chooser than the model is. Here it picks the tool and the model is
left with the one job it is good at -- pulling the arguments (product name,
SKU, quantity, phone number) out of the sentence.

Two limits shape the whole file.

The first is consent. add_to_cart, create_payment_link and build_cart_url act
on a customer's order or money, and must follow an explicit request, never a
guess made from a regex. They are never routed here; the model may still call
them on its own.

The second is that a forced tool must be callable. compare_products requires
numeric product ids, get_product_availability wants a SKU or an id, and
get_order_status requires the mobile number the order was placed with. Force a
tool whose required argument is not available and the model does not decline --
it invents one, and an invented phone number or product id is worse than no
tool call at all. So a route whose arguments cannot be known yet goes through
search_products first, and the target is forced on the following turn once its
results carry real ids. get_order_status has no such lookup: without a phone
number in the conversation it is simply not routed, and the model asks.
"""
import re
from typing import List, Optional, Sequence

# intent (see intent_classifier.INTENTS) -> the tool that should answer it
_INTENT_ROUTES = {
    "price": "get_product_availability",
    "availability": "get_product_availability",
    "comparison": "compare_products",
    "consultation": "recommend_products",
    "order_status": "get_order_status",
}

# Acts on the customer's order or money: requires their explicit say-so.
UNROUTABLE_TOOLS = frozenset({"add_to_cart", "create_payment_link", "build_cart_url"})

# Which questions are worth a tool-deciding call at all.
#
# Wider than the routing table above, because a question can need a tool
# without one being forced: "payment" reaches create_payment_link, which is
# never routed but is exactly what that intent wants. Everything else --
# shipping, returns, authenticity, technical support, and the "other" bucket
# -- is answered from the indexed content, and running a deciding turn for
# those spends a model call to be told there was nothing to call.
#
# The classifier is right about 80% of the time, so this trades a tool call
# on a question it mislabels for no call on the majority that never needed
# one. Measured on 30 real customer messages: see the deploy report.
TOOL_CANDIDATE_INTENTS = frozenset({
    "price", "availability", "comparison", "consultation", "order_status", "payment",
})


# Needs a numeric product id the customer never types, so it cannot be the
# first call unless the question carries a SKU.
_NEEDS_PRODUCT_LOOKUP = frozenset({"compare_products", "get_product_availability"})

_LOOKUP_TOOL = "search_products"

# Iranian mobile, the only contact get_order_status accepts. Matched on the
# conversation, never invented.
_MOBILE = re.compile(r"(?:(?:\+?98|0)9\d{9})")

# A part-number-like token: at least one digit and one letter/separator, or a
# long digit run. Deliberately the same shape rag_service looks for.
_SKU_LIKE = re.compile(r"\b(?=[A-Za-z0-9\-_/]*\d)[A-Za-z0-9][A-Za-z0-9\-_/]{3,}\b")


def is_tool_candidate(intent: Optional[str]) -> bool:
    """Whether this question is worth asking the model about tools."""
    return (intent or "") in TOOL_CANDIDATE_INTENTS


class RoutePlan:
    """Which tool to force next on the way to `target`."""

    def __init__(self, target: str, via_lookup: bool):
        self.target = target
        self.via_lookup = via_lookup

    def forced_tool(self, already_called: Sequence[str]) -> Optional[str]:
        """
        The tool to force on this turn, or None to let the model choose.

        None once the target has run: whatever comes after it -- a follow-up
        call, or the final answer -- is the model's to decide.
        """
        if self.target in already_called:
            return None
        if self.via_lookup and _LOOKUP_TOOL not in already_called:
            return _LOOKUP_TOOL
        return self.target

    def __repr__(self) -> str:  # pragma: no cover - debugging aid
        return f"RoutePlan(target={self.target!r}, via_lookup={self.via_lookup})"


def _conversation_text(query: str, history: Optional[List[dict]]) -> str:
    parts = [query or ""]
    for turn in (history or [])[-6:]:
        if turn.get("content"):
            parts.append(str(turn["content"]))
    return "\n".join(parts)


def has_mobile(query: str, history: Optional[List[dict]] = None) -> bool:
    return bool(_MOBILE.search(_conversation_text(query, history)))


def has_sku_like_token(query: str) -> bool:
    """Only the customer's own message: a SKU mentioned six turns ago is not
    necessarily the product being asked about now."""
    return bool(_SKU_LIKE.search(query or ""))


def route(
    intent: str, enabled_tools: Sequence[str], query: str,
    history: Optional[List[dict]] = None,
) -> Optional[RoutePlan]:
    """
    The plan for this question, or None to leave the choice to the model.

    None whenever routing would be a guess: an intent with no mapped tool, a
    mapped tool the chatbot has switched off, or a tool whose required
    argument is not in the conversation.
    """
    target = _INTENT_ROUTES.get(intent)
    if not target or target in UNROUTABLE_TOOLS:
        return None

    enabled = set(enabled_tools or ())
    if target not in enabled:
        return None

    if target == "get_order_status":
        # No lookup path exists for a phone number, and inventing one would
        # send an SMS to a stranger.
        return RoutePlan(target, via_lookup=False) if has_mobile(query, history) else None

    if target in _NEEDS_PRODUCT_LOOKUP:
        # A question carrying a part number can go straight to the tool;
        # anything else has to find the product first.
        if target == "get_product_availability" and has_sku_like_token(query):
            return RoutePlan(target, via_lookup=False)
        if _LOOKUP_TOOL not in enabled:
            return None
        return RoutePlan(target, via_lookup=True)

    return RoutePlan(target, via_lookup=False)
