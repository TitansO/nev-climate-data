"""One-off backfill: populates original_amount/original_currency/exchange_rate
for `funding` rows inserted before the live USD -> local-currency conversion
existed (World Bank/GCF/OPEC Fund PDF - AfDB has always carried its own real
XDR figure and is never touched here). Kept in the repo rather than run once
and discarded, in case a future purge/reimport needs it replayed.

Run once via:
    docker compose exec funding-validator python -m pipeline.scripts.backfill_local_currency
"""
from __future__ import annotations

from decimal import Decimal

from pipeline.common.db import get_connection
from pipeline.processors.funding_validator import (
    ensure_gcf_source,
    ensure_opec_fund_source,
    ensure_world_bank_source,
    get_current_usd_rates,
    lookup_country_currency,
)


def backfill() -> int:
    """Fills in the 3 fields for every current, still-unconverted row from
    the 3 USD-native sources. Returns the number of rows updated. A country
    with no known currency, or a currency absent from the fetched rate
    table, is left untouched (None stays None) - same null-safety as
    process_message(), never a guessed fallback.
    """
    connection = get_connection()
    updated = 0
    try:
        with connection:
            with connection.cursor() as cursor:
                source_ids = (
                    ensure_world_bank_source(cursor),
                    ensure_gcf_source(cursor),
                    ensure_opec_fund_source(cursor),
                )
                rates = get_current_usd_rates()

                cursor.execute(
                    """
                    SELECT id, country_id, amount FROM funding
                    WHERE is_current = true AND source_id = ANY(%s) AND original_currency IS NULL
                    """,
                    (list(source_ids),),
                )
                rows = cursor.fetchall()

                for funding_id, country_id, amount in rows:
                    local_currency = lookup_country_currency(cursor, country_id)
                    if not local_currency:
                        continue
                    rate = rates.get(local_currency)
                    if not rate:
                        continue

                    original_amount = Decimal(str(amount)) * Decimal(str(rate))
                    exchange_rate = Decimal("1") / Decimal(str(rate))
                    cursor.execute(
                        """
                        UPDATE funding
                        SET original_amount = %s, original_currency = %s, exchange_rate = %s,
                            updated_at = now()
                        WHERE id = %s
                        """,
                        (original_amount, local_currency, exchange_rate, funding_id),
                    )
                    updated += 1
    finally:
        connection.close()

    return updated


if __name__ == "__main__":
    count = backfill()
    print(f"[backfill_local_currency] updated {count} funding row(s)")
