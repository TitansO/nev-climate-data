"""Unit tests for the PNUE collector's parsing/mapping logic - uses mocked
HTTP responses (real payload shapes captured from the live UN SDG API
during the B1.4 design work) rather than hitting the network, so this file
runs offline and fast. The live smoke test lives in
test_pnue_collector_live.py, kept separate so it can be skipped
independently if the external API is unreachable.
"""
from unittest.mock import MagicMock, patch

from pipeline.collectors.pnue import (
    collect_and_publish,
    country_iso3_to_m49,
    fetch_emissions_for_country,
    parse_emission,
)


def test_country_iso3_to_m49_converts_known_code():
    # Senegal: ISO 3166-1 numeric 686, verified live to be the same code
    # the UN SDG API expects as areaCode (querying areaCode=686 returns
    # Senegal's real data).
    assert country_iso3_to_m49("SEN") == "686"


def test_country_iso3_to_m49_returns_none_for_unknown_code():
    assert country_iso3_to_m49("ZZZ") is None


# Real row shapes captured live from the UN SDG API for Senegal, year
# 2000 (areaCode=686) - the two Activity dimension values that exist for
# the same country/year.
_ROW_TOTAL = {
    "geoAreaCode": "686",
    "timePeriodStart": 2000.0,
    "value": "3.52",
    "dimensions": {"Reporting Type": "G", "Activity": "TOTAL"},
}
_ROW_MANUFACTURING_SUBSET = {
    "geoAreaCode": "686",
    "timePeriodStart": 2000.0,
    "value": "0.5",
    "dimensions": {"Reporting Type": "G", "Activity": "ISIC4_C10T32X19"},
}


def test_parse_emission_keeps_the_total_activity_row():
    payload = parse_emission(_ROW_TOTAL, "SEN")

    assert payload["source"] == "pnue"
    assert payload["country_iso"] == "SEN"
    assert payload["year"] == 2000
    assert payload["value_mt"] == 3.52


def test_parse_emission_discards_the_manufacturing_subset_row():
    assert parse_emission(_ROW_MANUFACTURING_SUBSET, "SEN") is None


def test_parse_emission_uses_the_caller_provided_country_iso_not_geo_area_code():
    # Real bug found during B1.4's end-to-end verification:
    # pycountry.countries.get(numeric=...) requires zero-padding ("024"
    # for Angola), but the SDG API returns geoAreaCode unpadded ("24") for
    # any country under numeric code 100 - reverse-deriving country_iso
    # from geoAreaCode wrongly quarantined real Angola data as
    # unknown_country. parse_emission must use the caller-provided
    # country_iso, not row["geoAreaCode"], regardless of its format.
    row = {
        "geoAreaCode": "24",  # Angola, unpadded - as the real API returns it
        "timePeriodStart": 2000.0,
        "value": "4.63",
        "dimensions": {"Reporting Type": "G", "Activity": "TOTAL"},
    }

    payload = parse_emission(row, "AGO")

    assert payload["country_iso"] == "AGO"


def test_fetch_emissions_for_country_queries_the_series_for_the_given_area():
    mock_response = MagicMock()
    mock_response.json.return_value = {"data": [_ROW_TOTAL, _ROW_MANUFACTURING_SUBSET]}

    with patch("pipeline.collectors.pnue.requests.get", return_value=mock_response) as mock_get:
        rows = list(fetch_emissions_for_country("686"))

    assert rows == [_ROW_TOTAL, _ROW_MANUFACTURING_SUBSET]
    assert mock_get.call_args.kwargs["params"]["seriesCode"] == "EN_ATM_CO2"
    assert mock_get.call_args.kwargs["params"]["areaCode"] == "686"


def test_collect_and_publish_fetches_and_publishes_total_rows_per_country():
    mock_producer = MagicMock()
    mock_response = MagicMock()
    mock_response.json.return_value = {"data": [_ROW_TOTAL, _ROW_MANUFACTURING_SUBSET]}

    with patch("pipeline.collectors.pnue.requests.get", return_value=mock_response) as mock_get:
        published = collect_and_publish(["SEN"], mock_producer)

    assert published == 1  # only the TOTAL row
    mock_producer.send.assert_called_once()
    assert mock_producer.send.call_args[0][0] == "nev.emissions.raw"
    assert mock_get.call_args.kwargs["params"]["areaCode"] == "686"
    mock_producer.flush.assert_called_once()


def test_collect_and_publish_skips_a_country_pycountry_does_not_recognize():
    mock_producer = MagicMock()

    with patch("pipeline.collectors.pnue.requests.get") as mock_get:
        published = collect_and_publish(["ZZZ"], mock_producer)

    assert published == 0
    mock_get.assert_not_called()  # never even queries the API for an unmappable country
    mock_producer.flush.assert_called_once()
