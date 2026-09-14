"""Airflow DAG: quarterly (re)indexing of the AI Copilot's RAG corpus (F6)
- same cadence as every other Volet B connector (see the 2026-09-01
unification spec). A single task, not extraire/transformer/publier: this
isn't a data connector producing Bronze/Silver artifacts for Kafka - it's
a corpus refresh, triggered over HTTP against the `ai` service itself
(the `airflow` container has none of that service's dependencies
installed, by design - see ai/db.py's docblock on keeping it isolated).
"""
from datetime import datetime

import requests
from airflow import DAG
from airflow.operators.python import PythonOperator

from pipeline.common.alerting import default_args

AI_SERVICE_URL = "http://ai:8000/ai/reindex"
# Mesure reelle (F5/F6, verification live) : ~11 minutes pour indexer tout
# le corpus initial (581 chunks) sur le palier gratuit Gemini, la duree
# etant dominee par les temporisations de rate-limit (HTTP 429,
# ai/gemini_client.py) plutot que par le calcul lui-meme. Marge large :
# une reindexation de reprise (contenu deja indexe) est quasi instantanee
# (ai/rag.py::upsert_chunk() ne rappelle jamais Gemini pour un hash deja
# present - a verifier : en realite l'embedding est calcule avant le test
# ON CONFLICT, donc le cout Gemini est paye a chaque run meme sans
# insertion reelle. A optimiser si la frequence trimestrielle s'avere
# malgre tout trop couteuse).
REQUEST_TIMEOUT_SECONDS = 1800


def _reindexer(**context) -> None:
    response = requests.post(AI_SERVICE_URL, timeout=REQUEST_TIMEOUT_SECONDS)
    response.raise_for_status()
    context["ti"].xcom_push(key="ingest_summary", value=response.json())


with DAG(
    dag_id="indexer_rag",
    default_args=default_args,
    schedule_interval="0 3 1 1,4,7,10 *",  # 1er jour de chaque trimestre, 03h00
    start_date=datetime(2026, 1, 1),
    catchup=False,
    tags=["f6", "ia", "rag"],
) as dag:
    reindexer = PythonOperator(task_id="reindexer", python_callable=_reindexer)
