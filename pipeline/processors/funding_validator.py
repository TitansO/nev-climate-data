"""Consumes `nev.funding.raw`, applies the B1.1 sector-mapping rule, and
writes to the `funding` table directly (bypassing the Symfony backend
entirely - see architecture spec decision 3). Publishes each record to
`nev.funding.valides` or `nev.funding.rejets` depending on outcome.

Long-running service - see the `funding-validator` entry in
docker-compose.yml.
"""
from __future__ import annotations

from decimal import Decimal
from typing import Any

from pipeline.common.db import get_connection
from pipeline.common.kafka_client import make_consumer, make_producer
from pipeline.processors.sector_mapping import map_to_nev_sector
from pipeline.processors.sector_mapping_gcf import map_gcf_sector

WORLD_BANK_SOURCE_NAME = "World Bank Data API"  # matches backend/src/DataFixtures/SourceFixtures.php - reuses that row instead of creating a duplicate
GCF_SOURCE_NAME = "Green Climate Fund — IATI Datastore"  # matches backend/src/DataFixtures/SourceFixtures.php - a NEW row, not the existing PDF-typed one (see B1.2 plan Task 3, Step 1)


def ensure_world_bank_source(cursor) -> int:
    """Idempotently ensures the `World Bank` row exists in `source`, and
    returns its id.
    """
    cursor.execute(
        """
        INSERT INTO source (name, type, reliability)
        VALUES (%s, 'official_api', 'high')
        ON CONFLICT (name) DO NOTHING
        """,
        (WORLD_BANK_SOURCE_NAME,),
    )
    cursor.execute("SELECT id FROM source WHERE name = %s", (WORLD_BANK_SOURCE_NAME,))
    return cursor.fetchone()[0]


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


def lookup_country_id(cursor, country_iso: str) -> int | None:
    cursor.execute("SELECT id FROM country WHERE iso_code = %s", (country_iso,))
    row = cursor.fetchone()
    return row[0] if row else None


def lookup_sector_id(cursor, sector_name: str) -> int | None:
    cursor.execute("SELECT id FROM sector WHERE name = %s", (sector_name,))
    row = cursor.fetchone()
    return row[0] if row else None


def upsert_funding(cursor, *, source_id: int, country_id: int, sector_id: int, year: int,
                    funding_type: str, amount: Decimal, collection_date: str) -> None:
    """Sums `amount` into the current row for this dedup key if one
    exists (closing it out and inserting a new historized version), or
    inserts a fresh row otherwise - see B1.1 spec decision 6.
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
            created_at, updated_at
        ) VALUES (
            %s, %s, %s, %s, %s, %s,
            %s, 'validated', now(), true,
            now(), now()
        )
        """,
        (country_id, sector_id, year, new_amount, funding_type, source_id, collection_date),
    )


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


def run() -> None:
    consumer = make_consumer("nev.funding.raw", group_id="funding-validator")
    producer = make_producer()

    for kafka_message in consumer:
        message = kafka_message.value
        connection = get_connection()
        try:
            with connection:
                with connection.cursor() as cursor:
                    accepted, reason = process_message(cursor, message)
        finally:
            connection.close()

        if accepted:
            producer.send("nev.funding.valides", message)
        else:
            producer.send("nev.funding.rejets", {**message, "rejection_reason": reason})

    producer.flush()


if __name__ == "__main__":
    run()
