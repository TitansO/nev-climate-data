"""Unit tests for ai/ingest.py - filesystem reads use a real tmp_path
fixture (no need to mock the filesystem for something this simple);
backend HTTP calls (report catalogue, analytics aggregates) are mocked,
same offline-by-default convention as the rest of this project.
"""
from unittest.mock import MagicMock, patch

from ai import ingest


def test_ingest_markdown_files_chunks_every_md_file_and_the_readme(tmp_path):
    docs_root = tmp_path / "docs"
    docs_root.mkdir()
    (docs_root / "spec.md").write_text("Contenu de la spec.", encoding="utf-8")
    (docs_root / "notes.txt").write_text("Ignore moi, pas du markdown.", encoding="utf-8")
    readme_path = tmp_path / "README.md"
    readme_path.write_text("Contenu du README.", encoding="utf-8")

    with patch("ai.ingest.DOCS_ROOT", docs_root), \
         patch("ai.ingest.README_PATH", readme_path), \
         patch("ai.rag.upsert_chunk", return_value=True) as mock_upsert:
        inserted = ingest.ingest_markdown_files()

    assert inserted == 2  # spec.md + README.md, not notes.txt
    source_refs = {call.args[1] for call in mock_upsert.call_args_list}
    assert str(docs_root / "spec.md") in source_refs
    assert str(readme_path) in source_refs


def test_ingest_markdown_files_returns_zero_when_docs_root_is_missing(tmp_path):
    missing_docs_root = tmp_path / "does-not-exist"
    missing_readme = tmp_path / "also-missing.md"

    with patch("ai.ingest.DOCS_ROOT", missing_docs_root), \
         patch("ai.ingest.README_PATH", missing_readme):
        assert ingest.ingest_markdown_files() == 0


def test_ingest_reports_chunks_one_blurb_per_report():
    fake_response = MagicMock()
    fake_response.json.return_value = {
        "data": [
            {"id": 9, "title": "Senegal - Climate Finance Country Profile", "type": "Country Report",
             "country": {"name": "Senegal"}, "publicationDate": "2026-05-20"},
        ]
    }

    with patch("ai.ingest.httpx.get", return_value=fake_response), \
         patch("ai.rag.upsert_chunk", return_value=True) as mock_upsert:
        inserted = ingest.ingest_reports()

    assert inserted == 1
    blurb = mock_upsert.call_args.args[0]
    assert "Senegal - Climate Finance Country Profile" in blurb
    assert "Senegal" in blurb


def test_ingest_funding_aggregates_chunks_hero_stats_sectors_and_countries():
    hero_response = MagicMock()
    hero_response.json.return_value = {
        "countriesCovered": 53, "sectorsTracked": 5, "fundingRecords": 3005, "activeSources": 4,
    }
    sectors_response = MagicMock()
    sectors_response.json.return_value = {
        "data": [{"sector": "Renewable Energy", "amount": 1000000, "percentage": 40.0}]
    }
    countries_response = MagicMock()
    countries_response.json.return_value = {
        "data": [{"country": "Senegal", "isoCode": "SEN", "amount": 500000, "percentage": 20.0}]
    }

    with patch("ai.ingest.httpx.get", side_effect=[hero_response, sectors_response, countries_response]), \
         patch("ai.rag.upsert_chunk", return_value=True) as mock_upsert:
        inserted = ingest.ingest_funding_aggregates()

    assert inserted == 3  # 1 hero blurb + 1 sector blurb + 1 country blurb
    blurbs = [call.args[0] for call in mock_upsert.call_args_list]
    assert any("53 pays" in b for b in blurbs)
    assert any("Renewable Energy" in b for b in blurbs)
    assert any("Senegal" in b for b in blurbs)


def test_run_aggregates_all_three_sources():
    with patch("ai.ingest.ingest_markdown_files", return_value=2), \
         patch("ai.ingest.ingest_reports", return_value=1), \
         patch("ai.ingest.ingest_funding_aggregates", return_value=3):
        summary = ingest.run()

    assert summary == {
        "markdown_chunks_inserted": 2,
        "report_chunks_inserted": 1,
        "aggregate_chunks_inserted": 3,
    }
