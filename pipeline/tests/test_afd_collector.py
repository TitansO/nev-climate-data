"""Unit tests for the AFD collector's parsing logic - uses realistic
sample activities (field shapes captured from the real IATI Datastore
during this connector's design, 2026-10-08) rather than hitting the
network, so this file runs offline and fast.
"""
from unittest.mock import MagicMock, patch

from pipeline.collectors.afd import (
    _afd_commitment_summary,
    _dac_sector_codes,
    collect_and_publish,
    fetch_afd_activities,
    fetch_eur_to_usd_rate,
    parse_activity,
)

# Reel (champs simplifies), verifie en direct le 2026-10-08 : un vrai
# projet de transport urbain a Dakar, finance par l'AFD.
_SAMPLE_ACTIVITY_TRANSPORT = {
    "iati_identifier": "FR-3-CSN155205",
    "recipient_country_code": ["SN"],
    "sector_code": ["21012", "6"],
    "sector_vocabulary": ["1", "99"],
    "transaction_transaction_type_code": ["2", "3"],
    "transaction_value": [3500000.0, 1000000.0],
    "transaction_transaction_date_iso_date": ["2023-06-13T00:00:00Z", "2026-08-31T00:00:00Z"],
}

# Reel : meme forme mais sans aucune transaction de type Commitment
# (seulement Disbursement) - ne doit rien publier (voir
# _afd_commitment_summary).
_SAMPLE_ACTIVITY_NO_COMMITMENT = {
    "iati_identifier": "FR-3-NO-COMMIT",
    "recipient_country_code": ["SN"],
    "sector_code": ["21012", "6"],
    "sector_vocabulary": ["1", "99"],
    "transaction_transaction_type_code": ["3"],
    "transaction_value": [500000.0],
    "transaction_transaction_date_iso_date": ["2023-01-01T00:00:00Z"],
}

# Reel : aucune transaction du tout (certaines activites reelles de ce
# portefeuille n'en reportent aucune) - ne doit rien publier.
_SAMPLE_ACTIVITY_NO_TRANSACTIONS = {
    "iati_identifier": "FR-3-CZZ3386",
    "recipient_country_code": ["SN"],
    "sector_code": ["15160", "7"],
    "sector_vocabulary": ["1", "99"],
    "transaction_transaction_type_code": None,
    "transaction_value": None,
    "transaction_transaction_date_iso_date": None,
}

# Reel : un engagement anterieur a 1992 (plancher Rio/CCNUCC) ne doit
# pas compter - meme principe que afdb.py.
_SAMPLE_ACTIVITY_TOO_OLD = {
    "iati_identifier": "FR-3-OLD",
    "recipient_country_code": ["SN"],
    "sector_code": ["21012", "6"],
    "sector_vocabulary": ["1", "99"],
    "transaction_transaction_type_code": ["2"],
    "transaction_value": [100000.0],
    "transaction_transaction_date_iso_date": ["1985-01-01T00:00:00Z"],
}


def test_dac_sector_codes_filters_to_vocabulary_1_only():
    assert _dac_sector_codes(_SAMPLE_ACTIVITY_TRANSPORT) == ["21012"]


def test_afd_commitment_summary_sums_only_commitment_transactions():
    result = _afd_commitment_summary(_SAMPLE_ACTIVITY_TRANSPORT)
    assert result == (3500000.0, 2023, "2023-06-13")


def test_afd_commitment_summary_returns_none_without_a_commitment_transaction():
    assert _afd_commitment_summary(_SAMPLE_ACTIVITY_NO_COMMITMENT) is None


def test_afd_commitment_summary_returns_none_without_any_transactions():
    assert _afd_commitment_summary(_SAMPLE_ACTIVITY_NO_TRANSACTIONS) is None


def test_afd_commitment_summary_excludes_a_commitment_before_the_rio_floor():
    assert _afd_commitment_summary(_SAMPLE_ACTIVITY_TOO_OLD) is None


def test_parse_activity_extracts_a_real_public_climate_project():
    payload = parse_activity(_SAMPLE_ACTIVITY_TRANSPORT, eur_to_usd_rate=1.08)

    assert payload["source"] == "afd"
    assert payload["project_id"] == "FR-3-CSN155205"
    assert payload["country_iso"] == "SEN"
    assert payload["year"] == 2023
    assert payload["amount_usd"] == round(3500000.0 * 1.08)
    assert payload["original_amount"] == 3500000.0
    assert payload["original_currency"] == "EUR"
    assert payload["exchange_rate"] == 1.08
    assert payload["funding_type"] == "public"
    assert payload["raw_sector_codes"] == ["21012"]


def test_parse_activity_returns_none_without_a_commitment():
    assert parse_activity(_SAMPLE_ACTIVITY_NO_COMMITMENT, eur_to_usd_rate=1.08) is None


def test_parse_activity_returns_none_without_a_recipient_country():
    activity = {**_SAMPLE_ACTIVITY_TRANSPORT, "recipient_country_code": []}
    assert parse_activity(activity, eur_to_usd_rate=1.08) is None


def test_fetch_afd_activities_paginates_through_every_page(monkeypatch):
    monkeypatch.setenv("IATI_API_KEY", "fake-key")
    page_1 = MagicMock()
    page_1.json.return_value = {"response": {"numFound": 3, "docs": [{"iati_identifier": "A"}, {"iati_identifier": "B"}]}}
    page_2 = MagicMock()
    page_2.json.return_value = {"response": {"numFound": 3, "docs": [{"iati_identifier": "C"}]}}

    with patch("pipeline.collectors.afd.requests.get", side_effect=[page_1, page_2]), \
         patch("pipeline.collectors.afd.time.sleep"):
        results = list(fetch_afd_activities())

    assert results == [{"iati_identifier": "A"}, {"iati_identifier": "B"}, {"iati_identifier": "C"}]


def test_fetch_eur_to_usd_rate_reads_the_real_response_shape():
    mock_response = MagicMock()
    mock_response.json.return_value = {"rates": {"USD": 1.08}}

    with patch("pipeline.collectors.afd.requests.get", return_value=mock_response):
        assert fetch_eur_to_usd_rate() == 1.08


def test_collect_and_publish_sends_only_parseable_activities_and_returns_count():
    mock_producer = MagicMock()

    with patch("pipeline.collectors.afd.fetch_eur_to_usd_rate", return_value=1.08), \
         patch(
             "pipeline.collectors.afd.fetch_afd_activities",
             return_value=iter([_SAMPLE_ACTIVITY_TRANSPORT, _SAMPLE_ACTIVITY_NO_COMMITMENT]),
         ):
        published = collect_and_publish(mock_producer)

    assert published == 1
    mock_producer.send.assert_called_once()
    assert mock_producer.send.call_args[0][0] == "nev.funding.raw"
    assert mock_producer.send.call_args[1]["key"] == "afd:FR-3-CSN155205:SEN"
    mock_producer.flush.assert_called_once()
