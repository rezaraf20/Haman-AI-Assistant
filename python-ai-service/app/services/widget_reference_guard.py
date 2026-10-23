"""
Stop the assistant pointing a customer at a button that doesn't exist.

Original incident: offered to add a product to the cart, the assistant
wrote "to add it to your cart, click the button below" — no tool had been
called that turn, so no widget_blocks existed, so there was no button. The
customer's own next message was "which button? there's no button."

A second, distinct incident (2026-10-23) found this guard itself was only
half right: it treated "a widget_block exists this turn" as proof ANY
button/click reference was backed, regardless of WHICH block type. Once
add_to_cart became an auto-firing action (haman-widget.js's
autoAddToCart() — no click, ever), that stopped being true for it
specifically: a real customer got "I've prepared the button, click it" for
an add_to_cart turn, with the tool's own description already telling the
model not to say that (see product_tools.add_to_cart's docstring) — the
model said it anyway, and this guard, seeing `widget_blocks` was non-empty,
let it straight through. Fixed by checking the actual block TYPE, not just
whether the list is non-empty: only build_cart_url/create_payment_link/
get_order_status ever render something a customer genuinely clicks;
add_to_cart and the product-display blocks do not, so a click/button
reference survives only when one of the genuinely clickable types is
actually present this turn.

Unlike catalog_claim_guard.py's absolute-denial case, this one has a real,
checkable fact to verify a claim against: widget_blocks either contains a
genuinely clickable block this turn or it doesn't. That makes this a
rewriter, not just a detector — a sentence pointing at a UI element is
removed outright when nothing backs it, the same posture claim_guard.py
takes with a false completed-action claim.

Only whole sentences are removed, never spliced words, so what's left still
reads as language -- same rule as claim_guard.py's own.
"""
import re
from typing import Iterable, List, Tuple

_SENTENCE_SPLIT = re.compile(r"(?<=[.!?؟…])\s+|\n+")

# Only these block types render something a customer actually clicks —
# add_to_cart fires on its own (haman-widget.js's autoAddToCart()), and
# product_cards/product_compare never had a button at all. See this
# module's own docstring for the 2026-10-23 incident this distinction
# fixes: "a widget_block exists" is not the same claim as "a clickable one
# exists".
_CLICKABLE_BLOCK_TYPES = {"cart_links", "payment_link_preview", "order_status_otp"}

# An instruction to interact with a UI element the text itself doesn't
# render. Anchored on "below"/"zir" and the click/tap verb together so an
# unrelated, ordinary sentence that happens to contain "link" or "button"
# (describing a product feature, say) is never caught.
_UI_REFERENCE_PATTERNS = [
    r"روی\s*(دکمه|لینک)[^.!؟\n]{0,25}(کلیک\s*کن(?:ید)?|بزنید|بزن)\b",
    r"(دکمه|لینک)[‌\s]*(ی)?\s*زیر\b",
    # "...the button is ready, click on IT" — "آن"/"اون" referring back to a
    # دکمه/لینک named earlier in the same sentence, not repeated right after
    # "روی" (see the 2026-10-23 add_to_cart incident this pattern was added
    # for, in this module's own docstring).
    r"(دکمه|لینک)[^.!؟\n]{0,60}روی\s*(آن|اون)\b[^.!؟\n]{0,10}(کلیک\s*کن(?:ید)?|بزنید|بزن)\b",
    r"\bclick\s+(the\s+)?(button|link|here)\b",
    r"\btap\s+(the\s+)?button\b",
    r"\b(button|link)\s+below\b",
]
_COMPILED = [re.compile(p, re.IGNORECASE) for p in _UI_REFERENCE_PATTERNS]


def references_ui_element(sentence: str) -> bool:
    """True if this one sentence tells the customer to interact with a
    button/link the response is supposed to be rendering itself."""
    return any(p.search(sentence) for p in _COMPILED)


def strip_unbacked_widget_references(text: str, widget_block_types: Iterable[str] = ()) -> Tuple[str, List[str]]:
    """Remove any sentence referencing a UI element when nothing this turn
    actually rendered a genuinely clickable one. Returns the cleaned text
    and the removed sentences (empty list when a clickable block type is
    present -- nothing to check, since a real button/link exists and could
    legitimately be what the text points at)."""
    has_clickable = bool(_CLICKABLE_BLOCK_TYPES & set(widget_block_types or ()))
    if has_clickable or not text:
        return text or "", []

    kept: List[str] = []
    removed: List[str] = []
    for sentence in _SENTENCE_SPLIT.split(text):
        if not sentence.strip():
            continue
        if references_ui_element(sentence):
            removed.append(sentence.strip())
        else:
            kept.append(sentence)

    return " ".join(kept).strip(), removed
