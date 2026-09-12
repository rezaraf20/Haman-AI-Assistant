"""
add_to_cart — the richer sibling of build_cart_url. The widget itself
runs on the shop's own domain (same-origin with the cart), so it can call
WooCommerce's Store API directly with the browser's own cookies — no cart
token, no buyer-identity question. This tool makes ZERO HTTP calls (unlike
every live-query tool in this module) and returns pure intent for the
widget to render as a real button; the actual add only happens after a
real browser click (verified separately by the widget's own jsdom tests,
since this tool's own job ends at "return validated intent").
"""
import unittest
from unittest.mock import MagicMock

from app.services.tools import product_tools


class AddToCartValidationTest(unittest.TestCase):
    def test_empty_items_is_rejected(self):
        db = MagicMock()
        result = product_tools.add_to_cart(db, "chatbot-1", items=[])
        self.assertIn("error", result)
        db.execute.assert_not_called()

    def test_non_list_items_is_rejected(self):
        db = MagicMock()
        result = product_tools.add_to_cart(db, "chatbot-1", items="12")
        self.assertIn("error", result)

    def test_too_many_items_is_rejected(self):
        db = MagicMock()
        items = [{"product_id": i, "name": "x"} for i in range(1, 8)]
        result = product_tools.add_to_cart(db, "chatbot-1", items=items)
        self.assertIn("error", result)

    def test_missing_name_is_rejected(self):
        db = MagicMock()
        result = product_tools.add_to_cart(db, "chatbot-1", items=[{"product_id": 12}])
        self.assertIn("error", result)

    def test_blank_name_is_rejected(self):
        db = MagicMock()
        result = product_tools.add_to_cart(db, "chatbot-1", items=[{"product_id": 12, "name": "   "}])
        self.assertIn("error", result)

    def test_invalid_product_id_is_rejected(self):
        db = MagicMock()
        result = product_tools.add_to_cart(db, "chatbot-1", items=[{"product_id": "DROP TABLE", "name": "x"}])
        self.assertIn("error", result)

    def test_invalid_variation_id_is_rejected(self):
        db = MagicMock()
        result = product_tools.add_to_cart(db, "chatbot-1", items=[{"product_id": 12, "variation_id": "bad", "name": "x"}])
        self.assertIn("error", result)

    def test_invalid_quantity_is_rejected(self):
        db = MagicMock()
        result = product_tools.add_to_cart(db, "chatbot-1", items=[{"product_id": 12, "quantity": "lots", "name": "x"}])
        self.assertIn("error", result)


class AddToCartNoLiveCallTest(unittest.TestCase):
    """The defining property of this tool, same as build_cart_url: it never
    contacts the store, so this doesn't even need a domain on file — it
    doesn't touch the database at all."""

    def test_success_never_touches_db_or_requests(self):
        from unittest.mock import patch
        db = MagicMock()
        with patch("app.services.tools.product_tools._requests.post") as mock_post:
            product_tools.add_to_cart(db, "chatbot-1", items=[{"product_id": 12, "name": "Widget"}])
        mock_post.assert_not_called()
        db.execute.assert_not_called()

    def test_success_returns_the_validated_intent_with_defaults(self):
        db = MagicMock()
        result = product_tools.add_to_cart(db, "chatbot-1", items=[{"product_id": 12, "name": "Widget"}])
        self.assertEqual(result["items"], [{"product_id": 12, "variation_id": None, "quantity": 1, "name": "Widget"}])

    def test_success_honors_variation_id_and_quantity(self):
        db = MagicMock()
        result = product_tools.add_to_cart(db, "chatbot-1", items=[
            {"product_id": 12, "variation_id": 34, "quantity": 2, "name": "Widget (Blue)"},
        ])
        self.assertEqual(result["items"][0], {"product_id": 12, "variation_id": 34, "quantity": 2, "name": "Widget (Blue)"})

    def test_quantity_is_clamped_not_rejected_when_absurdly_large(self):
        db = MagicMock()
        result = product_tools.add_to_cart(db, "chatbot-1", items=[{"product_id": 12, "quantity": 99999, "name": "x"}])
        self.assertEqual(result["items"][0]["quantity"], product_tools.MAX_CART_QUANTITY)

    def test_zero_or_negative_quantity_is_clamped_to_one(self):
        db = MagicMock()
        result = product_tools.add_to_cart(db, "chatbot-1", items=[{"product_id": 12, "quantity": -3, "name": "x"}])
        self.assertEqual(result["items"][0]["quantity"], 1)

    def test_name_is_trimmed_and_capped(self):
        db = MagicMock()
        result = product_tools.add_to_cart(db, "chatbot-1", items=[{"product_id": 12, "name": "  " + ("x" * 500) + "  "}])
        name = result["items"][0]["name"]
        self.assertEqual(len(name), 200)
        self.assertFalse(name.startswith(" "))

    def test_multiple_items_each_carry_their_own_intent(self):
        db = MagicMock()
        result = product_tools.add_to_cart(db, "chatbot-1", items=[
            {"product_id": 12, "name": "A"},
            {"product_id": 34, "variation_id": 56, "quantity": 3, "name": "B (Large)"},
        ])
        self.assertEqual(len(result["items"]), 2)
        self.assertIsNone(result["items"][0]["variation_id"])
        self.assertEqual(result["items"][1]["variation_id"], 56)


if __name__ == "__main__":
    unittest.main()
