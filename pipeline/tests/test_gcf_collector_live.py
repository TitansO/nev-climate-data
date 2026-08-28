"""Live smoke test against the real IATI Datastore API - not mocked. Kept
separate from test_gcf_collector.py so it can be skipped in an environment
without outbound network access (or without IATI_API_KEY set) without
failing the rest of the suite; run explicitly with `-m live`.
"""
import pytest

from pipeline.collectors.gcf import fetch_gcf_activities, parse_activity


@pytest.mark.live
def test_gcf_portfolio_returns_parseable_activities_for_real():
    activities = list(fetch_gcf_activities())
    # GCF's real portfolio was 350 activities when this connector was
    # designed (verified live, exact-phrase query) - a generous floor that
    # would fail loudly if the query silently started matching nothing
    # (e.g. the reporting-org identifier ever changes).
    assert len(activities) > 300

    parsed = []
    for activity in activities:
        parsed.extend(parse_activity(activity))
    assert len(parsed) > 0

    first = parsed[0]
    assert first["source"] == "gcf"
    assert first["funding_type"] == "multilateral"
    assert first["amount_usd"] > 0
    assert len(first["country_iso"]) == 3  # alpha-3
