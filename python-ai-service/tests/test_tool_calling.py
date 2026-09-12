"""
Feature tests for the tool-calling infrastructure (see tool_calling_service.py
and app/services/tools/). Covers the explicit acceptance criteria:
  - the model actually invoking a real registered tool (not text parsing)
  - a tool_called conversation_event being logged with tool/args/result/latency
  - a manipulated tool-call argument (chatbot_id/schema_name/tenant_id) never
    reaching a tool handler — the real server-side chatbot_id always wins
  - the WordPress site being unreachable degrading to a graceful fallback
    that never states a stale price, never a crash

Live verification (a real conversation_events row from an actual chat
request, and the real live-data tools hitting a real — or genuinely
offline — WordPress site) is done separately against the deployed server
per the task's acceptance criteria; this file verifies the code's own
logic deterministically, the same division of labor as every other test
file in this suite.
"""
import json as _json
import unittest
from unittest.mock import MagicMock, patch

from app.services.tools.registry import Tool, register, get_enabled_tools, to_openai_schema, _REGISTRY
from app.services.tools import product_tools
from app.services import tool_calling_service


class RegistryTest(unittest.TestCase):
    def test_unregistered_name_is_silently_ignored(self):
        self.assertEqual(get_enabled_tools(["does_not_exist"]), [])

    def test_all_three_live_tools_are_registered(self):
        for name in ("get_product_availability", "get_product_variants", "search_products"):
            self.assertIn(name, _REGISTRY)

    def test_registered_tool_is_returned_when_named(self):
        tools = get_enabled_tools(["get_product_availability"])
        self.assertEqual(len(tools), 1)
        self.assertEqual(tools[0].name, "get_product_availability")

    def test_openai_schema_shape(self):
        tools = get_enabled_tools(["get_product_availability", "get_product_variants", "search_products"])
        schema = to_openai_schema(tools)
        names = {f["function"]["name"] for f in schema}
        self.assertEqual(names, {"get_product_availability", "get_product_variants", "search_products"})
        for f in schema:
            self.assertEqual(f["type"], "function")
            # chatbot_id/schema_name must never be exposed to the model as
            # something it could supply — the real security boundary.
            self.assertNotIn("chatbot_id", f["function"]["parameters"]["properties"])
            self.assertNotIn("schema_name", f["function"]["parameters"]["properties"])


class ProductToolsValidationTest(unittest.TestCase):
    """Every identifier/filter these tools accept must be validated before
    it goes anywhere near a URL, an HMAC payload, or a live query — never
    merely escaped."""

    def test_sku_with_sql_metacharacters_is_rejected(self):
        db = MagicMock()
        result = product_tools.get_product_availability(db, "chatbot-1", sku="'; DROP TABLE products; --")
        self.assertIn("error", result)
        db.execute.assert_not_called()

    def test_sku_with_path_traversal_is_rejected(self):
        db = MagicMock()
        result = product_tools.get_product_availability(db, "chatbot-1", sku="../../etc/passwd")
        self.assertIn("error", result)

    def test_normal_sku_passes_validation(self):
        self.assertTrue(product_tools._SAFE_SKU_RE.match("LM358N"))
        self.assertTrue(product_tools._SAFE_SKU_RE.match("LM358-N"))
        self.assertFalse(product_tools._SAFE_SKU_RE.match("LM358<script>"))

    def test_neither_sku_nor_product_id_is_an_error(self):
        db = MagicMock()
        result = product_tools.get_product_availability(db, "chatbot-1")
        self.assertIn("error", result)
        db.execute.assert_not_called()

    def test_non_numeric_product_id_is_rejected(self):
        db = MagicMock()
        result = product_tools.get_product_availability(db, "chatbot-1", product_id="1 OR 1=1")
        self.assertIn("error", result)
        db.execute.assert_not_called()

    def test_negative_product_id_is_rejected(self):
        db = MagicMock()
        result = product_tools.get_product_variants(db, "chatbot-1", product_id=-5)
        self.assertIn("error", result)
        db.execute.assert_not_called()

    def test_search_query_over_200_chars_is_truncated_not_rejected(self):
        db = MagicMock()
        site_row = MagicMock(primary_domain="example.test", webhook_secret="s3cret")
        db.execute.return_value.fetchone.return_value = site_row

        fake_response = MagicMock(status_code=200)
        fake_response.json.return_value = {"count": 0, "currency": "IRT", "results": []}
        with patch("app.services.tools.product_tools._requests.post", return_value=fake_response) as mock_post:
            product_tools.search_products(db, "chatbot-1", query="x" * 500)

        sent_body = mock_post.call_args.kwargs["data"].decode()
        sent_query = _json.loads(sent_body)["query"]
        self.assertEqual(len(sent_query), 200)

    def test_invalid_category_filter_is_rejected(self):
        db = MagicMock()
        result = product_tools.search_products(db, "chatbot-1", category="a" * 200)
        self.assertIn("error", result)
        db.execute.assert_not_called()


