"""Live smoke test against the real World Bank API - not mocked. Kept
separate from test_world_bank_collector.py so it can be skipped in an
environment without outbound network access without failing the rest of
the suite; run explicitly with `-m live`.
"""
import pytest

from pipeline.collectors.world_bank import fetch_projects_for_country, parse_project


@pytest.mark.live
def test_senegal_returns_at_least_one_parseable_project():
    projects = fetch_projects_for_country("SN")
    parsed = [parse_project(project) for project in projects]
    parsed = [item for item in parsed if item is not None]

    assert len(parsed) > 0
    first = parsed[0]
    assert first["country_iso"] == "SEN"  # converted from the API's alpha-2 "SN"
    assert first["funding_type"] == "multilateral"
    assert first["amount_usd"] > 0
