# B1.3 AfDB Connector Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Collect African Development Bank Group (BAD/AfDB) project financing for every NEV-tracked country, quarterly, and land it in the `funding` table via the shared Volet B pipeline — the first connector to convert a non-USD currency and populate `Funding.originalAmount`/`originalCurrency`/`exchangeRate` for real.

**Architecture:** A new `pipeline/collectors/afdb.py` paginates AfDB's entire IATI Datastore portfolio (5,604 activities — too large for one request, unlike B1.2), sums each activity's real AfDB-Group-provided commitment (filtered by a fixed entity allowlist, not free text), converts XDR to USD via `open.er-api.com`, and publishes to the **existing** `nev.funding.raw` topic (`source: "afdb"`). The **existing** `funding-validator` service is extended to dispatch to a new `pipeline/processors/sector_mapping_afdb.py` and to persist the new currency-provenance fields. No new Kafka topics or services.

**Tech Stack:** Python 3.12, `requests`, `pycountry` (already in the shared pipeline image), IATI Datastore REST API (same `IATI_API_KEY` as B1.2), `open.er-api.com` (free, no key), Apache Airflow 2.9 (existing `airflow` service), TimescaleDB (existing `funding`/`source`/`country`/`sector` tables — `original_amount`/`original_currency`/`exchange_rate` columns already exist since A1.3, unpopulated until this plan), pytest.

## Global Constraints

- No new Kafka topics or services — reuses the existing `nev.funding.raw` topic and `funding-validator` service (spec decision 1 / architecture spec decision 4).
- `fundingType` is always `Multilateral` (B1.3 spec decision 3).
- Exact-phrase Solr query required: `q=reporting_org_ref:"XM-DAC-46002"` (quoted — same Solr tokenizing pitfall as B1.2).
- Full pagination required — no server-side climate filter exists for this source (B1.3 spec decisions 1-2).
- Currency: AfDB reports 100% in XDR. Convert via `https://open.er-api.com/v6/latest/XDR` (`rates.USD`) — the ECB structurally cannot serve this currency (B1.3 spec decision 4). Populate `original_amount`/`original_currency`/`exchange_rate` on every row this connector writes.
- Financing entity allowlist — only these IATI provider refs count as real AfDB Group financing on a Commitment (`type = "2"`) transaction: `XM-DAC-46002` (AfDB), `XM-DAC-46003` (ADF), `XM-DAC-NTF` (Nigerian Trust Fund). Everything else (government counterpart commitments, other funds routed through AfDB — including `XM-DAC-GCF`, which would double-count against B1.2) is excluded (B1.3 spec decision 6).
- No multi-country splitting logic — AfDB activities are never multi-country (verified; B1.3 spec decision 5). Activities with no recipient country at all are skipped pre-publish.
- `year` = the year of the earliest allowlisted Commitment transaction (B1.3 spec decision 7).
- Sector mapping: fixed DAC 5-digit code table, first NEV-sector-in-priority-order with any matching code wins, no percentage weighting — same mechanism as B1.2, different (larger, more conservative) table. Never map generic energy-policy (`23111`) or non-renewable/coal codes (`23310`, `23320`) to Renewable Energy; never map generic environmental-policy (`41010`) to Adaptation (B1.3 spec decision 8).
- Cancelled activities (`activity_status_code = "5"`) are **not** filtered out — a formal Board commitment is a real historical event regardless of later cancellation.
- Repo root for all paths below: `nev-climate-data/`. All `docker compose` commands run from that root.

---

## Task 1: AfDB sector-mapping function

**Files:**
- Create: `pipeline/processors/sector_mapping_afdb.py`
- Test: `pipeline/tests/test_sector_mapping_afdb.py`

**Interfaces:**
- Produces: `map_afdb_sector(sector_codes: list[str]) -> str | None`. Consumed by Task 3's `process_message`.

- [ ] **Step 1: Write the failing tests**

```python
from pipeline.processors.sector_mapping_afdb import map_afdb_sector


def test_maps_solar_energy_code():
    assert map_afdb_sector(["23230"]) == "Renewable Energy"


def test_maps_hydro_code():
    assert map_afdb_sector(["23220"]) == "Renewable Energy"


def test_maps_wind_code():
    assert map_afdb_sector(["23240"]) == "Renewable Energy"


def test_generic_energy_policy_alone_is_unclassifiable():
    # 23111 is AfDB's 4th most frequent code overall (315 occurrences) but
    # does not specify renewable vs non-renewable - must not default to
    # Renewable Energy. See B1.3 spec decision 8.
    assert map_afdb_sector(["23111"]) is None


def test_coal_power_is_never_renewable_energy():
    # Confirmed present in AfDB's real portfolio - the whole reason this
    # connector needs its own careful table instead of reusing a loose
    # "energy" keyword match.
    assert map_afdb_sector(["23320"]) is None
    assert map_afdb_sector(["23310"]) is None


def test_maps_national_road_construction():
    assert map_afdb_sector(["21023"]) == "Sustainable Transport"


def test_maps_public_transport_services():
    assert map_afdb_sector(["21012"]) == "Sustainable Transport"


def test_maps_agricultural_policy():
    assert map_afdb_sector(["31110"]) == "Agriculture"


def test_maps_livestock():
    assert map_afdb_sector(["31163"]) == "Agriculture"


def test_maps_forestry_development():
    assert map_afdb_sector(["31220"]) == "Forestry"


def test_generic_environmental_policy_is_unclassifiable():
    # 41010 is the only "environmental" code present in AfDB's real
    # portfolio - too broad to imply climate adaptation. Neither of
    # B1.2's Adaptation codes (43060, 41030) appear anywhere in AfDB's
    # data (verified live) - Adaptation is never populated by this
    # connector, a real gap, not a bug.
    assert map_afdb_sector(["41010"]) is None


def test_priority_order_renewable_energy_before_agriculture():
    assert map_afdb_sector(["31110", "23230"]) == "Renewable Energy"


def test_returns_none_for_no_codes_at_all():
    assert map_afdb_sector([]) is None


def test_returns_none_for_general_budget_support():
    # 51010 - AfDB's 2nd most frequent code (615 occurrences) - not a NEV sector.
    assert map_afdb_sector(["51010"]) is None
```

Save as `pipeline/tests/test_sector_mapping_afdb.py`.

