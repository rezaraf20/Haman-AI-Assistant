"""
run_tool_calling_pipeline()'s comparison-intent fast path
(_try_resolve_comparison in tool_calling_service.py) -- the fix for the
2026-10-25 incident where a comparison request's two products were never
independently resolved: one generic search_products call stood in for
both, and happened to return two catalog listings of the SAME product.

These tests exercise the full pipeline entry point, not just
comparison_resolver.py's own unit tests (see test_comparison_resolver.py
for those), so they also verify: compare_products is called directly
(never through a model-issued tool_call) when both entities resolve
confidently; nothing is compared when either entity is ambiguous or
missing; a genuinely-not-found entity (and only that case) produces a
lead_signal; and a message this splitter can't parse falls through to the
unchanged model-driven flow exactly as before this feature existed.
"""
import unittest
from unittest.mock import MagicMock, patch

from app.services import tool_calling_service as tcs
from app.services.tools.registry import Tool
from tests._safe_http_test_utils import fake_live_query


def _dummy_tools():
    return [
        Tool(name="search_products", description="t", access_level="read",
             parameters={"type": "object", "properties": {}}, handler=lambda **kw: {}),
        Tool(name="compare_products", description="t", access_level="read",
             parameters={"type": "object", "properties": {}}, handler=lambda **kw: {}),
    ]


class ConfidentComparisonResolutionTest(unittest.TestCase):
    def test_both_entities_resolved_calls_compare_products_directly(self):
        search_resp_a = MagicMock(status_code=200)
        search_resp_a.json.return_value = {
            "count": 1, "currency": "IRT",
            "results": [{"product_id": 10, "name": "ماگ آبی", "price": 90000, "stock_status": "instock"}],
        }
        search_resp_b = MagicMock(status_code=200)
        search_resp_b.json.return_value = {
            "count": 1, "currency": "IRT",
            "results": [{"product_id": 20, "name": "ماگ قرمز", "price": 95000, "stock_status": "instock"}],
        }
        compare_resp = MagicMock(status_code=200)
        compare_resp.json.return_value = {
            "currency": "IRT",
            "products": [
                {"product_id": 10, "found": True, "name": "ماگ آبی", "price": 90000, "stock_status": "instock", "image": None, "attributes": {}},
                {"product_id": 20, "found": True, "name": "ماگ قرمز", "price": 95000, "stock_status": "instock", "image": None, "attributes": {}},
            ],
        }

        final_message = {"content": "ماگ آبی ۹۰٬۰۰۰ و ماگ قرمز ۹۵٬۰۰۰ تومان است.", "tool_calls": None}
        chat_calls = []

        def fake_tool_calling_chat(db, messages, tools_schema, max_tokens, temperature, forced_tool=None):
            chat_calls.append((messages, tools_schema))
            return final_message, "groq/test", {"prompt_tokens": 10, "completion_tokens": 10, "total_tokens": 20}, 0.0

        db = MagicMock()
        site_row = MagicMock(primary_domain="shop.example.com", webhook_secret="s3cret")
        db.execute.return_value.fetchone.return_value = site_row

        with patch.object(tcs, "get_enabled_tools", return_value=_dummy_tools()), \
             patch.object(tcs, "to_openai_schema", return_value=[{"type": "function"}]), \
             patch.object(tcs, "_tool_calling_chat", side_effect=fake_tool_calling_chat), \
             patch("app.services.rag_service._log_event"), \
             fake_live_query(side_effect=[search_resp_a, search_resp_b, compare_resp]):
            result = tcs.run_tool_calling_pipeline(
                db=db, chatbot_id="chatbot-1", conversation_id="conv-1",
                query="ماگ آبی را با ماگ قرمز مقایسه کن", history=[], system_prompt_text="system prompt",
                max_tokens=800, temperature=0.3,
                enabled_tool_names=["search_products", "compare_products"], intent="comparison",
            )

        self.assertIsNotNone(result)
        self.assertEqual(result["finish_reason"], "tool_stop_comparison_resolved")
        self.assertEqual(len(result["widget_blocks"]), 1)
        self.assertEqual(result["widget_blocks"][0]["type"], "product_compare")
        ids_shown = {p["product_id"] for p in result["widget_blocks"][0]["products"]}
        self.assertEqual(ids_shown, {10, 20})
        self.assertIsNone(result["lead_signal"])

        # Exactly one model call -- the narrative-writing call -- with
        # tools disabled, never a decision/search turn the model had to
        # be forced through.
        self.assertEqual(len(chat_calls), 1)
        messages, tools_schema = chat_calls[0]
        self.assertIsNone(tools_schema)
        tool_role_contents = [m["content"] for m in messages if m.get("role") == "tool"]
        self.assertTrue(any("ماگ آبی" in c and "ماگ قرمز" in c for c in tool_role_contents))


