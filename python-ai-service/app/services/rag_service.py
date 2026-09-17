import time
import json
import logging
import re
import requests as _requests
from typing import List, Optional, AsyncGenerator, Tuple
from sqlalchemy.orm import Session
from sqlalchemy import text
from app.core.config import settings
from app.services import llm_provider_service
from app.services.intent_classifier import classify_intent
from app.services.answer_format import strip_source_labels
from app.services.claim_guard import verify_claims
from app.services.tool_router import is_tool_candidate

logger = logging.getLogger(__name__)

GEMINI_V1_EMBED_URL = "https://generativelanguage.googleapis.com/v1/models/gemini-embedding-001:embedContent"

GROUNDING_RULES = (
    "You are a helpful AI assistant. Answer using facts explicitly stated in the "
    "provided context below. Never invent, estimate, or guess specific facts — prices, "
    "phone numbers, emails, dates, or figures of any kind — that are not explicitly "
    "present in the context, even if the user asks directly and even if a plausible "
    "number would be helpful. If a specific detail (e.g. an exact price) isn't in the "
    "context, don't give a blanket refusal — stay helpful: share whatever related "
    "information the context does contain, then note plainly that this specific detail "
    "isn't listed and suggest the user contact the business directly for it. Do not "
    "present estimates or made-up figures as if they were real.\n\n"
    "You are part of this business's own team, not an outside assistant describing "
    "it from afar. Always speak as 'we'/'our' ('we offer...', 'our team can...', "
    "'we're based in...') — never refer to the business by name in the third person "
    "as if it were some other company you're telling the user about (wrong: 'Company "
    "X can help you with...' or 'working with Company X is the best option'; right: "
    "'we can help you with...'). Never recommend, compare against, or promote any "
    "other company, product, or tool as an alternative — even one mentioned in the "
    "context purely as background/comparison information. If the context happens to "
    "mention a competing option, do not repeat or suggest it; redirect the "
    "conversation back to what this business itself offers.\n\n"
    "If the business's exact name isn't given to you (either below or in the context), "
    "never invent or guess one — say you don't know its exact name rather than naming "
    "any company, including anything that merely looks like a name in the context "
    "(a theme, template, or internal system label is not the business's name).\n\n"
    "The '[Source N]' labels on the context are for you, not for the customer. "
    "Never copy one into your reply, in any form — not '[Source 1]', not "
    "'[Source 1, Source 4]', not 'source 1'. The customer cannot see the "
    "numbered list they refer to, so a label tells them nothing.\n\n"
    "When a source is marked with a page number (e.g. '[Source 2] Datasheet.pdf "
    "(page 4)'), this is a technical document — for questions answered from it, name "
    "the source file and page number in your answer (e.g. 'according to Datasheet.pdf, "
    "page 4, ...'). Write it that way, in words: the file name and page are useful to "
    "a customer, the bracketed label is not.\n\n"
    "Never invent specific capabilities, integrations, or supported third-party "
    "platforms/tools/software (e.g. claiming compatibility with a named e-commerce "
    "platform, CRM, or app) unless that exact name appears in the context — a generic "
    "statement like 'works with online stores' does not license naming specific "
    "platforms yourself. This includes never naming a specific AI model or vendor "
    "(e.g. GPT-4, Claude, Gemini) as powering a product being described, and never "
    "naming a specific e-commerce/CMS platform (e.g. Shopify, Magento, WooCommerce, "
    "WordPress) as something a product integrates with — unless that literal name is "
    "written in the context. If asked what a product integrates with or what "
    "technology it uses and the context doesn't say, answer honestly that this isn't "
    "listed rather than naming plausible-sounding real products. Specifically: when "
    "asked what platforms/software something 'integrates with', 'works with', 'runs "
    "on', or 'connects to' and the context does not explicitly name specific products "
    "for that, do not produce a list of platform/software names at all — not even "
    "well-known ones offered as plausible examples. State only what the context "
    "actually says, then say the specific integration list isn't available here and "
    "suggest contacting the team directly for it. The same applies to "
    "every statistic, percentage, or "
    "performance claim ('increases conversions by X%', 'X times more sales', 'reduces "
    "support tickets by X%'): state a number only if it appears verbatim in the "
    "context for the exact thing being asked about — never round, combine, rephrase, "
    "or reuse a real number from a different topic in the context to answer a "
    "question it wasn't actually about, and never state a number you're not certain "
    "is in the context at all."
)
DEFAULT_SYSTEM = GROUNDING_RULES

# Persian translation of GROUNDING_RULES — a real incident showed a small
# model (llama-3.1-8b-instant) following these multi-clause negative
# instructions noticeably worse when the rules are in English but the whole
# conversation is in Persian. Chosen by the incoming *question*'s script,
# not the chatbot's configured response_language (which can be "auto").
GROUNDING_RULES_FA = (
    "شما دستیار هوش مصنوعی این کسب‌وکار هستید. فقط بر اساس اطلاعاتی که صراحتاً در زمینه (context) "
    "زیر آمده پاسخ بده. هرگز هیچ واقعیت خاصی — قیمت، شماره تلفن، ایمیل، تاریخ یا هر رقمی — را که در "
    "زمینه نیامده حدس نزن یا از خودت نساز، حتی اگر کاربر مستقیم بپرسد و حتی اگر یک عدد قابل‌قبول به "
    "نظر برسد. اگر جزئیات خاصی (مثلاً یک قیمت دقیق) در زمینه نیست، پاسخ رد نکن — همچنان کمک‌کننده "
    "باش: هر اطلاعات مرتبطی که در زمینه هست را بگو، سپس صراحتاً بگو این جزئیات خاص فهرست نشده و "
    "پیشنهاد بده کاربر مستقیم با کسب‌وکار تماس بگیرد. هرگز تخمین یا عدد ساختگی را به‌عنوان واقعیت "
    "ارائه نده.\n\n"
    "تو بخشی از تیم خود این کسب‌وکار هستی، نه یک دستیار بیرونی که درباره‌ی آن از دور توضیح می‌دهد. "
    "همیشه با ضمیر «ما» صحبت کن («ما ارائه می‌دهیم...»، «تیم ما می‌تواند...»، «ما مستقر هستیم در...») "
    "— هرگز از نام کسب‌وکار به‌صورت سوم‌شخص یاد نکن، انگار شرکت دیگری است که داری درباره‌اش توضیح "
    "می‌دهی (غلط: «شرکت X می‌تواند به شما کمک کند»؛ درست: «ما می‌توانیم به شما کمک کنیم»). هرگز هیچ "
    "شرکت، محصول یا ابزار دیگری را به‌عنوان جایگزین توصیه، مقایسه یا تبلیغ نکن — حتی اگر در زمینه "
    "صرفاً به‌عنوان اطلاعات پس‌زمینه به آن اشاره شده باشد. اگر زمینه به یک گزینه‌ی رقیب یا هر نام "
    "دیگری غیر از نام خود کسب‌وکار اشاره کرد، آن را تکرار یا پیشنهاد نکن؛ گفتگو را به سمت چیزی که "
    "خود این کسب‌وکار ارائه می‌دهد برگردان.\n\n"
    "اگر نام دقیق این کسب‌وکار در ادامه یا در زمینه مشخص نشده، هرگز نام هیچ شرکتی را نساز — حتی اگر "
    "چیزی در زمینه شبیه یک اسم به نظر برسد (نام یک قالب، افزونه یا برچسب داخلی سیستم، نام این "
    "کسب‌وکار نیست) — به‌جای آن صادقانه بگو نام دقیق آن را نمی‌دانی.\n\n"
    "برچسب‌های «[Source N]» روی زمینه برای تو هستند، نه برای مشتری. هرگز هیچ‌کدام را در پاسخت "
    "کپی نکن — نه «[Source 1]»، نه «[Source 1, Source 4]»، نه «منبع ۱». مشتری آن فهرست "
    "شماره‌دار را نمی‌بیند، پس این برچسب برای او هیچ معنایی ندارد.\n\n"
    "اگر یک منبع شماره صفحه داشته باشد (مثلاً «[Source 2] Datasheet.pdf (page 4)»)، یعنی یک سند "
    "فنی است — برای سوالاتی که از آن پاسخ داده می‌شود، نام فایل و شماره صفحه را با کلمات در پاسخت "
    "بیاور (مثلاً «طبق Datasheet.pdf، صفحه ۴، ...»). نام فایل و صفحه برای مشتری مفید است؛ "
    "برچسب داخل کروشه نه.\n\n"
    "هرگز قابلیت، یکپارچه‌سازی یا پلتفرم/نرم‌افزار شخص ثالثی (مثلاً ادعای سازگاری با یک پلتفرم "
    "فروشگاهی، CRM یا اپلیکیشن خاص با نام) را از خودت نساز، مگر این‌که همان نام دقیقاً در زمینه آمده "
    "باشد — جمله‌ی کلی‌ای مثل «با فروشگاه‌های آنلاین کار می‌کند» اجازه نمی‌دهد خودت اسم پلتفرم‌های "
    "خاص را بسازی. این شامل نام بردن از یک مدل یا شرکت هوش مصنوعی خاص (مثلاً GPT-4، Claude، Gemini) "
    "به‌عنوان موتور یک محصول، و نام بردن از یک پلتفرم فروشگاهی یا CMS خاص (مثلاً Shopify، Magento، "
    "WooCommerce، وردپرس) به‌عنوان چیزی که محصول با آن یکپارچه می‌شود هم می‌شود — مگر این‌که همان نام "
    "دقیقاً در زمینه نوشته شده باشد. اگر پرسیده شد یک محصول با چه چیزی یکپارچه می‌شود یا از چه فناوری‌ای "
    "استفاده می‌کند و زمینه چیزی نگفته، صادقانه بگو این اطلاعات فهرست نشده، نه این‌که نام محصولات واقعی "
    "و قابل‌قبول را از خودت بسازی. به‌طور مشخص: وقتی پرسیده شد یک چیز با چه پلتفرم/نرم‌افزارهایی «یکپارچه "
    "می‌شود»، «کار می‌کند» یا «متصل می‌شود» و زمینه اسم مشخصی برای آن نیاورده، اصلاً هیچ فهرستی از اسم "
    "پلتفرم/نرم‌افزار نساز — حتی اسم‌های شناخته‌شده به‌عنوان مثال احتمالی. فقط همان چیزی را بگو که در "
    "زمینه واقعاً آمده، سپس بگو فهرست دقیق یکپارچه‌سازی‌ها اینجا موجود نیست و پیشنهاد بده مستقیم با تیم "
    "تماس بگیرد. همین قانون برای هر آمار، درصد یا ادعای عملکردی («نرخ تبدیل را X درصد افزایش "
    "می‌دهد»، «X برابر فروش بیشتر»، «تیکت‌های پشتیبانی را X درصد کاهش می‌دهد») هم صدق می‌کند: فقط "
    "عددی را بگو که دقیقاً همان‌طور در زمینه، برای همان موضوعی که سوال شده، آمده — هرگز عددی از یک "
    "موضوع دیگر در زمینه را گرد نکن، ترکیب نکن، بازنویسی نکن یا برای پاسخ به سوالی که واقعاً درباره‌ی "
    "آن نبوده استفاده نکن، و هرگز عددی را که مطمئن نیستی اصلاً در زمینه هست بیان نکن."
)

