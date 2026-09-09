"""
Intent classification (doc-04 "Intent analytics", Very high) — a plain
bilingual keyword/regex classifier, not an extra LLM call per message
(see intent_classifier.py's module docstring for the cost reasoning).
This tests the classifier's own logic with representative phrases in both
languages; the real accuracy assessment against 20 actual production
messages is done separately (a manual read, reported honestly in the
deploy notes rather than asserted here as a number this file can't
actually verify).
"""
import unittest

from app.services.intent_classifier import classify_intent, INTENTS


class ClassifyIntentTest(unittest.TestCase):
    def test_never_raises_on_empty_or_none(self):
        self.assertEqual(classify_intent(""), "other")
        self.assertEqual(classify_intent(None), "other")
        self.assertEqual(classify_intent("   "), "other")

    def test_falls_back_to_other_for_unrelated_text(self):
        self.assertEqual(classify_intent("Hello, nice website!"), "other")
        self.assertEqual(classify_intent("سلام، روز خوبی داشته باشید"), "other")

    def test_returns_only_known_intents(self):
        samples = [
            "how much does this cost?", "قیمتش چنده؟", "is it in stock?", "موجوده؟",
            "compare these two", "کدوم بهتره؟", "what do you recommend for oily skin?",
            "پیشنهاد میدی چی بگیرم؟", "is this genuine?", "اصله یا تقلبی؟",
            "shipping cost to Tehran?", "چند روز میرسه؟", "can I return this?", "مرجوع میکنید؟",
            "how do I pay?", "کارت به کارت هم قبول میکنید؟", "where is my order?", "سفارشم کجاست؟",
            "the app isn't working", "سایت خرابه", "just saying hi",
        ]
        for s in samples:
            self.assertIn(classify_intent(s), INTENTS, s)

    # ── price ──────────────────────────────────────────────────────────
    def test_price_english(self):
        self.assertEqual(classify_intent("How much does this cost?"), "price")

    def test_price_persian(self):
        self.assertEqual(classify_intent("قیمتش چنده؟"), "price")

    # ── availability ───────────────────────────────────────────────────
    def test_availability_english(self):
        self.assertEqual(classify_intent("Do you have this in stock?"), "availability")

    def test_availability_persian(self):
        self.assertEqual(classify_intent("این کالا موجوده؟"), "availability")

    # ── comparison ─────────────────────────────────────────────────────
    def test_comparison_english(self):
        self.assertEqual(classify_intent("Can you compare these two products?"), "comparison")

    def test_comparison_persian(self):
        self.assertEqual(classify_intent("کدوم بهتره، این یا اون؟"), "comparison")

    # ── consultation ───────────────────────────────────────────────────
    def test_consultation_english(self):
        self.assertEqual(classify_intent("What do you recommend for oily skin?"), "consultation")

    def test_consultation_persian(self):
        self.assertEqual(classify_intent("برای پوست چرب چی پیشنهاد میدی؟"), "consultation")

    # ── authenticity ───────────────────────────────────────────────────
    def test_authenticity_english(self):
        self.assertEqual(classify_intent("Is this product genuine or fake?"), "authenticity")

    def test_authenticity_persian(self):
        self.assertEqual(classify_intent("این کالا اصله یا تقلبی؟"), "authenticity")

    # ── shipping ───────────────────────────────────────────────────────
    def test_shipping_english(self):
        self.assertEqual(classify_intent("What's the shipping cost to my city?"), "shipping")

    def test_shipping_persian(self):
        self.assertEqual(classify_intent("هزینه ارسال به شیراز چقدره؟"), "shipping")

    # ── return ─────────────────────────────────────────────────────────
    def test_return_english(self):
        self.assertEqual(classify_intent("Can I return this if it doesn't fit?"), "return")

    def test_return_persian(self):
        self.assertEqual(classify_intent("میشه این رو مرجوع کنم؟"), "return")

    # ── payment ────────────────────────────────────────────────────────
    def test_payment_english(self):
        self.assertEqual(classify_intent("What payment methods do you accept?"), "payment")

    def test_payment_persian(self):
        self.assertEqual(classify_intent("روش پرداخت چیه؟"), "payment")

    # ── order_status ───────────────────────────────────────────────────
    def test_order_status_english(self):
        self.assertEqual(classify_intent("Where is my order? I need the tracking number."), "order_status")

    def test_order_status_persian(self):
        self.assertEqual(classify_intent("سفارشم کجاست؟ کد سفارش رو دارم"), "order_status")

    # ── technical_support ──────────────────────────────────────────────
    def test_technical_support_english(self):
        self.assertEqual(classify_intent("The app isn't working, I get an error message."), "technical_support")

    def test_technical_support_persian(self):
        self.assertEqual(classify_intent("سایت خرابه، کار نمیکنه"), "technical_support")

    # ── priority order among overlapping vocabulary ───────────────────
    def test_authenticity_checked_before_availability(self):
        # Both "genuine" (authenticity) and "in stock" (availability)
        # appear — authenticity must win as the more specific/higher-
        # liability category (see rag_service._authenticity_rule()).
        self.assertEqual(classify_intent("Is the genuine version in stock?"), "authenticity")

    def test_order_status_checked_before_return(self):
        self.assertEqual(classify_intent("My order status - can I still return it?"), "order_status")


if __name__ == "__main__":
    unittest.main()
