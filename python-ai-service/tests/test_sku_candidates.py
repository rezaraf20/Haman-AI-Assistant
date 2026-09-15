"""
Which tokens in a question count as a part number.

The fast path was answering nothing for the customer who needs it most. Two
separate breaks: the products table it reads was empty, and this extractor
would not have recognised that shop's part numbers anyway. It requires a
letter, on the reasoning that a bare number is a year or an order number --
true of a loose number, false of "440-128", which is how 148 of that
catalogue's 173 products are numbered.

So a grouped digit token counts, and a bare one counts only when the customer
labels it. The cases that must NOT count are the point of this file: an order
number, a tracking code, a discount code and a date are all digits too.
"""
import unittest

from app.services.rag_service import _extract_sku_candidates, _normalize_sku


class PartNumberShapesTest(unittest.TestCase):
    def test_the_original_alphanumeric_shape_still_works(self):
        self.assertEqual(_extract_sku_candidates("LM358N دارید؟"), ["LM358N"])
        self.assertIn("ABC-1234", _extract_sku_candidates("do you have ABC-1234?"))

    def test_a_grouped_numeric_part_number(self):
        self.assertEqual(_extract_sku_candidates("440-128 موجوده؟"), ["440-128"])
        self.assertEqual(_extract_sku_candidates("قیمت 440-637 چنده؟"), ["440-637"])

    def test_a_bare_number_counts_only_when_it_is_labelled(self):
        self.assertEqual(_extract_sku_candidates("440128 موجوده؟"), [])
        self.assertEqual(_extract_sku_candidates("کد 440128 رو دارید؟"), ["440128"])
        self.assertEqual(_extract_sku_candidates("کد محصول 440128"), ["440128"])
        self.assertEqual(_extract_sku_candidates("SKU 440128"), ["440128"])

    def test_it_normalizes_to_what_the_sync_stored(self):
        # products.sku_normalized is written by App\Support\SkuNormalizer;
        # both hyphenated and bare forms have to land on the same value.
        self.assertEqual(_normalize_sku("440-128"), "440128")
        self.assertEqual(_normalize_sku("440128"), "440128")


class NotAPartNumberTest(unittest.TestCase):
    def test_an_order_number_is_not_a_part_number(self):
        self.assertEqual(_extract_sku_candidates("سفارش 1407 من کجاست؟"), [])
        self.assertEqual(_extract_sku_candidates("کد سفارش 440128 رو دارم"), [])
        self.assertEqual(_extract_sku_candidates("کد پیگیری 123456"), [])
        self.assertEqual(_extract_sku_candidates("کد تخفیف 123456 کار نمیکنه"), [])

    def test_a_date_is_not_a_part_number(self):
        self.assertEqual(_extract_sku_candidates("تاریخ 2026-09-15"), [])

    def test_a_phone_number_is_not_a_part_number(self):
        self.assertEqual(_extract_sku_candidates("شماره‌ام 0912-111-2233 است"), [])

    def test_plain_questions_have_no_candidates(self):
        for query in ("ماگ دارید؟", "قیمت چنده؟", "سلام", "برای هدیه چی پیشنهاد می‌دید؟"):
            self.assertEqual(_extract_sku_candidates(query), [], query)


if __name__ == "__main__":
    unittest.main()