# Used only when: (a) the chatbot has no custom fallback_response configured
# (chatbot.fallback_response is Laravel's first choice — see ChatService::
# sendMessage()'s own catch blocks), AND (b) every LLM provider call failed
# (embedding failure, or every active llm_provider_profiles entry exhausted
# — see _chat_completion()/_chat_completion_stream()). Previously a raw
# hardcoded English string ("Sorry, I could not generate a response.") was
# returned here even for a Persian conversation — since this whole response
# still comes back as a normal 200 from Python, Laravel's own bilingual
# ChatService catch-block fallback (WidgetDefaults) never even fires; this
# was the only place that raw English could actually reach a real visitor.
# Chosen by the query's own script, matching every other bilingual choice in
# this file. Actively invites leaving contact info — the one broadly useful
# thing to ask for when nothing else could be done for this message.
DEFAULT_ERROR_RESPONSE_EN = (
    "Sorry, we couldn't process your message right now. Please leave your "
    "phone number or email and we'll get back to you as soon as possible."
)
DEFAULT_ERROR_RESPONSE_FA = (
    "متأسفیم، در حال حاضر امکان پردازش پیام شما نیست. لطفاً شماره تماس یا ایمیل خود را بگذارید "
    "تا در اسرع وقت با شما تماس بگیریم."
)


def _default_error_response(query: str) -> str:
    return DEFAULT_ERROR_RESPONSE_FA if _looks_persian(query) else DEFAULT_ERROR_RESPONSE_EN


def _looks_persian(text_val: str) -> bool:
    """True if the text contains Persian/Arabic-script characters — used to
    pick the grounding rules' language from the actual incoming question,
    not the chatbot's configured response_language (which is often 'auto')."""
    return any('؀' <= ch <= 'ۿ' or 'ݐ' <= ch <= 'ݿ' for ch in text_val)


def _grounding_rules_for(query: str) -> str:
    return GROUNDING_RULES_FA if _looks_persian(query) else GROUNDING_RULES


def _business_name_rule(business_name: Optional[str], is_fa: bool) -> str:
    if not business_name:
        return ""
    if is_fa:
        return f"\n\nنام این کسب‌وکار «{business_name}» است. هرگز نام دیگری برای آن به کار نبر و هیچ نام شرکت دیگری را ذکر نکن."
    return f"\n\nThis business's name is \"{business_name}\". Never use any other name for it, and never state any other company's name as its own."


# "Is this genuine?" — the single most common customer question in BOTH
# real interviews this app was built from (electronics parts and
# cosmetics). A wrong "yes" here is a real liability the merchant bears,
# not a cosmetic mistake — so this rule is always active (unlike the
# tool-gated pricing rules below), since authenticity data is synced
# straight into the regular retrieved CONTEXT as plain lines (see
# SyncService::syncProducts()' "Authenticity Status:"/"Brand:"/etc. — only
# ever written when the seller's own WooCommerce field mapping actually
# had a value), never fetched via a live tool call.
DEFAULT_AUTHENTICITY_UNKNOWN_EN = (
    "This detail hasn't been recorded by the seller for this product. Please contact us "
    "directly to confirm authenticity, brand, official distributor, or warranty details."
)
DEFAULT_AUTHENTICITY_UNKNOWN_FA = (
    "این جزئیات توسط فروشنده برای این محصول ثبت نشده است. لطفاً برای تأیید اصالت، برند، "
    "نمایندگی رسمی یا گارانتی مستقیماً با ما تماس بگیرید."
)


def _authenticity_rule(authenticity_unknown_message: Optional[str], is_fa: bool) -> str:
    fallback = authenticity_unknown_message or (DEFAULT_AUTHENTICITY_UNKNOWN_FA if is_fa else DEFAULT_AUTHENTICITY_UNKNOWN_EN)
    if is_fa:
        return (
            "\n\nدرباره‌ی اصالت، برند، نمایندگی رسمی، مدت گارانتی یا کشور مبدأ یک محصول، فقط "
            "دقیقاً همان چیزی را بگو که در زمینه‌ی بالا صراحتاً برای همان محصول آمده — هرگز بر "
            "اساس توضیحات محصول، نظرات کاربران، قیمت یا برداشت خودت درباره‌ی اصالت قضاوت نکن. "
            f"اگر این اطلاعات برای همان محصول در زمینه نیامده، دقیقاً همین را بگو: «{fallback}» "
            "— هرگز حدس نزن و هرگز نگو «به‌احتمال زیاد اصل است» یا مشابه آن."
        )
    return (
        "\n\nFor any question about a product's authenticity, brand, official distributor, "
        "warranty period, or country of origin, state only exactly what the CONTEXT above "
        "explicitly says for that specific product — never judge authenticity from the "
        "product's description, reviews, price, or your own impression. If this information "
        f"isn't in the context for that product, say exactly this: \"{fallback}\" — never "
        "guess, and never say it's \"probably genuine\" or similar."
    )


# Names from tools/product_tools.py — a chatbot enabling any of these has
# live pricing/stock capability, so the model must be told never to answer
# a price/stock/variant question from the (possibly stale) retrieved
# CONTEXT above and to use the tool instead. Checked by name rather than
# just "enabled_tools is non-empty" so a future non-pricing tool doesn't
# silently pull in a rule that doesn't apply to it.
_PRICING_TOOL_NAMES = {"get_product_availability", "get_product_variants", "search_products", "recommend_products", "compare_products", "create_payment_link"}

# recommend_products / compare_products (doc-04's "Product compare", Very
# high) results render as actual widget UI — product cards or a comparison
# table (see haman-widget.js's renderProductCards()/renderCompareTable())
# — never as text the model re-describes or re-tables itself.
_PRODUCT_DISPLAY_TOOL_NAMES = {"recommend_products", "compare_products"}


def _product_display_rule(enabled_tools: Optional[List[str]], is_fa: bool) -> str:
    if not enabled_tools or not (_PRODUCT_DISPLAY_TOOL_NAMES & set(enabled_tools)):
        return ""
    if is_fa:
        return (
            "\n\nوقتی از ابزار recommend_products یا compare_products استفاده می‌کنی، کارت‌های محصول یا "
            "جدول مقایسه به‌طور مستقیم در ویجت به کاربر نمایش داده می‌شود — نام، قیمت یا فهرست کامل "
            "ویژگی‌های هر محصول را دوباره در متن خودت تکرار نکن و هرگز جدول متنی خودت را نساز. فقط یک "
            "جمله‌ی کوتاه مقدمه بنویس، و برای recommend_products، برای هر محصول پیشنهادی یک جمله‌ی کوتاه "
            "بنویس که چرا مناسب است — فقط بر اساس همان توضیح کوتاهی (short_description) که ابزار "
            "برگردانده، نه از خودت. هرگز محصولی را که ابزار برنگردانده پیشنهاد نده، و هرگز محصولی از یک "
            "فراخوانی ناموفق یا خالی را طوری بیان نکن که انگار موجود است. در compare_products، اگر "
            "ویژگی‌ای برای یک محصول در نتیجه نبود، صراحتاً بگو برای آن محصول ذکر نشده — هرگز آن را از "
            "روی محصول دیگر یا از خودت حدس نزن."
        )
    return (
        "\n\nWhen you use recommend_products or compare_products, the actual product cards or "
        "comparison table are rendered directly in the chat widget for the customer — do not "
        "re-describe each product's name, price, or full attribute list in your own text, and "
        "never draw your own text table. Just write one short intro sentence, and for "
        "recommend_products, one short sentence per recommended product explaining why it fits — "
        "based only on the short_description the tool actually returned, never invented. Never "
        "recommend a product the tool did not return, and never describe a product from a failed "
        "or empty call as if it were available. For compare_products, if a feature is missing for "
        "one product in the result, say plainly that it isn't listed for that product — never "
        "guess it from the other product or from general knowledge."
    )


_CART_LINK_TOOL_NAMES = {"build_cart_url", "add_to_cart"}


def _cart_link_rule(enabled_tools: Optional[List[str]], is_fa: bool) -> str:
    """build_cart_url/add_to_cart both build something the CUSTOMER must
    click for anything to actually happen — this server never adds
    anything to a cart itself (see product_tools.add_to_cart's docstring:
    the real Store API call only fires in the customer's own browser,
    after a real click). The one failure mode that matters here isn't a
    wrong price or a stale fact, it's the model describing the add as
    already done ("I've added it to your cart") when nothing has happened
    yet — that's simply false until the customer clicks, so this gets its
    own explicit rule rather than folding into _product_display_rule's
    more general "don't re-describe" guidance. add_to_cart also needs its
    own variant-selection clause: unlike build_cart_url (a plain link
    WooCommerce resolves itself), add_to_cart requires a real variation_id
    up front for a variable product, so the model must ask rather than
    guess one."""
    enabled = set(enabled_tools or [])
    if not (_CART_LINK_TOOL_NAMES & enabled):
        return ""
    has_add_to_cart = "add_to_cart" in enabled
    if is_fa:
        rule = (
            "\n\nوقتی از ابزار build_cart_url یا add_to_cart استفاده می‌کنی، دکمه‌های واقعی «افزودن به سبد "
            "خرید» به‌طور مستقیم در ویجت نمایش داده می‌شود — لینک یا دکمه را در متن خودت دوباره توصیف نکن. "
            "مهم‌تر از آن: تا وقتی کاربر خودش روی آن دکمه کلیک نکند، هیچ‌چیز واقعاً به سبد خرید اضافه نشده — "
            "هرگز نگو «اضافه کردم» یا «به سبد شما اضافه شد»؛ به‌جایش بگو چیزی مثل «برای افزودن به سبد خرید "
            "روی دکمه‌ی زیر کلیک کنید»."
        )
        if has_add_to_cart:
            rule += (
                "\n\nبرای add_to_cart، اگر محصول متغیر است (سایز/رنگ/...) و هنوز نمی‌دانی کاربر کدام گزینه "
                "را می‌خواهد، هرگز variation_id را حدس نزن — اول از کاربر بپرس کدام سایز/رنگ را می‌خواهد "
                "(یا از ابزار get_product_variants استفاده کن)، و فقط بعد از آن add_to_cart را صدا بزن."
            )
        return rule
    rule = (
        "\n\nWhen you use build_cart_url or add_to_cart, real 'Add to cart' buttons are rendered "
        "directly in the chat widget — do not re-describe the link/button in your own text. More "
        "importantly: nothing is actually added to the customer's cart until they click that "
        "button themselves — never say you've already added it or that it's now in their cart; say "
        "something like 'click below to add it to your cart' instead."
    )
    if has_add_to_cart:
        rule += (
            "\n\nFor add_to_cart specifically: if the product is variable (size/color/etc.) and you "
            "don't yet know which option the customer wants, never guess a variation_id — ask the "
            "customer which one they want first (or call get_product_variants), and only then call "
            "add_to_cart."
        )
    return rule


