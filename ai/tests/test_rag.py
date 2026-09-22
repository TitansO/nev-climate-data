"""Unit tests for ai/rag.py - chunk_text() is pure and tested directly;
upsert_chunk()/retrieve()/answer() touch the real ai-db and Gemini, so
they're exercised here with mocks (same offline-by-default convention as
the Volet B pipeline's own unit tests) and separately by a live smoke
test (ai/tests/test_live_smoke.py) against the real service.
"""
from unittest.mock import MagicMock, patch

from ai import rag


def test_chunk_text_returns_a_single_chunk_for_short_text():
    assert rag.chunk_text("Un texte court.") == ["Un texte court."]


def test_chunk_text_splits_on_paragraph_boundaries_once_over_the_limit():
    paragraph_a = "A" * 900
    paragraph_b = "B" * 900
    text = f"{paragraph_a}\n\n{paragraph_b}"

    chunks = rag.chunk_text(text, chunk_size=1000)

    assert chunks == [paragraph_a, paragraph_b]


def test_chunk_text_keeps_paragraphs_together_while_under_the_limit():
    text = "Court A.\n\nCourt B.\n\nCourt C."

    chunks = rag.chunk_text(text, chunk_size=1000)

    assert chunks == ["Court A.\n\nCourt B.\n\nCourt C."]


def test_chunk_text_drops_blank_paragraphs():
    text = "Contenu.\n\n\n\n   \n\nAutre contenu."

    chunks = rag.chunk_text(text, chunk_size=1000)

    assert chunks == ["Contenu.\n\nAutre contenu."]


def test_chunk_text_returns_empty_list_for_blank_input():
    assert rag.chunk_text("   \n\n  ") == []


def test_upsert_chunk_embeds_and_inserts_with_a_stable_hash():
    mock_cursor = MagicMock()
    mock_cursor.fetchone.return_value = None  # not already indexed
    mock_cursor.rowcount = 1
    mock_connection = MagicMock()
    mock_connection.cursor.return_value.__enter__.return_value = mock_cursor

    with patch("ai.rag.local_embeddings.embed", return_value=[0.1, 0.2, 0.3]) as mock_embed, \
         patch("ai.rag.db.get_connection", return_value=mock_connection):
        inserted = rag.upsert_chunk("Un contenu.", "docs/example.md")

    assert inserted is True
    mock_embed.assert_called_once_with("Un contenu.")
    mock_connection.commit.assert_called_once()
    # the INSERT is the second execute() call (the first is the existence
    # check); the embedding literal is its 2nd bound parameter, cast to
    # ::vector in SQL.
    insert_args = mock_cursor.execute.call_args_list[1].args
    assert insert_args[1][1] == "[0.1,0.2,0.3]"


def test_upsert_chunk_reports_no_insert_when_conflict_is_hit():
    mock_cursor = MagicMock()
    mock_cursor.fetchone.return_value = None  # not already indexed
    mock_cursor.rowcount = 0
    mock_connection = MagicMock()
    mock_connection.cursor.return_value.__enter__.return_value = mock_cursor

    with patch("ai.rag.local_embeddings.embed", return_value=[0.1]), \
         patch("ai.rag.db.get_connection", return_value=mock_connection):
        inserted = rag.upsert_chunk("Contenu deja indexe.", "docs/example.md")

    assert inserted is False


def test_upsert_chunk_skips_the_embedding_call_entirely_when_already_indexed():
    # Real gap found during F5/F6 live verification: re-embedding
    # already-indexed content on every re-run wastes a real embedding call
    # for nothing (was rate-limited under Gemini, is wasted local CPU time
    # under local_embeddings) - the existence check must happen BEFORE
    # embed(), not be left to ON CONFLICT DO NOTHING alone.
    mock_cursor = MagicMock()
    mock_cursor.fetchone.return_value = (1,)  # already indexed
    mock_connection = MagicMock()
    mock_connection.cursor.return_value.__enter__.return_value = mock_cursor

    with patch("ai.rag.local_embeddings.embed") as mock_embed, \
         patch("ai.rag.db.get_connection", return_value=mock_connection):
        inserted = rag.upsert_chunk("Contenu deja indexe.", "docs/example.md")

    assert inserted is False
    mock_embed.assert_not_called()
    mock_connection.commit.assert_not_called()


def test_answer_reports_unavailable_when_the_corpus_has_no_match():
    with patch("ai.rag.retrieve", return_value=[]):
        result = rag.answer("Une question sans contexte disponible ?")

    assert result == {"available": False, "answer": None, "sources": []}


def test_answer_grounds_the_response_in_retrieved_chunks_and_lists_sources():
    matches = [("Contenu A", "docs/a.md"), ("Contenu B", "docs/b.md")]

    with patch("ai.rag.retrieve", return_value=matches), \
         patch("ai.rag.gemini_client.ask", return_value="Reponse fondee sur le contexte.") as mock_ask:
        result = rag.answer("Une question ?")

    mock_ask.assert_called_once_with("Une question ?", ["Contenu A", "Contenu B"])
    assert result == {
        "available": True,
        "answer": "Reponse fondee sur le contexte.",
        "sources": ["docs/a.md", "docs/b.md"],
    }