class AmbiguousOrMissingComparisonEntityTest(unittest.TestCase):
    def _run(self, search_responses, query="ماگ آبی را با ماگ قرمز مقایسه کن"):
        final_message = {"content": "کدام را منظورتان بود؟", "tool_calls": None}
        chat_calls = []

        def fake_tool_calling_chat(db, messages, tools_schema, max_tokens, temperature, forced_tool=None):
            chat_calls.append((messages, tools_schema))
            return final_message, "groq/test", {"prompt_tokens": 10, "completion_tokens": 10, "total_tokens": 20}, 0.0

        db = MagicMock()
        site_row = MagicMock(primary_domain="shop.example.com", webhook_secret="s3cret")
        db.execute.return_value.fetchone.return_value = site_row

        with patch.object(tcs, "get_enabled_tools", return_value=_dummy_tools()), \
             patch.object(tcs, "to_openai_schema", return_value=[{"type": "function"}]), \
             patch.object(tcs, "_tool_calling_chat", side_effect=fake_tool_calling_chat), \
             patch("app.services.tools.product_tools.compare_products") as mock_compare, \
             patch("app.services.rag_service._log_event"), \
             fake_live_query(side_effect=search_responses):
            result = tcs.run_tool_calling_pipeline(
                db=db, chatbot_id="chatbot-1", conversation_id="conv-1",
                query=query, history=[], system_prompt_text="system prompt",
                max_tokens=800, temperature=0.3,
                enabled_tool_names=["search_products", "compare_products"], intent="comparison",
            )
        return result, chat_calls, mock_compare

    def test_ambiguous_entity_never_calls_compare_products(self):
        ambiguous_resp = MagicMock(status_code=200)
        ambiguous_resp.json.return_value = {
            "count": 2, "currency": "IRT",
            "results": [
                {"product_id": 10, "name": "ماگ آبی بزرگ", "price": 90000, "stock_status": "instock"},
                {"product_id": 11, "name": "ماگ آبی کوچک", "price": 80000, "stock_status": "instock"},
            ],
        }
        resolved_resp = MagicMock(status_code=200)
        resolved_resp.json.return_value = {
            "count": 1, "currency": "IRT",
            "results": [{"product_id": 20, "name": "ماگ قرمز", "price": 95000, "stock_status": "instock"}],
        }
        result, chat_calls, mock_compare = self._run([ambiguous_resp, resolved_resp])

        self.assertIsNotNone(result)
        mock_compare.assert_not_called()
        self.assertIsNone(result["lead_signal"])
        self.assertEqual(result["widget_blocks"], [])
        messages, tools_schema = chat_calls[0]
        self.assertIsNone(tools_schema)
        system_contents = " ".join(m["content"] for m in messages if m.get("role") == "system")
        self.assertIn("چند مورد نزدیک پیدا شد", system_contents)

    def test_single_not_found_entity_sets_lead_signal(self):
        not_found_resp = MagicMock(status_code=200)
        not_found_resp.json.return_value = {"count": 0, "currency": "IRT", "results": []}
        resolved_resp = MagicMock(status_code=200)
        resolved_resp.json.return_value = {
            "count": 1, "currency": "IRT",
            "results": [{"product_id": 20, "name": "ماگ قرمز", "price": 95000, "stock_status": "instock"}],
        }
        result, chat_calls, mock_compare = self._run([not_found_resp, resolved_resp])

        self.assertIsNotNone(result)
        mock_compare.assert_not_called()
        self.assertEqual(result["lead_signal"], {"mode": "not_in_catalog", "item": "ماگ آبی"})

    def test_both_not_found_sets_no_lead_signal(self):
        # _detect_lead_signal's own "one unambiguous item or nothing" rule
        # (see tool_calling_service.py): a lead that doesn't name one real
        # thing the customer asked for tells the merchant nothing they can
        # act on -- so this module only sets it for exactly one not_found
        # entity, never two at once.
        not_found_resp_a = MagicMock(status_code=200)
        not_found_resp_a.json.return_value = {"count": 0, "currency": "IRT", "results": []}
        not_found_resp_b = MagicMock(status_code=200)
        not_found_resp_b.json.return_value = {"count": 0, "currency": "IRT", "results": []}
        result, chat_calls, mock_compare = self._run([not_found_resp_a, not_found_resp_b])

        self.assertIsNotNone(result)
        mock_compare.assert_not_called()
        self.assertIsNone(result["lead_signal"])


