# B1.9 — Alerting réel sur échec des DAGs - Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Recevoir un vrai email quand un DAG Volet B échoue pour de bon (après ses 3 tentatives),
sans être notifié à chaque tentative individuelle.

**Architecture:** Configuration SMTP native d'Airflow dans `docker-compose.yml`, un
`default_args` partagé (`pipeline/common/alerting.py`) importé par les 5 DAGs à la place de leur
dictionnaire dupliqué.

**Tech Stack:** Apache Airflow 2.9 (`AIRFLOW__SMTP__*`, `email_on_failure`/`email_on_retry`),
Gmail SMTP.

## Global Constraints

- `AIRFLOW_ALERT_EMAIL`/`AIRFLOW_SMTP_PASSWORD` sont déjà dans `.env` local (non commité) avec les
  vraies valeurs. `.env.example` ne doit contenir que des placeholders.
- `email_on_failure` doit être dérivé de la présence réelle de `AIRFLOW_ALERT_EMAIL`
  (`bool(ALERT_EMAIL)`), jamais codé en dur à `True`.
- `email_on_retry` reste `False` - alerte seulement après épuisement des 3 tentatives.
- Aucun changement aux valeurs `retries: 3` / `retry_delay: timedelta(minutes=5)` déjà en place.
- Le DAG de test jetable (Tâche 3) ne doit **jamais** être commité au dépôt.

---

### Tâche 1 : Config SMTP + module `pipeline/common/alerting.py`

**Files:**
- Modify: `docker-compose.yml`
- Modify: `.env.example`
- Create: `pipeline/common/alerting.py`
- Test: `pipeline/tests/test_alerting.py`

**Interfaces:**
- Produces: `pipeline.common.alerting.ALERT_EMAIL: str`, `pipeline.common.alerting.default_args: dict`
  - consommé par les 5 DAGs en Tâche 2.

- [ ] **Step 1: Write the failing test**

Create `pipeline/tests/test_alerting.py`:

```python
"""Tests for the shared Airflow default_args (retries + real failure
alerting via email) - see the 2026-09-01 B1.9 spec.
"""
import importlib
from datetime import timedelta

from pipeline.common import alerting


def test_default_args_alerts_on_failure_when_an_alert_email_is_configured(monkeypatch):
    monkeypatch.setenv("AIRFLOW_ALERT_EMAIL", "ops@example.org")
    importlib.reload(alerting)
    try:
        assert alerting.default_args["email_on_failure"] is True
        assert alerting.default_args["email"] == ["ops@example.org"]
        assert alerting.default_args["email_on_retry"] is False
        assert alerting.default_args["retries"] == 3
        assert alerting.default_args["retry_delay"] == timedelta(minutes=5)
    finally:
        monkeypatch.undo()
        importlib.reload(alerting)


def test_default_args_never_alerts_when_no_alert_email_is_configured(monkeypatch):
    monkeypatch.delenv("AIRFLOW_ALERT_EMAIL", raising=False)
    importlib.reload(alerting)
    try:
        assert alerting.default_args["email_on_failure"] is False
        assert alerting.default_args["email"] == []
    finally:
        monkeypatch.undo()
        importlib.reload(alerting)
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose run --rm funding-validator pytest pipeline/tests/test_alerting.py -v`
Expected: FAIL - `ModuleNotFoundError: No module named 'pipeline.common.alerting'`.

- [ ] **Step 3: Write the implementation**

Create `pipeline/common/alerting.py`:

