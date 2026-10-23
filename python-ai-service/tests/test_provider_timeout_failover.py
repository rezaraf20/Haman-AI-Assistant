"""
A real, measured investigation (2026-10-23) found a 28.5s gap in a real
customer turn was a single provider call succeeding slowly on its FIRST
attempt (no retries, no 429s, no errors at all) — direct, repeated calls
outside the whole pipeline, same key, same model, confirmed the variance
(0.71s to 31.82s for the identical request) is Google's own, not ours.

The fix: a short, configurable per-attempt timeout
(limits.llm_provider_attempt_timeout_seconds, default 8s) that fails over
to the next same-priority key the moment it's hit, instead of waiting out
a much longer per-profile timeout_seconds. A timeout must NOT be treated
like a real failure -- counting it toward a profile's consecutive-failure
auto-disable would eventually disable providers that are actually
healthy, purely because they got unlucky once (exactly the same reasoning
already applied to a 429 — see ProviderRateLimitedError).

This file exercises that behavior directly against
_tool_calling_chat/_chat_completion/_chat_completion_stream, mocking only
the underlying requests.post/requests.exceptions.Timeout, never the
retry/failover logic itself.
"""
import unittest
from unittest.mock import MagicMock, patch

import requests as _requests

from app.services import rag_service, tool_calling_service
from app.services.rag_service import ProviderTimeoutError
from app.services.tools.registry import Tool


def _profile(name, priority=0, timeout_seconds=30):
    return {
        "name": name, "provider": "openai_compatible", "model_name": "gemini-3.5-flash-lite",
        "base_url": "https://generativelanguage.googleapis.com/v1beta/openai/",
        "api_key": "test-key", "priority": priority, "timeout_seconds": timeout_seconds,
        "max_tokens_response": None, "input_price_per_1m_toman": 0, "output_price_per_1m_toman": 0,
    }


class ToolCallingTimeoutTest(unittest.TestCase):
    def test_a_timeout_fails_over_immediately_with_no_same_profile_retry(self):
        profiles = [_profile("key-1"), _profile("key-2")]
        calls = []

        def fake_post(*args, **kwargs):
            calls.append(kwargs.get("timeout"))
            if len(calls) == 1:
                raise _requests.exceptions.Timeout("simulated timeout")
            return MagicMock(
                status_code=200,
                json=lambda: {"choices": [{"message": {"content": "ok", "tool_calls": None}}], "usage": {"prompt_tokens": 100, "completion_tokens": 5}},
            )

        with patch.object(tool_calling_service.llm_provider_service, "get_active_tool_calling_profiles", return_value=profiles), \
             patch.object(tool_calling_service.llm_provider_service, "order_for_attempt", side_effect=lambda p: p), \
             patch.object(tool_calling_service._requests, "post", side_effect=fake_post), \
             patch.object(tool_calling_service.llm_provider_service, "record_outcome") as mock_record:
            message, model, usage, cost = tool_calling_service._tool_calling_chat(
                db=MagicMock(), messages=[{"role": "system", "content": "x"}], tools_schema=None, max_tokens=800, temperature=0.3,
            )

        self.assertEqual(message["content"], "ok")
        self.assertEqual(len(calls), 2, "exactly one attempt per profile — no same-profile retry on timeout")
        # key-1 timed out and must NEVER be recorded as a failure.
        for call_args in mock_record.call_args_list:
            self.assertNotEqual(call_args.args[1:], ("key-1", False), "a timeout must not count toward auto-disable")
        mock_record.assert_any_call(unittest.mock.ANY, "key-2", success=True)

    def test_the_request_timeout_is_the_platform_setting_not_the_profile_default(self):
        profiles = [_profile("key-1", timeout_seconds=30)]
        seen_timeouts = []

        def fake_post(*args, **kwargs):
            seen_timeouts.append(kwargs.get("timeout"))
            return MagicMock(
                status_code=200,
                json=lambda: {"choices": [{"message": {"content": "ok", "tool_calls": None}}], "usage": {"prompt_tokens": 1, "completion_tokens": 1}},
            )

        with patch.object(tool_calling_service.llm_provider_service, "get_active_tool_calling_profiles", return_value=profiles), \
             patch.object(tool_calling_service.llm_provider_service, "order_for_attempt", side_effect=lambda p: p), \
             patch.object(tool_calling_service._requests, "post", side_effect=fake_post), \
             patch.object(tool_calling_service.llm_provider_service, "record_outcome"):
            tool_calling_service._tool_calling_chat(
                db=MagicMock(), messages=[{"role": "system", "content": "x"}], tools_schema=None, max_tokens=800, temperature=0.3,
            )

        # Default platform setting is 8 (DEFAULTS in platform_settings_service.py),
        # well under the profile's own 30s -- min() must pick the shorter one.
        self.assertEqual(seen_timeouts, [8])

    def test_all_profiles_timing_out_still_raises_the_real_error(self):
        profiles = [_profile("key-1"), _profile("key-2")]

        def fake_post(*args, **kwargs):
            raise _requests.exceptions.Timeout("simulated timeout")

        with patch.object(tool_calling_service.llm_provider_service, "get_active_tool_calling_profiles", return_value=profiles), \
             patch.object(tool_calling_service.llm_provider_service, "order_for_attempt", side_effect=lambda p: p), \
             patch.object(tool_calling_service._requests, "post", side_effect=fake_post), \
             patch.object(tool_calling_service.llm_provider_service, "record_outcome") as mock_record:
            with self.assertRaises(ProviderTimeoutError):
                tool_calling_service._tool_calling_chat(
                    db=MagicMock(), messages=[{"role": "system", "content": "x"}], tools_schema=None, max_tokens=800, temperature=0.3,
                )

        mock_record.assert_not_called()


