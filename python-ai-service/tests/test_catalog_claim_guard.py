"""
Regression test for a real conversation on the live chatbot: asked for a
green mug, the assistant answered "ما در حال حاضر محصولی با رنگ سبز در
زمینهٔ خود نداریم" (we don't currently have a green product) -- a flat
denial from one retrieval pass, not a fact about the whole catalog -- and in
the very same turn went on to link an actual mug product.

Two things are checked: the detector in catalog_claim_guard.py actually
catches the reported sentence shape (and leaves the hedged, correct phrasing
alone), and -- same approach as test_grounding_no_fabricated_claims.py,
since an LLM's live output can't be asserted on deterministically -- the
prompt text itself (grounding rules, the repeated reminder, and the
search_products/recommend_products tool descriptions the model reads before
answering) now explicitly forbids the flat-denial phrasing and spells out
the required hedge.
"""
import unittest

from app.services.catalog_claim_guard import (
    find_absolute_catalog_denials, is_absolute_catalog_denial,
)
from app.services.rag_service import GROUNDING_RULES, GROUNDING_RULES_FA, _grounding_reminder
from app.services.tools import product_tools  # noqa: F401 -- registers the tools below
from app.services.tools.registry import _REGISTRY


class AbsoluteCatalogDenialDetectionTest(unittest.TestCase):
    def test_the_reported_sentence_is_caught(self):
        self.assertTrue(is_absolute_catalog_denial(
            "ما در حال حاضر محصولی با رنگ سبز در زمینهٔ خود نداریم."
        ))

    def test_other_absolute_denials_in_persian(self):
        for sentence in (
            "این مدل را نداریم.",
            "فلاسک ۸۰۰ میلی‌لیتری در فهرست محصولات ما موجود نیست.",
            "ما ماگ سبز نداریم.",
        ):
            self.assertTrue(is_absolute_catalog_denial(sentence), sentence)

    def test_absolute_denials_in_english(self):
        for sentence in (
            "We don't have a green mug.",
            "We don't carry that model.",
            "This is not available in our catalog.",
        ):
            self.assertTrue(is_absolute_catalog_denial(sentence), sentence)

    def test_the_hedged_correct_phrasing_is_not_flagged(self):
        for sentence in (
            "در نتایجی که دیدم ماگ سبز پیدا نکردم؛ ممکن است با عبارت دیگری ثبت شده باشد.",
            "در جستجوی خودم فلاسک ۸۰۰ میلی‌لیتری پیدا نکردم؛ شاید با نام دیگری ثبت شده باشد.",
            "I didn't find a green mug in what I searched just now; it might be listed "
            "under another name.",
        ):
            self.assertFalse(is_absolute_catalog_denial(sentence), sentence)

    def test_an_offer_or_question_about_a_color_is_not_a_denial(self):
        # Must not fire on the customer's own question, or on the assistant
        # asking a clarifying one -- only on a flat assertion.
        for sentence in (
            "آیا رنگ سبز هم دارید؟",
            "چه رنگ‌هایی موجود است؟",
            "Do you have this in green?",
        ):
            self.assertFalse(is_absolute_catalog_denial(sentence), sentence)

    def test_ordinary_product_talk_is_untouched(self):
        for sentence in (
            "این ماگ ۵۵۰ میلی‌لیتر است و جنس آن استیل ۳۱۶ است.",
            "قیمت این محصول ۱۳۵٬۰۰۰ تومان است.",
        ):
            self.assertFalse(is_absolute_catalog_denial(sentence), sentence)

    def test_find_all_returns_only_the_offending_sentence(self):
        text = (
            "این ماگ ۵۵۰ میلی‌لیتر است. ما رنگ سبز نداریم. قیمت آن ۱۳۵٬۰۰۰ تومان است."
        )
        found = find_absolute_catalog_denials(text)
        self.assertEqual(len(found), 1)
        self.assertIn("رنگ سبز نداریم", found[0])


class GroundingRulesForbidFlatCatalogDenialTest(unittest.TestCase):
    def test_english_rules_forbid_the_flat_denial(self):
        self.assertIn("Never tell a customer the business does not carry something", GROUNDING_RULES)
        self.assertIn("We don't have a green mug", GROUNDING_RULES)

    def test_persian_rules_forbid_the_flat_denial(self):
        self.assertIn("هرگز به مشتری نگو این کسب‌وکار چیزی را ندارد", GROUNDING_RULES_FA)
        self.assertIn("ماگ سبز نداریم", GROUNDING_RULES_FA)

    def test_reminder_repeats_the_rule_english(self):
        self.assertIn("never say 'we don't have that'", _grounding_reminder(is_fa=False))

    def test_reminder_repeats_the_rule_persian(self):
        self.assertIn("هرگز نگو «نداریم»", _grounding_reminder(is_fa=True))


class ToolDescriptionsForbidFlatCatalogDenialTest(unittest.TestCase):
    def test_search_products_description_forbids_the_flat_denial(self):
        desc = _REGISTRY["search_products"].description
        self.assertIn("doesn't carry", desc)
        self.assertIn("not proof the store doesn't carry", desc)

    def test_recommend_products_description_forbids_the_flat_denial(self):
        desc = _REGISTRY["recommend_products"].description
        self.assertIn("doesn't carry", desc)


if __name__ == "__main__":
    unittest.main()
