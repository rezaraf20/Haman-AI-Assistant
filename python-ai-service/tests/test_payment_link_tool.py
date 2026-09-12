"""
create_payment_link (doc-04) — the model-callable half is READ-ONLY: it
only ever previews a proposed order (real price/name/total, via the SAME
preview_order action Laravel re-checks fresh at confirm time). It never
creates an order or a payment link itself — that only happens after a
real customer click, handled entirely by ChatController::createPaymentLink()
with its own from-scratch security checks this tool never touches. See
test_add_to_cart_tool.py / test_recommend_compare_tools.py for the sibling
patterns this mirrors (validation-only vs. live-query-dependent).
"""
import unittest
from unittest.mock import MagicMock, patch

from app.services.tools import product_tools


class PaymentLinkValidationTest(unittest.TestCase):
    def test_empty_items_is_rejected(self):
        db = MagicMock()
        result = product_tools.create_payment_link(db, "chatbot-1", items=[])
        self.assertIn("error", result)
        db.execute.assert_not_called()

    def test_non_list_items_is_rejected(self):
        db = MagicMock()
        result = product_tools.create_payment_link(db, "chatbot-1", items="12")
        self.assertIn("error", result)
        db.execute.assert_not_called()

    def test_too_many_items_is_rejected(self):
        db = MagicMock()
        items = [{"product_id": i} for i in range(1, product_tools.MAX_PAYMENT_LINK_ITEMS + 2)]
        result = product_tools.create_payment_link(db, "chatbot-1", items=items)
        self.assertIn("error", result)
        db.execute.assert_not_called()

    def test_item_missing_product_id_is_rejected(self):
        db = MagicMock()
        result = product_tools.create_payment_link(db, "chatbot-1", items=[{"quantity": 1}])
        self.assertIn("error", result)

    def test_invalid_product_id_is_rejected(self):
        db = MagicMock()
        result = product_tools.create_payment_link(db, "chatbot-1", items=[{"product_id": "DROP TABLE"}])
        self.assertIn("error", result)

    def test_invalid_variation_id_is_rejected(self):
        db = MagicMock()
        result = product_tools.create_payment_link(db, "chatbot-1", items=[{"product_id": 12, "variation_id": "bad"}])
        self.assertIn("error", result)

    def test_invalid_quantity_is_rejected(self):
        db = MagicMock()
        result = product_tools.create_payment_link(db, "chatbot-1", items=[{"product_id": 12, "quantity": "lots"}])
        self.assertIn("error", result)

    def test_quantity_is_clamped_not_rejected_when_absurdly_large(self):
        db = MagicMock()
        db.execute.return_value.fetchone.return_value = None
        result = product_tools.create_payment_link(db, "chatbot-1", items=[{"product_id": 12, "quantity": 99999}])
        # No site on file, so this fails before ever using the clamped
        # quantity — but validation itself must not reject it outright.
        self.assertEqual(result["error"], "live_check_unavailable")

    def test_a_non_dict_item_is_rejected(self):
        db = MagicMock()
        result = product_tools.create_payment_link(db, "chatbot-1", items=["not-a-dict"])
        self.assertIn("error", result)


class PaymentLinkCustomerValidationTest(unittest.TestCase):
    def test_customer_is_none_by_default(self):
        self.assertIsNone(product_tools._validate_customer(None))

    def test_non_dict_customer_is_ignored(self):
        self.assertIsNone(product_tools._validate_customer("Ali"))

    def test_empty_customer_dict_is_none(self):
        self.assertIsNone(product_tools._validate_customer({}))

    def test_blank_fields_are_dropped(self):
        self.assertIsNone(product_tools._validate_customer({"name": "   ", "phone": ""}))

    def test_valid_fields_are_trimmed_and_kept(self):
        out = product_tools._validate_customer({"name": "  Ali  ", "phone": "0912", "email": "a@b.com"})
        self.assertEqual(out, {"name": "Ali", "phone": "0912", "email": "a@b.com"})

    def test_fields_are_capped_to_max_length(self):
        out = product_tools._validate_customer({"name": "x" * 500})
        self.assertEqual(len(out["name"]), 255)

    def test_unknown_extra_fields_are_ignored(self):
        out = product_tools._validate_customer({"name": "Ali", "note": "vip"})
        self.assertEqual(out, {"name": "Ali"})


