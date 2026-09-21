"""Gemini wrapper for the ai service - embeddings (RAG indexing/retrieval)
and grounded chat completion (/ai/ask). Mirrors the retry/timeout
conventions already established in pipeline/common/pdf_extraction.py
(same google-genai client, same real-world-transient-error handling) but
scoped to fast text calls rather than large PDF uploads, so the budgets
here are much smaller - a chat/embedding call has no reason to take
minutes the way a multi-page PDF extraction can.
"""
from __future__ import annotations

import json
import os
import time

import httpx
from google import genai
from google.genai import errors, types

from ai.db import EMBEDDING_DIMENSIONS

# Fixed embedding dimension (see ai/db.py's EMBEDDING_DIMENSIONS) - this
# specific model is not swappable without a migration of rag_chunk's
# `embedding` column. "text-embedding-004" (the model this constant
# originally assumed) returns 404 NOT_FOUND against the live API as of
# this service's F5/F6 build - confirmed live via client.models.list(),
# which lists only "gemini-embedding-001"/"-2"/"-2-preview" as
# embedContent-capable. The stable (non-preview) one is used here.
EMBEDDING_MODEL = "models/gemini-embedding-001"
# Same model already pinned for PDF extraction (B1.5) - see
# pipeline/common/pdf_extraction.py for why "gemini-flash-latest"/
# "gemini-2.5-flash" were rejected. Reused here for consistency; if this
# service ever needs a different quality/cost trade-off for chat than for
# PDF extraction, this constant is the one place to change it.
CHAT_MODEL = "gemini-3.5-flash"
REQUEST_TIMEOUT_MS = 30_000
MAX_RETRIES = 3
RETRY_DELAY_SECONDS = 2
# Real finding from this service's own F5/F6 live verification: bulk
# embedding during /ai/reindex (one call per corpus chunk) hit the free
# tier's rate limit mid-run (HTTP 429 RESOURCE_EXHAUSTED) - a real,
# expected condition on this tier (see docs/exploitation/suivi-couts.md
# risk R5), not a bug, but it must be retried with backoff like a 503
# would be; delivered by the SDK as ClientError(429, ...), a different
# exception class from ServerError entirely, so it needs its own check.
RATE_LIMIT_RETRY_DELAY_SECONDS = 20


def _client() -> genai.Client:
    return genai.Client(
        api_key=os.environ["GEMINI_API_KEY"],
        http_options=types.HttpOptions(timeout=REQUEST_TIMEOUT_MS),
    )


def _with_retry(call):
    last_error: Exception | None = None
    for attempt in range(MAX_RETRIES):
        try:
            return call()
        except errors.ClientError as exc:
            # A 429 (free-tier rate limit, verified live - see
            # RATE_LIMIT_RETRY_DELAY_SECONDS above) is transient and worth
            # a longer backoff; any other 4xx (a real request error - bad
            # model name, malformed input) is not retried, it's raised
            # immediately so it surfaces as the real bug it is.
            if getattr(exc, "code", None) != 429:
                raise
            last_error = exc
            if attempt < MAX_RETRIES - 1:
                time.sleep(RATE_LIMIT_RETRY_DELAY_SECONDS)
        except (errors.ServerError, httpx.TimeoutException, httpx.TransportError) as exc:
            last_error = exc
            if attempt < MAX_RETRIES - 1:
                time.sleep(RETRY_DELAY_SECONDS)
    raise RuntimeError(f"Gemini call failed after {MAX_RETRIES} attempts") from last_error


def embed(text: str) -> list[float]:
    """Returns the embedding vector for `text` - used both at ingestion
    time (ai/ingest.py) and at query time (ai/rag.py). Requests exactly
    ai.db.EMBEDDING_DIMENSIONS dimensions explicitly - gemini-embedding-001
    defaults to a larger vector (3072) otherwise, which would not match
    the fixed-width `VECTOR(768)` column in ai/db.py.
    """
    def call() -> list[float]:
        client = _client()
        response = client.models.embed_content(
            model=EMBEDDING_MODEL,
            contents=text,
            config=types.EmbedContentConfig(output_dimensionality=EMBEDDING_DIMENSIONS),
        )
        return list(response.embeddings[0].values)

    return _with_retry(call)


def ask(question: str, context_chunks: list[str]) -> str:
    """Answers `question` using ONLY `context_chunks` as grounding - the
    guardrail asked for by F5/C2.1 ("garde-fou 'contexte uniquement'").
    Returns the raw model text; the caller (ai/rag.py) pairs it with the
    chunks' source_ref for citation.
    """
    context = "\n\n---\n\n".join(context_chunks)
    prompt = (
        "Tu es l'assistant de la plateforme NEV Climate Data. Reponds "
        "UNIQUEMENT a partir du contexte fourni ci-dessous. Si le contexte "
        "ne permet pas de repondre, dis-le explicitement plutot que de "
        "deviner ou d'utiliser des connaissances externes.\n\n"
        f"Contexte :\n{context}\n\n"
        f"Question : {question}"
    )

    def call() -> str:
        client = _client()
        response = client.models.generate_content(model=CHAT_MODEL, contents=[prompt])
        return response.text

    return _with_retry(call)


