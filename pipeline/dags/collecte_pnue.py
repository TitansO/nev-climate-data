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
