"""
Stop the assistant claiming it did something it did not do.

With add_to_cart switched off, the model still answered "it has been added to
your cart". Nothing was added; there was no tool to add it with. That is not a
quality problem, it is the assistant lying to a customer about their own order,
and a rule in the system prompt is not enough to prevent it -- the prompt
already says not to, and it happened anyway.

So the text is checked after it is generated and before it is sent. Five
actions can only be claimed if this same turn actually produced them:

    cart_add      something was put in the cart
    cart_link     a cart link was built
    payment_link  a payment link was prepared
    order_status  an order lookup was started
    callback      a callback/contact request was registered

The proof is the tool result, not the model's word for it. A turn where the
tool was off, where it was on but returned an error, or where the shop was
unreachable, all produce the same thing: no proof. The claiming sentence is
removed and replaced with one that says plainly what did not happen.

Only cart_link is ever provable in the turn that runs it -- see
proven_actions() for why the other four are not, which is most of the value
here: three of them look like they succeeded and have not.

Only whole sentences are removed, never spliced words, so what is left still
reads as language. Everything not making one of these five claims is passed
through untouched -- this is a guard, not a rewriter.
"""
import re
from typing import Iterable, List, Optional, Set, Tuple

ACTIONS = ("cart_add", "cart_link", "payment_link", "order_status", "callback")

# Sentence boundaries, Persian and English. Kept with the sentence they end so
# rejoining does not lose punctuation.
_SENTENCE_SPLIT = re.compile(r"(?<=[.!?؟…])\s+|\n+")

# A claim is past-tense/completed or "it is ready". A question, an offer, or a
# conditional ("shall I add it?", "to add it, say...") is not a claim, so the
# patterns below are deliberately anchored on completion wording.
_CLAIM_PATTERNS = {
    # The verb forms matter more than the noun. A live model wrote "به سبد
    # خرید شما اضافه کردیم" -- first person plural, which an earlier, narrower
    # list missed entirely. (?!ن) keeps the negations out: "اضافه نشد" and
    # "اضافه نکردیم" are the opposite claim.
    "cart_add": [
        r"سبد\s*(خرید)?\s*(شما|تان|ت)?\s*اضافه\s*(?!ن)(شد|شده|گردید|کرد[یم]?[مد]?|نمود[یم]?[مد]?)",
        r"(در|توی|تو)\s+سبد\s*(خرید)?\s*(شما|تان)?\s*(قرار\s*گرفت|گذاشت[مه]|ثبت\s*شد)",
        # "حالا سبد خرید شما شامل این قلم است" -- same assertion, no verb.
        r"سبد\s*(خرید)?\s*(شما|تان)\s*(هم\s*)?شامل",
        r"\badded\s+(it\s+)?to\s+(your\s+)?(cart|basket)\b",
        r"\b(is|has been)\s+(now\s+)?in\s+your\s+(cart|basket)\b",
    ],
    "cart_link": [
        r"لینک\s+سبد\s*(خرید)?\s*(شما|تان)?\s*(آماده|ساخته|ایجاد)\s*(شد|است|گردید)",
        r"\b(cart|basket)\s+link\s+(is\s+)?(ready|created|built)\b",
    ],
    "payment_link": [
        r"لینک\s+پرداخت\s*(شما|تان)?\s*(آماده|ساخته|ایجاد|صادر)\s*(شد|است|گردید)",
        r"(لینک|درگاه)\s+پرداخت\s+را\s+(برای\s*(شما|تان)?\s*)?(ساختم|ساختیم|ایجاد\s*کرد[یم]?[مد]?|آماده\s*کرد[یم]?[مد]?|فرستاد[یم]?[مد]?)",
        # Handing over a URL labelled as one is the claim, whatever the verb.
        # A live model answered "لینک پرداخت: <http://shop/?p=11>" with the
        # payment tool switched off -- and that address is a product page.
        r"لینک\s+پرداخت\b[^\n:：]{0,40}[:：]",
        r"\bpayment\s+link\b[^\n:：]{0,40}[:：]",
        r"\bpayment\s+link\s+(is\s+)?(ready|created|generated|prepared)\b",
        r"\b(created|generated|prepared)\s+(a\s+)?payment\s+link\b",
    ],
    "order_status": [
        r"سفارش\s*(شما|تان)?\s*را\s*(بررسی|پیگیری|چک)\s*کردم",
        r"(وضعیت\s+سفارش\s*(شما|تان)?\s*(این|به\s*شرح)\s*است)",
        r"(کد\s+(تأیید|تایید|verification)\s*(را)?\s*(برای\s*(شما|تان)?\s*)?(فرستاد[می]|ارسال\s*شد))",
        r"\b(checked|looked up|tracked)\s+your\s+order\b",
        r"\b(sent|texted)\s+(you\s+)?(a\s+)?(verification\s+)?code\b",
    ],
    "callback": [
        r"(درخواست|شماره|اطلاعات)\s*(تماس)?\s*(شما|تان)?\s*(با\s*موفقیت\s*)?ثبت\s*شد",
        r"(همکاران\s*ما|با\s*شما)\s*تماس\s*(خواهند\s*گرفت|می‌?گیرند)\s*[.،]?\s*(ثبت\s*شد)?",
        r"\b(your\s+)?(callback|contact)\s+request\s+(has\s+been\s+)?(registered|recorded|saved)\b",
    ],
}

