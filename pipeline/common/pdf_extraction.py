"""Shared helpers for PDF-report extraction connectors (B1.5) - hashing,
page-range slicing, Gemini-assisted structured extraction with retry, and
MinIO Bronze storage. Source-specific collectors (e.g.
pipeline/collectors/opec_fund_climate_finance.py) import from here; this
module has no source-specific knowledge (URLs, page ranges, prompts) of
its own - see the B1.5 spec's "Scope boundary" for why this split exists.
"""
from __future__ import annotations

import hashlib
import io
import os
import time

import httpx
from google import genai
from google.genai import errors, types
from pypdf import PdfReader, PdfWriter

from pipeline.common.minio_staging import MINIO_BUCKET, make_minio_client
from pipeline.common.minio_staging import upload_bytes as upload_to_minio

# Pinned - see B1.5 spec decision 1: "gemini-flash-latest" hit repeated real
# HTTP 503s on the real target document, "gemini-2.5-flash" is fully retired
# (HTTP 404: "no longer available to new users") for new API keys.
GEMINI_MODEL = "gemini-3.5-flash"
# Real finding during B1.5's end-to-end verification: genuine
# httpx.ReadTimeouts occurred against the real target document at 180s and
# even 300s budgets, running inside the `airflow` container specifically
# (a real successful call from the `funding-validator` container earlier in
# this same connector's design work took 168s) - pointing to real, variable
# local network conditions in this environment rather than a fixed
# processing time. Raised to 600s for real headroom.
GEMINI_REQUEST_TIMEOUT_MS = 600_000
GEMINI_MAX_RETRIES = 5
# Real transient overload delay observed live during this connector's design
# work - not a documented rate limit, an empirically-sized backoff.
GEMINI_RETRY_DELAY_SECONDS = 20


def sha256_hash(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def slice_pdf_pages(pdf_bytes: bytes, start_page: int, end_page: int) -> bytes:
    """Returns a new PDF containing only pages `start_page`..`end_page`
    (1-indexed, inclusive) of `pdf_bytes` - keeps the Gemini request small
    and reliable. See B1.5 spec decision 2: sending a large whole report
    measurably increased the real HTTP 503 rate during this connector's
    design work; an 8-page slice of the real target document did not.
    """
    reader = PdfReader(io.BytesIO(pdf_bytes))
    writer = PdfWriter()
    for page in reader.pages[start_page - 1:end_page]:
        writer.add_page(page)
    buffer = io.BytesIO()
    writer.write(buffer)
    return buffer.getvalue()


def extract_json_via_gemini(pdf_bytes: bytes, prompt: str) -> str:
    """Uploads `pdf_bytes` to Gemini's File API and asks `GEMINI_MODEL` to
    answer `prompt`, retrying real transient errors - HTTP 503 ("high
    demand", a `google.genai.errors.ServerError`) and genuine network
    read/connect timeouts (raw `httpx` exceptions, NOT wrapped as a
    `ServerError` - a real gap found during B1.5's end-to-end verification:
    an earlier version of this function only caught `ServerError`, so a
    real `httpx.ReadTimeout` crashed the whole task immediately instead of
    retrying) - up to GEMINI_MAX_RETRIES times. Returns the raw response
    text; the caller parses it as JSON.
    """
    client = genai.Client(
        api_key=os.environ["GEMINI_API_KEY"],
        http_options=types.HttpOptions(timeout=GEMINI_REQUEST_TIMEOUT_MS),
    )
    uploaded = client.files.upload(
        file=io.BytesIO(pdf_bytes), config={"mime_type": "application/pdf"}
    )

    last_error: Exception | None = None
    for attempt in range(GEMINI_MAX_RETRIES):
        try:
            response = client.models.generate_content(
                model=GEMINI_MODEL,
                contents=[
                    {"file_data": {"file_uri": uploaded.uri, "mime_type": "application/pdf"}},
                    prompt,
                ],
            )
            return response.text
        except (errors.ServerError, httpx.TimeoutException, httpx.TransportError) as exc:
            last_error = exc
            if attempt < GEMINI_MAX_RETRIES - 1:
                time.sleep(GEMINI_RETRY_DELAY_SECONDS)
    raise RuntimeError(
        f"Gemini extraction failed after {GEMINI_MAX_RETRIES} attempts"
    ) from last_error


def is_already_processed(cursor, document_hash: str) -> bool:
    cursor.execute("SELECT 1 FROM processed_document WHERE hash = %s", (document_hash,))
    return cursor.fetchone() is not None


def record_processed(cursor, *, document_hash: str, source_name: str, source_url: str,
                      minio_path: str, rows_extracted: int) -> None:
    cursor.execute(
        """
        INSERT INTO processed_document (hash, source_name, source_url, minio_path, rows_extracted, processed_at)
        VALUES (%s, %s, %s, %s, %s, now())
        ON CONFLICT (hash) DO NOTHING
        """,
        (document_hash, source_name, source_url, minio_path, rows_extracted),
    )
