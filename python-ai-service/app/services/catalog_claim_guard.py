"""
Spot a blanket "we don't have that" before it reaches a customer.

Real incident: asked for a green mug, the assistant answered "we don't
currently have a green product" -- then, in the same turn, linked a product
from this store. One search came back empty and got reported as a fact about
the whole catalog, not about that one search. "I didn't find it" and "we
don't have it" are not the same claim: the first is honest about what one
search covers, the second is a certainty the assistant's single retrieval
pass has no way to back up, and for a shop it is the version that loses a
sale over a wording mismatch rather than a real stockout.

This is a detector, not a rewriter: unlike claim_guard.py's five provable
actions, there's no tool result that can prove the business genuinely lacks
something system-wide, so there's nothing to check an absolute claim against
and no safe automatic replacement to substitute. What this module is for is
catching the sentence shape in tests and logs -- the actual fix is the
grounding-rule instruction (see rag_service.py's GROUNDING_RULES /
GROUNDING_RULES_FA and the search_products/recommend_products tool
descriptions) that tells the model to hedge instead of asserting.
"""
import re
from typing import List

_SENTENCE_SPLIT = re.compile(r"(?<=[.!?؟…])\s+|\n+")

# A hedge marker anywhere in the sentence means the claim is already scoped
# to "what I looked at", not the whole catalog -- these sentences are fine
# and must never be flagged alongside the absolute ones below.
_HEDGE_MARKERS = re.compile(
    r"پیدا نکرد|در (نتایجی|چیزی) که|ممکن است|شاید|در جستجوی خودم|در آنچه (بررسی|جستجو)"
    r"|didn.?t find|couldn.?t find|in (what|the results) i (looked|searched)|might be listed"
    r"|may be listed|not sure|perhaps",
    re.IGNORECASE,
)

# Absolute, unhedged denial that the business carries/has/offers something --
# a product, a color, a size, a model, anything. Anchored on the negated verb
# plus a first-person-plural/business subject, same anchoring approach as
# claim_guard.py's completion-verb patterns, so an offer or a question (e.g.
# "آیا رنگ سبز هم دارید؟") never matches -- only a flat denial does.
_ABSOLUTE_DENIAL_PATTERNS = [
    r"(ما\s+)?(چنین\s+)?(محصول|کالا|مدل|رنگ|سایز|نوع)[^.!؟\n]{0,40}\s*(را\s+)?ندار(یم|د)\b",
    r"\bما\s+[^.!؟\n]{0,60}\s*ندار(یم|د)\b",
    r"\bموجود\s+نیست\b[^.!؟\n]{0,20}$",
    r"در\s+(فهرست|لیست)\s*(محصولات)?\s*(ما)?\s*(موجود\s*نیست|نیست|نداریم)\b",
    r"\bwe\s+(do\s*not|don.?t)\s+(have|carry|offer|sell|stock)\b",
    r"\b(is|are)\s+not\s+(available|carried|offered|sold)\s+(in\s+our|here|at\s+our)\b",
    r"\bnot\s+(available|carried|in\s+stock)\s+in\s+our\s+(catalog|store|shop|range)\b",
]
_COMPILED_DENIALS = [re.compile(p, re.IGNORECASE) for p in _ABSOLUTE_DENIAL_PATTERNS]


def is_absolute_catalog_denial(sentence: str) -> bool:
    """True if this one sentence flatly denies the business carries
    something, with no hedge scoping that denial to a single search."""
    if _HEDGE_MARKERS.search(sentence):
        return False
    return any(p.search(sentence) for p in _COMPILED_DENIALS)


def find_absolute_catalog_denials(text: str) -> List[str]:
    """Every sentence in `text` that makes an unhedged denial, in order."""
    if not text:
        return []
    return [s for s in _SENTENCE_SPLIT.split(text) if s.strip() and is_absolute_catalog_denial(s)]