- [ ] **Step 2: Run to verify it fails**

```bash
docker compose run --rm funding-validator python -m pytest pipeline/tests/test_sector_mapping_afdb.py -v
```
Expected: `ModuleNotFoundError: No module named 'pipeline.processors.sector_mapping_afdb'`.

- [ ] **Step 3: Write the implementation**

```python
"""Maps an AfDB activity's DAC 5-digit sector codes onto one of NEV's
five funding sectors, per the ordered table in
docs/superpowers/specs/2026-08-28-b13-afdb-connector-design.md (decision
8). Returns None - triggering quarantine - when no code matches.
"""
from __future__ import annotations

# Ordered: the first NEV sector (in this list's order) that has ANY
# matching DAC code in the activity wins - same mechanism as B1.2's
# map_gcf_sector, continuing that established policy, but a larger and
# more conservative table: AfDB funds general development (health,
# education, roads, budget support, mining...), not exclusively climate
# projects, so this table must actively exclude codes that sound
# energy/environment-adjacent but aren't specific enough to be safe.
#
# Deliberately excludes 23111 ("Energy sector policy, planning and
# administration" - generic, does not specify renewable vs non-renewable)
# and 23310/23320 (non-renewable/coal power generation - confirmed present
# in AfDB's real portfolio) from Renewable Energy: only technology-specific
# renewable generation codes count. Excludes 41010 ("Environmental policy
# and administrative management", the only "environmental" code present in
# AfDB's real data) from Adaptation - too generic to imply climate
# adaptation specifically. Neither 43060 (Disaster Risk Reduction) nor
# 41030 (Biodiversity) - the two codes B1.2's Adaptation bucket relies on
# for GCF - appear anywhere in AfDB's real portfolio (verified live, 0
# occurrences each): Adaptation is never populated by this connector, a
# real, verified gap, not a bug.
_DAC_SECTOR_RULES: list[tuple[str, frozenset[str]]] = [
    ("Renewable Energy", frozenset({"23220", "23230", "23240", "23250", "23260", "23270", "23280"})),
    ("Sustainable Transport", frozenset({"21012", "21023", "21030", "21040", "21050"})),
    ("Agriculture", frozenset({"31110", "31140", "31163", "31320", "32161"})),
    ("Forestry", frozenset({"31220"})),
]


def map_afdb_sector(sector_codes: list[str]) -> str | None:
    """Returns one of NEV's five sector names, or None if unclassifiable.

    `sector_codes` are OECD DAC CRS 5-digit purpose codes from a
    `nev.funding.raw` AfDB message's `raw_sector_codes` field.
    """
    codes = set(sector_codes)
    for nev_sector, dac_codes in _DAC_SECTOR_RULES:
        if codes & dac_codes:
            return nev_sector
    return None
```

Save as `pipeline/processors/sector_mapping_afdb.py`.

- [ ] **Step 4: Run to verify it passes**

```bash
docker compose run --rm funding-validator python -m pytest pipeline/tests/test_sector_mapping_afdb.py -v
```
Expected: PASS (13 tests).

- [ ] **Step 5: Commit**

```bash
git add pipeline/processors/sector_mapping_afdb.py pipeline/tests/test_sector_mapping_afdb.py
git commit -m "feat(b1.3): add AfDB DAC-code sector-mapping function"
```

---

## Task 2: AfDB collector

**Files:**
- Create: `pipeline/collectors/afdb.py`
- Test: `pipeline/tests/test_afdb_collector.py`
- Test: `pipeline/tests/test_afdb_collector_live.py`

**Interfaces:**
- Consumes: nothing from earlier tasks (pure HTTP + Kafka producer). `IATI_API_KEY` is already wired into `docker-compose.yml`'s `funding-validator`/`airflow` services since B1.2 Task 2 — no new environment plumbing needed.
- Produces: `collect_and_publish(producer) -> int`. Consumed by Task 4 (the DAG).

- [ ] **Step 1: Write the failing offline unit tests**

