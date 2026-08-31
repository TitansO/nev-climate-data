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
from pipeline.processors.sector_mapping_afdb import map_afdb_sector
from pipeline.processors.sector_mapping_opec import map_opec_sector

WORLD_BANK_SOURCE_NAME = "World Bank Data API"  # matches backend/src/DataFixtures/SourceFixtures.php - reuses that row instead of creating a duplicate
GCF_SOURCE_NAME = "Green Climate Fund — IATI Datastore"  # matches backend/src/DataFixtures/SourceFixtures.php - a NEW row, not the existing PDF-typed one (see B1.2 plan Task 3, Step 1)
AFDB_SOURCE_NAME = "African Development Bank Group — IATI Datastore"  # matches backend/src/DataFixtures/SourceFixtures.php
OPEC_FUND_SOURCE_NAME = "OPEC Fund — Climate Finance Report (PDF, Gemini-assisted)"  # matches backend/src/DataFixtures/SourceFixtures.php


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


def ensure_opec_fund_source(cursor) -> int:
    """Idempotently ensures the OPEC Fund (PDF, Gemini-assisted) row
    exists in `source`, and returns its id.
    """
    cursor.execute(
        """
        INSERT INTO source (name, type, reliability)
        VALUES (%s, 'pdf_report', 'high')
        ON CONFLICT (name) DO NOTHING
        """,
        (OPEC_FUND_SOURCE_NAME,),
    )
    cursor.execute("SELECT id FROM source WHERE name = %s", (OPEC_FUND_SOURCE_NAME,))
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
                    funding_type: str, delta: Decimal, collection_date: str,
                    original_amount: Decimal | None = None,
                    original_currency: str | None = None,
                    exchange_rate: Decimal | None = None) -> None:
    """Applies `delta` (can be negative - see apply_project_contribution's
    sector/year-change case) to the current row for this dedup key,
    historizing the previous version, or inserts a fresh row if none
    exists yet. A zero delta is a genuine no-op - see the 2026-08-31
    idempotency fix spec: it must never create a needless new historized
    version (that was the exact shape of the real double-counting bug).
    `original_amount`/`original_currency`/`exchange_rate` describe the
    latest contributing message's raw figures (not accumulated across
    historized versions, same treatment as `collection_date`) - only
    populated by connectors reporting in a non-pivot currency (B1.3's
    AfDB connector is the first; World Bank/GCF never pass them, so they
    stay NULL as before).
    """
    if delta == 0:
        return

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
        new_amount = existing_amount + delta
        cursor.execute(
            "UPDATE funding SET is_current = false, valid_to = now() WHERE id = %s",
            (existing_id,),
        )
    else:
        new_amount = delta

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


