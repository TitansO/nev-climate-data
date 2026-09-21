"""C2.7 : classification taxonomique (title, description, sector) - le
cache (`classification_cache`, ai/db.py) vit ici, la logique Gemini elle-
meme dans ai/gemini_client.py (meme separation de responsabilite que
ai/rag.py : ce module orchestre, gemini_client.py ne connait que l'API
Gemini).
"""
from __future__ import annotations

import hashlib

from ai import db, gemini_client


def _hash(title: str, description: str, sector: str) -> str:
    return hashlib.sha256(f"{title}:{description}:{sector}".encode("utf-8")).hexdigest()


def _cached(cursor, content_hash: str) -> dict | None:
    cursor.execute(
        "SELECT taxonomy_class, activity_category, reasoning FROM classification_cache WHERE content_hash = %s",
        (content_hash,),
    )
    row = cursor.fetchone()
    if row is None:
        return None
    taxonomy_class, activity_category, reasoning = row
    return {"taxonomy_class": taxonomy_class, "activity_category": activity_category, "reasoning": reasoning}


def classify_activity(title: str, description: str, sector: str) -> dict:
    """Classe (title, description, sector) - sert le cache s'il existe deja
    (aucun appel Gemini, `cached: True`), sinon appelle Gemini et stocke le
    resultat avant de le renvoyer (`cached: False`). Retourne toujours
    `available: True` : contrairement a /ai/ask, il n'existe pas de cas
    "reponse indisponible" ici - le modele produit toujours une classe,
    y compris "not_relevant".
    """
    content_hash = _hash(title, description, sector)

    connection = db.get_connection()
    try:
        with connection.cursor() as cursor:
            cached_result = _cached(cursor, content_hash)
        if cached_result is not None:
            return {"available": True, "cached": True, **cached_result}

        result = gemini_client.classify(title, description, sector)

        with connection.cursor() as cursor:
            cursor.execute(
                """
                INSERT INTO classification_cache (content_hash, taxonomy_class, activity_category, reasoning)
                VALUES (%s, %s, %s, %s)
                ON CONFLICT (content_hash) DO NOTHING
                """,
                (content_hash, result["taxonomy_class"], result["activity_category"], result["reasoning"]),
            )
        connection.commit()

        return {"available": True, "cached": False, **result}
    finally:
        connection.close()