```python
"""Shared Airflow default_args for every Volet B DAG - retries + real
failure alerting (email), configured once instead of duplicated 5 times.
See the 2026-09-01 B1.9 spec.
"""
from __future__ import annotations

import os
from datetime import timedelta

ALERT_EMAIL = os.environ.get("AIRFLOW_ALERT_EMAIL", "")

default_args = {
    "owner": "nev-climate-data",
    "retries": 3,
    "retry_delay": timedelta(minutes=5),
    # Alert only once a task has exhausted all its retries, not on every
    # individual attempt - a slow/rate-limited source (AfDB) would
    # otherwise generate noise on every run that needed even one retry.
    "email_on_failure": bool(ALERT_EMAIL),
    "email_on_retry": False,
    "email": [ALERT_EMAIL] if ALERT_EMAIL else [],
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose build funding-validator && docker compose run --rm funding-validator pytest pipeline/tests/test_alerting.py -v`
Expected: PASS (2 tests).

- [ ] **Step 5: Add SMTP config to `docker-compose.yml`**

In `docker-compose.yml`, in the `airflow` service's `environment:` block, add after
`MINIO_ROOT_PASSWORD: ${MINIO_ROOT_PASSWORD}`:

```yaml
      MINIO_ROOT_PASSWORD: ${MINIO_ROOT_PASSWORD}
      # B1.9: real email alerting on DAG task failure - see
      # pipeline/common/alerting.py and the 2026-09-01 spec.
      AIRFLOW_ALERT_EMAIL: ${AIRFLOW_ALERT_EMAIL}
      AIRFLOW__SMTP__SMTP_HOST: smtp.gmail.com
      AIRFLOW__SMTP__SMTP_STARTTLS: "True"
      AIRFLOW__SMTP__SMTP_SSL: "False"
      AIRFLOW__SMTP__SMTP_PORT: "587"
      AIRFLOW__SMTP__SMTP_USER: ${AIRFLOW_ALERT_EMAIL}
      AIRFLOW__SMTP__SMTP_PASSWORD: ${AIRFLOW_SMTP_PASSWORD}
      AIRFLOW__SMTP__SMTP_MAIL_FROM: ${AIRFLOW_ALERT_EMAIL}
```

- [ ] **Step 6: Add placeholders to `.env.example`**

In `.env.example`, add near the other Airflow/pipeline-related variables:

```bash
# Alerting Airflow (B1.9) - email envoyé uniquement quand un DAG échoue pour de bon (après ses
# 3 tentatives). AIRFLOW_SMTP_PASSWORD est un "mot de passe d'application" Gmail, pas le mot de
# passe du compte - à générer sur myaccount.google.com/apppasswords (nécessite la validation en
# 2 étapes activée sur le compte).
AIRFLOW_ALERT_EMAIL=change_me_your_alert_email@example.org
AIRFLOW_SMTP_PASSWORD=change_me_gmail_app_password
```

- [ ] **Step 7: Recreate the airflow container to pick up the new env vars**

Run: `docker compose up -d --force-recreate airflow`
Expected: container starts and stays up (no crash loop) - check with
`docker compose ps airflow` after a few seconds.

- [ ] **Step 8: Commit**

```bash
git add pipeline/common/alerting.py pipeline/tests/test_alerting.py docker-compose.yml .env.example
git commit -m "feat: add shared Airflow default_args with real email-on-failure alerting"
git pull --rebase
git push
```

---

### Tâche 2 : Les 5 DAGs importent le `default_args` partagé

**Files:**
- Modify: `pipeline/dags/collecte_worldbank.py`
- Modify: `pipeline/dags/collecte_gcf.py`
- Modify: `pipeline/dags/collecte_afdb.py`
- Modify: `pipeline/dags/collecte_pnue.py`
- Modify: `pipeline/dags/extraction_pdf.py`

**Interfaces:**
- Consumes: `pipeline.common.alerting.default_args` (Tâche 1).

- [ ] **Step 1: `collecte_worldbank.py`**

Replace:
```python
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
```
With:
```python
from datetime import datetime

import pycountry
from airflow import DAG
from airflow.operators.python import PythonOperator

from pipeline.collectors.world_bank import fetch_projects_for_country, parse_project
from pipeline.common.alerting import default_args
from pipeline.common.db import get_connection
from pipeline.common.kafka_client import make_producer
from pipeline.common.minio_staging import download_json, make_minio_client, upload_json
```

