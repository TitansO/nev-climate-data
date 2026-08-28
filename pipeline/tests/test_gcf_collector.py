"""Unit tests for the GCF collector's parsing/splitting logic - uses
mocked HTTP responses (real payload shapes captured from the live IATI
Datastore during the B1.2 design work) rather than hitting the network, so
this file runs offline and fast. The live-network smoke test lives in
test_gcf_collector_live.py, kept separate so it can be skipped
independently if the external API is unreachable.
"""
from unittest.mock import MagicMock, patch

from pipeline.collectors.gcf import collect_and_publish, fetch_gcf_activities, parse_activity

# Real activity, single recipient country, verified live 2026-08-28.
_SAMPLE_ACTIVITY_SINGLE_COUNTRY = {
    "iati_identifier": "XM-DAC-41317-FP049",
    "recipient_country_code": ["SN"],
    "recipient_country_percentage": [100.0],
    "sector_code": ["16010", "16050"],
    "sector_percentage": [48.0, 52.0],
    "transaction_transaction_type_code": ["2", "3", "3", "3", "3"],
    "transaction_description_narrative": [
        "GCF financing commitment", "Disbursement", "Disbursement", "Disbursement", "Disbursement",
    ],
    "transaction_value": [9983521.0, 2495900.0, 2415917.0, 2519897.0, 2187614.0],
    "transaction_transaction_date_iso_date": [
        "2017-10-02T00:00:00Z", "2020-01-23T00:00:00Z", "2021-03-30T00:00:00Z",
        "2022-02-07T00:00:00Z", "2023-07-25T00:00:00Z",
    ],
}

# Real activity, two recipient countries split 50/50, with one "GCF
# financing commitment" AND one "Co-financing commitment" - verified live
# 2026-08-28 that both share provider_org_ref="XM-DAC-41317" (GCF), which
# is why that field cannot be used to tell them apart (spec decision 4).
_SAMPLE_ACTIVITY_MULTI_COUNTRY = {
    "iati_identifier": "XM-DAC-41317-FP103",
    "recipient_country_code": ["KE", "SN"],
    "recipient_country_percentage": [50.0, 50.0],
    "sector_code": ["23183"],
    "sector_percentage": [100.0],
    "transaction_transaction_type_code": ["2", "2", "3", "3", "3", "3", "3"],
    "transaction_description_narrative": [
        "GCF financing commitment", "Co-financing commitment", "Disbursement",
        "Disbursement", "Disbursement", "Disbursement", "Disbursement",
    ],
    "transaction_value": [43722728.0, 6134699.95, 7019405.4, 12074904.0, 11103360.0, 10293740.0, 3875766.6],
    "transaction_transaction_date_iso_date": [
        "2019-02-28T00:00:00Z", "2019-02-28T00:00:00Z", "2021-05-21T00:00:00Z",
        "2021-11-29T00:00:00Z", "2023-05-23T00:00:00Z", "2024-11-14T00:00:00Z", "2025-03-21T00:00:00Z",
    ],
}

# Synthetic: an activity with commitment transactions but none literally
# described "GCF financing commitment" - covers the pre-publish skip.
_SAMPLE_ACTIVITY_NO_GCF_COMMITMENT = {
    "iati_identifier": "XM-DAC-41317-FAKE",
    "recipient_country_code": ["SN"],
    "recipient_country_percentage": [100.0],
    "sector_code": ["23210"],
    "sector_percentage": [100.0],
    "transaction_transaction_type_code": ["2", "3"],
    "transaction_description_narrative": ["Co-financing commitment", "Disbursement"],
    "transaction_value": [1000000.0, 500000.0],
    "transaction_transaction_date_iso_date": ["2020-01-01T00:00:00Z", "2020-06-01T00:00:00Z"],
}


def test_parse_activity_single_country_sums_gcf_commitment_only():
    payloads = parse_activity(_SAMPLE_ACTIVITY_SINGLE_COUNTRY)

    assert len(payloads) == 1
    payload = payloads[0]
    assert payload["source"] == "gcf"
    assert payload["project_id"] == "XM-DAC-41317-FP049"
    assert payload["country_iso"] == "SEN"  # converted from IATI's alpha-2 "SN"
    assert payload["year"] == 2017
    assert payload["amount_usd"] == 9983521  # only the commitment transaction, not the 4 disbursements
    assert payload["funding_type"] == "multilateral"
    assert payload["raw_sector_codes"] == ["16010", "16050"]
    assert payload["board_approval_date"] == "2017-10-02"


def test_parse_activity_multi_country_prorates_amount_and_excludes_cofinancing():
    payloads = parse_activity(_SAMPLE_ACTIVITY_MULTI_COUNTRY)

    assert len(payloads) == 2
    by_country = {p["country_iso"]: p for p in payloads}
    assert set(by_country) == {"KEN", "SEN"}
    # 43722728 (GCF commitment only - excludes the 6134699.95 co-financing
    # line, even though both share provider_org_ref) split 50/50.
    assert by_country["KEN"]["amount_usd"] == 21861364
    assert by_country["SEN"]["amount_usd"] == 21861364
    assert by_country["KEN"]["year"] == 2019
    assert by_country["KEN"]["project_id"] == "XM-DAC-41317-FP103"


def test_parse_activity_returns_empty_list_when_no_gcf_commitment_transaction():
    assert parse_activity(_SAMPLE_ACTIVITY_NO_GCF_COMMITMENT) == []


def test_fetch_gcf_activities_uses_exact_phrase_query_and_yields_docs():
    mock_response = MagicMock()
    mock_response.json.return_value = {
        "response": {"docs": [_SAMPLE_ACTIVITY_SINGLE_COUNTRY, _SAMPLE_ACTIVITY_MULTI_COUNTRY]},
    }

    with patch("pipeline.collectors.gcf.requests.get", return_value=mock_response) as mock_get:
        results = list(fetch_gcf_activities())

    assert [a["iati_identifier"] for a in results] == ["XM-DAC-41317-FP049", "XM-DAC-41317-FP103"]
    call_params = mock_get.call_args.kwargs["params"]
    # Quoted/exact-phrase - an unquoted value tokenizes on the dashes in
    # "XM-DAC-41317" and matches ~269,000 unrelated documents (verified live).
    assert call_params["q"] == 'reporting_org_ref:"XM-DAC-41317"'


def test_collect_and_publish_sends_one_message_per_country_split_and_returns_count():
    mock_response = MagicMock()
    mock_response.json.return_value = {
        "response": {
            "docs": [
                _SAMPLE_ACTIVITY_SINGLE_COUNTRY,
                _SAMPLE_ACTIVITY_MULTI_COUNTRY,
                _SAMPLE_ACTIVITY_NO_GCF_COMMITMENT,
            ],
        },
    }
    mock_producer = MagicMock()

    with patch("pipeline.collectors.gcf.requests.get", return_value=mock_response):
        published = collect_and_publish(mock_producer)

    assert published == 3  # 1 (FP049) + 2 (FP103: KEN + SEN) + 0 (no GCF commitment)
    assert mock_producer.send.call_count == 3
    for call in mock_producer.send.call_args_list:
        assert call[0][0] == "nev.funding.raw"
    mock_producer.flush.assert_called_once()
