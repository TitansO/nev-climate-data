"""Unit tests for ai/greenwashing.py - mocked (same offline-by-default
convention as ai/tests/test_classify.py): no real ai-db, no real Gemini call.
"""
from unittest.mock import MagicMock, patch

from ai import greenwashing


def test_check_calls_gemini_and_caches_on_a_miss():
    mock_cursor = MagicMock()
    mock_cursor.fetchone.return_value = None  # not cached
    mock_connection = MagicMock()
    mock_connection.cursor.return_value.__enter__.return_value = mock_cursor

    gemini_result = {
        "risk_level": "high",
        "red_flags": ["Montant disproportionne par rapport au descriptif"],
        "reasoning": "Le montant demande est tres superieur aux projets comparables de ce secteur.",
    }

    with patch("ai.greenwashing.gemini_client.check_greenwashing", return_value=gemini_result) as mock_check, \
         patch("ai.greenwashing.classify_module.classify_activity") as mock_classify, \
         patch("ai.greenwashing.db.get_connection", return_value=mock_connection):
        result = greenwashing.check("Solar Farm", "Ferme solaire.", 500_000.0, "Renewable Energy", taxonomy_class="mitigation")

    # taxonomy_class was already provided - no need to classify the activity first.
    mock_classify.assert_not_called()
    mock_check.assert_called_once_with("Solar Farm", "Ferme solaire.", 500_000.0, "mitigation")
    mock_connection.commit.assert_called_once()
    assert result == {"available": True, "cached": False, **gemini_result}
    # the INSERT is the 2nd execute() call (the 1st is the cache lookup)
    insert_args = mock_cursor.execute.call_args_list[1].args
    assert insert_args[1][1] == "high"


def test_check_serves_the_cache_without_calling_gemini():
    mock_cursor = MagicMock()
    mock_cursor.fetchone.return_value = ("low", '["Aucun signal notable"]', "Le libelle et le montant sont coherents.")
    mock_connection = MagicMock()
    mock_connection.cursor.return_value.__enter__.return_value = mock_cursor

    with patch("ai.greenwashing.gemini_client.check_greenwashing") as mock_check, \
         patch("ai.greenwashing.classify_module.classify_activity") as mock_classify, \
         patch("ai.greenwashing.db.get_connection", return_value=mock_connection):
        result = greenwashing.check("Flood Warning Network", "Systeme d'alerte.", 200_000.0, "Adaptation")

    mock_check.assert_not_called()
    mock_classify.assert_not_called()
    mock_connection.commit.assert_not_called()
    assert result == {
        "available": True,
        "cached": True,
        "risk_level": "low",
        "red_flags": ["Aucun signal notable"],
        "reasoning": "Le libelle et le montant sont coherents.",
    }


def test_check_classifies_the_activity_first_when_taxonomy_class_is_missing():
    mock_cursor = MagicMock()
    mock_cursor.fetchone.return_value = None
    mock_connection = MagicMock()
    mock_connection.cursor.return_value.__enter__.return_value = mock_cursor

    gemini_result = {"risk_level": "moderate", "red_flags": [], "reasoning": "A verifier."}

    with patch("ai.greenwashing.gemini_client.check_greenwashing", return_value=gemini_result) as mock_check, \
         patch("ai.greenwashing.classify_module.classify_activity", return_value={"taxonomy_class": "adaptation"}) as mock_classify, \
         patch("ai.greenwashing.db.get_connection", return_value=mock_connection):
        greenwashing.check("Levee Project", "Construction d'une digue.", 1_000_000.0, "Infrastructure")

    mock_classify.assert_called_once_with("Levee Project", "Construction d'une digue.", "Infrastructure")
    mock_check.assert_called_once_with("Levee Project", "Construction d'une digue.", 1_000_000.0, "adaptation")


def test_hash_distinguishes_different_triplets():
    # Same title, different description/amount -> a different hash - the
    # cache must never conflate two genuinely different check requests.
    assert greenwashing._hash("Title", "Description A", 1000.0, "Renewable Energy") != greenwashing._hash("Title", "Description B", 1000.0, "Renewable Energy")
    assert greenwashing._hash("Title", "Description A", 1000.0, "Renewable Energy") != greenwashing._hash("Title", "Description A", 2000.0, "Renewable Energy")


def test_hash_distinguishes_different_sectors():
    # Audit 2026-10-01 : regression test for the cache-key collision found
    # during the full-project audit - two records sharing title/description/
    # amount but with a different sector (hence a potentially different
    # taxonomy_class, and therefore a different Gemini verdict) must never
    # hash identically, or one would silently serve the other's cached
    # risk_level/red_flags.
    assert greenwashing._hash("Solar Farm", "Ferme solaire.", 500_000.0, "Renewable Energy") != greenwashing._hash(
        "Solar Farm", "Ferme solaire.", 500_000.0, "Adaptation"
    )