_PAYMENT_LINK_TOOL_NAMES = {"create_payment_link"}


def _payment_link_rule(enabled_tools: Optional[List[str]], is_fa: bool) -> str:
    """create_payment_link's tool call only ever returns a PREVIEW (see
    product_tools.create_payment_link's own docstring) — the widget
    renders that preview as the real order summary + total + a "Confirm &
    Pay" button, and a real WooCommerce order is only ever created after
    the customer clicks it (ChatController::createPaymentLink()). Two
    failure modes matter here, both worse than a wrong price: claiming an
    order was CREATED before it was, and claiming it was PAID before the
    customer ever reached the payment gateway."""
    if not enabled_tools or not (_PAYMENT_LINK_TOOL_NAMES & set(enabled_tools)):
        return ""
    if is_fa:
        return (
            "\n\nوقتی از ابزار create_payment_link استفاده می‌کنی، خلاصه‌ی سفارش (اقلام و مبلغ کل واقعی) "
            "به‌طور مستقیم در ویجت نمایش داده می‌شود، همراه با یک دکمه‌ی واقعی «تأیید و پرداخت» — دوباره "
            "اقلام یا مبلغ را در متن خودت فهرست نکن و هرگز مبلغی غیر از همان مبلغ واقعی که ابزار برگردانده "
            "نگو. مهم‌تر از آن: تا وقتی کاربر خودش روی آن دکمه کلیک نکند، هیچ سفارشی ساخته نشده — هرگز نگو "
            "«سفارشتان را ثبت کردم» یا «لینک پرداخت ساختم». و حتی بعد از کلیک کاربر روی دکمه، تا وقتی خودِ "
            "کاربر واقعاً در صفحه‌ی درگاه پرداخت را کامل نکرده، هرگز نگو «پرداخت انجام شد» یا «سفارش شما "
            "پرداخت شد» — فقط بگو لینک پرداخت آماده است."
        )
    return (
        "\n\nWhen you use create_payment_link, the order summary (items and the real total) is "
        "rendered directly in the chat widget, along with a real 'Confirm & Pay' button — do not "
        "list the items or total again in your own text, and never state any total other than the "
        "exact one the tool returned. More importantly: no order exists until the customer clicks "
        "that button themselves — never say 'I've placed your order' or 'I've created a payment "
        "link' as if it's already done. And even after they click it, never say 'your payment went "
        "through' or 'your order is paid' — only that a real payment link is ready; the customer "
        "still has to actually complete payment on the gateway page themselves."
    )


_ORDER_STATUS_TOOL_NAMES = {"get_order_status"}


def _order_status_rule(enabled_tools: Optional[List[str]], is_fa: bool) -> str:
    """get_order_status returns intent only — no code has been sent and no
    order has been looked up when the tool call returns (see
    product_tools.get_order_status). Three things must never happen: the
    model claiming it already found/sent something, the model asking the
    customer to type the verification code into the chat (the widget has
    its own field, and a code pasted into chat is stored in message
    history), and the model inventing an order status it has not been
    given."""
    if not enabled_tools or not (_ORDER_STATUS_TOOL_NAMES & set(enabled_tools)):
        return ""
    if is_fa:
        return (
            "\n\nبرای پیگیری سفارش، اول شماره‌ی موبایلی را که مشتری هنگام ثبت سفارش وارد کرده بپرس، بعد "
            "ابزار get_order_status را صدا بزن. این ابزار هیچ پیامکی نمی‌فرستد و هیچ سفارشی را پیدا نمی‌کند — "
            "فقط یک دکمه‌ی تأیید در ویجت نمایش داده می‌شود. پس هرگز نگو «کد را فرستادم» یا «سفارشتان را پیدا "
            "کردم». کد تأیید فیلد مخصوص خودش را در ویجت دارد: هرگز از مشتری نخواه کد را در چت بنویسد و هرگز "
            "کدی را در پاسخت تکرار نکن. وضعیت سفارش، کد رهگیری و اقلام بعد از تأیید کد مستقیماً در ویجت "
            "نمایش داده می‌شوند — آن‌ها را در متن خودت بازنویسی نکن و هیچ‌وقت وضعیتی را که به تو داده نشده حدس نزن."
        )
    return (
        "\n\nFor order tracking, first ask for the mobile number the customer used on the order, "
        "then call get_order_status. That tool sends nothing and finds nothing — it only renders a "
        "confirm button in the widget. So never say 'I've sent the code' or 'I found your order'. "
        "The verification code has its own field in the widget: never ask the customer to type it "
        "into the chat, and never repeat a code back in your reply. The order status, tracking code "
        "and items are rendered directly in the widget once the code is verified — do not restate "
        "them in your own text, and never guess a status you were not given."
    )


def _live_pricing_rule(enabled_tools: Optional[List[str]], is_fa: bool) -> str:
    """A wrong price is the worst mistake a shop's bot can make — worse
    than no answer. This rule exists specifically because sys_p already
    contains the regular retrieved CONTEXT (which may itself mention an
    old price, e.g. from a synced product description or a datasheet
    example) by the time the tool-calling call runs, so the model must be
    told explicitly which source wins."""
    if not enabled_tools or not (_PRICING_TOOL_NAMES & set(enabled_tools)):
        return ""
    if is_fa:
        return (
            "\n\nقیمت و موجودی محصولات این کسب‌وکار ممکن است در هر لحظه تغییر کند — زمینه‌ی (context) "
            "بالا ممکن است شامل قیمت‌های قدیمی باشد. هرگز قیمت، وضعیت موجودی یا در دسترس بودن را از "
            "زمینه‌ی بالا بیان نکن. برای هر سوال درباره‌ی قیمت، موجودی، در دسترس بودن یا تنوع محصول "
            "(رنگ/سایز)، باید از ابزار زنده‌ی مناسب استفاده کنی و فقط بر اساس نتیجه‌ی همان ابزار پاسخ "
            "بدهی. وقتی از نتیجه‌ی یک ابزار زنده استفاده می‌کنی، صراحتاً به کاربر بگو این اطلاعات زنده و "
            "لحظه‌ای است. اگر ابزار زنده در دسترس نبود یا خطا داد، هرگز عدد قدیمی حدس نزن یا تکرار نکن — "
            "صادقانه بگو در حال حاضر نمی‌توانی قیمت یا موجودی دقیق را تأیید کنی، و اگر ابزار لینک صفحه‌ی "
            "محصول (product_url) داد از همان استفاده کن، وگرنه بگو مستقیم به فروشگاه مراجعه کند."
        )
    return (
        "\n\nThis business's product prices and stock levels can change at any time — the "
        "CONTEXT above may contain outdated prices, if any. Never state a price, stock "
        "status, or availability from the CONTEXT above. For any question about price, "
        "stock, availability, or product variants (color/size), you must call the "
        "appropriate live tool and answer using only its result. When you answer using a "
        "live tool's result, explicitly tell the user this is live/current data. If the "
        "live tool is unavailable or fails, never guess or reuse an old number — say you "
        "can't confirm the exact price or availability right now, and if the tool gave a "
        "product_url, share that link; otherwise tell them to check the shop directly."
    )


_CATALOGUE_LOOKUP_TOOL_NAMES = {"search_products"}


def _catalogue_lookup_rule(enabled_tools: Optional[List[str]], is_fa: bool) -> str:
    """Stop the CONTEXT from reading as the whole catalogue.

    One run of eight real Persian questions against a real synced shop, asking
    only which tool the model decides to call: 5/8 with the CONTEXT block in
    the prompt, 7/8 without it. Both losses were the same mistake -- asked to
    compare two products, and asked to put one in the cart, it looked at the
    CONTEXT, found no product id there, and told the customer the information
    was not recorded, instead of calling search_products, which finds both in
    one call.

    With this rule the same run scores 6/8 and 8/8: the cart question is
    fixed, the comparison one is not, and a prompt carrying the CONTEXT still
    does worse than one without it. Removing the block from this turn is the
    bigger lever and remains open, but it costs an extra model call on every
    message that ends up needing no tool, which this does not.

    Single samples per cell, not an average -- treat the direction as the
    finding, not the exact fractions.
    """
    if not enabled_tools or not (_CATALOGUE_LOOKUP_TOOL_NAMES & set(enabled_tools)):
        return ""
    if is_fa:
        return (
            "\n\nزمینه‌ی (context) بالا فقط چند قطعه‌ی بازیابی‌شده است، نه فهرست کامل محصولات، و "
            "هیچ‌وقت شناسه‌ی محصول (product ID) در آن نیست. اگر محصولی در زمینه‌ی بالا نبود، یعنی "
            "فقط بازیابی نشده؛ این به معنی نداشتن آن محصول نیست. هر وقت برای کاری (مقایسه، افزودن به سبد، "
            "قیمت، موجودی، لینک پرداخت) به شناسه‌ی محصول نیاز داری، اول ابزار جست‌وجو را صدا بزن و "
            "شناسه را از نتیجه‌ی آن بردار. هرگز به مشتری نگو «این اطلاعات ثبت نشده» یا «در اطلاعات "
            "موجود نیست» وقتی یک ابزار می‌تواند همان را پیدا کند، و هرگز شناسه‌ای را از مشتری نپرس "
            "وقتی خودت می‌توانی جست‌وجویش کنی."
        )
    return (
        "\n\nThe CONTEXT above is a handful of retrieved passages, not the product "
        "catalogue, and it never contains product IDs. A product missing from the "
        "CONTEXT was simply not retrieved; it does not mean the shop has no such "
        "product. Whenever you need a product ID for anything - comparing, adding "
        "to a cart, price, stock, a payment link - call the search tool first and "
        "take the ID from its result. Never tell the customer that information is "
        "not recorded or not available when a tool can find it, and never ask the "
        "customer for an ID you can look up yourself."
    )


def _grounding_reminder(is_fa: bool) -> str:
    """A short, final restatement of the highest-risk rules, placed right
    before the user's question — the repetition closest to the generated
    text has more influence than the same rule stated once at the top of a
    long system prompt, which is otherwise easy for a small model to lose
    track of by the time it reaches the actual question."""
    if is_fa:
        return (
            "یادآوری: فقط بر اساس زمینه‌ی بالا پاسخ بده. هیچ نام شرکتی (چه رقیب، چه هر نام دیگری) "
            "را که در بالا صراحتاً به‌عنوان نام این کسب‌وکار داده نشده، نساز یا به‌کار نبر — اگر نام "
            "دقیق را نمی‌دانی، صادقانه بگو نمی‌دانی. همچنین هیچ نام پلتفرم/نرم‌افزار شخص ثالث (مثل "
            "Shopify، Magento، GPT-4، Claude) و هیچ عدد یا درصدی که دقیقاً در زمینه، برای همین سوال، "
            "نیامده نساز — اگر زمینه چیزی نگفته، بگو این اطلاعات فهرست نشده."
        )
    return (
        "Reminder: answer using only the context above. Never invent or state any "
        "company name (a competitor's or anything else) that wasn't explicitly given "
        "above as this business's own name — if you don't know its exact name, say so. "
        "Also never invent a third-party platform/software name (e.g. Shopify, Magento, "
        "GPT-4, Claude) or any number/percentage that doesn't appear verbatim in the "
        "context for this exact question — if the context doesn't say, say it isn't listed."
    )


