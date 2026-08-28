"""Airflow DAG: monthly collection of Green Climate Fund (GCF) project
financing via the IATI Datastore - see the B1.2 spec, decision 1 (a
single request covers GCF's entire IATI-published portfolio, no
per-country querying needed - unlike B1.1's World Bank DAG, this one does
not need NEV's own `country` table at all) and the plan's own B1.2
livrable ("DAG Airflow mensuel").
"""
from datetime import datetime, timedelta

from airflow import DAG
from airflow.operators.python import PythonOperator

from pipeline.collectors.gcf import collect_and_publish
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
    dag_id="collecte_gcf",
    default_args=default_args,
    schedule_interval="0 3 1 * *",  # 1er jour de chaque mois, 03h00
    start_date=datetime(2026, 1, 1),
    catchup=False,
    tags=["b1.2", "collecte", "gcf"],
) as dag:
    collecter = PythonOperator(
        task_id="collecter_financements_gcf",
        python_callable=_collect,
    )