class ProductToolsPricingSafetyTest(unittest.TestCase):
    """The core safety property of these three tools: on ANY failure to
    reach the live store, they must never return a price/stock figure at
    all — there's nothing left for the model to misreport, only a note
    telling it to say so and (when knowable) a product_url built from the
    numeric ID."""

    def test_no_site_on_file_returns_no_price_field(self):
        db = MagicMock()
        db.execute.return_value.fetchone.return_value = None
        result = product_tools.get_product_availability(db, "chatbot-1", sku="LM358N")
        self.assertFalse(result["live"])
        self.assertNotIn("price", result)
        self.assertIn("note", result)
        self.assertIsNone(result["product_url"])

    def test_no_site_on_file_but_product_id_known_still_builds_no_url(self):
        # No domain is known at all in this case, so even a known numeric
        # ID can't be turned into a URL — nothing to link to yet.
        db = MagicMock()
        db.execute.return_value.fetchone.return_value = None
        result = product_tools.get_product_availability(db, "chatbot-1", product_id=42)
        self.assertIsNone(result["product_url"])

    def test_live_query_timeout_returns_no_price_field(self):
        db = MagicMock()
        site_row = MagicMock(primary_domain="example.test", webhook_secret="s3cret")
        db.execute.return_value.fetchone.return_value = site_row

        with patch("app.services.tools.product_tools._requests.post", side_effect=product_tools._requests.RequestException("timed out")):
            result = product_tools.get_product_availability(db, "chatbot-1", product_id=42)

        self.assertFalse(result["live"])
        self.assertNotIn("price", result)
        self.assertNotIn("stock_status", result)
        self.assertIn("note", result)
        # product_id WAS known (the caller supplied it) even though the live
        # call failed, so a product_url should still be buildable.
        self.assertEqual(result["product_url"], "https://example.test/?p=42")

    def test_live_query_non_200_returns_no_price_field(self):
        db = MagicMock()
        site_row = MagicMock(primary_domain="example.test", webhook_secret="s3cret")
        db.execute.return_value.fetchone.return_value = site_row

        fake_response = MagicMock(status_code=500, text="Internal Server Error")
        with patch("app.services.tools.product_tools._requests.post", return_value=fake_response):
            result = product_tools.get_product_availability(db, "chatbot-1", sku="LM358N")

        self.assertFalse(result["live"])
        self.assertNotIn("price", result)

    def test_live_query_success_returns_live_price_and_id_based_url(self):
        db = MagicMock()
        site_row = MagicMock(primary_domain="example.test", webhook_secret="s3cret")
        db.execute.return_value.fetchone.return_value = site_row

        fake_response = MagicMock(status_code=200)
        fake_response.json.return_value = {
            "found": True, "product_id": 42, "name": "LM358 Op-Amp", "sku": "LM358N",
            "status": "publish", "stock_status": "instock", "stock_quantity": 10,
            "price": 15000, "regular_price": 18000, "sale_price": 15000, "on_sale": True,
            "is_variable": False, "currency": "IRT",
        }
        with patch("app.services.tools.product_tools._requests.post", return_value=fake_response):
            result = product_tools.get_product_availability(db, "chatbot-1", sku="LM358N")

        self.assertTrue(result["live"])
        self.assertTrue(result["found"])
        self.assertEqual(result["price"], 15000)
        self.assertEqual(result["stock_status"], "instock")
        # By numeric ID, never by slug (see product_tools._product_url).
        self.assertEqual(result["product_url"], "https://example.test/?p=42")

    def test_search_products_unavailable_returns_empty_results_not_stale_data(self):
        db = MagicMock()
        db.execute.return_value.fetchone.return_value = None
        result = product_tools.search_products(db, "chatbot-1", query="shoes")
        self.assertFalse(result["live"])
        self.assertEqual(result["results"], [])

    def test_search_products_success_tags_each_result_with_an_id_based_url(self):
        db = MagicMock()
        site_row = MagicMock(primary_domain="example.test", webhook_secret="s3cret")
        db.execute.return_value.fetchone.return_value = site_row

        fake_response = MagicMock(status_code=200)
        fake_response.json.return_value = {
            "count": 1, "currency": "IRT",
            "results": [{"product_id": 7, "name": "Red Shoes", "sku": "SHOE-RED", "price": 200000, "on_sale": False, "stock_status": "instock", "is_variable": False}],
        }
        with patch("app.services.tools.product_tools._requests.post", return_value=fake_response):
            result = product_tools.search_products(db, "chatbot-1", query="shoes", in_stock_only=True)

        self.assertTrue(result["live"])
        self.assertEqual(result["results"][0]["product_url"], "https://example.test/?p=7")

    def test_variants_unavailable_returns_no_price_field(self):
        db = MagicMock()
        site_row = MagicMock(primary_domain="example.test", webhook_secret="s3cret")
        db.execute.return_value.fetchone.return_value = site_row

        with patch("app.services.tools.product_tools._requests.post", side_effect=product_tools._requests.RequestException("connection refused")):
            result = product_tools.get_product_variants(db, "chatbot-1", product_id=99)

        self.assertFalse(result["live"])
        self.assertNotIn("variants", result)
        self.assertEqual(result["product_url"], "https://example.test/?p=99")

    def test_variants_success_returns_each_variants_own_price_and_stock(self):
        db = MagicMock()
        site_row = MagicMock(primary_domain="example.test", webhook_secret="s3cret")
        db.execute.return_value.fetchone.return_value = site_row

        fake_response = MagicMock(status_code=200)
        fake_response.json.return_value = {
            "found": True, "is_variable": True, "product_id": 99, "name": "T-Shirt", "currency": "IRT",
            "variants": [
                {"variation_id": 101, "attributes": {"attribute_pa_size": "M"}, "sku": "TS-M", "stock_status": "instock", "stock_quantity": 5, "price": 100000, "regular_price": 100000, "sale_price": None},
                {"variation_id": 102, "attributes": {"attribute_pa_size": "L"}, "sku": "TS-L", "stock_status": "outofstock", "stock_quantity": 0, "price": 100000, "regular_price": 100000, "sale_price": None},
            ],
        }
        with patch("app.services.tools.product_tools._requests.post", return_value=fake_response):
            result = product_tools.get_product_variants(db, "chatbot-1", product_id=99)

        self.assertTrue(result["live"])
        self.assertEqual(len(result["variants"]), 2)
        self.assertEqual(result["variants"][0]["stock_status"], "instock")
        self.assertEqual(result["variants"][1]["stock_status"], "outofstock")


