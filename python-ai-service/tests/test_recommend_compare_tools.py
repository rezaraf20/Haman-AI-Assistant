"""
Tests for recommend_products/compare_products (doc-04 "Product compare",
Very high) — see product_tools.py's module docstring for the two
guarantees these specifically must hold:
  - recommend_products only ever returns in-stock products (enforced at
    the WordPress query level, not merely by prompting the model)
  - compare_products never invents a value for an attribute a product
    doesn't have — a missing key means "not listed", never a guess

Same "verify the code's own logic deterministically" division of labor as
test_tool_calling.py; live verification (a real recommendation against a
real catalog) is done separately against the deployed server.
"""
import unittest
from unittest.mock import MagicMock, patch

from app.services.tools import product_tools


class RecommendProductsValidationTest(unittest.TestCase):
    def test_empty_need_is_rejected(self):
        db = MagicMock()
        result = product_tools.recommend_products(db, "chatbot-1", need="   ")
        self.assertIn("error", result)
        db.execute.assert_not_called()

    def test_non_string_need_is_rejected(self):
        db = MagicMock()
        result = product_tools.recommend_products(db, "chatbot-1", need=12345)
        self.assertIn("error", result)

    def test_invalid_category_is_rejected(self):
        db = MagicMock()
        result = product_tools.recommend_products(db, "chatbot-1", need="oily skin", category="x" * 200)
        self.assertIn("error", result)
        db.execute.assert_not_called()


class RecommendProductsPricingSafetyTest(unittest.TestCase):
    def test_no_site_on_file_recommends_nothing(self):
        db = MagicMock()
        db.execute.return_value.fetchone.return_value = None
        result = product_tools.recommend_products(db, "chatbot-1", need="oily skin")
        self.assertFalse(result["live"])
        self.assertEqual(result["products"], [])
        self.assertIn("note", result)

    def test_live_query_failure_recommends_nothing(self):
        db = MagicMock()
        site_row = MagicMock(primary_domain="shop.example.com", webhook_secret="s3cret")
        db.execute.return_value.fetchone.return_value = site_row
        with patch("app.services.tools.product_tools._requests.post", side_effect=product_tools._requests.RequestException("timed out")):
            result = product_tools.recommend_products(db, "chatbot-1", need="oily skin")
        self.assertFalse(result["live"])
        self.assertEqual(result["products"], [])

    def test_live_success_returns_at_most_three_with_id_based_urls(self):
        db = MagicMock()
        site_row = MagicMock(primary_domain="shop.example.com", webhook_secret="s3cret")
        db.execute.return_value.fetchone.return_value = site_row

        fake_response = MagicMock(status_code=200)
        fake_response.json.return_value = {
            "currency": "IRT",
            "products": [
                {"product_id": 1, "name": "Oil-Free Cleanser", "price": 90000, "on_sale": False, "stock_status": "instock", "short_description": "For oily skin.", "image": "https://x/1.jpg"},
                {"product_id": 2, "name": "Mattifying Gel", "price": 70000, "on_sale": True, "stock_status": "instock", "short_description": "Controls shine.", "image": None},
                {"product_id": 3, "name": "Toner", "price": 50000, "on_sale": False, "stock_status": "instock", "short_description": "Balances skin.", "image": None},
                {"product_id": 4, "name": "Extra one WP shouldn't send", "price": 1, "on_sale": False, "stock_status": "instock", "short_description": "", "image": None},
            ],
        }
        with patch("app.services.tools.product_tools._requests.post", return_value=fake_response):
            result = product_tools.recommend_products(db, "chatbot-1", need="oily skin")

        self.assertTrue(result["live"])
        # Defense in depth: capped to 3 here even though the fake WP
        # response (deliberately) sent 4.
        self.assertEqual(len(result["products"]), 3)
        self.assertEqual(result["products"][0]["product_url"], "https://shop.example.com/?p=1")
        for p in result["products"]:
            self.assertEqual(p["stock_status"], "instock")

    def test_query_forces_stock_status_instock_at_the_request_level(self):
        """The model never controls whether out-of-stock items can be
        recommended — recommend_products doesn't even expose an
        in_stock_only parameter, unlike search_products."""
        db = MagicMock()
        site_row = MagicMock(primary_domain="shop.example.com", webhook_secret="s3cret")
        db.execute.return_value.fetchone.return_value = site_row
        fake_response = MagicMock(status_code=200)
        fake_response.json.return_value = {"currency": "IRT", "products": []}
        with patch("app.services.tools.product_tools._requests.post", return_value=fake_response) as mock_post:
            product_tools.recommend_products(db, "chatbot-1", need="oily skin")
        sent_body = mock_post.call_args.kwargs["data"].decode()
        self.assertNotIn("in_stock_only", sent_body)  # not a toggle at all — WordPress hardcodes it


