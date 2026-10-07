"""Tests for collecte_norfund's 3 task functions (_extraire, _transformer,
_publier) - same orchestration pattern as the other connectors' DAGs. The
business logic they call (fetch_norfund_workbook, fetch_norfund_projects,
parse_project) is already covered by
pipeline/tests/test_norfund_collector.py and is mocked here.
"""
from unittest.mock import MagicMock, patch

from pipeline.dags.collecte_norfund import _extraire, _publier, _transformer


def _make_context(pulls=None):
    ti = MagicMock()
    ti.xcom_pull.side_effect = lambda task_ids, key: (pulls or {}).get(key)
    return {"ds": "2026-10-07", "ti": ti}


def _pushed(context, key):
    for call in context["ti"].xcom_push.call_args_list:
        if call.kwargs.get("key") == key:
            return call.kwargs.get("value")
    return None


def test_extraire_fetches_the_workbook_joins_every_project_and_stages_to_minio():
    context = _make_context()

    with patch("pipeline.dags.collecte_norfund.fetch_norfund_workbook", return_value=MagicMock()), \
         patch("pipeline.dags.collecte_norfund.fetch_norfund_projects", return_value=iter([{"investee": "Agua Imara"}])), \
         patch("pipeline.dags.collecte_norfund.make_minio_client"), \
         patch("pipeline.dags.collecte_norfund.upload_json") as mock_upload:
        _extraire(**context)

    call_args = mock_upload.call_args
    assert call_args[0][1] == "bronze/norfund/2026-10-07/raw.json"
    assert call_args[0][2] == [{"investee": "Agua Imara"}]
    assert _pushed(context, "raw_path") == "bronze/norfund/2026-10-07/raw.json"


def test_transformer_parses_every_raw_project_and_skips_unparseable_ones():
    context = _make_context(pulls={"raw_path": "bronze/norfund/2026-10-07/raw.json"})

    with patch("pipeline.dags.collecte_norfund.make_minio_client"), \
         patch("pipeline.dags.collecte_norfund.download_json", return_value=[{"investee": "1"}, {"investee": "2"}]), \
         patch("pipeline.dags.collecte_norfund.parse_project") as mock_parse, \
         patch("pipeline.dags.collecte_norfund.upload_json") as mock_upload:
        mock_parse.side_effect = [{"source": "norfund", "project_id": "1"}, None]
        _transformer(**context)

    call_args = mock_upload.call_args
    assert call_args[0][1] == "silver/norfund/2026-10-07/payloads.json"
    assert call_args[0][2] == [{"source": "norfund", "project_id": "1"}]
    assert _pushed(context, "payloads_path") == "silver/norfund/2026-10-07/payloads.json"


def test_publier_sends_every_payload_and_reports_the_published_count():
    context = _make_context(pulls={"payloads_path": "silver/norfund/2026-10-07/payloads.json"})
    mock_producer = MagicMock()
    payload = {"source": "norfund", "project_id": "Agua Imara", "country_iso": "GTM"}

    with patch("pipeline.dags.collecte_norfund.make_minio_client"), \
         patch("pipeline.dags.collecte_norfund.download_json", return_value=[payload]), \
         patch("pipeline.dags.collecte_norfund.make_producer", return_value=mock_producer):
        _publier(**context)

    mock_producer.send.assert_called_once_with("nev.funding.raw", payload, key="norfund:Agua Imara:GTM")
    mock_producer.flush.assert_called_once()
    assert _pushed(context, "published_count") == 1