_COMPILED = {
    action: [re.compile(p, re.IGNORECASE) for p in patterns]
    for action, patterns in _CLAIM_PATTERNS.items()
}

# What to say instead. Never re-promises the action, and never invents a
# reason -- the customer is told the thing did not happen and what to do.
_REPLACEMENT_FA = {
    "cart_add": "این محصول به سبد خرید شما اضافه نشد؛ لطفاً از صفحه‌ی خود محصول آن را به سبد اضافه کنید.",
    "cart_link": "لینک سبد خرید ساخته نشد؛ لطفاً از صفحه‌ی محصول اقدام کنید.",
    "payment_link": "لینک پرداختی ساخته نشد؛ برای تکمیل خرید از صفحه‌ی سبد خرید فروشگاه اقدام کنید.",
    "order_status": "وضعیت سفارش بررسی نشد؛ برای پیگیری، لطفاً شماره‌ی موبایلی را که با آن سفارش ثبت کرده‌اید بفرستید.",
    "callback": "درخواست تماس ثبت نشد؛ لطفاً دوباره شماره‌ی تماستان را بفرستید.",
}

_REPLACEMENT_EN = {
    "cart_add": "The item was not added to your cart; please add it from the product page.",
    "cart_link": "No cart link was created; please use the product page instead.",
    "payment_link": "No payment link was created; please complete the purchase from the shop's cart page.",
    "order_status": "Your order was not looked up; to track it, please send the mobile number you ordered with.",
    "callback": "No callback request was registered; please send your contact number again.",
}


def claims_in(sentence: str) -> Set[str]:
    """Which of the five actions this one sentence asserts as done."""
    return {
        action
        for action, patterns in _COMPILED.items()
        if any(p.search(sentence) for p in patterns)
    }


def verify_claims(
    text: Optional[str], proven: Iterable[str], is_fa: bool = True
) -> Tuple[str, List[str]]:
    """
    Return the text with unproven claims replaced, and which actions were cut.

    `proven` is the set of actions this turn genuinely performed, derived from
    tool results -- never from the text itself.
    """
    if not text:
        return text or "", []

    proven_set = set(proven or ())
    replacements = _REPLACEMENT_FA if is_fa else _REPLACEMENT_EN

    kept: List[str] = []
    removed: List[str] = []
    already_said: Set[str] = set()

    for sentence in _SENTENCE_SPLIT.split(text):
        if not sentence.strip():
            continue

        unproven = claims_in(sentence) - proven_set
        if not unproven:
            kept.append(sentence)
            continue

        # One correction per action, however many sentences claimed it.
        for action in sorted(unproven):
            removed.append(action)
            if action not in already_said:
                already_said.add(action)
                kept.append(replacements[action])

    return " ".join(kept).strip(), removed


def proven_actions(widget_blocks: Iterable[dict], tool_results: Iterable[dict]) -> Set[str]:
    """
    The actions a turn can honestly claim, read from what the tools returned.

    Only one of the five is ever provable inside the turn that runs it, and
    that asymmetry is the point:

    build_cart_url returns real URLs, so a cart link genuinely exists ->
    cart_link is proven.

    add_to_cart, create_payment_link and get_order_status do not do the thing
    they are named after. They prepare something the customer must then click:
    the loop's own handling calls these "intent only" and "preview only", and
    create_payment_link returns a price preview with no link in it at all. The
    real outcomes arrive later, from the shop, as cart_add_succeeded,
    payment_link_created and order_status_viewed. So inside this turn there is
    nothing to prove, and "it has been added" is false even when the tool
    succeeded -- which is exactly the sentence that started this.

    A callback is registered by the customer answering an offer, never by the
    assistant, so it is never provable here either.

    A tool that is off, that errored, or that could not reach the shop all
    reach this the same way: no block was built, so nothing is proven.
    """
    proven: Set[str] = set()

    for block in widget_blocks or ():
        if (block or {}).get("type") == "cart_links":
            proven.add("cart_link")

    # Kept so a future in-turn confirmation has somewhere to land rather than
    # being bolted on at the call sites.
    for result in tool_results or ():
        if isinstance(result, dict) and result.get("confirmed_action") in ACTIONS:
            proven.add(result["confirmed_action"])

    return proven