- [ ] **Step 2: `collecte_gcf.py`**

Replace:
```python
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
```
With:
```python
from datetime import datetime

from airflow import DAG
from airflow.operators.python import PythonOperator

from pipeline.collectors.gcf import fetch_gcf_activities, parse_activity
from pipeline.common.alerting import default_args
from pipeline.common.kafka_client import make_producer
from pipeline.common.minio_staging import download_json, make_minio_client, upload_json
```

- [ ] **Step 3: `collecte_afdb.py`**

Replace:
```python
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
```
With:
```python
from datetime import datetime

from airflow import DAG
from airflow.operators.python import PythonOperator

from pipeline.collectors.afdb import (
    fetch_afdb_activities,
    fetch_xdr_to_usd_rate,
    parse_activity,
)
from pipeline.common.alerting import default_args
from pipeline.common.kafka_client import make_producer
from pipeline.common.minio_staging import download_json, make_minio_client, upload_json
```

- [ ] **Step 4: `collecte_pnue.py`**

Replace:
```python
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
```
With:
```python
from datetime import datetime

from airflow import DAG
from airflow.operators.python import PythonOperator

from pipeline.collectors.pnue import (
    country_iso3_to_m49,
    fetch_emissions_for_country,
    parse_emission,
)
from pipeline.common.alerting import default_args
from pipeline.common.db import get_connection
from pipeline.common.kafka_client import make_producer
from pipeline.common.minio_staging import download_json, make_minio_client, upload_json
```

- [ ] **Step 5: `extraction_pdf.py`**

Replace:
```python
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
```
With:
```python
import json
from datetime import date, datetime

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
from pipeline.common.alerting import default_args
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
```

- [ ] **Step 6: Confirm every DAG still parses (Airflow container, no rebuild needed - volume-mounted)**

Run: `docker compose exec airflow airflow dags list 2>&1 | grep -E "collecte_worldbank|collecte_gcf|collecte_afdb|collecte_pnue|extraction_pdf"`
Expected: all 5 `dag_id`s still listed, no import error.

Run: `docker compose exec airflow airflow dags list-import-errors`
Expected: no errors for any of the 5 files.

- [ ] **Step 7: Run the existing DAG task test suites to confirm no regression**

Run: `docker compose exec airflow pytest pipeline/tests/test_dag_worldbank_tasks.py pipeline/tests/test_dag_gcf_tasks.py pipeline/tests/test_dag_afdb_tasks.py pipeline/tests/test_dag_pnue_tasks.py pipeline/tests/test_dag_extraction_pdf_tasks.py -v`
Expected: all 18 tests PASS (they test the `_extraire`/`_transformer`/`_publier` functions
directly, not `default_args`, so this change doesn't affect them - this run only proves the import
change didn't break anything else in these modules).

- [ ] **Step 8: Commit**

```bash
git add pipeline/dags/collecte_worldbank.py pipeline/dags/collecte_gcf.py pipeline/dags/collecte_afdb.py pipeline/dags/collecte_pnue.py pipeline/dags/extraction_pdf.py
git commit -m "refactor: the 5 Volet B DAGs use the shared alerting default_args"
git pull --rebase
git push
```

---

### Tâche 3 : Vérification réelle bout-en-bout + documentation

**Files:**
- Create (temporaire, non commité) : `pipeline/dags/_test_b19_alerting.py`
- Modify: `README.md`

**Interfaces:** n/a - vérification et documentation.

- [ ] **Step 1: Vérifier directement que le SMTP configuré envoie un vrai email**

Run:
```bash
docker compose exec airflow python -c "
from airflow.utils.email import send_email
send_email(to=['nevserviceinformatique@gmail.com'], subject='[NEV Climate Data] Test SMTP B1.9', html_content='<p>Ceci est un test reel de la configuration SMTP Airflow (B1.9).</p>')
print('sent')
"
```
Expected: affiche `sent` sans exception. **Demander à Serge de confirmer la réception réelle de
cet email avant de continuer** - une absence d'exception ne prouve pas la livraison (le serveur
SMTP de Gmail peut accepter puis rejeter silencieusement).