# C2.7 : taxonomie verte simplifiee (plan) - 4 classes seulement, jamais
# une hierarchie complete de type EU Taxonomy/Common Principles MDB (hors
# scope de ce sprint, voir le plan). "not_relevant" existe explicitement
# pour un texte qui ne decrit rien de climat-pertinent (un porteur de
# projet peut se tromper de plateforme, un financement peut etre mal
# etiquete a la source) - jamais force dans l'une des 3 autres classes.
TAXONOMY_CLASSES = ("mitigation", "adaptation", "both", "not_relevant")

_CLASSIFY_RESPONSE_SCHEMA = {
    "type": "OBJECT",
    "properties": {
        "taxonomy_class": {"type": "STRING", "enum": list(TAXONOMY_CLASSES)},
        "activity_category": {"type": "STRING"},
        "reasoning": {"type": "STRING"},
    },
    "required": ["taxonomy_class", "activity_category", "reasoning"],
}

# Few-shot : un exemple par classe, tire de donnees deja reelles/plausibles
# sur cette plateforme (secteurs de App\Entity\Sector) - pas invente au
# hasard, pour que le modele ancre ses reponses sur le meme vocabulaire
# que le corpus reel qu'il verra en production.
_CLASSIFY_FEW_SHOT_EXAMPLES = """Exemple 1 :
Titre : Geothermal Expansion - Menengai Phase III
Description : Extension d'une centrale geothermique pour reduire la dependance aux combustibles fossiles.
Secteur : Renewable Energy
Reponse : {"taxonomy_class": "mitigation", "activity_category": "production d'energie renouvelable", "reasoning": "Reduit des emissions futures en substituant une source fossile, aucun volet d'adaptation."}

Exemple 2 :
Titre : Coastal Community Flood Early-Warning Network
Description : Systeme d'alerte precoce aux inondations pour des communautes cotieres vulnerables.
Secteur : Adaptation
Reponse : {"taxonomy_class": "adaptation", "activity_category": "reduction des risques climatiques", "reasoning": "Reduit la vulnerabilite a un risque climatique deja present, n'evite aucune emission."}

Exemple 3 :
Titre : Climate-Smart Coffee Value Chain Resilience
Description : Pratiques agricoles resilientes qui reduisent aussi les emissions de la chaine de valeur du cafe.
Secteur : Agriculture
Reponse : {"taxonomy_class": "both", "activity_category": "agriculture climato-intelligente", "reasoning": "Combine reduction d'emissions (pratiques bas-carbone) et adaptation (resilience de la chaine de valeur)."}

Exemple 4 :
Titre : Annual Staff Training Workshop
Description : Formation administrative annuelle du personnel, sans lien avec le climat.
Secteur : Renewable Energy
Reponse : {"taxonomy_class": "not_relevant", "activity_category": "aucune", "reasoning": "Aucun lien avec l'attenuation ou l'adaptation climatique malgre le secteur declare."}"""


def classify(title: str, description: str, sector: str) -> dict:
    """Classe (title, description, sector) selon la taxonomie verte
    simplifiee ci-dessus, few-shot (pas de modele entraine/fine-tune - le
    plan exclut explicitement le ML entraine pour ce sprint). Sortie
    structuree (response_schema) plutot qu'un texte a parser : une classe
    hors enum est rejetee par l'API elle-meme, jamais une chaine
    approximative a interpreter cote appelant.
    """
    prompt = (
        "Tu classes un projet ou financement climatique selon une taxonomie "
        "verte simplifiee a 4 classes : mitigation (attenuation - reduit des "
        "emissions futures), adaptation (reduit la vulnerabilite a un risque "
        "climatique deja present), both (les deux a la fois), ou not_relevant "
        "(aucun lien reel avec le climat). Reponds uniquement a partir du "
        "titre, de la description et du secteur fournis - ne suppose rien "
        "de plus.\n\n"
        f"{_CLASSIFY_FEW_SHOT_EXAMPLES}\n\n"
        "A classer :\n"
        f"Titre : {title}\n"
        f"Description : {description}\n"
        f"Secteur : {sector}\n"
        "Reponse :"
    )

    def call() -> dict:
        client = _client()
        response = client.models.generate_content(
            model=CHAT_MODEL,
            contents=[prompt],
            config=types.GenerateContentConfig(
                response_mime_type="application/json",
                response_schema=_CLASSIFY_RESPONSE_SCHEMA,
            ),
        )
        return json.loads(response.text)

    return _with_retry(call)
