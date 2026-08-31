"""Tests for extraction_pdf's 3 task functions (_extraire, _transformer,
_publier) and its cache short-circuit (a `cache_hit` flag propagated via
XCom, no dynamic Airflow branching) - see the 2026-08-31 multi-task DAG
refactor spec, decision 5. The business logic they call (slice_pdf_pages,
extract_json_via_gemini, build_payloads, record_processed) is already
covered by pipeline/tests/test_pdf_extraction.py and
pipeline/tests/test_opec_fund_collector.py, and is mocked here.
"""
import json
from unittest.mock import MagicMock, patch

from pipeline.dags.extraction_pdf import _extraire, _publier, _transformer


def _make_context(pulls=None):
    ti = MagicMock()
    ti.xcom_pull.side_effect = lambda task_ids, key: (pulls or {}).get(key)
    return {"ds": "2026-08-31", "ti": ti}


def _pushed(context, key):
    for call in context["ti"].xcom_push.call_args_list:
        if call.kwargs.get("key") == key:
            return call.kwargs.get("value")
    return None


def test_extraire_short_circuits_on_a_cache_hit():
    mock_response = MagicMock(content=b"pdf-bytes")
    context = _make_context()

    with patch("pipeline.dags.extraction_pdf.requests.get", return_value=mock_response), \
         patch("pipeline.dags.extraction_pdf.sha256_hash", return_value="abc123"), \
         patch("pipeline.dags.extraction_pdf.get_connection", return_value=MagicMock()), \
         patch("pipeline.dags.extraction_pdf.is_already_processed", return_value=True), \
         patch("pipeline.dags.extraction_pdf.make_minio_client") as mock_make_client, \
         patch("pipeline.dags.extraction_pdf.upload_bytes") as mock_upload:
        _extraire(**context)

    assert _pushed(context, "cache_hit") is True
    assert _pushed(context, "document_hash") == "abc123"
    mock_upload.assert_not_called()
    mock_make_client.assert_not_called()


def test_extraire_slices_and_stages_both_pdfs_on_a_cache_miss():
    mock_response = MagicMock(content=b"pdf-bytes")
    context = _make_context()

    with patch("pipeline.dags.extraction_pdf.requests.get", return_value=mock_response), \
         patch("pipeline.dags.extraction_pdf.sha256_hash", return_value="abc123"), \
         patch("pipeline.dags.extraction_pdf.get_connection", return_value=MagicMock()), \
         patch("pipeline.dags.extraction_pdf.is_already_processed", return_value=False), \
         patch("pipeline.dags.extraction_pdf.slice_pdf_pages", return_value=b"annex-bytes"), \
         patch("pipeline.dags.extraction_pdf.make_minio_client"), \
         patch("pipeline.dags.extraction_pdf.upload_bytes") as mock_upload:
        _extraire(**context)

    assert _pushed(context, "cache_hit") is False
    assert _pushed(context, "document_hash") == "abc123"
    assert _pushed(context, "minio_path_pdf").endswith("abc123.pdf")
    assert _pushed(context, "minio_path_annex").endswith("abc123-annex.pdf")
    assert mock_upload.call_count == 2


def test_transformer_short_circuits_when_extraire_reports_a_cache_hit():
    context = _make_context(pulls={"cache_hit": True})

    with patch("pipeline.dags.extraction_pdf.extract_json_via_gemini") as mock_gemini, \
         patch("pipeline.dags.extraction_pdf.make_minio_client") as mock_make_client:
        _transformer(**context)

    assert _pushed(context, "cache_hit") is True
    mock_gemini.assert_not_called()
    mock_make_client.assert_not_called()


def test_transformer_extracts_and_stages_payloads_on_a_cache_miss():
    context = _make_context(pulls={
        "cache_hit": False,
        "document_hash": "abc123",
        "minio_path_pdf": "bronze/opec-fund-climate-finance-2024/2026-08-31/abc123.pdf",
        "minio_path_annex": "bronze/opec-fund-climate-finance-2024/2026-08-31/abc123-annex.pdf",
    })
    # Real row shape from the B1.5 spec's own example (Senegal's PROVALE-CV) -
    # 20% adaptation + 20% mitigation, invariant holds (20 + 20 = 40).
    row = {
        "year": 2020, "country": "Senegal", "project": "PROVALE-CV", "sector": "Agriculture",
        "amount_usd_mn": 20, "adaptation_pct": 20, "mitigation_pct": 20, "total_climate_pct": 40,
    }

    with patch("pipeline.dags.extraction_pdf.make_minio_client"), \
         patch("pipeline.dags.extraction_pdf.download_bytes", return_value=b"annex-bytes"), \
         patch("pipeline.dags.extraction_pdf.extract_json_via_gemini", return_value=json.dumps([row])), \
         patch("pipeline.dags.extraction_pdf.upload_json") as mock_upload:
        _transformer(**context)

    assert _pushed(context, "cache_hit") is False
    assert _pushed(context, "document_hash") == "abc123"
    assert _pushed(context, "minio_path_pdf") == "bronze/opec-fund-climate-finance-2024/2026-08-31/abc123.pdf"
    assert _pushed(context, "rows_extracted") == 1
    mock_upload.assert_called_once()
    published_payloads = mock_upload.call_args[0][2]
    assert len(published_payloads) == 2  # adaptation + mitigation, both non-zero here


def test_publier_short_circuits_when_transformer_reports_a_cache_hit():
    context = _make_context(pulls={"cache_hit": True})

    with patch("pipeline.dags.extraction_pdf.make_minio_client") as mock_make_client, \
         patch("pipeline.dags.extraction_pdf.make_producer") as mock_make_producer:
        _publier(**context)

    assert _pushed(context, "published_count") == 0
    mock_make_client.assert_not_called()
    mock_make_producer.assert_not_called()


def test_publier_sends_payloads_and_records_the_cache_entry_on_a_cache_miss():
    context = _make_context(pulls={
        "cache_hit": False,
        "document_hash": "abc123",
        "minio_path_pdf": "bronze/opec-fund-climate-finance-2024/2026-08-31/abc123.pdf",
        "payloads_path": "silver/opec-fund-climate-finance-2024/2026-08-31/payloads.json",
        "rows_extracted": 1,
    })
    mock_producer = MagicMock()

    with patch("pipeline.dags.extraction_pdf.make_minio_client"), \
         patch("pipeline.dags.extraction_pdf.download_json", return_value=[{"a": 1}, {"a": 2}]), \
         patch("pipeline.dags.extraction_pdf.make_producer", return_value=mock_producer), \
         patch("pipeline.dags.extraction_pdf.get_connection", return_value=MagicMock()), \
         patch("pipeline.dags.extraction_pdf.record_processed") as mock_record:
        _publier(**context)

    assert mock_producer.send.call_count == 2
    mock_producer.flush.assert_called_once()
    mock_record.assert_called_once()
    record_kwargs = mock_record.call_args.kwargs
    assert record_kwargs["document_hash"] == "abc123"
    assert record_kwargs["minio_path"] == "bronze/opec-fund-climate-finance-2024/2026-08-31/abc123.pdf"
    assert record_kwargs["rows_extracted"] == 1
    assert _pushed(context, "published_count") == 2
