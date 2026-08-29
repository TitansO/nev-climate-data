"""Unit tests for the AfDB collector's parsing/pagination/currency logic -
uses mocked HTTP responses (real payload shapes captured from the live
IATI Datastore during the B1.3 design work) rather than hitting the
network, so this file runs offline and fast. The live-network smoke tests
live in test_afdb_collector_live.py, kept separate so they can be skipped
independently if the external APIs are unreachable.
"""
from unittest.mock import MagicMock, patch

from pipeline.collectors.afdb import (
    collect_and_publish,
    fetch_afdb_activities,
    fetch_xdr_to_usd_rate,
    parse_activity,
)

# Real activity, two AfDB-Group commitment transactions (same date - both
# count, summed) plus government/interest/disbursement noise mixed in the
# same activity - verified live 2026-08-28.
_SAMPLE_ACTIVITY_MULTI_COMMITMENT = {
    "iati_identifier": "46002-P-BF-AA0-032",
    "recipient_country_code": ["BF"],
    "sector_code": ["31110"],
    "transaction_transaction_type_code": ["2", "3", "5", "2", "3"],
    "transaction_provider_org_ref": [
        "XM-DAC-46003", "XM-DAC-46003", "XM-DAC-46003", "XM-DAC-46003", "XM-DAC-46003",
    ],
    "transaction_value": [9760000.0, 61345.73, 22862.47, 18240000.0, 135992.72],
    "transaction_transaction_date_iso_date": [
        "2023-02-27T00:00:00Z", "2024-06-30T00:00:00Z", "2024-02-29T00:00:00Z",
        "2023-02-27T00:00:00Z", "2024-06-15T00:00:00Z",
    ],
}

# Real activity: one AfDB-provided commitment (counts), one disbursement
# (wrong type, excluded), one government-provided "commitment" (right
# type, wrong provider - excluded). Proves the allowlist actually filters
# by provider, not just by transaction type. Verified live 2026-08-28.
_SAMPLE_ACTIVITY_EXCLUDES_GOVERNMENT_PROVIDER = {
    "iati_identifier": "46002-P-GQ-K00-001",
    "recipient_country_code": ["GQ"],
    "sector_code": ["51010"],
    "transaction_transaction_type_code": ["2", "3", "2"],
    "transaction_provider_org_ref": ["XM-DAC-46003", "XM-DAC-46003", "GQ-COA-GOV"],
    "transaction_value": [807403.72, 901194.08, 91474.0],
    "transaction_transaction_date_iso_date": [
        "1993-01-20T00:00:00Z", "2009-12-31T00:00:00Z", "1993-01-20T00:00:00Z",
    ],
}

# Synthetic: an activity whose only Commitment transaction is provided by
# GCF (via AfDB as implementing entity) - must produce nothing, or B1.2's
# GCF connector and this one would double-count the same real money.
_SAMPLE_ACTIVITY_GCF_ROUTED_THROUGH_AFDB = {
    "iati_identifier": "46002-P-XX-GCF-001",
    "recipient_country_code": ["KE"],
    "sector_code": ["23230"],
    "transaction_transaction_type_code": ["2"],
    "transaction_provider_org_ref": ["XM-DAC-GCF"],
    "transaction_value": [5000000.0],
    "transaction_transaction_date_iso_date": ["2022-01-01T00:00:00Z"],
}

# Synthetic: no recipient country at all - 986/5,604 real AfDB activities
# are like this (regional/institutional activities).
_SAMPLE_ACTIVITY_NO_COUNTRY = {
    "iati_identifier": "46002-P-ZZ-000-001",
    "recipient_country_code": [],
    "sector_code": ["31110"],
    "transaction_transaction_type_code": ["2"],
    "transaction_provider_org_ref": ["XM-DAC-46002"],
    "transaction_value": [1000000.0],
    "transaction_transaction_date_iso_date": ["2020-01-01T00:00:00Z"],
}


