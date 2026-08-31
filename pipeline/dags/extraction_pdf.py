"""Airflow DAG: annual extraction of the OPEC Fund Climate Finance Report
(B1.5) - see the B1.5 spec, decision 12 (annual schedule, matching the
real publication cadence of the source document itself - a 2025 edition
already exists).
"""
from datetime import datetime, timedelta

from airflow import DAG
from airflow.operators.python import PythonOperator

from pipeline.collectors.opec_fund_climate_finance import collect_and_publish
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
    dag_id="extraction_pdf",
    default_args=default_args,
    schedule_interval="0 3 1 1 *",  # 1er janvier, 03h00 - annuel, cf. spec decision 12
    start_date=datetime(2026, 1, 1),
    catchup=False,
    tags=["b1.5", "extraction", "pdf", "opec-fund"],
) as dag:
    collecter = PythonOperator(
        task_id="extraire_rapport_opec_fund",
        python_callable=_collect,
    )
