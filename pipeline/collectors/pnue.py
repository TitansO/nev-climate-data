"""PNUE (UNEP) connector - country-level CO2 emissions (B1.4). Fetches
each NEV-tracked country's national CO2 emissions from the UN SDG Global
Database API (indicator 9.4.1, series EN_ATM_CO2, IEA-attributed data -
see the B1.4 spec's data source decision for why no UNEP-native API could
be used), filters to the national-total dimension, and publishes to the
dedicated Kafka topic `nev.emissions.raw`.
"""
from __future__ import annotations

import datetime as dt
from typing import Any, Iterator

import pycountry
import requests

SDG_API_URL = "https://unstats.un.org/SDGAPI/v1/sdg/Series/Data"
SERIES_CODE = "EN_ATM_CO2"
# The series carries two "Activity" dimension values per country/year:
# "TOTAL" (the real national figure) and "ISIC4_C10T32X19" (a
# manufacturing-sector subset). Verified live (Senegal, 2000): TOTAL=3.52
# Mt, ISIC4_C10T32X19=0.5 Mt - different numbers for the same year. Only
# TOTAL is published - see parse_emission().
TOTAL_ACTIVITY_CODE = "TOTAL"
REQUEST_TIMEOUT_SECONDS = 30
# A single country's full time series was verified live to be 48 rows (24
# years x 2 Activity values) - 100 gives comfortable headroom without
# needing pagination.
PAGE_SIZE = 100


def country_iso3_to_m49(country_iso: str) -> str | None:
    """Converts an ISO 3166-1 alpha-3 code to the UN M49 numeric area code
    the SDG API expects - verified live to be numerically identical to the
    ISO 3166-1 numeric code (Senegal: alpha-3 "SEN" -> numeric "686", and
    querying the SDG API with areaCode=686 returns Senegal's real data).
    Returns None for a code pycountry doesn't recognize.
    """
    country = pycountry.countries.get(alpha_3=country_iso)
    return country.numeric if country is not None else None


def parse_emission(row: dict[str, Any]) -> dict[str, Any] | None:
    """Converts one raw UN SDG API data row into a `nev.emissions.raw`
    payload, or None if it isn't the national total (see TOTAL_ACTIVITY_CODE).
    """
    if row.get("dimensions", {}).get("Activity") != TOTAL_ACTIVITY_CODE:
        return None

    country = pycountry.countries.get(numeric=row["geoAreaCode"])
    # Falls back to the raw numeric code if pycountry doesn't recognize it
    # - same reasoning as every earlier connector: it will never match a
    # Country.isoCode downstream, so the record is quarantined as
    # unknown_country rather than silently mis-mapped.
    country_iso = country.alpha_3 if country is not None else row["geoAreaCode"]

    return {
        "source": "pnue",
        "country_iso": country_iso,
        "year": int(row["timePeriodStart"]),
        "value_mt": float(row["value"]),
        "collected_at": dt.datetime.now(dt.timezone.utc).isoformat(),
    }


def fetch_emissions_for_country(area_code: str) -> Iterator[dict[str, Any]]:
    """Yields every raw UN SDG API data row for one country's CO2 series -
    a single request covers a country's full time series (verified live:
    at most ~48 rows, well under PAGE_SIZE, no pagination needed).
    """
    response = requests.get(
        SDG_API_URL,
        params={
            "seriesCode": SERIES_CODE,
            "areaCode": area_code,
            "pageSize": PAGE_SIZE,
        },
        timeout=REQUEST_TIMEOUT_SECONDS,
    )
    response.raise_for_status()
    payload = response.json()
    yield from payload["data"]


def collect_and_publish(country_isos: list[str], producer) -> int:
    """Fetches CO2 emissions for every country in `country_isos` and
    publishes each parseable (Activity == "TOTAL") row to
    `nev.emissions.raw` via `producer` (a `kafka.KafkaProducer`, e.g. from
    `pipeline.common.kafka_client.make_producer()`). Returns the number of
    messages actually published.
    """
    published = 0
    for country_iso in country_isos:
        area_code = country_iso3_to_m49(country_iso)
        if area_code is None:
            continue
        for row in fetch_emissions_for_country(area_code):
            payload = parse_emission(row)
            if payload is None:
                continue
            producer.send("nev.emissions.raw", payload)
            published += 1
    producer.flush()
    return published
