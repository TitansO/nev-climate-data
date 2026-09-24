from unittest.mock import MagicMock, patch
from ai import greenwashing

mock_cursor = MagicMock()
mock_cursor.fetchone.return_value = None
mock_connection = MagicMock()
mock_connection.cursor.return_value.__enter__.return_value = mock_cursor
gemini_result = {"risk_level": "high", "red_flags": ["x"], "reasoning": "y"}

with patch("ai.greenwashing.gemini_client.check_greenwashing", return_value=gemini_result) as mock_check, \
     patch("ai.greenwashing.classify_module.classify_activity") as mock_classify, \
     patch("ai.greenwashing.db.get_connection", return_value=mock_connection):
    result = greenwashing.check("Solar Farm", "Ferme solaire.", 500000.0, "Renewable Energy", taxonomy_class="mitigation")

assert not mock_classify.called
mock_check.assert_called_once_with("Solar Farm", "Ferme solaire.", 500000.0, "mitigation")
assert mock_connection.commit.called
assert result == {"available": True, "cached": False, **gemini_result}
print("TEST 1 OK: cache miss calls gemini and caches")

mock_cursor2 = MagicMock()
mock_cursor2.fetchone.return_value = ("low", '["none"]', "ok")
mock_connection2 = MagicMock()
mock_connection2.cursor.return_value.__enter__.return_value = mock_cursor2
with patch("ai.greenwashing.gemini_client.check_greenwashing") as mock_check2, \
     patch("ai.greenwashing.db.get_connection", return_value=mock_connection2):
    result2 = greenwashing.check("X", "Y", 1.0, "Z")
assert not mock_check2.called
assert result2 == {"available": True, "cached": True, "risk_level": "low", "red_flags": ["none"], "reasoning": "ok"}
print("TEST 2 OK: cache hit skips gemini")

mock_cursor3 = MagicMock()
mock_cursor3.fetchone.return_value = None
mock_connection3 = MagicMock()
mock_connection3.cursor.return_value.__enter__.return_value = mock_cursor3
with patch("ai.greenwashing.gemini_client.check_greenwashing", return_value={"risk_level": "moderate", "red_flags": [], "reasoning": "a"}) as mock_check3, \
     patch("ai.greenwashing.classify_module.classify_activity", return_value={"taxonomy_class": "adaptation"}) as mock_classify3, \
     patch("ai.greenwashing.db.get_connection", return_value=mock_connection3):
    greenwashing.check("Levee Project", "Construction d'une digue.", 1000000.0, "Infrastructure")
mock_classify3.assert_called_once_with("Levee Project", "Construction d'une digue.", "Infrastructure")
mock_check3.assert_called_once_with("Levee Project", "Construction d'une digue.", 1000000.0, "adaptation")
print("TEST 3 OK: classifies first when taxonomy_class is missing")

assert greenwashing._hash("T", "A", 1000.0) != greenwashing._hash("T", "B", 1000.0)
assert greenwashing._hash("T", "A", 1000.0) != greenwashing._hash("T", "A", 2000.0)
print("TEST 4 OK: hash distinguishes triplets")

print("ALL GREENWASHING TESTS PASSED")
