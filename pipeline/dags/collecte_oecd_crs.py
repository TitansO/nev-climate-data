"""Airflow DAG: collecte trimestrielle du financement climat PRIVE via
l'API SDMX reelle d'OCDE CRS (Private Development Finance) - voir
pipeline/collectors/oecd_crs.py pour le detail complet de la verification
menee avant ce connecteur (2026-10-07, demande explicite utilisateur).
Memes 3 taches liees (extraire >> transformer >> publier) que les autres
connecteurs de ce projet.
"""
from datetime import datetime

from airflow import DAG
from airflow.operators.python import PythonOperator

from pipeline.collectors.oecd_crs import fetch_crs_rows, parse_row
from pipeline.common.alerting import default_args
from pipeline.common.kafka_client import make_producer
from pipeline.common.minio_staging import download_json, make_minio_client, upload_json


def _extraire(**context) -> None:
    raw_rows = fetch_crs_rows()

    object_path = f"bronze/oecd_crs/{context['ds']}/raw.json"
    upload_json(make_minio_client(), object_path, raw_rows)
    context["ti"].xcom_push(key="raw_path", value=object_path)


def _transformer(**context) -> None:
    raw_path = context["ti"].xcom_pull(task_ids="extraire", key="raw_path")
    raw_rows = download_json(make_minio_client(), raw_path)

    payloads = []
    for raw_row in raw_rows:
        payload = parse_row(raw_row)
        if payload is not None:
            payloads.append(payload)

    object_path = f"silver/oecd_crs/{context['ds']}/payloads.json"
    upload_json(make_minio_client(), object_path, payloads)
    context["ti"].xcom_push(key="payloads_path", value=object_path)


def _publier(**context) -> None:
    payloads_path = context["ti"].xcom_pull(task_ids="transformer", key="payloads_path")
    payloads = download_json(make_minio_client(), payloads_path)

    producer = make_producer()
    for payload in payloads:
        producer.send("nev.funding.raw", payload, key=f"{payload['source']}:{payload['project_id']}:{payload['country_iso']}")
    producer.flush()
    context["ti"].xcom_push(key="published_count", value=len(payloads))


with DAG(
    dag_id="collecte_oecd_crs",
    default_args=default_args,
    schedule_interval="0 3 1 1,4,7,10 *",  # 1er jour de chaque trimestre, 03h00 - meme cadence que les autres connecteurs
    start_date=datetime(2026, 1, 1),
    catchup=False,
    tags=["collecte", "oecd_crs", "prive"],
) as dag:
    extraire = PythonOperator(task_id="extraire", python_callable=_extraire)
    transformer = PythonOperator(task_id="transformer", python_callable=_transformer)
    publier = PythonOperator(task_id="publier", python_callable=_publier)

    extraire >> transformer >> publier
