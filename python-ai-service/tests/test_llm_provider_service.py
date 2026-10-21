"""
llm_provider_service.py had no dedicated tests at all before this -- every
one of its Redis-backed behaviors (the provider-list cache, auto-disable,
and now LRU ordering/cooldown/outage-alert) was only ever exercised
indirectly through whatever test happened to patch _chat_completion.

The real incident this file is built around: a live test disabled every
provider on purpose to prove the admin alert fires, and it didn't --
get_active_profiles() returning an empty list takes an early-return path
in _chat_completion() that never called record_all_providers_failed() at
all. That path is "nothing was even active to try", a real customer-facing
outage every bit as real as "every profile was tried and failed", and it
had no test either, which is exactly how the gap went unnoticed until a
live run exposed it.
"""
import json
import unittest
from unittest.mock import MagicMock, patch

from app.services import llm_provider_service as lp


class OrderForAttemptTest(unittest.TestCase):
    def setUp(self):
        self.profiles = [
            {"name": "A", "priority": 0},
            {"name": "B", "priority": 0},
            {"name": "C", "priority": 0},
            {"name": "D", "priority": 1},
        ]

    def _fake_redis(self, store):
        fake = MagicMock()
        pipe = MagicMock()
        cmds = []
        pipe.get.side_effect = lambda k: cmds.append(k)
        pipe.execute.side_effect = lambda: [store.get(k) for k in cmds]
        fake.pipeline.return_value = pipe
        return fake

    def test_no_state_keeps_the_original_order(self):
        with patch.object(lp, "_redis", self._fake_redis({})):
            ordered = lp.order_for_attempt(self.profiles)
        self.assertEqual([p["name"] for p in ordered], ["A", "B", "C", "D"])

    def test_least_recently_used_goes_first_within_a_tier(self):
        store = {
            f"{lp.LRU_KEY_PREFIX}A": "100",
            f"{lp.LRU_KEY_PREFIX}B": "50",
            f"{lp.LRU_KEY_PREFIX}C": "200",
        }
        with patch.object(lp, "_redis", self._fake_redis(store)):
            ordered = lp.order_for_attempt(self.profiles)
        self.assertEqual([p["name"] for p in ordered], ["B", "A", "C", "D"])

    def test_a_cooled_down_profile_is_dropped_from_this_attempt(self):
        store = {f"{lp.COOLDOWN_KEY_PREFIX}B": "1"}
        with patch.object(lp, "_redis", self._fake_redis(store)):
            ordered = lp.order_for_attempt(self.profiles)
        self.assertEqual([p["name"] for p in ordered], ["A", "C", "D"])

    def test_a_whole_tier_in_cooldown_falls_through_to_the_next_tier(self):
        store = {
            f"{lp.COOLDOWN_KEY_PREFIX}A": "1",
            f"{lp.COOLDOWN_KEY_PREFIX}B": "1",
            f"{lp.COOLDOWN_KEY_PREFIX}C": "1",
        }
        with patch.object(lp, "_redis", self._fake_redis(store)):
            ordered = lp.order_for_attempt(self.profiles)
        self.assertEqual([p["name"] for p in ordered], ["D"])

    def test_redis_failure_falls_back_to_plain_priority_order(self):
        fake = MagicMock()
        fake.pipeline.side_effect = RuntimeError("redis is down")
        with patch.object(lp, "_redis", fake):
            ordered = lp.order_for_attempt(self.profiles)
        self.assertEqual([p["name"] for p in ordered], ["A", "B", "C", "D"])

    def test_empty_input_is_a_noop(self):
        self.assertEqual(lp.order_for_attempt([]), [])


class MarkAttemptedAndRateLimitedTest(unittest.TestCase):
    def test_mark_attempted_writes_the_lru_key(self):
        fake = MagicMock()
        with patch.object(lp, "_redis", fake):
            lp.mark_attempted("Groq New")
        fake.set.assert_called_once()
        self.assertEqual(fake.set.call_args[0][0], f"{lp.LRU_KEY_PREFIX}Groq New")

    def test_mark_rate_limited_sets_a_cooldown_with_ttl(self):
        fake = MagicMock()
        with patch.object(lp, "_redis", fake):
            lp.mark_rate_limited("Groq New")
        fake.set.assert_called_once_with(f"{lp.COOLDOWN_KEY_PREFIX}Groq New", "1", ex=lp.COOLDOWN_SECONDS)

    def test_neither_raises_when_redis_is_unreachable(self):
        fake = MagicMock()
        fake.set.side_effect = RuntimeError("down")
        with patch.object(lp, "_redis", fake):
            lp.mark_attempted("X")  # must not raise
            lp.mark_rate_limited("X")  # must not raise


class RecordAllProvidersFailedTest(unittest.TestCase):
    def test_sets_the_alert_key_with_the_reason(self):
        fake = MagicMock()
        with patch.object(lp, "_redis", fake):
            lp.record_all_providers_failed("no active profiles")
        fake.set.assert_called_once()
        args, kwargs = fake.set.call_args
        self.assertEqual(args[0], lp.ALL_PROVIDERS_FAILED_KEY)
        self.assertEqual(json.loads(args[1])["reason"], "no active profiles")
        self.assertEqual(kwargs["ex"], lp.ALL_PROVIDERS_FAILED_TTL_SECONDS)

    def test_does_not_raise_when_redis_is_unreachable(self):
        fake = MagicMock()
        fake.set.side_effect = RuntimeError("down")
        with patch.object(lp, "_redis", fake):
            lp.record_all_providers_failed("anything")  # must not raise


class ChatCompletionAlertsOnNoActiveProfilesTest(unittest.TestCase):
    """The exact gap a live test found: zero active profiles is a different
    code path from "every profile tried and failed", and it must raise the
    same alert -- a customer got nothing either way."""

    def test_chat_completion_alerts_when_no_profiles_are_active(self):
        from app.services.rag_service import _chat_completion

        db = MagicMock()
        with patch("app.services.llm_provider_service.get_active_profiles", return_value=[]), \
             patch("app.services.llm_provider_service.record_all_providers_failed") as alert:
            with self.assertRaises(RuntimeError):
                _chat_completion(db, "prompt", 800, 0.3)
            alert.assert_called_once()

    def test_chat_completion_stream_alerts_when_no_profiles_are_active(self):
        from app.services.rag_service import _chat_completion_stream

        db = MagicMock()
        with patch("app.services.llm_provider_service.get_active_profiles", return_value=[]), \
             patch("app.services.llm_provider_service.record_all_providers_failed") as alert:
            with self.assertRaises(RuntimeError):
                list(_chat_completion_stream(db, "prompt", 800, 0.3))
            alert.assert_called_once()


if __name__ == "__main__":
    unittest.main()