def apply_project_contribution(cursor, *, source_id: int, project_id: str, country_id: int,
                                sector_id: int, year: int, funding_type: str, amount: Decimal,
                                collection_date: str,
                                original_amount: Decimal | None = None,
                                original_currency: str | None = None,
                                exchange_rate: Decimal | None = None) -> None:
    """Applies one project's real, current contribution to the `funding`
    aggregate for its dedup key - idempotently. Fixes a real production
    bug (2026-08-31): every collection DAG re-publishes its entire current
    portfolio on every run, not just new/changed projects, so summing each
    incoming message's amount blindly (the old upsert_funding contract)
    double-counted every project on every re-run. This function tracks
    each project's last-known contribution in `funding_project_contribution`
    and applies only the real delta: a project reported again with the
    exact same amount contributes nothing (delta 0 - the bug scenario); a
    project reported with a genuinely different amount contributes only
    the difference (a real revision, kept traceable in `funding`'s own
    SCD2 history); a project whose dedup key itself changed (e.g. a
    sector-mapping fix between two runs) is moved from its old key's
    aggregate to its new one rather than guessing which aggregate a raw
    delta belongs to.
    """
    cursor.execute(
        """
        SELECT sector_id, year, funding_type, amount FROM funding_project_contribution
        WHERE source_id = %s AND project_id = %s AND country_id = %s
        """,
        (source_id, project_id, country_id),
    )
    existing = cursor.fetchone()

    if existing is None:
        upsert_funding(
            cursor, source_id=source_id, country_id=country_id, sector_id=sector_id,
            year=year, funding_type=funding_type, delta=amount, collection_date=collection_date,
            original_amount=original_amount, original_currency=original_currency,
            exchange_rate=exchange_rate,
        )
        cursor.execute(
            """
            INSERT INTO funding_project_contribution
                (source_id, project_id, country_id, sector_id, year, funding_type, amount, updated_at)
            VALUES (%s, %s, %s, %s, %s, %s, %s, now())
            """,
            (source_id, project_id, country_id, sector_id, year, funding_type, amount),
        )
        return

    old_sector_id, old_year, old_funding_type, old_amount = existing
    same_key = (old_sector_id == sector_id and old_year == year and old_funding_type == funding_type)

    if same_key:
        delta = amount - old_amount
        upsert_funding(
            cursor, source_id=source_id, country_id=country_id, sector_id=sector_id,
            year=year, funding_type=funding_type, delta=delta, collection_date=collection_date,
            original_amount=original_amount, original_currency=original_currency,
            exchange_rate=exchange_rate,
        )
    else:
        upsert_funding(
            cursor, source_id=source_id, country_id=country_id, sector_id=old_sector_id,
            year=old_year, funding_type=old_funding_type, delta=-old_amount,
            collection_date=collection_date,
        )
        upsert_funding(
            cursor, source_id=source_id, country_id=country_id, sector_id=sector_id,
            year=year, funding_type=funding_type, delta=amount, collection_date=collection_date,
            original_amount=original_amount, original_currency=original_currency,
            exchange_rate=exchange_rate,
        )

    cursor.execute(
        """
        UPDATE funding_project_contribution
        SET sector_id = %s, year = %s, funding_type = %s, amount = %s, updated_at = now()
        WHERE source_id = %s AND project_id = %s AND country_id = %s
        """,
        (sector_id, year, funding_type, amount, source_id, project_id, country_id),
    )


def process_message(cursor, message: dict[str, Any]) -> tuple[bool, str | None]:
    """Applies source-specific sector mapping and, on success, applies the
    project's contribution idempotently. Returns (accepted, reason) -
    `reason` is None when accepted, or a short machine-readable string
    explaining rejection when not.
    """
    source = message["source"]
    if source == "world_bank":
        nev_sector = map_to_nev_sector(message["raw_sectors"], message["raw_theme"])
        ensure_source = ensure_world_bank_source
        contribution_project_id = message["project_id"]
    elif source == "gcf":
        nev_sector = map_gcf_sector(message["raw_sector_codes"], message["raw_sector_percentages"])
        ensure_source = ensure_gcf_source
        contribution_project_id = message["project_id"]
    elif source == "afdb":
        nev_sector = map_afdb_sector(message["raw_sector_codes"])
        ensure_source = ensure_afdb_source
        contribution_project_id = message["project_id"]
    elif source == "opec_fund_pdf":
        if message["climate_dimension"] == "adaptation":
            nev_sector = "Adaptation"
        else:
            nev_sector = map_opec_sector(message["sector_label_raw"], message["project_name"])
        ensure_source = ensure_opec_fund_source
        # A single OPEC Fund table row can produce two payloads (adaptation +
        # mitigation, decision 7 of the B1.5 spec) sharing the same
        # message["project_id"] - the dimension must be folded in here so
        # each is tracked as its own contribution, not one overwriting the
        # other.
        contribution_project_id = f"{message['project_id']}:{message['climate_dimension']}"
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
    apply_project_contribution(
        cursor,
        source_id=source_id,
        project_id=contribution_project_id,
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
        except Exception as exc:
            # A malformed/unexpected message must never crash this permanent
            # service - see the 2026-08-31 B1.6 closure spec. psycopg2's
            # `with connection:` above already rolled back any partial
            # transaction before this exception reached here.
            accepted, reason = False, f"processing_error:{type(exc).__name__}"
            print(f"[funding-validator] unexpected error processing message: {exc!r}")
        finally:
            connection.close()

        if accepted:
            producer.send("nev.funding.valides", message)
        else:
            producer.send("nev.funding.rejets", {**message, "rejection_reason": reason})

    producer.flush()


if __name__ == "__main__":
    run()
