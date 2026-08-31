"""Tests for collecte_gcf's 3 task functions (_extraire, _transformer,
_publier) - the orchestration logic introduced by the 2026-08-31 multi-task
DAG refactor. The business logic they call (fetch_gcf_activities,
parse_activity) is already covered by pipeline/tests/test_gcf_collector.py
and is mocked here.
"""
from unittest.mock import MagicMock, patch

from pipeline.dags.collecte_gcf import _extraire, _publier, _transformer


def _make_context(pulls=None):
    ti = MagicMock()
    ti.xcom_pull.side_effect = lambda task_ids, key: (pulls or {}).get(key)
    return {"ds": "2026-08-31", "ti": ti}


def _pushed(context, key):
    for call in context["ti"].xcom_push.call_args_list:
        if call.kwargs.get("key") == key:
            return call.kwargs.get("value")
    return None


def test_extraire_fetches_the_whole_portfolio_and_stages_to_minio():
    context = _make_context()

    with patch("pipeline.dags.collecte_gcf.fetch_gcf_activities", return_value=iter([{"iati_identifier": "A1"}])), \
         patch("pipeline.dags.collecte_gcf.make_minio_client"), \
         patch("pipeline.dags.collecte_gcf.upload_json") as mock_upload:
        _extraire(**context)

    call_args = mock_upload.call_args
    assert call_args[0][1] == "bronze/gcf/2026-08-31/raw.json"
    assert call_args[0][2] == [{"iati_identifier": "A1"}]
    assert _pushed(context, "raw_path") == "bronze/gcf/2026-08-31/raw.json"


def test_transformer_flattens_every_activity_s_country_splits():
    context = _make_context(pulls={"raw_path": "bronze/gcf/2026-08-31/raw.json"})

    with patch("pipeline.dags.collecte_gcf.make_minio_client"), \
         patch("pipeline.dags.collecte_gcf.download_json", return_value=[{"iati_identifier": "A1"}]), \
         patch("pipeline.dags.collecte_gcf.parse_activity", return_value=[{"country_iso": "SEN"}, {"country_iso": "KEN"}]), \
         patch("pipeline.dags.collecte_gcf.upload_json") as mock_upload:
        _transformer(**context)

    call_args = mock_upload.call_args
    assert call_args[0][1] == "silver/gcf/2026-08-31/payloads.json"
    assert call_args[0][2] == [{"country_iso": "SEN"}, {"country_iso": "KEN"}]
    assert _pushed(context, "payloads_path") == "silver/gcf/2026-08-31/payloads.json"


def test_publier_sends_every_payload_and_reports_the_published_count():
    context = _make_context(pulls={"payloads_path": "silver/gcf/2026-08-31/payloads.json"})
    mock_producer = MagicMock()

    with patch("pipeline.dags.collecte_gcf.make_minio_client"), \
         patch("pipeline.dags.collecte_gcf.download_json", return_value=[{"a": 1}]), \
         patch("pipeline.dags.collecte_gcf.make_producer", return_value=mock_producer):
        _publier(**context)

    mock_producer.send.assert_called_once_with("nev.funding.raw", {"a": 1})
    mock_producer.flush.assert_called_once()
    assert _pushed(context, "published_count") == 1
