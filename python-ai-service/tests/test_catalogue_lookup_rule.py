"""
The CONTEXT block is a handful of retrieved passages, but a model reads it as
the whole catalogue.

One run of eight real Persian questions against a real synced shop, scoring
only which tool the model decides to call: 5/8 with the CONTEXT block in the
system prompt and 7/8 without it. Both losses were the same mistake -- asked
to compare two products, and asked to add one to the cart, it looked at the
CONTEXT, found no product id, and told the customer the information was not
recorded, rather than calling the search tool that would have found both.
With this rule the same run scores 6/8 and 8/8.

_catalogue_lookup_rule() is what says otherwise. Like the other rule tests
here, this checks the prompt text the pipeline builds, not an LLM's output:
the phrasing is what carries the behaviour, and it must survive edits.
"""
import unittest

from app.services.rag_service import _catalogue_lookup_rule


class CatalogueLookupRuleTest(unittest.TestCase):
    def test_absent_when_no_tools_enabled(self):
        self.assertEqual(_catalogue_lookup_rule(None, is_fa=False), "")
        self.assertEqual(_catalogue_lookup_rule([], is_fa=False), "")

    def test_absent_without_the_search_tool(self):
        # The rule tells the model to search. With no search tool enabled
        # that is an instruction it cannot follow.
        self.assertEqual(
            _catalogue_lookup_rule(["get_order_status", "add_to_cart"], is_fa=False), ""
        )

    def test_present_when_the_search_tool_is_enabled(self):
        self.assertNotEqual(_catalogue_lookup_rule(["search_products"], is_fa=False), "")

    def test_english_says_the_context_is_not_the_catalogue(self):
        rule = _catalogue_lookup_rule(["search_products"], is_fa=False)

        self.assertIn("not the product", rule)
        self.assertIn("never contains product IDs", rule)
        # The two failures this rule exists for: giving up, and passing the
        # lookup back to the customer.
        self.assertIn("not recorded or not available when a tool can find it", rule)
        self.assertIn("never ask the", rule)

    def test_persian_carries_the_same_two_prohibitions(self):
        rule = _catalogue_lookup_rule(["search_products"], is_fa=True)

        self.assertIn("فهرست کامل محصولات", rule)
        self.assertIn("شناسه‌ی محصول", rule)
        self.assertIn("ثبت نشده", rule)
        self.assertIn("نپرس", rule)

    def test_it_starts_on_its_own_line(self):
        # Every rule is concatenated onto one growing system prompt, so a
        # rule that does not open with a blank line runs into the previous
        # sentence.
        for is_fa in (True, False):
            self.assertTrue(
                _catalogue_lookup_rule(["search_products"], is_fa=is_fa).startswith("\n\n")
            )


if __name__ == "__main__":
    unittest.main()
