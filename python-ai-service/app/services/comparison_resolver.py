"""
Resolve a comparison request's two products deterministically, instead of
asking the model to.

Real incident (2026-10-25, khonehrangi.ir): a customer asked "فلاسک ۸۰۰ را
با فلاسم ۶۰۰ مقایسه کن" (compare flask 800 with [typo] flask 600). The
existing comparison route (tool_router.route()) forces exactly ONE
search_products call before forcing compare_products -- a design that
assumes one lookup resolves the one tool the customer needs, true for
price/availability but never true for a comparison, which is inherently
about two different things. The model searched "۸۰۰" alone, got back two
catalog listings of the SAME physical 800ml flask (one titled with Persian
digits, one with Latin -- a real merchant-side duplicate listing, confirmed
live), and called compare_products with those two ids to satisfy the
"at least 2" requirement. The customer's actual second product -- the
600ml flask, typo'd as "فلاسم" -- was never looked up. The customer got a
comparison table of one product against itself, no narrative text, and no
answer to what they actually asked.

This module splits "A با/و/vs B" into two name strings and resolves each
one with its OWN search_products call before compare_products is ever
considered, so the ids compare_products receives always correspond to the
two things the customer actually named -- never two incidental results of
a single generic query.

Three outcomes per entity, matching the one real fact a live search can
establish:
  resolved   exactly one confident match -- safe to compare by id.
  ambiguous  more than one plausible match, none clearly ahead -- never
             picked for the customer; the honest answer lists the
             candidates and asks which one was meant (this is the
             "خوب" example: "پیدا کردم، ولی مطمئن نیستم... همین است؟" vs
             the "بد" example, which turns an ambiguous MATCH into a false
             "we don't have it" -- not the same mistake as a genuine zero-
             result search).
  not_found  the live search genuinely returned nothing (or everything it
             returned is out of stock) -- a real demand-gap signal, safe to
             report as "we don't carry this", and the one case this module
             still lets _detect_lead_signal fire for (see
             resolve_comparison_entities' own note).
  unavailable the live check itself failed (site down/unreachable) --
             never reported as "not found": an infrastructure failure is
             not evidence of anything about the catalog.

Splitting itself is conservative on purpose: it only ever acts when it can
identify exactly two non-trivial name fragments with high confidence.
Anything else returns None, and the caller falls through to today's
unchanged behaviour (the model's own free search+compare turn) -- so a
message this splitter can't parse is never worse off than before this
module existed.
"""
import difflib
import logging
import re
from typing import List, Optional, Sequence

logger = logging.getLogger(__name__)

_PERSIAN_DIGITS = "۰۱۲۳۴۵۶۷۸۹"
_ARABIC_INDIC_DIGITS = "٠١٢٣٤٥٦٧٨٩"
_DIGIT_TRANSLATION = str.maketrans(
    _PERSIAN_DIGITS + _ARABIC_INDIC_DIGITS,
    "0123456789" * 2,
)


def normalize_digits(text: str) -> str:
    """Persian/Arabic-Indic digits to ASCII. "۸۰۰" and "800" must compare
    equal for fuzzy matching regardless of which script the customer (or
    the merchant's own catalog) happened to use."""
    return (text or "").translate(_DIGIT_TRANSLATION)


# Tried in this order because "در برابر"/"در مقابل"/"vs" unambiguously mean
# comparison and never appear in an unrelated sentence, so they're checked
# first; "با" is still fairly specific to "X with/versus Y" in a comparison
# message. "و" (plain "and") is the weakest signal -- extremely common in
# ordinary Persian for reasons that have nothing to do with comparing two
# products -- so it is tried last and only ever reached for a message
# intent_classifier has *already* tagged "comparison" (see this module's
# only caller), which is the guard that keeps it from mis-splitting
# unrelated text.
_SEPARATORS = [
    re.compile(r"\bدر\s+برابر\b"),
    re.compile(r"\bدر\s+مقابل\b"),
    re.compile(r"\bversus\b", re.IGNORECASE),
    re.compile(r"\bvs\.?\b", re.IGNORECASE),
    re.compile(r"\bبا\b"),
    re.compile(r"\bو\b"),
]

# Stripped off whatever remains on each side after splitting -- the verb
# (or, for the very common "فرق A و B چیست؟" question form, the
# interrogative tail) itself, not part of either product's name.
# Longest/most specific phrases first so "مقایسه کن" doesn't leave a
# dangling "کن" behind.
_NOISE_PHRASES = [
    "را با هم مقایسه کن", "با هم مقایسه کن", "را مقایسه کن", "مقایسه کن",
    "مقایسه کنید", "را مقایسه کنید", "مقایسه", "فرق", "تفاوت", "چه فرقی دارد",
    "چه فرقی دارند", "چیست؟", "چیست", "چیه؟", "چیه", "compare", "comparison",
]
_NOISE_RE = re.compile("|".join(re.escape(p) for p in _NOISE_PHRASES), re.IGNORECASE)