def test_parse_activity_sums_multiple_allowlisted_commitments():
    payload = parse_activity(_SAMPLE_ACTIVITY_MULTI_COMMITMENT, xdr_to_usd_rate=1.5)

    assert payload["source"] == "afdb"
    assert payload["project_id"] == "46002-P-BF-AA0-032"
    assert payload["country_iso"] == "BFA"  # converted from IATI's alpha-2 "BF"
    assert payload["year"] == 2023
    assert payload["original_amount"] == 28000000.0  # 9,760,000 + 18,240,000 - disbursements/interest excluded
    assert payload["original_currency"] == "XDR"
    assert payload["exchange_rate"] == 1.5
    assert payload["amount_usd"] == 42000000  # 28,000,000 * 1.5
    assert payload["funding_type"] == "multilateral"
    assert payload["raw_sector_codes"] == ["31110"]
    assert payload["board_approval_date"] == "2023-02-27"


def test_parse_activity_excludes_government_provided_commitment():
    payload = parse_activity(_SAMPLE_ACTIVITY_EXCLUDES_GOVERNMENT_PROVIDER, xdr_to_usd_rate=1.5)

    assert payload["original_amount"] == 807403.72  # only the AfDB-provided transaction
    assert payload["amount_usd"] == 1211106  # round(807403.72 * 1.5)
    assert payload["country_iso"] == "GNQ"
    assert payload["year"] == 1993


def test_parse_activity_excludes_commitments_from_other_funds_routed_through_afdb():
    assert parse_activity(_SAMPLE_ACTIVITY_GCF_ROUTED_THROUGH_AFDB, xdr_to_usd_rate=1.5) is None


def test_parse_activity_returns_none_when_no_recipient_country():
    assert parse_activity(_SAMPLE_ACTIVITY_NO_COUNTRY, xdr_to_usd_rate=1.5) is None


def test_fetch_afdb_activities_paginates_using_start_offset():
    page_one = {
        "response": {
            "numFound": 3,
            "docs": [{"iati_identifier": "A1"}, {"iati_identifier": "A2"}],
        },
    }
    page_two = {
        "response": {
            "numFound": 3,
            "docs": [{"iati_identifier": "A3"}],
        },
    }
    mock_response_one = MagicMock()
    mock_response_one.json.return_value = page_one
    mock_response_two = MagicMock()
    mock_response_two.json.return_value = page_two

    with patch(
        "pipeline.collectors.afdb.requests.get",
        side_effect=[mock_response_one, mock_response_two],
    ) as mock_get, patch("pipeline.collectors.afdb.time.sleep") as mock_sleep:
        results = list(fetch_afdb_activities())

    assert [a["iati_identifier"] for a in results] == ["A1", "A2", "A3"]
    assert mock_get.call_count == 2
    # Real rate limit on the IATI Datastore's free tier (confirmed live: 6
    # back-to-back requests reliably hit HTTP 429 partway through every
    # single run) - must pause between pages, not before the first request.
    mock_sleep.assert_called_once()
    assert mock_get.call_args_list[0].kwargs["params"]["start"] == 0
    assert mock_get.call_args_list[0].kwargs["params"]["q"] == 'reporting_org_ref:"XM-DAC-46002"'
    # Advances by documents actually received (2), not the requested page
    # size - confirms the loop doesn't stop early when a page is short.
    assert mock_get.call_args_list[1].kwargs["params"]["start"] == 2


def test_fetch_xdr_to_usd_rate_reads_usd_from_response():
    mock_response = MagicMock()
    mock_response.json.return_value = {"rates": {"USD": 1.370818, "EUR": 1.17}}

    with patch("pipeline.collectors.afdb.requests.get", return_value=mock_response):
        rate = fetch_xdr_to_usd_rate()

    assert rate == 1.370818


def test_collect_and_publish_fetches_rate_once_and_publishes_parseable_activities():
    activities_response = MagicMock()
    activities_response.json.return_value = {
        "response": {
            "numFound": 2,
            "docs": [_SAMPLE_ACTIVITY_MULTI_COMMITMENT, _SAMPLE_ACTIVITY_GCF_ROUTED_THROUGH_AFDB],
        },
    }
    rate_response = MagicMock()
    rate_response.json.return_value = {"rates": {"USD": 1.5}}
    mock_producer = MagicMock()

    with patch(
        "pipeline.collectors.afdb.requests.get",
        side_effect=[rate_response, activities_response],
    ):
        published = collect_and_publish(mock_producer)

    assert published == 1  # the multi-commitment activity only - GCF-routed one is excluded
    mock_producer.send.assert_called_once()
    assert mock_producer.send.call_args[0][0] == "nev.funding.raw"
    mock_producer.flush.assert_called_once()
