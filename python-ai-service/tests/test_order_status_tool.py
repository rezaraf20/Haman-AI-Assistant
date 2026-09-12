"""
get_order_status (doc-04) — the model-callable half is pure intent: it
sends nothing, looks nothing up, and makes no HTTP call at all. Every
control that matters (does this number have an order at this store, the
per-contact/per-chatbot/per-IP caps, the SMS charge) lives in
ChatController::requestOrderStatusCode(), reachable only from a real
human click on the widget's button.

That split is the entire security argument for the feature, so the tests
below are mostly about what this function must NOT do.
"""
import unittest
from unittest.mock import MagicMock, patch

from app.services.tools import product_tools


class OrderStatusValidationTest(unittest.TestCase):
    def test_missing_contact_is_rejected(self):
        db = MagicMock()
        self.assertIn("error", product_tools.get_order_status(db, "chatbot-1", contact=""))

    def test_whitespace_only_contact_is_rejected(self):
        db = MagicMock()
        self.assertIn("error", product_tools.get_order_status(db, "chatbot-1", contact="   "))

    def test_non_string_contact_is_rejected(self):
        db = MagicMock()
        self.assertIn("error", product_tools.get_order_status(db, "chatbot-1", contact=9121234567))

    def test_too_short_a_number_is_rejected(self):
        db = MagicMock()
        self.assertIn("error", product_tools.get_order_status(db, "chatbot-1", contact="12345"))

    def test_a_landline_is_rejected(self):
        # Iranian mobiles are 9XXXXXXXXX; a Tehran landline is not one, and
        # sending an OTP there would burn the merchant's money for nothing.
        db = MagicMock()
        self.assertIn("error", product_tools.get_order_status(db, "chatbot-1", contact="02188776655"))

    def test_an_email_is_rejected(self):
        # Email would need a mail transport this platform does not have.
        db = MagicMock()
        self.assertIn("error", product_tools.get_order_status(db, "chatbot-1", contact="a@b.com"))


class OrderStatusNormalizationTest(unittest.TestCase):
    """One human, many spellings — all must collapse to one form, or the
    per-number hourly cap could be walked straight past."""

    def _contact(self, raw):
        return product_tools.get_order_status(MagicMock(), "chatbot-1", contact=raw)["contact"]

    def test_local_format_is_kept(self):
        self.assertEqual(self._contact("09121234567"), "09121234567")

    def test_international_plus_format_is_normalized(self):
        self.assertEqual(self._contact("+989121234567"), "09121234567")

    def test_double_zero_format_is_normalized(self):
        self.assertEqual(self._contact("00989121234567"), "09121234567")

    def test_bare_format_is_normalized(self):
        self.assertEqual(self._contact("9121234567"), "09121234567")

    def test_spaces_and_dashes_are_ignored(self):
        self.assertEqual(self._contact(" 0912 123 - 4567 "), "09121234567")

    def test_every_spelling_collapses_to_one_value(self):
        forms = ["09121234567", "+989121234567", "00989121234567", "9121234567", "0912-123-4567"]
        self.assertEqual(len({self._contact(f) for f in forms}), 1)


class OrderStatusSendsNothingTest(unittest.TestCase):
    """The defining property: calling this tool cannot cost the merchant
    anything, because it talks to nobody."""

    def test_it_makes_no_http_call_and_touches_no_database(self):
        db = MagicMock()
        with patch("app.services.tools.product_tools._requests.post") as mock_post:
            result = product_tools.get_order_status(db, "chatbot-1", contact="09121234567")
        mock_post.assert_not_called()
        db.execute.assert_not_called()
        self.assertEqual(result, {"contact": "09121234567"})

    def test_it_returns_no_order_data_of_any_kind(self):
        # The customer has not proved they control the number yet, so there
        # is nothing here to leak even if the model asked for it.
        result = product_tools.get_order_status(MagicMock(), "chatbot-1", contact="09121234567")
        self.assertEqual(list(result.keys()), ["contact"])


class OrderStatusRegistrationTest(unittest.TestCase):
    def test_the_tool_is_registered_and_read_only(self):
        from app.services.tools.registry import _REGISTRY
        tool = _REGISTRY["get_order_status"]
        self.assertEqual(tool.access_level, "read")
        self.assertEqual(tool.parameters["required"], ["contact"])

    def test_the_description_tells_the_model_it_sends_nothing(self):
        from app.services.tools.registry import _REGISTRY
        description = _REGISTRY["get_order_status"].description
        self.assertIn("does NOT", description)
        self.assertIn("never ask for or repeat a verification code", description)


if __name__ == "__main__":
    unittest.main()
