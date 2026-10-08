"""Consumes `nev.funding.raw`, applies the B1.1 sector-mapping rule, and
writes to the `funding` table directly (bypassing the Symfony backend
entirely - see architecture spec decision 3). Publishes each record to
`nev.funding.valides` or `nev.funding.rejets` depending on outcome.

Long-running service - see the `funding-validator` entry in
docker-compose.yml.
"""
from __future__ import annotations

import time
from decimal import Decimal
from typing import Any

from pipeline.common.db import get_connection
from pipeline.common.fx_rates import fetch_usd_to_currency_rates
from pipeline.common.kafka_client import make_consumer, make_producer
from pipeline.processors.sector_mapping import map_to_nev_sector
from pipeline.processors.sector_mapping_gcf import map_gcf_sector
from pipeline.processors.sector_mapping_afdb import map_afdb_sector
from pipeline.processors.sector_mapping_opec import map_opec_sector
from pipeline.processors.sector_mapping_proparco import map_proparco_sector

# Sources that report a real, direct-from-institution non-USD amount
# themselves (currently only AfDB, XDR) never go through the live-rate
# conversion below - their message already carries the real figure, and
# overwriting it with a derived one would replace a sourced number with a
# less faithful one.
SOURCES_WITHOUT_NATIVE_ORIGINAL_AMOUNT = frozenset({"world_bank", "gcf", "opec_fund_pdf", "dfc", "norfund", "oecd_crs"})

# In-memory cache for the long-running consumer (unlike the one-shot DAGs,
# this process can stay up for weeks - fetching on every message would be
# wasteful/slow, but a single fetch at startup would silently go stale).
_rates_cache: dict[str, Any] = {"rates": None, "fetched_at": 0.0}
RATES_TTL_SECONDS = 24 * 3600


def get_current_usd_rates() -> dict[str, float]:
    """Returns the cached USD-pivot rate table, refreshing it if empty or
    older than RATES_TTL_SECONDS. A fetch failure propagates - the caller
    (process_message) already runs inside run()'s per-message try/except,
    so one bad fetch quarantines that message rather than crashing the
    service; it does not fall back to a stale/guessed rate.
    """
    age = time.monotonic() - _rates_cache["fetched_at"]
    if _rates_cache["rates"] is None or age > RATES_TTL_SECONDS:
        _rates_cache["rates"] = fetch_usd_to_currency_rates()
        _rates_cache["fetched_at"] = time.monotonic()
    return _rates_cache["rates"]

WORLD_BANK_SOURCE_NAME = "World Bank Data API"  # matches backend/src/DataFixtures/SourceFixtures.php - reuses that row instead of creating a duplicate
GCF_SOURCE_NAME = "Green Climate Fund - IATI Datastore"  # matches backend/src/DataFixtures/SourceFixtures.php - a NEW row, not the existing PDF-typed one (see B1.2 plan Task 3, Step 1)
AFDB_SOURCE_NAME = "African Development Bank Group - IATI Datastore"  # matches backend/src/DataFixtures/SourceFixtures.php
OPEC_FUND_SOURCE_NAME = "OPEC Fund - Climate Finance Report (PDF, Gemini-assisted)"  # matches backend/src/DataFixtures/SourceFixtures.php
DFC_SOURCE_NAME = "U.S. DFC - Annual Project Data (Excel)"  # matches backend/src/DataFixtures/SourceFixtures.php
NORFUND_SOURCE_NAME = "Norfund - Investment Overview (Excel)"  # matches backend/src/DataFixtures/SourceFixtures.php
PROPARCO_SOURCE_NAME = "Proparco - Open Data AFD"  # matches backend/src/DataFixtures/SourceFixtures.php
OECD_CRS_SOURCE_NAME = "OECD CRS - Private Development Finance"  # matches backend/src/DataFixtures/SourceFixtures.php


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


def ensure_dfc_source(cursor) -> int:
    """Idempotently ensures the DFC (Annual Project Data) row exists in
    `source`, and returns its id.
    """
    cursor.execute(
        """
        INSERT INTO source (name, type, reliability)
        VALUES (%s, 'official_api', 'high')
        ON CONFLICT (name) DO NOTHING
        """,
        (DFC_SOURCE_NAME,),
    )
    cursor.execute("SELECT id FROM source WHERE name = %s", (DFC_SOURCE_NAME,))
    return cursor.fetchone()[0]


