"""Unit tests for the OPEC Fund collector's parsing/mapping logic - uses
mocked HTTP/Gemini responses rather than hitting the network, so this file
runs offline and fast. The live end-to-end test lives in
test_opec_fund_collector_live.py, kept separate so it can be
skipped independently if the external services are unreachable.
"""
import io
import json
from unittest.mock import MagicMock, patch

from pypdf import PdfWriter

from pipeline.collectors.opec_fund_climate_finance import (
    SOURCE_NAME,
    SOURCE_URL,
    build_payloads,
    collect_and_publish,
    country_name_to_iso3,
    validate_invariant,
)


def test_country_name_to_iso3_resolves_exact_match():
    assert country_name_to_iso3("Senegal") == "SEN"


def test_country_name_to_iso3_resolves_a_real_tricky_exact_name():
    # Verified live during B1.5's design: pycountry's exact get(name=...)
    # handles this correctly with no fuzzy fallback needed.
    assert country_name_to_iso3("Côte d'Ivoire") == "CIV"
    assert country_name_to_iso3("Türkiye") == "TUR"
    assert country_name_to_iso3("Viet Nam") == "VNM"


def test_country_name_to_iso3_falls_back_to_fuzzy_match():
    # Verified live: these two real names need the fuzzy fallback.
    assert country_name_to_iso3("Tanzania") == "TZA"
    assert country_name_to_iso3("Kyrgyz Republic") == "KGZ"


def test_country_name_to_iso3_returns_none_for_regional_entries():
    assert country_name_to_iso3("Africa (regional)") is None
    assert country_name_to_iso3("Regional Africa") is None
    assert country_name_to_iso3("Regional Latin America and the Caribbean") is None


def test_country_name_to_iso3_resolves_every_real_country_name_in_the_source_table():
    # The complete real distinct-country list extracted live from Annex 2
    # during B1.5's design work (111 rows) - every one of these must
    # resolve, and the three regional entries must not.
    real_names = [
        "Albania", "Argentina", "Armenia", "Azerbaijan", "Bangladesh", "Belize",
        "Benin", "Burundi", "Cameroon", "Chad", "China", "Colombia", "Comoros",
        "Cuba", "Côte d'Ivoire", "Dominican Republic", "Egypt", "Eswatini",
        "Ghana", "Guatemala", "Honduras", "India", "Jordan", "Kenya", "Kosovo",
        "Kyrgyz Republic", "Lesotho", "Liberia", "Madagascar", "Malawi",
        "Maldives", "Morocco", "Mozambique", "Nepal", "Nicaragua", "Niger",
        "North Macedonia", "Oman", "Pakistan", "Panama", "Papua New Guinea",
        "Paraguay", "Rwanda", "Saint Vincent and the Grenadines", "Senegal",
        "Seychelles", "Sierra Leone", "Tajikistan", "Tanzania", "Turkmenistan",
        "Türkiye", "Uganda", "Uzbekistan", "Viet Nam", "Zimbabwe",
    ]
    unresolved = [name for name in real_names if country_name_to_iso3(name) is None]
    assert unresolved == []


def test_validate_invariant_accepts_a_real_consistent_row():
    # Real row (Panama): 58.33 + 41.67 = 100.00.
    row = {"adaptation_pct": 58.33, "mitigation_pct": 41.67, "total_climate_pct": 100.0}
    assert validate_invariant(row) is True


def test_validate_invariant_rejects_an_inconsistent_row():
    row = {"adaptation_pct": 20.0, "mitigation_pct": 20.0, "total_climate_pct": 90.0}
    assert validate_invariant(row) is False


def test_validate_invariant_tolerates_real_rounding_noise():
    # Real row (Colombia): 29.27 + 43.90 = 73.17 exactly, but this asserts
    # the tolerance itself, not just an exact-sum row.
    row = {"adaptation_pct": 29.27, "mitigation_pct": 43.90, "total_climate_pct": 73.16}
    assert validate_invariant(row) is True


_SENEGAL_ROW = {
    "year": 2020,
    "country": "Senegal",
    "project": "Water Valorisation For Value Chains Development Project (Provale-CV)",
    "sector": "Agriculture and Livelihoods",
    "amount_usd_mn": 20.0,
    "adaptation_pct": 20.0,
    "mitigation_pct": 20.0,
    "total_climate_pct": 40.0,
}


