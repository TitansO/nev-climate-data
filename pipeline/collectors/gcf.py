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
