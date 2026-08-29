"""Integration tests for the emission-validator processor's DB logic -
runs against the real TimescaleDB service (same instance the Symfony
backend uses), wrapped in a transaction rolled back in teardown so the
suite stays re-runnable - same pattern as test_funding_validator.py.
Requires the demo fixtures loaded (Senegal + the PNUE source must exist).
"""
import os
from decimal import Decimal

import psycopg2
import pytest

from pipeline.processors.emission_validator import process_message

PNUE_SOURCE_NAME = "UN SDG Global Database — Indicator 9.4.1 (IEA)"


@pytest.fixture()
def db_cursor():
    connection = psycopg2.connect(os.environ["PIPELINE_DATABASE_URL"])
    connection.autocommit = False
    cursor = connection.cursor()
    yield cursor
    connection.rollback()
    cursor.close()
    connection.close()


def _emission_row(cursor, source_id, country_id, year):
    cursor.execute(
        """
        SELECT value_mt, is_current FROM emission
        WHERE source_id = %s AND country_id = %s AND year = %s AND is_current = true
        """,
        (source_id, country_id, year),
    )
    return cursor.fetchone()


def _pnue_message(value_mt: float, year: int = 2026) -> dict:
    return {
        "source": "pnue",
        "country_iso": "SEN",
        "year": year,
        "value_mt": value_mt,
        "collected_at": "2026-08-29T00:00:00Z",
    }


def test_first_message_inserts_a_new_emission_row(db_cursor):
    accepted, reason = process_message(db_cursor, _pnue_message(12.4))

    assert accepted is True
    assert reason is None

    db_cursor.execute("SELECT id FROM source WHERE name = %s", (PNUE_SOURCE_NAME,))
    source_id = db_cursor.fetchone()[0]
    db_cursor.execute("SELECT id FROM country WHERE iso_code = 'SEN'")
    country_id = db_cursor.fetchone()[0]

    row = _emission_row(db_cursor, source_id, country_id, 2026)
    assert row == (Decimal("12.400"), True)


def test_second_message_same_key_replaces_not_sums(db_cursor):
    process_message(db_cursor, _pnue_message(12.4))
    process_message(db_cursor, _pnue_message(13.1))  # a revised estimate for the same year

    db_cursor.execute("SELECT id FROM source WHERE name = %s", (PNUE_SOURCE_NAME,))
    source_id = db_cursor.fetchone()[0]
    db_cursor.execute("SELECT id FROM country WHERE iso_code = 'SEN'")
    country_id = db_cursor.fetchone()[0]

    current_row = _emission_row(db_cursor, source_id, country_id, 2026)
    # 13.1, not 12.4 + 13.1 = 25.5 - the defining behavioral difference
    # from upsert_funding() (B1.4 spec decision 6).
    assert current_row == (Decimal("13.100"), True)

    db_cursor.execute(
        """
        SELECT count(*) FROM emission
        WHERE source_id = %s AND country_id = %s AND year = 2026 AND is_current = false
        """,
        (source_id, country_id),
    )
    assert db_cursor.fetchone()[0] == 1  # the 12.4 estimate, historized


def test_unknown_country_is_rejected_without_writing(db_cursor):
    message = _pnue_message(12.4)
    message["country_iso"] = "ZZZ"

    accepted, reason = process_message(db_cursor, message)

    assert accepted is False
    assert reason == "unknown_country"


def test_unrecognized_source_is_rejected():
    message = _pnue_message(12.4)
    message["source"] = "something_else"

    # No DB touched - dispatch fails before any query, so cursor is never used.
    accepted, reason = process_message(None, message)

    assert accepted is False
    assert reason == "unknown_source"


def test_pnue_source_row_is_created_idempotently(db_cursor):
    process_message(db_cursor, _pnue_message(12.4))
    process_message(db_cursor, _pnue_message(13.1, year=2020))

    db_cursor.execute("SELECT count(*) FROM source WHERE name = %s", (PNUE_SOURCE_NAME,))
    assert db_cursor.fetchone()[0] == 1  # not duplicated across two messages
