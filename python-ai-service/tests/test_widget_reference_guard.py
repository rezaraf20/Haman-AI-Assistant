"""
Regression test for a real conversation: the assistant answered "to add
this to your cart, click the button below" with no tool call that turn —
so no widget_blocks existed, so there was no button. The customer's next
message was "which button? there's no button."

Unlike catalog_claim_guard.py's absolute-denial case, widget_blocks gives a
real, checkable fact: either this turn built one or it didn't. So this
module is a rewriter, not just a detector, and this test checks the removal
itself, not only prompt text (plus the prompt text, same approach as
test_catalog_claim_guard.py, since an LLM's live output can't be asserted
on deterministically).
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
        cleaned, removed = strip_unbacked_widget_references(text, has_widget_blocks=False)
        self.assertEqual(cleaned, "")
        self.assertEqual(len(removed), 1)

    def test_left_alone_when_widget_blocks_exist(self):
        text = "برای افزودن ماگ دختر پسر درب سیلیکونی به سبد خرید، روی دکمه‌ی زیر کلیک کنید:"
        cleaned, removed = strip_unbacked_widget_references(text, has_widget_blocks=True)
        self.assertEqual(cleaned, text)
        self.assertEqual(removed, [])

    def test_only_the_offending_sentence_is_removed(self):
        text = "این ماگ ۵۵۰ میلی‌لیتر است. روی دکمه‌ی زیر کلیک کنید. قیمت آن ۱۳۵٬۰۰۰ تومان است."
        cleaned, removed = strip_unbacked_widget_references(text, has_widget_blocks=False)
        self.assertNotIn("دکمه", cleaned)
        self.assertIn("۵۵۰ میلی‌لیتر", cleaned)
        self.assertIn("۱۳۵٬۰۰۰ تومان", cleaned)
        self.assertEqual(len(removed), 1)

    def test_empty_text_is_a_noop(self):
        self.assertEqual(strip_unbacked_widget_references("", False), ("", []))
        self.assertEqual(strip_unbacked_widget_references(None, False), ("", []))


class GroundingRulesForbidUnbackedUiReferenceTest(unittest.TestCase):
    def test_english_rules_forbid_it(self):
        self.assertIn("unless a tool call this exact turn actually", GROUNDING_RULES)

    def test_persian_rules_forbid_it(self):
        self.assertIn("مگر این‌که همین نوبت یک ابزار واقعاً آن را ساخته باشد", GROUNDING_RULES_FA)


if __name__ == "__main__":
    unittest.main()
