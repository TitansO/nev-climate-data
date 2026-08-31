"""Live smoke test for the OPEC Fund collector - downloads the real
target PDF and calls the real Gemini API. Kept separate from
test_opec_fund_collector.py (which is fully mocked) so it can be
run/skipped independently with `-m live`. This is the one test in the
whole B1.5 suite that spends real Gemini quota - keep it to a single
call.
"""
import json

import pytest
import requests

from pipeline.collectors.opec_fund_climate_finance import (
    ANNEX_2_END_PAGE,
    ANNEX_2_START_PAGE,
    EXTRACTION_PROMPT,
    SOURCE_URL,
)
from pipeline.common.pdf_extraction import extract_json_via_gemini, slice_pdf_pages


@pytest.mark.live
def test_real_extraction_returns_the_full_real_annex_2_table():
    response = requests.get(SOURCE_URL, timeout=60)
    response.raise_for_status()
    annex_bytes = slice_pdf_pages(response.content, ANNEX_2_START_PAGE, ANNEX_2_END_PAGE)

    raw_text = extract_json_via_gemini(annex_bytes, EXTRACTION_PROMPT)
    rows = json.loads(raw_text)

    assert len(rows) > 100  # verified live during design work: 111 real rows
    assert all({"year", "country", "project", "sector", "amount_usd_mn",
                "adaptation_pct", "mitigation_pct", "total_climate_pct"} <= row.keys()
               for row in rows)
    senegal_rows = [r for r in rows if r["country"] == "Senegal"]
    assert len(senegal_rows) >= 1
