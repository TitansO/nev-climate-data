"""Airflow DAG: annual extraction of the OPEC Fund Climate Finance Report
(B1.5) - see the B1.5 spec, decision 12. Split into 3 linked tasks (extraire
>> transformer >> publier) with a cache short-circuit propagated via XCom -
see the 2026-08-31 multi-task DAG refactor spec, decision 5.
"""
import json
from datetime import date, datetime

import requests
from airflow import DAG
from airflow.operators.python import PythonOperator

from pipeline.collectors.opec_fund_climate_finance import (
    ANNEX_2_END_PAGE,
    ANNEX_2_START_PAGE,
    DOCUMENT_SLUG,
    EXTRACTION_PROMPT,
    REQUEST_TIMEOUT_SECONDS,
    SOURCE_NAME,
    SOURCE_URL,
    build_payloads,
)
from pipeline.common.alerting import default_args
from pipeline.common.db import get_connection
from pipeline.common.kafka_client import make_producer
from pipeline.common.minio_staging import (
    download_bytes,
    download_json,
    make_minio_client,
    upload_bytes,
    upload_json,
)
from pipeline.common.pdf_extraction import (
    extract_json_via_gemini,
    is_already_processed,
    record_processed,
    sha256_hash,
    slice_pdf_pages,
)


def _extraire(**context) -> None:
    response = requests.get(SOURCE_URL, timeout=REQUEST_TIMEOUT_SECONDS)
    response.raise_for_status()
    pdf_bytes = response.content
    document_hash = sha256_hash(pdf_bytes)

    connection = get_connection()
    try:
        with connection:
            with connection.cursor() as cursor:
                already_processed = is_already_processed(cursor, document_hash)
    finally:
        connection.close()

    ti = context["ti"]
    if already_processed:
        ti.xcom_push(key="cache_hit", value=True)
        ti.xcom_push(key="document_hash", value=document_hash)
        return

    annex_bytes = slice_pdf_pages(pdf_bytes, ANNEX_2_START_PAGE, ANNEX_2_END_PAGE)
    today = date.today().isoformat()
    minio_path_pdf = f"bronze/{DOCUMENT_SLUG}/{today}/{document_hash}.pdf"
    minio_path_annex = f"bronze/{DOCUMENT_SLUG}/{today}/{document_hash}-annex.pdf"

    minio_client = make_minio_client()
    upload_bytes(minio_client, minio_path_pdf, pdf_bytes)
    upload_bytes(minio_client, minio_path_annex, annex_bytes)

    ti.xcom_push(key="cache_hit", value=False)
    ti.xcom_push(key="document_hash", value=document_hash)
    ti.xcom_push(key="minio_path_pdf", value=minio_path_pdf)
    ti.xcom_push(key="minio_path_annex", value=minio_path_annex)


def _transformer(**context) -> None:
    ti = context["ti"]
    cache_hit = ti.xcom_pull(task_ids="extraire", key="cache_hit")
    if cache_hit:
        ti.xcom_push(key="cache_hit", value=True)
        return

    document_hash = ti.xcom_pull(task_ids="extraire", key="document_hash")
    minio_path_pdf = ti.xcom_pull(task_ids="extraire", key="minio_path_pdf")
    minio_path_annex = ti.xcom_pull(task_ids="extraire", key="minio_path_annex")

    minio_client = make_minio_client()
    annex_bytes = download_bytes(minio_client, minio_path_annex)
    raw_text = extract_json_via_gemini(annex_bytes, EXTRACTION_PROMPT)
    rows = json.loads(raw_text)

    payloads = []
    for row in rows:
        payloads.extend(build_payloads(row, document_hash))

    object_path = f"silver/{DOCUMENT_SLUG}/{context['ds']}/payloads.json"
    upload_json(minio_client, object_path, payloads)

    ti.xcom_push(key="cache_hit", value=False)
    ti.xcom_push(key="document_hash", value=document_hash)
    ti.xcom_push(key="minio_path_pdf", value=minio_path_pdf)
    ti.xcom_push(key="payloads_path", value=object_path)
    ti.xcom_push(key="rows_extracted", value=len(rows))


def _publier(**context) -> None:
    ti = context["ti"]
    cache_hit = ti.xcom_pull(task_ids="transformer", key="cache_hit")
    if cache_hit:
        ti.xcom_push(key="published_count", value=0)
        return

    document_hash = ti.xcom_pull(task_ids="transformer", key="document_hash")
    minio_path_pdf = ti.xcom_pull(task_ids="transformer", key="minio_path_pdf")
    payloads_path = ti.xcom_pull(task_ids="transformer", key="payloads_path")
    rows_extracted = ti.xcom_pull(task_ids="transformer", key="rows_extracted")

    minio_client = make_minio_client()
    payloads = download_json(minio_client, payloads_path)

    producer = make_producer()
    for payload in payloads:
        producer.send("nev.funding.raw", payload)
    producer.flush()

    connection = get_connection()
    try:
        with connection:
            with connection.cursor() as cursor:
                record_processed(
                    cursor,
                    document_hash=document_hash,
                    source_name=SOURCE_NAME,
                    source_url=SOURCE_URL,
                    minio_path=minio_path_pdf,
                    rows_extracted=rows_extracted,
                )
    finally:
        connection.close()

    ti.xcom_push(key="published_count", value=len(payloads))


with DAG(
    dag_id="extraction_pdf",
    default_args=default_args,
    schedule_interval="0 3 1 1 *",  # 1er janvier, 03h00 - annuel, cf. spec decision 12
    start_date=datetime(2026, 1, 1),
    catchup=False,
    tags=["b1.5", "extraction", "pdf", "opec-fund"],
) as dag:
    extraire = PythonOperator(task_id="extraire", python_callable=_extraire)
    transformer = PythonOperator(task_id="transformer", python_callable=_transformer)
    publier = PythonOperator(task_id="publier", python_callable=_publier)

    extraire >> transformer >> publier
