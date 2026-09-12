"""
_detect_lead_signal() — the moment a shop can still save a sale it is
about to lose: the customer wanted something real that is out of stock,
or something the shop does not carry at all.

The two are deliberately separate modes. "We'll text you when it's back"
is a promise a shop can keep; saying it about something never stocked is
not. Laravel's LeadCaptureService owns the wording and the off switches —
this function only reports what the tool call actually found.

The failure that matters most here is a FALSE positive: telling a
customer a product is not stocked, and asking for their phone number,
when really the store was just unreachable.
"""
import unittest

from app.services import tool_calling_service as tcs


class OutOfStockDetectionTest(unittest.TestCase):
    def test_a_found_but_out_of_stock_product_is_detected_by_name(self):
        signal = tcs._detect_lead_signal(
            "get_product_availability",
            {"sku": "MUG-1"},
            {"found": True, "live": True, "name": "تراول ماگ", "stock_status": "outofstock"},
        )
        self.assertEqual(signal, {"mode": "out_of_stock", "item": "تراول ماگ"})

    def test_an_in_stock_product_produces_nothing(self):
        self.assertIsNone(tcs._detect_lead_signal(
            "get_product_availability", {"sku": "MUG-1"},
            {"found": True, "live": True, "name": "تراول ماگ", "stock_status": "instock"},
        ))

    def test_backorder_counts_as_available_not_as_out_of_stock(self):
        # The shop can still take the money for a backorder — offering a
        # "we'll tell you when it's back" callback would be nonsense.
        self.assertIsNone(tcs._detect_lead_signal(
            "get_product_availability", {"sku": "MUG-1"},
            {"found": True, "live": True, "name": "x", "stock_status": "onbackorder"},
        ))

    def test_search_results_that_are_all_out_of_stock_are_detected(self):
        signal = tcs._detect_lead_signal(
            "search_products", {"query": "ماگ"},
            {"live": True, "results": [
                {"name": "ماگ آبی", "stock_status": "outofstock"},
                {"name": "ماگ قرمز", "stock_status": "outofstock"},
            ]},
        )
        self.assertEqual(signal["mode"], "out_of_stock")
        self.assertEqual(signal["item"], "ماگ آبی")

    def test_search_results_with_one_in_stock_produce_nothing(self):
        # Something is buyable — there is no lost sale to rescue.
        self.assertIsNone(tcs._detect_lead_signal(
            "search_products", {"query": "ماگ"},
            {"live": True, "results": [
                {"name": "ماگ آبی", "stock_status": "outofstock"},
                {"name": "ماگ قرمز", "stock_status": "instock"},
            ]},
        ))


class NotInCatalogDetectionTest(unittest.TestCase):
    def test_a_live_lookup_that_found_nothing_is_not_in_catalog(self):
        signal = tcs._detect_lead_signal(
            "get_product_availability", {"sku": "MEDICUBE"},
            {"found": False, "live": True, "note": "not found"},
        )
        self.assertEqual(signal, {"mode": "not_in_catalog", "item": "MEDICUBE"})

    def test_an_empty_search_is_not_in_catalog_and_names_the_brand(self):
        signal = tcs._detect_lead_signal(
            "search_products", {"brand": "مدیکوب"},
            {"live": True, "count": 0, "results": []},
        )
        self.assertEqual(signal, {"mode": "not_in_catalog", "item": "مدیکوب"})

    def test_the_query_is_used_when_no_brand_was_given(self):
        signal = tcs._detect_lead_signal(
            "search_products", {"query": "ساعت هوشمند"},
            {"live": True, "results": []},
        )
        self.assertEqual(signal["item"], "ساعت هوشمند")


class NeverGuessesTest(unittest.TestCase):
    """A false "we don't stock that" costs the shop a customer AND a phone
    number they had no right to ask for."""

    def test_an_unreachable_store_is_never_reported_as_not_stocked(self):
        # live is False — the check did not actually run.
        self.assertIsNone(tcs._detect_lead_signal(
            "get_product_availability", {"sku": "X"},
            {"found": False, "live": False, "note": "unavailable"},
        ))
        self.assertIsNone(tcs._detect_lead_signal(
            "search_products", {"query": "x"},
            {"live": False, "error": "live_check_unavailable", "results": []},
        ))

    def test_an_error_result_produces_nothing(self):
        self.assertIsNone(tcs._detect_lead_signal(
            "get_product_availability", {"sku": "X"}, {"error": "Invalid SKU format."},
        ))

    def test_a_missing_stock_status_is_not_treated_as_out_of_stock(self):
        self.assertIsNone(tcs._detect_lead_signal(
            "get_product_availability", {"sku": "X"},
            {"found": True, "live": True, "name": "x", "stock_status": None},
        ))

    def test_an_unrelated_tool_produces_nothing(self):
        self.assertIsNone(tcs._detect_lead_signal(
            "create_payment_link", {}, {"items": [], "total": 0},
        ))

    def test_a_non_dict_result_produces_nothing(self):
        self.assertIsNone(tcs._detect_lead_signal("search_products", {}, None))

    def test_nothing_is_returned_when_there_is_no_item_name_to_report(self):
        # Without a name the lead would say "someone wants something".
        self.assertIsNone(tcs._detect_lead_signal(
            "search_products", {}, {"live": True, "results": []},
        ))


if __name__ == "__main__":
    unittest.main()
