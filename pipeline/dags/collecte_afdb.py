"""Airflow DAG: quarterly collection of African Development Bank Group
(BAD/AfDB) project financing via the IATI Datastore - see the B1.3 spec.
Like B1.2's GCF DAG (and unlike B1.1's World Bank DAG), this does not need
NEV's own `country` table as an input - the collector paginates AfDB's
entire portfolio itself and lets country validity be decided downstream
by the funding-validator.
"""
from datetime import datetime, timedelta

from airflow import DAG
from airflow.operators.python import PythonOperator

from pipeline.collectors.afdb import collect_and_publish
from pipeline.common.kafka_client import make_producer

default_args = {
    "owner": "nev-climate-data",
    "retries": 3,
    "retry_delay": timedelta(minutes=5),
}


def _collect(**context) -> None:
    producer = make_producer()
    published = collect_and_publish(producer)
    context["ti"].xcom_push(key="published_count", value=published)


with DAG(
    dag_id="collecte_afdb",
    default_args=default_args,
    schedule_interval="0 3 1 1,4,7,10 *",  # 1er jour de chaque trimestre, 03h00
    start_date=datetime(2026, 1, 1),
    catchup=False,
    tags=["b1.3", "collecte", "afdb"],
) as dag:
    collecter = PythonOperator(
        task_id="collecter_financements_afdb",
        python_callable=_collect,
    )
