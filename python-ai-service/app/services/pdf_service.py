"""
Datasheet/PDF attachment handling — see class-hamman-product-sync.php for
where these get discovered (product downloads + attached media + links
inside the description) and SyncService::syncProducts() for how each one
becomes its own `documents` row (source_type='product_attachment',
raw_content left empty — this module is what actually fills it in).

Real interview quote this traces back to: the shop's most expensive
question type came from a datasheet and took 15 minutes, requiring
escalation to an engineer. The fix isn't just "index the PDF" — a wrong or
unattributed answer to a technical datasheet question is worse than no
answer, so every chunk here is tagged with its exact source page, and a
PDF with no extractable text (a scan) is skipped loudly rather than
silently answering from nothing.
"""
import io
import logging
from typing import List

import pdfplumber
import requests

from app.services.embedding_service import chunk_text, count_tokens

logger = logging.getLogger(__name__)

# "e.g. 10MB and 100 pages" per the spec — a catalog full of oversized
# datasheets would otherwise run up real embedding-API cost for content
# that's disproportionately unlikely to be the actual answer (a 100+ page
# datasheet is mostly boilerplate/compliance pages, not the one spec table
# a customer is asking about). Both are rejected outright (skipped, not
# truncated) so a customer never gets a citation to "page 47 of 300" from a
# file that was silently cut off at 100.
MAX_PDF_BYTES = 10 * 1024 * 1024
MAX_PDF_PAGES = 100

# Below this, treat the PDF as a scan with no usable text layer — OCR is
# explicitly out of scope (per the spec) rather than silently guessing.
MIN_EXTRACTABLE_CHARS = 20


class PdfTooLargeError(Exception):
    pass


class PdfTooManyPagesError(Exception):
    pass


def download_pdf(url: str, max_bytes: int = MAX_PDF_BYTES) -> bytes:
    """Streamed download with a hard byte cap enforced during the read, not
    just checked against a (spoofable, sometimes absent) Content-Length
    header — a server that lies about size or omits the header entirely
    must not be able to make this buffer an arbitrarily large response."""
    resp = requests.get(url, stream=True, timeout=30)
    resp.raise_for_status()

    chunks = []
    total = 0
    for chunk in resp.iter_content(chunk_size=65536):
        total += len(chunk)
        if total > max_bytes:
            raise PdfTooLargeError(f"PDF at {url} exceeds {max_bytes} bytes")
        chunks.append(chunk)
    return b"".join(chunks)


def extract_pdf_pages(pdf_bytes: bytes, max_pages: int = MAX_PDF_PAGES) -> List[str]:
    """One string per page, 1-indexed by the caller's enumerate() — this is
    what makes an exact page-number citation possible downstream. layout=True
    keeps a datasheet's row/column spacing roughly intact as plain text
    (the practical, lightweight way to preserve "table structure as much as
    possible" without a full table-extraction pass, which is slow and
    fragile on real-world scanned-then-printed datasheet PDFs)."""
    with pdfplumber.open(io.BytesIO(pdf_bytes)) as pdf:
        if len(pdf.pages) > max_pages:
            raise PdfTooManyPagesError(f"PDF has {len(pdf.pages)} pages, limit is {max_pages}")
        return [(page.extract_text(layout=True) or "") for page in pdf.pages]


def is_extractable(pages: List[str]) -> bool:
    total_chars = sum(len(p.strip()) for p in pages)
    return total_chars >= MIN_EXTRACTABLE_CHARS


def chunk_pdf_pages(pages: List[str], chunk_size: int = 512, overlap: int = 64) -> List[dict]:
    """Every chunk is tagged with exactly one page number — chunking never
    spans a page boundary, even if that means a very short trailing chunk,
    because "which page is this from" must stay unambiguous for citation
    (requirement: the source page matters more than the answer itself)."""
    result = []
    for page_num, page_text in enumerate(pages, start=1):
        for piece in chunk_text(page_text, chunk_size=chunk_size, overlap=overlap):
            result.append({"content": piece, "page": page_num})
    return result


def compute_embedding_cost_toman(chunks: List[dict], price_per_1m_toman: float) -> float:
    total_tokens = sum(count_tokens(c["content"]) for c in chunks)
    return (total_tokens / 1_000_000) * price_per_1m_toman
