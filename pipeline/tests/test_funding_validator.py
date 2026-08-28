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
