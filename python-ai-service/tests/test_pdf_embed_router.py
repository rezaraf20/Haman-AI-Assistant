"""
Tests the DB-writing orchestration in embed.py's PDF path (_embed_pdf_attachment
/ _skip_document) — the part test_pdf_extraction.py's pure pdf_service tests
don't reach. get_embeddings() is mocked (it calls the real Gemini API), but
extraction/chunking runs for real against the same fixtures used there, so
this exercises the actual code path the running server executes end to end
except for the network calls (PDF download, embedding API).
"""
import os
import unittest
from unittest.mock import MagicMock, patch

from app.routers import embed as embed_router

FIXTURES_DIR = os.path.join(os.path.dirname(__file__), "fixtures")


def _read_fixture(name: str) -> bytes:
    with open(os.path.join(FIXTURES_DIR, name), "rb") as f:
        return f.read()


class EmbedPdfAttachmentTest(unittest.TestCase):
    def test_real_pdf_gets_indexed_with_page_tagged_chunks(self):
        db = MagicMock()
        with patch("app.routers.embed.pdf_service.download_pdf", return_value=_read_fixture("sample_datasheet.pdf")), \
             patch("app.routers.embed.get_embeddings", return_value=[[0.0] * 8, [0.0] * 8]):
            result = embed_router._embed_pdf_attachment(
                db, "doc-1", "chatbot-1", "https://example.test/datasheet.pdf",
                "LM358 Datasheet", {"product_id": 7001, "product_name": "LM358 Op-Amp"}, "english",
            )

        self.assertEqual(result["status"], "indexed")
        self.assertEqual(result["pages"], 2)
        self.assertEqual(result["chunks"], 2)

        insert_calls = [c for c in db.execute.call_args_list if "INSERT INTO chunks" in str(c.args[0])]
        self.assertEqual(len(insert_calls), 2)
        pages_inserted = sorted(json_page(c) for c in insert_calls)
        self.assertEqual(pages_inserted, [1, 2])

        update_calls = [c for c in db.execute.call_args_list if "UPDATE documents" in str(c.args[0])]
        self.assertEqual(len(update_calls), 1)
        self.assertIn("status='indexed'", str(update_calls[0].args[0]))

    def test_scanned_pdf_is_skipped_not_crashed(self):
        db = MagicMock()
        with patch("app.routers.embed.pdf_service.download_pdf", return_value=_read_fixture("scanned_no_text.pdf")):
            result = embed_router._embed_pdf_attachment(
                db, "doc-2", "chatbot-1", "https://example.test/scanned.pdf",
                "Scanned Datasheet", {}, "english",
            )

        self.assertEqual(result["status"], "skipped")
        insert_calls = [c for c in db.execute.call_args_list if "INSERT INTO chunks" in str(c.args[0])]
        self.assertEqual(insert_calls, [], "A scanned PDF must not produce any chunks.")

    def test_oversized_pdf_is_skipped(self):
        db = MagicMock()
        with patch("app.routers.embed.pdf_service.download_pdf", side_effect=embed_router.pdf_service.PdfTooLargeError("too big")):
            result = embed_router._embed_pdf_attachment(
                db, "doc-3", "chatbot-1", "https://example.test/huge.pdf", "Huge File", {}, "english",
            )
        self.assertEqual(result["status"], "skipped")
        self.assertIn("size limit", result["reason"])

    def test_too_many_pages_is_skipped(self):
        db = MagicMock()
        with patch("app.routers.embed.pdf_service.download_pdf", return_value=b"fake"), \
             patch("app.routers.embed.pdf_service.extract_pdf_pages", side_effect=embed_router.pdf_service.PdfTooManyPagesError("too many")):
            result = embed_router._embed_pdf_attachment(
                db, "doc-4", "chatbot-1", "https://example.test/long.pdf", "Long File", {}, "english",
            )
        self.assertEqual(result["status"], "skipped")
        self.assertIn("page limit", result["reason"])

    def test_missing_source_url_is_skipped_without_downloading(self):
        db = MagicMock()
        with patch("app.routers.embed.pdf_service.download_pdf") as mock_download:
            result = embed_router._embed_pdf_attachment(
                db, "doc-5", "chatbot-1", None, "No URL", {}, "english",
            )
        self.assertEqual(result["status"], "skipped")
        mock_download.assert_not_called()

    def test_genuine_network_error_propagates_not_swallowed(self):
        """A real transient failure (unlike a deliberate skip) must reach
        EmbedDocumentJob.php's normal retry/failed-status handling."""
        db = MagicMock()
        with patch("app.routers.embed.pdf_service.download_pdf", side_effect=ConnectionError("network down")):
            with self.assertRaises(ConnectionError):
                embed_router._embed_pdf_attachment(
                    db, "doc-6", "chatbot-1", "https://example.test/x.pdf", "X", {}, "english",
                )


def json_page(call) -> int:
    import json
    params = call.args[1]
    return json.loads(params["meta"])["page"]


if __name__ == "__main__":
    unittest.main()