# From a real interview at an electronics-parts shop: a customer typing
# their own part number ("do you have this?") is one of their three most
# common questions — and a vector embedding of a bare alphanumeric string
# like "LM358N" carries almost no useful semantic signal, so hybrid
# retrieval alone handles this case poorly. Matched *before* any retrieval
# runs; only alphanumeric-with-both-letters-and-digits tokens qualify (a
# plain word or a pure number is not a plausible part number), 4-20 chars,
# hyphens allowed within the token (stripped at normalization time, not
# here — the raw hyphenated form is still worth keeping as the "candidate"
# shown back to the user on a miss).
SKU_CANDIDATE_PATTERN = re.compile(
    r'\b(?=[A-Za-z0-9-]{4,20}\b)(?=[A-Za-z0-9-]*[A-Za-z])(?=[A-Za-z0-9-]*\d)[A-Za-z0-9-]{4,20}\b'
)


# The pattern above requires a letter, because a bare number is usually a year
# or an order number rather than a part number. That is true of a loose number
# and false of a grouped one: a real customer's catalogue numbers every product
# "440-128", "440-637", and 148 of its 173 products are shaped that way, so the
# fast path never fired for the shop that needed it most.
#
# The separator is what makes it safe. "1407" stays an ordinary number; a digit
# group split by a hyphen or slash is deliberate notation.
SKU_NUMERIC_PATTERN = re.compile(r'\b\d{2,6}(?:[-/]\d{2,6}){1,3}\b')

# A bare digit run is only a part number when the customer says it is --
# "کد 440128 رو دارید؟" is a lookup, "سفارش 1407 من کجاست؟" is not. The
# labelling word has to be there, and it must not be a word that labels
# something else entirely: an order code, a tracking code, a discount code or
# a verification code are all "کد" too, and none of them is a product.
SKU_LABELLED_PATTERN = re.compile(
    r'(?:کد|شناسه|sku|part\s*(?:number|no\.?)?|پارت\s*نامبر)'
    r'\s*(?:محصول|کالا)?\s*:?\s*'
    r'(?<!سفارش)(?<!پیگیری)(?<!تخفیف)(?<!تایید)(?<!تأیید)'
    r'(\d{4,10})\b',
    re.IGNORECASE,
)

_LABEL_EXCLUSIONS = ("سفارش", "پیگیری", "رهگیری", "تخفیف", "تایید", "تأیید", "پستی")

# 2026-09-15 is a date, not a part number.
_ISO_DATE = re.compile(r'^\d{4}[-/]\d{1,2}[-/]\d{1,2}$')

# Longer than any part number, and the length at which phone numbers start:
# 0912-111-2233 is not a lookup.
_MAX_NUMERIC_SKU_DIGITS = 9


def _extract_sku_candidates(query: str) -> List[str]:
    candidates = SKU_CANDIDATE_PATTERN.findall(query)

    for token in SKU_NUMERIC_PATTERN.findall(query):
        if token in candidates or _ISO_DATE.match(token):
            continue
        if sum(c.isdigit() for c in token) > _MAX_NUMERIC_SKU_DIGITS:
            continue
        candidates.append(token)

    for match in SKU_LABELLED_PATTERN.finditer(query):
        token = match.group(1)
        preceding = query[max(0, match.start() - 24):match.start()]
        if token in candidates or any(word in preceding for word in _LABEL_EXCLUSIONS):
            continue
        candidates.append(token)

    return candidates


def _normalize_sku(raw: str) -> str:
    """Must stay in exact lockstep with App\\Support\\SkuNormalizer (PHP) —
    that's what populates products.sku_normalized at sync time. Uppercase,
    strip whitespace/hyphens, nothing fancier: "lm358-n" and "LM358 N" and
    "LM358N" all normalize to the same "LM358N"."""
    return re.sub(r'[\s\-]+', '', raw).upper()


def _sku_row_to_dict(row) -> dict:
    return {
        "id": row.id, "name": row.name, "sku": row.sku,
        "price": row.price, "currency": row.currency,
        "stock_status": row.stock_status, "permalink": row.permalink,
    }


def _strip_unproven_claims(
    db: Session, conversation_id: Optional[str], chatbot_id: str,
    text_out: Optional[str], query: str,
) -> tuple:
    """Drop any claim of a completed action from an answer no tool backed.

    Called on the paths where no tool ran at all, so the proven set is empty
    by construction and any such claim is false.
    """
    cleaned, labels = strip_source_labels(text_out or "")
    if labels:
        logger.info(f"Removed {labels} internal source label(s) from an answer for chatbot {chatbot_id}")

    cleaned, cut = verify_claims(cleaned, (), is_fa=_looks_persian(query))
    if cut:
        logger.warning(f"Unproven claim(s) removed for chatbot {chatbot_id}: {sorted(set(cut))}")
        _log_event(db, conversation_id, chatbot_id, "unproven_claim_removed", {
            "actions": sorted(set(cut)),
        })
    return cleaned, cut


def _lookup_by_sku(db: Session, chatbot_id: str, query: str) -> Optional[dict]:
    """Returns None when the query contains no part-number-like token at
    all (the normal retrieval path should run, no SKU logic involved).
    Otherwise returns a dict with match_type 'exact' | 'fuzzy' | 'none' —
    'none' still means "a candidate token was found but nothing in the
    catalog is even close", which the caller logs (a real demand-gap
    signal — a customer typed a part number this catalog doesn't carry)
    before falling through to normal retrieval, exactly like a real miss.
    """
    candidates = _extract_sku_candidates(query)
    if not candidates:
        return None

    for candidate in candidates:
        normalized = _normalize_sku(candidate)
        # sku_normalized exact match, or the raw candidate showing up
        # anywhere inside attributes' free-form JSON (there's no fixed key
        # for "alternate identifiers" today — the WordPress plugin's
        # product sync doesn't even populate attributes at all currently —
        # so a plain substring search on its text form is the honest,
        # dependency-free way to honor "any identifier in attributes"
        # without inventing a schema convention nothing actually uses yet).
        row = db.execute(text("""
            SELECT id::text, name, sku, price, currency, stock_status, permalink
            FROM products
            WHERE chatbot_id = CAST(:cid AS uuid)
              AND (
                    sku_normalized = :norm
                 OR attributes::text ILIKE '%' || :raw || '%'
              )
            LIMIT 1
        """), {"cid": chatbot_id, "norm": normalized, "raw": candidate}).fetchone()
        if row:
            return {"match_type": "exact", "candidate": candidate, "product": _sku_row_to_dict(row)}

    # No exact hit for any candidate — try a near match (bidirectional
    # substring on the normalized column) before giving up. A confidently
    # wrong suggestion is exactly what this must avoid, hence 'fuzzy' is
    # always presented as an explicit non-match, never silently treated
    # like a real hit (see _sku_fuzzy_response()).
    for candidate in candidates:
        normalized = _normalize_sku(candidate)
        rows = db.execute(text("""
            SELECT id::text, name, sku, price, currency, stock_status, permalink
            FROM products
            WHERE chatbot_id = CAST(:cid AS uuid)
              AND sku_normalized IS NOT NULL
              AND (sku_normalized LIKE '%' || :norm || '%' OR :norm LIKE '%' || sku_normalized || '%')
            LIMIT 5
        """), {"cid": chatbot_id, "norm": normalized}).fetchall()
        if rows:
            return {"match_type": "fuzzy", "candidate": candidate, "products": [_sku_row_to_dict(r) for r in rows]}

    return {"match_type": "none", "candidate": candidates[0]}


def _format_price(product: dict) -> Optional[str]:
    """The price with the shop's own currency, or with none at all.

    A product whose sync did not carry a currency has no currency here, and
    naming one anyway is how an Iranian shop's prices were shown to its
    customers as USD. The number alone is incomplete; the number with the
    wrong unit is wrong.
    """
    if product.get("price") is None:
        return None
    currency = (product.get("currency") or "").strip()
    amount = f"{product['price']:,.0f}"
    return f"{amount} {currency}" if currency else amount


def _sku_exact_response(product: dict, is_fa: bool) -> str:
    price = _format_price(product)
    if is_fa:
        parts = [f"بله، این محصول را داریم: {product['name']} (کد: {product['sku']})."]
        if price: parts.append(f"قیمت: {price}.")
        parts.append("موجود است." if product.get('stock_status') == 'instock' else "در حال حاضر موجود نیست.")
        if product.get('permalink'): parts.append(product['permalink'])
        return " ".join(parts)
    parts = [f"Yes, we have this product: {product['name']} (SKU: {product['sku']})."]
    if price: parts.append(f"Price: {price}.")
    parts.append("In stock." if product.get('stock_status') == 'instock' else "Currently out of stock.")
    if product.get('permalink'): parts.append(product['permalink'])
    return " ".join(parts)


def _sku_fuzzy_response(products: List[dict], candidate: str, is_fa: bool) -> str:
    names = [f"{p['name']} ({p['sku']})" for p in products[:3]]
    if is_fa:
        return (
            f"دقیقاً کد «{candidate}» را در فهرست نداریم، اما این موارد ممکن است مدنظرتان باشد: "
            + "، ".join(names)
            + ". (توجه: این یک تطبیق دقیق نیست، لطفاً کد را با همکاران ما تأیید کنید.)"
        )
    return (
        f"We don't have an exact match for \"{candidate}\", but these might be what you're looking for: "
        + ", ".join(names)
        + ". (Note: this is not an exact match — please confirm the part number with our team.)"
    )


def _try_sku_shortcut(db: Session, chatbot_id: str, conversation_id: Optional[str], query: str, start: float) -> Optional[dict]:
    """Returns a fully-formed run_rag_pipeline()-shaped result dict when the
    query contains a part-number-like token AND the catalog has an exact or
    fuzzy match for it — the caller returns/yields this directly, skipping
    embedding + hybrid_retrieve + the LLM call entirely (cheaper and more
    reliable than asking an LLM to reason about an opaque alphanumeric
    string). Returns None when there's nothing to short-circuit on — either
    no part-number-like token at all, or one was found but the catalog has
    truly nothing close (that case still gets logged, then falls through to
    normal retrieval, since a fuzzy/none SKU miss doesn't mean the question
    itself is unanswerable by the normal pipeline)."""
    result = _lookup_by_sku(db, chatbot_id, query)
    if result is None:
        return None

    _log_event(db, conversation_id, chatbot_id, "sku_lookup", {
        "query": query, "candidate": result["candidate"], "match_type": result["match_type"],
    })

    if result["match_type"] == "none":
        return None

    is_fa_question = _looks_persian(query)
    if result["match_type"] == "exact":
        product = result["product"]
        answer = _sku_exact_response(product, is_fa_question)
        is_unanswered = False
        sources = [{"title": product["name"], "url": product["permalink"], "type": "product"}] if product.get("permalink") else []
    else:
        products = result["products"]
        answer = _sku_fuzzy_response(products, result["candidate"], is_fa_question)
        # An explicit "not exact" hedge is still a real gap for the demand-
        # gap dashboard and lead-capture (if enabled) to pick up on — the
        # bot didn't confidently answer what was actually asked.
        is_unanswered = True
        sources = [{"title": p["name"], "url": p["permalink"], "type": "product"} for p in products[:3] if p.get("permalink")]

    latency_ms = int((time.time() - start) * 1000)
    _log_event(db, conversation_id, chatbot_id, "response", {
        "model": "sku-lookup", "prompt_tokens": 0, "completion_tokens": 0, "cost_toman": 0, "citations": sources,
    }, latency_ms=latency_ms)
    if is_unanswered:
        _log_event(db, conversation_id, chatbot_id, "unanswered", {
            "query": query, "best_score": None, "reason": "sku_fuzzy_only",
        })

    return {
        "response": answer, "chunk_ids": [], "scores": [], "sources": sources,
        "prompt_tokens": 0, "completion_tokens": 0, "total_tokens": 0, "cost_toman": 0,
        "model": "sku-lookup", "latency_ms": latency_ms,
        "is_fallback": is_unanswered, "is_unanswered": is_unanswered,
        "finish_reason": f"sku_{result['match_type']}",
    }


