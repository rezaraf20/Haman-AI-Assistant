"""
How the assistant is told to describe what a tool actually did.

Three of the tools do not do the thing their names suggest. add_to_cart
prepares a button, create_payment_link returns a price preview with no link in
it, and get_order_status sends nothing and looks nothing up -- each produces
something the customer has to act on before anything happens. Left to its own
words the model reports them as done, and "it has been added to your cart" is
false even on the turn the tool succeeded.

claim_guard catches that after the fact by deleting the sentence. Deleting is
a poor answer when the tool did work: the customer is told what did not happen
instead of what did. So the answering turn is handed the true sentence up
front, and the guard stays as the second line for everything that slips past.

The wording lives here rather than inline so it can be corrected without
touching the pipeline, and so both languages stay side by side where a missing
one is obvious.
"""
from typing import Optional

# tool name -> {locale: the sentence the assistant should use}
#
# Each says plainly that something is ready for the customer to act on, and
# none of them claims the action is finished.
_PHRASES = {
    "add_to_cart": {
        "fa": "دکمه‌ی افزودن به سبد را برایتان آماده کردم، روی آن بزنید.",
        "en": "I've prepared an add-to-cart button for you — tap it to add the item.",
    },
    "create_payment_link": {
        "fa": "خلاصه‌ی سفارش و مبلغ را آماده کردم؛ با تأیید شما لینک پرداخت ساخته می‌شود.",
        "en": "I've prepared your order summary and total; the payment link will be created once you confirm.",
    },
    "get_order_status": {
        "fa": "کد تأیید را برایتان می‌فرستم.",
        "en": "I'll send you a verification code.",
    },
}

# The tools whose results need this treatment at all. build_cart_url really
# does return a usable link, and the read-only tools return facts to describe
# rather than an action to report.
PHRASED_TOOLS = frozenset(_PHRASES)


def tool_result_phrase(tool_name: str, is_fa: bool = True) -> Optional[str]:
    """The sentence for a tool that succeeded, or None if it needs no wording."""
    phrases = _PHRASES.get(tool_name)

    return phrases["fa" if is_fa else "en"] if phrases else None
