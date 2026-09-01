"""Shared Airflow default_args for every Volet B DAG - retries + real
failure alerting (email), configured once instead of duplicated 5 times.
See the 2026-09-01 B1.9 spec.
"""
from __future__ import annotations

import os
from datetime import timedelta

ALERT_EMAIL = os.environ.get("AIRFLOW_ALERT_EMAIL", "")

default_args = {
    "owner": "nev-climate-data",
    "retries": 3,
    "retry_delay": timedelta(minutes=5),
    # Alert only once a task has exhausted all its retries, not on every
    # individual attempt - a slow/rate-limited source (AfDB) would
    # otherwise generate noise on every run that needed even one retry.
    "email_on_failure": bool(ALERT_EMAIL),
    "email_on_retry": False,
    "email": [ALERT_EMAIL] if ALERT_EMAIL else [],
}