def retrieve_chunks(db: Session, chatbot_id: str, query_embedding: List[float], top_k: int = 8, threshold: float = 0.60) -> List[dict]:
    try:
        emb_str = "[" + ",".join(str(x) for x in query_embedding) + "]"
        rows = db.execute(text("""
            SELECT id::text, content, metadata,
                   1 - (embedding <=> CAST(:emb AS vector)) AS similarity
            FROM chunks
            WHERE chatbot_id = CAST(:cid AS uuid)
              AND embedding IS NOT NULL
              AND 1 - (embedding <=> CAST(:emb AS vector)) > :threshold
            ORDER BY embedding <=> CAST(:emb AS vector)
            LIMIT :top_k
        """), {"emb": emb_str, "cid": chatbot_id, "threshold": threshold, "top_k": top_k}).fetchall()
        return [{"id": str(r[0]), "content": r[1], "metadata": r[2] or {}, "similarity": float(r[3])} for r in rows]
    except Exception as e:
        # A failed statement leaves Postgres's transaction aborted — every
        # later query on this same session would fail too ("current
        # transaction is aborted") until rolled back. Each FastAPI request
        # gets its own fresh session (get_db()) so this was invisible there,
        # but a long-lived session reusing one connection across many calls
        # (scripts/eval_retrieval.py) would cascade-fail from a single
        # transient error onward. Real bug, not just a script-side one.
        db.rollback()
        logger.error(f"Vector search error: {e}")
        return []


RRF_K = 60
RRF_CANDIDATE_POOL = 40   # per leg, before fusion
RRF_FUSED_LIMIT = 20      # candidates handed to rerank (or used directly)
RERANK_TOP_N = 5          # what actually reaches the LLM's context when reranking


def _vector_search(db: Session, chatbot_id: str, query_embedding: List[float], limit: int = RRF_CANDIDATE_POOL) -> List[dict]:
    """Unlike retrieve_chunks() (used by /search/semantic, unrelated to chat),
    this applies no similarity threshold — RRF needs a full ranked candidate
    pool from each leg to fuse, not a pre-filtered one. Thresholding happens
    once, after fusion (and rerank, if enabled) — see hybrid_retrieve()."""
    try:
        emb_str = "[" + ",".join(str(x) for x in query_embedding) + "]"
        rows = db.execute(text("""
            SELECT id::text, content, metadata,
                   1 - (embedding <=> CAST(:emb AS vector)) AS similarity
            FROM chunks
            WHERE chatbot_id = CAST(:cid AS uuid) AND embedding IS NOT NULL
            ORDER BY embedding <=> CAST(:emb AS vector)
            LIMIT :limit
        """), {"emb": emb_str, "cid": chatbot_id, "limit": limit}).fetchall()
        return [{"id": str(r[0]), "content": r[1], "metadata": r[2] or {}, "similarity": float(r[3])} for r in rows]
    except Exception as e:
        db.rollback()
        logger.error(f"Vector search error: {e}")
        return []


def _fulltext_search(db: Session, chatbot_id: str, query: str, language: str, limit: int = RRF_CANDIDATE_POOL) -> List[dict]:
    """content_tsv is populated at embed time (embed.py) using the source
    document's own language — 'simple' for fa (Postgres has no Persian
    stemmer), 'english' otherwise. The query side uses the chatbot's
    language/response_language the same way, since that's the best signal
    available for what language an incoming message is likely in."""
    config = 'simple' if language == 'fa' else 'english'
    try:
        rows = db.execute(text("""
            SELECT id::text, content, metadata,
                   ts_rank(content_tsv, plainto_tsquery(CAST(:cfg AS regconfig), :q)) AS ft_rank
            FROM chunks
            WHERE chatbot_id = CAST(:cid AS uuid)
              AND content_tsv IS NOT NULL
              AND content_tsv @@ plainto_tsquery(CAST(:cfg AS regconfig), :q)
            ORDER BY ft_rank DESC
            LIMIT :limit
        """), {"cfg": config, "q": query, "cid": chatbot_id, "limit": limit}).fetchall()
        return [{"id": str(r[0]), "content": r[1], "metadata": r[2] or {}, "ft_rank": float(r[3])} for r in rows]
    except Exception as e:
        db.rollback()
        logger.error(f"Full-text search error: {e}")
        return []


def _reciprocal_rank_fusion(vector_rows: List[dict], fulltext_rows: List[dict], k: int = RRF_K, limit: int = RRF_FUSED_LIMIT) -> List[dict]:
    """RRF: score(doc) = sum over each ranked list it appears in of
    1/(k + rank), rank 1-indexed. Simple, no per-source weight tuning needed
    (that's the whole appeal over hand-picked vector/BM25 blend weights), and
    naturally rewards a document both legs agree on over one only one leg
    ranks highly."""
    scores: dict[str, float] = {}
    docs: dict[str, dict] = {}
    for rank, r in enumerate(vector_rows, start=1):
        scores[r["id"]] = scores.get(r["id"], 0.0) + 1.0 / (k + rank)
        docs[r["id"]] = r
    for rank, r in enumerate(fulltext_rows, start=1):
        scores[r["id"]] = scores.get(r["id"], 0.0) + 1.0 / (k + rank)
        docs.setdefault(r["id"], r)

    ordered_ids = sorted(scores.keys(), key=lambda i: scores[i], reverse=True)[:limit]
    fused = []
    for doc_id in ordered_ids:
        d = dict(docs[doc_id])
        d["rrf_score"] = scores[doc_id]
        d.setdefault("similarity", 0.0)
        fused.append(d)
    return fused


RERANK_SYSTEM_PROMPT = (
    "You are a relevance-scoring system, not a chat assistant. You will be given a "
    "user question and a numbered list of candidate passages. Score each passage's "
    "relevance to answering the question, from 0.0 (irrelevant) to 1.0 (directly "
    "answers it). Respond with ONLY a JSON array, one entry per passage, in the exact "
    "form [{\"index\": 0, \"score\": 0.9}, {\"index\": 1, \"score\": 0.2}, ...]. No "
    "other text, no markdown code fences."
)


def _parse_rerank_scores(raw: str, n: int) -> dict[int, float]:
    try:
        start = raw.index("[")
        end = raw.rindex("]") + 1
        parsed = json.loads(raw[start:end])
        return {
            int(item["index"]): max(0.0, min(1.0, float(item["score"])))
            for item in parsed
            if isinstance(item, dict) and "index" in item and "score" in item and 0 <= int(item["index"]) < n
        }
    except Exception:
        return {}


def _rerank_llm(db: Session, query: str, candidates: List[dict], top_n: int = RERANK_TOP_N, max_tokens: int = 800) -> Tuple[List[dict], float, dict]:
    """A real cross-encoder model is too heavy to add to this lightweight
    FastAPI service (new ML runtime + weights just for this), so this scores
    relevance via one extra LLM call instead — the same admin-configured
    provider failover chain as the answer call, one combined prompt scoring
    all candidates at once (not N separate calls), so cost/latency stay
    bounded. Its cost is real and gets folded into the message's cost_toman
    by the caller, not hidden.

    On any failure (no active provider, malformed JSON, etc.) this degrades
    gracefully to the RRF order rather than breaking the chat response —
    same philosophy as _chat_completion()'s provider failover.
    """
    if not candidates:
        return [], 0.0, {}

    profiles = llm_provider_service.get_active_profiles(db)
    if not profiles:
        logger.warning("Rerank requested but no active LLM provider profiles — falling back to RRF order")
        for c in candidates:
            c.setdefault("rerank_score", c.get("rrf_score", 0.0))
        return candidates[:top_n], 0.0, {}

    listing = "\n\n".join(f"[{i}] {c['content'][:500]}" for i, c in enumerate(candidates))
    prompt = f"{RERANK_SYSTEM_PROMPT}\n\nQuestion: {query}\n\nPassages:\n{listing}\n\nJSON:"

    # Same priority-ordered failover as _chat_completion() — a rate-limited
    # or down top-priority provider shouldn't silently disable reranking
    # entirely when a lower-priority one is available and healthy.
    for profile in profiles:
        try:
            raw, usage = _openai_compatible_chat(profile, prompt, max_tokens, temperature=0.0)
            cost_toman = _compute_cost_toman(profile, usage)
            scores = _parse_rerank_scores(raw, len(candidates))
            if not scores:
                raise ValueError(f"Rerank response had no parseable scores: {raw[:200]!r}")
            for i, c in enumerate(candidates):
                c["rerank_score"] = scores.get(i, 0.0)
            ranked = sorted(candidates, key=lambda c: c["rerank_score"], reverse=True)
            return ranked[:top_n], cost_toman, usage
        except Exception as e:
            logger.warning(f"Rerank call via '{profile['name']}' failed, trying next provider: {e}")
            continue

    logger.warning("Rerank failed on every active provider — falling back to RRF order")
    for c in candidates:
        c.setdefault("rerank_score", c.get("rrf_score", 0.0))
    return candidates[:top_n], 0.0, {}


def _log_event(
    db: Session, conversation_id: Optional[str], chatbot_id: str, event_type: str,
    payload: Optional[dict] = None, message_id: Optional[str] = None,
    latency_ms: Optional[int] = None,
) -> None:
    """Best-effort, same philosophy as record_outcome() — analytics must
    never break the actual chat response. conversation_id is required
    (conversation_events.conversation_id is NOT NULL) and is only ever
    missing for a caller that predates ChatRequest.conversation_id or
    genuinely has none yet; such an event is simply skipped rather than
    attempted with a null FK. message_id stays None for every event this
    module logs (retrieval/response/unanswered/product_mentioned) — they
    all fire before the assistant Message row exists (that's created back
    in Laravel's ChatService::finish(), after this whole request returns)."""
    if not conversation_id:
        return
    try:
        db.execute(text("""
            INSERT INTO conversation_events (conversation_id, message_id, chatbot_id, event_type, payload, latency_ms)
            VALUES (:conversation_id, :message_id, :chatbot_id, :event_type, :payload, :latency_ms)
        """), {
            "conversation_id": conversation_id,
            "message_id": message_id,
            "chatbot_id": chatbot_id,
            "event_type": event_type,
            "payload": json.dumps(payload) if payload is not None else None,
            "latency_ms": latency_ms,
        })
        db.commit()
    except Exception as e:
        logger.warning(f"Failed to log conversation_event '{event_type}': {e}")
        db.rollback()


