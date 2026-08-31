"""Tests for the generic MinIO staging helpers (bronze/silver transit
between Airflow tasks) - see the 2026-08-31 multi-task DAG refactor spec,
decision 3.
"""
from unittest.mock import MagicMock

from pipeline.common.minio_staging import (
    MINIO_BUCKET,
    download_bytes,
    download_json,
    upload_bytes,
    upload_json,
)


def test_upload_bytes_creates_the_bucket_if_missing():
    mock_client = MagicMock()
    mock_client.bucket_exists.return_value = False

    upload_bytes(mock_client, "bronze/test/raw.json", b"some-bytes")

    mock_client.make_bucket.assert_called_once_with(MINIO_BUCKET)
    mock_client.put_object.assert_called_once()
    call_args = mock_client.put_object.call_args
    assert call_args[0][0] == MINIO_BUCKET
    assert call_args[0][1] == "bronze/test/raw.json"


def test_upload_bytes_skips_bucket_creation_when_it_already_exists():
    mock_client = MagicMock()
    mock_client.bucket_exists.return_value = True

    upload_bytes(mock_client, "bronze/test/raw.json", b"some-bytes")

    mock_client.make_bucket.assert_not_called()


def test_download_bytes_reads_and_releases_the_response():
    mock_client = MagicMock()
    mock_response = MagicMock()
    mock_response.read.return_value = b"downloaded-bytes"
    mock_client.get_object.return_value = mock_response

    result = download_bytes(mock_client, "bronze/test/raw.json")

    assert result == b"downloaded-bytes"
    mock_client.get_object.assert_called_once_with(MINIO_BUCKET, "bronze/test/raw.json")
    mock_response.close.assert_called_once()
    mock_response.release_conn.assert_called_once()


def test_upload_json_serializes_and_uploads():
    mock_client = MagicMock()
    mock_client.bucket_exists.return_value = True

    upload_json(mock_client, "silver/test/payloads.json", [{"a": 1}])

    call_args = mock_client.put_object.call_args
    uploaded_stream = call_args[0][2]
    assert uploaded_stream.read() == b'[{"a": 1}]'


def test_download_json_reads_and_deserializes():
    mock_client = MagicMock()
    mock_response = MagicMock()
    mock_response.read.return_value = b'[{"a": 1}]'
    mock_client.get_object.return_value = mock_response

    result = download_json(mock_client, "silver/test/payloads.json")

    assert result == [{"a": 1}]
