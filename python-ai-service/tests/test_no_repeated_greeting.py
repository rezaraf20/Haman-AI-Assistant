"""
Regression test for a real report on the live hamantech.ir chatbot: a
customer asked three questions back to back in one conversation, and all
three replies opened with "سلام!" ("Hello!") — the model has no built-in
sense of "we're already mid-conversation" unless the prompt says so, every
turn. The same missing signal let a merchant's own free-text
system_prompt (WidgetSettings' "System Instruction" field, explicitly
described to merchants as a place for "things to always mention") repeat a
closing signature/contact-info block at the end of every reply too.

Like test_grounding_no_fabricated_claims.py, this can't verify an LLM's
actual output deterministically — it verifies the *prompt this code sends
the LLM* now explicitly says whether this is the first turn or not, with
the instruction to greet only when it genuinely is. Real-output
verification happens separately against the live server per the report's
acceptance criteria.
"""
import unittest
from unittest.mock import patch

from app.services.rag_service import _grounding_reminder, _turn_awareness_rule, run_rag_pipeline


class TurnAwarenessRuleTest(unittest.TestCase):
    def test_first_turn_english_permits_a_greeting(self):
        rule = _turn_awareness_rule(prior_turn_count=0, is_fa=False)
        self.assertIn("first message", rule)
        self.assertIn("you may open your reply with a brief greeting", rule)

    def test_first_turn_persian_permits_a_greeting(self):
        rule = _turn_awareness_rule(prior_turn_count=0, is_fa=True)
        self.assertIn("اولین پیام این مکالمه است", rule)
        self.assertIn("احوال‌پرسی", rule)

    def test_later_turn_english_forbids_greeting_and_signature(self):
        rule = _turn_awareness_rule(prior_turn_count=2, is_fa=False)
        self.assertIn("message #3", rule)
        self.assertIn("Do not open your reply with", rule)
        self.assertIn("not at the end of every reply", rule)

    def test_later_turn_persian_forbids_greeting_and_signature(self):
        rule = _turn_awareness_rule(prior_turn_count=2, is_fa=True)
        self.assertIn("پیام شماره 3", rule)
        self.assertIn("با «سلام»", rule)
        self.assertIn("نه در انتهای هر پاسخ", rule)

    def test_reminder_stays_quiet_on_the_first_turn(self):
        self.assertNotIn("already under way", _grounding_reminder(is_fa=False, is_first_turn=True))
        self.assertNotIn("در جریان است", _grounding_reminder(is_fa=True, is_first_turn=True))

    def test_reminder_repeats_the_no_regreet_rule_on_later_turns(self):
        self.assertIn("do not greet again", _grounding_reminder(is_fa=False, is_first_turn=False))
        self.assertIn("دوباره سلام نکن", _grounding_reminder(is_fa=True, is_first_turn=False))


class ThreeMessagesOneGreetingTest(unittest.IsolatedAsyncioTestCase):
    """The exact reported scenario: three questions back to back in one
    conversation. Only the first prompt may allow a greeting; the second
    and third must explicitly forbid one."""

    async def _prompt_for_turn(self, history):
        captured = {}

        def fake_chat_completion(db, prompt, max_tokens, temperature):
            captured["prompt"] = prompt
            return ("پاسخ آزمایشی", "test/model", {"prompt_tokens": 1, "completion_tokens": 1, "total_tokens": 2}, 0.0)

        with patch("app.services.rag_service._embed_query", return_value=[0.0] * 8), \
             patch("app.services.rag_service.hybrid_retrieve", return_value={
                 "chunks": [], "is_unanswered": False, "rerank_cost_toman": 0.0, "rerank_usage": {},
             }), \
             patch("app.services.rag_service._chat_completion", side_effect=fake_chat_completion):
            await run_rag_pipeline(
                db=None, chatbot_id="c1", query="سوال بعدی؟", history=history,
                system_prompt=None, fallback_resp=None, llm_model="m",
                top_k=8, threshold=0.6, temperature=0.0, max_tokens=800,
                language="fa", business_name="هامان تک",
            )

        return captured["prompt"]

    async def test_only_the_first_of_three_messages_may_greet(self):
        # Turn 1: nothing has been said yet.
        prompt_1 = await self._prompt_for_turn(history=[])
        self.assertIn("اولین پیام این مکالمه است", prompt_1)

        # Turn 2: one prior exchange already happened. Laravel's own
        # history always carries the just-asked query as its last "user"
        # entry too (see _turn_awareness_rule()'s docstring) — reproduced
        # here on purpose, since that's the real shape this code receives.
        prompt_2 = await self._prompt_for_turn(history=[
            {"role": "user", "content": "سوال اول؟"},
            {"role": "assistant", "content": "پاسخ اول"},
            {"role": "user", "content": "سوال بعدی؟"},
        ])
        self.assertIn("پیام شماره 2 در این مکالمه است، نه اولین پیام", prompt_2)
        self.assertNotIn("اولین پیام این مکالمه است", prompt_2)

        # Turn 3: two prior exchanges.
        prompt_3 = await self._prompt_for_turn(history=[
            {"role": "user", "content": "سوال اول؟"},
            {"role": "assistant", "content": "پاسخ اول"},
            {"role": "user", "content": "سوال دوم؟"},
            {"role": "assistant", "content": "پاسخ دوم"},
            {"role": "user", "content": "سوال بعدی؟"},
        ])
        self.assertIn("پیام شماره 3 در این مکالمه است، نه اولین پیام", prompt_3)
        self.assertNotIn("اولین پیام این مکالمه است", prompt_3)


if __name__ == "__main__":
    unittest.main()
