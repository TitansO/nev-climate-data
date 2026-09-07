"""Shared USD-pivot exchange-rate helper, real rates only - never the
illustrative table backend/src/Reference/AfricanCurrencies.php uses for
the Volet A demo dataset (that class's own docstring reserves it strictly
for demo data; see the "conversion réelle en devise locale" plan).

Same free, keyless API `pipeline/collectors/afdb.py::fetch_xdr_to_usd_rate`
already uses for XDR->USD, queried in the other direction here
(USD->every other currency) - verified live to cover all 42 African
currency codes `AfricanCurrencies::COUNTRY_CURRENCY` maps to, zero missing.
"""
from __future__ import annotations

import requests

USD_RATES_URL = "https://open.er-api.com/v6/latest/USD"
REQUEST_TIMEOUT_SECONDS = 30


def fetch_usd_to_currency_rates() -> dict[str, float]:
    """Fetches today's USD -> <currency> rates for every currency the API
    publishes (166 as of verification, all needed African codes present).
    Each value is "units of that currency per 1 USD" - multiply a USD
    amount by this to get the local-currency equivalent, same direction
    already established by AfricanCurrencies::LOCAL_UNITS_PER_USD.
    """
    response = requests.get(USD_RATES_URL, timeout=REQUEST_TIMEOUT_SECONDS)
    response.raise_for_status()
    payload = response.json()
    return payload["rates"]