- [ ] **Step 2: Créer un DAG de test jetable qui échoue toujours**

Create `pipeline/dags/_test_b19_alerting.py` (fichier temporaire - **ne jamais le committer**,
voir Step 5) :

```python
"""Throwaway DAG used once to verify B1.9's real email-on-failure alerting
end-to-end. NOT a real connector - delete this file after verification,
never commit it.
"""
from datetime import datetime

from airflow import DAG
from airflow.operators.python import PythonOperator

from pipeline.common.alerting import default_args

test_args = {**default_args, "retries": 0}


def _always_fails(**context) -> None:
    raise RuntimeError("B1.9 verification: this task is designed to always fail.")


with DAG(
    dag_id="_test_b19_alerting",
    default_args=test_args,
    schedule_interval=None,
    start_date=datetime(2026, 1, 1),
    catchup=False,
    tags=["b1.9", "test", "throwaway"],
) as dag:
    PythonOperator(task_id="always_fails", python_callable=_always_fails)
```

- [ ] **Step 3: Déclencher réellement ce DAG et confirmer l'échec + l'email**

Attendre que le scheduler synchronise le nouveau DAG (même pattern que les vérifications
précédentes), puis :

```bash
docker compose exec airflow airflow dags unpause _test_b19_alerting
docker compose exec airflow airflow dags trigger _test_b19_alerting
```

Sonder `airflow dags list-runs -d _test_b19_alerting --output json` jusqu'à l'état `failed`
(attendu rapidement, `retries: 0`). **Demander à Serge de confirmer la réception d'un email
d'échec réel** correspondant à ce DAG (sujet Airflow standard : `Airflow alert:
<TaskInstance: _test_b19_alerting.always_fails ...>`).

- [ ] **Step 4: Nettoyer - supprimer le DAG de test**

```bash
docker compose exec airflow airflow dags delete _test_b19_alerting --yes
```

Puis supprimer le fichier local :

```bash
rm "pipeline/dags/_test_b19_alerting.py"
```

Run: `git status --short pipeline/dags/` - confirmer qu'aucune trace du fichier de test
n'apparaît (jamais ajouté à l'index, donc rien à committer).

- [ ] **Step 5: Documenter dans README.md**

Dans "Pipeline (Volet B)", après la sous-section la plus récente, ajouter :

```markdown
### Alerting réel sur échec des DAGs (B1.9, 2026-09-01)

Les 5 DAGs Volet B envoient désormais un vrai email (Gmail SMTP,
`AIRFLOW_ALERT_EMAIL`/`AIRFLOW_SMTP_PASSWORD` dans `.env`) quand une tâche épuise ses 3 tentatives
et échoue pour de bon - jamais à chaque tentative individuelle (`email_on_retry: False`), pour ne
pas noyer une vraie panne sous des alertes de lenteur transitoire déjà couvertes par le retry
existant. Un changement de structure d'une source (champ renommé/retiré côté API) n'a pas de
détection dédiée - il se manifeste déjà comme une exception Python réelle pendant le parsing, que
`email_on_failure` couvre au même titre que n'importe quel autre échec. Décisions complètes :
[`docs/superpowers/specs/2026-09-01-b19-airflow-alerting-design.md`](docs/superpowers/specs/2026-09-01-b19-airflow-alerting-design.md).

Vérifié en direct : un email de test SMTP et un DAG jetable systématiquement en échec ont tous
deux généré une réception réelle confirmée par Serge, avant suppression du DAG de test (jamais
commité).
```

- [ ] **Step 6: Commit**

```bash
git add README.md
git commit -m "docs: document real email alerting on DAG failure (B1.9)"
git pull --rebase
git push
```
