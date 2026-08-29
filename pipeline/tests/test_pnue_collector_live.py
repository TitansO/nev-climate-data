"""Live smoke test for the PNUE collector - hits the real UN SDG API.
Kept separate from test_pnue_collector.py (which is fully mocked) so it
can be run/skipped independently with `-m live`.
"""
import pytest

from pipeline.collectors.pnue import fetch_emissions_for_country, parse_emission


@pytest.mark.live
def test_real_api_returns_populated_senegal_data_and_the_total_filter_works():
    rows = list(fetch_emissions_for_country("686"))  # Senegal
    assert len(rows) > 0

    parsed = [parse_emission(row) for row in rows]
    kept = [p for p in parsed if p is not None]
    assert len(kept) > 0
    assert all(p["country_iso"] == "SEN" for p in kept)
    assert all(p["value_mt"] > 0 for p in kept)
    # Every kept row really is a distinct year - proves the Activity=="TOTAL"
    # filter removed the manufacturing-subset duplicate per year, not just
    # some other row.
    years = [p["year"] for p in kept]
    assert len(years) == len(set(years))
