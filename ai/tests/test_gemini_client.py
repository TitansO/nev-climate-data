"""Unit tests for ai/gemini_client.py - mocks google.genai.Client entirely
(same "no live network by default" convention as
pipeline/tests/test_*_collector.py), so this covers the retry/wiring
logic this module actually owns, not the SDK itself.
"""
from unittest.mock import MagicMock, patch

import httpx
import pytest
from google.genai import errors

from ai import gemini_client


def _fake_embedding_response(values):
    embedding = MagicMock()
    embedding.values = values
    response = MagicMock()
    response.embeddings = [embedding]
    return response


def test_embed_returns_the_values_from_the_first_embedding():
    fake_client = MagicMock()
    fake_client.models.embed_content.return_value = _fake_embedding_response([0.1, 0.2])

    with patch("ai.gemini_client._client", return_value=fake_client):
        result = gemini_client.embed("un texte")

    assert result == [0.1, 0.2]
    call_kwargs = fake_client.models.embed_content.call_args.kwargs
    assert call_kwargs["model"] == gemini_client.EMBEDDING_MODEL
    assert call_kwargs["contents"] == "un texte"
    assert call_kwargs["config"].output_dimensionality == gemini_client.EMBEDDING_DIMENSIONS


def test_embed_retries_on_a_transient_server_error_then_succeeds():
    fake_client = MagicMock()
    fake_client.models.embed_content.side_effect = [
        errors.ServerError(503, {"error": {"message": "overloaded"}}),
        _fake_embedding_response([0.5]),
    ]

    with patch("ai.gemini_client._client", return_value=fake_client), \
         patch("ai.gemini_client.time.sleep"):
        result = gemini_client.embed("un texte")

    assert result == [0.5]
    assert fake_client.models.embed_content.call_count == 2


def test_embed_retries_on_a_429_rate_limit_then_succeeds():
    # Real finding from this service's own F5/F6 live verification: bulk
    # embedding during /ai/reindex hit the free tier's rate limit
    # mid-run - a genuine, expected condition (see
    # docs/exploitation/suivi-couts.md risk R5), delivered as
    # errors.ClientError(429, ...), a different exception class from
    # ServerError - must be retried too, not just 5xx errors.
    fake_client = MagicMock()
    fake_client.models.embed_content.side_effect = [
        errors.ClientError(429, {"error": {"message": "quota exceeded"}}),
        _fake_embedding_response([0.9]),
    ]

    with patch("ai.gemini_client._client", return_value=fake_client), \
         patch("ai.gemini_client.time.sleep") as mock_sleep:
        result = gemini_client.embed("un texte")

    assert result == [0.9]
    assert fake_client.models.embed_content.call_count == 2
    mock_sleep.assert_called_once_with(gemini_client.RATE_LIMIT_RETRY_DELAY_SECONDS)


def test_embed_does_not_retry_a_non_rate_limit_client_error():
    # A genuine 4xx that isn't a rate limit (bad model name, malformed
    # request) is a real bug, not a transient condition - must surface
    # immediately, not be retried and masked for MAX_RETRIES attempts.
    fake_client = MagicMock()
    fake_client.models.embed_content.side_effect = errors.ClientError(
        404, {"error": {"message": "model not found"}}
    )

    with patch("ai.gemini_client._client", return_value=fake_client), \
         patch("ai.gemini_client.time.sleep"):
        with pytest.raises(errors.ClientError):
            gemini_client.embed("un texte")

    assert fake_client.models.embed_content.call_count == 1


def test_embed_gives_up_after_max_retries():
    fake_client = MagicMock()
    fake_client.models.embed_content.side_effect = httpx.ReadTimeout("timed out")

    with patch("ai.gemini_client._client", return_value=fake_client), \
         patch("ai.gemini_client.time.sleep"):
        with pytest.raises(RuntimeError, match="Gemini call failed"):
            gemini_client.embed("un texte")

    assert fake_client.models.embed_content.call_count == gemini_client.MAX_RETRIES


def test_ask_grounds_the_prompt_in_the_provided_context_only():
    fake_client = MagicMock()
    fake_response = MagicMock()
    fake_response.text = "Reponse."
    fake_client.models.generate_content.return_value = fake_response

    with patch("ai.gemini_client._client", return_value=fake_client):
        result = gemini_client.ask("Question ?", ["Contexte A", "Contexte B"])

    assert result == "Reponse."
    call_kwargs = fake_client.models.generate_content.call_args.kwargs
    assert call_kwargs["model"] == gemini_client.CHAT_MODEL
    prompt = call_kwargs["contents"][0]
    assert "Contexte A" in prompt
    assert "Contexte B" in prompt
    assert "Question ?" in prompt
    assert "UNIQUEMENT" in prompt
