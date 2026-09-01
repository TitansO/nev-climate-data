"""Tests for the shared Airflow default_args (retries + real failure
alerting via email) - see the 2026-09-01 B1.9 spec.
"""
import importlib
from datetime import timedelta

from pipeline.common import alerting


def test_default_args_alerts_on_failure_when_an_alert_email_is_configured(monkeypatch):
    monkeypatch.setenv("AIRFLOW_ALERT_EMAIL", "ops@example.org")
    importlib.reload(alerting)
    try:
        assert alerting.default_args["email_on_failure"] is True
        assert alerting.default_args["email"] == ["ops@example.org"]
        assert alerting.default_args["email_on_retry"] is False
        assert alerting.default_args["retries"] == 3
        assert alerting.default_args["retry_delay"] == timedelta(minutes=5)
    finally:
        monkeypatch.undo()
        importlib.reload(alerting)


def test_default_args_never_alerts_when_no_alert_email_is_configured(monkeypatch):
    monkeypatch.delenv("AIRFLOW_ALERT_EMAIL", raising=False)
    importlib.reload(alerting)
    try:
        assert alerting.default_args["email_on_failure"] is False
        assert alerting.default_args["email"] == []
    finally:
        monkeypatch.undo()
        importlib.reload(alerting)
