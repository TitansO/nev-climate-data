"""Tests for collecte_dfc's 3 task functions (_extraire, _transformer,
_publier) - same orchestration pattern as the other connectors' DAGs. The
business logic they call (fetch_dfc_projects, parse_project) is already
covered by pipeline/tests/test_dfc_collector.py and is mocked here.
"""
from unittest.mock import MagicMock, patch

from pipeline.dags.collecte_dfc import _extraire, _publier, _transformer


def _make_context(pulls=None):
    ti = MagicMock()
    ti.xcom_pull.side_effect = lambda task_ids, key: (pulls or {}).get(key)
    return {"ds": "2026-10-06", "ti": ti}


def _pushed(context, key):
    for call in context["ti"].xcom_push.call_args_list:
        if call.kwargs.get("key") == key:
            return call.kwargs.get("value")
    return None


def test_extraire_fetches_every_project_and_stages_to_minio():
    context = _make_context()

    with patch("pipeline.dags.collecte_dfc.fetch_dfc_projects", return_value=iter([{"Project Number": 1}])), \
         patch("pipeline.dags.collecte_dfc.make_minio_client"), \
         patch("pipeline.dags.collecte_dfc.upload_json") as mock_upload:
        _extraire(**context)

    call_args = mock_upload.call_args
    assert call_args[0][1] == "bronze/dfc/2026-10-06/raw.json"
    assert call_args[0][2] == [{"Project Number": 1}]
    assert _pushed(context, "raw_path") == "bronze/dfc/2026-10-06/raw.json"


def test_transformer_parses_every_raw_project_and_skips_unparseable_ones():
    context = _make_context(pulls={"raw_path": "bronze/dfc/2026-10-06/raw.json"})

    with patch("pipeline.dags.collecte_dfc.make_minio_client"), \
         patch("pipeline.dags.collecte_dfc.download_json", return_value=[{"Project Number": 1}, {"Project Number": 2}]), \
         patch("pipeline.dags.collecte_dfc.parse_project") as mock_parse, \
         patch("pipeline.dags.collecte_dfc.upload_json") as mock_upload:
        mock_parse.side_effect = [{"source": "dfc", "project_id": "1"}, None]
        _transformer(**context)

    call_args = mock_upload.call_args
    assert call_args[0][1] == "silver/dfc/2026-10-06/payloads.json"
    assert call_args[0][2] == [{"source": "dfc", "project_id": "1"}]
    assert _pushed(context, "payloads_path") == "silver/dfc/2026-10-06/payloads.json"


def test_publier_sends_every_payload_and_reports_the_published_count():
    context = _make_context(pulls={"payloads_path": "silver/dfc/2026-10-06/payloads.json"})
    mock_producer = MagicMock()
    payload = {"source": "dfc", "project_id": "P1", "country_iso": "KEN"}

    with patch("pipeline.dags.collecte_dfc.make_minio_client"), \
         patch("pipeline.dags.collecte_dfc.download_json", return_value=[payload]), \
         patch("pipeline.dags.collecte_dfc.make_producer", return_value=mock_producer):
        _publier(**context)

    mock_producer.send.assert_called_once_with("nev.funding.raw", payload, key="dfc:P1:KEN")
    mock_producer.flush.assert_called_once()
    assert _pushed(context, "published_count") == 1
