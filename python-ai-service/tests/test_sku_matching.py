"""
Feature test for SKU/part-number lookup — see the docstrings on
SKU_CANDIDATE_PATTERN / _lookup_by_sku / _try_sku_shortcut in rag_service.py
for the full rationale. Real-world trigger: an electronics-parts shop
customer typing their own part number ("do you have LM358N?") — a vector
embedding of a bare alphanumeric string carries almost no useful semantic
signal, so this runs as a regex-detected exact/fuzzy DB lookup *before* any
retrieval, never via an LLM call.

Mocking convention follows test_grounding_business_name.py: DB-touching
functions are exercised against a MagicMock db (not a real connection), and
_try_sku_shortcut is called with conversation_id=None so _log_event's
best-effort logging short-circuits before touching db at all — isolating
the test to the SKU-matching logic itself.
"""
import unittest
from collections import namedtuple
from unittest.mock import MagicMock

from app.services.rag_service import (
    SKU_CANDIDATE_PATTERN,
    _extract_sku_candidates,
    _normalize_sku,
    _lookup_by_sku,
    _sku_exact_response,
    _sku_fuzzy_response,
    _try_sku_shortcut,
)

ProductRow = namedtuple("ProductRow", ["id", "name", "sku", "price", "currency", "stock_status", "permalink"])


class RegexDetectionTest(unittest.TestCase):
    def test_bare_part_number_detected(self):
        self.assertEqual(_extract_sku_candidates("LM358N"), ["LM358N"])

    def test_part_number_inside_a_sentence_detected(self):
        self.assertIn("LM358N", _extract_sku_candidates("Do you have LM358N in stock?"))

    def test_part_number_inside_a_persian_sentence_detected(self):
        self.assertIn("LM358N", _extract_sku_candidates("آیا LM358N رو دارید؟"))

    def test_hyphenated_lowercase_variant_detected(self):
        self.assertIn("lm358-n", _extract_sku_candidates("do you have lm358-n?"))

    def test_plain_word_not_detected(self):
        self.assertEqual(_extract_sku_candidates("hello world"), [])

    def test_pure_number_not_detected(self):
        self.assertEqual(_extract_sku_candidates("12345678"), [])

    def test_too_short_token_not_detected(self):
        self.assertEqual(_extract_sku_candidates("A1 B2"), [])

    def test_ordinary_question_yields_no_candidates(self):
        self.assertEqual(_extract_sku_candidates("What are your business hours?"), [])


class NormalizationTest(unittest.TestCase):
    def test_uppercase_unchanged(self):
        self.assertEqual(_normalize_sku("LM358N"), "LM358N")

    def test_hyphen_stripped(self):
        self.assertEqual(_normalize_sku("LM358-N"), "LM358N")

    def test_lowercase_and_hyphen_both_normalized(self):
        self.assertEqual(_normalize_sku("lm358-n"), "LM358N")

    def test_internal_space_stripped(self):
        self.assertEqual(_normalize_sku("LM358 N"), "LM358N")

    def test_all_variants_converge(self):
        variants = ["LM358N", "lm358n", "LM358-N", "lm358-n", "LM358 N"]
        normalized = {_normalize_sku(v) for v in variants}
        self.assertEqual(normalized, {"LM358N"})


class LookupBySkuTest(unittest.TestCase):
    def test_no_candidate_token_returns_none_without_touching_db(self):
        db = MagicMock()
        result = _lookup_by_sku(db, "chatbot-1", "what are your business hours?")
        self.assertIsNone(result)
        db.execute.assert_not_called()

    def test_exact_match(self):
        db = MagicMock()
        row = ProductRow(id="p1", name="LM358 Op-Amp", sku="LM358N", price=15000,
                          currency="IRT", stock_status="instock", permalink="https://shop.example/lm358n")
        db.execute.return_value.fetchone.return_value = row
        result = _lookup_by_sku(db, "chatbot-1", "do you have LM358N?")
        self.assertEqual(result["match_type"], "exact")
        self.assertEqual(result["product"]["sku"], "LM358N")

    def test_exact_match_ignores_hyphen_and_case(self):
        db = MagicMock()
        row = ProductRow(id="p1", name="LM358 Op-Amp", sku="LM358N", price=15000,
                          currency="IRT", stock_status="instock", permalink="https://shop.example/lm358n")
        db.execute.return_value.fetchone.return_value = row
        result = _lookup_by_sku(db, "chatbot-1", "do you have lm358-n?")
        self.assertEqual(result["match_type"], "exact")
        # the normalized form sent to the query must match regardless of input casing/hyphens
        params = db.execute.call_args[0][1]
        self.assertEqual(params["norm"], "LM358N")

    def test_fuzzy_match_when_no_exact_hit(self):
        db = MagicMock()
        exact_miss = MagicMock()
        exact_miss.fetchone.return_value = None
        near = ProductRow(id="p2", name="LM358AN Op-Amp", sku="LM358AN", price=17000,
                           currency="IRT", stock_status="instock", permalink="https://shop.example/lm358an")
        fuzzy_hit = MagicMock()
        fuzzy_hit.fetchall.return_value = [near]
        db.execute.side_effect = [exact_miss, fuzzy_hit]

        result = _lookup_by_sku(db, "chatbot-1", "do you have LM358Z?")
        self.assertEqual(result["match_type"], "fuzzy")
        self.assertEqual(result["products"][0]["sku"], "LM358AN")

    def test_nonexistent_sku_returns_none_match_type_not_a_wrong_suggestion(self):
        db = MagicMock()
        exact_miss = MagicMock()
        exact_miss.fetchone.return_value = None
        fuzzy_miss = MagicMock()
        fuzzy_miss.fetchall.return_value = []
        db.execute.side_effect = [exact_miss, fuzzy_miss]

        result = _lookup_by_sku(db, "chatbot-1", "do you have XYZQWERTY9999?")
        self.assertEqual(result["match_type"], "none")


