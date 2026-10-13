"""Integration tests for the funding-validator processor's DB logic -
runs against the real TimescaleDB service (same instance the Symfony
backend uses), wrapped in a transaction rolled back in teardown so the
suite stays re-runnable, matching the pattern already established in
backend/tests/Integration/ for the PHP side. Requires the demo fixtures
loaded (Senegal + the 5 sectors must exist).
"""
import os
from decimal import Decimal
from unittest.mock import patch

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


def _funding_instrument(cursor, source_id, country_id, sector_id, year, funding_type):
    cursor.execute(
        """
        SELECT instrument FROM funding
        WHERE source_id = %s AND country_id = %s AND sector_id = %s
          AND year = %s AND funding_type = %s AND is_current = true
        """,
        (source_id, country_id, sector_id, year, funding_type),
    )
    row = cursor.fetchone()
    return row[0] if row else None


def _funding_external_reference(cursor, source_id, country_id, sector_id, year, funding_type):
    cursor.execute(
        """
        SELECT external_reference FROM funding
        WHERE source_id = %s AND country_id = %s AND sector_id = %s
          AND year = %s AND funding_type = %s AND is_current = true
        """,
        (source_id, country_id, sector_id, year, funding_type),
    )
    row = cursor.fetchone()
    return row[0] if row else None