def _log_product_mentioned(db: Session, conversation_id: Optional[str], chatbot_id: str, chunks: List[dict]) -> None:
    """Fires when any retrieved chunk backing this answer came from a
    product document (metadata.type == 'product', set by SyncService's
    product upsert). Identifies products by permalink+title rather than a
    formal products.id — chunk metadata carries whatever was passed to
    upsertDoc() at sync time (price/permalink/title/type/...), which does
    not include the products.external_id needed to join back to a real
    product row; resolving that would mean an extra query per response for
    a signal this is already good enough to act on (which products come up
    in conversations at all)."""
    product_chunks = [c for c in chunks if c.get("metadata", {}).get("type") == "product"]
    if not product_chunks:
        return
    _log_event(db, conversation_id, chatbot_id, "product_mentioned", {
        "product_ids": [c.get("metadata", {}).get("permalink") or c["id"] for c in product_chunks],
        "context": [c.get("metadata", {}).get("title") for c in product_chunks if c.get("metadata", {}).get("title")],
    })


def hybrid_retrieve(
    db: Session, chatbot_id: str, conversation_id: Optional[str], query: str, query_embedding: List[float],
    top_k: int, threshold: float, language: str,
    rerank_enabled: bool, rerank_threshold: float,
) -> dict:
    """Vector search + full-text search, fused with Reciprocal Rank Fusion,
    optionally reranked. Returns:
      chunks: the final list to build LLM context from
      is_unanswered: score-based gap signal — checked against rerank_threshold
        on the top *rerank* score when reranking is on, against the plain
        `threshold` on top *raw similarity* when it's off (a rerank score and
        a cosine similarity are not on the same scale, so they can't share
        one threshold — see the caller's config for both).
      rerank_cost_toman / rerank_usage: 0 / {} when reranking is off or the
        rerank call itself failed and fell back.

    Also logs the 'retrieval' event (query, chunk_ids, scores, strategy,
    latency) and, when the result is unanswered, the 'unanswered' event
    (query, best_score, reason) — this is the one place that actually knows
    which chunks were returned and at what score, the exact data an eval
    set for the recall gap needs (see scripts/build_low_confidence_eval_set.py).
    """
    retrieval_start = time.time()
    vec = _vector_search(db, chatbot_id, query_embedding)
    ft = _fulltext_search(db, chatbot_id, query, language)
    fused = _reciprocal_rank_fusion(vec, ft)
    retrieval_latency_ms = int((time.time() - retrieval_start) * 1000)

    if not fused:
        _log_event(db, conversation_id, chatbot_id, "retrieval", {
            "query": query, "chunk_ids": [], "scores": [], "strategy": "hybrid-empty",
        }, latency_ms=retrieval_latency_ms)
        _log_event(db, conversation_id, chatbot_id, "unanswered", {
            "query": query, "best_score": None, "reason": "no_candidates",
        })
        return {"chunks": [], "is_unanswered": True, "rerank_cost_toman": 0.0, "rerank_usage": {}}

    if rerank_enabled:
        reranked, cost_toman, usage = _rerank_llm(db, query, fused, top_n=RERANK_TOP_N)
        best_score = reranked[0]["rerank_score"] if reranked else 0.0
        is_unanswered = best_score < rerank_threshold
        _log_event(db, conversation_id, chatbot_id, "retrieval", {
            "query": query,
            "chunk_ids": [c["id"] for c in reranked],
            "scores": [round(c.get("rerank_score", 0.0), 4) for c in reranked],
            "strategy": "hybrid+rerank",
        }, latency_ms=retrieval_latency_ms)
        if is_unanswered:
            _log_event(db, conversation_id, chatbot_id, "unanswered", {
                "query": query, "best_score": round(best_score, 4), "reason": "below_rerank_threshold",
            })
        return {
            "chunks": reranked,
            "is_unanswered": is_unanswered,
            "rerank_cost_toman": cost_toman,
            "rerank_usage": usage,
        }

    top = fused[:top_k]
    best_similarity = top[0].get("similarity", 0.0) if top else 0.0
    is_unanswered = best_similarity < threshold
    _log_event(db, conversation_id, chatbot_id, "retrieval", {
        "query": query,
        "chunk_ids": [c["id"] for c in top],
        "scores": [round(c.get("similarity", 0.0), 4) for c in top],
        "strategy": "hybrid",
    }, latency_ms=retrieval_latency_ms)
    if is_unanswered:
        _log_event(db, conversation_id, chatbot_id, "unanswered", {
            "query": query, "best_score": round(best_similarity, 4), "reason": "below_threshold",
        })
    return {
        "chunks": top,
        "is_unanswered": is_unanswered,
        "rerank_cost_toman": 0.0,
        "rerank_usage": {},
    }


def _embed_query(query: str) -> List[float]:
    resp = _requests.post(
        f"{GEMINI_V1_EMBED_URL}?key={settings.GEMINI_API_KEY}",
        json={
            "model": "models/gemini-embedding-001",
            "content": {"parts": [{"text": query}]},
            "taskType": "RETRIEVAL_QUERY",
        },
        timeout=30,
    )
    resp.raise_for_status()
    return resp.json()["embedding"]["values"]


def _openai_compatible_chat(profile: dict, prompt: str, max_tokens: int, temperature: float) -> tuple[str, dict]:
    """groq / xai / openai_compatible all share this same request/response shape
    (choices[0].message.content, usage{prompt_tokens,completion_tokens,total_tokens})
    — one function serves all three provider types, parameterized by the DB row."""
    resp = _requests.post(
        f"{profile['base_url'].rstrip('/')}/chat/completions",
        headers={
            "Authorization": f"Bearer {profile['api_key']}",
            "Content-Type": "application/json",
        },
        json={
            "model": profile['model_name'],
            "messages": [{"role": "user", "content": prompt}],
            "max_tokens": profile.get('max_tokens_response') or max_tokens,
            "temperature": temperature,
        },
        timeout=profile.get('timeout_seconds') or 30,
    )
    resp.raise_for_status()
    body = resp.json()
    return body["choices"][0]["message"]["content"], body.get("usage", {})


def _compute_cost_toman(profile: dict, usage: dict) -> float:
    """Admin-entered Toman price per 1M tokens (see LlmProviderProfileResource)
    applied to what this specific call actually used. Both price fields
    default to 0, so an unpriced profile just costs 0 rather than erroring."""
    prompt_tokens = usage.get("prompt_tokens", 0) or 0
    completion_tokens = usage.get("completion_tokens", 0) or 0
    input_price = float(profile.get("input_price_per_1m_toman") or 0)
    output_price = float(profile.get("output_price_per_1m_toman") or 0)
    return (prompt_tokens / 1_000_000 * input_price) + (completion_tokens / 1_000_000 * output_price)


def _chat_completion(db: Session, prompt: str, max_tokens: int, temperature: float) -> tuple[str, str, dict, float]:
    """Try each active provider profile in priority order (admin-configured via
    Filament), falling through to the next on any failure — a rate-limited or
    down provider no longer takes the whole product down.

    Returns (answer, model_label, usage, cost_toman) — usage is the provider's
    OpenAI-compatible {prompt_tokens, completion_tokens, total_tokens} dict,
    empty if it didn't include one; cost_toman is computed from the profile's
    admin-entered per-1M-token prices.
    """
    profiles = llm_provider_service.get_active_profiles(db)
    if not profiles:
        raise RuntimeError("No active LLM provider profiles configured")

    last_error = None
    for profile in profiles:
        # One retry per provider before moving on — Groq in particular has
        # shown transient failures (network blip, brief overload) that
        # succeed a second later; a customer shouldn't get a dead-end "could
        # not generate a response" for something that would've worked on
        # retry. Skipped for 429s specifically: the quota that just got
        # rejected won't have refilled a second later, so retrying just
        # burns time before falling through to the next provider anyway.
        attempts = 2
        for attempt in range(attempts):
            try:
                answer, usage = _openai_compatible_chat(profile, prompt, max_tokens, temperature)
                llm_provider_service.record_outcome(db, profile['name'], success=True)
                cost_toman = _compute_cost_toman(profile, usage)
                return answer, f"{profile['provider']}/{profile['model_name']}", usage, cost_toman
            except Exception as e:
                last_error = e
                status = getattr(getattr(e, 'response', None), 'status_code', None)
                is_last_attempt = attempt == attempts - 1
                if status == 429 or is_last_attempt:
                    logger.warning(f"LLM provider '{profile['name']}' failed, trying next: {e}")
                    # A 429 is transient and expected under load. Counting it
                    # towards auto-disable turned a momentary throttle into a
                    # permanent outage: five rate-limited messages in a row
                    # disabled the only working profile and every request
                    # after that failed instantly until a human re-enabled it.
                    if status != 429:
                        llm_provider_service.record_outcome(db, profile['name'], success=False)
                    break
                logger.warning(f"LLM provider '{profile['name']}' failed (attempt {attempt + 1}/{attempts}), retrying: {e}")
                time.sleep(1)

    raise last_error


