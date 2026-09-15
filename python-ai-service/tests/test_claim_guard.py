"""
The assistant must not tell a customer it did something it did not do.

The reported case: with add_to_cart switched off, the answer said "it has been
added to your cart". Nothing was added -- there was no tool to add it with.

Three ways a turn ends up with no proof, and all three must behave the same:
the tool was off, the tool ran and returned an error, and the shop could not
be reached. Each is covered below.
"""
import unittest

from app.services.claim_guard import (
    ACTIONS, claims_in, proven_actions, verify_claims,
)


class ClaimDetectionTest(unittest.TestCase):
    def test_it_spots_each_action_in_persian(self):
        cases = {
            "cart_add": "محصول به سبد خرید شما اضافه شد.",
            "cart_link": "لینک سبد خرید شما آماده شد.",
            "payment_link": "لینک پرداخت شما آماده است.",
            "order_status": "سفارش شما را بررسی کردم.",
            "callback": "درخواست تماس شما ثبت شد.",
        }
        self.assertEqual(set(cases), set(ACTIONS), "a guarded action has no Persian case")
        for action, sentence in cases.items():
            self.assertIn(action, claims_in(sentence), sentence)

    def test_it_spots_each_action_in_english(self):
        cases = {
            "cart_add": "I added it to your cart.",
            "cart_link": "Your cart link is ready.",
            "payment_link": "The payment link is ready.",
            "order_status": "I checked your order.",
            "callback": "Your callback request has been registered.",
        }
        for action, sentence in cases.items():
            self.assertIn(action, claims_in(sentence), sentence)

    def test_an_offer_or_a_question_is_not_a_claim(self):
        # The guard must not eat the assistant offering to do something, or
        # explaining how -- only asserting it is done.
        for sentence in (
            "می‌خواهید این را به سبد خرید اضافه کنم؟",
            "برای افزودن به سبد، روی دکمه‌ی زیر بزنید.",
            "Would you like me to add it to your cart?",
            "Tap the button below to add it to your cart.",
            "برای پیگیری سفارش، شماره‌ی موبایلتان را بفرستید.",
        ):
            self.assertEqual(claims_in(sentence), set(), sentence)

    def test_ordinary_product_talk_is_untouched(self):
        for sentence in (
            "این ماگ ۵۵۰ میلی‌لیتر است و جنس آن استیل ۳۱۶ است.",
            "قیمت این محصول ۱۳۵٬۰۰۰ تومان است.",
            "This mug holds 550ml.",
        ):
            self.assertEqual(claims_in(sentence), set(), sentence)


class GuardWithoutProofTest(unittest.TestCase):
    """No proof, whatever the reason -- the claim goes."""

    def test_the_reported_case_tool_switched_off(self):
        # No tools ran at all, so no blocks and no results.
        answer, cut = verify_claims(
            "حتماً! محصول به سبد خرید شما اضافه شد. برای تکمیل خرید اقدام کنید.",
            proven_actions([], []),
            is_fa=True,
        )

        self.assertEqual(cut, ["cart_add"])
        self.assertNotIn("اضافه شد", answer)
        self.assertIn("اضافه نشد", answer)
        # The sentence that made no claim survives.
        self.assertIn("برای تکمیل خرید اقدام کنید.", answer)

    def test_tool_ran_but_returned_an_error(self):
        # _build_widget_block returns None for a result carrying "error",
        # so an errored tool reaches the guard with nothing to show for it.
        blocks, results = [], [{"error": "Invalid product_id in items."}]

        answer, cut = verify_claims(
            "به سبد خرید شما اضافه شد.", proven_actions(blocks, results), is_fa=True
        )

        self.assertEqual(cut, ["cart_add"])
        self.assertIn("اضافه نشد", answer)

    def test_the_shop_could_not_be_reached(self):
        results = [{"error": "live_check_unavailable",
                    "note": "Live order preview is unavailable right now.", "items": []}]

        answer, cut = verify_claims(
            "لینک پرداخت شما آماده است.", proven_actions([], results), is_fa=True
        )

        self.assertEqual(cut, ["payment_link"])
        self.assertIn("ساخته نشد", answer)

    def test_several_claims_in_one_answer_each_get_corrected_once(self):
        answer, cut = verify_claims(
            "به سبد خرید شما اضافه شد. لینک پرداخت آماده است. باز هم به سبد اضافه شد.",
            proven_actions([], []),
            is_fa=True,
        )

        self.assertEqual(sorted(set(cut)), ["cart_add", "payment_link"])
        self.assertEqual(answer.count("اضافه نشد"), 1)


class GuardWithProofTest(unittest.TestCase):
    def test_a_real_cart_link_may_be_claimed(self):
        blocks = [{"type": "cart_links", "items": [{"product_id": 12, "url": "https://shop.test/cart?add-to-cart=12"}]}]

        answer, cut = verify_claims(
            "لینک سبد خرید شما آماده شد.", proven_actions(blocks, []), is_fa=True
        )

        self.assertEqual(cut, [])
        self.assertIn("آماده شد", answer)

    def test_a_prepared_cart_is_still_not_an_added_cart(self):
        # add_to_cart succeeded, but nothing is in the cart until the
        # customer taps -- the loop calls this block "intent only".
        blocks = [{"type": "add_to_cart", "items": [{"product_id": 12}]}]

        _answer, cut = verify_claims(
            "به سبد خرید شما اضافه شد.", proven_actions(blocks, []), is_fa=True
        )

        self.assertEqual(cut, ["cart_add"])

    def test_a_price_preview_is_still_not_a_payment_link(self):
        blocks = [{"type": "payment_link_preview", "items": [{"product_id": 1}],
                   "total": 135000, "currency": "IRT"}]

        _answer, cut = verify_claims(
            "لینک پرداخت آماده است.", proven_actions(blocks, []), is_fa=True
        )

        self.assertEqual(cut, ["payment_link"])

    def test_an_otp_offer_is_not_a_completed_lookup(self):
        blocks = [{"type": "order_status_otp", "contact": "09120000000"}]

        _answer, cut = verify_claims(
            "سفارش شما را بررسی کردم.", proven_actions(blocks, []), is_fa=True
        )

        self.assertEqual(cut, ["order_status"])


class GuardEdgesTest(unittest.TestCase):
    def test_empty_and_none_are_safe(self):
        self.assertEqual(verify_claims("", []), ("", []))
        self.assertEqual(verify_claims(None, []), ("", []))

    def test_an_answer_with_no_claims_is_returned_intact(self):
        text = "این ماگ استیل ۳۱۶ است و ۵۵۰ میلی‌لیتر جا دارد."

        answer, cut = verify_claims(text, [], is_fa=True)

        self.assertEqual(cut, [])
        self.assertEqual(answer, text)


if __name__ == "__main__":
    unittest.main()
