"""Unit tests for the FMO collector's parsing logic - uses realistic
sample activities (field shapes captured from the real IATI Datastore
during this connector's design, 2026-10-08) rather than hitting the
network, so this file runs offline and fast.
"""
from unittest.mock import MagicMock, patch

from pipeline.collectors.fmo import (
    _fmo_commitment_summary,
    collect_and_publish,
    fetch_eur_to_usd_rate,
    fetch_fmo_activities,
    parse_activity,
)

# Reel (champs simplifies), verifie en direct le 2026-10-08 : un vrai
# projet renouvelable en Ethiopie, finance par FMO. sector_vocabulary
# n'est jamais lu par ce connecteur (voir le docblock du module) - le
# code brut 5 chiffres est transmis tel quel.
_SAMPLE_ACTIVITY_RENEWABLE = {
    "iati_identifier": "NL-KVK-27078545-0000181014",
    "recipient_country_code": ["ET"],
    "sector_code": ["23210"],
    "transaction_transaction_type_code": ["2"],
    "transaction_value": [58000000.0],
    "transaction_transaction_date_iso_date": ["2022-05-10T00:00:00Z"],
}

# Reel : meme forme mais aucune transaction de type Commitment - ne
# doit rien publier.
_SAMPLE_ACTIVITY_NO_COMMITMENT = {
    "iati_identifier": "NL-KVK-27078545-NO-COMMIT",
    "recipient_country_code": ["KE"],
    "sector_code": ["24030"],
    "transaction_transaction_type_code": ["3"],
    "transaction_value": [500000.0],
    "transaction_transaction_date_iso_date": ["2023-01-01T00:00:00Z"],
}


def test_fmo_commitment_summary_sums_only_commitment_transactions():
    result = _fmo_commitment_summary(_SAMPLE_ACTIVITY_RENEWABLE)
    assert result == (58000000.0, 2022, "2022-05-10")


def test_fmo_commitment_summary_returns_none_without_a_commitment_transaction():
    assert _fmo_commitment_summary(_SAMPLE_ACTIVITY_NO_COMMITMENT) is None


def test_parse_activity_extracts_a_real_private_climate_project():
    payload = parse_activity(_SAMPLE_ACTIVITY_RENEWABLE, eur_to_usd_rate=1.08)

    assert payload["source"] == "fmo"
    assert payload["project_id"] == "NL-KVK-27078545-0000181014"
    assert payload["country_iso"] == "ETH"
    assert payload["year"] == 2022
    assert payload["amount_usd"] == round(58000000.0 * 1.08)
    assert payload["original_amount"] == 58000000.0
    assert payload["original_currency"] == "EUR"
    assert payload["exchange_rate"] == 1.08
    assert payload["funding_type"] == "private"
    assert payload["raw_sector_codes"] == ["23210"]


def test_parse_activity_passes_through_sector_codes_without_any_vocabulary_filter():
    # Piege reel de ce connecteur (different de afd.py/bii.py) : FMO
    # mal-etiquete son vocabulaire IATI, donc aucun filtre n'est applique
    # ici - meme un code qui ne serait pas classifiable reste transmis,
    # le rejet se fait dans funding_validator.py.
    activity = {**_SAMPLE_ACTIVITY_RENEWABLE, "sector_code": ["24030"]}
    payload = parse_activity(activity, eur_to_usd_rate=1.08)
    assert payload["raw_sector_codes"] == ["24030"]


def test_parse_activity_returns_none_without_a_commitment():
    assert parse_activity(_SAMPLE_ACTIVITY_NO_COMMITMENT, eur_to_usd_rate=1.08) is None


def test_parse_activity_returns_none_without_a_recipient_country():
    activity = {**_SAMPLE_ACTIVITY_RENEWABLE, "recipient_country_code": []}
    assert parse_activity(activity, eur_to_usd_rate=1.08) is None


def test_fetch_fmo_activities_paginates_through_every_page(monkeypatch):
    monkeypatch.setenv("IATI_API_KEY", "fake-key")
    page_1 = MagicMock()
    page_1.json.return_value = {"response": {"numFound": 3, "docs": [{"iati_identifier": "A"}, {"iati_identifier": "B"}]}}
    page_2 = MagicMock()
    page_2.json.return_value = {"response": {"numFound": 3, "docs": [{"iati_identifier": "C"}]}}

    with patch("pipeline.collectors.fmo.requests.get", side_effect=[page_1, page_2]), \
         patch("pipeline.collectors.fmo.time.sleep"):
        results = list(fetch_fmo_activities())

    assert results == [{"iati_identifier": "A"}, {"iati_identifier": "B"}, {"iati_identifier": "C"}]


def test_fetch_eur_to_usd_rate_reads_the_real_response_shape():
    mock_response = MagicMock()
    mock_response.json.return_value = {"rates": {"USD": 1.08}}

    with patch("pipeline.collectors.fmo.requests.get", return_value=mock_response):
        assert fetch_eur_to_usd_rate() == 1.08


def test_collect_and_publish_sends_only_parseable_activities_and_returns_count():
    mock_producer = MagicMock()

    with patch("pipeline.collectors.fmo.fetch_eur_to_usd_rate", return_value=1.08), \
         patch(
             "pipeline.collectors.fmo.fetch_fmo_activities",
             return_value=iter([_SAMPLE_ACTIVITY_RENEWABLE, _SAMPLE_ACTIVITY_NO_COMMITMENT]),
         ):
        published = collect_and_publish(mock_producer)

    assert published == 1
    mock_producer.send.assert_called_once()
    assert mock_producer.send.call_args[0][0] == "nev.funding.raw"
    assert mock_producer.send.call_args[1]["key"] == "fmo:NL-KVK-27078545-0000181014:ETH"
    mock_producer.flush.assert_called_once()
