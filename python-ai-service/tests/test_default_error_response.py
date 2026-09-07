"""
Regression test: a real production error ("Sorry, I could not generate a
response") reached a Persian-speaking visitor as raw English. Root cause —
rag_service.py's own internal fallback (used when every LLM provider call
fails and the chatbot has no custom fallback_response) was a hardcoded
English string returned inside a normal 200 response, so Laravel's own
bilingual ChatService fallback never got a chance to run at all.
"""
import unittest

from app.services.rag_service import (
    _default_error_response,
    DEFAULT_ERROR_RESPONSE_EN,
    DEFAULT_ERROR_RESPONSE_FA,
)


class DefaultErrorResponseTest(unittest.TestCase):
    def test_persian_query_gets_persian_response(self):
        self.assertEqual(_default_error_response("محصول چت بات دارید؟"), DEFAULT_ERROR_RESPONSE_FA)

    def test_english_query_gets_english_response(self):
        self.assertEqual(_default_error_response("Do you sell a chatbot product?"), DEFAULT_ERROR_RESPONSE_EN)

    def test_neither_default_is_the_old_raw_placeholder(self):
        self.assertNotIn("Sorry, I could not generate a response.", DEFAULT_ERROR_RESPONSE_EN)
        self.assertNotIn("Sorry, I cannot process your request right now.", DEFAULT_ERROR_RESPONSE_EN)

    def test_response_invites_leaving_contact_info(self):
        self.assertTrue(
            "phone" in DEFAULT_ERROR_RESPONSE_EN.lower() or "email" in DEFAULT_ERROR_RESPONSE_EN.lower()
        )
        self.assertTrue(
            "تماس" in DEFAULT_ERROR_RESPONSE_FA or "ایمیل" in DEFAULT_ERROR_RESPONSE_FA
        )


if __name__ == "__main__":
    unittest.main()
