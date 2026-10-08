"""Unit tests for the OECD CRS collector's parsing/filtering logic - uses
a realistic sample DataFrame (field shapes captured from the real, public
SDMX API during this connector's design, 2026-10-07) rather than hitting
the network, so this file runs offline and fast.
"""
from unittest.mock import MagicMock, patch

import pandas as pd

from pipeline.collectors.oecd_crs import (
    collect_and_publish,
    fetch_crs_rows,
    parse_row,
    resolve_country_iso,
)

# Reel (champs simplifies), verifie en direct le 2026-10-07 : chaque
# (pays, annee, projet) apparait 4 fois dans la reponse brute
# (Commitments/Disbursements x Current/Constant prices) - seule la ligne
# Commitments + Current prices doit survivre au filtre.
_RAW_ROWS = pd.DataFrame([
    {"recipient_name": "Kenya", "sector_code": 41030.0, "crs_id": "2022990919", "year": 2022,
     "value": 0.242535, "unit_multiplier": 6, "climate_mitigation": 1.0, "climate_adaptation": 1.0,
     "flow_type_code": "D", "amount_type": "Current prices"},
    {"recipient_name": "Kenya", "sector_code": 41030.0, "crs_id": "2022990919", "year": 2022,
     "value": 0.232496, "unit_multiplier": 6, "climate_mitigation": 1.0, "climate_adaptation": 1.0,
     "flow_type_code": "C", "amount_type": "Current prices"},
    {"recipient_name": "Kenya", "sector_code": 41030.0, "crs_id": "2022990919", "year": 2022,
     "value": 0.242535, "unit_multiplier": 6, "climate_mitigation": 1.0, "climate_adaptation": 1.0,
     "flow_type_code": "C", "amount_type": "Constant prices"},
    {"recipient_name": "Ghana", "sector_code": 15110.0, "crs_id": "2022990920", "year": 2022,
     "value": 5.0, "unit_multiplier": 6, "climate_mitigation": 0.0, "climate_adaptation": 0.0,
     "flow_type_code": "C", "amount_type": "Current prices"},
])


def test_fetch_crs_rows_dedups_to_commitments_current_prices_and_keeps_only_climate_rows():
    with patch("pipeline.collectors.oecd_crs.download_crs", return_value=_RAW_ROWS):
        rows = fetch_crs_rows(start_year=2022, end_year=2022)

    assert len(rows) == 1
    assert rows[0]["crs_id"] == "2022990919"
    assert rows[0]["value"] == 0.232496
    assert rows[0]["sector_code"] == "41030"
    assert rows[0]["recipient_name"] == "Kenya"
    assert rows[0]["climate_adaptation"] == 1.0


def test_resolve_country_iso_handles_a_real_dac_english_name():
    assert resolve_country_iso("Kenya") == "KEN"


def test_resolve_country_iso_handles_a_name_needing_fuzzy_matching():
    assert resolve_country_iso("Tanzania") == "TZA"


def test_resolve_country_iso_returns_none_for_an_unresolvable_name():
    assert resolve_country_iso("Pays Totalement Invente") is None


def test_resolve_country_iso_returns_none_for_none():
    assert resolve_country_iso(None) is None


_SAMPLE_RAW_ROW = {
    "year": 2022,
    "sector_code": "41030",
    "recipient_name": "Kenya",
    "crs_id": "2022990919",
    "value": 0.232496,
    "unit_multiplier": 6,
    "climate_adaptation": 1.0,
}


def test_parse_row_extracts_a_real_private_climate_row():
    payload = parse_row(_SAMPLE_RAW_ROW)

    assert payload["source"] == "oecd_crs"
    assert payload["project_id"] == "2022990919"
    assert payload["country_iso"] == "KEN"
    assert payload["year"] == 2022
    assert payload["amount_usd"] == round(0.232496 * 1_000_000)
    assert payload["funding_type"] == "private"
    assert payload["raw_sector_codes"] == ["41030"]
    assert payload["climate_adaptation_rio_marker"] is True


def test_parse_row_uses_the_real_unit_multiplier_not_a_hardcoded_million():
    row = {**_SAMPLE_RAW_ROW, "value": 5.0, "unit_multiplier": 3}  # thousands, not millions
    payload = parse_row(row)
    assert payload["amount_usd"] == 5000


def test_parse_row_returns_none_for_a_zero_value():
    row = {**_SAMPLE_RAW_ROW, "value": 0}
    assert parse_row(row) is None


def test_parse_row_returns_none_without_a_crs_id():
    row = {**_SAMPLE_RAW_ROW, "crs_id": None}
    assert parse_row(row) is None


def test_parse_row_returns_none_for_an_unresolvable_country():
    row = {**_SAMPLE_RAW_ROW, "recipient_name": "Pays Totalement Invente"}
    assert parse_row(row) is None


def test_parse_row_returns_none_without_a_year():
    row = {**_SAMPLE_RAW_ROW, "year": None}
    assert parse_row(row) is None


def test_parse_row_sets_the_rio_marker_false_when_absent():
    row = {**_SAMPLE_RAW_ROW, "climate_adaptation": 0.0}
    payload = parse_row(row)
    assert payload["climate_adaptation_rio_marker"] is False


def test_collect_and_publish_sends_only_parseable_rows_and_returns_count():
    mock_producer = MagicMock()
    unparseable_row = {**_SAMPLE_RAW_ROW, "crs_id": None}

    with patch(
        "pipeline.collectors.oecd_crs.fetch_crs_rows",
        return_value=[_SAMPLE_RAW_ROW, unparseable_row],
    ):
        published = collect_and_publish(mock_producer)

    assert published == 1
    mock_producer.send.assert_called_once()
    assert mock_producer.send.call_args[0][0] == "nev.funding.raw"
    assert mock_producer.send.call_args[1]["key"] == "oecd_crs:2022990919:KEN"
    mock_producer.flush.assert_called_once()
