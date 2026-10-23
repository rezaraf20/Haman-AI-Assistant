"""
The structural backstop against a retried HTTP call re-running the whole
chat pipeline — including a write-tool call — a second time while the
first attempt is still executing server-side. Real incident, 2026-10-22:
Laravel's client-side timeout fired on a genuinely slow (not dead)
python_ai call, its blind retry() sent an identical request, and the
original attempt (which had already called add_to_cart successfully) kept
running server-side, unaware it had been abandoned.

request_id (generated once per logical customer message by ChatService::
gatewayPayload(), see the Laravel side) is what lets _dedupe_chat_request()
below recognize "this is the same message arriving again" instead of
quietly running the pipeline — and any write-tool it reaches — twice.
"""
import json
import unittest
from unittest.mock import patch

from app.routers import chat as chat_router


class FakeRedis:
    """Enough of redis-py's contract (decode_responses=True semantics) to
    exercise the real lock/cache/poll logic, not a reimplementation of it."""

    def __init__(self):
        self.store = {}

    def set(self, key, value, nx=False, ex=None):
        if nx and key in self.store:
            return None
        self.store[key] = value
        return True

    def get(self, key):
        return self.store.get(key)

    def delete(self, key):
        self.store.pop(key, None)


class NoRequestIdTest(unittest.IsolatedAsyncioTestCase):
    async def test_no_request_id_skips_dedup_entirely(self):
        """Backward compatibility with an in-flight caller from before this
        field existed -- just run, never touch Redis."""
        calls = []

        async def run():
            calls.append(1)
            return {"response": "ok"}

        fake = FakeRedis()
        with patch.object(chat_router, "_redis", fake):
            result = await chat_router._dedupe_chat_request(None, run)

        self.assertEqual(result, {"response": "ok"})
        self.assertEqual(len(calls), 1)
        self.assertEqual(fake.store, {}, "Redis must never be touched when there is no request_id")


class FirstTimeSeenTest(unittest.IsolatedAsyncioTestCase):
    async def test_first_attempt_runs_once_and_caches_the_real_result(self):
        calls = []

        async def run():
            calls.append(1)
            return {"response": "ok", "widget_blocks": []}

        fake = FakeRedis()
        with patch.object(chat_router, "_redis", fake):
            result = await chat_router._dedupe_chat_request("req-1", run)

        self.assertEqual(result, {"response": "ok", "widget_blocks": []})
        self.assertEqual(len(calls), 1)
        cached = json.loads(fake.store["haman:chat_idem:req-1"])
        self.assertEqual(cached, {"response": "ok", "widget_blocks": []})

    async def test_a_failed_first_attempt_releases_the_lock(self):
        """Don't leave a poisoned 'processing forever' lock behind for a
        request that actually raised -- a genuine retry (or the customer
        trying again) must get a real attempt, not a stuck wait."""
        async def run():
            raise RuntimeError("boom")

        fake = FakeRedis()
        with patch.object(chat_router, "_redis", fake):
            with self.assertRaises(RuntimeError):
                await chat_router._dedupe_chat_request("req-1", run)

        self.assertNotIn("haman:chat_idem:req-1", fake.store)


class AlreadyCompletedTest(unittest.IsolatedAsyncioTestCase):
    async def test_a_retry_with_an_already_cached_result_never_runs_the_pipeline_again(self):
        """THE core safety property: a retry of a message whose first
        attempt already finished (and, critically, may already have called
        a write-tool like add_to_cart) must get that same cached result —
        never a second, independent pipeline run."""
        fake = FakeRedis()
        fake.store["haman:chat_idem:req-1"] = json.dumps({"response": "already done", "widget_blocks": [{"type": "add_to_cart"}]})

        async def run():
            self.fail("the pipeline must never run again for an already-completed request_id")

        with patch.object(chat_router, "_redis", fake):
            result = await chat_router._dedupe_chat_request("req-1", run)

        self.assertEqual(result, {"response": "already done", "widget_blocks": [{"type": "add_to_cart"}]})


class StillInFlightTest(unittest.IsolatedAsyncioTestCase):
    async def test_a_retry_while_the_first_attempt_is_still_running_waits_for_its_real_result(self):
        """The id is locked ('processing') right now -- the retry must wait
        for the genuine result rather than starting a second run, and must
        get that exact result once it lands."""
        fake = FakeRedis()
        fake.store["haman:chat_idem:req-1"] = chat_router._PROCESSING_MARKER

        async def run():
            self.fail("a second, concurrent run must never start while the first is still in flight")

        async def fake_sleep(_seconds):
            # The very first poll "discovers" the real result landed —
            # keeps this test fast and deterministic instead of racing a
            # real background task.
            fake.store["haman:chat_idem:req-1"] = json.dumps({"response": "the real answer"})

        with patch.object(chat_router, "_redis", fake), \
             patch("app.routers.chat.asyncio.sleep", side_effect=fake_sleep):
            result = await chat_router._dedupe_chat_request("req-1", run)

        self.assertEqual(result, {"response": "the real answer"})

    async def test_if_the_other_attempt_never_produces_a_result_this_caller_eventually_runs(self):
        """An extreme edge case (the other attempt's own process died
        without ever releasing or completing) -- waiting forever would just
        mean the customer never gets a reply at all, so once the key
        disappears entirely (lock TTL expired with no cached result ever
        written), this caller is allowed to run for real."""
        fake = FakeRedis()
        fake.store["haman:chat_idem:req-1"] = chat_router._PROCESSING_MARKER
        calls = []

        async def run():
            calls.append(1)
            return {"response": "ran because the other attempt vanished"}

        async def fake_sleep(_seconds):
            fake.store.pop("haman:chat_idem:req-1", None)

        with patch.object(chat_router, "_redis", fake), \
             patch("app.routers.chat.asyncio.sleep", side_effect=fake_sleep):
            result = await chat_router._dedupe_chat_request("req-1", run)

        self.assertEqual(len(calls), 1)
        self.assertEqual(result, {"response": "ran because the other attempt vanished"})


class RedisDownTest(unittest.IsolatedAsyncioTestCase):
    async def test_a_redis_outage_fails_open_not_closed(self):
        """A cache outage must never be the reason a real customer's chat
        breaks -- same best-effort posture as every other Redis-touching
        helper in this app (e.g. tool_calling_service._tool_rate_limited)."""
        calls = []

        async def run():
            calls.append(1)
            return {"response": "ran anyway"}

        class BrokenRedis:
            def set(self, *a, **kw):
                raise ConnectionError("redis is down")

        with patch.object(chat_router, "_redis", BrokenRedis()):
            result = await chat_router._dedupe_chat_request("req-1", run)

        self.assertEqual(result, {"response": "ran anyway"})
        self.assertEqual(len(calls), 1)


if __name__ == "__main__":
    unittest.main()
