"""
The live stock/price/variant tools (see app/services/tools/product_tools.py)
deliberately never fall back to the last-synced index on failure — a wrong
price is the worst mistake a shop's bot can make. But sys_p already
contains the regular retrieved CONTEXT (which can itself mention an old
price, e.g. from a synced product description or a datasheet example) by
the time the tool-calling call runs, so the model must be told explicitly
never to answer a price/stock/variant question from that context and to
use the live tool instead. This tests _live_pricing_rule() directly, the
same "verify the prompt text, not an LLM's actual output" approach used by
test_grounding_no_fabricated_claims.py.
"""
import unittest

from app.services.rag_service import _live_pricing_rule, _product_display_rule, _cart_link_rule


class LivePricingRuleTest(unittest.TestCase):
    def test_absent_when_no_tools_enabled(self):
        self.assertEqual(_live_pricing_rule(None, is_fa=False), "")
        self.assertEqual(_live_pricing_rule([], is_fa=False), "")

    def test_absent_when_enabled_tools_has_no_pricing_tool(self):
        self.assertEqual(_live_pricing_rule(["some_other_tool"], is_fa=False), "")

    def test_present_when_get_product_availability_enabled(self):
        rule = _live_pricing_rule(["get_product_availability"], is_fa=False)
        self.assertIn("Never state a price", rule)

    def test_present_when_get_product_variants_enabled(self):
        rule = _live_pricing_rule(["get_product_variants"], is_fa=False)
        self.assertNotEqual(rule, "")

    def test_present_when_search_products_enabled(self):
        rule = _live_pricing_rule(["search_products"], is_fa=False)
        self.assertNotEqual(rule, "")

    def test_present_when_recommend_products_enabled(self):
        # recommend_products/compare_products also surface price/stock, so
        # they must trip the same "never state price from CONTEXT" rule.
        rule = _live_pricing_rule(["recommend_products"], is_fa=False)
        self.assertNotEqual(rule, "")

    def test_present_when_compare_products_enabled(self):
        rule = _live_pricing_rule(["compare_products"], is_fa=False)
        self.assertNotEqual(rule, "")

    def test_english_rule_forbids_stating_price_from_context(self):
        rule = _live_pricing_rule(["get_product_availability"], is_fa=False)
        self.assertIn("CONTEXT above", rule)
        self.assertIn("live/current data", rule)
        self.assertIn("product_url", rule)

    def test_persian_rule_forbids_stating_price_from_context(self):
        rule = _live_pricing_rule(["get_product_availability"], is_fa=True)
        self.assertIn("هرگز قیمت", rule)
        self.assertIn("زنده و", rule)

    def test_language_selection_follows_is_fa_flag_not_tool_name(self):
        en_rule = _live_pricing_rule(["get_product_availability"], is_fa=False)
        fa_rule = _live_pricing_rule(["get_product_availability"], is_fa=True)
        self.assertNotEqual(en_rule, fa_rule)


class ProductDisplayRuleTest(unittest.TestCase):
    """recommend_products/compare_products render as actual widget UI
    (product cards / a comparison table) — the model must be told not to
    re-describe or re-table that content itself. See
    tool_calling_service._build_widget_block()."""

    def test_absent_when_no_display_tool_enabled(self):
        self.assertEqual(_product_display_rule(None, is_fa=False), "")
        self.assertEqual(_product_display_rule(["get_product_availability"], is_fa=False), "")

    def test_present_when_recommend_products_enabled(self):
        rule = _product_display_rule(["recommend_products"], is_fa=False)
        self.assertIn("rendered directly in the chat widget", rule)

    def test_present_when_compare_products_enabled(self):
        rule = _product_display_rule(["compare_products"], is_fa=False)
        self.assertNotEqual(rule, "")

    def test_english_rule_forbids_recommending_unreturned_products(self):
        rule = _product_display_rule(["recommend_products"], is_fa=False)
        self.assertIn("Never recommend a product the tool did not return", rule)

    def test_english_rule_forbids_guessing_missing_compare_features(self):
        rule = _product_display_rule(["compare_products"], is_fa=False)
        self.assertIn("never guess it from the other product", rule)

    def test_persian_rule_present(self):
        rule = _product_display_rule(["recommend_products"], is_fa=True)
        self.assertIn("کارت‌های محصول", rule)

    def test_language_selection_follows_is_fa_flag(self):
        en_rule = _product_display_rule(["recommend_products"], is_fa=False)
        fa_rule = _product_display_rule(["recommend_products"], is_fa=True)
        self.assertNotEqual(en_rule, fa_rule)


class CartLinkRuleTest(unittest.TestCase):
    """build_cart_url's link only actually adds anything once the customer
    clicks it — the model must never claim the item is already in the
    cart, since nothing has happened server-side at all yet. See
    product_tools.build_cart_url's own docstring for why real cart
    mutation is explicitly out of scope for now."""

    def test_absent_when_cart_tool_not_enabled(self):
        self.assertEqual(_cart_link_rule(None, is_fa=False), "")
        self.assertEqual(_cart_link_rule(["get_product_availability"], is_fa=False), "")

    def test_present_when_build_cart_url_enabled(self):
        rule = _cart_link_rule(["build_cart_url"], is_fa=False)
        self.assertNotEqual(rule, "")

    def test_english_rule_forbids_claiming_already_added(self):
        rule = _cart_link_rule(["build_cart_url"], is_fa=False)
        self.assertIn("never say you've already added it", rule)

    def test_persian_rule_forbids_claiming_already_added(self):
        rule = _cart_link_rule(["build_cart_url"], is_fa=True)
        self.assertIn("هرگز نگو", rule)

    def test_language_selection_follows_is_fa_flag(self):
        en_rule = _cart_link_rule(["build_cart_url"], is_fa=False)
        fa_rule = _cart_link_rule(["build_cart_url"], is_fa=True)
        self.assertNotEqual(en_rule, fa_rule)

    def test_present_when_add_to_cart_enabled(self):
        rule = _cart_link_rule(["add_to_cart"], is_fa=False)
        self.assertNotEqual(rule, "")

    def test_add_to_cart_adds_the_variant_selection_clause_english(self):
        rule = _cart_link_rule(["add_to_cart"], is_fa=False)
        self.assertIn("never guess a variation_id", rule)

    def test_add_to_cart_adds_the_variant_selection_clause_persian(self):
        rule = _cart_link_rule(["add_to_cart"], is_fa=True)
        self.assertIn("variation_id را حدس نزن", rule)

    def test_build_cart_url_alone_has_no_variant_selection_clause(self):
        # build_cart_url has no variation_id concept at all — the extra
        # clause is specific to add_to_cart and must not leak in otherwise.
        rule = _cart_link_rule(["build_cart_url"], is_fa=False)
        self.assertNotIn("variation_id", rule)

    def test_both_tools_enabled_still_produces_one_combined_rule(self):
        rule = _cart_link_rule(["build_cart_url", "add_to_cart"], is_fa=False)
        self.assertIn("never say you've already added it", rule)
        self.assertIn("never guess a variation_id", rule)


if __name__ == "__main__":
    unittest.main()
