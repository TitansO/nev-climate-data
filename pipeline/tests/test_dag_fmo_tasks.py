"""Tests for collecte_fmo's 3 task functions (_extraire, _transformer,
_publier) - same orchestration pattern as collecte_afd.py (fetch the
EUR->USD rate once in `_extraire`, stage it alongside the raw
activities). The business logic they call (fetch_fmo_activities,
fetch_eur_to_usd_rate, parse_activity) is already covered by
pipeline/tests/test_fmo_collector.py and is mocked here.
"""
from unittest.mock import MagicMock, patch

from pipeline.dags.collecte_fmo import _extraire, _publier, _transformer


def _make_context(pulls=None):
    ti = MagicMock()
    ti.xcom_pull.side_effect = lambda task_ids, key: (pulls or {}).get(key)
    return {"ds": "2026-10-08", "ti": ti}


def _pushed(context, key):
    for call in context["ti"].xcom_push.call_args_list:
        if call.kwargs.get("key") == key:
            return call.kwargs.get("value")
    return None


def test_extraire_fetches_the_rate_and_the_full_portfolio_and_stages_to_minio():
    context = _make_context()

    with patch("pipeline.dags.collecte_fmo.fetch_eur_to_usd_rate", return_value=1.08), \
         patch("pipeline.dags.collecte_fmo.fetch_fmo_activities", return_value=iter([{"iati_identifier": "A1"}])), \
         patch("pipeline.dags.collecte_fmo.make_minio_client"), \
         patch("pipeline.dags.collecte_fmo.upload_json") as mock_upload:
        _extraire(**context)

    call_args = mock_upload.call_args
    assert call_args[0][1] == "bronze/fmo/2026-10-08/raw.json"
    assert call_args[0][2] == {"eur_to_usd_rate": 1.08, "activities": [{"iati_identifier": "A1"}]}
    assert _pushed(context, "raw_path") == "bronze/fmo/2026-10-08/raw.json"


def test_transformer_parses_every_activity_with_the_staged_rate():
    context = _make_context(pulls={"raw_path": "bronze/fmo/2026-10-08/raw.json"})
    staged_raw = {"eur_to_usd_rate": 1.08, "activities": [{"iati_identifier": "A1"}, {"iati_identifier": "A2"}]}

    with patch("pipeline.dags.collecte_fmo.make_minio_client"), \
         patch("pipeline.dags.collecte_fmo.download_json", return_value=staged_raw), \
         patch("pipeline.dags.collecte_fmo.parse_activity") as mock_parse, \
         patch("pipeline.dags.collecte_fmo.upload_json") as mock_upload:
        mock_parse.side_effect = [{"source": "fmo", "project_id": "A1"}, None]
        _transformer(**context)

    mock_parse.assert_any_call({"iati_identifier": "A1"}, 1.08)
    mock_parse.assert_any_call({"iati_identifier": "A2"}, 1.08)
    call_args = mock_upload.call_args
    assert call_args[0][1] == "silver/fmo/2026-10-08/payloads.json"
    assert call_args[0][2] == [{"source": "fmo", "project_id": "A1"}]
    assert _pushed(context, "payloads_path") == "silver/fmo/2026-10-08/payloads.json"


def test_publier_sends_every_payload_and_reports_the_published_count():
    context = _make_context(pulls={"payloads_path": "silver/fmo/2026-10-08/payloads.json"})
    mock_producer = MagicMock()
    payload = {"source": "fmo", "project_id": "NL-KVK-27078545-0000181014", "country_iso": "ETH"}

    with patch("pipeline.dags.collecte_fmo.make_minio_client"), \
         patch("pipeline.dags.collecte_fmo.download_json", return_value=[payload]), \
         patch("pipeline.dags.collecte_fmo.make_producer", return_value=mock_producer):
        _publier(**context)

    mock_producer.send.assert_called_once_with("nev.funding.raw", payload, key="fmo:NL-KVK-27078545-0000181014:ETH")
    mock_producer.flush.assert_called_once()
    assert _pushed(context, "published_count") == 1