def ensure_norfund_source(cursor) -> int:
    """Idempotently ensures the Norfund (Investment Overview) row exists
    in `source`, and returns its id.
    """
    cursor.execute(
        """
        INSERT INTO source (name, type, reliability)
        VALUES (%s, 'official_api', 'high')
        ON CONFLICT (name) DO NOTHING
        """,
        (NORFUND_SOURCE_NAME,),
    )
    cursor.execute("SELECT id FROM source WHERE name = %s", (NORFUND_SOURCE_NAME,))
    return cursor.fetchone()[0]


def ensure_proparco_source(cursor) -> int:
    """Idempotently ensures the Proparco (Open Data AFD) row exists in
    `source`, and returns its id.
    """
    cursor.execute(
        """
        INSERT INTO source (name, type, reliability)
        VALUES (%s, 'official_api', 'high')
        ON CONFLICT (name) DO NOTHING
        """,
        (PROPARCO_SOURCE_NAME,),
    )
    cursor.execute("SELECT id FROM source WHERE name = %s", (PROPARCO_SOURCE_NAME,))
    return cursor.fetchone()[0]


def ensure_oecd_crs_source(cursor) -> int:
    """Idempotently ensures the OECD CRS (Private Development Finance)
    row exists in `source`, and returns its id.
    """
    cursor.execute(
        """
        INSERT INTO source (name, type, reliability)
        VALUES (%s, 'official_api', 'high')
        ON CONFLICT (name) DO NOTHING
        """,
        (OECD_CRS_SOURCE_NAME,),
    )
    cursor.execute("SELECT id FROM source WHERE name = %s", (OECD_CRS_SOURCE_NAME,))
    return cursor.fetchone()[0]


def lookup_country_id(cursor, country_iso: str) -> int | None:
    cursor.execute("SELECT id FROM country WHERE iso_code = %s", (country_iso,))
    row = cursor.fetchone()
    return row[0] if row else None


def lookup_sector_id(cursor, sector_name: str) -> int | None:
    cursor.execute("SELECT id FROM sector WHERE name = %s", (sector_name,))
    row = cursor.fetchone()
    return row[0] if row else None


def lookup_country_currency(cursor, country_id: int) -> str | None:
    cursor.execute("SELECT currency FROM country WHERE id = %s", (country_id,))
    row = cursor.fetchone()
    return row[0] if row else None


def upsert_funding(cursor, *, source_id: int, country_id: int, sector_id: int, year: int,
                    funding_type: str, delta: Decimal, collection_date: str,
                    original_amount: Decimal | None = None,
                    original_currency: str | None = None,
                    exchange_rate: Decimal | None = None,
                    instrument: str = "unknown") -> None:
    """Applies `delta` (can be negative - see apply_project_contribution's
    sector/year-change case) to the current row for this dedup key,
    historizing the previous version, or inserts a fresh row if none
    exists yet. A zero delta never historizes a needless new version (see
    the 2026-08-31 idempotency fix spec - that was the exact shape of the
    real double-counting bug), but the current row's own provenance
    metadata (`collection_date`/`original_amount`/`original_currency`/
    `exchange_rate`/`instrument`) is still refreshed in place: found live
    (2026-09-15) re-running collecte_gcf/collecte_afdb right after fixing
    a bug in how `instrument` was computed - GCF/AfDB republish their
    entire portfolio every run, so almost every project's *amount* delta
    was genuinely 0, and the original "delta == 0 -> return immediately"
    short-circuit silently froze `instrument` (and the other provenance
    fields) at whatever the very first message for that dedup key
    happened to carry, forever - not just skipping a needless historized
    version, but skipping the metadata refresh entirely, even on an
    already-current row. The financial *amount* truth hasn't changed when
    delta is 0, so there is still no need for a new SCD2 version - this
    only ever runs a plain UPDATE on the existing current row, never an
    INSERT. `original_amount`/`original_currency`/`exchange_rate` describe
    the latest contributing message's raw figures (not accumulated across
    historized versions) - only populated by connectors reporting in a
    non-pivot currency (B1.3's AfDB connector is the first; World Bank/GCF
    never pass them, so they stay NULL as before). `instrument` (F1) gets
    the exact same treatment: it reflects only the latest contributing
    message, not a value merged across every project aggregated into this
    dedup key - a dedup key is not currently split by instrument (unlike
    funding_type, which already was one), so two projects with different
    instruments sharing the same key will show whichever one wrote most
    recently.
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

    if delta == 0:
        if existing is not None:
            existing_id, _ = existing
            cursor.execute(
                """
                UPDATE funding
                SET collection_date = %s, original_amount = %s, original_currency = %s,
                    exchange_rate = %s, instrument = %s, updated_at = now()
                WHERE id = %s
                """,
                (collection_date, original_amount, original_currency, exchange_rate, instrument, existing_id),
            )
        return

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
            original_amount, original_currency, exchange_rate, instrument,
            created_at, updated_at
        ) VALUES (
            %s, %s, %s, %s, %s, %s,
            %s, 'validated', now(), true,
            %s, %s, %s, %s,
            now(), now()
        )
        """,
        (country_id, sector_id, year, new_amount, funding_type, source_id, collection_date,
         original_amount, original_currency, exchange_rate, instrument),
    )