# Plain punctuation/whitespace only -- deliberately NOT a character class
# containing "ر"/"ا" (an earlier version of this tried `[...را]`, which
# strips any trailing run of the *individual letters* ر and ا, eating into
# an unrelated word that happens to end in one of them). The literal word
# "را" is stripped separately, only as a whole leading/trailing word.
_EDGE_PUNCT = re.compile(r"^[\s،,.؟?!]+|[\s،,.؟?!]+$")
_TRAILING_RA = re.compile(r"\s+را\s*$")
_LEADING_RA = re.compile(r"^\s*را\s+")

_MIN_ENTITY_LEN = 2


def _clean_fragment(fragment: str) -> str:
    cleaned = _NOISE_RE.sub(" ", fragment)
    # Several rounds: stripping "را" can expose more edge punctuation
    # (and vice versa), e.g. ". را " -> ". " -> "" only across two passes.
    for _ in range(3):
        before = cleaned
        cleaned = _EDGE_PUNCT.sub("", cleaned)
        cleaned = _TRAILING_RA.sub("", cleaned)
        cleaned = _LEADING_RA.sub("", cleaned)
        cleaned = cleaned.strip()
        if cleaned == before:
            break
    return cleaned


def split_comparison_entities(query: str) -> Optional[List[str]]:
    """Two product-name fragments, or None when the split isn't confident.

    Only ever splits ONCE, into exactly two parts -- a three-way "A و B و C
    مقایسه کن" is real but rare enough that reaching for the common case
    cleanly matters more than handling every case; it returns None here
    and falls through to the model-driven flow like any other message this
    splitter can't parse."""
    if not query or not query.strip():
        return None

    normalized = normalize_digits(query)
    for sep in _SEPARATORS:
        m = sep.search(normalized)
        if not m:
            continue
        left = _clean_fragment(normalized[:m.start()])
        right = _clean_fragment(normalized[m.end():])
        if len(left) >= _MIN_ENTITY_LEN and len(right) >= _MIN_ENTITY_LEN:
            return [left, right]
        # This separator matched but didn't leave two real fragments
        # (e.g. "و" inside a product's own name) -- try the next,
        # weaker separator rather than giving up immediately.
    return None


# Below this ratio (difflib.SequenceMatcher, case/digit-normalized) a
# result isn't treated as the entity at all -- just noise in the search
# results, same as not having searched.
_MATCH_FLOOR = 0.45
# A top match at least this far ahead of the runner-up is confident enough
# to resolve on its own; closer than this and the honest answer is "which
# one did you mean", not a guess. 0.45 was chosen over a stricter
# threshold specifically to tolerate a one-letter typo in a ~5-7 character
# product-name token (see the "فلاسم"/"فلاسک" incident this module exists
# for) without conflating that with a genuinely different product.
_CONFIDENT_MARGIN = 0.12


def _score(entity: str, name: str) -> float:
    a = normalize_digits(entity).strip().lower()
    b = normalize_digits(name or "").strip().lower()
    if not a or not b:
        return 0.0
    return difflib.SequenceMatcher(a=a, b=b).ratio()


def _classify_candidates(entity: str, results: Sequence[dict]) -> dict:
    scored = []
    for r in results:
        if not isinstance(r, dict) or not r.get("name"):
            continue
        scored.append((_score(entity, r["name"]), r))
    scored.sort(key=lambda pair: pair[0], reverse=True)
    scored = [pair for pair in scored if pair[0] >= _MATCH_FLOOR]

    if not scored:
        return {"status": "not_found", "product_id": None, "name": None, "candidates": []}

    candidates = [{"product_id": r.get("product_id"), "name": r.get("name"),
                   "price": r.get("price"), "score": round(score, 3)} for score, r in scored]

    if len(scored) == 1 or (scored[0][0] - scored[1][0]) >= _CONFIDENT_MARGIN:
        best = scored[0][1]
        return {"status": "resolved", "product_id": best.get("product_id"),
                "name": best.get("name"), "candidates": candidates}

    return {"status": "ambiguous", "product_id": None, "name": None, "candidates": candidates}


def resolve_comparison_entities(db, chatbot_id: str, entities: List[str]) -> List[dict]:
    """One real search_products call per entity -- never the model's own
    free-text query, always the exact fragment this module split out.

    Each returned dict is {"text", "status", "product_id", "name",
    "candidates"}. Deliberately calls product_tools.search_products
    directly rather than going through the model/tool-call loop:
    _detect_lead_signal (tool_calling_service.py) only ever sees tool
    calls the MODEL issued, so a resolution search run here never feeds a
    false demand-gap signal from a split that turned out wrong -- that
    guard is structural (these calls are simply invisible to it), not a
    rule either this function or that one has to remember to apply.

    The one demand-gap-worthy case -- a cleanly split entity whose live
    search genuinely found nothing -- is intentionally NOT logged as a
    lead_signal from inside this module; the caller (tool_calling_service)
    does that itself, exactly once, the same way it already does for every
    other tool result, so there's one place that owns "first signal wins"
    rather than two."""
    from app.services.tools.product_tools import search_products

    out = []
    for entity in entities:
        result = search_products(db=db, chatbot_id=chatbot_id, query=normalize_digits(entity))
        if not result.get("live"):
            out.append({"text": entity, "status": "unavailable", "product_id": None,
                        "name": None, "candidates": [], "search_result": result})
            continue
        classified = _classify_candidates(entity, result.get("results") or [])
        classified["text"] = entity
        classified["search_result"] = result
        out.append(classified)
    return out
