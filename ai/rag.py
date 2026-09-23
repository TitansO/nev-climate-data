"""Chunking, storage and retrieval for the RAG corpus (F5/F6). The corpus
itself (what gets ingested) lives in ai/ingest.py - this module only knows
how to split text, store/dedupe embedded chunks, and retrieve the closest
ones to a query.
"""
from __future__ import annotations

import hashlib

from ai import db, gemini_client, local_embeddings

# ~1500 characters keeps a chunk well inside typical embedding input
# limits while staying large enough to carry real context (a paragraph or
# a small table row group, not a single sentence). No overlap: this
# corpus (docs/specs, README sections, report titles, aggregate figures)
# is made of short, self-contained units already - unlike a long prose
# document where cutting mid-argument would lose meaning.
CHUNK_SIZE_CHARS = 1500

# Audit pre-production 2026-09-23 (P3) : retrieve() renvoyait toujours ses
# top_k resultats, meme quand aucun n'a de rapport reel avec la question
# (ex. une question hors-sujet recevait quand meme 5 chunks "les moins
# eloignes", que answer() transmettait ensuite a Gemini comme s'ils
# etaient pertinents). MAX_COSINE_DISTANCE existe pour filtrer les cas
# degeneres - mais calibre EN DIRECT (docker compose exec ai python -c
# "...", sur le corpus reel) plutot que devine, ce calibrage a montre
# une limite reelle qu'il faut documenter honnetement plutot que
# masquer : avec all-MiniLM-L6-v2 (modele generaliste, jamais affine sur
# ce corpus finance-climat), les distances des questions hors-sujet
# testees ("Recette du gateau au chocolat" : 0.563 ; "Quelle heure est-il
# a Tokyo ?" : 0.550 ; "Qui a gagne la coupe du monde 2018 ?" : 0.577)
# chevauchent directement celles de questions pertinentes ("score
# d'equite climatique" : jusqu'a 0.567 sur son 3e resultat). Aucun seuil
# ne separe proprement les deux a ce corpus/modele - ce n'est donc pas
# le filtre principal contre les reponses hors-sujet : ce role reste
# tenu par la consigne "reponds UNIQUEMENT a partir du contexte fourni"
# deja dans gemini_client.ask() (Gemini juge sur le texte complet, pas
# sur un seul chiffre de distance). Ce seuil, volontairement large,
# n'ecarte que les cas franchement degeneres (embedding quasi-oppose -
# aucun exemple teste n'a jamais depasse 0.61).
MAX_COSINE_DISTANCE = 0.9


def chunk_text(text: str, chunk_size: int = CHUNK_SIZE_CHARS) -> list[str]:
    """Splits `text` into chunks of at most `chunk_size` characters,
    breaking on paragraph boundaries (blank lines) where possible rather
    than mid-sentence. Empty/whitespace-only chunks are dropped.
    """
    paragraphs = [p.strip() for p in text.split("\n\n") if p.strip()]
    chunks: list[str] = []
    current = ""
    for paragraph in paragraphs:
        candidate = f"{current}\n\n{paragraph}" if current else paragraph
        if len(candidate) > chunk_size and current:
            chunks.append(current)
            current = paragraph
        else:
            current = candidate
    if current:
        chunks.append(current)
    return chunks


def _hash(content: str, source_ref: str) -> str:
    return hashlib.sha256(f"{source_ref}:{content}".encode("utf-8")).hexdigest()


def _already_indexed(cursor, content_hash: str) -> bool:
    cursor.execute("SELECT 1 FROM rag_chunk WHERE content_hash = %s", (content_hash,))
    return cursor.fetchone() is not None


def upsert_chunk(content: str, source_ref: str) -> bool:
    """Stores one chunk, keyed by a hash of (source_ref, content) -
    re-ingesting the same unchanged content is a real no-op that costs no
    Gemini call at all, checked BEFORE embedding rather than relying on
    `ON CONFLICT DO NOTHING` alone - a real gap found during this
    service's own F5/F6 live verification: a naive "insert, let the
    database dedupe" version still called embed() (a real, rate-limited
    Gemini request) for every already-indexed chunk on every re-run,
    exactly the cost the OPEC Fund PDF connector's SHA-256 cache
    (B1.5, is_already_processed() checked BEFORE extraction) already
    avoids elsewhere in this project. Returns True if a new row was
    actually inserted.
    """
    content_hash = _hash(content, source_ref)

    connection = db.get_connection()
    try:
        with connection.cursor() as cursor:
            if _already_indexed(cursor, content_hash):
                return False

        embedding = local_embeddings.embed(content)
        embedding_literal = "[" + ",".join(str(v) for v in embedding) + "]"

        with connection.cursor() as cursor:
            cursor.execute(
                """
                INSERT INTO rag_chunk (content, embedding, source_ref, content_hash)
                VALUES (%s, %s::vector, %s, %s)
                ON CONFLICT (content_hash) DO NOTHING
                """,
                (content, embedding_literal, source_ref, content_hash),
            )
            inserted = cursor.rowcount > 0
        connection.commit()
        return inserted
    finally:
        connection.close()


def retrieve(question: str, top_k: int = 5) -> list[tuple[str, str]]:
    """Returns up to `top_k` (content, source_ref) pairs closest to
    `question` by cosine distance, excluding matches beyond
    MAX_COSINE_DISTANCE (see its comment for why this is a coarse safety
    net, not a precision relevance filter). No ivfflat/HNSW index: this
    corpus (specs, README, report titles, aggregate figures) is small
    enough (low thousands of chunks at most) that an exact sequential
    scan is fast - an approximate index would trade correctness for a
    speedup this corpus size doesn't need yet.
    """
    query_embedding = local_embeddings.embed(question)
    embedding_literal = "[" + ",".join(str(v) for v in query_embedding) + "]"

    connection = db.get_connection()
    try:
        with connection.cursor() as cursor:
            cursor.execute(
                """
                SELECT content, source_ref
                FROM rag_chunk
                WHERE embedding <=> %s::vector <= %s
                ORDER BY embedding <=> %s::vector
                LIMIT %s
                """,
                (embedding_literal, MAX_COSINE_DISTANCE, embedding_literal, top_k),
            )
            return list(cursor.fetchall())
    finally:
        connection.close()


def answer(question: str, top_k: int = 5) -> dict:
    """Full RAG round-trip for /ai/ask: retrieve the closest chunks, ask
    Gemini to answer using only those, return the answer with its
    sources. `available=False` (no Gemini call made) when the corpus is
    empty - matches this project's established "Donnee non disponible"
    convention (AnalyticsService) rather than asking the model to answer
    from nothing.
    """
    matches = retrieve(question, top_k=top_k)
    if not matches:
        return {"available": False, "answer": None, "sources": []}

    contents = [content for content, _ in matches]
    sources = sorted({source_ref for _, source_ref in matches})
    response_text = gemini_client.ask(question, contents)
    return {"available": True, "answer": response_text, "sources": sources}
