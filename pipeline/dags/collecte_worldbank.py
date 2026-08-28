"""Airflow DAG: quarterly collection of World Bank climate-themed project
financing for every country NEV tracks - see the B1.1 spec, decision 1
(the country list comes from NEV's own `country` table, not a
hard-coded list) and decision 8 (quarterly schedule).
"""
from datetime import datetime, timedelta

import pycountry
from airflow import DAG
from airflow.operators.python import PythonOperator

from pipeline.collectors.world_bank import collect_and_publish
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
            country_isos_alpha3 = [row[0] for row in cursor.fetchall()]
    finally:
        connection.close()

    # `country.iso_code` is alpha-3 (see Country.php, A1.3), but the World Bank API's
    # `countrycode_exact` filter expects alpha-2 (verified live: querying with "SEN" returns 0
    # projects, "SN" returns 264 - confirmed while running this DAG end-to-end for real). A
    # country pycountry doesn't recognize is skipped rather than passed through unconverted -
    # an alpha-3 code sent to the API would just as silently return 0 projects for it.
    country_isos = []
    for alpha3 in country_isos_alpha3:
        country = pycountry.countries.get(alpha_3=alpha3)
        if country is not None:
            country_isos.append(country.alpha_2)

    producer = make_producer()
    published = collect_and_publish(country_isos, producer)
    context["ti"].xcom_push(key="published_count", value=published)


with DAG(
    dag_id="collecte_worldbank",
    default_args=default_args,
    schedule_interval="0 3 1 1,4,7,10 *",  # 1er jour de chaque trimestre, 03h00
    start_date=datetime(2026, 1, 1),
    catchup=False,
    tags=["b1.1", "collecte", "world-bank"],
) as dag:
    collecter = PythonOperator(
        task_id="collecter_financements_banque_mondiale",
        python_callable=_collect,
    )
