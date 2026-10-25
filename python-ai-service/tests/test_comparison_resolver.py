"""
comparison_resolver.py — regression tests for the 2026-10-25 incident:
"فلاسک ۸۰۰ را با فلاسم ۶۰۰ مقایسه کن" (compare flask 800 with [typo'd]
flask 600) ended up comparing two catalog listings of the SAME 800ml
flask (one with Persian digits in its title, one with Latin), because the
old flow forced exactly one generic search_products call and let its two
results stand in for "the two things to compare" -- the customer's actual
second product was never looked up at all.

split_comparison_entities() is tested on real sentence shapes (including
the exact reported one); resolve_comparison_entities() is tested against
a faked live search per entity, the same fake_live_query() fixture every
other product_tools test uses.
"""
import unittest
from unittest.mock import MagicMock

from app.services.comparison_resolver import (
    normalize_digits, split_comparison_entities, resolve_comparison_entities,
)
from tests._safe_http_test_utils import fake_live_query


class NormalizeDigitsTest(unittest.TestCase):
    def test_persian_digits_become_ascii(self):
        self.assertEqual(normalize_digits("۸۰۰"), "800")

    def test_arabic_indic_digits_become_ascii(self):
        self.assertEqual(normalize_digits("٨٠٠"), "800")

    def test_already_ascii_is_unchanged(self):
        self.assertEqual(normalize_digits("800"), "800")

    def test_mixed_text_only_digits_translate(self):
        self.assertEqual(normalize_digits("فلاسک ۸۰۰ میل"), "فلاسک 800 میل")


class SplitComparisonEntitiesTest(unittest.TestCase):
    def test_the_reported_sentence(self):
        entities = split_comparison_entities("فلاسک ۸۰۰ را با فلاسم ۶۰۰ مقایسه کن")
        self.assertEqual(entities, ["فلاسک 800", "فلاسم 600"])

    def test_vs_separator(self):
        entities = split_comparison_entities("Flask 800 vs Flask 600")
        self.assertEqual(entities, ["Flask 800", "Flask 600"])

    def test_dar_barabar_separator(self):
        entities = split_comparison_entities("ماگ آبی در برابر ماگ قرمز را مقایسه کن")
        self.assertEqual(entities, ["ماگ آبی", "ماگ قرمز"])

    def test_plain_va_separator_with_the_fargh_chist_question_form(self):
        # "فرق A و B چیست؟" ("what's the difference between A and B?") is
        # at least as common in real messages as the imperative "مقایسه
        # کن" form, so both the leading "فرق" and the trailing "چیست" must
        # be recognized as noise, not part of either product's name.
        entities = split_comparison_entities("فرق ماگ آبی و ماگ قرمز چیست")
        self.assertEqual(entities, ["ماگ آبی", "ماگ قرمز"])

    def test_no_separator_returns_none(self):
        self.assertIsNone(split_comparison_entities("این محصول چقدر است"))

    def test_empty_query_returns_none(self):
        self.assertIsNone(split_comparison_entities(""))
        self.assertIsNone(split_comparison_entities(None))

    def test_separator_with_no_real_second_half_returns_none(self):
        # "و" at the very end of the sentence, nothing meaningful after it.
        self.assertIsNone(split_comparison_entities("ماگ آبی و"))


class ResolveComparisonEntitiesTest(unittest.TestCase):
    def _db_with_site(self):
        db = MagicMock()
        site_row = MagicMock(primary_domain="shop.example.com", webhook_secret="s3cret")
        db.execute.return_value.fetchone.return_value = site_row
        return db

    def test_single_strong_match_resolves(self):
        db = self._db_with_site()
        fake_response = MagicMock(status_code=200)
        fake_response.json.return_value = {
            "count": 1, "currency": "IRT",
            "results": [{"product_id": 42, "name": "فلاسک 800 میل", "price": 345000, "stock_status": "instock"}],
        }
        with fake_live_query(return_value=fake_response):
            out = resolve_comparison_entities(db, "chatbot-1", ["فلاسک 800"])
        self.assertEqual(out[0]["status"], "resolved")
        self.assertEqual(out[0]["product_id"], 42)

    def test_the_reported_incident_resolves_both_sides_independently(self):
        """The actual two searches from the live incident: "فلاسک 800"
        finds the two duplicate 800ml listings (ambiguous -- neither is
        confidently the one meant, since the same name scored against two
        near-identical matches), "فلاسم 600" (typo) finds the real 600ml
        flask by fuzzy match despite the typo."""
        db = self._db_with_site()

        resp_800 = MagicMock(status_code=200)
        resp_800.json.return_value = {
            "count": 2, "currency": "IRT",
            "results": [
                {"product_id": 2149, "name": "فلاسک ۸۰۰ میل", "price": 365000, "stock_status": "instock"},
                {"product_id": 1975, "name": "فلاسک 800 میل", "price": 345000, "stock_status": "instock"},
            ],
        }
        with fake_live_query(return_value=resp_800):
            out_800 = resolve_comparison_entities(db, "chatbot-1", ["فلاسک 800"])
        self.assertEqual(out_800[0]["status"], "ambiguous")
        self.assertEqual({c["product_id"] for c in out_800[0]["candidates"]}, {2149, 1975})

        resp_600 = MagicMock(status_code=200)
        resp_600.json.return_value = {
            "count": 1, "currency": "IRT",
            "results": [{"product_id": 3001, "name": "فلاسک 600 میل", "price": 310000, "stock_status": "instock"}],
        }
        with fake_live_query(return_value=resp_600):
            out_600 = resolve_comparison_entities(db, "chatbot-1", ["فلاسم 600"])
        self.assertEqual(out_600[0]["status"], "resolved")
        self.assertEqual(out_600[0]["product_id"], 3001)

    def test_zero_results_is_not_found(self):
        db = self._db_with_site()
        fake_response = MagicMock(status_code=200)
        fake_response.json.return_value = {"count": 0, "currency": "IRT", "results": []}
        with fake_live_query(return_value=fake_response):
            out = resolve_comparison_entities(db, "chatbot-1", ["محصول عجیب غریب"])
        self.assertEqual(out[0]["status"], "not_found")

    def test_results_that_dont_resemble_the_entity_at_all_are_not_found(self):
        # A weak/unrelated search response shouldn't be forced into a match.
        db = self._db_with_site()
        fake_response = MagicMock(status_code=200)
        fake_response.json.return_value = {
            "count": 1, "currency": "IRT",
            "results": [{"product_id": 7, "name": "کیف چرم زنانه", "price": 500000, "stock_status": "instock"}],
        }
        with fake_live_query(return_value=fake_response):
            out = resolve_comparison_entities(db, "chatbot-1", ["فلاسک 800"])
        self.assertEqual(out[0]["status"], "not_found")

    def test_live_failure_is_unavailable_not_not_found(self):
        from app.services.tools import product_tools
        db = self._db_with_site()
        with fake_live_query(side_effect=product_tools._requests.RequestException("timed out")):
            out = resolve_comparison_entities(db, "chatbot-1", ["فلاسک 800"])
        self.assertEqual(out[0]["status"], "unavailable")

    def test_no_site_on_file_is_unavailable(self):
        db = MagicMock()
        db.execute.return_value.fetchone.return_value = None
        out = resolve_comparison_entities(db, "chatbot-1", ["فلاسک 800"])
        self.assertEqual(out[0]["status"], "unavailable")


if __name__ == "__main__":
    unittest.main()
