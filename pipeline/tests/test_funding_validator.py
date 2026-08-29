"""Integration tests for the funding-validator processor's DB logic -
runs against the real TimescaleDB service (same instance the Symfony
backend uses), wrapped in a transaction rolled back in teardown so the
suite stays re-runnable, matching the pattern already established in
backend/tests/Integration/ for the PHP side. Requires the demo fixtures
loaded (Senegal + the 5 sectors must exist).
"""
import os
from decimal import Decimal

import psycopg2
import pytest

from pipeline.processors.funding_validator import process_message


@pytest.fixture()
def db_cursor():
    connection = psycopg2.connect(os.environ["PIPELINE_DATABASE_URL"])
    connection.autocommit = False
    cursor = connection.cursor()
    yield cursor
    connection.rollback()
    cursor.close()
    connection.close()


def _funding_row(cursor, source_id, country_id, sector_id, year, funding_type):
    cursor.execute(
        """
        SELECT amount, is_current FROM funding
        WHERE source_id = %s AND country_id = %s AND sector_id = %s
          AND year = %s AND funding_type = %s AND is_current = true
        """,
        (source_id, country_id, sector_id, year, funding_type),
    )
    return cursor.fetchone()


def _sample_message(amount_usd: int) -> dict:
    return {
        "source": "world_bank",
        "project_id": "P-TEST",
        "country_iso": "SEN",
        "year": 2026,
        "amount_usd": amount_usd,
        "funding_type": "multilateral",
        "raw_sectors": ["Energy Generation - Solar"],
        "raw_theme": [],
        "board_approval_date": "2026-01-15",
        "collected_at": "2026-08-26T00:00:00Z",
    }


def test_first_message_inserts_a_new_funding_row(db_cursor):
    accepted, reason = process_message(db_cursor, _sample_message(1_000_000))

    assert accepted is True
    assert reason is None

    db_cursor.execute("SELECT id FROM source WHERE name = 'World Bank Data API'")
    source_id = db_cursor.fetchone()[0]
    db_cursor.execute("SELECT id FROM country WHERE iso_code = 'SEN'")
    country_id = db_cursor.fetchone()[0]
    db_cursor.execute("SELECT id FROM sector WHERE name = 'Renewable Energy'")
    sector_id = db_cursor.fetchone()[0]

    row = _funding_row(db_cursor, source_id, country_id, sector_id, 2026, "multilateral")
    assert row == (Decimal("1000000.00"), True)


def test_second_message_same_key_sums_and_historizes(db_cursor):
    process_message(db_cursor, _sample_message(1_000_000))
    process_message(db_cursor, _sample_message(500_000))

    db_cursor.execute("SELECT id FROM source WHERE name = 'World Bank Data API'")
    source_id = db_cursor.fetchone()[0]
    db_cursor.execute("SELECT id FROM country WHERE iso_code = 'SEN'")
    country_id = db_cursor.fetchone()[0]
    db_cursor.execute("SELECT id FROM sector WHERE name = 'Renewable Energy'")
    sector_id = db_cursor.fetchone()[0]

    current_row = _funding_row(db_cursor, source_id, country_id, sector_id, 2026, "multilateral")
    assert current_row == (Decimal("1500000.00"), True)

    db_cursor.execute(
        """
        SELECT count(*) FROM funding
        WHERE source_id = %s AND country_id = %s AND sector_id = %s
          AND year = %s AND funding_type = %s AND is_current = false
        """,
        (source_id, country_id, sector_id, 2026, "multilateral"),
    )
    assert db_cursor.fetchone()[0] == 1


def test_unclassifiable_sector_is_rejected_without_writing(db_cursor):
    message = _sample_message(1_000_000)
    message["raw_sectors"] = ["Health"]
    message["raw_theme"] = ["Social Protection"]

    accepted, reason = process_message(db_cursor, message)

    assert accepted is False
    assert reason == "unclassifiable_sector"


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


def _afdb_sample_message(amount_usd: int, original_amount: float = None, exchange_rate: float = None) -> dict:
    return {
        "source": "afdb",
        "project_id": "46002-P-TEST-001",
        "country_iso": "SEN",
        "year": 2026,
        "amount_usd": amount_usd,
        "original_amount": original_amount if original_amount is not None else amount_usd / 1.5,
        "original_currency": "XDR",
        "exchange_rate": exchange_rate if exchange_rate is not None else 1.5,
        "funding_type": "multilateral",
        "raw_sector_codes": ["31110"],
        "board_approval_date": "2026-01-15",
        "collected_at": "2026-08-28T00:00:00Z",
    }