class PaymentLinkPricingSafetyTest(unittest.TestCase):
    """This tool never creates anything — it's still bound by the same
    "never fabricate a price/total" rule as the other live tools (see
    module docstring's PRICING RULE)."""

    def test_no_site_on_file_returns_live_check_unavailable(self):
        db = MagicMock()
        db.execute.return_value.fetchone.return_value = None
        result = product_tools.create_payment_link(db, "chatbot-1", items=[{"product_id": 12}])
        self.assertEqual(result["error"], "live_check_unavailable")
        self.assertEqual(result["items"], [])

    def test_live_query_failure_returns_live_check_unavailable(self):
        db = MagicMock()
        site_row = MagicMock(primary_domain="example.test", webhook_secret="s3cret")
        db.execute.return_value.fetchone.return_value = site_row
        with patch("app.services.tools.product_tools._requests.post",
                   side_effect=product_tools._requests.RequestException("timed out")):
            result = product_tools.create_payment_link(db, "chatbot-1", items=[{"product_id": 12}])
        self.assertEqual(result["error"], "live_check_unavailable")

    def test_wordpress_error_passthrough(self):
        db = MagicMock()
        site_row = MagicMock(primary_domain="example.test", webhook_secret="s3cret")
        db.execute.return_value.fetchone.return_value = site_row
        fake_response = MagicMock(status_code=200)
        fake_response.json.return_value = {"error": "out_of_stock"}
        with patch("app.services.tools.product_tools._requests.post", return_value=fake_response):
            result = product_tools.create_payment_link(db, "chatbot-1", items=[{"product_id": 12}])
        self.assertEqual(result["error"], "out_of_stock")

    def test_live_success_returns_real_preview_and_never_writes(self):
        db = MagicMock()
        site_row = MagicMock(primary_domain="example.test", webhook_secret="s3cret")
        db.execute.return_value.fetchone.return_value = site_row
        fake_response = MagicMock(status_code=200)
        fake_response.json.return_value = {
            "items": [{"product_id": 12, "name": "Widget", "quantity": 1, "line_total": 90000}],
            "total": 90000,
            "currency": "IRT",
        }
        with patch("app.services.tools.product_tools._requests.post", return_value=fake_response) as mock_post:
            result = product_tools.create_payment_link(
                db, "chatbot-1", items=[{"product_id": 12}], customer={"name": "Ali"},
            )
        self.assertEqual(result["total"], 90000)
        self.assertEqual(result["currency"], "IRT")
        self.assertEqual(result["customer"], {"name": "Ali"})
        # It's a preview only — no order/write action was requested.
        sent_body = mock_post.call_args.kwargs["data"]
        self.assertIn(b'"action":"preview_order"', sent_body)
        self.assertNotIn(b'create_draft_order', sent_body)

    def test_live_success_defaults_currency_when_missing(self):
        db = MagicMock()
        site_row = MagicMock(primary_domain="example.test", webhook_secret="s3cret")
        db.execute.return_value.fetchone.return_value = site_row
        fake_response = MagicMock(status_code=200)
        fake_response.json.return_value = {"items": [], "total": 0}
        with patch("app.services.tools.product_tools._requests.post", return_value=fake_response):
            result = product_tools.create_payment_link(db, "chatbot-1", items=[{"product_id": 12}])
        self.assertEqual(result["currency"], "IRT")

    def test_customer_is_none_when_not_supplied(self):
        db = MagicMock()
        site_row = MagicMock(primary_domain="example.test", webhook_secret="s3cret")
        db.execute.return_value.fetchone.return_value = site_row
        fake_response = MagicMock(status_code=200)
        fake_response.json.return_value = {"items": [], "total": 0, "currency": "IRT"}
        with patch("app.services.tools.product_tools._requests.post", return_value=fake_response):
            result = product_tools.create_payment_link(db, "chatbot-1", items=[{"product_id": 12}])
        self.assertIsNone(result["customer"])


if __name__ == "__main__":
    unittest.main()