def _openai_compatible_chat_stream(profile: dict, prompt: str, max_tokens: int, temperature: float):
    """Same request as _openai_compatible_chat() but with stream:true — yields
    ("delta", text) for each token fragment the provider sends, and exactly
    one trailing ("usage", dict) once the stream ends (empty dict if the
    provider never included one; Groq/xAI only attach it to the final chunk
    when stream_options.include_usage is set, which this always requests)."""
    resp = _requests.post(
        f"{profile['base_url'].rstrip('/')}/chat/completions",
        headers={
            "Authorization": f"Bearer {profile['api_key']}",
            "Content-Type": "application/json",
        },
        json={
            "model": profile['model_name'],
            "messages": [{"role": "user", "content": prompt}],
            "max_tokens": profile.get('max_tokens_response') or max_tokens,
            "temperature": temperature,
            "stream": True,
            "stream_options": {"include_usage": True},
        },
        timeout=profile.get('timeout_seconds') or 30,
        stream=True,
    )
    resp.raise_for_status()
    usage = {}

    def _handle_line(line: str) -> Optional[Tuple[str, str]]:
        """Returns ("done", "") / ("delta", text) / None (nothing to yield)."""
        nonlocal usage
        if not line or not line.startswith("data:"):
            return None
        payload = line[len("data:"):].strip()
        if payload == "[DONE]":
            return ("done", "")
        try:
            chunk = json.loads(payload)
        except ValueError:
            return None
        choices = chunk.get("choices") or []
        if choices:
            delta = (choices[0].get("delta") or {}).get("content")
            if delta:
                return ("delta", delta)
        if chunk.get("usage"):
            usage = chunk["usage"]
        return None

    # iter_lines(decode_unicode=True) decodes each line using resp.encoding,
    # which `requests` derives from the response's Content-Type header per
    # RFC 2616 — falling back to ISO-8859-1 whenever no charset is present,
    # which is exactly what Groq/xAI send for text/event-stream. Every
    # multi-byte UTF-8 character (any non-ASCII text, e.g. Persian) then gets
    # decoded one byte at a time as Latin-1 and turns into mojibake. The
    # non-streaming path never hit this because it uses resp.json(), which
    # decodes the body from raw bytes correctly regardless of resp.encoding.
    #
    # Fix: read raw bytes, split on the line boundary ourselves, and decode
    # each complete line explicitly as UTF-8 — never handing decoding to
    # `requests`' guessed encoding. iter_content(chunk_size=None) also avoids
    # iter_lines' own byte-buffering, which could otherwise split a
    # multi-byte UTF-8 character across two chunks even with the right
    # encoding.
    buf = b""
    for raw_chunk in resp.iter_content(chunk_size=None):
        if not raw_chunk:
            continue
        buf += raw_chunk
        while b"\n" in buf:
            line_bytes, buf = buf.split(b"\n", 1)
            line = line_bytes.decode("utf-8", errors="replace").rstrip("\r")
            result = _handle_line(line)
            if result is None:
                continue
            kind, text_val = result
            if kind == "done":
                yield ("usage", usage)
                return
            yield ("delta", text_val)
    if buf:
        line = buf.decode("utf-8", errors="replace").rstrip("\r")
        result = _handle_line(line)
        if result is not None and result[0] == "delta":
            yield ("delta", result[1])
    yield ("usage", usage)


def _chat_completion_stream(db: Session, prompt: str, max_tokens: int, temperature: float):
    """Streaming counterpart to _chat_completion() — same priority-ordered
    provider list, but failover only works *before* any text has actually
    reached the caller for a given provider. Once a provider's stream has
    started yielding real content, a failure ends the generator with an
    exception instead of silently switching providers mid-answer (which
    would splice together text from two different models into one reply).

    Yields ("delta", text) fragments, then exactly one
    ("meta", {"model", "usage", "cost_toman"}) event on success.
    """
    profiles = llm_provider_service.get_active_profiles(db)
    if not profiles:
        raise RuntimeError("No active LLM provider profiles configured")

    last_error = None
    for profile in profiles:
        started = False
        try:
            usage = {}
            for kind, payload in _openai_compatible_chat_stream(profile, prompt, max_tokens, temperature):
                if kind == "delta":
                    started = True
                    yield ("delta", payload)
                elif kind == "usage":
                    usage = payload
            llm_provider_service.record_outcome(db, profile['name'], success=True)
            cost_toman = _compute_cost_toman(profile, usage)
            yield ("meta", {
                "model": f"{profile['provider']}/{profile['model_name']}",
                "usage": usage,
                "cost_toman": cost_toman,
            })
            return
        except Exception as e:
            last_error = e
            llm_provider_service.record_outcome(db, profile['name'], success=False)
            if started:
                raise
            logger.warning(f"LLM provider '{profile['name']}' failed before streaming any content, trying next: {e}")
            continue

    raise last_error


async def run_rag_pipeline_stream(
    db: Session, chatbot_id: str, query: str, history: List[dict],
    system_prompt: Optional[str], fallback_resp: Optional[str], llm_model: str,
    top_k: int, threshold: float, temperature: float, max_tokens: int, language: str,
    rerank_enabled: bool = False, rerank_threshold: float = 0.500,
    business_name: Optional[str] = None, conversation_id: Optional[str] = None,
    enabled_tools: Optional[List[str]] = None,
    authenticity_unknown_message: Optional[str] = None,
) -> AsyncGenerator[Tuple[str, object], None]:
    """Streaming counterpart to run_rag_pipeline() — identical retrieval and
    prompt-building, but yields ("delta", str) as the answer is generated
    instead of returning one finished dict. Always ends with exactly one
    ("done", dict) carrying the same fields run_rag_pipeline()'s return value
    has (with "response" holding the full accumulated text), so callers that
    only care about the final persisted record can treat it the same way.
    """
    start = time.time()

    # Zero-cost (no LLM call) — see intent_classifier.py. Logged
    # unconditionally, before any early-return branch below, so every real
    # user message gets an intent regardless of how the turn is eventually
    # answered (SKU shortcut, embedding failure, fallback, tool call, or a
    # normal completion) — a message's intent is a property of what the
    # customer asked, not of how well the pipeline could answer it.
    intent = classify_intent(query)
    _log_event(db, conversation_id, chatbot_id, "intent_classified", {"intent": intent})

    sku_result = _try_sku_shortcut(db, chatbot_id, conversation_id, query, start)
    if sku_result is not None:
        yield ("delta", sku_result["response"])
        yield ("done", sku_result)
        return

    try:
        query_embedding = _embed_query(query)
    except Exception as e:
        logger.error(f"Query embedding error: {e}")
        text_out = fallback_resp or _default_error_response(query)
        yield ("delta", text_out)
        yield ("done", {
            "response": text_out, "chunk_ids": [], "scores": [], "sources": [],
            "prompt_tokens": 0, "completion_tokens": 0, "total_tokens": 0, "cost_toman": 0,
            "model": "n/a", "latency_ms": int((time.time() - start) * 1000),
            "is_fallback": True, "is_unanswered": False, "finish_reason": "error",
        })
        return

    retrieval = hybrid_retrieve(db, chatbot_id, conversation_id, query, query_embedding, top_k, threshold, language, rerank_enabled, rerank_threshold)
    chunks = retrieval["chunks"]
    is_unanswered = retrieval["is_unanswered"]
    is_fallback = is_unanswered
    retrieval_cost_toman = retrieval["rerank_cost_toman"]

    if is_fallback and fallback_resp:
        yield ("delta", fallback_resp)
        yield ("done", {
            "response": fallback_resp, "chunk_ids": [], "scores": [], "sources": [],
            "prompt_tokens": 0, "completion_tokens": 0, "total_tokens": 0, "cost_toman": retrieval_cost_toman,
            "model": "n/a", "latency_ms": int((time.time() - start) * 1000),
            "is_fallback": True, "is_unanswered": True, "finish_reason": "fallback",
        })
        return

    context_parts = []
    for i, chunk in enumerate(chunks, 1):
        meta = chunk.get("metadata", {})
        title = meta.get("title", "")
        page = meta.get("page")
        header = f"[Source {i}]" + (f" {title}" if title else "") + (f" (page {page})" if page else "")
        context_parts.append(f"{header}\n{chunk['content']}")
    context = "\n\n---\n\n".join(context_parts)

    is_fa_question = _looks_persian(query)
    sys_p = _grounding_rules_for(query)
    sys_p += _business_name_rule(business_name, is_fa_question)
    sys_p += _live_pricing_rule(enabled_tools, is_fa_question)
    sys_p += _catalogue_lookup_rule(enabled_tools, is_fa_question)
    sys_p += _product_display_rule(enabled_tools, is_fa_question)
    sys_p += _cart_link_rule(enabled_tools, is_fa_question)
    sys_p += _payment_link_rule(enabled_tools, is_fa_question)
    sys_p += _order_status_rule(enabled_tools, is_fa_question)
    sys_p += _authenticity_rule(authenticity_unknown_message, is_fa_question)
    if system_prompt:
        sys_p += f"\n\n{system_prompt}"
    if context:
        sys_p += f"\n\n=== CONTEXT ===\n{context}\n=== END CONTEXT ==="

    lang_map = {"fa": "Persian/Farsi", "ar": "Arabic", "en": "English"}
    if language and language != "auto" and language in lang_map:
        sys_p += f"\n\nIMPORTANT: Always respond in {lang_map[language]}."

    # Tool calling doesn't stream token-by-token (a multi-step tool loop
    # doesn't map onto a single delta stream the way one plain completion
    # does) — run it to completion, then yield the whole answer as one
    # delta chunk followed by "done", the exact same pattern already used
    # for the SKU-shortcut and quota-exceeded paths above. See
    # run_rag_pipeline()'s identical, more detailed comment.
    # Only questions the classifier reads as needing live data get a
    # tool-deciding call. The rest go straight to retrieval, which is where
    # they were always going to end up -- previously by way of one wasted
    # model call that answered "no tool applies".
    if enabled_tools and is_tool_candidate(intent):
        from app.services.tool_calling_service import run_tool_calling_pipeline
        tool_result = run_tool_calling_pipeline(
            db, chatbot_id, conversation_id, query, history, sys_p, max_tokens, temperature, enabled_tools,
            intent=intent,
        )
        if tool_result is not None:
            tool_result["chunk_ids"] = [c["id"] for c in chunks]
            tool_result["scores"] = [round(c.get("rerank_score", c.get("similarity", 0.0)), 4) for c in chunks]
            tool_result["cost_toman"] = round(tool_result["cost_toman"] + retrieval_cost_toman, 4)
            yield ("delta", tool_result["response"])
            yield ("done", tool_result)
            return

    hist_text = ""
    for h in history[-12:]:
        if h.get("role") in ("user", "assistant") and h.get("content"):
            role_label = "User" if h["role"] == "user" else "Assistant"
            hist_text += f"{role_label}: {h['content']}\n"

    # The grounding/business-name rules are repeated here, right before the
    # question, in addition to the top of the prompt — repetition closest to
    # the generated text carries more weight than a rule stated once far
    # above, which a small model can lose track of by the time it reaches
    # the actual answer.
    reminder = _grounding_reminder(is_fa_question)
    full_prompt = f"{sys_p}\n\n{hist_text}{reminder}\n\nUser: {query}\nAssistant:"

    sources = []
    for c in chunks[:3]:
        m = c.get("metadata", {})
        src = {}
        if m.get("title"): src["title"] = m["title"]
        if m.get("url"): src["url"] = m["url"]
        if m.get("type"): src["type"] = m["type"]
        # Structured page number — the reliable half of citation. Prose in
        # the answer may or may not mention it (see the grounding-rules
        # clause below), but the widget can always show "file, page N" from
        # this regardless of what the model actually wrote.
        if m.get("page"): src["page"] = m["page"]
        if src: sources.append(src)

    full_text = ""
    model_used = "n/a"
    usage = {}
    cost_toman = retrieval_cost_toman
    stream_failed = False
    try:
        for kind, payload in _chat_completion_stream(db, full_prompt, max_tokens, temperature):
            if kind == "delta":
                full_text += payload
                yield ("delta", payload)
            elif kind == "meta":
                model_used = payload["model"]
                usage = payload["usage"]
                cost_toman += payload["cost_toman"]
    except Exception as e:
        logger.error(f"Streaming LLM error (all providers failed or died mid-stream): {e}")
        stream_failed = True
        if not full_text:
            full_text = fallback_resp or _default_error_response(query)
            yield ("delta", full_text)

    # Same check as the non-streaming path. The deltas have already gone out
    # token by token, so this corrects what is stored and what the client
    # renders as the final message, not what briefly appeared mid-stream --
    # an answer that claims an action is not something streaming can take
    # back. The tool path does not have this problem: it yields its whole
    # answer as a single delta, already guarded.
    full_text, cut = _strip_unproven_claims(db, conversation_id, chatbot_id, full_text, query)

    total_latency_ms = int((time.time() - start) * 1000)
    if not stream_failed:
        _log_event(db, conversation_id, chatbot_id, "response", {
            "model": model_used,
            "prompt_tokens": usage.get("prompt_tokens", 0),
            "completion_tokens": usage.get("completion_tokens", 0),
            "cost_toman": cost_toman,
            "citations": sources,
        }, latency_ms=total_latency_ms)
        _log_product_mentioned(db, conversation_id, chatbot_id, chunks)

    yield ("done", {
        "response": full_text,
        "chunk_ids": [c["id"] for c in chunks],
        "scores": [round(c.get("rerank_score", c.get("similarity", 0.0)), 4) for c in chunks],
        "sources": sources,
        "prompt_tokens": usage.get("prompt_tokens", 0),
        "completion_tokens": usage.get("completion_tokens", 0),
        "total_tokens": usage.get("total_tokens", 0),
        "cost_toman": cost_toman,
        "model": model_used,
        "latency_ms": int((time.time() - start) * 1000),
        "is_fallback": is_fallback or stream_failed,
        "is_unanswered": is_unanswered,
        "finish_reason": "error" if stream_failed else "stop",
    })


