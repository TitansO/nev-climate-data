"""Airflow DAG: annual collection of PNUE (UN SDG API) CO2 emissions data
for every country NEV tracks - see the B1.4 spec, decision 12 (annual
schedule, matching the roadmap's explicit wording) and decision 2 (the
country list comes from NEV's own `country` table, same pattern as
collecte_worldbank.py).
"""
from datetime import datetime, timedelta

from airflow import DAG
from airflow.operators.python import PythonOperator

from pipeline.collectors.pnue import collect_and_publish
from pipeline.common.db import get_connection
from pipeline.common.kafka_client import make_producer

default_args = {
    "owner": "nev-climate-data",
    "retries": 3,
    "retry_delay": timedelta(minutes=5),
}


def _collect(**context) -> None:
    connection = get_connection()
    try:
        with connection.cursor() as cursor:
            cursor.execute("SELECT iso_code FROM country ORDER BY iso_code")
            country_isos = [row[0] for row in cursor.fetchall()]
    finally:
        connection.close()

    # No alpha-2 conversion needed here (unlike collecte_worldbank.py) -
    # `country.iso_code` is already alpha-3, and pnue.py's own
    # country_iso3_to_m49() converts alpha-3 directly to the UN M49 code
    # the SDG API expects.
    producer = make_producer()
    published = collect_and_publish(country_isos, producer)
    context["ti"].xcom_push(key="published_count", value=published)


with DAG(
    dag_id="collecte_pnue",
    default_args=default_args,
    schedule_interval="0 3 1 1 *",  # 1er janvier, 03h00 - annuel, cf. spec decision 12
    start_date=datetime(2026, 1, 1),
    catchup=False,
    tags=["b1.4", "collecte", "pnue"],
) as dag:
    collecter = PythonOperator(
        task_id="collecter_emissions_co2",
        python_callable=_collect,
    )