```python
"""Unit tests for the AfDB collector's parsing/pagination/currency logic -
uses mocked HTTP responses (real payload shapes captured from the live
IATI Datastore during the B1.3 design work) rather than hitting the
network, so this file runs offline and fast. The live-network smoke tests
live in test_afdb_collector_live.py, kept separate so they can be skipped
independently if the external APIs are unreachable.
"""
from unittest.mock import MagicMock, patch

from pipeline.collectors.afdb import (
    collect_and_publish,
    fetch_afdb_activities,
    fetch_xdr_to_usd_rate,
    parse_activity,
)

# Real activity, two AfDB-Group commitment transactions (same date - both
# count, summed) plus government/interest/disbursement noise mixed in the
# same activity - verified live 2026-08-28.
_SAMPLE_ACTIVITY_MULTI_COMMITMENT = {
    "iati_identifier": "46002-P-BF-AA0-032",
    "recipient_country_code": ["BF"],
    "sector_code": ["31110"],
    "transaction_transaction_type_code": ["2", "3", "5", "2", "3"],
    "transaction_provider_org_ref": [
        "XM-DAC-46003", "XM-DAC-46003", "XM-DAC-46003", "XM-DAC-46003", "XM-DAC-46003",
    ],
    "transaction_value": [9760000.0, 61345.73, 22862.47, 18240000.0, 135992.72],
    "transaction_transaction_date_iso_date": [
        "2023-02-27T00:00:00Z", "2024-06-30T00:00:00Z", "2024-02-29T00:00:00Z",
        "2023-02-27T00:00:00Z", "2024-06-15T00:00:00Z",
    ],
}

# Real activity: one AfDB-provided commitment (counts), one disbursement
# (wrong type, excluded), one government-provided "commitment" (right
# type, wrong provider - excluded). Proves the allowlist actually filters
# by provider, not just by transaction type. Verified live 2026-08-28.
_SAMPLE_ACTIVITY_EXCLUDES_GOVERNMENT_PROVIDER = {
    "iati_identifier": "46002-P-GQ-K00-001",
    "recipient_country_code": ["GQ"],
    "sector_code": ["51010"],
    "transaction_transaction_type_code": ["2", "3", "2"],
    "transaction_provider_org_ref": ["XM-DAC-46003", "XM-DAC-46003", "GQ-COA-GOV"],
    "transaction_value": [807403.72, 901194.08, 91474.0],
    "transaction_transaction_date_iso_date": [
        "1993-01-20T00:00:00Z", "2009-12-31T00:00:00Z", "1993-01-20T00:00:00Z",
    ],
}

# Synthetic: an activity whose only Commitment transaction is provided by
# GCF (via AfDB as implementing entity) - must produce nothing, or B1.2's
# GCF connector and this one would double-count the same real money.
_SAMPLE_ACTIVITY_GCF_ROUTED_THROUGH_AFDB = {
    "iati_identifier": "46002-P-XX-GCF-001",
    "recipient_country_code": ["KE"],
    "sector_code": ["23230"],
    "transaction_transaction_type_code": ["2"],
    "transaction_provider_org_ref": ["XM-DAC-GCF"],
    "transaction_value": [5000000.0],
    "transaction_transaction_date_iso_date": ["2022-01-01T00:00:00Z"],
}

# Synthetic: no recipient country at all - 986/5,604 real AfDB activities
# are like this (regional/institutional activities).
_SAMPLE_ACTIVITY_NO_COUNTRY = {
    "iati_identifier": "46002-P-ZZ-000-001",
    "recipient_country_code": [],
    "sector_code": ["31110"],
    "transaction_transaction_type_code": ["2"],
    "transaction_provider_org_ref": ["XM-DAC-46002"],
    "transaction_value": [1000000.0],
    "transaction_transaction_date_iso_date": ["2020-01-01T00:00:00Z"],
}


def test_parse_activity_sums_multiple_allowlisted_commitments():
    payload = parse_activity(_SAMPLE_ACTIVITY_MULTI_COMMITMENT, xdr_to_usd_rate=1.5)

    assert payload["source"] == "afdb"
    assert payload["project_id"] == "46002-P-BF-AA0-032"
    assert payload["country_iso"] == "BFA"  # converted from IATI's alpha-2 "BF"
    assert payload["year"] == 2023
    assert payload["original_amount"] == 28000000.0  # 9,760,000 + 18,240,000 - disbursements/interest excluded
    assert payload["original_currency"] == "XDR"
    assert payload["exchange_rate"] == 1.5
    assert payload["amount_usd"] == 42000000  # 28,000,000 * 1.5
    assert payload["funding_type"] == "multilateral"
    assert payload["raw_sector_codes"] == ["31110"]
    assert payload["board_approval_date"] == "2023-02-27"


def test_parse_activity_excludes_government_provided_commitment():
    payload = parse_activity(_SAMPLE_ACTIVITY_EXCLUDES_GOVERNMENT_PROVIDER, xdr_to_usd_rate=1.5)

    assert payload["original_amount"] == 807403.72  # only the AfDB-provided transaction
    assert payload["amount_usd"] == 1211106  # round(807403.72 * 1.5)
    assert payload["country_iso"] == "GNQ"
    assert payload["year"] == 1993


def test_parse_activity_excludes_commitments_from_other_funds_routed_through_afdb():
    assert parse_activity(_SAMPLE_ACTIVITY_GCF_ROUTED_THROUGH_AFDB, xdr_to_usd_rate=1.5) is None


def test_parse_activity_returns_none_when_no_recipient_country():
    assert parse_activity(_SAMPLE_ACTIVITY_NO_COUNTRY, xdr_to_usd_rate=1.5) is None


def test_fetch_afdb_activities_paginates_using_start_offset():
    page_one = {
        "response": {
            "numFound": 3,
            "docs": [{"iati_identifier": "A1"}, {"iati_identifier": "A2"}],
        },
    }
    page_two = {
        "response": {
            "numFound": 3,
            "docs": [{"iati_identifier": "A3"}],
        },
    }
    mock_response_one = MagicMock()
    mock_response_one.json.return_value = page_one
    mock_response_two = MagicMock()
    mock_response_two.json.return_value = page_two

    with patch(
        "pipeline.collectors.afdb.requests.get",
        side_effect=[mock_response_one, mock_response_two],
    ) as mock_get:
        results = list(fetch_afdb_activities())

    assert [a["iati_identifier"] for a in results] == ["A1", "A2", "A3"]
    assert mock_get.call_count == 2
    assert mock_get.call_args_list[0].kwargs["params"]["start"] == 0
    assert mock_get.call_args_list[0].kwargs["params"]["q"] == 'reporting_org_ref:"XM-DAC-46002"'
    assert mock_get.call_args_list[1].kwargs["params"]["start"] == 1000


def test_fetch_xdr_to_usd_rate_reads_usd_from_response():
    mock_response = MagicMock()
    mock_response.json.return_value = {"rates": {"USD": 1.370818, "EUR": 1.17}}

    with patch("pipeline.collectors.afdb.requests.get", return_value=mock_response):
        rate = fetch_xdr_to_usd_rate()

    assert rate == 1.370818


def test_collect_and_publish_fetches_rate_once_and_publishes_parseable_activities():
    activities_response = MagicMock()
    activities_response.json.return_value = {
        "response": {
            "numFound": 2,
            "docs": [_SAMPLE_ACTIVITY_MULTI_COMMITMENT, _SAMPLE_ACTIVITY_GCF_ROUTED_THROUGH_AFDB],
        },
    }
    rate_response = MagicMock()
    rate_response.json.return_value = {"rates": {"USD": 1.5}}
    mock_producer = MagicMock()

    with patch(
        "pipeline.collectors.afdb.requests.get",
        side_effect=[rate_response, activities_response],
    ):
        published = collect_and_publish(mock_producer)

    assert published == 1  # the multi-commitment activity only - GCF-routed one is excluded
    mock_producer.send.assert_called_once()
    assert mock_producer.send.call_args[0][0] == "nev.funding.raw"
    mock_producer.flush.assert_called_once()
```

Save as `pipeline/tests/test_afdb_collector.py`.

- [ ] **Step 2: Run to verify it fails**

```bash
docker compose run --rm funding-validator python -m pytest pipeline/tests/test_afdb_collector.py -v
```
Expected: `ModuleNotFoundError: No module named 'pipeline.collectors.afdb'`.

- [ ] **Step 3: Write the implementation**

