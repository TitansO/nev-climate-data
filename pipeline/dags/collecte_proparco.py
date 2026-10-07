"""Airflow DAG: collecte trimestrielle du financement climat PRIVE via
l'API JSON reelle et publique de Proparco (opendata.afd.fr) - voir
pipeline/collectors/proparco.py pour le detail complet de la verification
menee avant ce connecteur (2026-10-07, demande explicite utilisateur).
Memes 3 taches liees (extraire >> transformer >> publier) que les autres
connecteurs de ce projet - meme principe que collecte_afdb.py pour le
taux de change (fetch une seule fois dans `_extraire`, stage avec les
donnees brutes).
"""
from datetime import datetime

from airflow import DAG
from airflow.operators.python import PythonOperator

from pipeline.collectors.proparco import fetch_eur_to_usd_rate, fetch_proparco_projects, parse_project
from pipeline.common.alerting import default_args
from pipeline.common.kafka_client import make_producer
from pipeline.common.minio_staging import download_json, make_minio_client, upload_json


def _extraire(**context) -> None:
    eur_to_usd_rate = fetch_eur_to_usd_rate()
    raw_projects = list(fetch_proparco_projects())

    object_path = f"bronze/proparco/{context['ds']}/raw.json"
    upload_json(
        make_minio_client(),
        object_path,
        {"eur_to_usd_rate": eur_to_usd_rate, "projects": raw_projects},
    )
    context["ti"].xcom_push(key="raw_path", value=object_path)


def _transformer(**context) -> None:
    raw_path = context["ti"].xcom_pull(task_ids="extraire", key="raw_path")
    staged = download_json(make_minio_client(), raw_path)
    eur_to_usd_rate = staged["eur_to_usd_rate"]

    payloads = []
    for raw_project in staged["projects"]:
        payload = parse_project(raw_project, eur_to_usd_rate)
        if payload is not None:
            payloads.append(payload)

    object_path = f"silver/proparco/{context['ds']}/payloads.json"
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
    dag_id="collecte_proparco",
    default_args=default_args,
    schedule_interval="0 3 1 1,4,7,10 *",  # 1er jour de chaque trimestre, 03h00 - meme cadence que les autres connecteurs
    start_date=datetime(2026, 1, 1),
    catchup=False,
    tags=["collecte", "proparco", "prive"],
) as dag:
    extraire = PythonOperator(task_id="extraire", python_callable=_extraire)
    transformer = PythonOperator(task_id="transformer", python_callable=_transformer)
    publier = PythonOperator(task_id="publier", python_callable=_publier)

    extraire >> transformer >> publier
