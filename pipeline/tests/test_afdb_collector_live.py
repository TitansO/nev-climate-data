"""Live smoke tests against the real IATI Datastore and open.er-api.com
APIs - not mocked. Kept separate from test_afdb_collector.py so they can
be skipped in an environment without outbound network access without
failing the rest of the suite; run explicitly with `-m live`.
"""
import os

import pytest
import requests

from pipeline.collectors.afdb import (
    AFDB_REPORTING_ORG_REF,
    FIELDS,
    IATI_DATASTORE_URL,
    fetch_xdr_to_usd_rate,
    parse_activity,
)


@pytest.mark.live
def test_afdb_first_page_returns_parseable_activities_for_real():
    # Deliberately does not call fetch_afdb_activities() (which paginates
    # the full 5,604-activity portfolio) - one direct request for a small
    # page, matching the "kept cheap" requirement from the B1.3 spec's
    # testing approach.
    response = requests.get(
        IATI_DATASTORE_URL,
        headers={"Ocp-Apim-Subscription-Key": os.environ["IATI_API_KEY"]},
        params={
            "q": f'reporting_org_ref:"{AFDB_REPORTING_ORG_REF}"',
            "rows": 50,
            "wt": "json",
            "fl": FIELDS,
        },
        timeout=30,
    )
    response.raise_for_status()
    docs = response.json()["response"]["docs"]
    assert len(docs) == 50

    rate = fetch_xdr_to_usd_rate()
    parsed = [parse_activity(doc, rate) for doc in docs]
    parsed = [item for item in parsed if item is not None]
    assert len(parsed) > 0
    first = parsed[0]
    assert first["source"] == "afdb"
    assert first["original_currency"] == "XDR"
    assert first["amount_usd"] > 0
    assert len(first["country_iso"]) == 3


@pytest.mark.live
def test_xdr_to_usd_rate_is_a_plausible_positive_number():
    rate = fetch_xdr_to_usd_rate()
    # 1 XDR has historically traded in roughly the 1.0-1.8 USD range - a
    # wide, generous band that only fails if the API breaks contract
    # (e.g. starts returning 0, a negative number, or a wildly different
    # unit), not a currency-market assertion.
    assert 0.5 < rate < 3.0
