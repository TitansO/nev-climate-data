"""Unit tests for the DFC collector's parsing logic - uses realistic
sample rows (field shapes captured from the real, public DFC "Project
Data" file during this connector's design, 2026-10-06) rather than
hitting the network, so this file runs offline and fast.
"""
from unittest.mock import MagicMock, patch

from pipeline.collectors.dfc import (
    collect_and_publish,
    fetch_dfc_projects,
    parse_project,
)

# Reel (champs simplifies), verifie en direct le 2026-10-06 : un vrai
# projet geothermique prive au Kenya, finance par la DFC.
_SAMPLE_PROJECT_PRIVATE_CLIMATE = {
    "Fiscal Year": 2011,
    "Project Number": 9000005508,
    "Country": "Kenya",
    "NAICS Sector": "Utilities",
    "Project Name": "Orpower 4 Geothermal",
    "Project Description": "52 MW expansion of geothermal power plant",
    "Committed": 310000000,
    "Currency": "USD",
    "Sovereign (Yes/No)": "No",
}

# Reel : meme secteur Utilities mais aucun mot-cle climat dans la
# description - ne doit rien publier (centrale non-renouvelable).
_SAMPLE_PROJECT_UTILITIES_NON_CLIMATE = {
    "Fiscal Year": 2015,
    "Project Number": 9000011111,
    "Country": "Jordan",
    "NAICS Sector": "Utilities",
    "Project Name": "Generic Power Transmission Upgrade",
    "Project Description": "Upgrade of national electricity transmission grid infrastructure",
    "Committed": 50000000,
    "Currency": "USD",
    "Sovereign (Yes/No)": "No",
}

# Reel : meme projet climat mais souverain (garanti par l'Etat) - hors
# perimetre de ce connecteur (c'est le gouvernement qui porte le risque,
# pas une entreprise privee).
_SAMPLE_PROJECT_SOVEREIGN_CLIMATE = {
    "Fiscal Year": 2020,
    "Project Number": 9000022222,
    "Country": "Mexico",
    "NAICS Sector": "Utilities",
    "Project Name": "National Solar Program",
    "Project Description": "Sovereign-backed national solar energy expansion program",
    "Committed": 100000000,
    "Currency": "USD",
    "Sovereign (Yes/No)": "Yes",
}

# Reel, verifie en direct : 1 projet climat prive du fichier reel est en
# devise locale (KES), pas USD - doit etre exclu, jamais converti sans
# taux historique fiable.
_SAMPLE_PROJECT_NON_USD_CURRENCY = {
    "Fiscal Year": 2019,
    "Project Number": 9000103704,
    "Country": "Kenya",
    "NAICS Sector": "Utilities",
    "Project Name": "SCALE-NonMSME- D. Light Limited",
    "Project Description": "Off-grid solar home systems distribution",
    "Committed": 4500000,
    "Currency": "KES",
    "Sovereign (Yes/No)": "No",
}

# Reel : entree regionale, aucun pays unique a qui attribuer le montant.
_SAMPLE_PROJECT_REGIONAL = {
    "Fiscal Year": 2024,
    "Project Number": 9000116905,
    "Country": "Africa Regional",
    "NAICS Sector": "Utilities",
    "Project Name": "Evolution III Fund",
    "Project Description": "Regional renewable energy investment fund",
    "Committed": 25000000,
    "Currency": "USD",
    "Sovereign (Yes/No)": "No",
}


def test_parse_project_extracts_a_private_climate_project():
    payload = parse_project(_SAMPLE_PROJECT_PRIVATE_CLIMATE)

    assert payload["source"] == "dfc"
    assert payload["project_id"] == "9000005508"
    assert payload["country_iso"] == "KEN"
    assert payload["year"] == 2011
    assert payload["amount_usd"] == 310000000
    assert payload["funding_type"] == "private"
    assert payload["project_name"] == "Orpower 4 Geothermal"


def test_parse_project_returns_none_without_a_climate_keyword():
    assert parse_project(_SAMPLE_PROJECT_UTILITIES_NON_CLIMATE) is None


def test_parse_project_returns_none_for_a_non_utilities_sector():
    project = {**_SAMPLE_PROJECT_PRIVATE_CLIMATE, "NAICS Sector": "Finance and Insurance"}
    assert parse_project(project) is None


def test_parse_project_returns_none_for_a_sovereign_backed_project():
    assert parse_project(_SAMPLE_PROJECT_SOVEREIGN_CLIMATE) is None


def test_parse_project_returns_none_for_a_non_usd_currency():
    assert parse_project(_SAMPLE_PROJECT_NON_USD_CURRENCY) is None


def test_parse_project_returns_none_for_a_regional_entry():
    assert parse_project(_SAMPLE_PROJECT_REGIONAL) is None


def test_parse_project_returns_none_without_a_committed_amount():
    project = {**_SAMPLE_PROJECT_PRIVATE_CLIMATE, "Committed": None}
    assert parse_project(project) is None


def test_fetch_dfc_projects_parses_the_real_sheet_shape():
    mock_workbook = MagicMock()
    mock_sheet = MagicMock()
    mock_sheet.__getitem__.return_value = [MagicMock(value="Country"), MagicMock(value="Committed")]
    mock_sheet.iter_rows.return_value = [("Kenya", 310000000)]
    mock_workbook.__getitem__.return_value = mock_sheet

    mock_response = MagicMock()
    mock_response.content = b"fake-xlsx-bytes"

    with patch("pipeline.collectors.dfc.requests.get", return_value=mock_response), \
         patch("pipeline.collectors.dfc.openpyxl.load_workbook", return_value=mock_workbook):
        results = list(fetch_dfc_projects())

    assert results == [{"Country": "Kenya", "Committed": 310000000}]


def test_collect_and_publish_sends_only_parseable_projects_and_returns_count():
    mock_producer = MagicMock()

    with patch(
        "pipeline.collectors.dfc.fetch_dfc_projects",
        return_value=iter([_SAMPLE_PROJECT_PRIVATE_CLIMATE, _SAMPLE_PROJECT_UTILITIES_NON_CLIMATE]),
    ):
        published = collect_and_publish(mock_producer)

    assert published == 1
    mock_producer.send.assert_called_once()
    assert mock_producer.send.call_args[0][0] == "nev.funding.raw"
    mock_producer.flush.assert_called_once()