class ExecuteToolCallSecurityTest(unittest.TestCase):
    """Acceptance criterion: a manipulated tool-call argument must never
    let a tool reach another tenant's data."""

    def test_manipulated_chatbot_id_in_model_arguments_is_ignored(self):
        captured_kwargs = {}

        def fake_handler(db, chatbot_id, **kwargs):
            captured_kwargs["chatbot_id"] = chatbot_id
            captured_kwargs["kwargs"] = kwargs
            return {"found": True}

        fake_tool = Tool(
            name="fake_lookup", description="test", access_level="read",
            parameters={"type": "object", "properties": {"sku": {"type": "string"}}},
            handler=fake_handler,
        )

        call = {
            "id": "call_1",
            "function": {
                "name": "fake_lookup",
                # The model tries to smuggle a different tenant's chatbot_id
                # (or schema_name/tenant_id) into its own tool-call
                # arguments — this must never reach the handler.
                "arguments": '{"sku": "LM358N", "chatbot_id": "some-other-tenants-chatbot-id", "schema_name": "tenant_other", "tenant_id": "other-tenant"}',
            },
        }

        db = MagicMock()
        with patch("app.services.rag_service._log_event"):
            tool_calling_service._execute_tool_call(db, "REAL-CHATBOT-ID", None, call, [fake_tool])

        self.assertEqual(captured_kwargs["chatbot_id"], "REAL-CHATBOT-ID")
        self.assertNotIn("chatbot_id", captured_kwargs["kwargs"])
        self.assertNotIn("schema_name", captured_kwargs["kwargs"])
        self.assertNotIn("tenant_id", captured_kwargs["kwargs"])
        self.assertEqual(captured_kwargs["kwargs"], {"sku": "LM358N"})

    def test_unknown_tool_name_returns_error_without_crashing(self):
        db = MagicMock()
        call = {"id": "call_1", "function": {"name": "not_a_real_tool", "arguments": "{}"}}
        with patch("app.services.rag_service._log_event"):
            result = tool_calling_service._execute_tool_call(db, "chatbot-1", None, call, [])
        self.assertIn("error", result)

    def test_write_tool_is_refused_unconditionally(self):
        write_tool = Tool(
            name="place_order", description="test", access_level="write",
            parameters={"type": "object", "properties": {}},
            handler=lambda db, chatbot_id, **kw: {"placed": True},
        )
        call = {"id": "call_1", "function": {"name": "place_order", "arguments": "{}"}}
        db = MagicMock()
        with patch("app.services.rag_service._log_event"):
            result = tool_calling_service._execute_tool_call(db, "chatbot-1", None, call, [write_tool])
        self.assertIn("error", result)
        self.assertIn("confirmation", result["error"])

    def test_handler_exception_does_not_propagate(self):
        def broken_handler(db, chatbot_id, **kwargs):
            raise RuntimeError("boom")
        broken_tool = Tool(
            name="broken", description="test", access_level="read",
            parameters={"type": "object", "properties": {}},
            handler=broken_handler,
        )
        call = {"id": "call_1", "function": {"name": "broken", "arguments": "{}"}}
        db = MagicMock()
        with patch("app.services.rag_service._log_event"):
            result = tool_calling_service._execute_tool_call(db, "chatbot-1", None, call, [broken_tool])
        self.assertIn("error", result)

    def test_tool_called_event_is_logged_with_expected_shape(self):
        fake_tool = Tool(
            name="fake_lookup", description="test", access_level="read",
            parameters={"type": "object", "properties": {}},
            handler=lambda db, chatbot_id, **kw: {"found": True, "stock_status": "instock"},
        )
        call = {"id": "call_1", "function": {"name": "fake_lookup", "arguments": "{}"}}
        db = MagicMock()
        with patch("app.services.rag_service._log_event") as mock_log:
            tool_calling_service._execute_tool_call(db, "chatbot-1", "conv-1", call, [fake_tool])

        mock_log.assert_called_once()
        args, kwargs = mock_log.call_args
        self.assertEqual(args[0], db)
        self.assertEqual(args[1], "conv-1")
        self.assertEqual(args[2], "chatbot-1")
        self.assertEqual(args[3], "tool_called")
        payload = args[4]
        self.assertEqual(payload["tool"], "fake_lookup")
        self.assertTrue(payload["success"])
        self.assertIn("latency_ms", kwargs)


