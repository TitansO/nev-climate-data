# B1.2 GCF Connector Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Collect Green Climate Fund (GCF) project financing for every NEV-tracked country, monthly, and land it in the `funding` table via the shared Volet B pipeline (Kafka + the existing Python validator writing directly to TimescaleDB).

**Architecture:** A new `pipeline/collectors/gcf.py` queries the IATI Datastore for GCF's entire IATI-published portfolio (one request, no per-country pagination — unlike B1.1's World Bank collector), splits multi-country activities into one payload per recipient country, and publishes to the **existing** `nev.funding.raw` topic (`source: "gcf"`). The **existing** `funding-validator` service is extended to dispatch on `source` to a new `pipeline/processors/sector_mapping_gcf.py` (DAC 5-digit code table, not keyword matching). No new Kafka topics or services — B1.1 already provisioned everything this plan reuses.

**Tech Stack:** Python 3.12, `requests`, `pycountry`, IATI Datastore REST API (Solr-backed, JSON), Apache Airflow 2.9 (existing `airflow` service), Kafka (existing `nev.funding.raw`/`nev.funding.valides`/`nev.funding.rejets` topics), TimescaleDB (existing `funding`/`source`/`country`/`sector` tables), pytest.

## Global Constraints

- No new Kafka topics or services — this connector publishes to the existing `nev.funding.raw` and is consumed by the existing `funding-validator` service (spec decision 1 / architecture spec decision 4).
- `fundingType` is always `Multilateral` for this connector (B1.2 spec decision 2).
- Currency is always USD for GCF — no conversion, `originalAmount`/`originalCurrency`/`exchangeRate` stay unused (B1.2 spec decision 3).
- `amount_usd` = the sum of transactions where `transaction_type = "2"` AND `transaction_description_narrative == "GCF financing commitment"` exactly — never `transaction_provider_org_ref` (always GCF, useless as a filter) (B1.2 spec decision 4).
- `year` = the year of those same summed transactions (B1.2 spec decision 5).
- Multi-country activities: one `nev.funding.raw` message per recipient country, `amount_usd` prorated by IATI's own `recipient_country_percentage` (B1.2 spec decision 6).
- Sector mapping: fixed DAC 5-digit code table, first NEV-sector-in-priority-order with any matching code wins, `sector_percentage` is not used for the decision (B1.2 spec decision 7).
- IATI Datastore requires `Ocp-Apim-Subscription-Key: $IATI_API_KEY` on every request — free key from `developer.iatistandard.org`, stored in `.env` (gitignored), never committed.
- Exact-phrase Solr query required: `q=reporting_org_ref:"XM-DAC-41317"` (quoted) — unquoted tokenizes on the dashes and matches ~269,000 unrelated documents instead of GCF's real 350 activities.
- Schedule: monthly (plan's own B1.2 livrable: "DAG Airflow mensuel").
- Repo root for all paths below: `nev-climate-data/`. All `docker compose` commands run from that root.

---

## Task 1: GCF sector-mapping function

**Files:**
- Create: `pipeline/processors/sector_mapping_gcf.py`
- Test: `pipeline/tests/test_sector_mapping_gcf.py`

**Interfaces:**
- Produces: `map_gcf_sector(sector_codes: list[str], sector_percentages: list[float]) -> str | None`. Consumed by Task 3's `process_message`.

- [ ] **Step 1: Write the failing tests**

```python
from pipeline.processors.sector_mapping_gcf import map_gcf_sector


def test_maps_renewable_energy_generation_code():
    assert map_gcf_sector(["23210"], [100.0]) == "Renewable Energy"


def test_maps_energy_efficiency_code_to_renewable_energy():
    assert map_gcf_sector(["23183"], [100.0]) == "Renewable Energy"


def test_maps_transport_policy_code():
    assert map_gcf_sector(["21010"], [100.0]) == "Sustainable Transport"


def test_maps_forestry_policy_code():
    assert map_gcf_sector(["31210"], [100.0]) == "Forestry"


def test_maps_disaster_risk_reduction_to_adaptation():
    assert map_gcf_sector(["43060"], [100.0]) == "Adaptation"


def test_maps_biodiversity_to_adaptation():
    assert map_gcf_sector(["41030"], [100.0]) == "Adaptation"


def test_social_protection_codes_alone_are_unclassifiable():
    # 16010/16050 are GCF's two MOST FREQUENT codes in real data but never
    # map to any NEV sector on their own - see B1.2 spec decision 7.
    assert map_gcf_sector(["16010", "16050"], [60.0, 40.0]) is None


def test_dominant_social_protection_does_not_block_a_real_sector_match():
    # Real-world shape (verified against the full 350-activity GCF
    # portfolio): Social Protection (16010) often has a HIGHER percentage
    # than the mappable code, but first-match-wins (ignoring percentage)
    # still classifies it correctly - a dominant-sector-only rule would
    # wrongly quarantine this (96/350 real activities are like this).
    assert map_gcf_sector(["16010", "23210"], [70.0, 30.0]) == "Renewable Energy"


def test_priority_order_renewable_energy_before_forestry():
    assert map_gcf_sector(["31210", "23210"], [50.0, 50.0]) == "Renewable Energy"


def test_returns_none_for_no_codes_at_all():
    assert map_gcf_sector([], []) is None
```

Save as `pipeline/tests/test_sector_mapping_gcf.py`.

- [ ] **Step 2: Run to verify it fails**

```bash
docker compose run --rm funding-validator python -m pytest pipeline/tests/test_sector_mapping_gcf.py -v
```
Expected: `ModuleNotFoundError: No module named 'pipeline.processors.sector_mapping_gcf'`.

- [ ] **Step 3: Write the implementation**

```python
"""Maps a GCF activity's DAC 5-digit sector codes onto one of NEV's five
funding sectors, per the ordered table in
docs/superpowers/specs/2026-08-28-b12-gcf-connector-design.md (decision 7).
Returns None - triggering quarantine - when no code matches.
"""
from __future__ import annotations

# Ordered: the first NEV sector (in this list's order) that has ANY
# matching DAC code in the activity wins - `sector_percentage` is
# deliberately ignored. Verified against the complete real GCF portfolio
# (350 activities): a dominant-sector-only rule (picking whichever DAC code
# has the highest percentage) would wrongly quarantine 96/350 activities
# where a non-mappable code like Social Protection (16010/16050 - GCF's two
# most frequent codes, never mapped here) happens to have a higher
# percentage than a real climate-sector code also present on the same
# activity. First-match-wins classifies 299/350 (85%) vs 203/350 (58%) for
# dominant-only.
#
# Agriculture never appears: no DAC code in GCF's real portfolio maps to
# it - not a bug, this source simply never contributes Agriculture rows.
# 41030 (Biodiversity) -> Adaptation is a judgement call (ecosystem-based
# adaptation), confirmed with Serge rather than assumed.
_DAC_SECTOR_RULES: list[tuple[str, frozenset[str]]] = [
    ("Renewable Energy", frozenset({"23210", "23183"})),
    ("Sustainable Transport", frozenset({"21010"})),
    ("Forestry", frozenset({"31210"})),
    ("Adaptation", frozenset({"43060", "41030"})),
]


def map_gcf_sector(sector_codes: list[str], sector_percentages: list[float]) -> str | None:
    """Returns one of NEV's five sector names, or None if unclassifiable.

    `sector_codes` are OECD DAC CRS 5-digit purpose codes from a
    `nev.funding.raw` GCF message's `raw_sector_codes` field.
    `sector_percentages` is accepted for interface symmetry with the raw
    IATI payload (`raw_sector_percentages`) but is not used in the matching
    logic itself - see the module docstring above.
    """
    codes = set(sector_codes)
    for nev_sector, dac_codes in _DAC_SECTOR_RULES:
        if codes & dac_codes:
            return nev_sector
    return None
```

Save as `pipeline/processors/sector_mapping_gcf.py`.

- [ ] **Step 4: Run to verify it passes**

```bash
docker compose run --rm funding-validator python -m pytest pipeline/tests/test_sector_mapping_gcf.py -v
```
Expected: PASS (10 tests).

- [ ] **Step 5: Commit**

```bash
git add pipeline/processors/sector_mapping_gcf.py pipeline/tests/test_sector_mapping_gcf.py
git commit -m "feat(b1.2): add GCF DAC-code sector-mapping function"
```

---

## Task 2: GCF collector

**Files:**
- Create: `pipeline/collectors/gcf.py`
- Test: `pipeline/tests/test_gcf_collector.py`
- Test: `pipeline/tests/test_gcf_collector_live.py`
- Modify: `docker-compose.yml` (add `IATI_API_KEY` to the `funding-validator` and `airflow` services' environment)
- Modify: `.env.example` (document `IATI_API_KEY`)

**Interfaces:**
- Consumes: nothing from earlier tasks (pure HTTP + Kafka producer).
- Produces: `collect_and_publish(producer) -> int`. Consumed by Task 4 (the DAG).

- [ ] **Step 1: Add `IATI_API_KEY` to the environment**

In `.env.example`, add after the MinIO section:

```
# --- IATI Datastore (Volet B — B1.2, connecteur GCF) -----------------------
# Clé gratuite : inscription sur https://developer.iatistandard.org/, puis
# Subscriptions -> "Exploratory" (aucune approbation requise). Utilisée pour
# interroger https://api.iatistandard.org/datastore/activity/select.
IATI_API_KEY=change_me_get_a_free_key_from_developer.iatistandard.org
```

Add the real value to the local `.env` (gitignored) if not already present from this plan's
design research: `IATI_API_KEY=<your key>`.

In `docker-compose.yml`, add `IATI_API_KEY: ${IATI_API_KEY}` to the `environment:` block of
**both** the `funding-validator` service (needed for `docker compose run --rm
funding-validator ...` while developing/testing this collector) and the `airflow` service
(needed once Task 4's DAG runs it):

```yaml
  funding-validator:
    ...
    environment:
      PIPELINE_DATABASE_URL: postgresql://${POSTGRES_USER}:${POSTGRES_PASSWORD}@database:5432/${POSTGRES_DB}
      KAFKA_BOOTSTRAP_SERVERS: kafka:9092
      IATI_API_KEY: ${IATI_API_KEY}
```

```yaml
  airflow:
    ...
    environment:
      AIRFLOW__CORE__EXECUTOR: LocalExecutor
      AIRFLOW__DATABASE__SQL_ALCHEMY_CONN: postgresql+psycopg2://airflow:airflow@postgres-airflow/airflow
      AIRFLOW__CORE__LOAD_EXAMPLES: "false"
      AIRFLOW__CORE__DAGS_FOLDER: /opt/airflow/pipeline/dags
      _PIP_ADDITIONAL_REQUIREMENTS: "kafka-python-ng==2.2.3 psycopg2-binary==2.9.9 requests==2.32.3 pycountry==24.6.1"
      PIPELINE_DATABASE_URL: postgresql://${POSTGRES_USER}:${POSTGRES_PASSWORD}@database:5432/${POSTGRES_DB}
      PYTHONPATH: /opt/airflow
      IATI_API_KEY: ${IATI_API_KEY}
```

Run: `docker compose up -d funding-validator` then `docker compose up -d --force-recreate
airflow` (recreate needed for the new env var to take effect — same requirement Task 1 of B1.1
documented for `_PIP_ADDITIONAL_REQUIREMENTS` changes).

- [ ] **Step 2: Write the failing offline unit tests**

```python
"""Unit tests for the GCF collector's parsing/splitting logic - uses
mocked HTTP responses (real payload shapes captured from the live IATI
Datastore during the B1.2 design work) rather than hitting the network, so
this file runs offline and fast. The live-network smoke test lives in
test_gcf_collector_live.py, kept separate so it can be skipped
independently if the external API is unreachable.
"""
from unittest.mock import MagicMock, patch

from pipeline.collectors.gcf import collect_and_publish, fetch_gcf_activities, parse_activity

# Real activity, single recipient country, verified live 2026-08-28.
_SAMPLE_ACTIVITY_SINGLE_COUNTRY = {
    "iati_identifier": "XM-DAC-41317-FP049",
    "recipient_country_code": ["SN"],
    "recipient_country_percentage": [100.0],
    "sector_code": ["16010", "16050"],
    "sector_percentage": [48.0, 52.0],
    "transaction_transaction_type_code": ["2", "3", "3", "3", "3"],
    "transaction_description_narrative": [
        "GCF financing commitment", "Disbursement", "Disbursement", "Disbursement", "Disbursement",
    ],
    "transaction_value": [9983521.0, 2495900.0, 2415917.0, 2519897.0, 2187614.0],
    "transaction_transaction_date_iso_date": [
        "2017-10-02T00:00:00Z", "2020-01-23T00:00:00Z", "2021-03-30T00:00:00Z",
        "2022-02-07T00:00:00Z", "2023-07-25T00:00:00Z",
    ],
}

# Real activity, two recipient countries split 50/50, with one "GCF
# financing commitment" AND one "Co-financing commitment" - verified live
# 2026-08-28 that both share provider_org_ref="XM-DAC-41317" (GCF), which
# is why that field cannot be used to tell them apart (spec decision 4).
_SAMPLE_ACTIVITY_MULTI_COUNTRY = {
    "iati_identifier": "XM-DAC-41317-FP103",
    "recipient_country_code": ["KE", "SN"],
    "recipient_country_percentage": [50.0, 50.0],
    "sector_code": ["23183"],
    "sector_percentage": [100.0],
    "transaction_transaction_type_code": ["2", "2", "3", "3", "3", "3", "3"],
    "transaction_description_narrative": [
        "GCF financing commitment", "Co-financing commitment", "Disbursement",
        "Disbursement", "Disbursement", "Disbursement", "Disbursement",
    ],
    "transaction_value": [43722728.0, 6134699.95, 7019405.4, 12074904.0, 11103360.0, 10293740.0, 3875766.6],
    "transaction_transaction_date_iso_date": [
        "2019-02-28T00:00:00Z", "2019-02-28T00:00:00Z", "2021-05-21T00:00:00Z",
        "2021-11-29T00:00:00Z", "2023-05-23T00:00:00Z", "2024-11-14T00:00:00Z", "2025-03-21T00:00:00Z",
    ],
}

# Synthetic: an activity with commitment transactions but none literally
# described "GCF financing commitment" - covers the pre-publish skip.
_SAMPLE_ACTIVITY_NO_GCF_COMMITMENT = {
    "iati_identifier": "XM-DAC-41317-FAKE",
    "recipient_country_code": ["SN"],
    "recipient_country_percentage": [100.0],
    "sector_code": ["23210"],
    "sector_percentage": [100.0],
    "transaction_transaction_type_code": ["2", "3"],
    "transaction_description_narrative": ["Co-financing commitment", "Disbursement"],
    "transaction_value": [1000000.0, 500000.0],
    "transaction_transaction_date_iso_date": ["2020-01-01T00:00:00Z", "2020-06-01T00:00:00Z"],
}


def test_parse_activity_single_country_sums_gcf_commitment_only():
    payloads = parse_activity(_SAMPLE_ACTIVITY_SINGLE_COUNTRY)

    assert len(payloads) == 1
    payload = payloads[0]
    assert payload["source"] == "gcf"
    assert payload["project_id"] == "XM-DAC-41317-FP049"
    assert payload["country_iso"] == "SEN"  # converted from IATI's alpha-2 "SN"
    assert payload["year"] == 2017
    assert payload["amount_usd"] == 9983521  # only the commitment transaction, not the 4 disbursements
    assert payload["funding_type"] == "multilateral"
    assert payload["raw_sector_codes"] == ["16010", "16050"]
    assert payload["board_approval_date"] == "2017-10-02"


def test_parse_activity_multi_country_prorates_amount_and_excludes_cofinancing():
    payloads = parse_activity(_SAMPLE_ACTIVITY_MULTI_COUNTRY)

    assert len(payloads) == 2
    by_country = {p["country_iso"]: p for p in payloads}
    assert set(by_country) == {"KEN", "SEN"}
    # 43722728 (GCF commitment only - excludes the 6134699.95 co-financing
    # line, even though both share provider_org_ref) split 50/50.
    assert by_country["KEN"]["amount_usd"] == 21861364
    assert by_country["SEN"]["amount_usd"] == 21861364
    assert by_country["KEN"]["year"] == 2019
    assert by_country["KEN"]["project_id"] == "XM-DAC-41317-FP103"


def test_parse_activity_returns_empty_list_when_no_gcf_commitment_transaction():
    assert parse_activity(_SAMPLE_ACTIVITY_NO_GCF_COMMITMENT) == []


def test_fetch_gcf_activities_uses_exact_phrase_query_and_yields_docs():
    mock_response = MagicMock()
    mock_response.json.return_value = {
        "response": {"docs": [_SAMPLE_ACTIVITY_SINGLE_COUNTRY, _SAMPLE_ACTIVITY_MULTI_COUNTRY]},
    }

    with patch("pipeline.collectors.gcf.requests.get", return_value=mock_response) as mock_get:
        results = list(fetch_gcf_activities())

    assert [a["iati_identifier"] for a in results] == ["XM-DAC-41317-FP049", "XM-DAC-41317-FP103"]
    call_params = mock_get.call_args.kwargs["params"]
    # Quoted/exact-phrase - an unquoted value tokenizes on the dashes in
    # "XM-DAC-41317" and matches ~269,000 unrelated documents (verified live).
    assert call_params["q"] == 'reporting_org_ref:"XM-DAC-41317"'


def test_collect_and_publish_sends_one_message_per_country_split_and_returns_count():
    mock_response = MagicMock()
    mock_response.json.return_value = {
        "response": {
            "docs": [
                _SAMPLE_ACTIVITY_SINGLE_COUNTRY,
                _SAMPLE_ACTIVITY_MULTI_COUNTRY,
                _SAMPLE_ACTIVITY_NO_GCF_COMMITMENT,
            ],
        },
    }
    mock_producer = MagicMock()

    with patch("pipeline.collectors.gcf.requests.get", return_value=mock_response):
        published = collect_and_publish(mock_producer)

    assert published == 3  # 1 (FP049) + 2 (FP103: KEN + SEN) + 0 (no GCF commitment)
    assert mock_producer.send.call_count == 3
    for call in mock_producer.send.call_args_list:
        assert call[0][0] == "nev.funding.raw"
    mock_producer.flush.assert_called_once()
```

Save as `pipeline/tests/test_gcf_collector.py`.

- [ ] **Step 3: Run to verify it fails**

```bash
docker compose run --rm funding-validator python -m pytest pipeline/tests/test_gcf_collector.py -v
```
Expected: `ModuleNotFoundError: No module named 'pipeline.collectors.gcf'`.

- [ ] **Step 4: Write the implementation**

```python
"""Green Climate Fund (GCF) collector, via the IATI Datastore API (B1.2).
Fetches GCF's entire IATI-published portfolio, splits multi-country
activities into one payload per recipient country, and publishes to Kafka
topic `nev.funding.raw` - see the B1.2 spec's payload shape.
"""
from __future__ import annotations

import datetime as dt
import os
from typing import Any, Iterator

import pycountry
import requests

IATI_DATASTORE_URL = "https://api.iatistandard.org/datastore/activity/select"
GCF_REPORTING_ORG_REF = "XM-DAC-41317"
# GCF's real portfolio was 350 activities when this connector was designed
# (verified live) - 1000 gives headroom for portfolio growth while staying
# a single request (no pagination needed, unlike B1.1's World Bank
# collector - see spec decision 1).
MAX_ACTIVITIES = 1000
REQUEST_TIMEOUT_SECONDS = 30

FIELDS = ",".join([
    "iati_identifier", "recipient_country_code", "recipient_country_percentage",
    "sector_code", "sector_percentage", "transaction_transaction_type_code",
    "transaction_description_narrative", "transaction_value",
    "transaction_transaction_date_iso_date",
])

COMMITMENT_TRANSACTION_TYPE = "2"
GCF_COMMITMENT_DESCRIPTION = "GCF financing commitment"


def fetch_gcf_activities() -> Iterator[dict[str, Any]]:
    """Yields every raw GCF activity record from the IATI Datastore - a
    single request covers GCF's entire portfolio (see MAX_ACTIVITIES).
    """
    response = requests.get(
        IATI_DATASTORE_URL,
        headers={"Ocp-Apim-Subscription-Key": os.environ["IATI_API_KEY"]},
        params={
            # Exact-phrase match (quoted) is required - an unquoted value
            # lets Solr tokenize on the dashes in "XM-DAC-41317" and match
            # ~269,000 unrelated documents instead of GCF's real ~350
            # (confirmed live during this connector's design).
            "q": f'reporting_org_ref:"{GCF_REPORTING_ORG_REF}"',
            "rows": MAX_ACTIVITIES,
            "wt": "json",
            "fl": FIELDS,
        },
        timeout=REQUEST_TIMEOUT_SECONDS,
    )
    response.raise_for_status()
    payload = response.json()
    yield from payload["response"]["docs"]


def _gcf_commitment_summary(activity: dict[str, Any]) -> tuple[int, int, str] | None:
    """Returns (total_amount_usd, year, earliest_commitment_date) by
    summing every transaction on this activity that is both type "2"
    (Outgoing Commitment) and described exactly "GCF financing commitment"
    - see the B1.2 spec's decision 4 for why `transaction_provider_org_ref`
    cannot be used instead (it is always GCF, even on co-financing lines -
    verified live against the real portfolio). Returns None when no such
    transaction exists - the caller treats this as nothing to publish,
    same as B1.1's World Bank collector skipping a zero-amount project.
    """
    types = activity.get("transaction_transaction_type_code", [])
    descriptions = activity.get("transaction_description_narrative", [])
    values = activity.get("transaction_value", [])
    dates = activity.get("transaction_transaction_date_iso_date", [])
    n = min(len(types), len(descriptions), len(values), len(dates))

    total = 0.0
    matched_dates = []
    for i in range(n):
        if types[i] == COMMITMENT_TRANSACTION_TYPE and descriptions[i] == GCF_COMMITMENT_DESCRIPTION:
            total += values[i]
            matched_dates.append(dates[i])

    if not matched_dates:
        return None

    matched_dates.sort()
    earliest_date = matched_dates[0][:10]
    return int(total), int(earliest_date[:4]), earliest_date


def parse_activity(activity: dict[str, Any]) -> list[dict[str, Any]]:
    """Converts one raw GCF activity into zero, one, or several
    `nev.funding.raw` payloads - one per recipient country, `amount_usd`
    prorated by that country's `recipient_country_percentage` (spec
    decision 6). Returns an empty list if the activity has no "GCF
    financing commitment" transaction to sum (spec decision 4), or if its
    country/percentage arrays are missing or mismatched in length (not
    observed in the real portfolio - verified live - but not worth
    guessing a split for if it ever happens).
    """
    commitment = _gcf_commitment_summary(activity)
    if commitment is None:
        return []
    total_amount_usd, year, board_approval_date = commitment

    sector_codes = activity.get("sector_code", [])
    sector_percentages = activity.get("sector_percentage", [])

    country_codes = activity.get("recipient_country_code", [])
    country_percentages = activity.get("recipient_country_percentage", [])
    if not country_codes or len(country_percentages) != len(country_codes):
        return []

    payloads = []
    for alpha2, pct in zip(country_codes, country_percentages):
        country = pycountry.countries.get(alpha_2=alpha2)
        # Falls back to the raw alpha-2 code if pycountry doesn't recognize
        # it - same reasoning as B1.1's World Bank collector: it will never
        # match a `Country.isoCode` downstream, so the record is
        # quarantined as unknown_country rather than silently mis-mapped.
        country_iso = country.alpha_3 if country is not None else alpha2
        prorated_amount = int(round(total_amount_usd * pct / 100))
        payloads.append({
            "source": "gcf",
            "project_id": activity["iati_identifier"],
            "country_iso": country_iso,
            "year": year,
            "amount_usd": prorated_amount,
            "funding_type": "multilateral",
            "raw_sector_codes": sector_codes,
            "raw_sector_percentages": sector_percentages,
            "board_approval_date": board_approval_date,
            "collected_at": dt.datetime.now(dt.timezone.utc).isoformat(),
        })
    return payloads


def collect_and_publish(producer) -> int:
    """Fetches GCF's entire IATI-published portfolio and publishes every
    parseable (activity, recipient_country) split to `nev.funding.raw` via
    `producer` (a `kafka.KafkaProducer`, e.g. from
    `pipeline.common.kafka_client.make_producer()`). Returns the number of
    messages actually published.
    """
    published = 0
    for raw_activity in fetch_gcf_activities():
        for payload in parse_activity(raw_activity):
            producer.send("nev.funding.raw", payload)
            published += 1
    producer.flush()
    return published
```

Save as `pipeline/collectors/gcf.py`.

- [ ] **Step 5: Run to verify it passes**

```bash
docker compose run --rm funding-validator python -m pytest pipeline/tests/test_gcf_collector.py -v
```
Expected: PASS (5 tests).

- [ ] **Step 6: Write the live smoke test**

```python
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
```

Save as `pipeline/tests/test_gcf_collector_live.py`.

- [ ] **Step 7: Run it**

```bash
docker compose run --rm funding-validator python -m pytest pipeline/tests/test_gcf_collector_live.py -v -m live
```
Expected: PASS (1 test) — proves the real IATI Datastore contract still matches what this
task's implementation assumes, not just the mocked fixtures.

- [ ] **Step 8: Commit**

```bash
git add pipeline/collectors/gcf.py pipeline/tests/test_gcf_collector.py pipeline/tests/test_gcf_collector_live.py docker-compose.yml .env.example
git commit -m "feat(b1.2): add GCF collector (IATI Datastore)"
```

---

## Task 3: Extend the funding validator for GCF

**Files:**
- Modify: `pipeline/processors/funding_validator.py`
- Modify: `pipeline/tests/test_funding_validator.py`
- Modify: `backend/src/DataFixtures/SourceFixtures.php` (add the GCF source row)

**Interfaces:**
- Consumes: `map_gcf_sector` from Task 1, the `nev.funding.raw` GCF payload shape from Task 2.
- Produces: `process_message` now dispatches on `message["source"]` (`"world_bank"` or `"gcf"`) — unchanged signature, `tuple[bool, str | None]`.

- [ ] **Step 1: Add the GCF source fixture row**

In `backend/src/DataFixtures/SourceFixtures.php`, add a new row to the `SOURCES` constant,
**deliberately a new row, not the existing PDF one**:

```php
    private const SOURCES = [
        ['World Bank Data API', 'world-bank-api', SourceType::OfficialApi, SourceReliability::High],
        ['Green Climate Fund — Annual Report (PDF)', 'gcf-pdf-report', SourceType::PdfReport, SourceReliability::Medium],
        ['Green Climate Fund — IATI Datastore', 'gcf-iati-datastore', SourceType::OfficialApi, SourceReliability::High],
        ['GreenAccess Platform Events', 'greenaccess-events', SourceType::GreenAccessEvent, SourceReliability::Medium],
        ['NEV Climate Data — Internal Demonstration', 'internal-demo', SourceType::InternalDemo, SourceReliability::Low],
    ];
```

The existing `'Green Climate Fund — Annual Report (PDF)'` row (`SourceType::PdfReport`) stays
untouched — its type is wrong for this connector (real GCF data comes from the IATI Datastore
API, not a PDF, per this plan's spec), and it may still be needed for a future PDF-based source.
Adding a second, differently-typed row for the same real-world organisation is intentional, not
a duplicate: `Source.name` uniqueness (added in B1.1) is per-name, and these two names differ.

Reload fixtures so local dev/test databases pick up the new row:

```bash
docker compose exec backend php bin/console doctrine:fixtures:load --no-interaction
docker compose exec -e APP_ENV=test backend php bin/console doctrine:fixtures:load --no-interaction
```
Expected: both complete without error. Verify:
```bash
docker compose exec backend php bin/console dbal:run-sql "SELECT name, type FROM source WHERE name LIKE 'Green Climate Fund%'"
```
Expected: two rows, one `pdf_report`, one `official_api`.

- [ ] **Step 2: Write the failing tests**

Add to `pipeline/tests/test_funding_validator.py` (alongside the existing World Bank tests and
fixtures — `db_cursor`, `_funding_row` already exist there from B1.1, reused as-is):

```python
def _gcf_sample_message(amount_usd: int) -> dict:
    return {
        "source": "gcf",
        "project_id": "XM-DAC-41317-TEST",
        "country_iso": "SEN",
        "year": 2026,
        "amount_usd": amount_usd,
        "funding_type": "multilateral",
        "raw_sector_codes": ["23210"],
        "raw_sector_percentages": [100.0],
        "board_approval_date": "2026-01-15",
        "collected_at": "2026-08-28T00:00:00Z",
    }


def test_gcf_message_inserts_a_new_funding_row_under_the_gcf_source(db_cursor):
    accepted, reason = process_message(db_cursor, _gcf_sample_message(2_000_000))

    assert accepted is True
    assert reason is None

    db_cursor.execute("SELECT id FROM source WHERE name = 'Green Climate Fund — IATI Datastore'")
    source_id = db_cursor.fetchone()[0]
    db_cursor.execute("SELECT id FROM country WHERE iso_code = 'SEN'")
    country_id = db_cursor.fetchone()[0]
    db_cursor.execute("SELECT id FROM sector WHERE name = 'Renewable Energy'")
    sector_id = db_cursor.fetchone()[0]

    row = _funding_row(db_cursor, source_id, country_id, sector_id, 2026, "multilateral")
    assert row == (Decimal("2000000.00"), True)


def test_gcf_and_world_bank_rows_stay_separate_for_the_same_dedup_key(db_cursor):
    # Same country/sector/year/type, different source - the dedup key
    # includes source_id, so these must NOT be summed together.
    process_message(db_cursor, _sample_message(1_000_000))       # world_bank, Renewable Energy, 2026
    process_message(db_cursor, _gcf_sample_message(2_000_000))   # gcf, Renewable Energy, 2026

    db_cursor.execute("SELECT id FROM country WHERE iso_code = 'SEN'")
    country_id = db_cursor.fetchone()[0]
    db_cursor.execute("SELECT id FROM sector WHERE name = 'Renewable Energy'")
    sector_id = db_cursor.fetchone()[0]

    db_cursor.execute(
        """
        SELECT amount FROM funding
        WHERE country_id = %s AND sector_id = %s AND year = 2026
          AND funding_type = 'multilateral' AND is_current = true
        ORDER BY amount
        """,
        (country_id, sector_id),
    )
    amounts = [row[0] for row in db_cursor.fetchall()]
    assert amounts == [Decimal("1000000.00"), Decimal("2000000.00")]


def test_gcf_unclassifiable_sector_is_rejected_without_writing(db_cursor):
    message = _gcf_sample_message(1_000_000)
    message["raw_sector_codes"] = ["16010", "16050"]
    message["raw_sector_percentages"] = [48.0, 52.0]

    accepted, reason = process_message(db_cursor, message)

    assert accepted is False
    assert reason == "unclassifiable_sector"


def test_unrecognized_source_is_rejected():
    message = _gcf_sample_message(1_000_000)
    message["source"] = "something_else"

    # No DB touched - dispatch fails before any query, so cursor is never used.
    accepted, reason = process_message(None, message)

    assert accepted is False
    assert reason == "unknown_source"
```

- [ ] **Step 3: Run to verify the new tests fail**

```bash
docker compose run --rm funding-validator python -m pytest pipeline/tests/test_funding_validator.py -v
```
Expected: the 4 new tests FAIL (`KeyError` on `raw_sector_codes`, or wrong sector resolved) —
the existing 3 World Bank tests still PASS unchanged.

- [ ] **Step 4: Update the implementation**

In `pipeline/processors/funding_validator.py`, add the import and the GCF source helper, then
replace `process_message`:

```python
from pipeline.processors.sector_mapping_gcf import map_gcf_sector
```

```python
GCF_SOURCE_NAME = "Green Climate Fund — IATI Datastore"  # matches backend/src/DataFixtures/SourceFixtures.php - a NEW row, not the existing PDF-typed one (see B1.2 plan Task 3, Step 1)


def ensure_gcf_source(cursor) -> int:
    """Idempotently ensures the GCF (IATI Datastore) row exists in
    `source`, and returns its id.
    """
    cursor.execute(
        """
        INSERT INTO source (name, type, reliability)
        VALUES (%s, 'official_api', 'high')
        ON CONFLICT (name) DO NOTHING
        """,
        (GCF_SOURCE_NAME,),
    )
    cursor.execute("SELECT id FROM source WHERE name = %s", (GCF_SOURCE_NAME,))
    return cursor.fetchone()[0]
```

Replace the existing `process_message` with:

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

    upsert_funding(
        cursor,
        source_id=source_id,
        country_id=country_id,
        sector_id=sector_id,
        year=message["year"],
        funding_type=message["funding_type"],
        amount=Decimal(message["amount_usd"]),
        collection_date=message["collected_at"][:10],
    )
    return True, None
```

- [ ] **Step 5: Run to verify everything passes**

```bash
docker compose run --rm funding-validator python -m pytest pipeline/tests/test_funding_validator.py -v
```
Expected: PASS (7 tests: the 3 existing World Bank ones, unchanged, plus the 4 new GCF ones).

- [ ] **Step 6: Run the full pipeline test suite together**

```bash
docker compose run --rm funding-validator python -m pytest pipeline/tests/ -v -m "not live"
```
Expected: PASS (32 tests: 8 sector-mapping World Bank + 10 sector-mapping GCF + 4
collector-offline World Bank + 5 collector-offline GCF + 3 validator World Bank + 4 validator
GCF; the `live` marker exclusion skips both network smoke tests, already verified separately).

- [ ] **Step 7: Restart the funding-validator service**

The always-on consumer needs the new code (it doesn't hot-reload):
```bash
docker compose up -d --build funding-validator
docker compose logs funding-validator --tail 20
```
Expected: no crash/traceback.

- [ ] **Step 8: Commit**

```bash
git add pipeline/processors/funding_validator.py pipeline/tests/test_funding_validator.py backend/src/DataFixtures/SourceFixtures.php
git commit -m "feat(b1.2): dispatch funding-validator to GCF sector mapping by source"
```

---

## Task 4: Airflow DAG

**Files:**
- Create: `pipeline/dags/collecte_gcf.py`

**Interfaces:**
- Consumes: `collect_and_publish` from Task 2, `make_producer` from `pipeline/common/kafka_client.py` (existing, from B1.1 Task 4).
- Produces: nothing consumed by a later task — Task 5 verifies this DAG end-to-end.

- [ ] **Step 1: Write the DAG**

```python
"""Airflow DAG: monthly collection of Green Climate Fund (GCF) project
financing via the IATI Datastore - see the B1.2 spec, decision 1 (a
single request covers GCF's entire IATI-published portfolio, no
per-country querying needed - unlike B1.1's World Bank DAG, this one does
not need NEV's own `country` table at all) and the plan's own B1.2
livrable ("DAG Airflow mensuel").
"""
from datetime import datetime, timedelta

from airflow import DAG
from airflow.operators.python import PythonOperator

from pipeline.collectors.gcf import collect_and_publish
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
    dag_id="collecte_gcf",
    default_args=default_args,
    schedule_interval="0 3 1 * *",  # 1er jour de chaque mois, 03h00
    start_date=datetime(2026, 1, 1),
    catchup=False,
    tags=["b1.2", "collecte", "gcf"],
) as dag:
    collecter = PythonOperator(
        task_id="collecter_financements_gcf",
        python_callable=_collect,
    )
```

Save as `pipeline/dags/collecte_gcf.py`. `./pipeline` is already volume-mounted to
`/opt/airflow/pipeline` and `/opt/airflow` is already on `PYTHONPATH` (both from B1.1 Task 1/8)
— this file needs no additional Airflow configuration.

- [ ] **Step 2: Verify the DAG is recognized**

```bash
docker compose exec airflow airflow dags list
```
Expected: both `collecte_worldbank` and `collecte_gcf` appear, with no import errors.

```bash
docker compose exec airflow airflow dags list-import-errors
```
Expected: empty output.

- [ ] **Step 3: Commit**

```bash
git add pipeline/dags/collecte_gcf.py
git commit -m "feat(b1.2): add monthly GCF collection DAG"
```

---

## Task 5: End-to-end verification

**Files:**
- None (verification-only task).

**Interfaces:**
- Consumes: the entire connector built in Tasks 1-4, running on B1.1's already-provisioned infrastructure.
- Produces: nothing — this is the plan's acceptance check.

- [ ] **Step 1: Trigger the DAG manually**

```bash
docker compose exec airflow airflow dags unpause collecte_gcf
docker compose exec airflow airflow dags trigger collecte_gcf
```

Wait a minute or two (one HTTP request, ~350 activities to parse — much faster than B1.1's
54-country World Bank run), then check:

```bash
docker compose exec airflow airflow dags list-runs -d collecte_gcf
```
Expected: the run shows state `success`.

If it fails, find and read the task log:
```bash
docker compose exec airflow sh -c "find /opt/airflow/logs/dag_id=collecte_gcf -name '*.log' | sort | tail -1 | xargs cat"
```

- [ ] **Step 2: Verify real data landed in `funding`**

```bash
docker compose exec backend php bin/console dbal:run-sql "SELECT count(*) FROM funding WHERE source_id = (SELECT id FROM source WHERE name = 'Green Climate Fund — IATI Datastore')"
```
Expected: a count greater than 0.

```bash
docker compose exec backend php bin/console dbal:run-sql "SELECT country_id, sector_id, year, amount, funding_type, is_current FROM funding WHERE source_id = (SELECT id FROM source WHERE name = 'Green Climate Fund — IATI Datastore') ORDER BY amount DESC LIMIT 5"
```
Expected: rows with plausible amounts, `funding_type = multilateral`, `is_current = t`.

- [ ] **Step 3: Verify World Bank and GCF rows stayed separate**

```bash
docker compose exec backend php bin/console dbal:run-sql "SELECT s.name, count(*) FROM funding f JOIN source s ON s.id = f.source_id WHERE s.name IN ('World Bank Data API', 'Green Climate Fund — IATI Datastore') GROUP BY s.name"
```
Expected: two distinct rows, both with real counts (confirms the dedup key correctly keeps
different sources apart — see Task 3's `test_gcf_and_world_bank_rows_stay_separate_for_the_same_dedup_key`,
now confirmed against real data too).

- [ ] **Step 4: Verify the historization/summing behavior for real**

```bash
docker compose exec airflow airflow dags trigger collecte_gcf
```

Wait for it to complete again, then:
```bash
docker compose exec backend php bin/console dbal:run-sql "SELECT count(*) FROM funding WHERE source_id = (SELECT id FROM source WHERE name = 'Green Climate Fund — IATI Datastore') AND is_current = false"
```
Expected: a count greater than 0 (the second run's matching country/sector/year/type
combinations summed onto the first run's rows, historizing the originals — same mechanism
B1.1 already verified, now confirmed for a second real source).

- [ ] **Step 5: Verify quarantine is reachable for GCF-sourced rejects**

```bash
docker compose exec kafka kafka-console-consumer --bootstrap-server localhost:9092 --topic nev.funding.rejets --from-beginning --max-messages 200 --timeout-ms 10000 2>&1 | grep '"source": "gcf"' | head -3
```
Expected: at least one GCF-sourced rejected message (very likely — 205/350 real activities
touch no NEV-tracked country at all, per the spec's own verified numbers, and will hit
`unknown_country`), with a `rejection_reason` field.

- [ ] **Step 6: No commit** — this task only ran verification commands; nothing in the repo
changed.

---

## Task 6: Documentation

**Files:**
- Modify: `README.md`

**Interfaces:**
- Consumes: nothing. Produces: nothing consumed elsewhere — final task of this plan.

- [ ] **Step 1: Extend the "Pipeline (Volet B)" section**

In the "Pipeline (Volet B)" section added by B1.1, add a short subsection right after the
existing "Lancer les tests du pipeline" part:

```markdown
### Connecteur GCF (B1.2)

Deuxième connecteur du Volet B : collecte mensuelle des financements du Fonds Vert pour le
Climat, via l'API IATI Datastore (pas l'API/dashboard propre du GCF, injoignable au moment de
la conception — voir la spec). Décisions de conception complètes :
[`docs/superpowers/specs/2026-08-28-b12-gcf-connector-design.md`](docs/superpowers/specs/2026-08-28-b12-gcf-connector-design.md).

Nécessite `IATI_API_KEY` dans le `.env` racine (clé gratuite, inscription sur
`developer.iatistandard.org` → Subscriptions → "Exploratory").

```bash
docker compose exec airflow airflow dags trigger collecte_gcf
```

Réutilise l'infrastructure et le topic `nev.funding.raw` déjà provisionnés par B1.1 — aucun
nouveau service, aucun nouveau topic.
```

- [ ] **Step 2: Add "Points d'attention" entries**

Append as the next numbered points in that section (after point 17, added by B1.1):

```markdown
18. **Une requête Solr non guillemetée sur un identifiant contenant des tirets fait un matching flou, pas une correspondance exacte.** `reporting_org_ref:XM-DAC-41317` (sans guillemets) tokenize sur les tirets et retourne ~269 000 documents sans rapport, contre 350 avec `reporting_org_ref:"XM-DAC-41317"` (guillemets = expression exacte). Tout code interrogeant l'API IATI Datastore avec un identifiant contenant des tirets doit guillemeter sa valeur dans le paramètre `q=`.

19. **Un champ "provider_org" d'une API ne garantit pas qu'il identifie le vrai payeur.** Sur l'API IATI Datastore, `transaction_provider_org_ref` vaut toujours l'identifiant du GCF sur *toutes* les transactions de type "Commitment" d'une activité GCF — y compris celles explicitement décrites comme "Co-financing commitment" (argent d'autres bailleurs, que GCF enregistre pour la transparence mais qui n'est pas son propre argent). Le seul signal fiable trouvé est un champ texte libre (`transaction_description_narrative`), pas un champ codé — voir `pipeline/collectors/gcf.py::_gcf_commitment_summary` et la décision 4 de la spec B1.2. Toujours vérifier qu'un champ apparemment codé («provider», «owner», «source») identifie vraiment ce que son nom suggère, sur des données réelles, avant de s'appuyer dessus pour un calcul financier.
```

- [ ] **Step 3: Commit**

```bash
git add README.md
git commit -m "docs(b1.2): document the GCF connector"
```

---

## Final check before considering B1.2 done

- [ ] `docker compose run --rm funding-validator python -m pytest pipeline/tests/ -v -m "not live"` — all green (32 tests).
- [ ] `docker compose run --rm funding-validator python -m pytest pipeline/tests/test_gcf_collector_live.py -v -m live` — green (real IATI API still matches assumptions).
- [ ] `docker compose exec backend php bin/phpunit` (full existing suite) — still green, confirming the new `SourceFixtures.php` row caused no regression.
- [ ] Two manual DAG triggers (Task 5) produced real `Funding` rows with `source = Green Climate Fund — IATI Datastore`, `funding_type = multilateral`, correct summing/historization on the second run, and World Bank rows verified to stay separate under the shared dedup key.
- [ ] Cross-check against the plan spreadsheet: B1.2's "Livrable attendu" was *"DAG Airflow mensuel publiant vers le topic Kafka dédié"* — confirmed: `collecte_gcf` DAG exists, scheduled monthly, publishes to `nev.funding.raw`.
