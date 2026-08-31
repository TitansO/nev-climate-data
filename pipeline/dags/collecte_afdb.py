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