```python
"""African Development Bank (AfDB/BAD Group) collector, via the IATI
Datastore API (B1.3). Paginates AfDB's entire IATI-published portfolio
(too large for one request, unlike B1.2's GCF collector), sums each
activity's real AfDB-Group commitment (filtered by a fixed entity
allowlist), converts XDR to USD, and publishes to Kafka topic
`nev.funding.raw` - see the B1.3 spec's payload shape.
"""
from __future__ import annotations

import datetime as dt
import os
from typing import Any, Iterator

import pycountry
import requests

IATI_DATASTORE_URL = "https://api.iatistandard.org/datastore/activity/select"
AFDB_REPORTING_ORG_REF = "XM-DAC-46002"
PAGE_SIZE = 1000
REQUEST_TIMEOUT_SECONDS = 30

FIELDS = ",".join([
    "iati_identifier", "recipient_country_code", "sector_code",
    "transaction_transaction_type_code", "transaction_provider_org_ref",
    "transaction_value", "transaction_transaction_date_iso_date",
])

COMMITMENT_TRANSACTION_TYPE = "2"
# Real AfDB Group entities only - see B1.3 spec decision 6. Everything
# else appearing as a Commitment-transaction provider on an AfDB-reported
# activity is either a recipient-country government counterpart
# commitment or an independent fund (GCF, GEF, EU, GAFSP, various
# AfDB-hosted-but-externally-capitalized trust funds) merely routed
# through AfDB as implementing entity - none of that is AfDB Group's own
# money, and XM-DAC-GCF specifically would double-count against B1.2.
AFDB_GROUP_PROVIDER_REFS = frozenset({
    "XM-DAC-46002",  # African Development Bank
    "XM-DAC-46003",  # African Development Fund
    "XM-DAC-NTF",    # Nigerian Trust Fund
})

XDR_RATE_URL = "https://open.er-api.com/v6/latest/XDR"


def fetch_afdb_activities() -> Iterator[dict[str, Any]]:
    """Yields every raw AfDB activity record from the IATI Datastore,
    paginating through the full portfolio (5,604 activities as of this
    connector's design, verified live) using the Datastore's `start`/
    `rows` offset pagination.
    """
    offset = 0
    while True:
        response = requests.get(
            IATI_DATASTORE_URL,
            headers={"Ocp-Apim-Subscription-Key": os.environ["IATI_API_KEY"]},
            params={
                # Exact-phrase match (quoted) - same Solr tokenizing pitfall as B1.2.
                "q": f'reporting_org_ref:"{AFDB_REPORTING_ORG_REF}"',
                "rows": PAGE_SIZE,
                "start": offset,
                "wt": "json",
                "fl": FIELDS,
            },
            timeout=REQUEST_TIMEOUT_SECONDS,
        )
        response.raise_for_status()
        payload = response.json()
        docs = payload["response"]["docs"]
        if not docs:
            return
        yield from docs
        offset += PAGE_SIZE
        if offset >= payload["response"]["numFound"]:
            return


def fetch_xdr_to_usd_rate() -> float:
    """Fetches the current XDR->USD conversion rate from open.er-api.com
    (free, no API key required) - see B1.3 spec decision 4 for why the
    ECB, this project's usual pivot-currency source, structurally cannot
    serve this currency (XDR is an IMF-defined basket unit, absent from
    the ECB's 28-currency daily reference-rate feed).
    """
    response = requests.get(XDR_RATE_URL, timeout=REQUEST_TIMEOUT_SECONDS)
    response.raise_for_status()
    payload = response.json()
    return payload["rates"]["USD"]


def _afdb_commitment_summary(activity: dict[str, Any]) -> tuple[float, int, str] | None:
    """Returns (total_amount_xdr, year, earliest_commitment_date) by
    summing every transaction on this activity that is both type "2"
    (Outgoing Commitment) and provided by an AFDB_GROUP_PROVIDER_REFS
    entity - see B1.3 spec decision 6. Returns None when no such
    transaction exists - the caller treats this as nothing to publish.
    """
    types = activity.get("transaction_transaction_type_code", [])
    providers = activity.get("transaction_provider_org_ref", [])
    values = activity.get("transaction_value", [])
    dates = activity.get("transaction_transaction_date_iso_date", [])
    n = min(len(types), len(providers), len(values), len(dates))

    total = 0.0
    matched_dates = []
    for i in range(n):
        if types[i] == COMMITMENT_TRANSACTION_TYPE and providers[i] in AFDB_GROUP_PROVIDER_REFS:
            total += values[i]
            matched_dates.append(dates[i])

    if not matched_dates:
        return None

    matched_dates.sort()
    earliest_date = matched_dates[0][:10]
    return total, int(earliest_date[:4]), earliest_date


def parse_activity(activity: dict[str, Any], xdr_to_usd_rate: float) -> dict[str, Any] | None:
    """Converts one raw AfDB activity into a `nev.funding.raw` payload, or
    None if there's nothing to publish: no real AfDB Group commitment
    (spec decision 6), or no recipient country at all (spec decision 5).
    Unlike B1.2's GCF collector, never returns more than one payload -
    AfDB activities are never multi-country (verified live).
    """
    commitment = _afdb_commitment_summary(activity)
    if commitment is None:
        return None
    total_amount_xdr, year, board_approval_date = commitment

    country_codes = activity.get("recipient_country_code", [])
    if not country_codes:
        return None
    alpha2 = country_codes[0]
    country = pycountry.countries.get(alpha_2=alpha2)
    # Falls back to the raw alpha-2 code if pycountry doesn't recognize it
    # - same reasoning as B1.1/B1.2: it will never match a Country.isoCode
    # downstream, so the record is quarantined as unknown_country.
    country_iso = country.alpha_3 if country is not None else alpha2

    amount_usd = int(round(total_amount_xdr * xdr_to_usd_rate))

    return {
        "source": "afdb",
        "project_id": activity["iati_identifier"],
        "country_iso": country_iso,
        "year": year,
        "amount_usd": amount_usd,
        "original_amount": total_amount_xdr,
        "original_currency": "XDR",
        "exchange_rate": xdr_to_usd_rate,
        "funding_type": "multilateral",
        "raw_sector_codes": activity.get("sector_code", []),
        "board_approval_date": board_approval_date,
        "collected_at": dt.datetime.now(dt.timezone.utc).isoformat(),
    }


def collect_and_publish(producer) -> int:
    """Fetches AfDB's entire IATI-published portfolio, converts each
    parseable activity's commitment to USD using a single XDR->USD rate
    fetched once per run, and publishes to `nev.funding.raw` via
    `producer` (a `kafka.KafkaProducer`, e.g. from
    `pipeline.common.kafka_client.make_producer()`). Returns the number of
    messages actually published.
    """
    xdr_to_usd_rate = fetch_xdr_to_usd_rate()
    published = 0
    for raw_activity in fetch_afdb_activities():
        payload = parse_activity(raw_activity, xdr_to_usd_rate)
        if payload is None:
            continue
        producer.send("nev.funding.raw", payload)
        published += 1
    producer.flush()
    return published
```

