"""
Routing from the intent, not from the model's choice.

The two rules that matter here are both about not forcing a tool that cannot
honestly run: anything acting on the customer's cart or money needs their
explicit say-so, and a tool whose required argument is missing must not be
forced, because a forced model invents the argument rather than declining.
"""
import unittest

from app.services.tool_router import (
    UNROUTABLE_TOOLS, has_mobile, has_sku_like_token, route,
)

ALL = [
    "get_product_availability", "get_product_variants", "search_products",
    "recommend_products", "compare_products", "build_cart_url", "add_to_cart",
    "create_payment_link", "get_order_status",
]


class RoutingTest(unittest.TestCase):
    def test_consultation_goes_straight_to_recommend(self):
        # recommend_products takes the need in free text, so nothing has to
        # be looked up first.
        plan = route("consultation", ALL, "برای هدیه تولد چی پیشنهاد می‌دید؟")

        self.assertIsNotNone(plan)
        self.assertEqual(plan.target, "recommend_products")
        self.assertEqual(plan.forced_tool([]), "recommend_products")

    def test_price_and_availability_both_reach_availability(self):
        for intent in ("price", "availability"):
            plan = route(intent, ALL, "قیمت فنجون دسته اردک چنده؟")
            self.assertIsNotNone(plan, intent)
            self.assertEqual(plan.target, "get_product_availability", intent)

    def test_a_product_question_searches_before_it_asks_for_stock(self):
        plan = route("availability", ALL, "تراش دایناسور الان موجوده؟")

        # No id in the question, so the first forced call finds one.
        self.assertEqual(plan.forced_tool([]), "search_products")
        self.assertEqual(plan.forced_tool(["search_products"]), "get_product_availability")
        # Once the target has run the model is free again.
        self.assertIsNone(plan.forced_tool(["search_products", "get_product_availability"]))

    def test_a_part_number_skips_the_search(self):
        plan = route("availability", ALL, "440-579 موجوده؟")

        self.assertEqual(plan.forced_tool([]), "get_product_availability")

    def test_comparison_always_searches_first(self):
        # compare_products needs two numeric ids; a customer gives names.
        plan = route("comparison", ALL, "فرق تراول ماگ حروف رنگی با فنجون دسته اردک چیه؟")

        self.assertEqual(plan.target, "compare_products")
        self.assertEqual(plan.forced_tool([]), "search_products")
        self.assertEqual(plan.forced_tool(["search_products"]), "compare_products")


class ConsentTest(unittest.TestCase):
    def test_cart_and_payment_are_never_routed(self):
        self.assertEqual(
            UNROUTABLE_TOOLS,
            frozenset({"add_to_cart", "create_payment_link", "build_cart_url"}),
        )
        # "payment" is a real intent, and it must not force a payment link.
        self.assertIsNone(route("payment", ALL, "می‌خوام بخرم، لینک پرداخت بدید"))

    def test_order_status_is_routed_only_once_a_number_is_given(self):
        self.assertIsNone(route("order_status", ALL, "سفارش من به کجا رسید؟"))

        plan = route("order_status", ALL, "سفارشم کجاست؟ شماره‌ام 09123456789 است")

        self.assertIsNotNone(plan)
        self.assertEqual(plan.target, "get_order_status")

    def test_a_number_from_an_earlier_turn_counts(self):
        history = [{"role": "user", "content": "09121112233"}]

        self.assertTrue(has_mobile("سفارشم کجاست؟", history))
        self.assertIsNotNone(route("order_status", ALL, "سفارشم کجاست؟", history))


class NoRouteTest(unittest.TestCase):
    def test_an_unmapped_intent_leaves_the_choice_to_the_model(self):
        for intent in ("other", "shipping", "return", "technical_support", "authenticity"):
            self.assertIsNone(route(intent, ALL, "یک سوال"), intent)

    def test_a_disabled_target_is_not_routed(self):
        enabled = [t for t in ALL if t != "recommend_products"]

        self.assertIsNone(route("consultation", enabled, "چی پیشنهاد می‌دید؟"))

    def test_a_lookup_route_needs_the_search_tool_too(self):
        enabled = [t for t in ALL if t != "search_products"]

        self.assertIsNone(route("comparison", enabled, "فرق اینها چیه؟"))

    def test_empty_intent_and_empty_tools_are_safe(self):
        self.assertIsNone(route("", ALL, "سلام"))
        self.assertIsNone(route("consultation", [], "چی پیشنهاد می‌دید؟"))


class TokenDetectionTest(unittest.TestCase):
    def test_sku_like_tokens(self):
        self.assertTrue(has_sku_like_token("440-579 موجوده؟"))
        self.assertTrue(has_sku_like_token("do you have ABC1234?"))
        # Ordinary words and short numbers are not part numbers.
        self.assertFalse(has_sku_like_token("ماگ دارید؟"))
        self.assertFalse(has_sku_like_token("قیمت چنده؟"))

    def test_mobile_shapes(self):
        self.assertTrue(has_mobile("09121112233"))
        self.assertTrue(has_mobile("+989121112233"))
        self.assertFalse(has_mobile("سفارش ۱۴۰۷ من کجاست؟"))


if __name__ == "__main__":
    unittest.main()
