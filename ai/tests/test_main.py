"""Unit tests for the ai service's FastAPI routes - db.ensure_schema() and
rag/ingest calls are all mocked, so this file needs neither a real
ai-db connection nor a real Gemini key (same offline-by-default
convention as the rest of this project's test suites).
"""
from unittest.mock import patch

from fastapi.testclient import TestClient

from ai.main import app


def _client() -> TestClient:
    with patch("ai.main.db.ensure_schema"):
        return TestClient(app)


def test_health_returns_ok():
    with _client() as client:
        response = client.get("/health")

    assert response.status_code == 200
    assert response.json() == {"status": "ok"}


def test_ask_returns_the_rag_answer():
    fake_answer = {"available": True, "answer": "Reponse.", "sources": ["docs/a.md"]}
    with _client() as client, patch("ai.main.rag.answer", return_value=fake_answer) as mock_answer:
        response = client.post("/ai/ask", json={"question": "Une question ?"})

    assert response.status_code == 200
    assert response.json() == fake_answer
    mock_answer.assert_called_once_with("Une question ?")


def test_classify_is_not_implemented_yet():
    with _client() as client:
        response = client.post(
            "/ai/classify",
            json={"title": "Projet X", "description": "Desc", "sector": "Agriculture"},
        )

    assert response.status_code == 501
    body = response.json()
    assert body["available"] is False
    assert body["planned_in"] == "C2.7"


def test_greenwashing_is_not_implemented_yet():
    with _client() as client:
        response = client.post(
            "/ai/greenwashing",
            json={"title": "Projet X", "description": "Desc", "amount_usd": 1000000.0},
        )

    assert response.status_code == 501
    body = response.json()
    assert body["available"] is False
    assert body["planned_in"] == "C3.5"


def test_reindex_returns_the_ingest_summary():
    fake_summary = {"markdown_chunks_inserted": 3, "report_chunks_inserted": 1, "aggregate_chunks_inserted": 5}
    with _client() as client, patch("ai.main.ingest.run", return_value=fake_summary) as mock_run:
        response = client.post("/ai/reindex")

    assert response.status_code == 200
    assert response.json() == fake_summary
    mock_run.assert_called_once()
