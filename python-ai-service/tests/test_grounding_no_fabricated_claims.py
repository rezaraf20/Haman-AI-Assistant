"""
Regression test for a real incident on the live hamantech.ir chatbot: asked
"do you sell a chatbot product?", the bot claimed integration with "Shopify
and Magento" and a "3x conversion rate increase" — neither claim exists
anywhere in the indexed content (confirmed by grepping the actual chunks
table on the live tenant schema; the source document only discusses generic
chatbot ROI stats — 40% fewer support calls, cart abandonment 65%->48%, 15%
AOV increase — none of which mention a named e-commerce platform or a "3x"
figure at all).

Like test_grounding_business_name.py, this can't verify an LLM's actual
output deterministically — it verifies the *prompt this code sends the
LLM* now explicitly forbids inventing third-party platform names and
unlisted statistics, which is the actual fix. Real-output verification
happens separately against the live server per the incident's acceptance
criteria (a real curl reproduction, done before and after this fix).
"""
import unittest
from unittest.mock import patch

from app.services.rag_service import (
    GROUNDING_RULES,
    GROUNDING_RULES_FA,
    _grounding_reminder,
    run_rag_pipeline,
)


class GroundingRulesForbidFabricatedClaimsTest(unittest.TestCase):
    def test_english_rules_forbid_inventing_platform_names(self):
        self.assertIn("Never invent specific capabilities, integrations, or supported", GROUNDING_RULES)

    def test_english_rules_forbid_inventing_statistics(self):
        self.assertIn("state a number only if it appears verbatim in the context", GROUNDING_RULES)

    def test_persian_rules_forbid_inventing_platform_names(self):
        self.assertIn("هرگز قابلیت، یکپارچه‌سازی یا پلتفرم/نرم‌افزار شخص ثالثی", GROUNDING_RULES_FA)

    def test_persian_rules_forbid_inventing_statistics(self):
        self.assertIn("فقط عددی را بگو که دقیقاً همان‌طور در زمینه", GROUNDING_RULES_FA)

    def test_reminder_repeats_the_platform_and_number_rule_english(self):
        self.assertIn("third-party platform/software name", _grounding_reminder(is_fa=False))

    def test_reminder_repeats_the_platform_and_number_rule_persian(self):
        self.assertIn("نام پلتفرم/نرم‌افزار شخص ثالث", _grounding_reminder(is_fa=True))


class PromptBuildsWithFabricationBanTest(unittest.IsolatedAsyncioTestCase):
    """End-to-end (within this process) check for the exact reported
    scenario: retrieved context contains generic chatbot-ROI content, and
    the prompt actually handed to the LLM must contain the anti-fabrication
    instructions before that context — same mocking approach as
    test_grounding_business_name.py's equivalent test."""

    async def test_prompt_contains_anti_fabrication_rules_for_the_reported_question(self):
        captured = {}

        def fake_chat_completion(db, prompt, max_tokens, temperature):
            captured["prompt"] = prompt
            return ("پاسخ آزمایشی", "test/model", {"prompt_tokens": 1, "completion_tokens": 1, "total_tokens": 2}, 0.0)

        retrieved_chunks = [{
            "id": "c1",
            "content": "تعداد تماس‌های پشتیبانی انسانی ۴۰ درصد کاهش می‌یابد. نرخ رها شدن سبد خرید از ۶۵ درصد به ۴۸ درصد می‌رسد.",
            "metadata": {"title": "هوش مصنوعی و چت‌بات‌ها", "type": "page"},
            "similarity": 0.75,
        }]

        with patch("app.services.rag_service._embed_query", return_value=[0.0] * 8), \
             patch("app.services.rag_service.hybrid_retrieve", return_value={
                 "chunks": retrieved_chunks, "is_unanswered": False, "rerank_cost_toman": 0.0, "rerank_usage": {},
             }), \
             patch("app.services.rag_service._chat_completion", side_effect=fake_chat_completion):
            await run_rag_pipeline(
                db=None, chatbot_id="c1", query="محصول چت بات دارید برای فروش؟", history=[],
                system_prompt=None, fallback_resp=None, llm_model="m",
                top_k=8, threshold=0.6, temperature=0.0, max_tokens=800,
                language="fa", business_name="هامان تک",
            )

        prompt = captured["prompt"]
        self.assertIn("هرگز قابلیت، یکپارچه‌سازی یا پلتفرم/نرم‌افزار شخص ثالثی", prompt)
        self.assertIn("فقط عددی را بگو که دقیقاً همان‌طور در زمینه", prompt)
        # The retrieved chunk itself never mentions Shopify/Magento/3x —
        # confirming those words aren't smuggled in via the context either.
        self.assertNotIn("شاپیفای", prompt)
        self.assertNotIn("مجنتو", prompt)
        self.assertNotIn("Shopify", prompt)
        self.assertNotIn("Magento", prompt)


if __name__ == "__main__":
    unittest.main()
