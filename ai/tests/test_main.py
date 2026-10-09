"""Unit tests for the ai service's FastAPI routes - db.ensure_schema() and
rag/ingest calls are all mocked, so this file needs neither a real
ai-db connection nor a real Gemini key (same offline-by-default
convention as the rest of this project's test suites).
"""
import os
from contextlib import contextmanager
from unittest.mock import MagicMock, patch

from fastapi.testclient import TestClient

from ai.main import app


@contextmanager
def _client():
    """`TestClient.__enter__` (not the constructor) is what fires the ASGI
    lifespan startup event, i.e. `db.ensure_schema()` - a `with patch(...):
    return TestClient(app)` body closes the patch as soon as it returns,
    before any caller's `with _client() as client:` reaches `__enter__`, so
    every real lifespan boot still hit a genuine (and here unreachable)
    database. Found live: all 7 tests in this file that go through
    `_client()` failed in CI with `psycopg2.OperationalError: connection ...
    Connection refused`, while the other 30 ai/tests/ tests (which never
    touch TestClient) passed - the patch has to stay entered for as long as
    the TestClient itself is, hence wrapping both in one context manager.
    """
    with patch("ai.main.db.ensure_schema"), TestClient(app) as client:
        yield client


def test_health_returns_ok_when_database_and_gemini_config_are_both_fine():
    fake_connection = MagicMock()
    fake_connection.cursor.return_value.__enter__.return_value = MagicMock()
    with (
        _client() as client,
        patch("ai.main.db.get_connection", return_value=fake_connection),
        patch.dict("os.environ", {"GEMINI_API_KEY": "fake-key-for-tests"}),
        patch("ai.main.gemini_client._client"),
    ):
        response = client.get("/health")

    assert response.status_code == 200
    assert response.json() == {"status": "ok", "database": True, "gemini_configured": True}
    fake_connection.close.assert_called_once()


def test_health_returns_503_when_the_database_is_unreachable():
    with (
        _client() as client,
        patch("ai.main.db.get_connection", side_effect=Exception("connection refused")),
        patch.dict("os.environ", {"GEMINI_API_KEY": "fake-key-for-tests"}),
        patch("ai.main.gemini_client._client"),
    ):
        response = client.get("/health")

    assert response.status_code == 503
    body = response.json()
    assert body["status"] == "degraded"
    assert body["database"] is False


def test_health_returns_503_when_the_gemini_api_key_is_missing():
    fake_connection = MagicMock()
    fake_connection.cursor.return_value.__enter__.return_value = MagicMock()
    with (
        _client() as client,
        patch("ai.main.db.get_connection", return_value=fake_connection),
        patch.dict("os.environ", {}, clear=False),
    ):
        os.environ.pop("GEMINI_API_KEY", None)
        response = client.get("/health")

    assert response.status_code == 503
    body = response.json()
    assert body["status"] == "degraded"
    assert body["gemini_configured"] is False


def test_ask_returns_the_rag_answer():
    fake_answer = {"available": True, "answer": "Reponse.", "sources": ["docs/a.md"]}
    with _client() as client, patch("ai.main.rag.answer", return_value=fake_answer) as mock_answer:
        response = client.post("/ai/ask", json={"question": "Une question ?"})

    assert response.status_code == 200
    assert response.json() == fake_answer
    mock_answer.assert_called_once_with("Une question ?")


def test_classify_returns_the_classification_result():
    fake_result = {"available": True, "cached": False, "taxonomy_class": "mitigation", "activity_category": "energie renouvelable", "reasoning": "Reduit des emissions futures."}
    with _client() as client, patch("ai.main.classify_module.classify_activity", return_value=fake_result) as mock_classify:
        response = client.post(
            "/ai/classify",
            json={"title": "Projet X", "description": "Desc", "sector": "Agriculture"},
        )

    assert response.status_code == 200
    assert response.json() == fake_result
    mock_classify.assert_called_once_with("Projet X", "Desc", "Agriculture")


def test_greenwashing_returns_the_check_result():
    fake_result = {"available": True, "cached": False, "risk_level": "low", "red_flags": [], "reasoning": "Rien de suspect."}
    with _client() as client, patch("ai.main.greenwashing_module.check", return_value=fake_result) as mock_check:
        response = client.post(
            "/ai/greenwashing",
            json={"title": "Projet X", "description": "Desc", "amount_usd": 1000000.0, "sector": "Agriculture"},
        )

    assert response.status_code == 200
    assert response.json() == fake_result
    mock_check.assert_called_once_with("Projet X", "Desc", 1000000.0, "Agriculture", None)


def test_reindex_returns_the_ingest_summary():
    fake_summary = {"markdown_chunks_inserted": 3, "report_chunks_inserted": 1, "aggregate_chunks_inserted": 5}
    with _client() as client, patch("ai.main.ingest.run", return_value=fake_summary) as mock_run:
        response = client.post("/ai/reindex")

    assert response.status_code == 200
    assert response.json() == fake_summary
    mock_run.assert_called_once()
