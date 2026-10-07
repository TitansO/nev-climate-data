"""Tests for collecte_proparco's 3 task functions (_extraire, _transformer,
_publier) - same orchestration pattern as collecte_afdb.py (fetch the
EUR->USD rate once in `_extraire`, stage it alongside the raw rows). The
business logic they call (fetch_proparco_projects, fetch_eur_to_usd_rate,
parse_project) is already covered by
pipeline/tests/test_proparco_collector.py and is mocked here.
"""
from unittest.mock import MagicMock, patch

from pipeline.dags.collecte_proparco import _extraire, _publier, _transformer


def _make_context(pulls=None):
    ti = MagicMock()
    ti.xcom_pull.side_effect = lambda task_ids, key: (pulls or {}).get(key)
    return {"ds": "2026-10-07", "ti": ti}


def _pushed(context, key):
    for call in context["ti"].xcom_push.call_args_list:
        if call.kwargs.get("key") == key:
            return call.kwargs.get("value")
    return None


def test_extraire_fetches_the_rate_and_every_project_and_stages_to_minio():
    context = _make_context()

    with patch("pipeline.dags.collecte_proparco.fetch_eur_to_usd_rate", return_value=1.08), \
         patch("pipeline.dags.collecte_proparco.fetch_proparco_projects", return_value=iter([{"id_projet": "PTR1081"}])), \
         patch("pipeline.dags.collecte_proparco.make_minio_client"), \
         patch("pipeline.dags.collecte_proparco.upload_json") as mock_upload:
        _extraire(**context)

    call_args = mock_upload.call_args
    assert call_args[0][1] == "bronze/proparco/2026-10-07/raw.json"
    assert call_args[0][2] == {"eur_to_usd_rate": 1.08, "projects": [{"id_projet": "PTR1081"}]}
    assert _pushed(context, "raw_path") == "bronze/proparco/2026-10-07/raw.json"


def test_transformer_parses_every_project_with_the_staged_rate_and_skips_unparseable_ones():
    context = _make_context(pulls={"raw_path": "bronze/proparco/2026-10-07/raw.json"})
    staged_raw = {"eur_to_usd_rate": 1.08, "projects": [{"id_projet": "P1"}, {"id_projet": "P2"}]}

    with patch("pipeline.dags.collecte_proparco.make_minio_client"), \
         patch("pipeline.dags.collecte_proparco.download_json", return_value=staged_raw), \
         patch("pipeline.dags.collecte_proparco.parse_project") as mock_parse, \
         patch("pipeline.dags.collecte_proparco.upload_json") as mock_upload:
        mock_parse.side_effect = [{"source": "proparco", "project_id": "P1"}, None]
        _transformer(**context)

    mock_parse.assert_any_call({"id_projet": "P1"}, 1.08)
    mock_parse.assert_any_call({"id_projet": "P2"}, 1.08)
    call_args = mock_upload.call_args
    assert call_args[0][1] == "silver/proparco/2026-10-07/payloads.json"
    assert call_args[0][2] == [{"source": "proparco", "project_id": "P1"}]
    assert _pushed(context, "payloads_path") == "silver/proparco/2026-10-07/payloads.json"


def test_publier_sends_every_payload_and_reports_the_published_count():
    context = _make_context(pulls={"payloads_path": "silver/proparco/2026-10-07/payloads.json"})
    mock_producer = MagicMock()
    payload = {"source": "proparco", "project_id": "PTR1081", "country_iso": "TUR"}

    with patch("pipeline.dags.collecte_proparco.make_minio_client"), \
         patch("pipeline.dags.collecte_proparco.download_json", return_value=[payload]), \
         patch("pipeline.dags.collecte_proparco.make_producer", return_value=mock_producer):
        _publier(**context)

    mock_producer.send.assert_called_once_with("nev.funding.raw", payload, key="proparco:PTR1081:TUR")
    mock_producer.flush.assert_called_once()
    assert _pushed(context, "published_count") == 1
