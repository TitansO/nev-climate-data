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
import time
from typing import Any, Iterator

import pycountry
import requests

IATI_DATASTORE_URL = "https://api.iatistandard.org/datastore/activity/select"
AFDB_REPORTING_ORG_REF = "XM-DAC-46002"
PAGE_SIZE = 1000
REQUEST_TIMEOUT_SECONDS = 30
# The IATI Datastore's free ("Exploratory") subscription tier enforces a
# real 1 request/second rate limit - confirmed live while running this
# connector end-to-end: firing all 6 pagination requests back-to-back
# (no delay) reliably hit HTTP 429 partway through, every single run,
# regardless of how much of the tier's daily quota remained. B1.2's GCF
# collector never needed this - its entire portfolio fits in one request.
PAGINATION_DELAY_SECONDS = 1.1

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
        if offset > 0:
            # Real rate limit, not a precaution - see PAGINATION_DELAY_SECONDS.
            time.sleep(PAGINATION_DELAY_SECONDS)
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
        # Advance by the number of documents actually received, not the
        # requested page size - a non-final page normally returns exactly
        # `rows` documents against a real Solr backend, but advancing by
        # the real count is correct regardless (confirmed by a test where
        # it isn't: a short first page must not make the loop stop early).
        offset += len(docs)
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