def test_afdb_message_inserts_a_new_funding_row_with_currency_provenance(db_cursor):
    accepted, reason = process_message(db_cursor, _afdb_sample_message(3_000_000, original_amount=2_000_000.0, exchange_rate=1.5))

    assert accepted is True
    assert reason is None

    db_cursor.execute("SELECT id FROM source WHERE name = 'African Development Bank Group — IATI Datastore'")
    source_id = db_cursor.fetchone()[0]
    db_cursor.execute("SELECT id FROM country WHERE iso_code = 'SEN'")
    country_id = db_cursor.fetchone()[0]
    db_cursor.execute("SELECT id FROM sector WHERE name = 'Agriculture'")
    sector_id = db_cursor.fetchone()[0]

    row = _funding_row(db_cursor, source_id, country_id, sector_id, 2026, "multilateral")
    assert row == (Decimal("3000000.00"), True)

    # original_amount/original_currency/exchange_rate: never populated by
    # any earlier connector (World Bank/GCF are always-USD) - this is the
    # first real test of this path. Scoped to the exact same dedup key as
    # _funding_row above, not just source_id - the dev database this runs
    # against also carries thousands of real AfDB rows from Task 5's
    # end-to-end DAG runs, so an unscoped "any current row for this
    # source" query can return an unrelated one (hit this for real: got
    # back a real row's original_amount instead of this test's own).
    db_cursor.execute(
        """
        SELECT original_amount, original_currency, exchange_rate FROM funding
        WHERE source_id = %s AND country_id = %s AND sector_id = %s
          AND year = %s AND funding_type = %s AND is_current = true
        """,
        (source_id, country_id, sector_id, 2026, "multilateral"),
    )
    original_amount, original_currency, exchange_rate = db_cursor.fetchone()
    assert original_amount == Decimal("2000000.00")
    assert original_currency == "XDR"
    assert exchange_rate == Decimal("1.500000")


def test_world_bank_and_gcf_rows_still_have_null_currency_provenance(db_cursor):
    # Regression check: extending upsert_funding with new optional
    # parameters must not affect the two existing connectors, which never
    # pass them.
    process_message(db_cursor, _sample_message(1_000_000))

    db_cursor.execute("SELECT id FROM source WHERE name = 'World Bank Data API'")
    source_id = db_cursor.fetchone()[0]
    db_cursor.execute(
        "SELECT original_amount, original_currency, exchange_rate FROM funding WHERE source_id = %s AND is_current = true",
        (source_id,),
    )
    original_amount, original_currency, exchange_rate = db_cursor.fetchone()
    assert original_amount is None
    assert original_currency is None
    assert exchange_rate is None


def test_afdb_unclassifiable_sector_is_rejected_without_writing(db_cursor):
    message = _afdb_sample_message(1_000_000)
    message["raw_sector_codes"] = ["51010"]  # General budget support - not a NEV sector

    accepted, reason = process_message(db_cursor, message)

    assert accepted is False
    assert reason == "unclassifiable_sector"


def test_all_three_sources_stay_separate_for_the_same_dedup_key(db_cursor):
    # Same country/year/type across all three (sector doesn't even need to
    # match - source_id alone is enough to keep the dedup key distinct).
    process_message(db_cursor, _sample_message(1_000_000))       # world_bank, Renewable Energy
    process_message(db_cursor, _gcf_sample_message(2_000_000))   # gcf, Renewable Energy
    process_message(db_cursor, _afdb_sample_message(3_000_000))  # afdb, Agriculture

    # Scoped to exactly these three sources' ids - the dev database this
    # runs against also carries thousands of real rows from earlier B1.1/
    # B1.2 end-to-end DAG runs (real Senegal/2026 World Bank and GCF
    # financing among them), so an unscoped count over country+year alone
    # would pick those up too and never equal a small, precise number.
    db_cursor.execute(
        """
        SELECT count(*) FROM funding
        WHERE is_current = true AND year = 2026
          AND country_id = (SELECT id FROM country WHERE iso_code = 'SEN')
          AND source_id IN (
              SELECT id FROM source WHERE name IN (
                  'World Bank Data API',
                  'Green Climate Fund — IATI Datastore',
                  'African Development Bank Group — IATI Datastore'
              )
          )
          AND amount IN (1000000.00, 2000000.00, 3000000.00)
        """,
    )
    assert db_cursor.fetchone()[0] == 3