class SplitFailureFallsThroughUnchangedTest(unittest.TestCase):
    def test_no_separator_falls_through_to_the_model_driven_flow(self):
        """A comparison-intent message this splitter can't parse into two
        named entities must behave exactly as it did before this feature
        existed -- the model's own forced search_products-then-
        compare_products turn, never short-circuited."""
        def fake_handler(db, chatbot_id, **kwargs):
            return {"live": True, "results": [], "count": 0, "currency": "IRT"}

        tools = [
            Tool(name="search_products", description="t", access_level="read",
                 parameters={"type": "object", "properties": {}}, handler=fake_handler),
            Tool(name="compare_products", description="t", access_level="read",
                 parameters={"type": "object", "properties": {}}, handler=fake_handler),
        ]

        decision_call_log = []
        call_sequence = [
            # Turn 1: the ordinary router forces search_products (see
            # tool_router.route()'s via_lookup for "comparison") -- the
            # model complies.
            ({"content": None, "tool_calls": [{"id": "c1", "function": {"name": "search_products", "arguments": "{}"}}]},
             {"prompt_tokens": 5, "completion_tokens": 5, "total_tokens": 10}),
            # Turn 2: forced to compare_products next.
            ({"content": None, "tool_calls": [{"id": "c2", "function": {"name": "compare_products", "arguments": '{"product_ids": [1, 2]}'}}]},
             {"prompt_tokens": 5, "completion_tokens": 5, "total_tokens": 10}),
            # Turn 3: final text answer.
            ({"content": "متوجه نشدم دقیقا کدام دو محصول را مقایسه کنم.", "tool_calls": None},
             {"prompt_tokens": 5, "completion_tokens": 5, "total_tokens": 10}),
        ]

        def fake_tool_calling_chat(db, messages, tools_schema, max_tokens, temperature, forced_tool=None):
            decision_call_log.append(tools_schema is not None)
            message, usage = call_sequence.pop(0)
            return message, "groq/test", usage, 0.0

        with patch.object(tcs, "get_enabled_tools", return_value=tools), \
             patch.object(tcs, "to_openai_schema", return_value=[{"type": "function"}]), \
             patch.object(tcs, "_tool_calling_chat", side_effect=fake_tool_calling_chat), \
             patch("app.services.rag_service._log_event"):
            result = tcs.run_tool_calling_pipeline(
                db=MagicMock(), chatbot_id="chatbot-1", conversation_id="conv-1",
                # No "با"/"و"/"vs"/"در برابر" separator -- the entity
                # splitter has nothing confident to split on ("این دو" is
                # a reference to products already in the conversation
                # history, not two named entities in this message).
                query="این دو محصول چه فرقی دارند",
                history=[], system_prompt_text="system prompt",
                max_tokens=800, temperature=0.3,
                enabled_tool_names=["search_products", "compare_products"], intent="comparison",
            )

        # Fell through to the ordinary router-forced flow -- the real
        # tools schema was offered and the router's own forced
        # search_products -> compare_products sequence ran to completion,
        # none of it short-circuited by the comparison-resolver fast path.
        self.assertTrue(any(decision_call_log))
        self.assertIsNotNone(result)
        self.assertEqual(result["finish_reason"], "tool_stop")


if __name__ == "__main__":
    unittest.main()