Save as `pipeline/collectors/afdb.py`.

- [ ] **Step 4: Run to verify it passes**

```bash
docker compose run --rm funding-validator python -m pytest pipeline/tests/test_afdb_collector.py -v
```
Expected: PASS (7 tests).

- [ ] **Step 5: Write the live smoke tests**

```python
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
```

Save as `pipeline/tests/test_afdb_collector_live.py`.

- [ ] **Step 6: Run it**

```bash
docker compose run --rm funding-validator python -m pytest pipeline/tests/test_afdb_collector_live.py -v -m live
```
Expected: PASS (2 tests) — proves the real IATI Datastore and open.er-api.com contracts still
match what this task's implementation assumes.

- [ ] **Step 7: Commit**

```bash
git add pipeline/collectors/afdb.py pipeline/tests/test_afdb_collector.py pipeline/tests/test_afdb_collector_live.py
git commit -m "feat(b1.3): add AfDB collector (IATI Datastore + XDR conversion)"
```

---

## Task 3: Extend the funding validator for AfDB

**Files:**
- Modify: `pipeline/processors/funding_validator.py`
- Modify: `pipeline/tests/test_funding_validator.py`
- Modify: `backend/src/DataFixtures/SourceFixtures.php` (add the AfDB source row)

**Interfaces:**
- Consumes: `map_afdb_sector` from Task 1, the `nev.funding.raw` AfDB payload shape from Task 2 (including its new `original_amount`/`original_currency`/`exchange_rate` fields).
- Produces: `process_message` now also dispatches on `"afdb"`. `upsert_funding`'s signature gains three new optional keyword parameters: `original_amount: Decimal | None = None`, `original_currency: str | None = None`, `exchange_rate: Decimal | None = None`.

- [ ] **Step 1: Add the AfDB source fixture row**

In `backend/src/DataFixtures/SourceFixtures.php`, add a new row to the `SOURCES` constant:

```php
    private const SOURCES = [
        ['World Bank Data API', 'world-bank-api', SourceType::OfficialApi, SourceReliability::High],
        ['Green Climate Fund — Annual Report (PDF)', 'gcf-pdf-report', SourceType::PdfReport, SourceReliability::Medium],
        ['Green Climate Fund — IATI Datastore', 'gcf-iati-datastore', SourceType::OfficialApi, SourceReliability::High],
        ['African Development Bank Group — IATI Datastore', 'afdb-iati-datastore', SourceType::OfficialApi, SourceReliability::High],
        ['GreenAccess Platform Events', 'greenaccess-events', SourceType::GreenAccessEvent, SourceReliability::Medium],
        ['NEV Climate Data — Internal Demonstration', 'internal-demo', SourceType::InternalDemo, SourceReliability::Low],
    ];
```

**Do not run `doctrine:fixtures:load` against the dev database** — it purges the whole
database and would destroy real pipeline-collected data (hit this exact problem during B1.2
Task 3; see README "Points d'attention"). Insert the row directly instead:

```bash
docker compose exec backend php bin/console dbal:run-sql "INSERT INTO source (name, type, reliability) VALUES ('African Development Bank Group — IATI Datastore', 'official_api', 'high') ON CONFLICT (name) DO NOTHING"
```

For the test database (safe to reload — no real pipeline data lives there):
```bash
docker compose exec -e APP_ENV=test backend php bin/console doctrine:fixtures:load --no-interaction
```

Verify both:
```bash
docker compose exec backend php bin/console dbal:run-sql "SELECT name, type FROM source WHERE name LIKE 'African Development Bank%'"
```
Expected: one row, `official_api`.

- [ ] **Step 2: Write the failing tests**

Add to `pipeline/tests/test_funding_validator.py` (alongside the existing World Bank/GCF
fixtures and helpers, reused as-is):

```python
def _afdb_sample_message(amount_usd: int, original_amount: float = None, exchange_rate: float = None) -> dict:
    return {
        "source": "afdb",
        "project_id": "46002-P-TEST-001",
        "country_iso": "SEN",
        "year": 2026,
        "amount_usd": amount_usd,
        "original_amount": original_amount if original_amount is not None else amount_usd / 1.5,
        "original_currency": "XDR",
        "exchange_rate": exchange_rate if exchange_rate is not None else 1.5,
        "funding_type": "multilateral",
        "raw_sector_codes": ["31110"],
        "board_approval_date": "2026-01-15",
        "collected_at": "2026-08-28T00:00:00Z",
    }


def test_afdb_message_inserts_a_new_funding_row_with_currency_provenance(db_cursor):
    accepted, reason = process_message(db_cursor, _afdb_sample_message(3_000_000, original_amount=2_000_000.0, exchange_rate=1.5))

    assert accepted is True
    assert reason is None

    db_cursor.execute("SELECT id FROM source WHERE name = 'African Development Bank Group — IATI Datastore'")
    source_id = db_cursor.fetchone()[0]
    db_cursor.execute("SELECT id FROM country WHERE iso_code = 'SEN'")
    country_id = db_cursor.fetchone()[0]
    db_cursor.execute("SELECT id FROM sector WHERE name = 'Agriculture'")
    sector_id = db_cursor.fetchone()[0]

    row = _funding_row(db_cursor, source_id, country_id, sector_id, 2026, "multilateral")
    assert row == (Decimal("3000000.00"), True)

    # original_amount/original_currency/exchange_rate: never populated by
    # any earlier connector (World Bank/GCF are always-USD) - this is the
    # first real test of this path.
    db_cursor.execute(
        "SELECT original_amount, original_currency, exchange_rate FROM funding WHERE source_id = %s AND is_current = true",
        (source_id,),
    )
    original_amount, original_currency, exchange_rate = db_cursor.fetchone()
    assert original_amount == Decimal("2000000.00")
    assert original_currency == "XDR"
    assert exchange_rate == Decimal("1.500000")


def test_world_bank_and_gcf_rows_still_have_null_currency_provenance(db_cursor):
    # Regression check: extending upsert_funding with new optional
    # parameters must not affect the two existing connectors, which never
    # pass them.
    process_message(db_cursor, _sample_message(1_000_000))

    db_cursor.execute("SELECT id FROM source WHERE name = 'World Bank Data API'")
    source_id = db_cursor.fetchone()[0]
    db_cursor.execute(
        "SELECT original_amount, original_currency, exchange_rate FROM funding WHERE source_id = %s AND is_current = true",
        (source_id,),
    )
    original_amount, original_currency, exchange_rate = db_cursor.fetchone()
    assert original_amount is None
    assert original_currency is None
    assert exchange_rate is None


def test_afdb_unclassifiable_sector_is_rejected_without_writing(db_cursor):
    message = _afdb_sample_message(1_000_000)
    message["raw_sector_codes"] = ["51010"]  # General budget support - not a NEV sector

    accepted, reason = process_message(db_cursor, message)

    assert accepted is False
    assert reason == "unclassifiable_sector"


def test_all_three_sources_stay_separate_for_the_same_dedup_key(db_cursor):
    # Same country/year/type across all three (sector doesn't even need to
    # match - source_id alone is enough to keep the dedup key distinct).
    process_message(db_cursor, _sample_message(1_000_000))       # world_bank, Renewable Energy
    process_message(db_cursor, _gcf_sample_message(2_000_000))   # gcf, Renewable Energy
    process_message(db_cursor, _afdb_sample_message(3_000_000))  # afdb, Agriculture

    db_cursor.execute("SELECT count(*) FROM funding WHERE is_current = true AND year = 2026 AND country_id = (SELECT id FROM country WHERE iso_code = 'SEN')")
    assert db_cursor.fetchone()[0] == 3
```