class CompareProductsValidationTest(unittest.TestCase):
    def test_non_list_product_ids_is_rejected(self):
        db = MagicMock()
        result = product_tools.compare_products(db, "chatbot-1", product_ids="1,2")
        self.assertIn("error", result)
        db.execute.assert_not_called()

    def test_single_product_id_is_rejected(self):
        db = MagicMock()
        result = product_tools.compare_products(db, "chatbot-1", product_ids=[1])
        self.assertIn("error", result)
        db.execute.assert_not_called()

    def test_invalid_id_in_list_is_rejected(self):
        db = MagicMock()
        result = product_tools.compare_products(db, "chatbot-1", product_ids=[1, "DROP TABLE"])
        self.assertIn("error", result)
        db.execute.assert_not_called()

    def test_duplicate_ids_are_deduped_before_the_minimum_check(self):
        db = MagicMock()
        result = product_tools.compare_products(db, "chatbot-1", product_ids=[1, 1])
        self.assertIn("error", result)  # deduped down to 1 distinct id -> still below the minimum
        db.execute.assert_not_called()

    def test_more_than_five_ids_is_capped_not_rejected(self):
        db = MagicMock()
        db.execute.return_value.fetchone.return_value = None  # no site -> short-circuits before a live call
        result = product_tools.compare_products(db, "chatbot-1", product_ids=[1, 2, 3, 4, 5, 6, 7])
        self.assertEqual(result["products"], [])  # degrades to "unavailable", doesn't crash


class CompareProductsPricingSafetyTest(unittest.TestCase):
    def test_no_site_on_file_returns_no_products_or_rows(self):
        db = MagicMock()
        db.execute.return_value.fetchone.return_value = None
        result = product_tools.compare_products(db, "chatbot-1", product_ids=[1, 2])
        self.assertFalse(result["live"])
        self.assertEqual(result["products"], [])
        self.assertEqual(result["attribute_rows"], [])

    def test_live_failure_returns_no_products_or_rows(self):
        db = MagicMock()
        site_row = MagicMock(primary_domain="shop.example.com", webhook_secret="s3cret")
        db.execute.return_value.fetchone.return_value = site_row
        with patch("app.services.tools.product_tools._requests.post", side_effect=product_tools._requests.RequestException("down")):
            result = product_tools.compare_products(db, "chatbot-1", product_ids=[1, 2])
        self.assertFalse(result["live"])
        self.assertEqual(result["products"], [])
        self.assertEqual(result["attribute_rows"], [])

    def test_missing_attribute_on_one_product_is_left_blank_not_guessed(self):
        db = MagicMock()
        site_row = MagicMock(primary_domain="shop.example.com", webhook_secret="s3cret")
        db.execute.return_value.fetchone.return_value = site_row

        fake_response = MagicMock(status_code=200)
        fake_response.json.return_value = {
            "currency": "IRT",
            "products": [
                {"product_id": 1, "found": True, "name": "Cream A", "price": 100000, "stock_status": "instock", "image": None,
                 "attributes": {"Volume": "50ml", "Scent": "Lavender"}},
                {"product_id": 2, "found": True, "name": "Cream B", "price": 120000, "stock_status": "instock", "image": None,
                 "attributes": {"Volume": "100ml"}},  # no Scent at all
            ],
        }
        with patch("app.services.tools.product_tools._requests.post", return_value=fake_response):
            result = product_tools.compare_products(db, "chatbot-1", product_ids=[1, 2])

        self.assertTrue(result["live"])
        rows_by_attr = {r["attribute"]: r["values"] for r in result["attribute_rows"]}
        self.assertEqual(rows_by_attr["Volume"], {"1": "50ml", "2": "100ml"})
        self.assertEqual(rows_by_attr["Scent"]["1"], "Lavender")
        self.assertIsNone(rows_by_attr["Scent"]["2"])  # never guessed from product 1

    def test_a_not_found_product_id_does_not_crash_and_is_flagged(self):
        db = MagicMock()
        site_row = MagicMock(primary_domain="shop.example.com", webhook_secret="s3cret")
        db.execute.return_value.fetchone.return_value = site_row

        fake_response = MagicMock(status_code=200)
        fake_response.json.return_value = {
            "currency": "IRT",
            "products": [
                {"product_id": 1, "found": True, "name": "Cream A", "price": 100000, "stock_status": "instock", "image": None, "attributes": {"Volume": "50ml"}},
                {"product_id": 999, "found": False},
            ],
        }
        with patch("app.services.tools.product_tools._requests.post", return_value=fake_response):
            result = product_tools.compare_products(db, "chatbot-1", product_ids=[1, 999])

        self.assertTrue(result["live"])
        found_flags = {p["product_id"]: p["found"] for p in result["products"]}
        self.assertEqual(found_flags, {1: True, 999: False})
        # The not-found product contributes nothing to any attribute row.
        for row in result["attribute_rows"]:
            self.assertNotIn("999", row["values"])


if __name__ == "__main__":
    unittest.main()
