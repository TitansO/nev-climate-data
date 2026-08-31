"""Generic MinIO staging helpers shared by every Volet B DAG - upload and
download of raw bytes and JSON blobs to the bronze/silver zones between
Airflow tasks. See the 2026-08-31 multi-task DAG refactor spec, decisions 2
and 3. Has no connector-specific knowledge (object-path conventions live in
each DAG file).
"""
from __future__ import annotations

import io
import json
import os
from typing import Any

from minio import Minio

MINIO_BUCKET = "nev-climate-data"


def make_minio_client() -> Minio:
    return Minio(
        os.environ.get("MINIO_ENDPOINT", "minio:9000"),
        access_key=os.environ["MINIO_ROOT_USER"],
        secret_key=os.environ["MINIO_ROOT_PASSWORD"],
        secure=False,
    )


def upload_bytes(client: Minio, object_path: str, data: bytes) -> None:
    if not client.bucket_exists(MINIO_BUCKET):
        client.make_bucket(MINIO_BUCKET)
    client.put_object(MINIO_BUCKET, object_path, io.BytesIO(data), length=len(data))


def download_bytes(client: Minio, object_path: str) -> bytes:
    response = client.get_object(MINIO_BUCKET, object_path)
    try:
        return response.read()
    finally:
        response.close()
        response.release_conn()


def upload_json(client: Minio, object_path: str, data: Any) -> None:
    upload_bytes(client, object_path, json.dumps(data).encode("utf-8"))


def download_json(client: Minio, object_path: str) -> Any:
    return json.loads(download_bytes(client, object_path).decode("utf-8"))
