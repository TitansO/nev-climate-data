"""Unit tests for the BII collector's parsing logic - uses realistic
sample activities (field shapes captured from the real IATI Datastore
during this connector's design, 2026-10-08) rather than hitting the
network, so this file runs offline and fast.
"""
from unittest.mock import MagicMock, patch

from pipeline.collectors.bii import (
    _bii_commitment_summary,
    _dac_sector_codes,
    collect_and_publish,
    fetch_bii_activities,
    parse_activity,
)

# Reel (champs simplifies), verifie en direct le 2026-10-08 : un vrai
# projet solaire prive en Egypte, finance par BII.
_SAMPLE_ACTIVITY_SOLAR = {
    "iati_identifier": "GB-COH-03877777-F317701",
    "recipient_country_code": ["EG"],
    "sector_code": ["23230", "230"],
    "sector_vocabulary": ["1", "2"],
    "transaction_transaction_type_code": ["2"],
    "transaction_value": [45000000.0],
    "transaction_transaction_date_iso_date": ["2021-03-15T00:00:00Z"],
}

# Reel : meme secteur mais aucune transaction de type Commitment - ne
# doit rien publier.
_SAMPLE_ACTIVITY_NO_COMMITMENT = {
    "iati_identifier": "GB-COH-03877777-NO-COMMIT",
    "recipient_country_code": ["KE"],
    "sector_code": ["24030", "240"],
    "sector_vocabulary": ["1", "2"],
    "transaction_transaction_type_code": [],
    "transaction_value": [],
    "transaction_transaction_date_iso_date": [],
}


def test_dac_sector_codes_filters_to_vocabulary_1_only():
    assert _dac_sector_codes(_SAMPLE_ACTIVITY_SOLAR) == ["23230"]


def test_bii_commitment_summary_sums_only_commitment_transactions():
    result = _bii_commitment_summary(_SAMPLE_ACTIVITY_SOLAR)
    assert result == (45000000.0, 2021, "2021-03-15")


def test_bii_commitment_summary_returns_none_without_a_commitment_transaction():
    assert _bii_commitment_summary(_SAMPLE_ACTIVITY_NO_COMMITMENT) is None


def test_parse_activity_extracts_a_real_private_climate_project():
    payload = parse_activity(_SAMPLE_ACTIVITY_SOLAR)

    assert payload["source"] == "bii"
    assert payload["project_id"] == "GB-COH-03877777-F317701"
    assert payload["country_iso"] == "EGY"
    assert payload["year"] == 2021
    assert payload["amount_usd"] == 45000000
    assert payload["funding_type"] == "private"
    assert payload["raw_sector_codes"] == ["23230"]


def test_parse_activity_returns_none_without_a_commitment():
    assert parse_activity(_SAMPLE_ACTIVITY_NO_COMMITMENT) is None


def test_parse_activity_returns_none_without_a_recipient_country():
    activity = {**_SAMPLE_ACTIVITY_SOLAR, "recipient_country_code": []}
    assert parse_activity(activity) is None


def test_fetch_bii_activities_parses_the_real_single_page_response(monkeypatch):
    monkeypatch.setenv("IATI_API_KEY", "fake-key")
    mock_response = MagicMock()
    mock_response.json.return_value = {"response": {"docs": [{"iati_identifier": "A"}, {"iati_identifier": "B"}]}}

    with patch("pipeline.collectors.bii.requests.get", return_value=mock_response):
        results = list(fetch_bii_activities())

    assert results == [{"iati_identifier": "A"}, {"iati_identifier": "B"}]


def test_collect_and_publish_sends_only_parseable_activities_and_returns_count():
    mock_producer = MagicMock()

    with patch(
        "pipeline.collectors.bii.fetch_bii_activities",
        return_value=iter([_SAMPLE_ACTIVITY_SOLAR, _SAMPLE_ACTIVITY_NO_COMMITMENT]),
    ):
        published = collect_and_publish(mock_producer)

    assert published == 1
    mock_producer.send.assert_called_once()
    assert mock_producer.send.call_args[0][0] == "nev.funding.raw"
    assert mock_producer.send.call_args[1]["key"] == "bii:GB-COH-03877777-F317701:EGY"
    mock_producer.flush.assert_called_once()
