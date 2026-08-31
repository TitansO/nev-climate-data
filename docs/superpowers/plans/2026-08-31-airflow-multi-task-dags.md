# Refactoring des 5 DAGs Volet B en tâches réelles reliées — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Faire apparaître, dans la vue "Graph" d'Airflow, une vraie chaîne de 3 tâches reliées (`extraire >> transformer >> publier`) pour chacun des 5 DAGs Volet B — sans changer aucun résultat final (mêmes messages Kafka, mêmes clés de payload, même dédoublonnage).

**Architecture:** Chaque DAG appelle désormais 3 fonctions au lieu d'une, chacune correspondant aux frontières de fonctions déjà existantes dans son collecteur (`fetch_*` / `parse_*`+`build_payloads` / `producer.send`). Les données intermédiaires transitent exclusivement par MinIO (zone `bronze/` puis `silver/`) — seul un chemin d'objet (texte court) circule en XCom entre les tâches.

**Tech Stack:** Apache Airflow 2.9 (PythonOperator, XCom), MinIO (`minio` Python client, déjà une dépendance depuis B1.5), Kafka (`kafka-python-ng`, inchangé), pytest + `unittest.mock`.

## Global Constraints

- Bucket MinIO : `nev-climate-data` (déjà créé, aucune migration nécessaire).
- Aucune nouvelle dépendance Python — `minio`, `google-genai`, `pypdf` sont déjà dans `pipeline/requirements.txt` depuis B1.5. Aucun changement à `docker-compose.yml`.
- `task_id` identiques dans les 5 DAGs : `extraire`, `transformer`, `publier`.
- Chemins d'objets bronze/silver : `bronze/<connecteur>/<ds>/raw.json` et `silver/<connecteur>/<ds>/payloads.json`, où `<ds>` est `context["ds"]` (date d'exécution Airflow). Exception `extraction_pdf` : voir Tâche 7 (chemins basés sur le hash du document, pas sur `<ds>`, pour rester cohérent avec le cache existant).
- Aucune modification de `pipeline/processors/funding_validator.py` ni `pipeline/processors/emission_validator.py`.
- Aucune modification des fonctions `fetch_*`/`parse_*`/`build_payloads`/`collect_and_publish` existantes dans les 5 collecteurs (`pipeline/collectors/*.py`) — les DAGs importent directement les fonctions `fetch_*`/`parse_*`/`build_payloads` au lieu de l'agrégat `collect_and_publish`. `collect_and_publish` reste en place, inutilisé par le DAG mais toujours testé par sa suite existante — c'est un point d'entrée manuel valide (backfill, exécution locale hors Airflow), pas du code mort à supprimer.
- Le `funding-validator` construit son image via `COPY pipeline/ ./pipeline/` (pas de montage de volume) : **après toute modification d'un fichier sous `pipeline/`, exécuter `docker compose build funding-validator` avant de lancer les tests** via `docker compose run --rm funding-validator pytest ...`. Le service `airflow`, lui, monte `./pipeline:/opt/airflow/pipeline` en volume — les DAGs y sont visibles immédiatement sans rebuild.
- Tous les commits sont poussés directement sur `developp` (convention déjà établie sur ce projet — pas de branche de fonctionnalité séparée), en gérant un éventuel push concurrent (`git pull --rebase`) comme pour B1.1-B1.5.

---

### Tâche 1 : Module partagé `pipeline/common/minio_staging.py`

**Files:**
- Create: `pipeline/common/minio_staging.py`
- Test: `pipeline/tests/test_minio_staging.py`

**Interfaces:**
- Produces: `MINIO_BUCKET: str`, `make_minio_client() -> Minio`, `upload_bytes(client: Minio, object_path: str, data: bytes) -> None`, `download_bytes(client: Minio, object_path: str) -> bytes`, `upload_json(client: Minio, object_path: str, data: Any) -> None`, `download_json(client: Minio, object_path: str) -> Any` — utilisés par toutes les tâches suivantes.

- [ ] **Step 1: Write the failing test**

Create `pipeline/tests/test_minio_staging.py`:

