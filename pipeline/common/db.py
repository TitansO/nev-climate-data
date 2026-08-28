"""Shared TimescaleDB connection helper for the Volet B pipeline.

Reads PIPELINE_DATABASE_URL, a plain psycopg2 DSN (not a SQLAlchemy URL) -
see the `pipeline` and `airflow` service definitions in docker-compose.yml
for how it's built from the same POSTGRES_* variables the Symfony backend
uses.
"""
import os

import psycopg2


def get_connection():
    """Opens a new psycopg2 connection to the shared TimescaleDB instance.

    Callers are responsible for closing the connection (or using it as a
    context manager) - this function does not pool or cache connections,
    matching the short-lived-script usage pattern of Airflow tasks and the
    one-connection-per-message usage of the Kafka consumer service.
    """
    dsn = os.environ["PIPELINE_DATABASE_URL"]
    return psycopg2.connect(dsn)
