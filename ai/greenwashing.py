"""C3.5 : detection heuristique du risque d'ecoblanchiment - le cache
(`greenwashing_cache`, ai/db.py) vit ici, la logique Gemini elle-meme dans
ai/gemini_client.py (meme separation de responsabilite que ai/classify.py).
"""
from __future__ import annotations

import hashlib
import json

from ai import classify as classify_module
from ai import db, gemini_client


def _hash(title: str, description: str, amount_usd: float, sector: str) -> str:
    # Audit 2026-10-01 : `sector` manquait de cette cle, alors que
    # check_greenwashing() recoit `taxonomy_class` (derive de (title,
    # description, sector) par classify.py) et que son verdict en depend
    # (risk_level/red_flags varient avec la classe). Deux financements au
    # meme titre/description/montant mais secteur different pouvaient
    # partager un hash identique et se voler mutuellement leur verdict en
    # cache. `sector` plutot que `taxonomy_class` : la meme discrimination
    # (taxonomy_class est une fonction deterministe de (title, description,
    # sector), voir classify.py::_hash) sans devoir resoudre taxonomy_class
    # avant meme de savoir si le cache contient deja la reponse.
    return hashlib.sha256(f"{title}:{description}:{amount_usd}:{sector}".encode("utf-8")).hexdigest()


def _cached(cursor, content_hash: str) -> dict | None:
    cursor.execute(
        "SELECT risk_level, red_flags, reasoning FROM greenwashing_cache WHERE content_hash = %s",
        (content_hash,),
    )
    row = cursor.fetchone()
    if row is None:
        return None
    risk_level, red_flags, reasoning = row
    return {"risk_level": risk_level, "red_flags": json.loads(red_flags), "reasoning": reasoning}


def check(title: str, description: str, amount_usd: float, sector: str, taxonomy_class: str | None = None) -> dict:
    """Verifie (title, description, amount_usd) - sert le cache s'il existe
    deja (`cached: True`), sinon classe d'abord l'activite si
    `taxonomy_class` n'est pas deja connu (ai/classify.py, avec son propre
    cache), puis appelle Gemini pour le red-flag check proprement dit.
    """
    content_hash = _hash(title, description, amount_usd, sector)

    connection = db.get_connection()
    try:
        with connection.cursor() as cursor:
            cached_result = _cached(cursor, content_hash)
        if cached_result is not None:
            return {"available": True, "cached": True, **cached_result}

        if taxonomy_class is None:
            taxonomy_class = classify_module.classify_activity(title, description, sector)["taxonomy_class"]

        result = gemini_client.check_greenwashing(title, description, amount_usd, taxonomy_class)

        with connection.cursor() as cursor:
            cursor.execute(
                """
                INSERT INTO greenwashing_cache (content_hash, risk_level, red_flags, reasoning)
                VALUES (%s, %s, %s, %s)
                ON CONFLICT (content_hash) DO NOTHING
                """,
                (content_hash, result["risk_level"], json.dumps(result["red_flags"]), result["reasoning"]),
            )
        connection.commit()

        return {"available": True, "cached": False, **result}
    finally:
        connection.close()
