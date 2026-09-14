"""
Intent classification — doc-04's "Intent analytics" (Very high), and the
prerequisite for revenue attribution: you can't say "the bot drove this
sale" without first knowing which messages were even purchase-adjacent.

Deliberately NOT an extra LLM call per message — the task's own instruction
was explicit that an added LLM call per message is expensive, and to
either use a light call or extract the signal from the tool-call path
instead. This goes one step further: a plain keyword/regex classifier,
bilingual (fa/en), checked in a fixed most-specific-first order. Real cost:
zero extra tokens, zero extra LLM round-trip, a few microseconds of CPU per
message. See rag_service.py's call site for where this runs (once per
incoming user message, unconditionally — there's no reason to gate a
free, response-shape-neutral analytics signal behind an opt-in flag the
way the paid tool-calling path is).

This is a heuristic, not a certainty — a real accuracy assessment against
20 real production messages (manual read of the model's own guess vs. what
a human would call it) is part of this feature's own acceptance criteria,
and the honest result of that assessment belongs in the deploy report, not
a claim made here in code.
"""
import re
from typing import Optional

INTENTS = (
    "price", "availability", "comparison", "consultation", "authenticity",
    "shipping", "return", "payment", "order_status", "technical_support", "other",
)

# Checked in this fixed order — most specific/least-ambiguous vocabulary
# first, so e.g. a message about order *tracking* doesn't fall through to
# the more general "availability" bucket just because it also mentions a
# product. Each entry: (intent, compiled pattern). A single _P() call
# builds one case-insensitive alternation from bilingual phrase lists.
def _P(*phrases: str) -> re.Pattern:
    return re.compile("|".join(re.escape(p) for p in phrases), re.IGNORECASE)


def _P_RAW(*fragments: str) -> re.Pattern:
    """Same as _P but the fragments are regex, not literals.

    Needed for the one case where a literal is actively wrong: "دارین"
    matched inside "ندارین", so "کانال تلگرام ندارین؟" — a negation, and not
    a stock question at all — was classified as availability.
    """
    return re.compile("|".join(fragments), re.IGNORECASE)

_PATTERNS = [
    ("authenticity", _P(
        "اصل است", "اصله", "اصل هست", "اصالت", "تقلبی", "فیک", "جعلی", "اورجینال", "غیر اصل",
        "genuine", "authentic", "counterfeit", "fake", "knockoff", "is this real",
    )),
    ("order_status", _P(
        "سفارشم", "سفارش من", "پیگیری سفارش", "کد سفارش", "شماره سفارش", "وضعیت سفارش", "سفارش کجاست",
        "my order", "order status", "track my order", "where is my order", "order number", "tracking number",
    )),
    ("return", _P(
        "مرجوع", "پس دادن", "برگشت کالا", "عودت", "تعویض کالا",
        "return policy", "return this", "refund", "exchange this", "can i return", "money back",
    )),
    ("payment", _P(
        "درگاه پرداخت", "کارت به کارت", "پرداخت در محل", "قسطی", "اقساط", "روش پرداخت",
        "payment method", "pay by", "installment", "credit card", "checkout", "how do i pay",
    )),
    ("shipping", _P(
        "هزینه ارسال", "ارسال رایگان", "چند روز میرسه", "چقدر طول میکشه", "زمان ارسال", "پست",
        "تحویل کی", "کی میرسه",
        "shipping cost", "free shipping", "how long to arrive", "delivery time", "when will it arrive",
        "how many days to ship",
    )),
    ("comparison", _P(
        "مقایسه", "کدوم بهتره", "فرقش چیه", "تفاوتشون", "کدومو بگیرم بهتره",
        " vs ", "compare", "comparison", "difference between", "which is better", "better than",
    )),
    ("consultation", _P(
        "پیشنهاد میدی", "چی بگیرم", "مناسب منه", "راهنمایی کن", "کدوم مناسبه",
        # "مشاوره" bare, not just "مشاوره میخوام": the real messages ask for
        # it every other way round — "امکان مشاوره رایگان هم هست؟", "چطور
        # میتونم مشاوره رایگان بگیرم؟" — and the fixed phrase caught none of
        # them. Same for someone describing a need rather than naming a
        # product: "من دنبال چراغ مطالعه میگردم که شارژی باشه".
        "مشاوره", "دنبال", "میگردم", "می‌گردم",
        "what do you recommend", "which one should i", "recommend for", "suggest a", "advice on",
        "best for my", "what's good for", "looking for a", "i need something",
    )),
    ("availability", _P_RAW(
        *[re.escape(x) for x in (
            "موجوده", "موجود است", "موجود هست", "موجود داری", "تمام شده", "ناموجود", "در انبار",
        )],
        # Not preceded by ن: "ندارین/ندارید" is a negation, the opposite of
        # what this intent means.
        r"(?<!ن)دارید", r"(?<!ن)دارین", r"(?<!ن)داريد",
        *[re.escape(x) for x in (
        # "دارید" is how this question is actually asked in Persian, and its
        # absence was the single biggest hole: every one of "ماگ سبز هم
        # دارید؟", "کیف غذا هم دارید؟", "تراول ماگ خرس کلاه دار دارید؟" fell
        # through to "other".

        # The busiest tenant sells services, where "do you do X" is the same
        # question as "do you have X". Spelled out rather than matching a
        # bare verb, so "شرکت در چه حوزه‌هایی کار میکنه" stays out of it.
        "انجام میدین", "انجام میدید", "انجام می‌دهید", "کار میکنید", "کار می‌کنید",
        "طراحی میکنید", "طراحی می‌کنید",
        "in stock", "out of stock", "do you have", "do you offer", "do you do",
        "is it available", "available now",
        )],
    )),
    ("price", _P(
        # "قیمت"/"هزینه" bare (price/cost, standalone nouns) are included
        # deliberately, not just fixed phrases — a real-message accuracy
        # check against 20 actual production messages (none of them a shop,
        # all a services company: "میتونم قیمت اپلیکیشن رو بدونم؟", "برای
        # سئو چقدر هزینه میگیرید؟", "هزینه طراحی سایت چقدر است؟") showed
        # the original fixed-phrase-only list missed real, more casually
        # worded price questions entirely. Checked after the more specific
        # shipping/order_status patterns above, so "هزینه ارسال" still
        # correctly resolves to shipping, not price.
        "قیمت", "هزینه", "قیمتش", "چنده", "چند تومانه", "چند ریاله", "تخفیف داره", "چقدره",
        "how much", "what's the price", "price of", "cost of", "is it expensive", "any discount",
    )),
    ("technical_support", _P(
        "کار نمیکنه", "کار نمی‌کند", "خرابه", "ایراد داره", "مشکل فنی", "درست نمیشه",
        "not working", "doesn't work", "is broken", "technical issue", "error message", "troubleshoot",
    )),
]


def classify_intent(query: Optional[str]) -> str:
    """Returns one of INTENTS. Never raises — an empty/odd input degrades
    to 'other' rather than an exception interrupting the chat turn this
    runs alongside."""
    if not query or not isinstance(query, str):
        return "other"
    for intent, pattern in _PATTERNS:
        if pattern.search(query):
            return intent
    return "other"
