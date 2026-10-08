"""Tests for collecte_bii's 3 task functions (_extraire, _transformer,
_publier) - same orchestration pattern as the other connectors' DAGs.
The business logic they call (fetch_bii_activities, parse_activity) is
already covered by pipeline/tests/test_bii_collector.py and is mocked
here.
"""
from unittest.mock import MagicMock, patch

from pipeline.dags.collecte_bii import _extraire, _publier, _transformer


def _make_context(pulls=None):
    ti = MagicMock()
    ti.xcom_pull.side_effect = lambda task_ids, key: (pulls or {}).get(key)
    return {"ds": "2026-10-08", "ti": ti}


def _pushed(context, key):
    for call in context["ti"].xcom_push.call_args_list:
        if call.kwargs.get("key") == key:
            return call.kwargs.get("value")
    return None


def test_extraire_fetches_every_activity_and_stages_to_minio():
    context = _make_context()

    with patch("pipeline.dags.collecte_bii.fetch_bii_activities", return_value=iter([{"iati_identifier": "A1"}])), \
         patch("pipeline.dags.collecte_bii.make_minio_client"), \
         patch("pipeline.dags.collecte_bii.upload_json") as mock_upload:
        _extraire(**context)

    call_args = mock_upload.call_args
    assert call_args[0][1] == "bronze/bii/2026-10-08/raw.json"
    assert call_args[0][2] == [{"iati_identifier": "A1"}]
    assert _pushed(context, "raw_path") == "bronze/bii/2026-10-08/raw.json"


def test_transformer_parses_every_raw_activity_and_skips_unparseable_ones():
    context = _make_context(pulls={"raw_path": "bronze/bii/2026-10-08/raw.json"})

    with patch("pipeline.dags.collecte_bii.make_minio_client"), \
         patch("pipeline.dags.collecte_bii.download_json", return_value=[{"iati_identifier": "A1"}, {"iati_identifier": "A2"}]), \
         patch("pipeline.dags.collecte_bii.parse_activity") as mock_parse, \
         patch("pipeline.dags.collecte_bii.upload_json") as mock_upload:
        mock_parse.side_effect = [{"source": "bii", "project_id": "A1"}, None]
        _transformer(**context)

    call_args = mock_upload.call_args
    assert call_args[0][1] == "silver/bii/2026-10-08/payloads.json"
    assert call_args[0][2] == [{"source": "bii", "project_id": "A1"}]
    assert _pushed(context, "payloads_path") == "silver/bii/2026-10-08/payloads.json"


def test_publier_sends_every_payload_and_reports_the_published_count():
    context = _make_context(pulls={"payloads_path": "silver/bii/2026-10-08/payloads.json"})
    mock_producer = MagicMock()
    payload = {"source": "bii", "project_id": "GB-COH-03877777-F317701", "country_iso": "EGY"}

    with patch("pipeline.dags.collecte_bii.make_minio_client"), \
         patch("pipeline.dags.collecte_bii.download_json", return_value=[payload]), \
         patch("pipeline.dags.collecte_bii.make_producer", return_value=mock_producer):
        _publier(**context)

    mock_producer.send.assert_called_once_with("nev.funding.raw", payload, key="bii:GB-COH-03877777-F317701:EGY")
    mock_producer.flush.assert_called_once()
    assert _pushed(context, "published_count") == 1