async def run_rag_pipeline(
    db: Session, chatbot_id: str, query: str, history: List[dict],
    system_prompt: Optional[str], fallback_resp: Optional[str], llm_model: str,
    top_k: int, threshold: float, temperature: float, max_tokens: int, language: str,
    rerank_enabled: bool = False, rerank_threshold: float = 0.500,
    business_name: Optional[str] = None, conversation_id: Optional[str] = None,
    enabled_tools: Optional[List[str]] = None,
    authenticity_unknown_message: Optional[str] = None,
) -> dict:

    start = time.time()

    # Zero-cost (no LLM call) — see intent_classifier.py. Logged
    # unconditionally, before any early-return branch below, so every real
    # user message gets an intent regardless of how the turn is eventually
    # answered (SKU shortcut, embedding failure, fallback, tool call, or a
    # normal completion) — a message's intent is a property of what the
    # customer asked, not of how well the pipeline could answer it.
    intent = classify_intent(query)
    _log_event(db, conversation_id, chatbot_id, "intent_classified", {"intent": intent})

    sku_result = _try_sku_shortcut(db, chatbot_id, conversation_id, query, start)
    if sku_result is not None:
        return sku_result

    try:
        query_embedding = _embed_query(query)
    except Exception as e:
        logger.error(f"Query embedding error: {e}")
        return {
            "response": fallback_resp or _default_error_response(query),
            "chunk_ids": [], "scores": [], "sources": [],
            "prompt_tokens": 0, "completion_tokens": 0, "total_tokens": 0, "cost_toman": 0,
            "model": "n/a",
            "latency_ms": int((time.time() - start) * 1000),
            # A failed embedding call is an infra problem, not evidence the
            # catalog is missing content — is_unanswered stays false here.
            "is_fallback": True, "is_unanswered": False, "finish_reason": "error",
        }

    # Vector search + full-text search fused with RRF, then optionally
    # reranked — see hybrid_retrieve(). is_unanswered is now a genuine
    # score-based signal (rerank score vs. rerank_threshold when reranking is
    # on, raw similarity vs. the chatbot's own retrieval_threshold when it's
    # off — never hardcoded, both admin-configurable per chatbot), not a
    # proxy for "did retrieval return zero rows", which hybrid search makes
    # almost always false regardless of relevance. Captured in its own
    # variable because is_fallback below gets overwritten again on an
    # LLM-call failure, which is a technical failure, not a content gap, and
    # must not be counted as "unanswered" for the demand-gap dashboard.
    retrieval = hybrid_retrieve(db, chatbot_id, conversation_id, query, query_embedding, top_k, threshold, language, rerank_enabled, rerank_threshold)
    chunks = retrieval["chunks"]
    is_unanswered = retrieval["is_unanswered"]
    is_fallback = is_unanswered
    retrieval_cost_toman = retrieval["rerank_cost_toman"]

    if is_fallback and fallback_resp:
        return {
            "response": fallback_resp, "chunk_ids": [], "scores": [], "sources": [],
            "prompt_tokens": 0, "completion_tokens": 0, "total_tokens": 0, "cost_toman": retrieval_cost_toman,
            "model": "n/a",
            "latency_ms": int((time.time() - start) * 1000),
            "is_fallback": True, "is_unanswered": True, "finish_reason": "fallback",
        }

    context_parts = []
    for i, chunk in enumerate(chunks, 1):
        meta = chunk.get("metadata", {})
        title = meta.get("title", "")
        page = meta.get("page")
        header = f"[Source {i}]" + (f" {title}" if title else "") + (f" (page {page})" if page else "")
        context_parts.append(f"{header}\n{chunk['content']}")
    context = "\n\n---\n\n".join(context_parts)

    # The grounding rules must always apply, regardless of any per-chatbot custom
    # prompt — previously a custom system_prompt fully *replaced* DEFAULT_SYSTEM
    # instead of adding to it, silently dropping the "don't invent facts" rule and
    # leaving small models free to fabricate plausible-sounding prices/contacts.
    # Chosen by the incoming question's own script (Persian vs. not) — see
    # _grounding_rules_for()'s docstring for why that's more reliable here
    # than the chatbot's configured response_language.
    is_fa_question = _looks_persian(query)
    sys_p = _grounding_rules_for(query)
    sys_p += _business_name_rule(business_name, is_fa_question)
    sys_p += _live_pricing_rule(enabled_tools, is_fa_question)
    sys_p += _catalogue_lookup_rule(enabled_tools, is_fa_question)
    sys_p += _product_display_rule(enabled_tools, is_fa_question)
    sys_p += _cart_link_rule(enabled_tools, is_fa_question)
    sys_p += _payment_link_rule(enabled_tools, is_fa_question)
    sys_p += _order_status_rule(enabled_tools, is_fa_question)
    sys_p += _authenticity_rule(authenticity_unknown_message, is_fa_question)
    if system_prompt:
        sys_p += f"\n\n{system_prompt}"
    if context:
        sys_p += f"\n\n=== CONTEXT ===\n{context}\n=== END CONTEXT ==="

    lang_map = {"fa": "Persian/Farsi", "ar": "Arabic", "en": "English"}
    if language and language != "auto" and language in lang_map:
        sys_p += f"\n\nIMPORTANT: Always respond in {lang_map[language]}."

    # Tool calling is layered on top of the RAG context above, not a
    # replacement for it — the model still sees whatever was retrieved
    # (sys_p already has grounding rules + business name + context baked
    # in at this point) AND gets the option to call a live-data tool for
    # the specific stock/price/etc. question retrieval structurally can't
    # answer. Returns None whenever tool calling doesn't apply (no tools
    # enabled for this chatbot, no tool-calling-capable provider
    # configured, the call itself failed, or the time budget ran out) —
    # falls through to the normal completion below unchanged in that case.
    # Only questions the classifier reads as needing live data get a
    # tool-deciding call. The rest go straight to retrieval, which is where
    # they were always going to end up -- previously by way of one wasted
    # model call that answered "no tool applies".
    if enabled_tools and is_tool_candidate(intent):
        from app.services.tool_calling_service import run_tool_calling_pipeline
        tool_result = run_tool_calling_pipeline(
            db, chatbot_id, conversation_id, query, history, sys_p, max_tokens, temperature, enabled_tools,
            intent=intent,
        )
        if tool_result is not None:
            tool_result["chunk_ids"] = [c["id"] for c in chunks]
            tool_result["scores"] = [round(c.get("rerank_score", c.get("similarity", 0.0)), 4) for c in chunks]
            tool_result["cost_toman"] = round(tool_result["cost_toman"] + retrieval_cost_toman, 4)
            return tool_result

    hist_text = ""
    for h in history[-12:]:
        if h.get("role") in ("user", "assistant") and h.get("content"):
            role_label = "User" if h["role"] == "user" else "Assistant"
            hist_text += f"{role_label}: {h['content']}\n"

    # Repeated right before the question, in addition to the top of the
    # prompt — see run_rag_pipeline_stream()'s identical comment.
    reminder = _grounding_reminder(is_fa_question)
    full_prompt = f"{sys_p}\n\n{hist_text}{reminder}\n\nUser: {query}\nAssistant:"

    model_used = "n/a"
    usage = {}
    cost_toman = retrieval_cost_toman
    llm_call_failed = False
    try:
        answer, model_used, usage, chat_cost_toman = _chat_completion(db, full_prompt, max_tokens, temperature)
        cost_toman += chat_cost_toman
    except Exception as e:
        logger.error(f"LLM error (all providers failed): {e}")
        answer = fallback_resp or _default_error_response(query)
        is_fallback = True
        llm_call_failed = True

    sources = []
    for c in chunks[:3]:
        m = c.get("metadata", {})
        src = {}
        if m.get("title"): src["title"] = m["title"]
        if m.get("url"): src["url"] = m["url"]
        if m.get("type"): src["type"] = m["type"]
        # Structured page number — the reliable half of citation. Prose in
        # the answer may or may not mention it (see the grounding-rules
        # clause below), but the widget can always show "file, page N" from
        # this regardless of what the model actually wrote.
        if m.get("page"): src["page"] = m["page"]
        if src: sources.append(src)

    # No tool ran on this path (the tool pipeline returns before here), so
    # there is nothing an "added to your cart" could rest on. This is the
    # exact case that produced one: the tool was switched off entirely.
    answer, cut = _strip_unproven_claims(db, conversation_id, chatbot_id, answer, query)

    total_latency_ms = int((time.time() - start) * 1000)
    if not llm_call_failed:
        _log_event(db, conversation_id, chatbot_id, "response", {
            "model": model_used,
            "prompt_tokens": usage.get("prompt_tokens", 0),
            "completion_tokens": usage.get("completion_tokens", 0),
            "cost_toman": cost_toman,
            "citations": sources,
        }, latency_ms=total_latency_ms)
        _log_product_mentioned(db, conversation_id, chatbot_id, chunks)

    return {
        "response": answer,
        "chunk_ids": [c["id"] for c in chunks],
        "scores": [round(c.get("rerank_score", c.get("similarity", 0.0)), 4) for c in chunks],
        "sources": sources,
        "prompt_tokens": usage.get("prompt_tokens", 0),
        "completion_tokens": usage.get("completion_tokens", 0),
        "total_tokens": usage.get("total_tokens", 0),
        "cost_toman": cost_toman,
        "model": model_used,
        "latency_ms": int((time.time() - start) * 1000),
        "is_fallback": is_fallback,
        "is_unanswered": is_unanswered,
        "finish_reason": "stop",
    }