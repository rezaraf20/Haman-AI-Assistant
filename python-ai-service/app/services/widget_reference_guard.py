"""
Stop the assistant pointing a customer at a button that doesn't exist.

Real incident: offered to add a product to the cart, the assistant wrote
"to add it to your cart, click the button below" — no tool had been called
that turn, so no widget_blocks existed, so there was no button. The
customer's own next message was "which button? there's no button."

Unlike catalog_claim_guard.py's absolute-denial case, this one has a real,
checkable fact to verify a claim against: widget_blocks either contains a
real block this turn or it doesn't. That makes this a rewriter, not just a
detector — a sentence pointing at a UI element is removed outright when
nothing backs it, the same posture claim_guard.py takes with a false
completed-action claim.

Only whole sentences are removed, never spliced words, so what's left still
reads as language -- same rule as claim_guard.py's own.
"""
import re
from typing import List, Tuple

_SENTENCE_SPLIT = re.compile(r"(?<=[.!?؟…])\s+|\n+")

# An instruction to interact with a UI element the text itself doesn't
# render. Anchored on "below"/"zir" and the click/tap verb together so an
# unrelated, ordinary sentence that happens to contain "link" or "button"
# (describing a product feature, say) is never caught.
_UI_REFERENCE_PATTERNS = [
    r"روی\s*(دکمه|لینک)[^.!؟\n]{0,25}(کلیک\s*کن(?:ید)?|بزنید|بزن)\b",
    r"(دکمه|لینک)[‌\s]*(ی)?\s*زیر\b",
    r"\bclick\s+(the\s+)?(button|link|here)\b",
    r"\btap\s+(the\s+)?button\b",
    r"\b(button|link)\s+below\b",
]
_COMPILED = [re.compile(p, re.IGNORECASE) for p in _UI_REFERENCE_PATTERNS]


def references_ui_element(sentence: str) -> bool:
    """True if this one sentence tells the customer to interact with a
    button/link the response is supposed to be rendering itself."""
    return any(p.search(sentence) for p in _COMPILED)


def strip_unbacked_widget_references(text: str, has_widget_blocks: bool) -> Tuple[str, List[str]]:
    """Remove any sentence referencing a UI element when nothing this turn
    actually built one. Returns the cleaned text and the removed sentences
    (empty list when has_widget_blocks is True -- nothing to check, since a
    real block exists and could legitimately be what the text points at)."""
    if has_widget_blocks or not text:
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
