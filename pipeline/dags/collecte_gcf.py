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
