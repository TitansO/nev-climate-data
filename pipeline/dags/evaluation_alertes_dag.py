"""Airflow DAG: daily evaluation of AlertRule conditions (C3.8) - a single
HTTP trigger, not the extraire/transformer/publier shape of the Volet B data
connectors, since there is no data to move here, only a computation to run.

The `airflow` container has no Docker socket mounted (unlike a real
docker-outside-of-docker setup - see devcontainer.json/point d'attention #10),
so it cannot `docker exec` into `backend` to run `app:alerts:evaluate`
directly. Instead this DAG calls a dedicated internal HTTP endpoint
(App\\Controller\\InternalAlertsController::evaluate(), POST /api/internal/
alerts/evaluate) over the shared `nev-network` - same reasoning `ai/ingest.py`
already established for reading from the backend (`BACKEND_BASE_URL`), here
extended to a write/trigger call, authenticated by a shared secret
(`INTERNAL_TRIGGER_TOKEN`) rather than a user JWT, since no human account is
involved in a scheduled machine-to-machine call.
"""
from __future__ import annotations

import os
from datetime import datetime

import requests
from airflow import DAG
from airflow.operators.python import PythonOperator

from pipeline.common.alerting import default_args

BACKEND_BASE_URL = os.environ.get("BACKEND_BASE_URL", "http://backend")
REQUEST_TIMEOUT_SECONDS = 30


def _evaluer(**context) -> None:
    token = os.environ["INTERNAL_TRIGGER_TOKEN"]
    response = requests.post(
        f"{BACKEND_BASE_URL}/api/internal/alerts/evaluate",
        headers={"X-Internal-Token": token},
        timeout=REQUEST_TIMEOUT_SECONDS,
    )
    response.raise_for_status()
    context["ti"].xcom_push(key="created", value=response.json()["created"])


with DAG(
    dag_id="evaluation_alertes",
    default_args=default_args,
    schedule_interval="0 6 * * *",  # chaque jour à 06h00, après la fenêtre de collecte trimestrielle
    start_date=datetime(2026, 1, 1),
    catchup=False,
    tags=["c3.8", "alertes"],
) as dag:
    evaluer = PythonOperator(task_id="evaluer", python_callable=_evaluer)