class RunToolCallingPipelineTest(unittest.TestCase):
    """Acceptance criterion: the model actually invoking a real registered
    tool — verified via a mocked LLM response containing a real tool_calls
    entry, confirming the loop executes the real handler and produces an
    answer built from its result."""

    def test_no_enabled_tools_returns_none_immediately(self):
        db = MagicMock()
        result = tool_calling_service.run_tool_calling_pipeline(
            db, "chatbot-1", None, "Is LM358N in stock?", [], "system prompt", 800, 0.3, [],
        )
        self.assertIsNone(result)

    def test_model_calls_the_real_tool_and_final_answer_uses_its_result(self):
        handler_calls = []

        def fake_handler(db, chatbot_id, sku):
            handler_calls.append(sku)
            return {"found": True, "live": True, "stock_status": "instock", "price": 15000}

        test_tool = Tool(
            name="get_product_availability", description="test", access_level="read",
            parameters={"type": "object", "properties": {"sku": {"type": "string"}}, "required": ["sku"]},
            handler=fake_handler,
        )

        # First LLM call: the model decides to call the tool.
        tool_call_message = {
            "content": None,
            "tool_calls": [{"id": "call_1", "function": {"name": "get_product_availability", "arguments": '{"sku": "LM358N"}'}}],
        }
        # Second LLM call (after seeing the tool result): final text answer.
        final_message = {"content": "Yes, LM358N is in stock for 15000 IRT (live data).", "tool_calls": None}

        call_sequence = [
            (tool_call_message, {"prompt_tokens": 50, "completion_tokens": 20, "total_tokens": 70}),
            (final_message, {"prompt_tokens": 80, "completion_tokens": 15, "total_tokens": 95}),
        ]

        def fake_tool_calling_chat(db, messages, tools_schema, max_tokens, temperature):
            message, usage = call_sequence.pop(0)
            return message, "groq/test-tool-model", usage, 0.0

        with patch.object(tool_calling_service, "get_enabled_tools", return_value=[test_tool]), \
             patch.object(tool_calling_service, "to_openai_schema", return_value=[{"type": "function", "function": {"name": "get_product_availability"}}]), \
             patch.object(tool_calling_service, "_tool_calling_chat", side_effect=fake_tool_calling_chat), \
             patch("app.services.rag_service._log_event"):
            result = tool_calling_service.run_tool_calling_pipeline(
                db=MagicMock(), chatbot_id="chatbot-1", conversation_id="conv-1",
                query="Is LM358N in stock?", history=[], system_prompt_text="system prompt",
                max_tokens=800, temperature=0.3, enabled_tool_names=["get_product_availability"],
            )

        self.assertEqual(handler_calls, ["LM358N"], "The real tool handler must actually have been invoked with the model's argument.")
        self.assertIsNotNone(result)
        self.assertIn("15000", result["response"])
        self.assertEqual(result["finish_reason"], "tool_stop")
        self.assertEqual(result["total_tokens"], 70 + 95)

    def test_tool_call_budget_forces_a_final_tools_disabled_call(self):
        """After MAX_TOOL_CALLS_PER_MESSAGE real tool executions, the next
        LLM call must be made with tools disabled so the turn always ends
        in a real text answer instead of looping forever."""
        executed = []

        def fake_handler(db, chatbot_id, sku):
            executed.append(sku)
            return {"found": True}

        test_tool = Tool(
            name="get_product_availability", description="test", access_level="read",
            parameters={"type": "object", "properties": {"sku": {"type": "string"}}},
            handler=fake_handler,
        )

        offered_tools_log = []

        def fake_tool_calling_chat(db, messages, tools_schema, max_tokens, temperature):
            offered_tools_log.append(tools_schema is not None)
            if tools_schema is not None:
                return (
                    {"content": None, "tool_calls": [{"id": f"call_{len(offered_tools_log)}", "function": {"name": "get_product_availability", "arguments": '{"sku": "X"}'}}]},
                    "groq/test", {"prompt_tokens": 1, "completion_tokens": 1, "total_tokens": 2}, 0.0,
                )
            return ({"content": "Here's what I found.", "tool_calls": None}, "groq/test", {"prompt_tokens": 1, "completion_tokens": 1, "total_tokens": 2}, 0.0)

        with patch.object(tool_calling_service, "get_enabled_tools", return_value=[test_tool]), \
             patch.object(tool_calling_service, "to_openai_schema", return_value=[{"type": "function"}]), \
             patch.object(tool_calling_service, "_tool_calling_chat", side_effect=fake_tool_calling_chat), \
             patch("app.services.rag_service._log_event"):
            result = tool_calling_service.run_tool_calling_pipeline(
                db=MagicMock(), chatbot_id="chatbot-1", conversation_id="conv-1",
                query="check a bunch of parts", history=[], system_prompt_text="sys",
                max_tokens=800, temperature=0.3, enabled_tool_names=["get_product_availability"],
            )

        self.assertIsNotNone(result, "The loop must still end in a real answer, not None, once the budget is spent.")
        self.assertLessEqual(len(executed), tool_calling_service.MAX_TOOL_CALLS_PER_MESSAGE)
        self.assertFalse(offered_tools_log[-1], "The final call must have been made with tools disabled.")

    def test_llm_failure_returns_none_and_does_not_raise(self):
        with patch.object(tool_calling_service, "get_enabled_tools", return_value=[MagicMock()]), \
             patch.object(tool_calling_service, "to_openai_schema", return_value=[{"type": "function"}]), \
             patch.object(tool_calling_service, "_tool_calling_chat", side_effect=RuntimeError("all providers failed")):
            result = tool_calling_service.run_tool_calling_pipeline(
                db=MagicMock(), chatbot_id="chatbot-1", conversation_id=None,
                query="q", history=[], system_prompt_text="sys",
                max_tokens=800, temperature=0.3, enabled_tool_names=["whatever"],
            )
        self.assertIsNone(result)


