"""Unit tests for ai/classify.py - mocked (same offline-by-default
convention as ai/tests/test_rag.py): no real ai-db, no real Gemini call.
"""
from unittest.mock import MagicMock, patch

from ai import classify


def test_classify_activity_calls_gemini_and_caches_on_a_miss():
    mock_cursor = MagicMock()
    mock_cursor.fetchone.return_value = None  # not cached
    mock_connection = MagicMock()
    mock_connection.cursor.return_value.__enter__.return_value = mock_cursor

    gemini_result = {"taxonomy_class": "mitigation", "activity_category": "production d'energie renouvelable", "reasoning": "Reduit des emissions futures."}

    with patch("ai.classify.gemini_client.classify", return_value=gemini_result) as mock_classify, \
         patch("ai.classify.db.get_connection", return_value=mock_connection):
        result = classify.classify_activity("Solar Farm", "Ferme solaire.", "Renewable Energy")

    mock_classify.assert_called_once_with("Solar Farm", "Ferme solaire.", "Renewable Energy")
    mock_connection.commit.assert_called_once()
    assert result == {"available": True, "cached": False, **gemini_result}
    # the INSERT is the 2nd execute() call (the 1st is the cache lookup)
    insert_args = mock_cursor.execute.call_args_list[1].args
    assert insert_args[1][1:] == ("mitigation", "production d'energie renouvelable", "Reduit des emissions futures.")


def test_classify_activity_serves_the_cache_without_calling_gemini():
    mock_cursor = MagicMock()
    mock_cursor.fetchone.return_value = ("adaptation", "reduction des risques climatiques", "Reduit la vulnerabilite a un risque deja present.")
    mock_connection = MagicMock()
    mock_connection.cursor.return_value.__enter__.return_value = mock_cursor

    with patch("ai.classify.gemini_client.classify") as mock_classify, \
         patch("ai.classify.db.get_connection", return_value=mock_connection):
        result = classify.classify_activity("Flood Warning Network", "Systeme d'alerte.", "Adaptation")

    mock_classify.assert_not_called()
    mock_connection.commit.assert_not_called()
    assert result == {
        "available": True,
        "cached": True,
        "taxonomy_class": "adaptation",
        "activity_category": "reduction des risques climatiques",
        "reasoning": "Reduit la vulnerabilite a un risque deja present.",
    }


def test_classify_activity_hashes_the_full_triplet_not_just_the_title():
    # Same title, different description/sector -> a different hash, a
    # different (potential) cache entry - the cache must never conflate
    # two genuinely different classification requests.
    assert classify._hash("Title", "Description A", "Sector A") != classify._hash("Title", "Description B", "Sector A")
    assert classify._hash("Title", "Description A", "Sector A") != classify._hash("Title", "Description A", "Sector B")
