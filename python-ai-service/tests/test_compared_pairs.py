"""
_log_compared_pairs() — records which products were put in front of a
customer together, so the Trends report can show what customers treat as
substitutes and which one wins.

Two event types on purpose: compared_pair is the customer explicitly
asking "which of these", co_presented is the bot showing options side by
side. The second is a real signal but a weaker one, and a merchant
reading the report should be able to tell them apart.
"""
import unittest
from unittest.mock import MagicMock, patch

from app.services import tool_calling_service as tcs


class ComparedPairsTest(unittest.TestCase):
    def _logged(self, products, event_type="compared_pair"):
        with patch("app.services.rag_service._log_event") as mock_log:
            tcs._log_compared_pairs(MagicMock(), "conv-1", "bot-1", products, event_type)
        return [
            {"event": c[0][3], "payload": c[0][4]}
            for c in mock_log.call_args_list
        ]

    def test_two_products_produce_one_pair(self):
        logged = self._logged([
            {"product_id": 10, "name": "ماگ آبی"},
            {"product_id": 20, "name": "ماگ قرمز"},
        ])
        self.assertEqual(len(logged), 1)
        self.assertEqual(logged[0]["event"], "compared_pair")
        self.assertEqual(logged[0]["payload"]["product_ids"], [10, 20])
        self.assertEqual(logged[0]["payload"]["names"], ["ماگ آبی", "ماگ قرمز"])

    def test_three_products_produce_every_pairing(self):
        # With three on screen the customer is weighing three matchups, and
        # (B,C) may be the one that actually decides it.
        logged = self._logged([
            {"product_id": 10, "name": "A"},
            {"product_id": 20, "name": "B"},
            {"product_id": 30, "name": "C"},
        ])
        pairs = sorted(tuple(e["payload"]["product_ids"]) for e in logged)
        self.assertEqual(pairs, [(10, 20), (10, 30), (20, 30)])

    def test_five_products_produce_ten_pairs(self):
        logged = self._logged([{"product_id": i, "name": str(i)} for i in range(1, 6)])
        self.assertEqual(len(logged), 10)

    def test_pair_order_is_stable_regardless_of_input_order(self):
        forward = self._logged([
            {"product_id": 10, "name": "A"}, {"product_id": 20, "name": "B"},
        ])
        reverse = self._logged([
            {"product_id": 20, "name": "B"}, {"product_id": 10, "name": "A"},
        ])
        self.assertEqual(forward[0]["payload"]["product_ids"], reverse[0]["payload"]["product_ids"])
        self.assertEqual(forward[0]["payload"]["names"], reverse[0]["payload"]["names"])

    def test_recommendations_are_logged_as_co_presented(self):
        logged = self._logged(
            [{"product_id": 10, "name": "A"}, {"product_id": 20, "name": "B"}],
            event_type="co_presented",
        )
        self.assertEqual(logged[0]["event"], "co_presented")

    def test_a_single_product_is_not_a_comparison(self):
        self.assertEqual(self._logged([{"product_id": 10, "name": "A"}]), [])

    def test_an_empty_set_logs_nothing(self):
        self.assertEqual(self._logged([]), [])

    def test_a_product_that_was_not_found_is_excluded(self):
        # compare_products marks a requested-but-missing id this way; it was
        # never shown, so it was never compared against anything.
        logged = self._logged([
            {"product_id": 10, "name": "A"},
            {"product_id": 20, "name": "B"},
            {"product_id": 99, "found": False},
        ])
        pairs = sorted(tuple(e["payload"]["product_ids"]) for e in logged)
        self.assertEqual(pairs, [(10, 20)])

    def test_products_without_an_id_are_skipped(self):
        logged = self._logged([
            {"product_id": 10, "name": "A"},
            {"name": "no id at all"},
        ])
        self.assertEqual(logged, [])


if __name__ == "__main__":
    unittest.main()