```python
"""Tests for the generic MinIO staging helpers (bronze/silver transit
between Airflow tasks) - see the 2026-08-31 multi-task DAG refactor spec,
decision 3.
"""
from unittest.mock import MagicMock

from pipeline.common.minio_staging import (
    MINIO_BUCKET,
    download_bytes,
    download_json,
    upload_bytes,
    upload_json,
)


def test_upload_bytes_creates_the_bucket_if_missing():
    mock_client = MagicMock()
    mock_client.bucket_exists.return_value = False

    upload_bytes(mock_client, "bronze/test/raw.json", b"some-bytes")

    mock_client.make_bucket.assert_called_once_with(MINIO_BUCKET)
    mock_client.put_object.assert_called_once()
    call_args = mock_client.put_object.call_args
    assert call_args[0][0] == MINIO_BUCKET
    assert call_args[0][1] == "bronze/test/raw.json"


def test_upload_bytes_skips_bucket_creation_when_it_already_exists():
    mock_client = MagicMock()
    mock_client.bucket_exists.return_value = True

    upload_bytes(mock_client, "bronze/test/raw.json", b"some-bytes")

    mock_client.make_bucket.assert_not_called()


def test_download_bytes_reads_and_releases_the_response():
    mock_client = MagicMock()
    mock_response = MagicMock()
    mock_response.read.return_value = b"downloaded-bytes"
    mock_client.get_object.return_value = mock_response

    result = download_bytes(mock_client, "bronze/test/raw.json")

    assert result == b"downloaded-bytes"
    mock_client.get_object.assert_called_once_with(MINIO_BUCKET, "bronze/test/raw.json")
    mock_response.close.assert_called_once()
    mock_response.release_conn.assert_called_once()


def test_upload_json_serializes_and_uploads():
    mock_client = MagicMock()
    mock_client.bucket_exists.return_value = True

    upload_json(mock_client, "silver/test/payloads.json", [{"a": 1}])

    call_args = mock_client.put_object.call_args
    uploaded_stream = call_args[0][2]
    assert uploaded_stream.read() == b'[{"a": 1}]'


def test_download_json_reads_and_deserializes():
    mock_client = MagicMock()
    mock_response = MagicMock()
    mock_response.read.return_value = b'[{"a": 1}]'
    mock_client.get_object.return_value = mock_response

    result = download_json(mock_client, "silver/test/payloads.json")

    assert result == [{"a": 1}]
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose run --rm funding-validator pytest pipeline/tests/test_minio_staging.py -v`
Expected: FAIL with `ModuleNotFoundError: No module named 'pipeline.common.minio_staging'` (the image still has the old `pipeline/` copied in — that's fine, the ImportError itself is the expected failure).

- [ ] **Step 3: Write the implementation**

Create `pipeline/common/minio_staging.py`:

```python
"""Generic MinIO staging helpers shared by every Volet B DAG - upload and
download of raw bytes and JSON blobs to the bronze/silver zones between
Airflow tasks. See the 2026-08-31 multi-task DAG refactor spec, decisions 2
and 3. Has no connector-specific knowledge (object-path conventions live in
each DAG file).
"""
from __future__ import annotations

import io
import json
import os
from typing import Any

from minio import Minio

MINIO_BUCKET = "nev-climate-data"


def make_minio_client() -> Minio:
    return Minio(
        os.environ.get("MINIO_ENDPOINT", "minio:9000"),
        access_key=os.environ["MINIO_ROOT_USER"],
        secret_key=os.environ["MINIO_ROOT_PASSWORD"],
        secure=False,
    )


def upload_bytes(client: Minio, object_path: str, data: bytes) -> None:
    if not client.bucket_exists(MINIO_BUCKET):
        client.make_bucket(MINIO_BUCKET)
    client.put_object(MINIO_BUCKET, object_path, io.BytesIO(data), length=len(data))


def download_bytes(client: Minio, object_path: str) -> bytes:
    response = client.get_object(MINIO_BUCKET, object_path)
    try:
        return response.read()
    finally:
        response.close()
        response.release_conn()


def upload_json(client: Minio, object_path: str, data: Any) -> None:
    upload_bytes(client, object_path, json.dumps(data).encode("utf-8"))


def download_json(client: Minio, object_path: str) -> Any:
    return json.loads(download_bytes(client, object_path).decode("utf-8"))
```

- [ ] **Step 4: Rebuild the funding-validator image and run the test again**

Run: `docker compose build funding-validator && docker compose run --rm funding-validator pytest pipeline/tests/test_minio_staging.py -v`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add pipeline/common/minio_staging.py pipeline/tests/test_minio_staging.py
git commit -m "feat: add generic MinIO staging helpers for bronze/silver DAG transit"
git pull --rebase
git push
```

---

### Tâche 2 : `pipeline/common/pdf_extraction.py` réutilise `minio_staging`

**Files:**
- Modify: `pipeline/common/pdf_extraction.py`

**Interfaces:**
- Consumes: `MINIO_BUCKET`, `make_minio_client`, `upload_bytes` from Task 1 (`pipeline.common.minio_staging`).
- Produces: unchanged external surface — `pdf_extraction.py` still exports `MINIO_BUCKET`, `make_minio_client`, `upload_to_minio` (same names, same signatures) alongside its existing `sha256_hash`, `slice_pdf_pages`, `extract_json_via_gemini`, `is_already_processed`, `record_processed`.

This is a refactor of already-tested code — no new test needed, but the existing suite must stay green with zero test-file changes (proves the external contract didn't shift).

- [ ] **Step 1: Run the existing test suite before changing anything (baseline)**

Run: `docker compose run --rm funding-validator pytest pipeline/tests/test_pdf_extraction.py -v`
Expected: PASS (10 tests) — confirms the current baseline before the refactor.

- [ ] **Step 2: Remove the duplicated MinIO helpers and delegate to `minio_staging`**

In `pipeline/common/pdf_extraction.py`:
- Remove the line `from minio import Minio`.
- Remove the module-level `MINIO_BUCKET = "nev-climate-data"` definition.
- Remove the `def make_minio_client() -> Minio: ...` function.
- Remove the `def upload_to_minio(client: Minio, object_path: str, data: bytes) -> None: ...` function.
- Add, alongside the other imports at the top of the file:

```python
from pipeline.common.minio_staging import MINIO_BUCKET, make_minio_client
from pipeline.common.minio_staging import upload_bytes as upload_to_minio
```

The rest of the file (`sha256_hash`, `slice_pdf_pages`, `extract_json_via_gemini`, `is_already_processed`, `record_processed`, and all the `GEMINI_*` constants) is untouched.

- [ ] **Step 3: Rebuild and run the same test file again**

Run: `docker compose build funding-validator && docker compose run --rm funding-validator pytest pipeline/tests/test_pdf_extraction.py -v`
Expected: PASS (10 tests, unchanged) — proves the refactor didn't shift behavior. `MINIO_BUCKET` and `upload_to_minio` are still importable from `pipeline.common.pdf_extraction` exactly as the test file already expects (no edit to the test file itself).

- [ ] **Step 4: Commit**

```bash
git add pipeline/common/pdf_extraction.py
git commit -m "refactor: pdf_extraction reuses the shared minio_staging helpers"
git pull --rebase
git push
```

---

### Tâche 3 : `collecte_worldbank` — 3 tâches reliées

**Files:**
- Modify: `pipeline/dags/collecte_worldbank.py`
- Test: `pipeline/tests/test_dag_worldbank_tasks.py`

**Interfaces:**
- Consumes: `make_minio_client`, `upload_json`, `download_json` from Task 1; `fetch_projects_for_country(country_iso: str) -> Iterator[dict]` and `parse_project(project: dict) -> dict | None` from `pipeline.collectors.world_bank` (unchanged); `get_connection()` from `pipeline.common.db`; `make_producer()` from `pipeline.common.kafka_client`.
- Produces: `_extraire(**context) -> None`, `_transformer(**context) -> None`, `_publier(**context) -> None` in `pipeline.dags.collecte_worldbank` — pushes XCom keys `raw_path` (from `extraire`), `payloads_path` (from `transformer`), `published_count` (from `publier`, same key name as before the refactor).

- [ ] **Step 1: Write the failing test**

Create `pipeline/tests/test_dag_worldbank_tasks.py`:

```python
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose run --rm funding-validator pytest pipeline/tests/test_dag_worldbank_tasks.py -v`
Expected: FAIL with `ImportError: cannot import name '_extraire' from 'pipeline.dags.collecte_worldbank'` (the DAG file still only defines `_collect`).

- [ ] **Step 3: Rewrite the DAG file**

Replace the full content of `pipeline/dags/collecte_worldbank.py`:

```python
"""Airflow DAG: quarterly collection of World Bank climate-themed project
financing for every country NEV tracks - see the B1.1 spec, decision 1 (the
country list comes from NEV's own `country` table, not a hard-coded list)
and decision 8 (quarterly schedule). Split into 3 linked tasks (extraire >>
transformer >> publier) - see the 2026-08-31 multi-task DAG refactor spec.
"""
from datetime import datetime, timedelta

import pycountry
from airflow import DAG
from airflow.operators.python import PythonOperator

from pipeline.collectors.world_bank import fetch_projects_for_country, parse_project
from pipeline.common.db import get_connection
from pipeline.common.kafka_client import make_producer
from pipeline.common.minio_staging import download_json, make_minio_client, upload_json

default_args = {
    "owner": "nev-climate-data",
    "retries": 3,
    "retry_delay": timedelta(minutes=5),
}


def _extraire(**context) -> None:
    connection = get_connection()
    try:
        with connection.cursor() as cursor:
            cursor.execute("SELECT iso_code FROM country ORDER BY iso_code")
            country_isos_alpha3 = [row[0] for row in cursor.fetchall()]
    finally:
        connection.close()

    # `country.iso_code` is alpha-3 (see Country.php, A1.3), but the World Bank API's
    # `countrycode_exact` filter expects alpha-2 (verified live: querying with "SEN" returns 0
    # projects, "SN" returns 264 - confirmed while running this DAG end-to-end for real). A
    # country pycountry doesn't recognize is skipped rather than passed through unconverted - an
    # alpha-3 code sent to the API would just as silently return 0 projects for it.
    country_isos = []
    for alpha3 in country_isos_alpha3:
        country = pycountry.countries.get(alpha_3=alpha3)
        if country is not None:
            country_isos.append(country.alpha_2)

    raw_projects = []
    for country_iso in country_isos:
        raw_projects.extend(fetch_projects_for_country(country_iso))

    object_path = f"bronze/worldbank/{context['ds']}/raw.json"
    upload_json(make_minio_client(), object_path, raw_projects)
    context["ti"].xcom_push(key="raw_path", value=object_path)


def _transformer(**context) -> None:
    raw_path = context["ti"].xcom_pull(task_ids="extraire", key="raw_path")
    raw_projects = download_json(make_minio_client(), raw_path)

    payloads = []
    for raw_project in raw_projects:
        payload = parse_project(raw_project)
        if payload is not None:
            payloads.append(payload)

    object_path = f"silver/worldbank/{context['ds']}/payloads.json"
    upload_json(make_minio_client(), object_path, payloads)
    context["ti"].xcom_push(key="payloads_path", value=object_path)


def _publier(**context) -> None:
    payloads_path = context["ti"].xcom_pull(task_ids="transformer", key="payloads_path")
    payloads = download_json(make_minio_client(), payloads_path)

    producer = make_producer()
    for payload in payloads:
        producer.send("nev.funding.raw", payload)
    producer.flush()
    context["ti"].xcom_push(key="published_count", value=len(payloads))


with DAG(
    dag_id="collecte_worldbank",
    default_args=default_args,
    schedule_interval="0 3 1 1,4,7,10 *",  # 1er jour de chaque trimestre, 03h00
    start_date=datetime(2026, 1, 1),
    catchup=False,
    tags=["b1.1", "collecte", "world-bank"],
) as dag:
    extraire = PythonOperator(task_id="extraire", python_callable=_extraire)
    transformer = PythonOperator(task_id="transformer", python_callable=_transformer)
    publier = PythonOperator(task_id="publier", python_callable=_publier)

    extraire >> transformer >> publier
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker compose build funding-validator && docker compose run --rm funding-validator pytest pipeline/tests/test_dag_worldbank_tasks.py pipeline/tests/test_world_bank_collector.py -v`
Expected: PASS (3 new tests + the existing collector suite unchanged and green — proves `fetch_projects_for_country`/`parse_project` weren't touched).

- [ ] **Step 5: Commit**

```bash
git add pipeline/dags/collecte_worldbank.py pipeline/tests/test_dag_worldbank_tasks.py
git commit -m "refactor: split collecte_worldbank into extraire/transformer/publier tasks"
git pull --rebase
git push
```

---

### Tâche 4 : `collecte_gcf` — 3 tâches reliées

**Files:**
- Modify: `pipeline/dags/collecte_gcf.py`
- Test: `pipeline/tests/test_dag_gcf_tasks.py`

**Interfaces:**
- Consumes: `make_minio_client`, `upload_json`, `download_json` from Task 1; `fetch_gcf_activities() -> Iterator[dict]` and `parse_activity(activity: dict) -> list[dict]` from `pipeline.collectors.gcf` (unchanged); `make_producer()` from `pipeline.common.kafka_client`.
- Produces: `_extraire`, `_transformer`, `_publier` in `pipeline.dags.collecte_gcf`, same XCom key pattern as Task 3.

- [ ] **Step 1: Write the failing test**

Create `pipeline/tests/test_dag_gcf_tasks.py`:

```python
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose run --rm funding-validator pytest pipeline/tests/test_dag_gcf_tasks.py -v`
Expected: FAIL with `ImportError: cannot import name '_extraire' from 'pipeline.dags.collecte_gcf'`.

- [ ] **Step 3: Rewrite the DAG file**

Replace the full content of `pipeline/dags/collecte_gcf.py`:

```python
"""Airflow DAG: monthly collection of Green Climate Fund (GCF) project
financing via the IATI Datastore - see the B1.2 spec, decision 1 (a single
request covers GCF's entire IATI-published portfolio). Split into 3 linked
tasks (extraire >> transformer >> publier) - see the 2026-08-31 multi-task
DAG refactor spec.
"""
from datetime import datetime, timedelta

from airflow import DAG
from airflow.operators.python import PythonOperator

from pipeline.collectors.gcf import fetch_gcf_activities, parse_activity
from pipeline.common.kafka_client import make_producer
from pipeline.common.minio_staging import download_json, make_minio_client, upload_json

default_args = {
    "owner": "nev-climate-data",
    "retries": 3,
    "retry_delay": timedelta(minutes=5),
}


def _extraire(**context) -> None:
    raw_activities = list(fetch_gcf_activities())

    object_path = f"bronze/gcf/{context['ds']}/raw.json"
    upload_json(make_minio_client(), object_path, raw_activities)
    context["ti"].xcom_push(key="raw_path", value=object_path)


def _transformer(**context) -> None:
    raw_path = context["ti"].xcom_pull(task_ids="extraire", key="raw_path")
    raw_activities = download_json(make_minio_client(), raw_path)

    payloads = []
    for raw_activity in raw_activities:
        payloads.extend(parse_activity(raw_activity))

    object_path = f"silver/gcf/{context['ds']}/payloads.json"
    upload_json(make_minio_client(), object_path, payloads)
    context["ti"].xcom_push(key="payloads_path", value=object_path)


def _publier(**context) -> None:
    payloads_path = context["ti"].xcom_pull(task_ids="transformer", key="payloads_path")
    payloads = download_json(make_minio_client(), payloads_path)

    producer = make_producer()
    for payload in payloads:
        producer.send("nev.funding.raw", payload)
    producer.flush()
    context["ti"].xcom_push(key="published_count", value=len(payloads))


with DAG(
    dag_id="collecte_gcf",
    default_args=default_args,
    schedule_interval="0 3 1 * *",  # 1er jour de chaque mois, 03h00
    start_date=datetime(2026, 1, 1),
    catchup=False,
    tags=["b1.2", "collecte", "gcf"],
) as dag:
    extraire = PythonOperator(task_id="extraire", python_callable=_extraire)
    transformer = PythonOperator(task_id="transformer", python_callable=_transformer)
    publier = PythonOperator(task_id="publier", python_callable=_publier)

    extraire >> transformer >> publier
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker compose build funding-validator && docker compose run --rm funding-validator pytest pipeline/tests/test_dag_gcf_tasks.py pipeline/tests/test_gcf_collector.py -v`
Expected: PASS (3 new tests + the existing collector suite unchanged and green).

- [ ] **Step 5: Commit**

```bash
git add pipeline/dags/collecte_gcf.py pipeline/tests/test_dag_gcf_tasks.py
git commit -m "refactor: split collecte_gcf into extraire/transformer/publier tasks"
git pull --rebase
git push
```

---

### Tâche 5 : `collecte_afdb` — 3 tâches reliées

**Files:**
- Modify: `pipeline/dags/collecte_afdb.py`
- Test: `pipeline/tests/test_dag_afdb_tasks.py`

**Interfaces:**
- Consumes: `make_minio_client`, `upload_json`, `download_json` from Task 1; `fetch_afdb_activities() -> Iterator[dict]`, `fetch_xdr_to_usd_rate() -> float`, `parse_activity(activity: dict, xdr_to_usd_rate: float) -> dict | None` from `pipeline.collectors.afdb` (unchanged); `make_producer()` from `pipeline.common.kafka_client`.
- Produces: `_extraire`, `_transformer`, `_publier` in `pipeline.dags.collecte_afdb`. The bronze payload is `{"xdr_to_usd_rate": float, "activities": list[dict]}`, not a plain list (only DAG in this batch whose bronze object is a dict, since the exchange rate must travel alongside the raw activities).

- [ ] **Step 1: Write the failing test**

Create `pipeline/tests/test_dag_afdb_tasks.py`:

```python
"""Tests for collecte_afdb's 3 task functions (_extraire, _transformer,
_publier) - the orchestration logic introduced by the 2026-08-31 multi-task
DAG refactor. The business logic they call (fetch_afdb_activities,
fetch_xdr_to_usd_rate, parse_activity) is already covered by
pipeline/tests/test_afdb_collector.py and is mocked here.
"""
from unittest.mock import MagicMock, patch

from pipeline.dags.collecte_afdb import _extraire, _publier, _transformer


def _make_context(pulls=None):
    ti = MagicMock()
    ti.xcom_pull.side_effect = lambda task_ids, key: (pulls or {}).get(key)
    return {"ds": "2026-08-31", "ti": ti}


def _pushed(context, key):
    for call in context["ti"].xcom_push.call_args_list:
        if call.kwargs.get("key") == key:
            return call.kwargs.get("value")
    return None


def test_extraire_fetches_the_rate_and_the_full_portfolio_and_stages_to_minio():
    context = _make_context()

    with patch("pipeline.dags.collecte_afdb.fetch_xdr_to_usd_rate", return_value=1.34), \
         patch("pipeline.dags.collecte_afdb.fetch_afdb_activities", return_value=iter([{"iati_identifier": "A1"}])), \
         patch("pipeline.dags.collecte_afdb.make_minio_client"), \
         patch("pipeline.dags.collecte_afdb.upload_json") as mock_upload:
        _extraire(**context)

    call_args = mock_upload.call_args
    assert call_args[0][1] == "bronze/afdb/2026-08-31/raw.json"
    assert call_args[0][2] == {"xdr_to_usd_rate": 1.34, "activities": [{"iati_identifier": "A1"}]}
    assert _pushed(context, "raw_path") == "bronze/afdb/2026-08-31/raw.json"


def test_transformer_parses_every_activity_with_the_staged_rate():
    context = _make_context(pulls={"raw_path": "bronze/afdb/2026-08-31/raw.json"})
    staged_raw = {"xdr_to_usd_rate": 1.34, "activities": [{"iati_identifier": "A1"}, {"iati_identifier": "A2"}]}

    with patch("pipeline.dags.collecte_afdb.make_minio_client"), \
         patch("pipeline.dags.collecte_afdb.download_json", return_value=staged_raw), \
         patch("pipeline.dags.collecte_afdb.parse_activity") as mock_parse, \
         patch("pipeline.dags.collecte_afdb.upload_json") as mock_upload:
        mock_parse.side_effect = [{"source": "afdb", "project_id": "A1"}, None]
        _transformer(**context)

    mock_parse.assert_any_call({"iati_identifier": "A1"}, 1.34)
    mock_parse.assert_any_call({"iati_identifier": "A2"}, 1.34)
    call_args = mock_upload.call_args
    assert call_args[0][1] == "silver/afdb/2026-08-31/payloads.json"
    assert call_args[0][2] == [{"source": "afdb", "project_id": "A1"}]
    assert _pushed(context, "payloads_path") == "silver/afdb/2026-08-31/payloads.json"


def test_publier_sends_every_payload_and_reports_the_published_count():
    context = _make_context(pulls={"payloads_path": "silver/afdb/2026-08-31/payloads.json"})
    mock_producer = MagicMock()

    with patch("pipeline.dags.collecte_afdb.make_minio_client"), \
         patch("pipeline.dags.collecte_afdb.download_json", return_value=[{"a": 1}]), \
         patch("pipeline.dags.collecte_afdb.make_producer", return_value=mock_producer):
        _publier(**context)

    mock_producer.send.assert_called_once_with("nev.funding.raw", {"a": 1})
    mock_producer.flush.assert_called_once()
    assert _pushed(context, "published_count") == 1
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose run --rm funding-validator pytest pipeline/tests/test_dag_afdb_tasks.py -v`
Expected: FAIL with `ImportError: cannot import name '_extraire' from 'pipeline.dags.collecte_afdb'`.

- [ ] **Step 3: Rewrite the DAG file**

Replace the full content of `pipeline/dags/collecte_afdb.py`:

```python
"""Airflow DAG: quarterly collection of African Development Bank Group
(BAD/AfDB) project financing via the IATI Datastore - see the B1.3 spec.
Split into 3 linked tasks (extraire >> transformer >> publier) - see the
2026-08-31 multi-task DAG refactor spec.
"""
from datetime import datetime, timedelta

from airflow import DAG
from airflow.operators.python import PythonOperator

from pipeline.collectors.afdb import (
    fetch_afdb_activities,
    fetch_xdr_to_usd_rate,
    parse_activity,
)
from pipeline.common.kafka_client import make_producer
from pipeline.common.minio_staging import download_json, make_minio_client, upload_json

default_args = {
    "owner": "nev-climate-data",
    "retries": 3,
    "retry_delay": timedelta(minutes=5),
}


def _extraire(**context) -> None:
    xdr_to_usd_rate = fetch_xdr_to_usd_rate()
    raw_activities = list(fetch_afdb_activities())

    object_path = f"bronze/afdb/{context['ds']}/raw.json"
    upload_json(
        make_minio_client(),
        object_path,
        {"xdr_to_usd_rate": xdr_to_usd_rate, "activities": raw_activities},
    )
    context["ti"].xcom_push(key="raw_path", value=object_path)


def _transformer(**context) -> None:
    raw_path = context["ti"].xcom_pull(task_ids="extraire", key="raw_path")
    staged = download_json(make_minio_client(), raw_path)
    xdr_to_usd_rate = staged["xdr_to_usd_rate"]

    payloads = []
    for raw_activity in staged["activities"]:
        payload = parse_activity(raw_activity, xdr_to_usd_rate)
        if payload is not None:
            payloads.append(payload)

    object_path = f"silver/afdb/{context['ds']}/payloads.json"
    upload_json(make_minio_client(), object_path, payloads)
    context["ti"].xcom_push(key="payloads_path", value=object_path)


def _publier(**context) -> None:
    payloads_path = context["ti"].xcom_pull(task_ids="transformer", key="payloads_path")
    payloads = download_json(make_minio_client(), payloads_path)

    producer = make_producer()
    for payload in payloads:
        producer.send("nev.funding.raw", payload)
    producer.flush()
    context["ti"].xcom_push(key="published_count", value=len(payloads))


with DAG(
    dag_id="collecte_afdb",
    default_args=default_args,
    schedule_interval="0 3 1 1,4,7,10 *",  # 1er jour de chaque trimestre, 03h00
    start_date=datetime(2026, 1, 1),
    catchup=False,
    tags=["b1.3", "collecte", "afdb"],
) as dag:
    extraire = PythonOperator(task_id="extraire", python_callable=_extraire)
    transformer = PythonOperator(task_id="transformer", python_callable=_transformer)
    publier = PythonOperator(task_id="publier", python_callable=_publier)

    extraire >> transformer >> publier
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker compose build funding-validator && docker compose run --rm funding-validator pytest pipeline/tests/test_dag_afdb_tasks.py pipeline/tests/test_afdb_collector.py -v`
Expected: PASS (3 new tests + the existing collector suite unchanged and green).

- [ ] **Step 5: Commit**

```bash
git add pipeline/dags/collecte_afdb.py pipeline/tests/test_dag_afdb_tasks.py
git commit -m "refactor: split collecte_afdb into extraire/transformer/publier tasks"
git pull --rebase
git push
```

---

### Tâche 6 : `collecte_pnue` — 3 tâches reliées

**Files:**
- Modify: `pipeline/dags/collecte_pnue.py`
- Test: `pipeline/tests/test_dag_pnue_tasks.py`

**Interfaces:**
- Consumes: `make_minio_client`, `upload_json`, `download_json` from Task 1; `country_iso3_to_m49(country_iso: str) -> str | None`, `fetch_emissions_for_country(area_code: str) -> Iterator[dict]`, `parse_emission(row: dict, country_iso: str) -> dict | None` from `pipeline.collectors.pnue` (unchanged); `get_connection()` from `pipeline.common.db`; `make_producer()` from `pipeline.common.kafka_client`.
- Produces: `_extraire`, `_transformer`, `_publier` in `pipeline.dags.collecte_pnue`. The bronze payload is a list of `{"country_iso": str, "row": dict}` entries — `country_iso` must travel with each raw row, per the real Angola bug fixed in B1.4 (`parse_emission` needs the caller's own `country_iso`, not a recomputation from `row["geoAreaCode"]`).

- [ ] **Step 1: Write the failing test**

Create `pipeline/tests/test_dag_pnue_tasks.py`:

```python
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose run --rm funding-validator pytest pipeline/tests/test_dag_pnue_tasks.py -v`
Expected: FAIL with `ImportError: cannot import name '_extraire' from 'pipeline.dags.collecte_pnue'`.

- [ ] **Step 3: Rewrite the DAG file**

Replace the full content of `pipeline/dags/collecte_pnue.py`:

```python
"""Airflow DAG: annual collection of PNUE (UN SDG API) CO2 emissions data
for every country NEV tracks - see the B1.4 spec, decision 12 (annual
schedule) and decision 2 (the country list comes from NEV's own `country`
table). Split into 3 linked tasks (extraire >> transformer >> publier) -
see the 2026-08-31 multi-task DAG refactor spec.
"""
from datetime import datetime, timedelta

from airflow import DAG
from airflow.operators.python import PythonOperator

from pipeline.collectors.pnue import (
    country_iso3_to_m49,
    fetch_emissions_for_country,
    parse_emission,
)
from pipeline.common.db import get_connection
from pipeline.common.kafka_client import make_producer
from pipeline.common.minio_staging import download_json, make_minio_client, upload_json

default_args = {
    "owner": "nev-climate-data",
    "retries": 3,
    "retry_delay": timedelta(minutes=5),
}


def _extraire(**context) -> None:
    connection = get_connection()
    try:
        with connection.cursor() as cursor:
            cursor.execute("SELECT iso_code FROM country ORDER BY iso_code")
            country_isos = [row[0] for row in cursor.fetchall()]
    finally:
        connection.close()

    raw_rows = []
    for country_iso in country_isos:
        area_code = country_iso3_to_m49(country_iso)
        if area_code is None:
            continue
        for row in fetch_emissions_for_country(area_code):
            # `country_iso` must travel with each raw row - real Angola bug from
            # B1.4: parse_emission needs the caller's own country_iso, not a
            # recomputation from row["geoAreaCode"].
            raw_rows.append({"country_iso": country_iso, "row": row})

    object_path = f"bronze/pnue/{context['ds']}/raw.json"
    upload_json(make_minio_client(), object_path, raw_rows)
    context["ti"].xcom_push(key="raw_path", value=object_path)


def _transformer(**context) -> None:
    raw_path = context["ti"].xcom_pull(task_ids="extraire", key="raw_path")
    raw_rows = download_json(make_minio_client(), raw_path)

    payloads = []
    for entry in raw_rows:
        payload = parse_emission(entry["row"], entry["country_iso"])
        if payload is not None:
            payloads.append(payload)

    object_path = f"silver/pnue/{context['ds']}/payloads.json"
    upload_json(make_minio_client(), object_path, payloads)
    context["ti"].xcom_push(key="payloads_path", value=object_path)


def _publier(**context) -> None:
    payloads_path = context["ti"].xcom_pull(task_ids="transformer", key="payloads_path")
    payloads = download_json(make_minio_client(), payloads_path)

    producer = make_producer()
    for payload in payloads:
        producer.send("nev.emissions.raw", payload)
    producer.flush()
    context["ti"].xcom_push(key="published_count", value=len(payloads))


with DAG(
    dag_id="collecte_pnue",
    default_args=default_args,
    schedule_interval="0 3 1 1 *",  # 1er janvier, 03h00 - annuel
    start_date=datetime(2026, 1, 1),
    catchup=False,
    tags=["b1.4", "collecte", "pnue"],
) as dag:
    extraire = PythonOperator(task_id="extraire", python_callable=_extraire)
    transformer = PythonOperator(task_id="transformer", python_callable=_transformer)
    publier = PythonOperator(task_id="publier", python_callable=_publier)

    extraire >> transformer >> publier
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker compose build funding-validator && docker compose run --rm funding-validator pytest pipeline/tests/test_dag_pnue_tasks.py pipeline/tests/test_pnue_collector.py -v`
Expected: PASS (3 new tests + the existing collector suite unchanged and green).

- [ ] **Step 5: Commit**

```bash
git add pipeline/dags/collecte_pnue.py pipeline/tests/test_dag_pnue_tasks.py
git commit -m "refactor: split collecte_pnue into extraire/transformer/publier tasks"
git pull --rebase
git push
```

---

### Tâche 7 : `extraction_pdf` — 3 tâches reliées avec court-circuit de cache

**Files:**
- Modify: `pipeline/dags/extraction_pdf.py`
- Test: `pipeline/tests/test_dag_extraction_pdf_tasks.py`

**Interfaces:**
- Consumes: `make_minio_client`, `upload_bytes`, `download_bytes`, `upload_json`, `download_json` from Task 1; `sha256_hash`, `slice_pdf_pages`, `extract_json_via_gemini`, `is_already_processed`, `record_processed` from `pipeline.common.pdf_extraction` (Task 2 — same names, unchanged signatures); `ANNEX_2_START_PAGE`, `ANNEX_2_END_PAGE`, `EXTRACTION_PROMPT`, `REQUEST_TIMEOUT_SECONDS`, `SOURCE_NAME`, `SOURCE_URL`, `DOCUMENT_SLUG`, `build_payloads(row: dict, document_hash: str) -> list[dict]` from `pipeline.collectors.opec_fund_climate_finance` (unchanged); `get_connection()` from `pipeline.common.db`; `make_producer()` from `pipeline.common.kafka_client`.
- Produces: `_extraire`, `_transformer`, `_publier` in `pipeline.dags.extraction_pdf`, propagating `cache_hit: bool` and `document_hash: str` at every step; `minio_path_pdf`, `minio_path_annex` (from `extraire`, cache-miss only); `payloads_path`, `rows_extracted` (from `transformer`, cache-miss only); `published_count` (from `publier`, always — `0` on any cache hit).

- [ ] **Step 1: Write the failing test**

Create `pipeline/tests/test_dag_extraction_pdf_tasks.py`:

```python
"""Tests for extraction_pdf's 3 task functions (_extraire, _transformer,
_publier) and its cache short-circuit (a `cache_hit` flag propagated via
XCom, no dynamic Airflow branching) - see the 2026-08-31 multi-task DAG
refactor spec, decision 5. The business logic they call (slice_pdf_pages,
extract_json_via_gemini, build_payloads, record_processed) is already
covered by pipeline/tests/test_pdf_extraction.py and
pipeline/tests/test_opec_fund_collector.py, and is mocked here.
"""
import json
from unittest.mock import MagicMock, patch

from pipeline.dags.extraction_pdf import _extraire, _publier, _transformer


def _make_context(pulls=None):
    ti = MagicMock()
    ti.xcom_pull.side_effect = lambda task_ids, key: (pulls or {}).get(key)
    return {"ds": "2026-08-31", "ti": ti}


def _pushed(context, key):
    for call in context["ti"].xcom_push.call_args_list:
        if call.kwargs.get("key") == key:
            return call.kwargs.get("value")
    return None


def test_extraire_short_circuits_on_a_cache_hit():
    mock_response = MagicMock(content=b"pdf-bytes")
    context = _make_context()

    with patch("pipeline.dags.extraction_pdf.requests.get", return_value=mock_response), \
         patch("pipeline.dags.extraction_pdf.sha256_hash", return_value="abc123"), \
         patch("pipeline.dags.extraction_pdf.get_connection", return_value=MagicMock()), \
         patch("pipeline.dags.extraction_pdf.is_already_processed", return_value=True), \
         patch("pipeline.dags.extraction_pdf.make_minio_client") as mock_make_client, \
         patch("pipeline.dags.extraction_pdf.upload_bytes") as mock_upload:
        _extraire(**context)

    assert _pushed(context, "cache_hit") is True
    assert _pushed(context, "document_hash") == "abc123"
    mock_upload.assert_not_called()
    mock_make_client.assert_not_called()


def test_extraire_slices_and_stages_both_pdfs_on_a_cache_miss():
    mock_response = MagicMock(content=b"pdf-bytes")
    context = _make_context()

    with patch("pipeline.dags.extraction_pdf.requests.get", return_value=mock_response), \
         patch("pipeline.dags.extraction_pdf.sha256_hash", return_value="abc123"), \
         patch("pipeline.dags.extraction_pdf.get_connection", return_value=MagicMock()), \
         patch("pipeline.dags.extraction_pdf.is_already_processed", return_value=False), \
         patch("pipeline.dags.extraction_pdf.slice_pdf_pages", return_value=b"annex-bytes"), \
         patch("pipeline.dags.extraction_pdf.make_minio_client"), \
         patch("pipeline.dags.extraction_pdf.upload_bytes") as mock_upload:
        _extraire(**context)

    assert _pushed(context, "cache_hit") is False
    assert _pushed(context, "document_hash") == "abc123"
    assert _pushed(context, "minio_path_pdf").endswith("abc123.pdf")
    assert _pushed(context, "minio_path_annex").endswith("abc123-annex.pdf")
    assert mock_upload.call_count == 2


def test_transformer_short_circuits_when_extraire_reports_a_cache_hit():
    context = _make_context(pulls={"cache_hit": True})

    with patch("pipeline.dags.extraction_pdf.extract_json_via_gemini") as mock_gemini, \
         patch("pipeline.dags.extraction_pdf.make_minio_client") as mock_make_client:
        _transformer(**context)

    assert _pushed(context, "cache_hit") is True
    mock_gemini.assert_not_called()
    mock_make_client.assert_not_called()


def test_transformer_extracts_and_stages_payloads_on_a_cache_miss():
    context = _make_context(pulls={
        "cache_hit": False,
        "document_hash": "abc123",
        "minio_path_pdf": "bronze/opec-fund-climate-finance-2024/2026-08-31/abc123.pdf",
        "minio_path_annex": "bronze/opec-fund-climate-finance-2024/2026-08-31/abc123-annex.pdf",
    })
    # Real row shape from the B1.5 spec's own example (Senegal's PROVALE-CV) -
    # 20% adaptation + 20% mitigation, invariant holds (20 + 20 = 40).
    row = {
        "year": 2020, "country": "Senegal", "project": "PROVALE-CV", "sector": "Agriculture",
        "amount_usd_mn": 20, "adaptation_pct": 20, "mitigation_pct": 20, "total_climate_pct": 40,
    }

    with patch("pipeline.dags.extraction_pdf.make_minio_client"), \
         patch("pipeline.dags.extraction_pdf.download_bytes", return_value=b"annex-bytes"), \
         patch("pipeline.dags.extraction_pdf.extract_json_via_gemini", return_value=json.dumps([row])), \
         patch("pipeline.dags.extraction_pdf.upload_json") as mock_upload:
        _transformer(**context)

    assert _pushed(context, "cache_hit") is False
    assert _pushed(context, "document_hash") == "abc123"
    assert _pushed(context, "minio_path_pdf") == "bronze/opec-fund-climate-finance-2024/2026-08-31/abc123.pdf"
    assert _pushed(context, "rows_extracted") == 1
    mock_upload.assert_called_once()
    published_payloads = mock_upload.call_args[0][2]
    assert len(published_payloads) == 2  # adaptation + mitigation, both non-zero here


def test_publier_short_circuits_when_transformer_reports_a_cache_hit():
    context = _make_context(pulls={"cache_hit": True})

    with patch("pipeline.dags.extraction_pdf.make_minio_client") as mock_make_client, \
         patch("pipeline.dags.extraction_pdf.make_producer") as mock_make_producer:
        _publier(**context)

    assert _pushed(context, "published_count") == 0
    mock_make_client.assert_not_called()
    mock_make_producer.assert_not_called()


def test_publier_sends_payloads_and_records_the_cache_entry_on_a_cache_miss():
    context = _make_context(pulls={
        "cache_hit": False,
        "document_hash": "abc123",
        "minio_path_pdf": "bronze/opec-fund-climate-finance-2024/2026-08-31/abc123.pdf",
        "payloads_path": "silver/opec-fund-climate-finance-2024/2026-08-31/payloads.json",
        "rows_extracted": 1,
    })
    mock_producer = MagicMock()

    with patch("pipeline.dags.extraction_pdf.make_minio_client"), \
         patch("pipeline.dags.extraction_pdf.download_json", return_value=[{"a": 1}, {"a": 2}]), \
         patch("pipeline.dags.extraction_pdf.make_producer", return_value=mock_producer), \
         patch("pipeline.dags.extraction_pdf.get_connection", return_value=MagicMock()), \
         patch("pipeline.dags.extraction_pdf.record_processed") as mock_record:
        _publier(**context)

    assert mock_producer.send.call_count == 2
    mock_producer.flush.assert_called_once()
    mock_record.assert_called_once()
    record_kwargs = mock_record.call_args.kwargs
    assert record_kwargs["document_hash"] == "abc123"
    assert record_kwargs["minio_path"] == "bronze/opec-fund-climate-finance-2024/2026-08-31/abc123.pdf"
    assert record_kwargs["rows_extracted"] == 1
    assert _pushed(context, "published_count") == 2
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose run --rm funding-validator pytest pipeline/tests/test_dag_extraction_pdf_tasks.py -v`
Expected: FAIL with `ImportError: cannot import name '_extraire' from 'pipeline.dags.extraction_pdf'`.

- [ ] **Step 3: Rewrite the DAG file**

Replace the full content of `pipeline/dags/extraction_pdf.py`:

```python
"""Airflow DAG: annual extraction of the OPEC Fund Climate Finance Report
(B1.5) - see the B1.5 spec, decision 12. Split into 3 linked tasks (extraire
>> transformer >> publier) with a cache short-circuit propagated via XCom -
see the 2026-08-31 multi-task DAG refactor spec, decision 5.
"""
import json
from datetime import date, datetime, timedelta

import requests
from airflow import DAG
from airflow.operators.python import PythonOperator

from pipeline.collectors.opec_fund_climate_finance import (
    ANNEX_2_END_PAGE,
    ANNEX_2_START_PAGE,
    DOCUMENT_SLUG,
    EXTRACTION_PROMPT,
    REQUEST_TIMEOUT_SECONDS,
    SOURCE_NAME,
    SOURCE_URL,
    build_payloads,
)
from pipeline.common.db import get_connection
from pipeline.common.kafka_client import make_producer
from pipeline.common.minio_staging import (
    download_bytes,
    download_json,
    make_minio_client,
    upload_bytes,
    upload_json,
)
from pipeline.common.pdf_extraction import (
    extract_json_via_gemini,
    is_already_processed,
    record_processed,
    sha256_hash,
    slice_pdf_pages,
)

default_args = {
    "owner": "nev-climate-data",
    "retries": 3,
    "retry_delay": timedelta(minutes=5),
}


def _extraire(**context) -> None:
    response = requests.get(SOURCE_URL, timeout=REQUEST_TIMEOUT_SECONDS)
    response.raise_for_status()
    pdf_bytes = response.content
    document_hash = sha256_hash(pdf_bytes)

    connection = get_connection()
    try:
        with connection:
            with connection.cursor() as cursor:
                already_processed = is_already_processed(cursor, document_hash)
    finally:
        connection.close()

    ti = context["ti"]
    if already_processed:
        ti.xcom_push(key="cache_hit", value=True)
        ti.xcom_push(key="document_hash", value=document_hash)
        return

    annex_bytes = slice_pdf_pages(pdf_bytes, ANNEX_2_START_PAGE, ANNEX_2_END_PAGE)
    today = date.today().isoformat()
    minio_path_pdf = f"bronze/{DOCUMENT_SLUG}/{today}/{document_hash}.pdf"
    minio_path_annex = f"bronze/{DOCUMENT_SLUG}/{today}/{document_hash}-annex.pdf"

    minio_client = make_minio_client()
    upload_bytes(minio_client, minio_path_pdf, pdf_bytes)
    upload_bytes(minio_client, minio_path_annex, annex_bytes)

    ti.xcom_push(key="cache_hit", value=False)
    ti.xcom_push(key="document_hash", value=document_hash)
    ti.xcom_push(key="minio_path_pdf", value=minio_path_pdf)
    ti.xcom_push(key="minio_path_annex", value=minio_path_annex)


def _transformer(**context) -> None:
    ti = context["ti"]
    cache_hit = ti.xcom_pull(task_ids="extraire", key="cache_hit")
    if cache_hit:
        ti.xcom_push(key="cache_hit", value=True)
        return

    document_hash = ti.xcom_pull(task_ids="extraire", key="document_hash")
    minio_path_pdf = ti.xcom_pull(task_ids="extraire", key="minio_path_pdf")
    minio_path_annex = ti.xcom_pull(task_ids="extraire", key="minio_path_annex")

    minio_client = make_minio_client()
    annex_bytes = download_bytes(minio_client, minio_path_annex)
    raw_text = extract_json_via_gemini(annex_bytes, EXTRACTION_PROMPT)
    rows = json.loads(raw_text)

    payloads = []
    for row in rows:
        payloads.extend(build_payloads(row, document_hash))

    object_path = f"silver/{DOCUMENT_SLUG}/{context['ds']}/payloads.json"
    upload_json(minio_client, object_path, payloads)

    ti.xcom_push(key="cache_hit", value=False)
    ti.xcom_push(key="document_hash", value=document_hash)
    ti.xcom_push(key="minio_path_pdf", value=minio_path_pdf)
    ti.xcom_push(key="payloads_path", value=object_path)
    ti.xcom_push(key="rows_extracted", value=len(rows))


def _publier(**context) -> None:
    ti = context["ti"]
    cache_hit = ti.xcom_pull(task_ids="transformer", key="cache_hit")
    if cache_hit:
        ti.xcom_push(key="published_count", value=0)
        return

    document_hash = ti.xcom_pull(task_ids="transformer", key="document_hash")
    minio_path_pdf = ti.xcom_pull(task_ids="transformer", key="minio_path_pdf")
    payloads_path = ti.xcom_pull(task_ids="transformer", key="payloads_path")
    rows_extracted = ti.xcom_pull(task_ids="transformer", key="rows_extracted")

    minio_client = make_minio_client()
    payloads = download_json(minio_client, payloads_path)

    producer = make_producer()
    for payload in payloads:
        producer.send("nev.funding.raw", payload)
    producer.flush()

    connection = get_connection()
    try:
        with connection:
            with connection.cursor() as cursor:
                record_processed(
                    cursor,
                    document_hash=document_hash,
                    source_name=SOURCE_NAME,
                    source_url=SOURCE_URL,
                    minio_path=minio_path_pdf,
                    rows_extracted=rows_extracted,
                )
    finally:
        connection.close()

    ti.xcom_push(key="published_count", value=len(payloads))


with DAG(
    dag_id="extraction_pdf",
    default_args=default_args,
    schedule_interval="0 3 1 1 *",  # 1er janvier, 03h00 - annuel, cf. spec decision 12
    start_date=datetime(2026, 1, 1),
    catchup=False,
    tags=["b1.5", "extraction", "pdf", "opec-fund"],
) as dag:
    extraire = PythonOperator(task_id="extraire", python_callable=_extraire)
    transformer = PythonOperator(task_id="transformer", python_callable=_transformer)
    publier = PythonOperator(task_id="publier", python_callable=_publier)

    extraire >> transformer >> publier
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `docker compose build funding-validator && docker compose run --rm funding-validator pytest pipeline/tests/test_dag_extraction_pdf_tasks.py pipeline/tests/test_pdf_extraction.py pipeline/tests/test_opec_fund_collector.py -v`
Expected: PASS (6 new tests + both existing suites unchanged and green).

- [ ] **Step 5: Commit**

```bash
git add pipeline/dags/extraction_pdf.py pipeline/tests/test_dag_extraction_pdf_tasks.py
git commit -m "refactor: split extraction_pdf into extraire/transformer/publier tasks with a cache short-circuit"
git pull --rebase
git push
```

---

### Tâche 8 : Vérification end-to-end réelle, documentation, clôture

**Files:**
- Modify: `README.md`

**Interfaces:** n/a — vérification et documentation, aucun nouveau code.

- [ ] **Step 1: Run the full offline pipeline test suite**

Run: `docker compose run --rm funding-validator pytest pipeline/tests/ -v`
Expected: every test green — the 5 collectors' own suites, `funding_validator`/`emission_validator`, `pdf_extraction`, `minio_staging`, and the 5 new `test_dag_*_tasks.py` files, with no regression from before this refactor.

- [ ] **Step 2: Confirm the Airflow scheduler has synced the 5 rewritten DAG files**

Run: `docker compose exec airflow airflow dags list | grep -E "collecte_worldbank|collecte_gcf|collecte_afdb|collecte_pnue|extraction_pdf"`
Expected: all 5 `dag_id`s listed (the `airflow` service volume-mounts `./pipeline`, so this should be near-instant — no rebuild needed for this service).

- [ ] **Step 3: Trigger each of the 5 DAGs for real and confirm the Graph view now shows 3 linked tasks**

For each `dag_id` in `collecte_worldbank`, `collecte_gcf`, `collecte_afdb`, `collecte_pnue`, `extraction_pdf`:

```bash
docker compose exec airflow airflow dags trigger <dag_id>
```

Poll `docker compose exec airflow airflow dags list-runs -d <dag_id>` until the run reaches `success` (or investigate/fix on `failed`, same troubleshooting approach as B1.5's own end-to-end verification — read the real task log under `/opt/airflow/logs/dag_id=<dag_id>/run_id=.../task_id=<task>/attempt=N.log` inside the container). Confirm in the Airflow UI's Graph view for each DAG that `extraire`, `transformer`, and `publier` appear as 3 separate nodes connected by arrows (`extraire → transformer → publier`) — this is the concrete fix for what Serge originally reported.

- [ ] **Step 4: Confirm published message counts are unchanged versus before the refactor**

For `collecte_worldbank`/`collecte_gcf`/`collecte_afdb`/`collecte_pnue`: compare each run's `published_count` (visible via `docker compose exec airflow airflow tasks states-for-dag-run <dag_id> <run_id>` or by reading the `publier` task's XCom in the UI) against a note of what the equivalent single-task run published before this refactor, or simply confirm the count is plausible (non-zero, roughly matching the connector's known real scale — e.g. AfDB in the low thousands, GCF a few hundred). For `extraction_pdf` specifically: since the real target document was already processed and cached during B1.5's own end-to-end verification, this first post-refactor run is expected to be a **cache hit** — confirm `published_count == 0` and that the Graph view still shows all 3 tasks as `success` (proving the short-circuit itself runs cleanly end-to-end, not just its mocked unit tests).

- [ ] **Step 5: Document the refactor in README.md**

In `README.md`'s "Pipeline (Volet B)" section, add a short paragraph (near the existing DAG-related notes) explaining: each of the 5 Volet B DAGs now runs as 3 linked tasks (`extraire >> transformer >> publier`) instead of one, MinIO's `bronze`/`silver` prefixes now also serve as transient staging between tasks (not just permanent document storage as in B1.5), and `extraction_pdf`'s cache short-circuit is implemented as an XCom flag rather than a dynamic Airflow branch.

- [ ] **Step 6: Commit**

```bash
git add README.md
git commit -m "docs: document the 5 DAGs' multi-task refactor"
git pull --rebase
git push
```
