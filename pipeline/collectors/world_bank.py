"""World Bank Projects & Operations API collector for climate-themed
financing (B1.1). Fetches, paginates, and publishes raw payloads to
Kafka topic `nev.funding.raw` - see the B1.1 spec's payload shape.
"""
from __future__ import annotations

import datetime as dt
from typing import Any, Iterator

import pycountry
import requests

PROJECTS_API_URL = "https://search.worldbank.org/api/v3/projects"
PAGE_SIZE = 100
REQUEST_TIMEOUT_SECONDS = 30

FIELDS = ",".join([
    "id", "countryname", "countrycode", "totalamt", "boardapprovaldate",
    "status", "major_sectors", "theme",
])


def fetch_projects_for_country(country_iso: str) -> Iterator[dict[str, Any]]:
    """Yields every raw project record for `country_iso` matching the
    Climate change theme, paginating through the full result set (a
    single country can have hundreds of matching projects - verified 264
    for Senegal alone during the B1.1 design work, well above one page).
    """
    offset = 0
    while True:
        response = requests.get(
            PROJECTS_API_URL,
            params={
                "format": "json",
                "countrycode_exact": country_iso,
                "mjtheme": "Climate change",
                "fl": FIELDS,
                "rows": PAGE_SIZE,
                "os": offset,
            },
            timeout=REQUEST_TIMEOUT_SECONDS,
        )
        response.raise_for_status()
        payload = response.json()

        projects = payload.get("projects", {})
        if not projects:
            return

        yield from projects.values()

        offset += PAGE_SIZE
        if offset >= int(payload.get("total", 0)):
            return


def parse_project(project: dict[str, Any]) -> dict[str, Any] | None:
    """Converts one raw World Bank project record into the `nev.funding.raw`
    payload shape. Returns None for a project with no usable financing
    amount or approval date - such a project (e.g. very early "Pipeline"
    status, verified live to lack these fields) has nothing to publish.
    """
    total_amount = project.get("totalamt")
    approval_date = project.get("boardapprovaldate")
    # `totalamt` is not always an integer string - confirmed live during Task 9's end-to-end
    # run, which crashed on a real record with totalamt="1439873.8" (int() rejects the decimal
    # point; float() handles both integer and decimal strings). Truncates to whole USD, which
    # is what amount_usd represents throughout this connector.
    if not total_amount or not approval_date or float(total_amount) <= 0:
        return None
    total_amount_usd = int(float(total_amount))

    raw_sectors = [
        entry["major_sector"]["major_sector_name"]
        for entry in project.get("major_sectors", [])
        if "major_sector" in entry
    ]
    raw_theme = [theme.strip() for theme in project.get("theme", "").split(",") if theme.strip()]

    country_codes = project.get("countrycode") or [""]
    alpha2 = country_codes[0]
    country = pycountry.countries.get(alpha_2=alpha2)
    # Falls back to the raw alpha-2 code if pycountry doesn't recognize it -
    # that value will simply never match a `Country.isoCode` (all 3 letters)
    # downstream, so the record is quarantined as unknown_country rather
    # than silently mis-mapped. Not expected in practice: every World Bank
    # member country has a valid ISO 3166-1 alpha-2 code.
    country_iso = country.alpha_3 if country is not None else alpha2

    return {
        "source": "world_bank",
        "project_id": project["id"],
        "country_iso": country_iso,
        "year": int(approval_date[:4]),
        "amount_usd": total_amount_usd,
        "funding_type": "multilateral",
        "raw_sectors": raw_sectors,
        "raw_theme": raw_theme,
        "board_approval_date": approval_date[:10],
        "collected_at": dt.datetime.now(dt.timezone.utc).isoformat(),
    }


def collect_and_publish(country_isos: list[str], producer) -> int:
    """Fetches climate-themed World Bank projects for every country in
    `country_isos` and publishes each parseable one to `nev.funding.raw`
    via `producer` (a `kafka.KafkaProducer`, e.g. from
    `pipeline.common.kafka_client.make_producer()`). Returns the number of
    messages actually published.
    """
    published = 0
    for country_iso in country_isos:
        for raw_project in fetch_projects_for_country(country_iso):
            payload = parse_project(raw_project)
            if payload is None:
                continue
            producer.send("nev.funding.raw", payload)
            published += 1
    producer.flush()
    return published
