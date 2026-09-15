"""
Forcing a tool, and what happens when the model will not be forced.

Naming a function in tool_choice is how the intent classifier's decision is
made binding: the model is left with the arguments and nothing else. Two
things about that had to be learned from the live provider rather than
guessed, and both are pinned here.

The first is that the prompt has to agree with the constraint. The decision
prompt ends by telling the model it may call nothing and answer in prose,
which is the one thing a named tool_choice forbids, and Groq answers a 400 --
"Tool choice is required, but model did not call a tool" -- instead of
returning the prose. The same request with a prompt that says the tool is
already chosen returns the call.

The second is that routing is an opinion, not a guarantee. A model that still
refuses must not take the turn down with it.
"""
import unittest
from unittest.mock import MagicMock, patch

from app.services import tool_calling_service


PROFILE = {
    "name": "test", "provider": "groq", "model_name": "test-model",
    "base_url": "https://api.test/openai/v1", "api_key": "k",
    "max_tokens_response": 300, "timeout_seconds": 10,
}

SCHEMA = [{"type": "function", "function": {"name": "search_products"}}]


def _response(status, payload):
    resp = MagicMock()
    resp.status_code = status
    resp.json.return_value = payload
    resp.raise_for_status.return_value = None
    return resp


_REFUSED = {"error": {"message": "Tool choice is required, but model did not call a tool",
                      "type": "invalid_request_error", "code": "tool_use_failed"}}

_CALLED = {"choices": [{"message": {"content": None, "tool_calls": [
    {"id": "c1", "function": {"name": "search_products", "arguments": '{"query":"مربع"}'}}]}}],
    "usage": {"prompt_tokens": 10, "completion_tokens": 4, "total_tokens": 14}}


class ForcedToolChoiceTest(unittest.TestCase):
    def test_a_named_tool_is_sent_as_tool_choice(self):
        with patch.object(tool_calling_service._requests, "post",
                          return_value=_response(200, _CALLED)) as post:
            message, _usage = tool_calling_service._openai_tool_chat(
                PROFILE, [{"role": "user", "content": "قمقمه مربع موجوده؟"}],
                SCHEMA, 300, 0.3, forced_tool="search_products",
            )

        sent = post.call_args.kwargs["json"]
        self.assertEqual(
            sent["tool_choice"],
            {"type": "function", "function": {"name": "search_products"}},
        )
        self.assertEqual(message["tool_calls"][0]["function"]["name"], "search_products")

    def test_without_a_named_tool_the_model_chooses(self):
        with patch.object(tool_calling_service._requests, "post",
                          return_value=_response(200, _CALLED)) as post:
            tool_calling_service._openai_tool_chat(
                PROFILE, [{"role": "user", "content": "سلام"}], SCHEMA, 300, 0.3,
            )

        self.assertEqual(post.call_args.kwargs["json"]["tool_choice"], "auto")

    def test_a_refused_force_retries_with_a_free_choice(self):
        # The provider rejects the forced turn; the retry must drop the
        # constraint rather than the turn.
        responses = [_response(400, _REFUSED), _response(200, _CALLED)]

        with patch.object(tool_calling_service._requests, "post",
                          side_effect=responses) as post:
            message, _usage = tool_calling_service._openai_tool_chat(
                PROFILE, [{"role": "user", "content": "قمقمه مربع موجوده؟"}],
                SCHEMA, 300, 0.3, forced_tool="search_products",
            )

        self.assertEqual(post.call_count, 2)
        first, second = [c.kwargs["json"]["tool_choice"] for c in post.call_args_list]
        self.assertEqual(first, {"type": "function", "function": {"name": "search_products"}})
        self.assertEqual(second, "auto")
        self.assertEqual(message["tool_calls"][0]["function"]["name"], "search_products")

    def test_a_refused_force_that_keeps_failing_still_raises(self):
        # Two 400s in a row: the caller falls back to the retrieval pipeline
        # rather than this silently returning something empty.
        with patch.object(tool_calling_service._requests, "post",
                          side_effect=[_response(400, _REFUSED), _response(400, _REFUSED)]):
            with self.assertRaises(Exception):
                tool_calling_service._openai_tool_chat(
                    PROFILE, [{"role": "user", "content": "x"}],
                    SCHEMA, 300, 0.3, forced_tool="search_products",
                )


class ForcedPromptTest(unittest.TestCase):
    def test_a_forced_turn_gets_the_prompt_that_agrees_with_it(self):
        forced = tool_calling_service._decision_messages("سلام", [], forced="search_products")
        free = tool_calling_service._decision_messages("سلام", [])

        self.assertNotEqual(forced[0]["content"], free[0]["content"])
        # The free prompt offers the escape hatch that a named tool_choice
        # forbids; the forced one must not.
        self.assertIn("call nothing", free[0]["content"])
        self.assertNotIn("call nothing", forced[0]["content"])
        self.assertIn("already been chosen", forced[0]["content"])

    def test_neither_prompt_carries_context_or_grounding_rules(self):
        for messages in (
            tool_calling_service._decision_messages("سلام", []),
            tool_calling_service._decision_messages("سلام", [], forced="search_products"),
        ):
            self.assertNotIn("CONTEXT", messages[0]["content"])

    def test_both_prompts_forbid_inventing_arguments(self):
        for messages in (
            tool_calling_service._decision_messages("سلام", []),
            tool_calling_service._decision_messages("سلام", [], forced="get_order_status"),
        ):
            self.assertIn("Never invent an argument", messages[0]["content"])


if __name__ == "__main__":
    unittest.main()
