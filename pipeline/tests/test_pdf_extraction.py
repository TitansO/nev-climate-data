"""Tests for the shared PDF-extraction helpers (B1.5) - hashing, page
slicing, Gemini-call-with-retry, MinIO storage, and the hash cache. All
network/storage calls are mocked; the live end-to-end path is exercised
by test_opec_fund_collector_live.py instead.
"""
import hashlib
import io
from unittest.mock import MagicMock, patch

from google.genai import errors
from pypdf import PdfReader, PdfWriter

from pipeline.common.pdf_extraction import (
    MINIO_BUCKET,
    extract_json_via_gemini,
    is_already_processed,
    record_processed,
    sha256_hash,
    slice_pdf_pages,
    upload_to_minio,
)


def _make_multi_page_pdf(num_pages: int) -> bytes:
    writer = PdfWriter()
    for _ in range(num_pages):
        writer.add_blank_page(width=200, height=200)
    buffer = io.BytesIO()
    writer.write(buffer)
    return buffer.getvalue()


def test_sha256_hash_is_deterministic_and_matches_hashlib():
    data = b"real pdf bytes go here"
    assert sha256_hash(data) == hashlib.sha256(data).hexdigest()


def test_slice_pdf_pages_returns_only_the_requested_range():
    pdf_bytes = _make_multi_page_pdf(10)

    sliced = slice_pdf_pages(pdf_bytes, 3, 5)

    reader = PdfReader(io.BytesIO(sliced))
    assert len(reader.pages) == 3


def test_extract_json_via_gemini_retries_on_server_error_then_succeeds():
    mock_uploaded = MagicMock(uri="https://generativelanguage.googleapis.com/v1beta/files/abc123")
    mock_success_response = MagicMock(text='[{"year": 2020}]')
    mock_client = MagicMock()
    mock_client.files.upload.return_value = mock_uploaded
    mock_client.models.generate_content.side_effect = [
        errors.ServerError(503, {"error": {"message": "overloaded"}}),
        errors.ServerError(503, {"error": {"message": "overloaded"}}),
        mock_success_response,
    ]

    with patch("pipeline.common.pdf_extraction.genai.Client", return_value=mock_client), \
         patch("pipeline.common.pdf_extraction.time.sleep") as mock_sleep, \
         patch.dict("os.environ", {"GEMINI_API_KEY": "test-key"}):
        result = extract_json_via_gemini(b"fake pdf bytes", "extract this")

    assert result == '[{"year": 2020}]'
    assert mock_client.models.generate_content.call_count == 3
    assert mock_sleep.call_count == 2  # slept between attempts 1->2 and 2->3, not after success


def test_extract_json_via_gemini_gives_up_after_max_retries():
    mock_uploaded = MagicMock(uri="https://generativelanguage.googleapis.com/v1beta/files/abc123")
    mock_client = MagicMock()
    mock_client.files.upload.return_value = mock_uploaded
    mock_client.models.generate_content.side_effect = errors.ServerError(
        503, {"error": {"message": "overloaded"}}
    )

    with patch("pipeline.common.pdf_extraction.genai.Client", return_value=mock_client), \
         patch("pipeline.common.pdf_extraction.time.sleep"), \
         patch.dict("os.environ", {"GEMINI_API_KEY": "test-key"}):
        try:
            extract_json_via_gemini(b"fake pdf bytes", "extract this")
            assert False, "expected RuntimeError"
        except RuntimeError as exc:
            assert "5 attempts" in str(exc)

    assert mock_client.models.generate_content.call_count == 5


def test_upload_to_minio_creates_the_bucket_if_missing():
    mock_client = MagicMock()
    mock_client.bucket_exists.return_value = False

    upload_to_minio(mock_client, "bronze/test/file.pdf", b"pdf-bytes")

    mock_client.make_bucket.assert_called_once_with(MINIO_BUCKET)
    mock_client.put_object.assert_called_once()
    call_args = mock_client.put_object.call_args
    assert call_args[0][0] == MINIO_BUCKET
    assert call_args[0][1] == "bronze/test/file.pdf"


def test_upload_to_minio_skips_bucket_creation_when_it_already_exists():
    mock_client = MagicMock()
    mock_client.bucket_exists.return_value = True

    upload_to_minio(mock_client, "bronze/test/file.pdf", b"pdf-bytes")

    mock_client.make_bucket.assert_not_called()


def test_is_already_processed_true_when_hash_found():
    mock_cursor = MagicMock()
    mock_cursor.fetchone.return_value = (1,)

    assert is_already_processed(mock_cursor, "abc123") is True


def test_is_already_processed_false_when_hash_not_found():
    mock_cursor = MagicMock()
    mock_cursor.fetchone.return_value = None

    assert is_already_processed(mock_cursor, "abc123") is False


def test_record_processed_inserts_a_new_cache_row():
    mock_cursor = MagicMock()

    record_processed(
        mock_cursor,
        document_hash="abc123",
        source_name="OPEC Fund — Climate Finance Report (PDF, Gemini-assisted)",
        source_url="https://example.org/report.pdf",
        minio_path="bronze/opec-fund-climate-finance/2026-08-29/abc123.pdf",
        rows_extracted=111,
    )

    mock_cursor.execute.assert_called_once()
    sql, params = mock_cursor.execute.call_args[0]
    assert "INSERT INTO processed_document" in sql
    assert "ON CONFLICT (hash) DO NOTHING" in sql
    assert params == (
        "abc123",
        "OPEC Fund — Climate Finance Report (PDF, Gemini-assisted)",
        "https://example.org/report.pdf",
        "bronze/opec-fund-climate-finance/2026-08-29/abc123.pdf",
        111,
    )
