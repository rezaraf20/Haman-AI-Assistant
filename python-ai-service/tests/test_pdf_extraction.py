"""
Feature test for PDF datasheet extraction — see pdf_service.py's module
docstring for the full rationale (the real interview quote: the shop's
most expensive question type came from a datasheet, took 15 minutes, and
needed escalation to an engineer).

tests/fixtures/sample_datasheet.pdf is a real, valid 2-page PDF (built
with fpdf2, not hand-crafted bytes) with a distinguishing marker on page 2
— used to prove page-accurate extraction against an actual PDF file, not
just mocked behavior. tests/fixtures/scanned_no_text.pdf is a real PDF
containing only a rasterized image of the same content (no text layer at
all) — a genuine stand-in for a scanned datasheet, used to prove the
skip-cleanly-don't-crash path actually triggers on a real file.
"""
import os
import unittest
from unittest.mock import MagicMock, patch

from app.services import pdf_service

FIXTURES_DIR = os.path.join(os.path.dirname(__file__), "fixtures")


def _read_fixture(name: str) -> bytes:
    with open(os.path.join(FIXTURES_DIR, name), "rb") as f:
        return f.read()


class RealPdfExtractionTest(unittest.TestCase):
    """Runs pdf_service's actual extraction code against real PDF bytes —
    no mocking of pdfplumber itself, since that's the exact part most
    likely to break silently (a wrong library call, a version mismatch)."""

    def test_real_pdf_extracts_two_pages(self):
        pages = pdf_service.extract_pdf_pages(_read_fixture("sample_datasheet.pdf"))
        self.assertEqual(len(pages), 2)

    def test_real_pdf_is_extractable(self):
        pages = pdf_service.extract_pdf_pages(_read_fixture("sample_datasheet.pdf"))
        self.assertTrue(pdf_service.is_extractable(pages))

    def test_content_lands_on_the_correct_page(self):
        pages = pdf_service.extract_pdf_pages(_read_fixture("sample_datasheet.pdf"))
        self.assertIn("LM358N", pages[0])
        self.assertNotIn("HAMAN_TEST_MARKER_42", pages[0])
        self.assertIn("HAMAN_TEST_MARKER_42", pages[1])

    def test_chunks_are_tagged_with_the_right_page_number(self):
        pages = pdf_service.extract_pdf_pages(_read_fixture("sample_datasheet.pdf"))
        chunks = pdf_service.chunk_pdf_pages(pages)
        marker_chunks = [c for c in chunks if "HAMAN_TEST_MARKER_42" in c["content"]]
        self.assertTrue(marker_chunks, "The marker text should survive chunking.")
        self.assertEqual(marker_chunks[0]["page"], 2)

    def test_scanned_pdf_has_no_extractable_text(self):
        pages = pdf_service.extract_pdf_pages(_read_fixture("scanned_no_text.pdf"))
        self.assertFalse(pdf_service.is_extractable(pages))


class ChunkPdfPagesTest(unittest.TestCase):
    """Unit-level checks on the pure chunking function, independent of
    real PDF bytes — every chunk must stay attributable to exactly one
    page, since an ambiguous page number would undermine the whole point
    of citing a source."""

    def test_empty_pages_yield_no_chunks(self):
        self.assertEqual(pdf_service.chunk_pdf_pages(["", "", ""]), [])

    def test_each_chunk_carries_its_own_page_number(self):
        chunks = pdf_service.chunk_pdf_pages(["First page text.", "Second page text."])
        pages_seen = {c["page"] for c in chunks}
        self.assertEqual(pages_seen, {1, 2})

    def test_a_long_page_can_still_produce_multiple_chunks_on_that_page(self):
        long_text = " ".join([f"Sentence number {i}." for i in range(400)])
        chunks = pdf_service.chunk_pdf_pages([long_text], chunk_size=50, overlap=5)
        self.assertGreater(len(chunks), 1)
        self.assertTrue(all(c["page"] == 1 for c in chunks))


class IsExtractableTest(unittest.TestCase):
    def test_all_empty_pages_not_extractable(self):
        self.assertFalse(pdf_service.is_extractable(["", "", ""]))

    def test_whitespace_only_pages_not_extractable(self):
        self.assertFalse(pdf_service.is_extractable(["   \n\t  ", " "]))

    def test_real_text_is_extractable(self):
        self.assertTrue(pdf_service.is_extractable(["This datasheet has real, meaningful text content."]))


class DownloadPdfSizeLimitTest(unittest.TestCase):
    """download_pdf() must abort mid-stream on an oversized file rather
    than buffering an arbitrarily large response — a malicious or
    misconfigured server could otherwise lie about (or omit) Content-Length."""

    def test_raises_when_stream_exceeds_max_bytes(self):
        fake_response = MagicMock()
        fake_response.iter_content.return_value = [b"x" * 1000 for _ in range(20)]
        with patch("app.services.pdf_service.requests.get", return_value=fake_response):
            with self.assertRaises(pdf_service.PdfTooLargeError):
                pdf_service.download_pdf("https://example.test/big.pdf", max_bytes=5000)

    def test_succeeds_when_under_the_limit(self):
        fake_response = MagicMock()
        fake_response.iter_content.return_value = [b"x" * 100 for _ in range(3)]
        with patch("app.services.pdf_service.requests.get", return_value=fake_response):
            result = pdf_service.download_pdf("https://example.test/small.pdf", max_bytes=5000)
        self.assertEqual(len(result), 300)


class TooManyPagesTest(unittest.TestCase):
    def test_raises_when_page_count_exceeds_limit(self):
        with self.assertRaises(pdf_service.PdfTooManyPagesError):
            pdf_service.extract_pdf_pages(_read_fixture("sample_datasheet.pdf"), max_pages=1)

    def test_does_not_raise_when_within_limit(self):
        pages = pdf_service.extract_pdf_pages(_read_fixture("sample_datasheet.pdf"), max_pages=100)
        self.assertEqual(len(pages), 2)


class EmbeddingCostTest(unittest.TestCase):
    def test_zero_price_yields_zero_cost(self):
        chunks = [{"content": "some text", "page": 1}]
        self.assertEqual(pdf_service.compute_embedding_cost_toman(chunks, 0), 0)

    def test_nonzero_price_scales_with_token_count(self):
        chunks = [{"content": "word " * 1000, "page": 1}]
        cost = pdf_service.compute_embedding_cost_toman(chunks, price_per_1m_toman=1_000_000)
        self.assertGreater(cost, 0)


if __name__ == "__main__":
    unittest.main()
