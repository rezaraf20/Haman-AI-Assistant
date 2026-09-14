"""Intent classification against real production messages.

The classifier's own docstring promised an accuracy check against real
messages and it was never run. When it finally was, the answer was 53.3% on
30 real messages — the patterns missed "دارید", which is simply how the
question "do you have X?" is asked in Persian, and matched "مشاوره" only in
one fixed phrasing.

These cases are the ones that were wrong, pinned so they cannot regress. The
measured numbers, for the record:

    before the fix   16/30 = 53.3%   (availability 1/10, consultation 0/4)
    after, held out  20/25 = 80.0%   (consultation still 0/4)

The held-out number is the honest one: the 30-message set was what the
patterns were tuned against, so it scores 100% and means nothing on its own.
"""
import unittest

from app.services.intent_classifier import classify_intent


class AvailabilityPhrasingTest(unittest.TestCase):
    """"دارید" was absent entirely — the single biggest hole."""

    def test_do_you_have_is_availability(self):
        for msg in (
            "ماگ سبز هم دارید؟",
            "کیف غذا هم دارید؟",
            "سلام تراول ماگ خرس کلاه دار دارید؟",
            "تراول ماگ 380 میل هم دارید؟",
            "چه محصولاتی دارید؟",
            "موجود داری",
        ):
            self.assertEqual(classify_intent(msg), "availability", msg)

    def test_offering_a_service_counts_as_availability(self):
        # The busiest tenant sells services; "do you do X" is the same
        # question as "do you have X".
        for msg in (
            "طراحی سایت انجام میدین؟",
            "سئو هم انجام میدید؟",
            "اپلیکیشن هم طراحی میکنید؟",
            "فروشگاه اینترنتی هم طراحی میکنید؟",
        ):
            self.assertEqual(classify_intent(msg), "availability", msg)

    def test_a_negation_is_not_availability(self):
        # "ندارین" contains "دارین". Caught by the held-out set: a message
        # asking whether they DON'T have an Instagram channel was being
        # filed as a stock question.
        self.assertNotEqual(
            classify_intent("سلام، آدرس اینستا یا کانال تلگرام ندارین؟"),
            "availability",
        )

    def test_a_bare_dari_elsewhere_does_not_trigger_it(self):
        self.assertNotEqual(classify_intent("چی داری میگی اصلا"), "availability")


class ConsultationPhrasingTest(unittest.TestCase):

    def test_asking_for_advice_is_consultation(self):
        for msg in (
            "چطور میتونم مشاوره رایگان بگیرم؟",
            "امکان مشاوره رایگان هم هست؟",
            "با کی باید صحبت کنم برای قبمت و مشاوره؟",   # typo in the original
        ):
            self.assertEqual(classify_intent(msg), "consultation", msg)

    def test_describing_a_need_is_consultation(self):
        self.assertEqual(
            classify_intent("من دنبال چراغ مطالعه میگردم که شارژی باشه"),
            "consultation",
        )


class PricePhrasingTest(unittest.TestCase):

    def test_bare_chande_is_price(self):
        # "طراحی سایت چنده؟" carries neither قیمت nor هزینه.
        self.assertEqual(classify_intent("طراحی سایت چنده؟"), "price")

    def test_the_word_cost_alone_is_price(self):
        self.assertEqual(classify_intent("هزینه"), "price")

    def test_shipping_cost_stays_shipping(self):
        # price is checked after shipping on purpose; adding bare "هزینه"
        # must not steal this one.
        self.assertEqual(classify_intent("هزینه ارسال چقدر است؟"), "shipping")


class ContactQuestionsStayOtherTest(unittest.TestCase):
    """There is no contact intent, and these must not be dragged elsewhere."""

    def test_contact_details_are_other(self):
        for msg in (
            "شماره تماس رو بگو",
            "ایمیل رو بده",
            "ادرس شرکت کجاست",
            "مدیرعامل هامان تک کیه؟",
            "اسم برندتون چیه؟",
            "ساعت کاری شما چیه؟",
        ):
            self.assertEqual(classify_intent(msg), "other", msg)


class DegradesSafelyTest(unittest.TestCase):

    def test_empty_and_odd_input(self):
        for value in (None, "", "   ", 12345, [], "?????"):
            self.assertEqual(classify_intent(value), "other", repr(value))


if __name__ == "__main__":
    unittest.main()
