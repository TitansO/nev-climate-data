"""OPEC Fund Climate Finance Report collector (B1.5) - downloads the real
target PDF, checks the SHA-256 hash cache, extracts Annex 2's real
per-project table via Gemini, validates it, and publishes up to two
`nev.funding.raw` payloads per row (adaptation + mitigation shares). See
the B1.5 spec for the full real-world research behind every decision here.
"""
from __future__ import annotations

import datetime as dt
import json
from typing import Any

import pycountry
import requests

from pipeline.common.db import get_connection
from pipeline.common.pdf_extraction import (
    extract_json_via_gemini,
    is_already_processed,
    make_minio_client,
    record_processed,
    sha256_hash,
    slice_pdf_pages,
    upload_to_minio,
)
from pipeline.processors.sector_mapping_opec import map_opec_sector

SOURCE_NAME = "OPEC Fund — Climate Finance Report (PDF, Gemini-assisted)"  # matches backend/src/DataFixtures/SourceFixtures.php
SOURCE_URL = "https://www.climatebusiness.africa/wp-content/uploads/2024/11/Climate-Finance-Report-2024.pdf"
DOCUMENT_SLUG = "opec-fund-climate-finance-2024"
ANNEX_2_START_PAGE = 62
ANNEX_2_END_PAGE = 69
REQUEST_TIMEOUT_SECONDS = 60

EXTRACTION_PROMPT = """This PDF contains "Annex 2: OPEC Fund Climate Finance Portfolio 2018-2023",
a table with columns: Year of Approval, Country, Project, Sector, OPEC Fund contribution
in US$MN, Adaptation Finance (%), Mitigation Finance (%), Total Climate Finance (%).

Extract EVERY row of this table as a strict JSON array (and nothing else - no markdown
fences, no commentary). Each element must have exactly these keys:
year (integer), country (string, exactly as written), project (string), sector (string,
exactly as written), amount_usd_mn (number), adaptation_pct (number), mitigation_pct
(number), total_climate_pct (number).

Do not skip any row. Do not invent or estimate any value - if a cell is genuinely
illegible, use null for that field only, never a guess."""


def country_name_to_iso3(name: str) -> str | None:
    """Resolves a real country name from the source table to its ISO
    3166-1 alpha-3 code - exact `pycountry` name match first, `search_fuzzy`
    as a fallback. Returns None for a name that matches neither, including
    real regional/multi-country entries ("Africa (regional)", etc.) - these
    are skipped pre-publish, same "nothing real to attribute the financing
    to" philosophy as every earlier connector's own no-country handling.
    """
    exact = pycountry.countries.get(name=name)
    if exact is not None:
        return exact.alpha_3
    try:
        fuzzy = pycountry.countries.search_fuzzy(name)
        return fuzzy[0].alpha_3
    except LookupError:
        return None


def validate_invariant(row: dict[str, Any], tolerance: float = 0.5) -> bool:
    """Returns False when `total_climate_pct` doesn't equal
    `adaptation_pct + mitigation_pct` within `tolerance` - a defensive
    guard against a future silent extraction error (a different report, a
    model regression), not trust-the-single-field. Verified live: this
    invariant holds across every spot-checked real row in the source
    table (e.g. Panama 58.33 + 41.67 = 100.00, Colombia 29.27 + 43.90 =
    73.17).
    """
    expected = (row.get("adaptation_pct") or 0) + (row.get("mitigation_pct") or 0)
    actual = row.get("total_climate_pct") or 0
    return abs(expected - actual) <= tolerance


def build_payloads(row: dict[str, Any], document_hash: str) -> list[dict[str, Any]]:
    """Converts one real Annex 2 table row into zero, one, or two
    `nev.funding.raw` payloads - one for its adaptation share, one for its
    mitigation share (spec decision 7). Returns an empty list if the
    country can't be resolved (decision 5) or the row fails the invariant
    guard (decision 4).
    """
    if not validate_invariant(row):
        return []

    country_iso = country_name_to_iso3(row["country"])
    if country_iso is None:
        return []

    amount_total = row["amount_usd_mn"] * 1_000_000
    project_id = f"{DOCUMENT_SLUG}:{row['year']}:{row['country']}:{row['project']}"
    collected_at = dt.datetime.now(dt.timezone.utc).isoformat()

    payloads = []
    for dimension, pct in (("adaptation", row["adaptation_pct"]), ("mitigation", row["mitigation_pct"])):
        if not pct:
            continue
        payloads.append({
            "source": "opec_fund_pdf",
            "project_id": project_id,
            "country_iso": country_iso,
            "year": row["year"],
            "amount_usd": int(round(amount_total * pct / 100)),
            "funding_type": "multilateral",
            "sector_label_raw": row["sector"],
            "project_name": row["project"],
            "climate_dimension": dimension,
            "document_hash": document_hash,
            "collected_at": collected_at,
        })
    return payloads


def collect_and_publish(producer) -> int:
    """Downloads the real target PDF, checks the hash cache, and - only on
    a genuinely new document - extracts Annex 2, publishes every valid
    row's payloads to `nev.funding.raw`, stores the raw PDF in MinIO, and
    records the cache entry. Returns the number of messages published (0
    on a cache hit, by design - see spec decision 9).
    """
    response = requests.get(SOURCE_URL, timeout=REQUEST_TIMEOUT_SECONDS)
    response.raise_for_status()
    pdf_bytes = response.content
    document_hash = sha256_hash(pdf_bytes)

    connection = get_connection()
    try:
        with connection:
            with connection.cursor() as cursor:
                if is_already_processed(cursor, document_hash):
                    return 0

                annex_bytes = slice_pdf_pages(pdf_bytes, ANNEX_2_START_PAGE, ANNEX_2_END_PAGE)
                raw_text = extract_json_via_gemini(annex_bytes, EXTRACTION_PROMPT)
                rows = json.loads(raw_text)

                published = 0
                for row in rows:
                    for payload in build_payloads(row, document_hash):
                        producer.send("nev.funding.raw", payload)
                        published += 1
                producer.flush()

                today = dt.date.today().isoformat()
                minio_path = f"bronze/{DOCUMENT_SLUG}/{today}/{document_hash}.pdf"
                minio_client = make_minio_client()
                upload_to_minio(minio_client, minio_path, pdf_bytes)

                record_processed(
                    cursor,
                    document_hash=document_hash,
                    source_name=SOURCE_NAME,
                    source_url=SOURCE_URL,
                    minio_path=minio_path,
                    rows_extracted=len(rows),
                )
    finally:
        connection.close()

    return published