- [ ] **Step 3: Run to verify the new tests fail**

```bash
docker compose run --rm funding-validator python -m pytest pipeline/tests/test_funding_validator.py -v
```
Expected: the 4 new tests FAIL (`KeyError` on `raw_sector_codes` for the AfDB dispatch, or
`TypeError`/column errors for the currency fields) — the existing 7 World Bank/GCF tests still
PASS unchanged.

- [ ] **Step 4: Update the implementation**

In `pipeline/processors/funding_validator.py`, add the import and the AfDB source helper:

```python
from pipeline.processors.sector_mapping_afdb import map_afdb_sector
```

```python
AFDB_SOURCE_NAME = "African Development Bank Group — IATI Datastore"  # matches backend/src/DataFixtures/SourceFixtures.php


def ensure_afdb_source(cursor) -> int:
    """Idempotently ensures the AfDB (IATI Datastore) row exists in
    `source`, and returns its id.
    """
    cursor.execute(
        """
        INSERT INTO source (name, type, reliability)
        VALUES (%s, 'official_api', 'high')
        ON CONFLICT (name) DO NOTHING
        """,
        (AFDB_SOURCE_NAME,),
    )
    cursor.execute("SELECT id FROM source WHERE name = %s", (AFDB_SOURCE_NAME,))
    return cursor.fetchone()[0]
```

Replace `upsert_funding` with a version accepting the three new optional parameters:

```python
def upsert_funding(cursor, *, source_id: int, country_id: int, sector_id: int, year: int,
                    funding_type: str, amount: Decimal, collection_date: str,
                    original_amount: Decimal | None = None,
                    original_currency: str | None = None,
                    exchange_rate: Decimal | None = None) -> None:
    """Sums `amount` into the current row for this dedup key if one
    exists (closing it out and inserting a new historized version), or
    inserts a fresh row otherwise - see B1.1 spec decision 6.
    `original_amount`/`original_currency`/`exchange_rate` describe the
    latest contributing message's raw figures (not accumulated across
    historized versions, same treatment as `collection_date`) - only
    populated by connectors reporting in a non-pivot currency (B1.3's
    AfDB connector is the first; World Bank/GCF never pass them, so they
    stay NULL as before).
    """
    cursor.execute(
        """
        SELECT id, amount FROM funding
        WHERE source_id = %s AND country_id = %s AND sector_id = %s
          AND year = %s AND funding_type = %s AND is_current = true
        """,
        (source_id, country_id, sector_id, year, funding_type),
    )
    existing = cursor.fetchone()

    if existing is not None:
        existing_id, existing_amount = existing
        new_amount = existing_amount + amount
        cursor.execute(
            "UPDATE funding SET is_current = false, valid_to = now() WHERE id = %s",
            (existing_id,),
        )
    else:
        new_amount = amount

    cursor.execute(
        """
        INSERT INTO funding (
            country_id, sector_id, year, amount, funding_type, source_id,
            collection_date, validation_status, valid_from, is_current,
            original_amount, original_currency, exchange_rate,
            created_at, updated_at
        ) VALUES (
            %s, %s, %s, %s, %s, %s,
            %s, 'validated', now(), true,
            %s, %s, %s,
            now(), now()
        )
        """,
        (country_id, sector_id, year, new_amount, funding_type, source_id, collection_date,
         original_amount, original_currency, exchange_rate),
    )
```

Replace `process_message` with a version dispatching to `"afdb"` too, and passing the currency
fields through when present:

```python
def process_message(cursor, message: dict[str, Any]) -> tuple[bool, str | None]:
    """Applies source-specific sector mapping and, on success, upserts.
    Returns (accepted, reason) - `reason` is None when accepted, or a
    short machine-readable string explaining rejection when not.
    """
    source = message["source"]
    if source == "world_bank":
        nev_sector = map_to_nev_sector(message["raw_sectors"], message["raw_theme"])
        ensure_source = ensure_world_bank_source
    elif source == "gcf":
        nev_sector = map_gcf_sector(message["raw_sector_codes"], message["raw_sector_percentages"])
        ensure_source = ensure_gcf_source
    elif source == "afdb":
        nev_sector = map_afdb_sector(message["raw_sector_codes"])
        ensure_source = ensure_afdb_source
    else:
        return False, "unknown_source"

    if nev_sector is None:
        return False, "unclassifiable_sector"

    country_id = lookup_country_id(cursor, message["country_iso"])
    if country_id is None:
        return False, "unknown_country"

    sector_id = lookup_sector_id(cursor, nev_sector)
    if sector_id is None:
        return False, "unknown_sector"

    source_id = ensure_source(cursor)

    # AfDB messages carry original_amount/original_currency/exchange_rate
    # (floats, from JSON) - convert via str() to avoid binary float
    # artifacts in the Decimal conversion (e.g. Decimal(1.370818) != 1.370818).
    # World Bank/GCF messages don't have these keys at all.
    upsert_funding(
        cursor,
        source_id=source_id,
        country_id=country_id,
        sector_id=sector_id,
        year=message["year"],
        funding_type=message["funding_type"],
        amount=Decimal(message["amount_usd"]),
        collection_date=message["collected_at"][:10],
        original_amount=Decimal(str(message["original_amount"])) if "original_amount" in message else None,
        original_currency=message.get("original_currency"),
        exchange_rate=Decimal(str(message["exchange_rate"])) if "exchange_rate" in message else None,
    )
    return True, None
```

