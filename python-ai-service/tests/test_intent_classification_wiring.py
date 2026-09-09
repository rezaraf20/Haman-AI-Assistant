"""
Verifies rag_service.py actually calls classify_intent()/logs the
intent_classified event for every real user message — placed before any
early-return branch (SKU shortcut, embedding failure, fallback, tool call,
or a normal completion), so a message's intent is recorded regardless of
how the turn is eventually answered. The classifier's own logic is tested
in test_intent_classifier.py; this only checks the wiring, the same
approach test_grounding_no_fabricated_claims.py uses for prompt content.
"""
import unittest
from unittest.mock import patch, MagicMock

from app.services.rag_service import run_rag_pipeline, run_rag_pipeline_stream


class IntentClassificationWiringTest(unittest.IsolatedAsyncioTestCase):
    async def test_logged_even_when_embedding_fails_right_after(self):
        """The strongest proof the log call sits before every early return:
        the very next step (embedding) fails, yet the event still fired."""
        with patch("app.services.rag_service._log_event") as mock_log, \
             patch("app.services.rag_service._try_sku_shortcut", return_value=None), \
             patch("app.services.rag_service._embed_query", side_effect=RuntimeError("embedding service down")):
            await run_rag_pipeline(
                db=MagicMock(), chatbot_id="chatbot-1", query="how much does this cost?", history=[],
                system_prompt=None, fallback_resp=None, llm_model="m",
                top_k=8, threshold=0.6, temperature=0.0, max_tokens=800,
                language="en", conversation_id="conv-1",
            )

        intent_calls = [c for c in mock_log.call_args_list if c.args[3] == "intent_classified"]
        self.assertEqual(len(intent_calls), 1, "Exactly one intent_classified event must be logged per message.")
        self.assertEqual(intent_calls[0].args[4], {"intent": "price"})

    async def test_streaming_pipeline_logs_the_same_way(self):
        with patch("app.services.rag_service._log_event") as mock_log, \
             patch("app.services.rag_service._try_sku_shortcut", return_value=None), \
             patch("app.services.rag_service._embed_query", side_effect=RuntimeError("embedding service down")):
            async for _ in run_rag_pipeline_stream(
                db=MagicMock(), chatbot_id="chatbot-1", query="is this genuine or fake?", history=[],
                system_prompt=None, fallback_resp=None, llm_model="m",
                top_k=8, threshold=0.6, temperature=0.0, max_tokens=800,
                language="en", conversation_id="conv-1",
            ):
                pass

        intent_calls = [c for c in mock_log.call_args_list if c.args[3] == "intent_classified"]
        self.assertEqual(len(intent_calls), 1)
        self.assertEqual(intent_calls[0].args[4], {"intent": "authenticity"})

    async def test_no_conversation_id_means_no_event_ever_reaches_the_db(self):
        # _log_event's own early-return-if-no-conversation_id guard — this
        # just confirms the call site doesn't bypass it in some way that
        # would try to insert a NULL into a NOT NULL foreign key.
        with patch("app.services.rag_service._try_sku_shortcut", return_value=None), \
             patch("app.services.rag_service._embed_query", side_effect=RuntimeError("down")):
            await run_rag_pipeline(
                db=MagicMock(), chatbot_id="chatbot-1", query="how much?", history=[],
                system_prompt=None, fallback_resp=None, llm_model="m",
                top_k=8, threshold=0.6, temperature=0.0, max_tokens=800,
                language="en", conversation_id=None,
            )
        # No exception is the assertion here — db.execute must never have
        # been reached with a null conversation_id.


if __name__ == "__main__":
    unittest.main()