def apply_project_contribution(cursor, *, source_id: int, project_id: str, country_id: int,
                                sector_id: int, year: int, funding_type: str, amount: Decimal,
                                collection_date: str,
                                original_amount: Decimal | None = None,
                                original_currency: str | None = None,
                                exchange_rate: Decimal | None = None,
                                instrument: str = "unknown") -> None:
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
            exchange_rate=exchange_rate, instrument=instrument,
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
            exchange_rate=exchange_rate, instrument=instrument,
        )
    else:
        # Closing out the old key: `instrument` isn't tracked per
        # contribution in `funding_project_contribution` (same simplification
        # as original_amount/original_currency/exchange_rate just above),
        # so this call has no real instrument to preserve for the old
        # bucket - left at the "unknown" default rather than guessing.
        upsert_funding(
            cursor, source_id=source_id, country_id=country_id, sector_id=old_sector_id,
            year=old_year, funding_type=old_funding_type, delta=-old_amount,
            collection_date=collection_date,
        )
        upsert_funding(
            cursor, source_id=source_id, country_id=country_id, sector_id=sector_id,
            year=year, funding_type=funding_type, delta=amount, collection_date=collection_date,
            original_amount=original_amount, original_currency=original_currency,
            exchange_rate=exchange_rate, instrument=instrument,
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
    elif source == "dfc":
        # Toujours "Renewable Energy" - jamais une table de correspondance
        # (contrairement a map_opec_sector()) : le filtre du connecteur
        # lui-meme (pipeline/collectors/dfc.py::parse_project(), secteur
        # NAICS "Utilities" + mot-cle solaire/eolien/geothermie/hydro dans
        # la description reelle) garantit deja que chaque message publie
        # ici est un projet d'energie renouvelable - meme raisonnement que
        # la branche "adaptation" d'OPEC Fund ci-dessus.
        nev_sector = "Renewable Energy"
        ensure_source = ensure_dfc_source
        contribution_project_id = message["project_id"]
    elif source == "norfund":
        # Contrairement a DFC, la categorie climat de Norfund ("Mitigation")
        # couvre aussi des banques/fonds/industries qui ne sont elles-memes
        # aucun des 5 secteurs NEV (verifie en direct 2026-10-07, voir
        # pipeline/collectors/norfund.py) - une vraie classification par
        # secteur reel (Secteur/Sous-secteur Norfund), reutilisant la meme
        # fonction generique que World Bank, est donc necessaire ici.
        nev_sector = map_to_nev_sector(message["raw_sectors"], message["raw_theme"])
        ensure_source = ensure_norfund_source
        contribution_project_id = message["project_id"]
    elif source == "proparco":
        nev_sector = map_proparco_sector(message["sector_label_raw"])
        ensure_source = ensure_proparco_source
        contribution_project_id = message["project_id"]
    elif source == "oecd_crs":
        # Reutilise map_afdb_sector() telle quelle - OCDE CRS et AfDB/IATI
        # partagent le meme systeme de codes secteur DAC 5 chiffres,
        # verifie en direct (2026-10-07). Repli sur le Marqueur de Rio
        # adaptation officiel (jamais devine) quand le code secteur seul
        # ne correspond a aucun des 4 autres secteurs NEV - meme principe
        # que la branche "world_bank" (secteur d'abord, theme en repli),
        # applique ici a un signal structure plutot qu'a du texte libre.
        nev_sector = map_afdb_sector(message["raw_sector_codes"])
        if nev_sector is None and message.get("climate_adaptation_rio_marker"):
            nev_sector = "Adaptation"
        ensure_source = ensure_oecd_crs_source
        contribution_project_id = message["project_id"]
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

    # AfDB messages carry a real, institution-reported original_amount/
    # original_currency/exchange_rate (floats, from JSON) - convert via
    # str() to avoid binary float artifacts in the Decimal conversion (e.g.
    # Decimal(1.370818) != 1.370818). World Bank/GCF/OPEC Fund report
    # directly in USD with no native non-USD figure to preserve, so their
    # "devise locale" column is instead a real, live-rate USD -> country
    # currency conversion (never the AfricanCurrencies illustrative table,
    # which is reserved for the demo dataset) - see fx_rates.py. A missing
    # country currency or rate leaves all three fields None rather than
    # guessing, exactly like AfDB's own existing null-safety.
    original_amount: Decimal | None
    original_currency: str | None
    exchange_rate: Decimal | None
    if "original_amount" in message:
        original_amount = Decimal(str(message["original_amount"]))
        original_currency = message.get("original_currency")
        exchange_rate = Decimal(str(message["exchange_rate"])) if "exchange_rate" in message else None
    elif source in SOURCES_WITHOUT_NATIVE_ORIGINAL_AMOUNT:
        original_amount, original_currency, exchange_rate = None, None, None
        local_currency = lookup_country_currency(cursor, country_id)
        if local_currency:
            try:
                rates = get_current_usd_rates()
            except Exception as exc:
                # Audit post-C3 2026-10-01 : echec silencieux auparavant -
                # une panne prolongee de l'API de taux de change degradait
                # silencieusement original_amount/original_currency/
                # exchange_rate (laisses a None, indiscernables d'un pays
                # "sans devise connue") pendant des mois sans aucun signal.
                # print() plutot que `logging` : meme convention que le
                # catch-all de run() plus bas dans ce fichier (capture par
                # les logs de tache Airflow).
                print(f"[funding-validator] failed to fetch USD exchange rates: {exc!r}")
                rates = {}
            rate = rates.get(local_currency)
            if rate:
                original_amount = Decimal(str(message["amount_usd"])) * Decimal(str(rate))
                original_currency = local_currency
                exchange_rate = Decimal("1") / Decimal(str(rate))
    else:
        original_amount, original_currency, exchange_rate = None, None, None

    # F1: only the IATI sources (gcf, afdb) currently carry this field
    # (see pipeline/collectors/gcf.py and afdb.py) - world_bank and
    # opec_fund_pdf messages have no instrument signal at all and always
    # fall back to "unknown", matching the column's own DB default.
    instrument = message.get("instrument", "unknown")

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
        original_amount=original_amount,
        original_currency=original_currency,
        exchange_rate=exchange_rate,
        instrument=instrument,
    )
    return True, None


def _message_key(message: dict[str, Any]) -> str:
    """Partitioning key matching funding_project_contribution's own unique
    constraint (source_id, project_id, country_id) - see kafka_client.py's
    make_producer() docstring for why this matters (audit post-C3
    2026-10-01). Every funding message carries these three raw fields
    regardless of source (see process_message() above).
    """
    return f"{message['source']}:{message['project_id']}:{message['country_iso']}"


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

        # Audit post-C3 2026-10-01 : commit explicite une fois la donnee
        # reellement actee en base (jamais avant, voir kafka_client.py) -
        # si le processus crashe apres ce point, Kafka ne re-livrera plus
        # ce message ; s'il crashe avant, il sera re-livre et retraite
        # (sans danger, apply_project_contribution() est idempotent : une
        # meme contribution rejouee produit un delta nul).
        consumer.commit()

        dedup_key = _message_key(message)
        if accepted:
            producer.send("nev.funding.valides", message, key=dedup_key)
        else:
            producer.send("nev.funding.rejets", {**message, "rejection_reason": reason}, key=dedup_key)

    producer.flush()


if __name__ == "__main__":
    run()