class PlainRagTimeoutTest(unittest.TestCase):
    def test_a_timeout_fails_over_immediately_with_no_same_profile_retry(self):
        profiles = [_profile("key-1"), _profile("key-2")]
        calls = []

        def fake_post(*args, **kwargs):
            calls.append(kwargs.get("timeout"))
            if len(calls) == 1:
                raise _requests.exceptions.Timeout("simulated timeout")
            return MagicMock(
                status_code=200,
                json=lambda: {"choices": [{"message": {"content": "a real answer"}}], "usage": {"prompt_tokens": 50, "completion_tokens": 5}},
            )

        with patch.object(rag_service.llm_provider_service, "get_active_profiles", return_value=profiles), \
             patch.object(rag_service.llm_provider_service, "order_for_attempt", side_effect=lambda p: p), \
             patch.object(rag_service._requests, "post", side_effect=fake_post), \
             patch.object(rag_service.llm_provider_service, "record_outcome") as mock_record, \
             patch("time.sleep"):
            answer, model, usage, cost = rag_service._chat_completion(db=MagicMock(), prompt="hi", max_tokens=800, temperature=0.3)

        self.assertEqual(answer, "a real answer")
        self.assertEqual(len(calls), 2)
        for call_args in mock_record.call_args_list:
            self.assertNotEqual(call_args.args[1:], ("key-1", False), "a timeout must not count toward auto-disable")

    def test_all_profiles_timing_out_raises_without_recording_a_failure(self):
        profiles = [_profile("key-1")]

        def fake_post(*args, **kwargs):
            raise _requests.exceptions.Timeout("simulated timeout")

        with patch.object(rag_service.llm_provider_service, "get_active_profiles", return_value=profiles), \
             patch.object(rag_service.llm_provider_service, "order_for_attempt", side_effect=lambda p: p), \
             patch.object(rag_service._requests, "post", side_effect=fake_post), \
             patch.object(rag_service.llm_provider_service, "record_outcome") as mock_record, \
             patch.object(rag_service.llm_provider_service, "record_all_providers_failed"), \
             patch("time.sleep"):
            with self.assertRaises(ProviderTimeoutError):
                rag_service._chat_completion(db=MagicMock(), prompt="hi", max_tokens=800, temperature=0.3)

        mock_record.assert_not_called()


class StreamingTimeoutTest(unittest.TestCase):
    def test_a_timeout_before_any_content_fails_over_to_the_next_profile(self):
        profiles = [_profile("key-1"), _profile("key-2")]
        calls = []

        def fake_post(*args, **kwargs):
            calls.append(kwargs.get("timeout"))
            if len(calls) == 1:
                raise _requests.exceptions.Timeout("simulated timeout")
            resp = MagicMock(status_code=200)
            resp.iter_content.return_value = [
                b'data: {"choices":[{"delta":{"content":"hi"}}]}\n\n',
                b'data: {"usage":{"prompt_tokens":1,"completion_tokens":1}}\n\n',
                b'data: [DONE]\n\n',
            ]
            return resp

        with patch.object(rag_service.llm_provider_service, "get_active_profiles", return_value=profiles), \
             patch.object(rag_service.llm_provider_service, "order_for_attempt", side_effect=lambda p: p), \
             patch.object(rag_service._requests, "post", side_effect=fake_post), \
             patch.object(rag_service.llm_provider_service, "record_outcome") as mock_record:
            events = list(rag_service._chat_completion_stream(db=MagicMock(), prompt="hi", max_tokens=800, temperature=0.3))

        kinds = [k for k, _ in events]
        self.assertIn("delta", kinds)
        self.assertIn("meta", kinds)
        self.assertEqual(len(calls), 2, "no same-profile retry on timeout, straight to the next key")
        for call_args in mock_record.call_args_list:
            self.assertNotEqual(call_args.args[1:], ("key-1", False), "a timeout must not count toward auto-disable")


if __name__ == "__main__":
    unittest.main()
