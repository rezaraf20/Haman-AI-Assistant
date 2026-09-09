"""
build_cart_url — the deliberately SAFE half of doc-04's "Add to cart +
cart URL" item. Real cart mutation (Woo Store API cart token/nonce, and
the buyer-identity question that opens) and order-status lookup are both
explicitly out of scope for now — see product_tools.build_cart_url's
docstring. This tool only ever builds WooCommerce's own native, nonce-free
?add-to-cart=<id>&quantity=<n> GET link; the customer's own click is what
actually adds it, WooCommerce handles that itself. Unlike every other tool
in this module, it makes NO live HTTP call to the store at all — these
tests reflect that by never mocking _requests.post.
"""
import unittest
from unittest.mock import MagicMock

from app.services.tools import product_tools


class BuildCartUrlValidationTest(unittest.TestCase):
    def test_empty_items_is_rejected(self):
        db = MagicMock()
        result = product_tools.build_cart_url(db, "chatbot-1", items=[])
        self.assertIn("error", result)
        db.execute.assert_not_called()

    def test_non_list_items_is_rejected(self):
        db = MagicMock()
        result = product_tools.build_cart_url(db, "chatbot-1", items="12")
        self.assertIn("error", result)
        db.execute.assert_not_called()

    def test_too_many_items_is_rejected(self):
        db = MagicMock()
        items = [{"product_id": i} for i in range(1, 8)]
        result = product_tools.build_cart_url(db, "chatbot-1", items=items)
        self.assertIn("error", result)
        db.execute.assert_not_called()

    def test_non_object_item_is_rejected(self):
        db = MagicMock()
        site_row = MagicMock(primary_domain="example.test")
        db.execute.return_value.fetchone.return_value = site_row
        result = product_tools.build_cart_url(db, "chatbot-1", items=[12])
        self.assertIn("error", result)

    def test_invalid_product_id_is_rejected(self):
        db = MagicMock()
        site_row = MagicMock(primary_domain="example.test")
        db.execute.return_value.fetchone.return_value = site_row
        result = product_tools.build_cart_url(db, "chatbot-1", items=[{"product_id": "DROP TABLE"}])
        self.assertIn("error", result)

    def test_invalid_quantity_is_rejected(self):
        db = MagicMock()
        site_row = MagicMock(primary_domain="example.test")
        db.execute.return_value.fetchone.return_value = site_row
        result = product_tools.build_cart_url(db, "chatbot-1", items=[{"product_id": 12, "quantity": "lots"}])
        self.assertIn("error", result)


class BuildCartUrlNoLiveCallTest(unittest.TestCase):
    """The defining property of this tool: it never contacts the store at
    all — it only needs the domain already on file plus the product IDs
    the model already knows."""

    def test_no_domain_on_file_returns_no_items(self):
        db = MagicMock()
        db.execute.return_value.fetchone.return_value = None
        result = product_tools.build_cart_url(db, "chatbot-1", items=[{"product_id": 12}])
        self.assertIn("error", result)
        self.assertEqual(result["items"], [])

    def test_success_never_touches_requests_post(self):
        from unittest.mock import patch
        db = MagicMock()
        site_row = MagicMock(primary_domain="example.test")
        db.execute.return_value.fetchone.return_value = site_row
        with patch("app.services.tools.product_tools._requests.post") as mock_post:
            product_tools.build_cart_url(db, "chatbot-1", items=[{"product_id": 12}])
        mock_post.assert_not_called()

    def test_success_builds_the_native_woocommerce_url_with_default_quantity(self):
        db = MagicMock()
        site_row = MagicMock(primary_domain="example.test")
        db.execute.return_value.fetchone.return_value = site_row
        result = product_tools.build_cart_url(db, "chatbot-1", items=[{"product_id": 12}])
        self.assertEqual(result["items"], [{"product_id": 12, "quantity": 1, "url": "https://example.test/?add-to-cart=12&quantity=1"}])

    def test_success_honors_a_given_quantity(self):
        db = MagicMock()
        site_row = MagicMock(primary_domain="example.test")
        db.execute.return_value.fetchone.return_value = site_row
        result = product_tools.build_cart_url(db, "chatbot-1", items=[{"product_id": 12, "quantity": 3}])
        self.assertEqual(result["items"][0]["url"], "https://example.test/?add-to-cart=12&quantity=3")

    def test_quantity_is_clamped_not_rejected_when_absurdly_large(self):
        db = MagicMock()
        site_row = MagicMock(primary_domain="example.test")
        db.execute.return_value.fetchone.return_value = site_row
        result = product_tools.build_cart_url(db, "chatbot-1", items=[{"product_id": 12, "quantity": 99999}])
        self.assertEqual(result["items"][0]["quantity"], product_tools.MAX_CART_QUANTITY)

    def test_zero_or_negative_quantity_is_clamped_to_one(self):
        db = MagicMock()
        site_row = MagicMock(primary_domain="example.test")
        db.execute.return_value.fetchone.return_value = site_row
        result = product_tools.build_cart_url(db, "chatbot-1", items=[{"product_id": 12, "quantity": -5}])
        self.assertEqual(result["items"][0]["quantity"], 1)

    def test_multiple_items_each_get_their_own_link(self):
        """WooCommerce core only ever reads a single product id from
        ?add-to-cart= (absint() silently truncates a comma-separated value
        to its first id) — a single combined multi-product URL would
        silently drop every item after the first, so this must always
        return one full URL per item, never one merged URL."""
        db = MagicMock()
        site_row = MagicMock(primary_domain="example.test")
        db.execute.return_value.fetchone.return_value = site_row
        result = product_tools.build_cart_url(db, "chatbot-1", items=[{"product_id": 12}, {"product_id": 34, "quantity": 2}])
        self.assertEqual(len(result["items"]), 2)
        self.assertEqual(result["items"][0]["url"], "https://example.test/?add-to-cart=12&quantity=1")
        self.assertEqual(result["items"][1]["url"], "https://example.test/?add-to-cart=34&quantity=2")


if __name__ == "__main__":
    unittest.main()
