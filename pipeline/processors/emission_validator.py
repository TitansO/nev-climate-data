"""Consumes `nev.emissions.raw`, resolves the country, and writes to the
`emission` table directly (bypassing the Symfony backend entirely - same
architecture as funding_validator.py, see architecture spec decision 3).
Publishes each record to `nev.emissions.valides` or `nev.emissions.rejets`
depending on outcome.

Kept as a separate file/service from funding_validator.py - see the B1.4
spec, decision 10: this is a different data domain (environmental impact,
not financing), and mixing it into funding_validator.py's 3-source
dispatch would break that file's single responsibility.

Long-running service - see the `emission-validator` entry in
docker-compose.yml.
"""
from __future__ import annotations

from decimal import Decimal
from typing import Any

from pipeline.common.db import get_connection
from pipeline.common.kafka_client import make_consumer, make_producer

PNUE_SOURCE_NAME = "UN SDG Global Database — Indicator 9.4.1 (IEA)"  # matches backend/src/DataFixtures/SourceFixtures.php


def ensure_pnue_source(cursor) -> int:
    """Idempotently ensures the PNUE (UN SDG API) row exists in `source`,
    and returns its id.
    """
    cursor.execute(
        """
        INSERT INTO source (name, type, reliability)
        VALUES (%s, 'official_api', 'high')
        ON CONFLICT (name) DO NOTHING
        """,
        (PNUE_SOURCE_NAME,),
    )
    cursor.execute("SELECT id FROM source WHERE name = %s", (PNUE_SOURCE_NAME,))
    return cursor.fetchone()[0]


def lookup_country_id(cursor, country_iso: str) -> int | None:
    cursor.execute("SELECT id FROM country WHERE iso_code = %s", (country_iso,))
    row = cursor.fetchone()
    return row[0] if row else None


def upsert_emission(cursor, *, source_id: int, country_id: int, year: int,
                    value_mt: Decimal, collection_date: str) -> None:
    """Replaces (not sums) the current row for this dedup key if one
    exists - see B1.4 spec decision 6: a national annual CO2 figure is a
    single authoritative statistic the IEA periodically revises, unlike
    Funding's additive transaction stream. The previous value is
    historized (is_current = false, valid_to = now()), never summed with
    the new one.
    """
    cursor.execute(
        """
        SELECT id FROM emission
        WHERE source_id = %s AND country_id = %s AND year = %s AND is_current = true
        """,
        (source_id, country_id, year),
    )
    existing = cursor.fetchone()

    if existing is not None:
        existing_id = existing[0]
        cursor.execute(
            "UPDATE emission SET is_current = false, valid_to = now() WHERE id = %s",
            (existing_id,),
        )

    cursor.execute(
        """
        INSERT INTO emission (
            country_id, year, value_mt, source_id,
            collection_date, validation_status, valid_from, is_current,
            created_at, updated_at
        ) VALUES (
            %s, %s, %s, %s,
            %s, 'validated', now(), true,
            now(), now()
        )
        """,
        (country_id, year, value_mt, source_id, collection_date),
    )


def process_message(cursor, message: dict[str, Any]) -> tuple[bool, str | None]:
    """Resolves the country and, on success, upserts. Returns
    (accepted, reason) - `reason` is None when accepted, or a short
    machine-readable string explaining rejection when not.
    """
    if message["source"] != "pnue":
        return False, "unknown_source"

    country_id = lookup_country_id(cursor, message["country_iso"])
    if country_id is None:
        return False, "unknown_country"

    source_id = ensure_pnue_source(cursor)

    upsert_emission(
        cursor,
        source_id=source_id,
        country_id=country_id,
        year=message["year"],
        # str() first - avoids binary-float artifacts in the Decimal
        # conversion (e.g. Decimal(13.1) != Decimal("13.1")), same
        # treatment as funding_validator's currency fields.
        value_mt=Decimal(str(message["value_mt"])),
        collection_date=message["collected_at"][:10],
    )
    return True, None


def run() -> None:
    consumer = make_consumer("nev.emissions.raw", group_id="emission-validator")
    producer = make_producer()

    for kafka_message in consumer:
        message = kafka_message.value
        connection = get_connection()
        try:
            with connection:
                with connection.cursor() as cursor:
                    accepted, reason = process_message(cursor, message)
        except Exception as exc:
            # A malformed/unexpected message must never crash this permanent
            # service - see the 2026-08-31 B1.6 closure spec. psycopg2's
            # `with connection:` above already rolled back any partial
            # transaction before this exception reached here.
            accepted, reason = False, f"processing_error:{type(exc).__name__}"
            print(f"[emission-validator] unexpected error processing message: {exc!r}")
        finally:
            connection.close()

        if accepted:
            producer.send("nev.emissions.valides", message)
        else:
            producer.send("nev.emissions.rejets", {**message, "rejection_reason": reason})

    producer.flush()


if __name__ == "__main__":
    run()