class WidgetBlockTest(unittest.TestCase):
    """recommend_products/compare_products results must render as actual
    widget UI, never as text the model re-describes (see
    _build_widget_block()), and every product actually shown must get a
    product_mentioned event (revenue-attribution input, doc-04's
    acceptance criterion) — logged for exactly the products shown, not
    merely fetched/considered."""

    def test_build_widget_block_ignores_non_display_tools(self):
        self.assertIsNone(tool_calling_service._build_widget_block("get_product_availability", {"live": True, "products": [{"product_id": 1}]}))

    def test_build_widget_block_ignores_unsuccessful_result(self):
        self.assertIsNone(tool_calling_service._build_widget_block("recommend_products", {"live": False, "products": []}))

    def test_build_widget_block_ignores_empty_products(self):
        self.assertIsNone(tool_calling_service._build_widget_block("recommend_products", {"live": True, "products": []}))

    def test_build_widget_block_recommend_shape(self):
        products = [{"product_id": 1, "name": "A"}]
        block = tool_calling_service._build_widget_block("recommend_products", {"live": True, "products": products})
        self.assertEqual(block, {"type": "product_cards", "products": products})

    def test_build_widget_block_compare_shape_includes_attribute_rows(self):
        products = [{"product_id": 1}, {"product_id": 2}]
        rows = [{"attribute": "Volume", "values": {"1": "50ml", "2": None}}]
        block = tool_calling_service._build_widget_block("compare_products", {"live": True, "products": products, "attribute_rows": rows})
        self.assertEqual(block, {"type": "product_compare", "products": products, "attribute_rows": rows})

    def test_build_widget_block_cart_links_shape(self):
        items = [{"product_id": 12, "quantity": 1, "url": "https://example.test/?add-to-cart=12&quantity=1"}]
        block = tool_calling_service._build_widget_block("build_cart_url", {"items": items})
        self.assertEqual(block, {"type": "cart_links", "items": items})

    def test_build_widget_block_ignores_cart_url_error_result(self):
        self.assertIsNone(tool_calling_service._build_widget_block("build_cart_url", {"error": "no_site_domain", "items": []}))

    def test_build_widget_block_ignores_empty_cart_items(self):
        self.assertIsNone(tool_calling_service._build_widget_block("build_cart_url", {"items": []}))

    def test_build_widget_block_add_to_cart_shape(self):
        items = [{"product_id": 12, "variation_id": None, "quantity": 1, "name": "Widget"}]
        block = tool_calling_service._build_widget_block("add_to_cart", {"items": items})
        self.assertEqual(block, {"type": "add_to_cart", "items": items})

    def test_build_widget_block_ignores_add_to_cart_error_result(self):
        self.assertIsNone(tool_calling_service._build_widget_block("add_to_cart", {"error": "items must be a non-empty list."}))

    def test_build_widget_block_ignores_empty_add_to_cart_items(self):
        self.assertIsNone(tool_calling_service._build_widget_block("add_to_cart", {"items": []}))

    def test_build_widget_block_payment_link_preview_shape(self):
        items = [{"product_id": 12, "name": "Widget", "quantity": 1, "line_total": 90000}]
        block = tool_calling_service._build_widget_block(
            "create_payment_link", {"items": items, "total": 90000, "currency": "IRT", "customer": {"name": "Ali"}},
        )
        self.assertEqual(block, {
            "type": "payment_link_preview", "items": items,
            "total": 90000, "currency": "IRT", "customer": {"name": "Ali"},
        })

    def test_build_widget_block_payment_link_preview_defaults_currency_and_customer(self):
        items = [{"product_id": 12, "name": "Widget", "quantity": 1, "line_total": 90000}]
        block = tool_calling_service._build_widget_block("create_payment_link", {"items": items, "total": 90000})
        self.assertEqual(block["currency"], "IRT")
        self.assertIsNone(block["customer"])

    def test_build_widget_block_ignores_payment_link_error_result(self):
        self.assertIsNone(tool_calling_service._build_widget_block(
            "create_payment_link", {"error": "live_check_unavailable", "items": []},
        ))

    def test_build_widget_block_ignores_empty_payment_link_items(self):
        self.assertIsNone(tool_calling_service._build_widget_block(
            "create_payment_link", {"items": [], "total": 90000},
        ))

    def test_build_widget_block_ignores_payment_link_missing_total(self):
        self.assertIsNone(tool_calling_service._build_widget_block(
            "create_payment_link", {"items": [{"product_id": 12}], "total": None},
        ))

    def test_log_cart_links_logs_one_event_per_item(self):
        with patch("app.services.rag_service._log_event") as mock_log:
            tool_calling_service._log_cart_links(
                MagicMock(), "conv-1", "chatbot-1",
                [{"product_id": 12, "quantity": 1, "url": "https://x/?add-to-cart=12&quantity=1"},
                 {"product_id": 34, "quantity": 2, "url": "https://x/?add-to-cart=34&quantity=2"}],
            )
        self.assertEqual(mock_log.call_count, 2)
        event_types = {c[0][3] for c in mock_log.call_args_list}
        self.assertEqual(event_types, {"cart_link_generated"})
        payloads = [c[0][4] for c in mock_log.call_args_list]
        self.assertEqual({p["product_id"] for p in payloads}, {12, 34})

    def test_log_product_mentions_skips_not_found_entries(self):
        with patch("app.services.rag_service._log_event") as mock_log:
            tool_calling_service._log_product_mentions(
                MagicMock(), "conv-1", "chatbot-1", "compare_products",
                [{"product_id": 1, "name": "A", "found": True}, {"product_id": 999, "found": False}],
            )
        mock_log.assert_called_once()
        self.assertEqual(mock_log.call_args[0][4]["product_id"], 1)

    def test_log_product_mentions_logs_one_event_per_product(self):
        with patch("app.services.rag_service._log_event") as mock_log:
            tool_calling_service._log_product_mentions(
                MagicMock(), "conv-1", "chatbot-1", "recommend_products",
                [{"product_id": 1, "name": "A"}, {"product_id": 2, "name": "B"}],
            )
        self.assertEqual(mock_log.call_count, 2)
        payloads = [c[0][4] for c in mock_log.call_args_list]
        self.assertEqual({p["product_id"] for p in payloads}, {1, 2})
        self.assertTrue(all(p["source"] == "recommend_products" for p in payloads))

    def test_run_tool_calling_pipeline_returns_widget_blocks_and_logs_mentions(self):
        recommend_tool = Tool(
            name="recommend_products", description="test", access_level="read",
            parameters={"type": "object", "properties": {"need": {"type": "string"}}, "required": ["need"]},
            handler=lambda db, chatbot_id, **kw: {
                "live": True,
                "products": [{"product_id": 11, "name": "Oil-Free Cleanser", "price": 90000, "stock_status": "instock"}],
            },
        )

        tool_call_message = {
            "content": None,
            "tool_calls": [{"id": "call_1", "function": {"name": "recommend_products", "arguments": '{"need": "oily skin"}'}}],
        }
        final_message = {"content": "Here's a good option for oily skin.", "tool_calls": None}
        call_sequence = [
            (tool_call_message, {"prompt_tokens": 10, "completion_tokens": 5, "total_tokens": 15}),
            (final_message, {"prompt_tokens": 20, "completion_tokens": 8, "total_tokens": 28}),
        ]

        def fake_tool_calling_chat(db, messages, tools_schema, max_tokens, temperature):
            message, usage = call_sequence.pop(0)
            return message, "groq/test-tool-model", usage, 0.0

        with patch.object(tool_calling_service, "get_enabled_tools", return_value=[recommend_tool]), \
             patch.object(tool_calling_service, "to_openai_schema", return_value=[{"type": "function", "function": {"name": "recommend_products"}}]), \
             patch.object(tool_calling_service, "_tool_calling_chat", side_effect=fake_tool_calling_chat), \
             patch("app.services.rag_service._log_event") as mock_log:
            result = tool_calling_service.run_tool_calling_pipeline(
                db=MagicMock(), chatbot_id="chatbot-1", conversation_id="conv-1",
                query="what do you recommend for oily skin?", history=[], system_prompt_text="system prompt",
                max_tokens=800, temperature=0.3, enabled_tool_names=["recommend_products"],
            )

        self.assertIsNotNone(result)
        self.assertEqual(result["widget_blocks"], [{
            "type": "product_cards",
            "products": [{"product_id": 11, "name": "Oil-Free Cleanser", "price": 90000, "stock_status": "instock"}],
        }])
        # Both the tool_called event AND the product_mentioned event fired.
        event_types = [c[0][3] for c in mock_log.call_args_list]
        self.assertIn("tool_called", event_types)
        self.assertIn("product_mentioned", event_types)

    def test_run_tool_calling_pipeline_returns_cart_links_and_logs_cart_link_generated(self):
        cart_tool = Tool(
            name="build_cart_url", description="test", access_level="read",
            parameters={"type": "object", "properties": {"items": {"type": "array"}}, "required": ["items"]},
            handler=lambda db, chatbot_id, **kw: {
                "items": [{"product_id": 12, "quantity": 1, "url": "https://example.test/?add-to-cart=12&quantity=1"}],
            },
        )

        tool_call_message = {
            "content": None,
            "tool_calls": [{"id": "call_1", "function": {"name": "build_cart_url", "arguments": '{"items": [{"product_id": 12}]}'}}],
        }
        final_message = {"content": "Click below to add it to your cart.", "tool_calls": None}
        call_sequence = [
            (tool_call_message, {"prompt_tokens": 10, "completion_tokens": 5, "total_tokens": 15}),
            (final_message, {"prompt_tokens": 20, "completion_tokens": 8, "total_tokens": 28}),
        ]

        def fake_tool_calling_chat(db, messages, tools_schema, max_tokens, temperature):
            message, usage = call_sequence.pop(0)
            return message, "groq/test-tool-model", usage, 0.0

        with patch.object(tool_calling_service, "get_enabled_tools", return_value=[cart_tool]), \
             patch.object(tool_calling_service, "to_openai_schema", return_value=[{"type": "function", "function": {"name": "build_cart_url"}}]), \
             patch.object(tool_calling_service, "_tool_calling_chat", side_effect=fake_tool_calling_chat), \
             patch("app.services.rag_service._log_event") as mock_log:
            result = tool_calling_service.run_tool_calling_pipeline(
                db=MagicMock(), chatbot_id="chatbot-1", conversation_id="conv-1",
                query="add that to my cart", history=[], system_prompt_text="system prompt",
                max_tokens=800, temperature=0.3, enabled_tool_names=["build_cart_url"],
            )

        self.assertIsNotNone(result)
        self.assertEqual(result["widget_blocks"], [{
            "type": "cart_links",
            "items": [{"product_id": 12, "quantity": 1, "url": "https://example.test/?add-to-cart=12&quantity=1"}],
        }])
        event_types = [c[0][3] for c in mock_log.call_args_list]
        self.assertIn("tool_called", event_types)
        self.assertIn("cart_link_generated", event_types)

    def test_run_tool_calling_pipeline_returns_add_to_cart_intent_and_logs_nothing_but_tool_called(self):
        """The critical architectural guarantee behind add_to_cart: the
        tool call itself must NEVER log a cart_add_succeeded (or any other
        "it happened" event) — nothing has actually happened yet. Only
        tool_called (which just records the call was made, same as any
        other tool) is expected here; the real cart_add_succeeded only
        ever comes from ChatController::cartEvent(), after a genuine
        browser-side Store API success."""
        atc_tool = Tool(
            name="add_to_cart", description="test", access_level="read",
            parameters={"type": "object", "properties": {"items": {"type": "array"}}, "required": ["items"]},
            handler=lambda db, chatbot_id, **kw: {
                "items": [{"product_id": 12, "variation_id": None, "quantity": 1, "name": "Widget"}],
            },
        )

        tool_call_message = {
            "content": None,
            "tool_calls": [{"id": "call_1", "function": {"name": "add_to_cart", "arguments": '{"items": [{"product_id": 12, "name": "Widget"}]}'}}],
        }
        final_message = {"content": "Click below to add it to your cart.", "tool_calls": None}
        call_sequence = [
            (tool_call_message, {"prompt_tokens": 10, "completion_tokens": 5, "total_tokens": 15}),
            (final_message, {"prompt_tokens": 20, "completion_tokens": 8, "total_tokens": 28}),
        ]

        def fake_tool_calling_chat(db, messages, tools_schema, max_tokens, temperature):
            message, usage = call_sequence.pop(0)
            return message, "groq/test-tool-model", usage, 0.0

        with patch.object(tool_calling_service, "get_enabled_tools", return_value=[atc_tool]), \
             patch.object(tool_calling_service, "to_openai_schema", return_value=[{"type": "function", "function": {"name": "add_to_cart"}}]), \
             patch.object(tool_calling_service, "_tool_calling_chat", side_effect=fake_tool_calling_chat), \
             patch("app.services.rag_service._log_event") as mock_log:
            result = tool_calling_service.run_tool_calling_pipeline(
                db=MagicMock(), chatbot_id="chatbot-1", conversation_id="conv-1",
                query="add that to my cart", history=[], system_prompt_text="system prompt",
                max_tokens=800, temperature=0.3, enabled_tool_names=["add_to_cart"],
            )

        self.assertIsNotNone(result)
        self.assertEqual(result["widget_blocks"], [{
            "type": "add_to_cart",
            "items": [{"product_id": 12, "variation_id": None, "quantity": 1, "name": "Widget"}],
        }])
        event_types = [c[0][3] for c in mock_log.call_args_list]
        self.assertEqual(event_types, ["tool_called"], "add_to_cart must log nothing beyond tool_called — no cart_add_succeeded until a real browser click confirms it.")

    def test_run_tool_calling_pipeline_returns_payment_link_preview_and_logs_nothing_but_tool_called(self):
        """Same architectural guarantee as add_to_cart, for the actual-money
        case: the tool call itself is a preview only — no order exists yet,
        so no payment_link_created (or any other "it happened") event may
        be logged here. The real event only ever comes from
        ChatController::createPaymentLink(), after a genuine confirmed
        click creates a real WooCommerce draft order."""
        payment_tool = Tool(
            name="create_payment_link", description="test", access_level="read",
            parameters={"type": "object", "properties": {"items": {"type": "array"}}, "required": ["items"]},
            handler=lambda db, chatbot_id, **kw: {
                "items": [{"product_id": 12, "name": "Widget", "quantity": 1, "line_total": 90000}],
                "total": 90000, "currency": "IRT", "customer": None,
            },
        )

        tool_call_message = {
            "content": None,
            "tool_calls": [{"id": "call_1", "function": {"name": "create_payment_link", "arguments": '{"items": [{"product_id": 12}]}'}}],
        }
        final_message = {"content": "Here's your order summary — confirm to get a payment link.", "tool_calls": None}
        call_sequence = [
            (tool_call_message, {"prompt_tokens": 10, "completion_tokens": 5, "total_tokens": 15}),
            (final_message, {"prompt_tokens": 20, "completion_tokens": 8, "total_tokens": 28}),
        ]

        def fake_tool_calling_chat(db, messages, tools_schema, max_tokens, temperature):
            message, usage = call_sequence.pop(0)
            return message, "groq/test-tool-model", usage, 0.0

        with patch.object(tool_calling_service, "get_enabled_tools", return_value=[payment_tool]), \
             patch.object(tool_calling_service, "to_openai_schema", return_value=[{"type": "function", "function": {"name": "create_payment_link"}}]), \
             patch.object(tool_calling_service, "_tool_calling_chat", side_effect=fake_tool_calling_chat), \
             patch("app.services.rag_service._log_event") as mock_log:
            result = tool_calling_service.run_tool_calling_pipeline(
                db=MagicMock(), chatbot_id="chatbot-1", conversation_id="conv-1",
                query="I want to pay for this now", history=[], system_prompt_text="system prompt",
                max_tokens=800, temperature=0.3, enabled_tool_names=["create_payment_link"],
            )

        self.assertIsNotNone(result)
        self.assertEqual(result["widget_blocks"], [{
            "type": "payment_link_preview",
            "items": [{"product_id": 12, "name": "Widget", "quantity": 1, "line_total": 90000}],
            "total": 90000, "currency": "IRT", "customer": None,
        }])
        event_types = [c[0][3] for c in mock_log.call_args_list]
        self.assertEqual(event_types, ["tool_called"], "create_payment_link must log nothing beyond tool_called — no payment_link_created until a real confirmed click creates the order.")


if __name__ == "__main__":
    unittest.main()
