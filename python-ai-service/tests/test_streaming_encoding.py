"""
Regression test for a real production bug: _openai_compatible_chat_stream()
used to decode SSE lines with requests' iter_lines(decode_unicode=True),
which decodes using resp.encoding — and `requests` falls back to ISO-8859-1
per RFC 2616 whenever a response has no explicit charset, which is exactly
what Groq/xAI send for text/event-stream. Every non-ASCII character (Persian,
Arabic, etc.) came out as mojibake. Never caught before because ASCII text
(English) round-trips identically through Latin-1 and UTF-8.

This test builds a fake streamed response, deliberately splitting the byte
stream so a single multi-byte UTF-8 character (Persian) is cut across two
network chunks — the scenario the byte-buffering rewrite has to handle
correctly, not just "use utf-8 instead of latin-1".
"""
import json
import unittest
from unittest.mock import patch, MagicMock

from app.services.rag_service import _openai_compatible_chat_stream


def _sse_chunk(delta: str) -> bytes:
    payload = json.dumps({"choices": [{"delta": {"content": delta}}]})
    return f"data: {payload}\n\n".encode("utf-8")


class FakeStreamResponse:
    """Mimics the subset of requests.Response used by
    _openai_compatible_chat_stream(): raise_for_status() and
    iter_content(chunk_size=None), the latter yielding raw bytes chunks
    split at whatever boundary the test wants — including mid-character."""

    def __init__(self, raw_chunks):
        self._raw_chunks = raw_chunks

    def raise_for_status(self):
        return None

    def iter_content(self, chunk_size=None):
        yield from self._raw_chunks


class StreamingEncodingTest(unittest.TestCase):
    def test_persian_text_split_mid_character_survives_round_trip(self):
        persian_text = "سلام، شرکت هامان‌تک چطور می‌تواند کمک کند؟"
        body = _sse_chunk(persian_text) + b"data: [DONE]\n\n"

        # Cut the byte stream at every possible offset and confirm the
        # decoded text is byte-for-byte correct regardless of where a
        # multi-byte UTF-8 character got split across two network chunks.
        for split_at in range(1, len(body)):
            raw_chunks = [body[:split_at], body[split_at:]]
            with patch(
                "app.services.rag_service._requests.post",
                return_value=FakeStreamResponse(raw_chunks),
            ):
                deltas = []
                for kind, payload in _openai_compatible_chat_stream(
                    profile={"base_url": "https://example.test", "api_key": "x", "model_name": "m"},
                    prompt="p", max_tokens=10, temperature=0.0,
                ):
                    if kind == "delta":
                        deltas.append(payload)

                self.assertEqual(
                    "".join(deltas), persian_text,
                    f"corrupted when split at byte offset {split_at}",
                )

    def test_ascii_text_unaffected(self):
        english_text = "Hello, how can we help?"
        body = _sse_chunk(english_text) + b"data: [DONE]\n\n"

        with patch(
            "app.services.rag_service._requests.post",
            return_value=FakeStreamResponse([body]),
        ):
            deltas = []
            for kind, payload in _openai_compatible_chat_stream(
                profile={"base_url": "https://example.test", "api_key": "x", "model_name": "m"},
                prompt="p", max_tokens=10, temperature=0.0,
            ):
                if kind == "delta":
                    deltas.append(payload)

            self.assertEqual("".join(deltas), english_text)


if __name__ == "__main__":
    unittest.main()
