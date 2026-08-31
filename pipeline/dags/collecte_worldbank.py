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