def test_build_payloads_splits_a_row_with_both_dimensions_into_two_payloads():
    payloads = build_payloads(_SENEGAL_ROW, document_hash="abc123")

    assert len(payloads) == 2
    dimensions = {p["climate_dimension"] for p in payloads}
    assert dimensions == {"adaptation", "mitigation"}

    adaptation = next(p for p in payloads if p["climate_dimension"] == "adaptation")
    assert adaptation["source"] == "opec_fund_pdf"
    assert adaptation["country_iso"] == "SEN"
    assert adaptation["year"] == 2020
    assert adaptation["amount_usd"] == 4_000_000  # 20 * 1,000,000 * 20%
    assert adaptation["funding_type"] == "multilateral"
    assert adaptation["sector_label_raw"] == "Agriculture and Livelihoods"
    assert adaptation["project_name"] == "Water Valorisation For Value Chains Development Project (Provale-CV)"
    assert adaptation["document_hash"] == "abc123"

    mitigation = next(p for p in payloads if p["climate_dimension"] == "mitigation")
    assert mitigation["amount_usd"] == 4_000_000  # 20 * 1,000,000 * 20%


def test_build_payloads_produces_one_payload_when_only_one_dimension_is_nonzero():
    row = {**_SENEGAL_ROW, "adaptation_pct": 0.0, "mitigation_pct": 40.0, "total_climate_pct": 40.0}

    payloads = build_payloads(row, document_hash="abc123")

    assert len(payloads) == 1
    assert payloads[0]["climate_dimension"] == "mitigation"


def test_build_payloads_returns_nothing_for_an_unresolvable_country():
    row = {**_SENEGAL_ROW, "country": "Africa (regional)"}

    assert build_payloads(row, document_hash="abc123") == []


def test_build_payloads_returns_nothing_for_an_invariant_violation():
    row = {**_SENEGAL_ROW, "total_climate_pct": 99.0}  # 20 + 20 != 99

    assert build_payloads(row, document_hash="abc123") == []


def _fake_full_pdf() -> bytes:
    writer = PdfWriter()
    for _ in range(96):
        writer.add_blank_page(width=200, height=200)
    buffer = io.BytesIO()
    writer.write(buffer)
    return buffer.getvalue()


def test_collect_and_publish_skips_extraction_entirely_on_a_cache_hit():
    mock_producer = MagicMock()
    mock_response = MagicMock()
    mock_response.content = _fake_full_pdf()
    mock_response.raise_for_status = MagicMock()

    mock_connection = MagicMock()

    with patch("pipeline.collectors.opec_fund_climate_finance.requests.get", return_value=mock_response), \
         patch("pipeline.collectors.opec_fund_climate_finance.get_connection", return_value=mock_connection), \
         patch("pipeline.collectors.opec_fund_climate_finance.is_already_processed", return_value=True), \
         patch("pipeline.collectors.opec_fund_climate_finance.extract_json_via_gemini") as mock_extract:
        published = collect_and_publish(mock_producer)

    assert published == 0
    mock_extract.assert_not_called()
    mock_producer.send.assert_not_called()


def test_collect_and_publish_extracts_and_publishes_on_a_new_document():
    mock_producer = MagicMock()
    mock_response = MagicMock()
    mock_response.content = _fake_full_pdf()
    mock_response.raise_for_status = MagicMock()

    mock_connection = MagicMock()

    extraction_json = json.dumps([_SENEGAL_ROW])
    mock_minio_client = MagicMock()

    with patch("pipeline.collectors.opec_fund_climate_finance.requests.get", return_value=mock_response), \
         patch("pipeline.collectors.opec_fund_climate_finance.get_connection", return_value=mock_connection), \
         patch("pipeline.collectors.opec_fund_climate_finance.is_already_processed", return_value=False), \
         patch("pipeline.collectors.opec_fund_climate_finance.extract_json_via_gemini", return_value=extraction_json), \
         patch("pipeline.collectors.opec_fund_climate_finance.make_minio_client", return_value=mock_minio_client), \
         patch("pipeline.collectors.opec_fund_climate_finance.upload_to_minio") as mock_upload, \
         patch("pipeline.collectors.opec_fund_climate_finance.record_processed") as mock_record:
        published = collect_and_publish(mock_producer)

    assert published == 2  # Senegal row splits into adaptation + mitigation
    assert mock_producer.send.call_count == 2
    assert mock_producer.send.call_args_list[0][0][0] == "nev.funding.raw"
    mock_upload.assert_called_once()
    mock_record.assert_called_once()
    mock_producer.flush.assert_called_once()
