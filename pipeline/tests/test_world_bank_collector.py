"""Unit tests for the World Bank collector's parsing/pagination logic -
uses mocked HTTP responses (payload shapes captured from the real API
during the B1.1 design work) rather than hitting the network, so this
file runs offline and fast. The live-network smoke test lives in
test_world_bank_collector_live.py, kept separate so it can be skipped
independently if the external API is unreachable.
"""
from unittest.mock import MagicMock, patch

from pipeline.collectors.world_bank import (
    collect_and_publish,
    fetch_projects_for_country,
    parse_project,
)

_SAMPLE_PROJECT_WITH_SECTOR = {
    "id": "P506839",
    "countryname": "Republic of Senegal",
    "countrycode": ["SN"],
    "totalamt": "300000000",
    "boardapprovaldate": "2026-09-15T00:00:00Z",
    "status": "Dropped",
    "major_sectors": [
        {"major_sector": {"major_sector_name": "Energy and Mineral Resources"}},
    ],
    "theme": " Adaptation,Climate Change,Green and Resilient Growth",
}

_SAMPLE_PROJECT_PIPELINE_NO_DATA = {
    "id": "P516778",
    "countryname": "Republic of Senegal",
    "countrycode": ["SN"],
    "totalamt": "0",
    "boardapprovaldate": "2026-09-24T00:00:00Z",
    "status": "Pipeline",
}


def test_parse_project_extracts_expected_fields():
    result = parse_project(_SAMPLE_PROJECT_WITH_SECTOR)

    assert result["source"] == "world_bank"
    assert result["project_id"] == "P506839"
    assert result["country_iso"] == "SEN"  # converted from the API's alpha-2 "SN" via pycountry
    assert result["year"] == 2026
    assert result["amount_usd"] == 300000000
    assert result["funding_type"] == "multilateral"
    assert result["raw_sectors"] == ["Energy and Mineral Resources"]
    assert result["raw_theme"] == ["Adaptation", "Climate Change", "Green and Resilient Growth"]
    assert result["board_approval_date"] == "2026-09-15"


def test_parse_project_returns_none_for_zero_amount():
    assert parse_project(_SAMPLE_PROJECT_PIPELINE_NO_DATA) is None


def test_fetch_projects_for_country_paginates_until_total_reached():
    page_one = {"total": "150", "projects": {"P1": {"id": "P1"}, "P2": {"id": "P2"}}}
    page_two = {"total": "150", "projects": {"P3": {"id": "P3"}}}
    mock_response_one = MagicMock()
    mock_response_one.json.return_value = page_one
    mock_response_two = MagicMock()
    mock_response_two.json.return_value = page_two

    with patch(
        "pipeline.collectors.world_bank.requests.get",
        side_effect=[mock_response_one, mock_response_two],
    ) as mock_get:
        results = list(fetch_projects_for_country("SN"))

    assert [project["id"] for project in results] == ["P1", "P2", "P3"]
    assert mock_get.call_count == 2
    assert mock_get.call_args_list[0].kwargs["params"]["os"] == 0
    assert mock_get.call_args_list[1].kwargs["params"]["os"] == 100


def test_collect_and_publish_sends_only_parseable_projects_and_returns_count():
    page = {
        "total": "2",
        "projects": {
            "P506839": _SAMPLE_PROJECT_WITH_SECTOR,
            "P516778": _SAMPLE_PROJECT_PIPELINE_NO_DATA,
        },
    }
    mock_response = MagicMock()
    mock_response.json.return_value = page
    mock_producer = MagicMock()

    with patch("pipeline.collectors.world_bank.requests.get", return_value=mock_response):
        published = collect_and_publish(["SN"], mock_producer)

    assert published == 1
    mock_producer.send.assert_called_once()
    assert mock_producer.send.call_args[0][0] == "nev.funding.raw"
    mock_producer.flush.assert_called_once()
