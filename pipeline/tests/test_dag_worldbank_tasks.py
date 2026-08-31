"""Tests for collecte_worldbank's 3 task functions (_extraire, _transformer,
_publier) - the orchestration logic introduced by the 2026-08-31 multi-task
DAG refactor. The business logic they call (fetch_projects_for_country,
parse_project) is already covered by pipeline/tests/test_world_bank_collector.py
and is mocked here.
"""
from unittest.mock import MagicMock, patch

from pipeline.dags.collecte_worldbank import _extraire, _publier, _transformer


def _make_context(pulls=None):
    ti = MagicMock()
    ti.xcom_pull.side_effect = lambda task_ids, key: (pulls or {}).get(key)
    return {"ds": "2026-08-31", "ti": ti}


def _pushed(context, key):
    for call in context["ti"].xcom_push.call_args_list:
        if call.kwargs.get("key") == key:
            return call.kwargs.get("value")
    return None


def test_extraire_fetches_projects_for_every_convertible_country_and_stages_to_minio():
    mock_connection = MagicMock()
    mock_cursor = MagicMock()
    mock_cursor.fetchall.return_value = [("SEN",), ("XXX",)]  # XXX: not a real ISO code
    mock_connection.cursor.return_value.__enter__.return_value = mock_cursor
    context = _make_context()

    with patch("pipeline.dags.collecte_worldbank.get_connection", return_value=mock_connection), \
         patch("pipeline.dags.collecte_worldbank.fetch_projects_for_country") as mock_fetch, \
         patch("pipeline.dags.collecte_worldbank.make_minio_client"), \
         patch("pipeline.dags.collecte_worldbank.upload_json") as mock_upload:
        mock_fetch.return_value = [{"id": "P001"}]
        _extraire(**context)

    # SEN converts to alpha-2 "SN" and is fetched; "XXX" isn't a real ISO code and is skipped
    mock_fetch.assert_called_once_with("SN")
    call_args = mock_upload.call_args
    assert call_args[0][1] == "bronze/worldbank/2026-08-31/raw.json"
    assert call_args[0][2] == [{"id": "P001"}]
    assert _pushed(context, "raw_path") == "bronze/worldbank/2026-08-31/raw.json"


def test_transformer_parses_every_raw_project_and_skips_unparseable_ones():
    context = _make_context(pulls={"raw_path": "bronze/worldbank/2026-08-31/raw.json"})

    with patch("pipeline.dags.collecte_worldbank.make_minio_client"), \
         patch("pipeline.dags.collecte_worldbank.download_json", return_value=[{"id": "P001"}, {"id": "P002"}]), \
         patch("pipeline.dags.collecte_worldbank.parse_project") as mock_parse, \
         patch("pipeline.dags.collecte_worldbank.upload_json") as mock_upload:
        mock_parse.side_effect = [{"source": "world_bank", "project_id": "P001"}, None]
        _transformer(**context)

    call_args = mock_upload.call_args
    assert call_args[0][1] == "silver/worldbank/2026-08-31/payloads.json"
    assert call_args[0][2] == [{"source": "world_bank", "project_id": "P001"}]
    assert _pushed(context, "payloads_path") == "silver/worldbank/2026-08-31/payloads.json"


def test_publier_sends_every_payload_and_reports_the_published_count():
    context = _make_context(pulls={"payloads_path": "silver/worldbank/2026-08-31/payloads.json"})
    mock_producer = MagicMock()

    with patch("pipeline.dags.collecte_worldbank.make_minio_client"), \
         patch("pipeline.dags.collecte_worldbank.download_json", return_value=[{"a": 1}, {"a": 2}]), \
         patch("pipeline.dags.collecte_worldbank.make_producer", return_value=mock_producer):
        _publier(**context)

    assert mock_producer.send.call_count == 2
    mock_producer.send.assert_any_call("nev.funding.raw", {"a": 1})
    mock_producer.flush.assert_called_once()
    assert _pushed(context, "published_count") == 2