- [ ] **Step 5: Run to verify everything passes**

```bash
docker compose run --rm funding-validator python -m pytest pipeline/tests/test_funding_validator.py -v
```
Expected: PASS (11 tests: the 7 existing World Bank/GCF ones, unchanged, plus the 4 new AfDB
ones).

- [ ] **Step 6: Run the full pipeline test suite together**

```bash
docker compose run --rm funding-validator python -m pytest pipeline/tests/ -v -m "not live"
```
Expected: PASS (55 tests: 8 sector-mapping World Bank + 10 sector-mapping GCF + 13
sector-mapping AfDB + 4 collector-offline World Bank + 5 collector-offline GCF + 7
collector-offline AfDB + 3 validator World Bank + 4 validator GCF + 11 validator combined —
the exact count matters less than confirming everything from every earlier task still passes
alongside the new work).

- [ ] **Step 7: Restart the funding-validator service**

```bash
docker compose up -d --build funding-validator
docker compose logs funding-validator --tail 20
```
Expected: no crash/traceback.

- [ ] **Step 8: Commit**

```bash
git add pipeline/processors/funding_validator.py pipeline/tests/test_funding_validator.py backend/src/DataFixtures/SourceFixtures.php
git commit -m "feat(b1.3): dispatch funding-validator to AfDB sector mapping and persist currency provenance"
```

---

## Task 4: Airflow DAG

**Files:**
- Create: `pipeline/dags/collecte_afdb.py`

**Interfaces:**
- Consumes: `collect_and_publish` from Task 2.
- Produces: nothing consumed by a later task — Task 5 verifies this DAG end-to-end.

- [ ] **Step 1: Write the DAG**

```python
"""Airflow DAG: quarterly collection of African Development Bank Group
(BAD/AfDB) project financing via the IATI Datastore - see the B1.3 spec.
Like B1.2's GCF DAG (and unlike B1.1's World Bank DAG), this does not need
NEV's own `country` table as an input - the collector paginates AfDB's
entire portfolio itself and lets country validity be decided downstream
by the funding-validator.
"""
from datetime import datetime, timedelta

from airflow import DAG
from airflow.operators.python import PythonOperator

from pipeline.collectors.afdb import collect_and_publish
from pipeline.common.kafka_client import make_producer

default_args = {
    "owner": "nev-climate-data",
    "retries": 3,
    "retry_delay": timedelta(minutes=5),
}


def _collect(**context) -> None:
    producer = make_producer()
    published = collect_and_publish(producer)
    context["ti"].xcom_push(key="published_count", value=published)


with DAG(
    dag_id="collecte_afdb",
    default_args=default_args,
    schedule_interval="0 3 1 1,4,7,10 *",  # 1er jour de chaque trimestre, 03h00
    start_date=datetime(2026, 1, 1),
    catchup=False,
    tags=["b1.3", "collecte", "afdb"],
) as dag:
    collecter = PythonOperator(
        task_id="collecter_financements_afdb",
        python_callable=_collect,
    )
```

Save as `pipeline/dags/collecte_afdb.py`.

- [ ] **Step 2: Verify the DAG is recognized**

```bash
docker compose exec airflow airflow dags list
```
Expected: `collecte_afdb` appears alongside `collecte_worldbank` and `collecte_gcf`, with no
import errors.

```bash
docker compose exec airflow airflow dags list-import-errors
```
Expected: empty output.

- [ ] **Step 3: Commit**

```bash
git add pipeline/dags/collecte_afdb.py
git commit -m "feat(b1.3): add quarterly AfDB collection DAG"
```

---

## Task 5: End-to-end verification

**Files:**
- None (verification-only task).

**Interfaces:**
- Consumes: the entire connector built in Tasks 1-4, running on B1.1/B1.2's already-provisioned infrastructure.
- Produces: nothing — this is the plan's acceptance check.

- [ ] **Step 1: Trigger the DAG manually**

```bash
docker compose exec airflow airflow dags unpause collecte_afdb
docker compose exec airflow airflow dags trigger collecte_afdb
```

Wait for it to finish — this paginates 5,604 activities across 6 HTTP requests plus one
currency-rate request, likely several minutes. Check:

```bash
docker compose exec airflow airflow dags list-runs -d collecte_afdb
```
Expected: the run shows state `success`.

If it fails, find and read the task log (same pattern as B1.1/B1.2):
```bash
docker compose exec airflow sh -c "find /opt/airflow/logs/dag_id=collecte_afdb -name '*.log' | sort | tail -1 | xargs cat"
```

If the run gets stuck in `queued` for more than a minute or two despite `airflow jobs check`
reporting a healthy scheduler, don't wait longer — recreate the container (a known real
Airflow issue hit during B1.2's own end-to-end verification):
```bash
docker compose up -d --force-recreate airflow
```

- [ ] **Step 2: Verify real data landed in `funding`, with currency provenance populated**

```bash
docker compose exec backend php bin/console dbal:run-sql "SELECT count(*) FROM funding WHERE source_id = (SELECT id FROM source WHERE name = 'African Development Bank Group — IATI Datastore')"
```
Expected: a count greater than 0.

