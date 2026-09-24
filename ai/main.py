"""F5 : FastAPI entrypoint for the ai service. No public port in
docker-compose.yml - reached only from inside the Docker network (the
Symfony backend proxies /api/ai/* to it, per C2.1's own scope; nothing in
this sprint exposes this service directly to the internet).
"""
from __future__ import annotations

import os
from contextlib import asynccontextmanager

from fastapi import FastAPI, Response
from pydantic import BaseModel

from ai import classify as classify_module
from ai import db, gemini_client, greenwashing as greenwashing_module, ingest, rag


@asynccontextmanager
async def lifespan(app: FastAPI):
    db.ensure_schema()
    yield


app = FastAPI(title="NEV Climate Data - AI service", version="0.1.0", lifespan=lifespan)


@app.get("/health")
def health(response: Response) -> dict:
    """Audit de robustesse 2026-09-24 : renvoyait auparavant {"status": "ok"}
    de façon inconditionnelle - affichait "healthy" côté Docker même pendant
    une vraie panne Gemini constatée en direct (503 UNAVAILABLE côté Google).
    Ne fait volontairement PAS d'appel Gemini réel ici (le healthcheck tourne
    toutes les 15s - épuiserait le quota du palier gratuit en quelques
    minutes pour ne détecter qu'une indisponibilité déjà correctement gérée
    par _with_retry()/le 503 propre renvoyé à l'appelant) : vérifie
    seulement que la config est valide (clé API présente, client
    constructible - aucun appel réseau) et que la base RAG locale répond
    réellement, deux dépendances dont la panne était, elle, invisible.
    """
    checks = {"database": _check_database(), "gemini_configured": _check_gemini_configured()}
    if not all(checks.values()):
        response.status_code = 503
        return {"status": "degraded", **checks}

    return {"status": "ok", **checks}


def _check_database() -> bool:
    try:
        connection = db.get_connection()
        try:
            with connection.cursor() as cursor:
                cursor.execute("SELECT 1")
        finally:
            connection.close()
        return True
    except Exception:
        return False


def _check_gemini_configured() -> bool:
    if not os.environ.get("GEMINI_API_KEY"):
        return False
    try:
        gemini_client._client()
        return True
    except Exception:
        return False


class AskRequest(BaseModel):
    question: str


@app.post("/ai/ask")
def ask(request: AskRequest) -> dict:
    return rag.answer(request.question)


@app.post("/ai/reindex")
def reindex() -> dict:
    """F6 : (re)builds the RAG corpus - called by the `indexer_rag`
    Airflow DAG (quarterly, same cadence as the rest of Volet B) rather
    than run as a bin script inside this container, so a manual trigger
    from the Airflow UI works exactly like every other NEV connector.
    """
    return ingest.run()


class ClassifyRequest(BaseModel):
    title: str
    description: str
    sector: str


@app.post("/ai/classify")
def classify(request: ClassifyRequest) -> dict:
    """C2.7 : classification taxonomique reelle (mitigation/adaptation/
    both/not_relevant + categorie d'activite), few-shot Gemini avec cache
    par hash - voir ai/classify.py.
    """
    return classify_module.classify_activity(request.title, request.description, request.sector)


class GreenwashingRequest(BaseModel):
    title: str
    description: str
    amount_usd: float
    sector: str
    taxonomy_class: str | None = None


@app.post("/ai/greenwashing")
def greenwashing(request: GreenwashingRequest) -> dict:
    """C3.5 : detection heuristique reelle du risque d'ecoblanchiment (red-
    flags + Gemini), cache par hash - voir ai/greenwashing.py. `sector` est
    requis pour classer l'activite si `taxonomy_class` n'est pas deja
    fourni par l'appelant (App\\Service\\GreenwashingService cote Symfony
    le connait deja via `Funding.sector`).
    """
    return greenwashing_module.check(request.title, request.description, request.amount_usd, request.sector, request.taxonomy_class)
