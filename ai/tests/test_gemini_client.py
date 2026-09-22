"""Unit tests for ai/gemini_client.py - mocks google.genai.Client entirely
(same "no live network by default" convention as
pipeline/tests/test_*_collector.py), so this covers the retry/wiring
logic this module actually owns, not the SDK itself. Embeddings moved to
ai/local_embeddings.py (see its own module docstring: Gemini's free-tier
embedding quota proved too tight for real RAG traffic) - the retry
behavior _with_retry() provides is still shared by ask()/classify(), so
it's exercised here through ask() instead of the now-removed embed().
"""
from unittest.mock import MagicMock, patch

import httpx
import pytest
from google.genai import errors

from ai import gemini_client


def _fake_text_response(text):
    response = MagicMock()
    response.text = text
    return response


def test_ask_grounds_the_prompt_in_the_provided_context_only():
    fake_client = MagicMock()
    fake_client.models.generate_content.return_value = _fake_text_response("Reponse.")

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


def test_ask_retries_on_a_transient_server_error_then_succeeds():
    fake_client = MagicMock()
    fake_client.models.generate_content.side_effect = [
        errors.ServerError(503, {"error": {"message": "overloaded"}}),
        _fake_text_response("Reponse."),
    ]

    with patch("ai.gemini_client._client", return_value=fake_client), \
         patch("ai.gemini_client.time.sleep"):
        result = gemini_client.ask("Question ?", ["Contexte"])

    assert result == "Reponse."
    assert fake_client.models.generate_content.call_count == 2


def test_ask_retries_on_a_429_rate_limit_then_succeeds():
    # Real finding from this service's own F5/F6 live verification: bulk
    # calls can hit the free tier's rate limit mid-run - a genuine,
    # expected condition (see docs/exploitation/suivi-couts.md risk R5),
    # delivered as errors.ClientError(429, ...), a different exception
    # class from ServerError - must be retried too, not just 5xx errors.
    fake_client = MagicMock()
    fake_client.models.generate_content.side_effect = [
        errors.ClientError(429, {"error": {"message": "quota exceeded"}}),
        _fake_text_response("Reponse."),
    ]

    with patch("ai.gemini_client._client", return_value=fake_client), \
         patch("ai.gemini_client.time.sleep") as mock_sleep:
        result = gemini_client.ask("Question ?", ["Contexte"])

    assert result == "Reponse."
    assert fake_client.models.generate_content.call_count == 2
    mock_sleep.assert_called_once_with(gemini_client.RATE_LIMIT_RETRY_DELAY_SECONDS)


def test_ask_does_not_retry_a_non_rate_limit_client_error():
    # A genuine 4xx that isn't a rate limit (bad model name, malformed
    # request) is a real bug, not a transient condition - must surface
    # immediately, not be retried and masked for MAX_RETRIES attempts.
    fake_client = MagicMock()
    fake_client.models.generate_content.side_effect = errors.ClientError(
        404, {"error": {"message": "model not found"}}
    )

    with patch("ai.gemini_client._client", return_value=fake_client), \
         patch("ai.gemini_client.time.sleep"):
        with pytest.raises(errors.ClientError):
            gemini_client.ask("Question ?", ["Contexte"])

    assert fake_client.models.generate_content.call_count == 1


def test_ask_gives_up_after_max_retries():
    fake_client = MagicMock()
    fake_client.models.generate_content.side_effect = httpx.ReadTimeout("timed out")

    with patch("ai.gemini_client._client", return_value=fake_client), \
         patch("ai.gemini_client.time.sleep"):
        with pytest.raises(RuntimeError, match="Gemini call failed"):
            gemini_client.ask("Question ?", ["Contexte"])

    assert fake_client.models.generate_content.call_count == gemini_client.MAX_RETRIES