```bash
docker compose exec backend php bin/console dbal:run-sql "SELECT country_id, sector_id, year, amount, original_amount, original_currency, exchange_rate, is_current FROM funding WHERE source_id = (SELECT id FROM source WHERE name = 'African Development Bank Group — IATI Datastore') ORDER BY amount DESC LIMIT 5"
```
Expected: rows with plausible amounts, `original_currency = XDR`, a real-looking
`exchange_rate` (roughly 1.0-1.8), and `amount ≈ original_amount × exchange_rate`.

- [ ] **Step 3: Verify all three sources stay separate**

```bash
docker compose exec backend php bin/console dbal:run-sql "SELECT s.name, count(*) FROM funding f JOIN source s ON s.id = f.source_id WHERE s.name IN ('World Bank Data API', 'Green Climate Fund — IATI Datastore', 'African Development Bank Group — IATI Datastore') GROUP BY s.name"
```
Expected: three distinct rows, each with a real count.

- [ ] **Step 4: Verify no `Adaptation` rows came from AfDB**

```bash
docker compose exec backend php bin/console dbal:run-sql "SELECT count(*) FROM funding f JOIN source s ON s.id = f.source_id JOIN sector sec ON sec.id = f.sector_id WHERE s.name = 'African Development Bank Group — IATI Datastore' AND sec.name = 'Adaptation'"
```
Expected: `0` — confirms the spec's verified gap (decision 8) holds against real data, not just
the mocked fixtures.

- [ ] **Step 5: Verify the historization/summing behavior for real**

```bash
docker compose exec airflow airflow dags trigger collecte_afdb
```

Wait for it to complete again, then:
```bash
docker compose exec backend php bin/console dbal:run-sql "SELECT count(*) FROM funding WHERE source_id = (SELECT id FROM source WHERE name = 'African Development Bank Group — IATI Datastore') AND is_current = false"
```
Expected: a count greater than 0.

- [ ] **Step 6: Verify quarantine is reachable for AfDB-sourced rejects**

```bash
docker compose exec kafka kafka-console-consumer --bootstrap-server localhost:9092 --topic nev.funding.rejets --from-beginning --timeout-ms 30000 2>&1 | grep '"source": "afdb"' | head -3
```
Expected: at least one AfDB-sourced rejected message with a `rejection_reason`
(`unclassifiable_sector` is very likely — most of AfDB's real portfolio is outside NEV's five
sectors, per the spec's own verified numbers).

- [ ] **Step 7: No commit** — this task only ran verification commands; nothing in the repo
changed.

---

## Task 6: Documentation

**Files:**
- Modify: `README.md`

**Interfaces:**
- Consumes: nothing. Produces: nothing consumed elsewhere — final task of this plan.

- [ ] **Step 1: Extend the "Pipeline (Volet B)" section**

Add a subsection after B1.2's "Connecteur GCF" subsection:

```markdown
### Connecteur BAD (B1.3)

Troisième connecteur du Volet B : collecte trimestrielle des financements de la Banque
Africaine de Développement (Groupe BAD), via la même API IATI Datastore que B1.2. Premier
connecteur à effectuer une vraie conversion de devise (XDR→USD, la BCE ne pouvant
structurellement pas servir cette devise — voir la spec) et à peupler pour de vrai les colonnes
`originalAmount`/`originalCurrency`/`exchangeRate` de `Funding`, réservées depuis A1.3.
Décisions de conception complètes :
[`docs/superpowers/specs/2026-08-28-b13-afdb-connector-design.md`](docs/superpowers/specs/2026-08-28-b13-afdb-connector-design.md).

```bash
docker compose exec airflow airflow dags trigger collecte_afdb
```

Réutilise `IATI_API_KEY` (déjà configuré depuis B1.2) et l'infrastructure existante — aucun
nouveau service, aucun nouveau topic.
```

- [ ] **Step 2: Add "Points d'attention" entries**

Append as the next numbered points (after B1.2's points 18-20):

```markdown
21. **La BCE ne couvre pas toutes les devises — le XDR (Droits de Tirage Spéciaux du FMI) en est absent.** Vérifié en direct sur le flux quotidien officiel (`eurofxref-daily.xml`) : 28 devises nationales, aucune n'est le XDR (un panier de devises défini par le FMI, pas une devise nationale). Le FMI publie bien un taux quotidien officiel mais uniquement sur une page HTML, sans API. Pour toute devise non couverte par la BCE, vérifier d'abord sa couverture réelle avant de supposer qu'une "conversion de devise standard" suffira — voir `pipeline/collectors/afdb.py` pour la solution retenue (API tierce `open.er-api.com`, décision documentée dans la spec B1.3).

22. **Un flux de données d'une organisation peut légitimement contenir l'argent d'*autres* organisations.** Le flux IATI de la BAD inclut des engagements de gouvernements récipiendaires ET d'autres fonds indépendants (dont le GCF, déjà suivi par un connecteur séparé) qui utilisent la BAD comme simple entité de mise en œuvre. Compter cet argent comme "financement BAD" aurait créé un vrai double comptage avec B1.2. Toujours vérifier, avec de vraies données, qui est le payeur réel derrière un enregistrement de financement multi-institutionnel — jamais supposer qu'une transaction appartient à l'organisation qui publie le flux.
```

- [ ] **Step 3: Commit**

```bash
git add README.md
git commit -m "docs(b1.3): document the AfDB connector"
```

---

## Final check before considering B1.3 done

- [ ] `docker compose run --rm funding-validator python -m pytest pipeline/tests/ -v -m "not live"` — all green.
- [ ] `docker compose run --rm funding-validator python -m pytest pipeline/tests/test_afdb_collector_live.py -v -m live` — green (real IATI + open.er-api.com contracts still match assumptions).
- [ ] `docker compose exec backend php bin/phpunit` (full existing suite) — still green, confirming the new `SourceFixtures.php` row caused no regression.
- [ ] Two manual DAG triggers (Task 5) produced real `Funding` rows with `source = African Development Bank Group — IATI Datastore`, `funding_type = multilateral`, real `original_amount`/`original_currency`/`exchange_rate` populated, correct summing/historization on the second run, zero `Adaptation` rows, and all three sources (World Bank/GCF/AfDB) verified to stay separate under the shared dedup key.
- [ ] Cross-check against the plan spreadsheet: B1.3's "Livrable attendu" was *"DAG Airflow trimestriel publiant vers le topic Kafka dédié"* — confirmed: `collecte_afdb` DAG exists, scheduled quarterly, publishes to `nev.funding.raw`.
