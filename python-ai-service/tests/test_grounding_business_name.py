"""
Regression test for a real production bug: a live chatbot answered "what's
your company name?" with a fabricated competitor-sounding name. Investigation
found the actual root cause was polluted scraped content (a theme/widget
identifier that looked like a company name), not purely a prompt-adherence
gap — but the prompt itself also had no explicit rule against inventing a
business name when one isn't known, and used only an English grounding
prompt regardless of the question's language.

This test can't verify an LLM's actual behavior (that requires a real call,
done separately against the live server per the incident's acceptance
criteria) — it verifies the *prompt this code sends the LLM* is actually
built the way the fix intends: language picked from the question, and an
explicit "don't invent a name" instruction present whenever business_name
isn't configured.
"""
import unittest
from unittest.mock import patch

from app.services.rag_service import (
    _looks_persian,
    _grounding_rules_for,
    _business_name_rule,
    _grounding_reminder,
    GROUNDING_RULES,
    GROUNDING_RULES_FA,
    run_rag_pipeline,
)


class LanguageDetectionTest(unittest.TestCase):
    def test_persian_question_detected(self):
        self.assertTrue(_looks_persian("اسم شرکت چیه؟"))

    def test_english_question_not_detected_as_persian(self):
        self.assertFalse(_looks_persian("What is your company name?"))

    def test_grounding_rules_pick_fa_for_persian_question(self):
        self.assertEqual(_grounding_rules_for("اسم شرکت چیه؟"), GROUNDING_RULES_FA)

    def test_grounding_rules_pick_en_for_english_question(self):
        self.assertEqual(_grounding_rules_for("What is your company name?"), GROUNDING_RULES)


class BusinessNameRuleTest(unittest.TestCase):
    def test_no_business_name_yields_no_extra_rule(self):
        self.assertEqual(_business_name_rule(None, is_fa=False), "")
        self.assertEqual(_business_name_rule("", is_fa=False), "")

    def test_business_name_injected_verbatim_english(self):
        rule = _business_name_rule("HamanTech", is_fa=False)
        self.assertIn("HamanTech", rule)

    def test_business_name_injected_verbatim_persian(self):
        rule = _business_name_rule("هامان تک", is_fa=True)
        self.assertIn("هامان تک", rule)


class GroundingRulesContentTest(unittest.TestCase):
    """The specific instruction that's actually new: don't invent a company
    name when you don't have one — this is what was missing before the fix
    (the old rules only forbade inventing *facts*, not a business identity)."""

    def test_english_rules_forbid_inventing_a_name(self):
        self.assertIn("never invent or guess one", GROUNDING_RULES)

    def test_persian_rules_forbid_inventing_a_name(self):
        self.assertIn("هرگز نام هیچ شرکتی را نساز", GROUNDING_RULES_FA)

    def test_reminder_repeats_the_rule_in_both_languages(self):
        self.assertIn("company name", _grounding_reminder(is_fa=False))
        self.assertIn("نام شرکتی", _grounding_reminder(is_fa=True))


class PromptBuildsWithoutInventingNameTest(unittest.IsolatedAsyncioTestCase):
    """End-to-end (within this process) check of the actual prompt handed to
    the LLM for the exact reported scenario: no business_name configured, no
    catalog content retrieved for the question. Mocks only the network calls
    (embedding + LLM), not the prompt-building logic itself."""

    async def test_empty_context_prompt_tells_model_not_to_invent_a_name(self):
        captured = {}

        def fake_chat_completion(db, prompt, max_tokens, temperature):
            captured["prompt"] = prompt
            return ("من نمی‌دانم.", "test/model", {"prompt_tokens": 1, "completion_tokens": 1, "total_tokens": 2}, 0.0)

        with patch("app.services.rag_service._embed_query", return_value=[0.0] * 8), \
             patch("app.services.rag_service.hybrid_retrieve", return_value={
                 "chunks": [], "is_unanswered": False, "rerank_cost_toman": 0.0, "rerank_usage": {},
             }), \
             patch("app.services.rag_service._chat_completion", side_effect=fake_chat_completion):
            await run_rag_pipeline(
                db=None, chatbot_id="c1", query="اسم شرکت چیه؟", history=[],
                system_prompt=None, fallback_resp=None, llm_model="m",
                top_k=8, threshold=0.6, temperature=0.0, max_tokens=100,
                language="fa", business_name=None,
            )

        prompt = captured["prompt"]
        self.assertIn("هرگز نام هیچ شرکتی را نساز", prompt)
        self.assertNotIn("HamanCo", prompt)


if __name__ == "__main__":
    unittest.main()