class ResponseTemplateTest(unittest.TestCase):
    """The bit that matters most per the acceptance criteria: a fuzzy
    suggestion must never read like a confident exact answer."""

    def test_exact_response_states_availability(self):
        product = {"name": "LM358 Op-Amp", "sku": "LM358N", "price": 15000,
                   "currency": "IRT", "stock_status": "instock", "permalink": "https://shop.example/lm358n"}
        self.assertIn("LM358N", _sku_exact_response(product, is_fa=False))

    def test_fuzzy_response_explicitly_flags_not_exact_english(self):
        products = [{"name": "LM358AN Op-Amp", "sku": "LM358AN", "price": 17000,
                     "currency": "IRT", "stock_status": "instock", "permalink": None}]
        resp = _sku_fuzzy_response(products, "LM358Z", is_fa=False)
        self.assertIn("not an exact match", resp)

    def test_fuzzy_response_explicitly_flags_not_exact_persian(self):
        products = [{"name": "LM358AN Op-Amp", "sku": "LM358AN", "price": 17000,
                     "currency": "IRT", "stock_status": "instock", "permalink": None}]
        resp = _sku_fuzzy_response(products, "LM358Z", is_fa=True)
        self.assertIn("تطبیق دقیق نیست", resp)


class TrySkuShortcutTest(unittest.TestCase):
    """conversation_id=None keeps _log_event's best-effort logging from
    touching db at all, isolating this to the shortcut's own control flow."""

    def test_exact_hit_marks_answered(self):
        db = MagicMock()
        row = ProductRow(id="p1", name="LM358 Op-Amp", sku="LM358N", price=15000,
                          currency="IRT", stock_status="instock", permalink="https://shop.example/lm358n")
        db.execute.return_value.fetchone.return_value = row
        result = _try_sku_shortcut(db, "chatbot-1", None, "do you have LM358N?", start=0.0)
        self.assertIsNotNone(result)
        self.assertFalse(result["is_unanswered"])
        self.assertEqual(result["finish_reason"], "sku_exact")

    def test_fuzzy_hit_marks_unanswered_despite_giving_a_suggestion(self):
        db = MagicMock()
        exact_miss = MagicMock()
        exact_miss.fetchone.return_value = None
        near = ProductRow(id="p2", name="LM358AN Op-Amp", sku="LM358AN", price=17000,
                           currency="IRT", stock_status="instock", permalink=None)
        fuzzy_hit = MagicMock()
        fuzzy_hit.fetchall.return_value = [near]
        db.execute.side_effect = [exact_miss, fuzzy_hit]

        result = _try_sku_shortcut(db, "chatbot-1", None, "do you have LM358Z?", start=0.0)
        self.assertIsNotNone(result)
        self.assertTrue(result["is_unanswered"])
        self.assertEqual(result["finish_reason"], "sku_fuzzy")

    def test_nonexistent_sku_falls_through_to_normal_pipeline(self):
        db = MagicMock()
        exact_miss = MagicMock()
        exact_miss.fetchone.return_value = None
        fuzzy_miss = MagicMock()
        fuzzy_miss.fetchall.return_value = []
        db.execute.side_effect = [exact_miss, fuzzy_miss]

        result = _try_sku_shortcut(db, "chatbot-1", None, "do you have XYZQWERTY9999?", start=0.0)
        self.assertIsNone(result)

    def test_no_candidate_falls_through_without_touching_db(self):
        db = MagicMock()
        result = _try_sku_shortcut(db, "chatbot-1", None, "what are your business hours?", start=0.0)
        self.assertIsNone(result)
        db.execute.assert_not_called()


if __name__ == "__main__":
    unittest.main()
