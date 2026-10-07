"""Unit tests for the Norfund collector's extraction/join/parsing logic -
uses realistic sample rows (field shapes captured from the real, public
Norfund "Investment Overview" file during this connector's design,
2026-10-07) rather than hitting the network, so this file runs offline
and fast.
"""
import datetime as dt
from unittest.mock import MagicMock, patch

from pipeline.collectors.norfund import (
    collect_and_publish,
    extract_climate_finance_by_investee,
    extract_earliest_commitment_year_by_investee,
    fetch_norfund_projects,
    parse_project,
)


def _make_mock_sheet(headers, rows):
    sheet = MagicMock()
    sheet.__getitem__.return_value = [MagicMock(value=h) for h in headers]
    sheet.iter_rows.return_value = rows
    return sheet


def _make_mock_workbook(sheets: dict):
    workbook = MagicMock()
    workbook.__getitem__.side_effect = lambda name: sheets[name]
    return workbook


def test_extract_climate_finance_by_investee_skips_non_climate_rows():
    # Reel : "Climate Finance Category" vaut l'entier 0 (jamais une
    # chaine) pour un investissement sans pertinence climat.
    sheet = _make_mock_sheet(
        ["Investee", "Climate Finance (USD)", "Climate Finance Category"],
        [
            ("Agua Imara", 5_000_000, "Mitigation"),
            ("B5 Plus Limited", 0, 0),
        ],
    )
    workbook = _make_mock_workbook({"Climate finance share": sheet})

    result = extract_climate_finance_by_investee(workbook)

    assert result == {"Agua Imara": (5_000_000.0, "Mitigation")}


def test_extract_earliest_commitment_year_by_investee_ignores_zero_amount_rows():
    # Reel : un meme investissement a plusieurs lignes/tranches sur
    # plusieurs annees, certaines a montant nul (tranche non tiree) - ne
    # doivent pas compter comme un engagement reel.
    sheet = _make_mock_sheet(
        ["Investee", "Committed Date", "Commitment (USD)"],
        [
            ("Agua Imara", dt.datetime(2008, 1, 1), 0),
            ("Agua Imara", dt.datetime(2015, 6, 1), 2_000_000),
            ("Agua Imara", dt.datetime(2010, 1, 1), 0),
        ],
    )
    workbook = _make_mock_workbook({"Investments by instrument": sheet})

    result = extract_earliest_commitment_year_by_investee(workbook)

    assert result == {"Agua Imara": 2015}


def test_fetch_norfund_projects_joins_the_three_sheets():
    climate_sheet = _make_mock_sheet(
        ["Investee", "Climate Finance (USD)", "Climate Finance Category"],
        [
            ("Agua Imara", 5_000_000, "Mitigation"),
            ("B5 Plus Limited", 0, 0),
        ],
    )
    instrument_sheet = _make_mock_sheet(
        ["Investee", "Committed Date", "Commitment (USD)"],
        [("Agua Imara", dt.datetime(2015, 6, 1), 2_000_000)],
    )
    country_sheet = _make_mock_sheet(
        ["Investee", "Country of operations", "Country share", "Sector", "Sub-Sector"],
        [
            ("Agua Imara", "Guatemala", 0.6, "Renewable Energy", "Hydropower"),
            ("Agua Imara", "Panama", 0.4, "Renewable Energy", "Hydropower"),
            ("B5 Plus Limited", "Zambia", 1.0, "Scalable enterprises", "Manufacture of fabricated metal products"),
        ],
    )
    workbook = _make_mock_workbook(
        {
            "Climate finance share": climate_sheet,
            "Investments by instrument": instrument_sheet,
            "Investments by country": country_sheet,
        }
    )

    results = list(fetch_norfund_projects(workbook))

    assert len(results) == 2
    assert results[0]["investee"] == "Agua Imara"
    assert results[0]["country"] == "Guatemala"
    assert results[0]["country_share"] == 0.6
    assert results[0]["climate_finance_usd_total"] == 5_000_000.0
    assert results[0]["year"] == 2015
    # B5 Plus Limited has no nonzero climate finance -> excluded entirely.
    assert all(row["investee"] != "B5 Plus Limited" for row in results)


def test_fetch_norfund_projects_skips_an_investee_with_no_resolvable_year():
    climate_sheet = _make_mock_sheet(
        ["Investee", "Climate Finance (USD)", "Climate Finance Category"],
        [("Orphan Investee", 1_000_000, "Mitigation")],
    )
    instrument_sheet = _make_mock_sheet(["Investee", "Committed Date", "Commitment (USD)"], [])
    country_sheet = _make_mock_sheet(
        ["Investee", "Country of operations", "Country share", "Sector", "Sub-Sector"],
        [("Orphan Investee", "Kenya", 1.0, "Renewable Energy", "Solar power")],
    )
    workbook = _make_mock_workbook(
        {
            "Climate finance share": climate_sheet,
            "Investments by instrument": instrument_sheet,
            "Investments by country": country_sheet,
        }
    )

    assert list(fetch_norfund_projects(workbook)) == []


_SAMPLE_RAW_ROW = {
    "investee": "Agua Imara",
    "country": "Guatemala",
    "country_share": 0.6,
    "sector": "Renewable Energy",
    "sub_sector": "Hydropower",
    "climate_finance_usd_total": 5_000_000.0,
    "climate_finance_category": "Mitigation",
    "year": 2015,
}


def test_parse_project_extracts_a_country_weighted_private_climate_project():
    payload = parse_project(_SAMPLE_RAW_ROW)

    assert payload["source"] == "norfund"
    assert payload["project_id"] == "Agua Imara"
    assert payload["country_iso"] == "GTM"
    assert payload["year"] == 2015
    assert payload["amount_usd"] == 3_000_000  # 5,000,000 * 0.6
    assert payload["funding_type"] == "private"
    assert payload["raw_sectors"] == ["Renewable Energy", "Hydropower"]
    assert payload["raw_theme"] == ["Mitigation"]


def test_parse_project_returns_none_for_no_country_distribution():
    row = {**_SAMPLE_RAW_ROW, "country": "No Country Distribution"}
    assert parse_project(row) is None


def test_parse_project_returns_none_for_a_zero_country_share():
    row = {**_SAMPLE_RAW_ROW, "country_share": 0}
    assert parse_project(row) is None


def test_parse_project_returns_none_for_an_unresolvable_country_name():
    row = {**_SAMPLE_RAW_ROW, "country": "Nowhereland Totally Made Up"}
    assert parse_project(row) is None


def test_parse_project_omits_a_missing_sub_sector_from_raw_sectors():
    row = {**_SAMPLE_RAW_ROW, "sub_sector": None}
    payload = parse_project(row)
    assert payload["raw_sectors"] == ["Renewable Energy"]


def test_collect_and_publish_sends_only_parseable_projects_and_returns_count():
    mock_producer = MagicMock()
    unparseable_row = {**_SAMPLE_RAW_ROW, "country": "No Country Distribution"}

    with patch("pipeline.collectors.norfund.fetch_norfund_workbook", return_value=MagicMock()), \
         patch(
             "pipeline.collectors.norfund.fetch_norfund_projects",
             return_value=iter([_SAMPLE_RAW_ROW, unparseable_row]),
         ):
        published = collect_and_publish(mock_producer)

    assert published == 1
    mock_producer.send.assert_called_once()
    assert mock_producer.send.call_args[0][0] == "nev.funding.raw"
    assert mock_producer.send.call_args[1]["key"] == "norfund:Agua Imara:GTM"
    mock_producer.flush.assert_called_once()
