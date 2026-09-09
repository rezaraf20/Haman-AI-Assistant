"""
"Is this genuine?" was the most common customer question in both real
interviews this app was built from (electronics parts and cosmetics) — and
a wrong "yes" is a real liability the merchant bears, not a cosmetic
mistake. The data itself is synced from WooCommerce (see SyncService::
syncProducts()' Authenticity Status/Brand/... lines and
AuthenticityFieldSyncTest.php on the Laravel side, tested separately),
never left to the model to infer. This tests _authenticity_rule() directly
— the prompt-side half — the same "verify the prompt text, not an LLM's
actual output" approach used by test_grounding_no_fabricated_claims.py and
test_live_pricing_rule.py.
"""
import unittest

from app.services.rag_service import (
    _authenticity_rule,
    DEFAULT_AUTHENTICITY_UNKNOWN_EN,
    DEFAULT_AUTHENTICITY_UNKNOWN_FA,
)


class AuthenticityRuleTest(unittest.TestCase):
    def test_always_present_even_with_no_tools_or_config(self):
        # Unlike the pricing/product-display rules, this one is NOT gated
        # behind enabled_tools — authenticity data arrives via the regular
        # retrieved CONTEXT, not a live tool call, so every chatbot needs it.
        rule = _authenticity_rule(None, is_fa=False)
        self.assertNotEqual(rule, "")

    def test_english_rule_forbids_judging_from_description_or_reviews(self):
        rule = _authenticity_rule(None, is_fa=False)
        self.assertIn("never judge authenticity from the product's description, reviews", rule)

    def test_english_rule_forbids_probably_genuine_hedge(self):
        rule = _authenticity_rule(None, is_fa=False)
        self.assertIn('never say it\'s "probably genuine"', rule)

    def test_persian_rule_forbids_judging_from_description_or_reviews(self):
        rule = _authenticity_rule(None, is_fa=True)
        self.assertIn("هرگز بر", rule)
        self.assertIn("قضاوت نکن", rule)

    def test_uses_the_default_english_fallback_when_none_configured(self):
        rule = _authenticity_rule(None, is_fa=False)
        self.assertIn(DEFAULT_AUTHENTICITY_UNKNOWN_EN, rule)

    def test_uses_the_default_persian_fallback_when_none_configured(self):
        rule = _authenticity_rule(None, is_fa=True)
        self.assertIn(DEFAULT_AUTHENTICITY_UNKNOWN_FA, rule)

    def test_uses_the_store_configured_fallback_when_given(self):
        custom = "Please call our showroom at 021-12345678 to verify authenticity."
        rule = _authenticity_rule(custom, is_fa=False)
        self.assertIn(custom, rule)
        self.assertNotIn(DEFAULT_AUTHENTICITY_UNKNOWN_EN, rule)

    def test_store_configured_fallback_also_used_in_persian(self):
        custom = "برای تأیید اصالت با ۰۲۱-۱۲۳۴۵۶۷۸ تماس بگیرید."
        rule = _authenticity_rule(custom, is_fa=True)
        self.assertIn(custom, rule)

    def test_empty_string_configured_message_falls_back_to_default(self):
        # "" is falsy in Python -- an empty override must not produce a
        # rule that tells the model to say literally nothing.
        rule = _authenticity_rule("", is_fa=False)
        self.assertIn(DEFAULT_AUTHENTICITY_UNKNOWN_EN, rule)


if __name__ == "__main__":
    unittest.main()
