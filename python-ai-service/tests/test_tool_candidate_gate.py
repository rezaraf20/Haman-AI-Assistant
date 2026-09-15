"""
Which questions are worth a tool-deciding call.

Taking the retrieved CONTEXT out of the deciding turn means that turn can no
longer double as the answer -- it has nothing to ground one in -- so a message
that needs no tool costs a model call that ends in "nothing applies". Gating
on the intent removes that call for the questions that were never going to use
a tool, which is most of them.

The trade is explicit: a tool-worthy question the classifier mislabels now
skips tools entirely. That is why the candidate set is wider than the routing
table, and why "other" is the only bucket a shopping question is likely to
fall into wrongly.
"""
import unittest

from app.services.intent_classifier import INTENTS, classify_intent
from app.services.tool_router import TOOL_CANDIDATE_INTENTS, is_tool_candidate, route


class CandidateSetTest(unittest.TestCase):
    def test_every_candidate_is_a_real_intent(self):
        self.assertTrue(TOOL_CANDIDATE_INTENTS.issubset(set(INTENTS)))

    def test_the_intents_that_need_live_data(self):
        for intent in ("price", "availability", "comparison", "consultation", "order_status"):
            self.assertTrue(is_tool_candidate(intent), intent)

    def test_payment_is_a_candidate_even_though_it_is_never_routed(self):
        # create_payment_link needs the customer's say-so, so it is not
        # forced -- but a payment question is exactly when the model should
        # be offered it.
        self.assertTrue(is_tool_candidate("payment"))
        self.assertIsNone(route("payment", ["create_payment_link"], "لینک پرداخت بدید"))

    def test_the_intents_answered_from_indexed_content_are_not(self):
        for intent in ("shipping", "return", "authenticity", "technical_support", "other"):
            self.assertFalse(is_tool_candidate(intent), intent)

    def test_missing_and_unknown_intents_are_not_candidates(self):
        for intent in (None, "", "not_an_intent"):
            self.assertFalse(is_tool_candidate(intent), repr(intent))


class RealQuestionsTest(unittest.TestCase):
    def test_shopping_questions_reach_the_gate(self):
        for query in (
            "قمقمه مربع موجوده؟",
            "قیمت تراش دایناسور چنده؟",
            "برای هدیه تولد چی پیشنهاد می‌دید؟",
            "فرق قمقمه مربع با فنجون دسته اردک چیه؟",
            "سفارش من به کجا رسید؟",
        ):
            self.assertTrue(is_tool_candidate(classify_intent(query)), query)

    def test_questions_the_indexed_content_answers_do_not(self):
        for query in (
            "هزینه ارسال چقدره؟",
            "شرایط مرجوعی چیه؟",
            "سلام",
        ):
            self.assertFalse(is_tool_candidate(classify_intent(query)), query)

    def test_a_known_ambiguity_costs_one_wasted_call(self):
        # "چنده" asks both "how much does it cost" and "what is it", so
        # "ساعت کاری‌تون چنده؟" reads as a price question. Recorded rather
        # than special-cased: the word really is ambiguous without context,
        # and the cost of getting it wrong is one deciding call that finds
        # no tool, after which the question is answered from the indexed
        # pages exactly as before. Narrowing "چنده" to exclude it would risk
        # the real price questions it was added for.
        self.assertEqual(classify_intent("ساعت کاری‌تون چنده؟"), "price")
        self.assertTrue(is_tool_candidate("price"))


if __name__ == "__main__":
    unittest.main()
