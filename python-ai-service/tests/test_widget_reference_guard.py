"""
Regression test for two real incidents. First: the assistant answered "to
add this to your cart, click the button below" with no tool call that
turn — so no widget_blocks existed, so there was no button. The customer's
next message was "which button? there's no button."

Second (2026-10-23): a real customer got the same "click the button"
sentence for an add_to_cart turn -- this time a widget_block DID exist
(add_to_cart genuinely ran), but add_to_cart fires on its own with no
click at all, so the claim was still false. The guard originally only
checked "did some block exist", not which TYPE -- fixed to check for a
genuinely clickable type specifically (see widget_reference_guard.py's
own module docstring).

Unlike catalog_claim_guard.py's absolute-denial case, widget_blocks gives a
real, checkable fact: either this turn built a genuinely clickable one or
it didn't. So this module is a rewriter, not just a detector, and this
test checks the removal itself, not only prompt text (plus the prompt
text, same approach as test_catalog_claim_guard.py, since an LLM's live
output can't be asserted on deterministically).
"""
import unittest

from app.services.widget_reference_guard import (
    references_ui_element, strip_unbacked_widget_references,
)
from app.services.rag_service import GROUNDING_RULES, GROUNDING_RULES_FA


class UiReferenceDetectionTest(unittest.TestCase):
    def test_the_reported_sentence_is_caught(self):
        self.assertTrue(references_ui_element(
            "برای افزودن ماگ دختر پسر درب سیلیکونی به سبد خرید، روی دکمه‌ی زیر کلیک کنید:"
        ))

    def test_other_ui_references_in_persian(self):
        for sentence in (
            "روی لینک زیر کلیک کنید.",
            "لینک زیر را باز کنید.",
        ):
            self.assertTrue(references_ui_element(sentence), sentence)

    def test_ui_references_in_english(self):
        for sentence in (
            "Click the button below to add it to your cart.",
            "Tap the button to continue.",
            "Use the link below.",
        ):
            self.assertTrue(references_ui_element(sentence), sentence)

    def test_ordinary_product_talk_is_untouched(self):
        for sentence in (
            "این محصول دارای یک درب سیلیکونی است.",
            "This product has a silicone lid and a steel chain link.",
            "قیمت این محصول ۱۳۵٬۰۰۰ تومان است.",
        ):
            self.assertFalse(references_ui_element(sentence), sentence)


class StripUnbackedWidgetReferencesTest(unittest.TestCase):
    def test_removed_when_no_widget_blocks(self):
        text = "برای افزودن ماگ دختر پسر درب سیلیکونی به سبد خرید، روی دکمه‌ی زیر کلیک کنید:"
        cleaned, removed = strip_unbacked_widget_references(text, widget_block_types=())
        self.assertEqual(cleaned, "")
        self.assertEqual(len(removed), 1)

    def test_left_alone_when_a_genuinely_clickable_block_exists(self):
        text = "برای افزودن ماگ دختر پسر درب سیلیکونی به سبد خرید، روی دکمه‌ی زیر کلیک کنید:"
        cleaned, removed = strip_unbacked_widget_references(text, widget_block_types={"cart_links"})
        self.assertEqual(cleaned, text)
        self.assertEqual(removed, [])

    def test_payment_link_preview_also_counts_as_clickable(self):
        text = "برای تکمیل خرید روی دکمه‌ی زیر کلیک کنید."
        cleaned, removed = strip_unbacked_widget_references(text, widget_block_types={"payment_link_preview"})
        self.assertEqual(cleaned, text)

    def test_order_status_otp_also_counts_as_clickable(self):
        text = "برای دریافت کد، روی دکمه‌ی زیر کلیک کنید."
        cleaned, removed = strip_unbacked_widget_references(text, widget_block_types={"order_status_otp"})
        self.assertEqual(cleaned, text)

    # ── the actual 2026-10-23 incident ──────────────────────────────────
    def test_an_add_to_cart_block_does_NOT_back_a_click_claim(self):
        """add_to_cart fires on its own (see haman-widget.js's
        autoAddToCart()) — a real production response claimed a button
        existed anyway, and this guard let it through because SOME block
        existed that turn, without checking which type. The fix: this
        specific type must never be treated as backing a click/button
        reference."""
        text = "دکمه‌ی افزودن به سبد را برایتان آماده کردم، روی آن بزنید."
        cleaned, removed = strip_unbacked_widget_references(text, widget_block_types={"add_to_cart"})
        self.assertEqual(cleaned, "")
        self.assertEqual(len(removed), 1)

    def test_product_cards_and_compare_also_never_back_a_click_claim(self):
        text = "روی دکمه‌ی زیر کلیک کنید."
        for block_type in ("product_cards", "product_compare"):
            cleaned, removed = strip_unbacked_widget_references(text, widget_block_types={block_type})
            self.assertEqual(cleaned, "", block_type)

    def test_a_clickable_type_alongside_add_to_cart_still_backs_the_claim(self):
        # A turn can legitimately carry more than one block — a comparison
        # table plus a genuinely clickable cart link, say. The claim only
        # needs ONE clickable type present, not every block to be one.
        text = "روی دکمه‌ی زیر کلیک کنید."
        cleaned, removed = strip_unbacked_widget_references(text, widget_block_types={"add_to_cart", "cart_links"})
        self.assertEqual(cleaned, text)
        self.assertEqual(removed, [])

    def test_only_the_offending_sentence_is_removed(self):
        text = "این ماگ ۵۵۰ میلی‌لیتر است. روی دکمه‌ی زیر کلیک کنید. قیمت آن ۱۳۵٬۰۰۰ تومان است."
        cleaned, removed = strip_unbacked_widget_references(text, widget_block_types=())
        self.assertNotIn("دکمه", cleaned)
        self.assertIn("۵۵۰ میلی‌لیتر", cleaned)
        self.assertIn("۱۳۵٬۰۰۰ تومان", cleaned)
        self.assertEqual(len(removed), 1)

    def test_empty_text_is_a_noop(self):
        self.assertEqual(strip_unbacked_widget_references("", ()), ("", []))
        self.assertEqual(strip_unbacked_widget_references(None, ()), ("", []))


class GroundingRulesForbidUnbackedUiReferenceTest(unittest.TestCase):
    def test_english_rules_forbid_it(self):
        self.assertIn("unless a tool call this exact turn actually", GROUNDING_RULES)

    def test_persian_rules_forbid_it(self):
        self.assertIn("مگر این‌که همین نوبت یک ابزار واقعاً آن را ساخته باشد", GROUNDING_RULES_FA)


if __name__ == "__main__":
    unittest.main()
