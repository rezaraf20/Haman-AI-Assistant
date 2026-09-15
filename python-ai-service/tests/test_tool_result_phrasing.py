"""
Saying what a tool really did, instead of deleting what it did not.

Three tools prepare something the customer still has to act on, so reporting
them as finished is false even when they succeed. claim_guard removes such a
sentence, which is right when nothing happened and poor when something did --
the customer ends up told what did not happen rather than what did. So the
answering turn is given the true sentence, and the guard stays behind it.

The two layers must not fight. A prescribed sentence that the guard then
deletes would be the worst of both, so that is the first thing checked here.
"""
import unittest

from app.lang.tool_results import PHRASED_TOOLS, tool_result_phrase
from app.services.claim_guard import claims_in
from app.services.tool_calling_service import _phrasing_instruction


class PhraseTest(unittest.TestCase):
    def test_every_phrased_tool_has_both_languages(self):
        for tool in PHRASED_TOOLS:
            for is_fa in (True, False):
                phrase = tool_result_phrase(tool, is_fa=is_fa)
                self.assertTrue(phrase, f"{tool} fa={is_fa}")

    def test_the_three_tools_that_only_prepare_something(self):
        self.assertEqual(
            set(PHRASED_TOOLS),
            {"add_to_cart", "create_payment_link", "get_order_status"},
        )

    def test_a_tool_that_needs_no_wording_gets_none(self):
        # build_cart_url really does return a usable link, and the read-only
        # tools return facts to describe rather than an action to report.
        for tool in ("build_cart_url", "search_products", "get_product_availability"):
            self.assertIsNone(tool_result_phrase(tool), tool)

    def test_no_prescribed_sentence_is_one_the_guard_would_delete(self):
        # The two layers have to agree. The English payment wording once said
        # "the payment link is created once you confirm", which the guard read
        # as a completed payment link and cut.
        for tool in PHRASED_TOOLS:
            for is_fa in (True, False):
                phrase = tool_result_phrase(tool, is_fa=is_fa)
                self.assertEqual(claims_in(phrase), set(), phrase)

    def test_none_of_them_claims_the_action_is_done(self):
        for forbidden in ("اضافه شد", "اضافه کردیم", "ثبت شد"):
            self.assertNotIn(forbidden, tool_result_phrase("add_to_cart", is_fa=True))
        self.assertNotIn("added", tool_result_phrase("add_to_cart", is_fa=False).lower().split("tap")[0])


class InstructionTest(unittest.TestCase):
    def test_it_names_the_sentences_to_use(self):
        phrases = [tool_result_phrase("add_to_cart", is_fa=True)]

        instruction = _phrasing_instruction(phrases, is_fa=True)

        self.assertIn(phrases[0], instruction)
        self.assertTrue(instruction.startswith("\n\n"))

    def test_a_repeated_tool_is_listed_once(self):
        phrase = tool_result_phrase("add_to_cart", is_fa=True)

        instruction = _phrasing_instruction([phrase, phrase], is_fa=True)

        self.assertEqual(instruction.count(phrase), 1)

    def test_english_and_persian_instructions_differ(self):
        fa = _phrasing_instruction([tool_result_phrase("add_to_cart", is_fa=True)], is_fa=True)
        en = _phrasing_instruction([tool_result_phrase("add_to_cart", is_fa=False)], is_fa=False)

        self.assertIn("exactly these sentences", en)
        self.assertNotIn("exactly these sentences", fa)


if __name__ == "__main__":
    unittest.main()
