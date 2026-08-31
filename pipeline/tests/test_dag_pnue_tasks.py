"""Tests for collecte_pnue's 3 task functions (_extraire, _transformer,
_publier) - the orchestration logic introduced by the 2026-08-31 multi-task
DAG refactor. The business logic they call (country_iso3_to_m49,
fetch_emissions_for_country, parse_emission) is already covered by
pipeline/tests/test_pnue_collector.py and is mocked here.
"""
from unittest.mock import MagicMock, patch

from pipeline.dags.collecte_pnue import _extraire, _publier, _transformer


def _make_context(pulls=None):
    ti = MagicMock()
    ti.xcom_pull.side_effect = lambda task_ids, key: (pulls or {}).get(key)
    return {"ds": "2026-08-31", "ti": ti}


def _pushed(context, key):
    for call in context["ti"].xcom_push.call_args_list:
        if call.kwargs.get("key") == key:
            return call.kwargs.get("value")
    return None


def test_extraire_fetches_emissions_for_every_convertible_country_and_stages_to_minio():
    mock_connection = MagicMock()
    mock_cursor = MagicMock()
    mock_cursor.fetchall.return_value = [("SEN",), ("XXX",)]  # XXX: not a real ISO code
    mock_connection.cursor.return_value.__enter__.return_value = mock_cursor
    context = _make_context()

    with patch("pipeline.dags.collecte_pnue.get_connection", return_value=mock_connection), \
         patch("pipeline.dags.collecte_pnue.country_iso3_to_m49") as mock_m49, \
         patch("pipeline.dags.collecte_pnue.fetch_emissions_for_country") as mock_fetch, \
         patch("pipeline.dags.collecte_pnue.make_minio_client"), \
         patch("pipeline.dags.collecte_pnue.upload_json") as mock_upload:
        mock_m49.side_effect = lambda iso: "686" if iso == "SEN" else None
        mock_fetch.return_value = [{"value": 3.52}]
        _extraire(**context)

    mock_fetch.assert_called_once_with("686")  # only SEN resolves to a real M49 code
    call_args = mock_upload.call_args
    assert call_args[0][1] == "bronze/pnue/2026-08-31/raw.json"
    assert call_args[0][2] == [{"country_iso": "SEN", "row": {"value": 3.52}}]
    assert _pushed(context, "raw_path") == "bronze/pnue/2026-08-31/raw.json"


def test_transformer_parses_every_raw_row_with_its_own_country_iso():
    context = _make_context(pulls={"raw_path": "bronze/pnue/2026-08-31/raw.json"})
    staged_raw = [{"country_iso": "SEN", "row": {"value": 3.52}}, {"country_iso": "SEN", "row": {"value": 0.5}}]

    with patch("pipeline.dags.collecte_pnue.make_minio_client"), \
         patch("pipeline.dags.collecte_pnue.download_json", return_value=staged_raw), \
         patch("pipeline.dags.collecte_pnue.parse_emission") as mock_parse, \
         patch("pipeline.dags.collecte_pnue.upload_json") as mock_upload:
        mock_parse.side_effect = [{"source": "pnue", "country_iso": "SEN"}, None]
        _transformer(**context)

    mock_parse.assert_any_call({"value": 3.52}, "SEN")
    mock_parse.assert_any_call({"value": 0.5}, "SEN")
    call_args = mock_upload.call_args
    assert call_args[0][1] == "silver/pnue/2026-08-31/payloads.json"
    assert call_args[0][2] == [{"source": "pnue", "country_iso": "SEN"}]
    assert _pushed(context, "payloads_path") == "silver/pnue/2026-08-31/payloads.json"


def test_publier_sends_every_payload_to_the_emissions_topic():
    context = _make_context(pulls={"payloads_path": "silver/pnue/2026-08-31/payloads.json"})
    mock_producer = MagicMock()

    with patch("pipeline.dags.collecte_pnue.make_minio_client"), \
         patch("pipeline.dags.collecte_pnue.download_json", return_value=[{"a": 1}]), \
         patch("pipeline.dags.collecte_pnue.make_producer", return_value=mock_producer):
        _publier(**context)

    mock_producer.send.assert_called_once_with("nev.emissions.raw", {"a": 1})
    mock_producer.flush.assert_called_once()
    assert _pushed(context, "published_count") == 1
