"""F5 : FastAPI entrypoint for the ai service. No public port in
docker-compose.yml - reached only from inside the Docker network (the
Symfony backend proxies /api/ai/* to it, per C2.1's own scope; nothing in
this sprint exposes this service directly to the internet).
"""
from __future__ import annotations

from contextlib import asynccontextmanager

from fastapi import FastAPI
from pydantic import BaseModel

from ai import classify as classify_module
from ai import db, greenwashing as greenwashing_module, ingest, rag


@asynccontextmanager
async def lifespan(app: FastAPI):
    db.ensure_schema()
    yield


app = FastAPI(title="NEV Climate Data - AI service", version="0.1.0", lifespan=lifespan)


@app.get("/health")
def health() -> dict:
    return {"status": "ok"}


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