def _sample_message(amount_usd: int, project_id: str = "P-TEST") -> dict:
    return {
        "source": "world_bank",
        "project_id": project_id,
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


def test_world_bank_message_local_currency_equals_original_currency(db_cursor):
    """Audit 2026-10-13 : World Bank n'a pas de montant natif propre (voir
    SOURCES_WITHOUT_NATIVE_ORIGINAL_AMOUNT), donc original_amount/
    original_currency etaient DEJA la conversion vers la devise du pays -
    local_amount/local_currency doivent donc etre strictement identiques,
    jamais recalcules une seconde fois avec un resultat different.
    """
    accepted, reason = process_message(db_cursor, _sample_message(1_000_000))
    assert accepted is True

    db_cursor.execute("SELECT id FROM source WHERE name = 'World Bank Data API'")
    source_id = db_cursor.fetchone()[0]
    db_cursor.execute("SELECT id FROM country WHERE iso_code = 'SEN'")
    country_id = db_cursor.fetchone()[0]
    db_cursor.execute("SELECT id FROM sector WHERE name = 'Renewable Energy'")
    sector_id = db_cursor.fetchone()[0]

    db_cursor.execute(
        """
        SELECT original_amount, original_currency, local_amount, local_currency FROM funding
        WHERE source_id = %s AND country_id = %s AND sector_id = %s
          AND year = %s AND funding_type = %s AND is_current = true
        """,
        (source_id, country_id, sector_id, 2026, "multilateral"),
    )
    original_amount, original_currency, local_amount, local_currency = db_cursor.fetchone()
    assert local_amount == original_amount
    assert local_currency == original_currency


def test_two_different_projects_same_dedup_key_sum_and_historize(db_cursor):
    process_message(db_cursor, _sample_message(1_000_000, project_id="P-TEST-1"))
    process_message(db_cursor, _sample_message(500_000, project_id="P-TEST-2"))

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


def test_project_id_is_persisted_as_external_reference(db_cursor):
    """Audit Prompt Maitre #3 (point 5, 2026-10-11): project_id was already
    produced by every connector and already persisted in
    funding_project_contribution, but never reported onto `funding` itself -
    making it impossible to manually verify a suspected cross-source
    duplicate (same country/sector/year/amount reported by two different
    sources) without digging into each source's raw data by hand.
    """
    process_message(db_cursor, _sample_message(1_000_000, project_id="P178523"))

    db_cursor.execute("SELECT id FROM source WHERE name = 'World Bank Data API'")
    source_id = db_cursor.fetchone()[0]
    db_cursor.execute("SELECT id FROM country WHERE iso_code = 'SEN'")
    country_id = db_cursor.fetchone()[0]
    db_cursor.execute("SELECT id FROM sector WHERE name = 'Renewable Energy'")
    sector_id = db_cursor.fetchone()[0]

    assert _funding_external_reference(db_cursor, source_id, country_id, sector_id, 2026, "multilateral") == "P178523"


def test_external_reference_survives_a_same_project_delta_zero_republish(db_cursor):
    """Same idempotency-refresh scenario already covered by
    test_instrument_is_refreshed_even_when_the_republished_amount_is_unchanged
    below (GCF/AfDB republish their entire portfolio every run, so the same
    project comes back with the exact same amount - delta 0, a plain UPDATE
    on the existing row, never an INSERT): external_reference is one of
    upsert_funding's provenance fields (see its own docblock) and must
    survive that UPDATE-only path, not be left NULL because no INSERT ran.
    """
    process_message(db_cursor, _sample_message(1_000_000, project_id="P178523"))
    process_message(db_cursor, _sample_message(1_000_000, project_id="P178523"))  # same project, same amount -> delta 0

    db_cursor.execute("SELECT id FROM source WHERE name = 'World Bank Data API'")
    source_id = db_cursor.fetchone()[0]
    db_cursor.execute("SELECT id FROM country WHERE iso_code = 'SEN'")
    country_id = db_cursor.fetchone()[0]
    db_cursor.execute("SELECT id FROM sector WHERE name = 'Renewable Energy'")
    sector_id = db_cursor.fetchone()[0]

    assert _funding_external_reference(db_cursor, source_id, country_id, sector_id, 2026, "multilateral") == "P178523"


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

    db_cursor.execute("SELECT id FROM source WHERE name = 'Green Climate Fund - IATI Datastore'")
    source_id = db_cursor.fetchone()[0]
    db_cursor.execute("SELECT id FROM country WHERE iso_code = 'SEN'")
    country_id = db_cursor.fetchone()[0]
    db_cursor.execute("SELECT id FROM sector WHERE name = 'Renewable Energy'")
    sector_id = db_cursor.fetchone()[0]

    row = _funding_row(db_cursor, source_id, country_id, sector_id, 2026, "multilateral")
    assert row == (Decimal("2000000.00"), True)


def test_world_bank_message_without_instrument_field_defaults_to_unknown(db_cursor):
    # F1: world_bank never carries an "instrument" key (no IATI signal) -
    # process_message must default it, not KeyError.
    process_message(db_cursor, _sample_message(1_000_000))

    db_cursor.execute("SELECT id FROM source WHERE name = 'World Bank Data API'")
    source_id = db_cursor.fetchone()[0]
    db_cursor.execute("SELECT id FROM country WHERE iso_code = 'SEN'")
    country_id = db_cursor.fetchone()[0]
    db_cursor.execute("SELECT id FROM sector WHERE name = 'Renewable Energy'")
    sector_id = db_cursor.fetchone()[0]

    assert _funding_instrument(db_cursor, source_id, country_id, sector_id, 2026, "multilateral") == "unknown"


def test_gcf_message_instrument_is_persisted_on_the_funding_row(db_cursor):
    message = _gcf_sample_message(2_000_000)
    message["instrument"] = "loan_concessional"
    process_message(db_cursor, message)

    db_cursor.execute("SELECT id FROM source WHERE name = 'Green Climate Fund - IATI Datastore'")
    source_id = db_cursor.fetchone()[0]
    db_cursor.execute("SELECT id FROM country WHERE iso_code = 'SEN'")
    country_id = db_cursor.fetchone()[0]
    db_cursor.execute("SELECT id FROM sector WHERE name = 'Renewable Energy'")
    sector_id = db_cursor.fetchone()[0]

    assert _funding_instrument(db_cursor, source_id, country_id, sector_id, 2026, "multilateral") == "loan_concessional"


def test_instrument_is_refreshed_even_when_the_republished_amount_is_unchanged(db_cursor):
    """Real bug found live (2026-09-15): a DAG re-run within the same
    collection window (or after a code fix like this one's own) republishes
    the exact same amount for a project it already knows about - delta is
    then exactly 0, and the old upsert_funding() returned immediately on
    that check *before* ever touching the row, silently freezing instrument
    (and collection_date/original_amount/original_currency/exchange_rate)
    at whatever value the first-ever message happened to carry. This is
    exactly why re-triggering collecte_gcf/collecte_afdb after fixing F1's
    first_finance_type_code() bug didn't change a single row: GCF/AfDB
    republish their entire portfolio every run, so almost every project's
    delta was 0 (same amount, only the instrument field's *value* changed).
    """
    first = _gcf_sample_message(2_000_000)
    first["instrument"] = "unknown"
    process_message(db_cursor, first)

    second = _gcf_sample_message(2_000_000)  # exact same amount -> delta 0
    second["instrument"] = "loan_concessional"
    process_message(db_cursor, second)

    db_cursor.execute("SELECT id FROM source WHERE name = 'Green Climate Fund - IATI Datastore'")
    source_id = db_cursor.fetchone()[0]
    db_cursor.execute("SELECT id FROM country WHERE iso_code = 'SEN'")
    country_id = db_cursor.fetchone()[0]
    db_cursor.execute("SELECT id FROM sector WHERE name = 'Renewable Energy'")
    sector_id = db_cursor.fetchone()[0]

    assert _funding_instrument(db_cursor, source_id, country_id, sector_id, 2026, "multilateral") == "loan_concessional"

    # The refresh must be in place, not a new SCD2 version - the financial
    # amount truth genuinely didn't change, only its provenance metadata did.
    db_cursor.execute(
        """
        SELECT count(*) FROM funding
        WHERE source_id = %s AND country_id = %s AND sector_id = %s
          AND year = %s AND funding_type = %s AND is_current = false
        """,
        (source_id, country_id, sector_id, 2026, "multilateral"),
    )
    assert db_cursor.fetchone()[0] == 0


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

    db_cursor.execute("SELECT id FROM source WHERE name = 'African Development Bank Group - IATI Datastore'")
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
    # pass them natively - they only get a derived value when a live USD
    # rate is actually available (see process_message's own comment on
    # SOURCES_WITHOUT_NATIVE_ORIGINAL_AMOUNT). get_current_usd_rates() is
    # mocked out here (audit post-C3 2026-10-01: this test previously made
    # a real, unmocked call to the live open.er-api.com API - it happened
    # to pass only because that call failed in whatever environment first
    # ran it, and started failing for real as soon as it ran somewhere with
    # outbound network access, since Senegal does have a known currency and
    # the live rate conversion is a genuine, intended feature, not a bug)
    # so the "no provenance without a usable rate" case stays deterministic.
    with patch("pipeline.processors.funding_validator.get_current_usd_rates", return_value={}):
        process_message(db_cursor, _sample_message(1_000_000))

    db_cursor.execute("SELECT id FROM source WHERE name = 'World Bank Data API'")
    source_id = db_cursor.fetchone()[0]
    # Scoped to this exact dedup key (country/year/funding_type), not just
    # source_id: the dev database this runs against also carries thousands
    # of real rows from earlier B1.1/B1.2 end-to-end DAG runs, including
    # other current World Bank rows for other dedup keys under the same
    # source_id (same reasoning as
    # test_all_three_sources_stay_separate_for_the_same_dedup_key above) -
    # an unscoped fetchone() could silently return one of those instead of
    # the row this test's own process_message() call just wrote, since
    # "is_current = true" alone is not unique per source_id.
    db_cursor.execute(
        """
        SELECT original_amount, original_currency, exchange_rate FROM funding
        WHERE source_id = %s AND is_current = true AND year = 2026 AND funding_type = 'multilateral'
          AND country_id = (SELECT id FROM country WHERE iso_code = 'SEN')
        """,
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
                  'Green Climate Fund - IATI Datastore',
                  'African Development Bank Group - IATI Datastore'
              )
          )
          AND amount IN (1000000.00, 2000000.00, 3000000.00)
        """,
    )
    assert db_cursor.fetchone()[0] == 3


def _opec_sample_message(amount_usd: int, climate_dimension: str = "adaptation",
                          sector_label_raw: str = "Agriculture and Livelihoods",
                          project_name: str = "Test Project") -> dict:
    return {
        "source": "opec_fund_pdf",
        "project_id": "opec-fund-climate-finance-2024:2026:Senegal:Test Project",
        "country_iso": "SEN",
        "year": 2026,
        "amount_usd": amount_usd,
        "funding_type": "multilateral",
        "sector_label_raw": sector_label_raw,
        "project_name": project_name,
        "climate_dimension": climate_dimension,
        "document_hash": "abc123",
        "collected_at": "2026-08-29T00:00:00Z",
    }


def test_opec_adaptation_message_always_maps_to_the_adaptation_sector(db_cursor):
    # Even though the raw sector label is "Agriculture and Livelihoods"
    # (which would map to Agriculture on its own), the adaptation-dimension
    # message must land under NEV's Adaptation sector - spec decision 7.
    message = _opec_sample_message(1_000_000, climate_dimension="adaptation")

    accepted, reason = process_message(db_cursor, message)

    assert accepted is True
    assert reason is None

    db_cursor.execute("SELECT id FROM source WHERE name = 'OPEC Fund - Climate Finance Report (PDF, Gemini-assisted)'")
    source_id = db_cursor.fetchone()[0]
    db_cursor.execute("SELECT id FROM country WHERE iso_code = 'SEN'")
    country_id = db_cursor.fetchone()[0]
    db_cursor.execute("SELECT id FROM sector WHERE name = 'Adaptation'")
    sector_id = db_cursor.fetchone()[0]

    row = _funding_row(db_cursor, source_id, country_id, sector_id, 2026, "multilateral")
    assert row == (Decimal("1000000.00"), True)


def test_opec_mitigation_message_uses_the_sector_mapping_table(db_cursor):
    message = _opec_sample_message(
        2_000_000, climate_dimension="mitigation", sector_label_raw="Transport"
    )

    accepted, reason = process_message(db_cursor, message)

    assert accepted is True

    db_cursor.execute("SELECT id FROM source WHERE name = 'OPEC Fund - Climate Finance Report (PDF, Gemini-assisted)'")
    source_id = db_cursor.fetchone()[0]
    db_cursor.execute("SELECT id FROM country WHERE iso_code = 'SEN'")
    country_id = db_cursor.fetchone()[0]
    db_cursor.execute("SELECT id FROM sector WHERE name = 'Sustainable Transport'")
    sector_id = db_cursor.fetchone()[0]

    row = _funding_row(db_cursor, source_id, country_id, sector_id, 2026, "multilateral")
    assert row == (Decimal("2000000.00"), True)


def test_opec_mitigation_message_energy_label_uses_the_project_name_keyword(db_cursor):
    # Proves the project_name field actually reaches map_opec_sector through
    # the real message -> process_message -> dispatch path, not just the
    # isolated direct-call unit tests in test_sector_mapping_opec.py. A
    # real bug caught during this plan's own self-review: an earlier draft
    # hardcoded an empty string here, which would have silently quarantined
    # every real hydropower/wind/solar OPEC Fund project instead of
    # classifying it as Renewable Energy.
    message = _opec_sample_message(
        3_000_000, climate_dimension="mitigation", sector_label_raw="Energy",
        project_name="Nachtigal Hydropower Company (NHPC)",
    )

    accepted, reason = process_message(db_cursor, message)

    assert accepted is True
    assert reason is None

    db_cursor.execute("SELECT id FROM source WHERE name = 'OPEC Fund - Climate Finance Report (PDF, Gemini-assisted)'")
    source_id = db_cursor.fetchone()[0]
    db_cursor.execute("SELECT id FROM country WHERE iso_code = 'SEN'")
    country_id = db_cursor.fetchone()[0]
    db_cursor.execute("SELECT id FROM sector WHERE name = 'Renewable Energy'")
    sector_id = db_cursor.fetchone()[0]

    row = _funding_row(db_cursor, source_id, country_id, sector_id, 2026, "multilateral")
    assert row == (Decimal("3000000.00"), True)


def test_opec_mitigation_message_with_an_unmappable_sector_is_quarantined(db_cursor):
    message = _opec_sample_message(
        1_000_000, climate_dimension="mitigation", sector_label_raw="Financial Intermediation"
    )

    accepted, reason = process_message(db_cursor, message)

    assert accepted is False
    assert reason == "unclassifiable_sector"


def test_republishing_the_same_project_eight_times_does_not_inflate_the_total(db_cursor):
    # Reproduces the real bug found live in production: World Bank's DAG
    # re-publishes its entire current portfolio on every run (not a delta),
    # and the old upsert_funding summed every message blindly - Senegal/
    # Agriculture/1989 was summed 8 times in a row with the exact same
    # increment (16.1M -> 128.8M) before this fix. Re-publishing the exact
    # same project 8 times must now produce exactly one project's worth of
    # funding, not eight.
    message = _sample_message(2_012_500, project_id="P-BUG-REPRO")
    for _ in range(8):
        process_message(db_cursor, message)

    db_cursor.execute("SELECT id FROM source WHERE name = 'World Bank Data API'")
    source_id = db_cursor.fetchone()[0]
    db_cursor.execute("SELECT id FROM country WHERE iso_code = 'SEN'")
    country_id = db_cursor.fetchone()[0]
    db_cursor.execute("SELECT id FROM sector WHERE name = 'Renewable Energy'")
    sector_id = db_cursor.fetchone()[0]

    current_row = _funding_row(db_cursor, source_id, country_id, sector_id, 2026, "multilateral")
    assert current_row == (Decimal("2012500.00"), True)

    db_cursor.execute(
        """
        SELECT count(*) FROM funding
        WHERE source_id = %s AND country_id = %s AND sector_id = %s
          AND year = %s AND funding_type = %s AND is_current = false
        """,
        (source_id, country_id, sector_id, 2026, "multilateral"),
    )
    assert db_cursor.fetchone()[0] == 0  # every repeat after the first was a genuine no-op


def test_republishing_the_same_project_with_a_revised_amount_applies_only_the_delta(db_cursor):
    process_message(db_cursor, _sample_message(1_000_000, project_id="P-REVISED"))
    process_message(db_cursor, _sample_message(1_500_000, project_id="P-REVISED"))

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
    assert db_cursor.fetchone()[0] == 1  # the original 1,000,000 version, real revision kept traceable


def test_same_project_reclassified_to_a_different_sector_moves_its_contribution(db_cursor):
    message = _sample_message(1_000_000, project_id="P-RECLASSIFIED")
    process_message(db_cursor, message)  # raw_sectors -> Renewable Energy

    message["raw_sectors"] = ["Agriculture"]
    process_message(db_cursor, message)  # same project, now maps to Agriculture

    db_cursor.execute("SELECT id FROM source WHERE name = 'World Bank Data API'")
    source_id = db_cursor.fetchone()[0]
    db_cursor.execute("SELECT id FROM country WHERE iso_code = 'SEN'")
    country_id = db_cursor.fetchone()[0]
    db_cursor.execute("SELECT id FROM sector WHERE name = 'Renewable Energy'")
    old_sector_id = db_cursor.fetchone()[0]
    db_cursor.execute("SELECT id FROM sector WHERE name = 'Agriculture'")
    new_sector_id = db_cursor.fetchone()[0]

    old_row = _funding_row(db_cursor, source_id, country_id, old_sector_id, 2026, "multilateral")
    assert old_row == (Decimal("0.00"), True)

    new_row = _funding_row(db_cursor, source_id, country_id, new_sector_id, 2026, "multilateral")
    assert new_row == (Decimal("1000000.00"), True)


def test_opec_adaptation_and_mitigation_from_the_same_row_are_tracked_as_separate_contributions(db_cursor):
    # Both payloads share the same message["project_id"] (decision 7, B1.5
    # spec) - re-publishing the same document (e.g. a re-triggered DAG
    # before the cache check) must not let one dimension's contribution
    # overwrite the other's.
    adaptation = _opec_sample_message(1_000_000, climate_dimension="adaptation")
    mitigation = _opec_sample_message(2_000_000, climate_dimension="mitigation", sector_label_raw="Transport")
    process_message(db_cursor, adaptation)
    process_message(db_cursor, mitigation)
    # Re-publish both again unchanged (simulates a re-run) - neither must move.
    process_message(db_cursor, adaptation)
    process_message(db_cursor, mitigation)

    db_cursor.execute("SELECT id FROM source WHERE name = 'OPEC Fund - Climate Finance Report (PDF, Gemini-assisted)'")
    source_id = db_cursor.fetchone()[0]
    db_cursor.execute("SELECT id FROM country WHERE iso_code = 'SEN'")
    country_id = db_cursor.fetchone()[0]
    db_cursor.execute("SELECT id FROM sector WHERE name = 'Adaptation'")
    adaptation_sector_id = db_cursor.fetchone()[0]
    db_cursor.execute("SELECT id FROM sector WHERE name = 'Sustainable Transport'")
    mitigation_sector_id = db_cursor.fetchone()[0]

    adaptation_row = _funding_row(db_cursor, source_id, country_id, adaptation_sector_id, 2026, "multilateral")
    assert adaptation_row == (Decimal("1000000.00"), True)

    mitigation_row = _funding_row(db_cursor, source_id, country_id, mitigation_sector_id, 2026, "multilateral")
    assert mitigation_row == (Decimal("2000000.00"), True)


def _dfc_sample_message(amount_usd: int, project_id: str = "9000099999") -> dict:
    return {
        "source": "dfc",
        "project_id": project_id,
        "country_iso": "SEN",
        "year": 2026,
        "amount_usd": amount_usd,
        "funding_type": "private",
        "project_name": "Test Solar Project",
        "project_description": "Test solar power generation project",
        "collected_at": "2026-10-06T00:00:00Z",
    }


def test_dfc_message_inserts_a_new_funding_row_under_the_renewable_energy_sector(db_cursor):
    # 2026-10-06 : premiere source de financement PRIVE du projet - le
    # secteur est toujours "Renewable Energy" ici, jamais une table de
    # correspondance (voir le commentaire de process_message()) : le
    # connecteur lui-meme ne publie que des projets solaire/eolien/
    # geothermie/hydro.
    accepted, reason = process_message(db_cursor, _dfc_sample_message(5_000_000))

    assert accepted is True
    assert reason is None

    db_cursor.execute("SELECT id FROM source WHERE name = 'U.S. DFC - Annual Project Data (Excel)'")
    source_id = db_cursor.fetchone()[0]
    db_cursor.execute("SELECT id FROM country WHERE iso_code = 'SEN'")
    country_id = db_cursor.fetchone()[0]
    db_cursor.execute("SELECT id FROM sector WHERE name = 'Renewable Energy'")
    sector_id = db_cursor.fetchone()[0]

    row = _funding_row(db_cursor, source_id, country_id, sector_id, 2026, "private")
    assert row == (Decimal("5000000.00"), True)


def test_dfc_rows_stay_separate_from_multilateral_rows_for_the_same_dedup_key(db_cursor):
    # "private" et "multilateral" font partie de la cle de deduplication
    # (uniq_funding_dedup_key_current) - une ligne DFC privee ne doit
    # jamais ecraser/fusionner avec une ligne multilaterale du meme pays/
    # secteur/annee venue d'une autre source.
    process_message(db_cursor, _dfc_sample_message(5_000_000))
    process_message(db_cursor, _sample_message(10_000_000))  # world_bank, multilateral

    db_cursor.execute("SELECT id FROM country WHERE iso_code = 'SEN'")
    country_id = db_cursor.fetchone()[0]
    db_cursor.execute("SELECT id FROM sector WHERE name = 'Renewable Energy'")
    sector_id = db_cursor.fetchone()[0]

    db_cursor.execute("SELECT id FROM source WHERE name = 'U.S. DFC - Annual Project Data (Excel)'")
    dfc_source_id = db_cursor.fetchone()[0]
    dfc_row = _funding_row(db_cursor, dfc_source_id, country_id, sector_id, 2026, "private")
    assert dfc_row == (Decimal("5000000.00"), True)


def _norfund_sample_message(amount_usd: int, project_id: str = "Test Investee", raw_sectors=None, raw_theme=None) -> dict:
    return {
        "source": "norfund",
        "project_id": project_id,
        "country_iso": "SEN",
        "year": 2026,
        "amount_usd": amount_usd,
        "funding_type": "private",
        "raw_sectors": raw_sectors if raw_sectors is not None else ["Renewable Energy", "Hydropower"],
        "raw_theme": raw_theme if raw_theme is not None else ["Mitigation"],
        "collected_at": "2026-10-07T00:00:00Z",
    }


def test_norfund_message_inserts_a_new_funding_row_under_the_mapped_sector(db_cursor):
    # 2026-10-07 : contrairement a DFC, Norfund n'a pas de secteur fixe -
    # le secteur reel ("Renewable Energy"/"Hydropower") est classifie par
    # map_to_nev_sector(), la meme fonction generique que World Bank (voir
    # le commentaire de process_message()).
    accepted, reason = process_message(db_cursor, _norfund_sample_message(5_000_000))

    assert accepted is True
    assert reason is None

    db_cursor.execute("SELECT id FROM source WHERE name = 'Norfund - Investment Overview (Excel)'")
    source_id = db_cursor.fetchone()[0]
    db_cursor.execute("SELECT id FROM country WHERE iso_code = 'SEN'")
    country_id = db_cursor.fetchone()[0]
    db_cursor.execute("SELECT id FROM sector WHERE name = 'Renewable Energy'")
    sector_id = db_cursor.fetchone()[0]

    row = _funding_row(db_cursor, source_id, country_id, sector_id, 2026, "private")
    assert row == (Decimal("5000000.00"), True)


def test_norfund_message_with_an_unclassifiable_sector_is_rejected(db_cursor):
    # Verifie en direct : la categorie "Mitigation" de Norfund couvre
    # aussi des entreprises qui ne sont elles-memes aucun des 5 secteurs
    # NEV (ex. B5 Plus Limited - fabrication de produits metalliques) -
    # doit etre rejete plutot que force dans un mauvais secteur.
    accepted, reason = process_message(
        db_cursor,
        _norfund_sample_message(
            5_000_000,
            raw_sectors=["Scalable enterprises", "Manufacture of fabricated metal products"],
            raw_theme=["Mitigation"],
        ),
    )

    assert accepted is False
    assert reason == "unclassifiable_sector"


def test_norfund_rows_stay_separate_from_multilateral_rows_for_the_same_dedup_key(db_cursor):
    # "private" et "multilateral" font partie de la cle de deduplication
    # (uniq_funding_dedup_key_current) - une ligne Norfund privee ne doit
    # jamais ecraser/fusionner avec une ligne multilaterale du meme pays/
    # secteur/annee venue d'une autre source.
    process_message(db_cursor, _norfund_sample_message(5_000_000))
    process_message(db_cursor, _sample_message(10_000_000))  # world_bank, multilateral

    db_cursor.execute("SELECT id FROM country WHERE iso_code = 'SEN'")
    country_id = db_cursor.fetchone()[0]
    db_cursor.execute("SELECT id FROM sector WHERE name = 'Renewable Energy'")
    sector_id = db_cursor.fetchone()[0]

    db_cursor.execute("SELECT id FROM source WHERE name = 'Norfund - Investment Overview (Excel)'")
    norfund_source_id = db_cursor.fetchone()[0]
    norfund_row = _funding_row(db_cursor, norfund_source_id, country_id, sector_id, 2026, "private")
    assert norfund_row == (Decimal("5000000.00"), True)


def _proparco_sample_message(amount_usd: int, project_id: str = "PTEST1001",
                              sector_label_raw: str = "Energies renouvelables",
                              original_amount: float = None, exchange_rate: float = None) -> dict:
    return {
        "source": "proparco",
        "project_id": project_id,
        "country_iso": "SEN",
        "year": 2026,
        "amount_usd": amount_usd,
        "original_amount": original_amount if original_amount is not None else amount_usd / 1.08,
        "original_currency": "EUR",
        "exchange_rate": exchange_rate if exchange_rate is not None else 1.08,
        "funding_type": "private",
        "sector_label_raw": sector_label_raw,
        "collected_at": "2026-10-07T00:00:00Z",
    }


def test_proparco_message_inserts_a_new_funding_row_with_currency_provenance(db_cursor):
    # 2026-10-07 : contrairement a DFC/Norfund, Proparco rapporte un
    # montant natif reel en EUR (comme AfDB en XDR) - original_amount/
    # original_currency/exchange_rate doivent etre ceux du message, jamais
    # derives d'un taux USD-pivot.
    accepted, reason = process_message(db_cursor, _proparco_sample_message(5_000_000, original_amount=4_629_629.63, exchange_rate=1.08))

    assert accepted is True
    assert reason is None

    db_cursor.execute("SELECT id FROM source WHERE name = 'Proparco - Open Data AFD'")
    source_id = db_cursor.fetchone()[0]
    db_cursor.execute("SELECT id FROM country WHERE iso_code = 'SEN'")
    country_id = db_cursor.fetchone()[0]
    db_cursor.execute("SELECT id FROM sector WHERE name = 'Renewable Energy'")
    sector_id = db_cursor.fetchone()[0]

    row = _funding_row(db_cursor, source_id, country_id, sector_id, 2026, "private")
    assert row == (Decimal("5000000.00"), True)

    db_cursor.execute(
        """
        SELECT original_amount, original_currency, exchange_rate FROM funding
        WHERE source_id = %s AND country_id = %s AND sector_id = %s
          AND year = %s AND funding_type = %s AND is_current = true
        """,
        (source_id, country_id, sector_id, 2026, "private"),
    )
    original_amount, original_currency, exchange_rate = db_cursor.fetchone()
    assert original_amount == Decimal("4629629.63")
    assert original_currency == "EUR"
    assert exchange_rate == Decimal("1.08")


def test_proparco_message_with_an_unclassifiable_sector_is_rejected(db_cursor):
    # Verifie en direct : "Banque & Institution financière" est un
    # secteur reel et frequent chez Proparco, mais n'est aucun des 5
    # secteurs NEV - doit etre rejete plutot que force dans un mauvais
    # secteur.
    accepted, reason = process_message(
        db_cursor,
        _proparco_sample_message(5_000_000, sector_label_raw="Banque & Institution financière"),
    )

    assert accepted is False
    assert reason == "unclassifiable_sector"


def test_proparco_rows_stay_separate_from_multilateral_rows_for_the_same_dedup_key(db_cursor):
    process_message(db_cursor, _proparco_sample_message(5_000_000))
    process_message(db_cursor, _sample_message(10_000_000))  # world_bank, multilateral

    db_cursor.execute("SELECT id FROM country WHERE iso_code = 'SEN'")
    country_id = db_cursor.fetchone()[0]
    db_cursor.execute("SELECT id FROM sector WHERE name = 'Renewable Energy'")
    sector_id = db_cursor.fetchone()[0]

    db_cursor.execute("SELECT id FROM source WHERE name = 'Proparco - Open Data AFD'")
    proparco_source_id = db_cursor.fetchone()[0]
    proparco_row = _funding_row(db_cursor, proparco_source_id, country_id, sector_id, 2026, "private")
    assert proparco_row == (Decimal("5000000.00"), True)


def _oecd_crs_sample_message(amount_usd: int, project_id: str = "2026990001",
                              raw_sector_codes: list = None, climate_adaptation_rio_marker: bool = False) -> dict:
    return {
        "source": "oecd_crs",
        "project_id": project_id,
        "country_iso": "SEN",
        "year": 2026,
        "amount_usd": amount_usd,
        "funding_type": "private",
        "raw_sector_codes": raw_sector_codes if raw_sector_codes is not None else ["31320"],  # Fishery development -> Agriculture
        "climate_adaptation_rio_marker": climate_adaptation_rio_marker,
        "collected_at": "2026-10-07T00:00:00Z",
    }


def test_oecd_crs_message_inserts_a_new_funding_row_via_the_reused_dac_sector_mapper(db_cursor):
    # 2026-10-07 : reutilise map_afdb_sector() telle quelle - meme systeme
    # de codes secteur DAC que l'IATI Datastore d'AfDB (verifie en direct).
    accepted, reason = process_message(db_cursor, _oecd_crs_sample_message(5_000_000))

    assert accepted is True
    assert reason is None

    db_cursor.execute("SELECT id FROM source WHERE name = 'OECD CRS - Private Development Finance'")
    source_id = db_cursor.fetchone()[0]
    db_cursor.execute("SELECT id FROM country WHERE iso_code = 'SEN'")
    country_id = db_cursor.fetchone()[0]
    db_cursor.execute("SELECT id FROM sector WHERE name = 'Agriculture'")
    sector_id = db_cursor.fetchone()[0]

    row = _funding_row(db_cursor, source_id, country_id, sector_id, 2026, "private")
    assert row == (Decimal("5000000.00"), True)


def test_oecd_crs_message_falls_back_to_adaptation_via_the_rio_marker(db_cursor):
    # Code secteur sans correspondance DAC (ex. "Education facilities and
    # training", 11240) mais Marqueur de Rio adaptation reel non nul -
    # repli sur "Adaptation" plutot qu'un rejet, meme principe que le
    # theme World Bank.
    accepted, reason = process_message(
        db_cursor,
        _oecd_crs_sample_message(5_000_000, raw_sector_codes=["11240"], climate_adaptation_rio_marker=True),
    )

    assert accepted is True
    assert reason is None

    db_cursor.execute("SELECT id FROM country WHERE iso_code = 'SEN'")
    country_id = db_cursor.fetchone()[0]
    db_cursor.execute("SELECT id FROM sector WHERE name = 'Adaptation'")
    sector_id = db_cursor.fetchone()[0]
    db_cursor.execute("SELECT id FROM source WHERE name = 'OECD CRS - Private Development Finance'")
    source_id = db_cursor.fetchone()[0]

    row = _funding_row(db_cursor, source_id, country_id, sector_id, 2026, "private")
    assert row == (Decimal("5000000.00"), True)


def test_oecd_crs_message_without_a_sector_match_or_rio_marker_is_rejected(db_cursor):
    accepted, reason = process_message(
        db_cursor,
        _oecd_crs_sample_message(5_000_000, raw_sector_codes=["11240"], climate_adaptation_rio_marker=False),
    )

    assert accepted is False
    assert reason == "unclassifiable_sector"


def test_oecd_crs_rows_stay_separate_from_multilateral_rows_for_the_same_dedup_key(db_cursor):
    process_message(db_cursor, _oecd_crs_sample_message(5_000_000))
    process_message(db_cursor, _sample_message(10_000_000))  # world_bank, multilateral, Renewable Energy

    db_cursor.execute("SELECT id FROM country WHERE iso_code = 'SEN'")
    country_id = db_cursor.fetchone()[0]
    db_cursor.execute("SELECT id FROM sector WHERE name = 'Agriculture'")
    sector_id = db_cursor.fetchone()[0]

    db_cursor.execute("SELECT id FROM source WHERE name = 'OECD CRS - Private Development Finance'")
    oecd_crs_source_id = db_cursor.fetchone()[0]
    oecd_crs_row = _funding_row(db_cursor, oecd_crs_source_id, country_id, sector_id, 2026, "private")
    assert oecd_crs_row == (Decimal("5000000.00"), True)


def _afd_sample_message(amount_usd: int, project_id: str = "FR-3-TEST-001",
                         raw_sector_codes: list = None,
                         original_amount: float = None, exchange_rate: float = None) -> dict:
    return {
        "source": "afd",
        "project_id": project_id,
        "country_iso": "SEN",
        "year": 2026,
        "amount_usd": amount_usd,
        "original_amount": original_amount if original_amount is not None else amount_usd / 1.08,
        "original_currency": "EUR",
        "exchange_rate": exchange_rate if exchange_rate is not None else 1.08,
        "funding_type": "public",
        "raw_sector_codes": raw_sector_codes if raw_sector_codes is not None else ["21012"],  # Road transport -> Sustainable Transport
        "board_approval_date": "2026-01-15",
        "collected_at": "2026-10-08T00:00:00Z",
    }


def test_afd_message_inserts_a_new_funding_row_with_currency_provenance(db_cursor):
    # 2026-10-08 : contrairement a DFC/Norfund/Proparco/OECD CRS, l'AFD
    # rapporte un montant natif reel en EUR (comme AfDB en XDR) -
    # original_amount/original_currency/exchange_rate doivent etre ceux
    # du message, jamais derives d'un taux USD-pivot.
    accepted, reason = process_message(db_cursor, _afd_sample_message(5_000_000, original_amount=4_629_629.63, exchange_rate=1.08))

    assert accepted is True
    assert reason is None

    db_cursor.execute("SELECT id FROM source WHERE name = 'Agence Francaise de Developpement - IATI Datastore'")
    source_id = db_cursor.fetchone()[0]
    db_cursor.execute("SELECT id FROM country WHERE iso_code = 'SEN'")
    country_id = db_cursor.fetchone()[0]
    db_cursor.execute("SELECT id FROM sector WHERE name = 'Sustainable Transport'")
    sector_id = db_cursor.fetchone()[0]

    row = _funding_row(db_cursor, source_id, country_id, sector_id, 2026, "public")
    assert row == (Decimal("5000000.00"), True)

    db_cursor.execute(
        """
        SELECT original_amount, original_currency, exchange_rate FROM funding
        WHERE source_id = %s AND country_id = %s AND sector_id = %s
          AND year = %s AND funding_type = %s AND is_current = true
        """,
        (source_id, country_id, sector_id, 2026, "public"),
    )
    original_amount, original_currency, exchange_rate = db_cursor.fetchone()
    assert original_amount == Decimal("4629629.63")
    assert original_currency == "EUR"
    assert exchange_rate == Decimal("1.08")


def test_afd_message_local_currency_is_the_recipient_countrys_own_not_eur(db_cursor):
    """Audit 2026-10-13 : reproduit exactement le cas trouve en direct par
    l'utilisateur - une ligne AFD affichant "8 300 000,00 EUR" sous
    l'ancienne colonne "devise locale" alors qu'EUR n'est la devise
    d'aucun pays africain. local_amount/local_currency doivent toujours
    etre la devise du PAYS beneficiaire (ici le Senegal, XOF) - jamais
    EUR, meme si original_currency (la devise propre de l'AFD, testee
    ci-dessus) reste EUR comme attendu.
    """
    accepted, reason = process_message(db_cursor, _afd_sample_message(5_000_000, original_amount=4_629_629.63, exchange_rate=1.08))
    assert accepted is True

    db_cursor.execute("SELECT id FROM source WHERE name = 'Agence Francaise de Developpement - IATI Datastore'")
    source_id = db_cursor.fetchone()[0]
    db_cursor.execute("SELECT id FROM country WHERE iso_code = 'SEN'")
    country_id = db_cursor.fetchone()[0]
    db_cursor.execute("SELECT currency FROM country WHERE id = %s", (country_id,))
    expected_local_currency = db_cursor.fetchone()[0]
    db_cursor.execute("SELECT id FROM sector WHERE name = 'Sustainable Transport'")
    sector_id = db_cursor.fetchone()[0]

    db_cursor.execute(
        """
        SELECT local_amount, local_currency, local_exchange_rate, original_currency FROM funding
        WHERE source_id = %s AND country_id = %s AND sector_id = %s
          AND year = %s AND funding_type = %s AND is_current = true
        """,
        (source_id, country_id, sector_id, 2026, "public"),
    )
    local_amount, local_currency, local_exchange_rate, original_currency = db_cursor.fetchone()
    assert expected_local_currency is not None, "fixture gap: Senegal must have a known currency for this test to mean anything"
    assert local_currency == expected_local_currency
    assert local_currency != "EUR"
    assert local_amount is not None
    assert local_exchange_rate is not None
    # original_currency (l'AFD elle-meme) reste EUR - les deux champs
    # coexistent, l'un n'ecrase jamais l'autre.
    assert original_currency == "EUR"


def test_afd_message_with_an_unclassifiable_sector_is_rejected(db_cursor):
    accepted, reason = process_message(
        db_cursor,
        _afd_sample_message(5_000_000, raw_sector_codes=["15160"]),  # Human rights - not a NEV sector
    )

    assert accepted is False
    assert reason == "unclassifiable_sector"


def test_afd_rows_stay_separate_from_multilateral_rows_for_the_same_dedup_key(db_cursor):
    # "public" et "multilateral" font partie de la cle de deduplication
    # (uniq_funding_dedup_key_current) - une ligne AFD publique ne doit
    # jamais ecraser/fusionner avec une ligne multilaterale du meme
    # pays/secteur/annee venue d'une autre source.
    process_message(db_cursor, _afd_sample_message(5_000_000))
    process_message(db_cursor, _sample_message(10_000_000, project_id="P-AFD-COMPARE"))  # world_bank, multilateral, Renewable Energy

    db_cursor.execute("SELECT id FROM country WHERE iso_code = 'SEN'")
    country_id = db_cursor.fetchone()[0]
    db_cursor.execute("SELECT id FROM sector WHERE name = 'Sustainable Transport'")
    sector_id = db_cursor.fetchone()[0]

    db_cursor.execute("SELECT id FROM source WHERE name = 'Agence Francaise de Developpement - IATI Datastore'")
    afd_source_id = db_cursor.fetchone()[0]
    afd_row = _funding_row(db_cursor, afd_source_id, country_id, sector_id, 2026, "public")
    assert afd_row == (Decimal("5000000.00"), True)


def _bii_sample_message(amount_usd: int, project_id: str = "GB-COH-03877777-TEST001",
                         raw_sector_codes: list = None) -> dict:
    return {
        "source": "bii",
        "project_id": project_id,
        "country_iso": "SEN",
        "year": 2026,
        "amount_usd": amount_usd,
        "funding_type": "private",
        "raw_sector_codes": raw_sector_codes if raw_sector_codes is not None else ["23230"],  # Solar energy -> Renewable Energy
        "board_approval_date": "2026-01-15",
        "collected_at": "2026-10-08T00:00:00Z",
    }


def test_bii_message_inserts_a_new_funding_row_via_the_reused_dac_sector_mapper(db_cursor):
    # 2026-10-08 : reutilise map_afdb_sector() telle quelle - meme
    # systeme de codes secteur DAC que AfDB/AFD/OECD CRS.
    accepted, reason = process_message(db_cursor, _bii_sample_message(5_000_000))

    assert accepted is True
    assert reason is None

    db_cursor.execute("SELECT id FROM source WHERE name = 'British International Investment - IATI Datastore'")
    source_id = db_cursor.fetchone()[0]
    db_cursor.execute("SELECT id FROM country WHERE iso_code = 'SEN'")
    country_id = db_cursor.fetchone()[0]
    db_cursor.execute("SELECT id FROM sector WHERE name = 'Renewable Energy'")
    sector_id = db_cursor.fetchone()[0]

    row = _funding_row(db_cursor, source_id, country_id, sector_id, 2026, "private")
    assert row == (Decimal("5000000.00"), True)


def test_bii_message_with_an_unclassifiable_sector_is_rejected(db_cursor):
    accepted, reason = process_message(
        db_cursor,
        _bii_sample_message(5_000_000, raw_sector_codes=["24030"]),  # Formal sector financial intermediaries - not a NEV sector
    )

    assert accepted is False
    assert reason == "unclassifiable_sector"


def test_bii_rows_stay_separate_from_multilateral_rows_for_the_same_dedup_key(db_cursor):
    process_message(db_cursor, _bii_sample_message(5_000_000))
    process_message(db_cursor, _sample_message(10_000_000, project_id="P-BII-COMPARE"))  # world_bank, multilateral, Renewable Energy

    db_cursor.execute("SELECT id FROM country WHERE iso_code = 'SEN'")
    country_id = db_cursor.fetchone()[0]
    db_cursor.execute("SELECT id FROM sector WHERE name = 'Renewable Energy'")
    sector_id = db_cursor.fetchone()[0]

    db_cursor.execute("SELECT id FROM source WHERE name = 'British International Investment - IATI Datastore'")
    bii_source_id = db_cursor.fetchone()[0]
    bii_row = _funding_row(db_cursor, bii_source_id, country_id, sector_id, 2026, "private")
    assert bii_row == (Decimal("5000000.00"), True)


def _fmo_sample_message(amount_usd: int, project_id: str = "NL-KVK-27078545-TEST001",
                         raw_sector_codes: list = None,
                         original_amount: float = None, exchange_rate: float = None) -> dict:
    return {
        "source": "fmo",
        "project_id": project_id,
        "country_iso": "SEN",
        "year": 2026,
        "amount_usd": amount_usd,
        "original_amount": original_amount if original_amount is not None else amount_usd / 1.08,
        "original_currency": "EUR",
        "exchange_rate": exchange_rate if exchange_rate is not None else 1.08,
        "funding_type": "private",
        "raw_sector_codes": raw_sector_codes if raw_sector_codes is not None else ["23210"],  # Energy generation, renewable sources -> Renewable Energy
        "board_approval_date": "2026-01-15",
        "collected_at": "2026-10-08T00:00:00Z",
    }


def test_fmo_message_inserts_a_new_funding_row_with_currency_provenance(db_cursor):
    # 2026-10-08 : comme AfDB/AFD, FMO rapporte un montant natif reel en
    # EUR - original_amount/original_currency/exchange_rate doivent etre
    # ceux du message, jamais derives d'un taux USD-pivot.
    accepted, reason = process_message(db_cursor, _fmo_sample_message(5_000_000, original_amount=4_629_629.63, exchange_rate=1.08))

    assert accepted is True
    assert reason is None

    db_cursor.execute("SELECT id FROM source WHERE name = 'FMO - IATI Datastore'")
    source_id = db_cursor.fetchone()[0]
    db_cursor.execute("SELECT id FROM country WHERE iso_code = 'SEN'")
    country_id = db_cursor.fetchone()[0]
    db_cursor.execute("SELECT id FROM sector WHERE name = 'Renewable Energy'")
    sector_id = db_cursor.fetchone()[0]

    row = _funding_row(db_cursor, source_id, country_id, sector_id, 2026, "private")
    assert row == (Decimal("5000000.00"), True)

    db_cursor.execute(
        """
        SELECT original_amount, original_currency, exchange_rate FROM funding
        WHERE source_id = %s AND country_id = %s AND sector_id = %s
          AND year = %s AND funding_type = %s AND is_current = true
        """,
        (source_id, country_id, sector_id, 2026, "private"),
    )
    original_amount, original_currency, exchange_rate = db_cursor.fetchone()
    assert original_amount == Decimal("4629629.63")
    assert original_currency == "EUR"
    assert exchange_rate == Decimal("1.08")


def test_fmo_message_with_an_unclassifiable_sector_is_rejected(db_cursor):
    accepted, reason = process_message(
        db_cursor,
        _fmo_sample_message(5_000_000, raw_sector_codes=["24030"]),  # Formal sector financial intermediaries - not a NEV sector
    )

    assert accepted is False
    assert reason == "unclassifiable_sector"


def test_fmo_rows_stay_separate_from_multilateral_rows_for_the_same_dedup_key(db_cursor):
    process_message(db_cursor, _fmo_sample_message(5_000_000))
    process_message(db_cursor, _sample_message(10_000_000, project_id="P-FMO-COMPARE"))  # world_bank, multilateral, Renewable Energy

    db_cursor.execute("SELECT id FROM country WHERE iso_code = 'SEN'")
    country_id = db_cursor.fetchone()[0]
    db_cursor.execute("SELECT id FROM sector WHERE name = 'Renewable Energy'")
    sector_id = db_cursor.fetchone()[0]

    db_cursor.execute("SELECT id FROM source WHERE name = 'FMO - IATI Datastore'")
    fmo_source_id = db_cursor.fetchone()[0]
    fmo_row = _funding_row(db_cursor, fmo_source_id, country_id, sector_id, 2026, "private")
    assert fmo_row == (Decimal("5000000.00"), True)
