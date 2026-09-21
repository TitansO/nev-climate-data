"""Connection helper + schema bootstrap for the ai service's own pgvector
database (F5). Deliberately a SEPARATE PostgreSQL instance (`ai-db` in
docker-compose.yml, image `pgvector/pgvector:pg16`) rather than the
`vector` extension bolted onto the main `database` service - the roadmap's
own F5 wording ("pgvector sur la base existante") was read literally at
first, but the main database is Doctrine-owned (every table, every
migration) and shared with the real-money `funding`/`emission` pipeline;
adding an extension AND a Doctrine-invisible table (`rag_chunk`) to it
would couple this brand-new, still-experimental service to that
production-critical schema for no real benefit. `postgres-airflow` already
established the precedent of a dedicated Postgres instance per concern in
this same project - this follows it.
"""
from __future__ import annotations

import os

import psycopg2


def get_connection():
    """Opens a new psycopg2 connection to the ai service's own database.
    Callers are responsible for closing it - same short-lived-connection
    convention as pipeline/common/db.py.
    """
    return psycopg2.connect(os.environ["AI_DATABASE_URL"])


# text-embedding-004 (see gemini_client.EMBEDDING_MODEL) - 768 dimensions,
# fixed by the model itself.
EMBEDDING_DIMENSIONS = 768


def ensure_schema() -> None:
    """Idempotently creates the `vector` extension, `rag_chunk` (F5/F6) and
    `classification_cache` (C2.7) tables. Called once at service startup
    (main.py) and by ingest.py - safe to call repeatedly (IF NOT EXISTS
    throughout).
    """
    connection = get_connection()
    try:
        with connection.cursor() as cursor:
            cursor.execute("CREATE EXTENSION IF NOT EXISTS vector")
            cursor.execute(
                f"""
                CREATE TABLE IF NOT EXISTS rag_chunk (
                    id SERIAL PRIMARY KEY,
                    content TEXT NOT NULL,
                    embedding VECTOR({EMBEDDING_DIMENSIONS}) NOT NULL,
                    source_ref TEXT NOT NULL,
                    content_hash TEXT NOT NULL UNIQUE,
                    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
                )
                """
            )
            # C2.7 : "cache par hash" (plan) - clasifier deux fois le meme
            # (title, description, sector) ne doit jamais couter un
            # deuxieme appel Gemini. Cle sur le hash du triplet, jamais
            # l'id d'un funding/project : la meme description peut se
            # retrouver sur plusieurs lignes reelles (texte copie d'une
            # source commune), autant partager le resultat.
            cursor.execute(
                """
                CREATE TABLE IF NOT EXISTS classification_cache (
                    id SERIAL PRIMARY KEY,
                    content_hash TEXT NOT NULL UNIQUE,
                    taxonomy_class TEXT NOT NULL,
                    activity_category TEXT NOT NULL,
                    reasoning TEXT NOT NULL,
                    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
                )
                """
            )
        connection.commit()
    finally:
        connection.close()
