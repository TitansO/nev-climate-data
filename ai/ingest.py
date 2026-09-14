"""F6 : builds/refreshes the RAG corpus that ai/rag.py queries. Three
sources, exactly as scoped by the C1-C3 plan's F6 item:

- the project's own design docs (docs/**/*.md) and README.md - read
  straight off the filesystem (bind-mounted read-only, see
  docker-compose.yml's `ai` service);
- the report catalogue - via the backend's own public GET /api/reports
  (PUBLIC_ACCESS, no auth needed), not a direct database read: this
  service has no Doctrine/database credentials for the app database at
  all, deliberately (see ai/db.py's docblock on keeping this service's
  own database separate) - going through the same public API the
  frontend uses keeps that boundary real rather than nominal;
- the funding aggregates - same reasoning, via GET /api/analytics/*.

Idempotent by design (ai/rag.py's upsert_chunk() dedupes on a hash of
source_ref+content) - safe to re-run on every trigger of the
`indexer_rag` DAG (quarterly, same cadence as the rest of Volet B) without
growing the corpus unboundedly on unchanged content.
"""
from __future__ import annotations

import os
from pathlib import Path

import httpx

from ai import rag

DOCS_ROOT = Path(os.environ.get("AI_DOCS_ROOT", "/app/docs"))
README_PATH = Path(os.environ.get("AI_README_PATH", "/app/README.md"))
BACKEND_BASE_URL = os.environ.get("BACKEND_BASE_URL", "http://backend")
REQUEST_TIMEOUT_SECONDS = 30


def ingest_markdown_files() -> int:
    """Chunks and stores every .md file under DOCS_ROOT plus README.md.
    Returns the number of new chunks actually inserted (0 on a re-run
    against unchanged content).
    """
    inserted = 0
    paths = list(DOCS_ROOT.rglob("*.md")) if DOCS_ROOT.is_dir() else []
    if README_PATH.is_file():
        paths.append(README_PATH)

    for path in paths:
        text = path.read_text(encoding="utf-8", errors="ignore")
        source_ref = str(path)
        for chunk in rag.chunk_text(text):
            if rag.upsert_chunk(chunk, source_ref):
                inserted += 1
    return inserted


def ingest_reports() -> int:
    """Chunks and stores one short text blurb per published report, from
    the backend's public report catalogue.
    """
    inserted = 0
    response = httpx.get(f"{BACKEND_BASE_URL}/api/reports", timeout=REQUEST_TIMEOUT_SECONDS)
    response.raise_for_status()
    for report in response.json().get("data", []):
        blurb = (
            f"Rapport : {report.get('title')}. Type : {report.get('type')}. "
            f"Pays : {(report.get('country') or {}).get('name', 'non specifie')}. "
            f"Publie le : {report.get('publicationDate', 'date inconnue')}."
        )
        source_ref = f"api:/api/reports#{report.get('id')}"
        if rag.upsert_chunk(blurb, source_ref):
            inserted += 1
    return inserted


def ingest_funding_aggregates() -> int:
    """Chunks and stores a short text summary of each real-time analytics
    aggregate the frontend itself displays - the same numbers, described
    in prose, so the AI Copilot can cite "the platform currently reports
    X" without ever computing its own, second, potentially-diverging
    figure (same principle already applied to PublishKpiSnapshotCommand).
    """
    inserted = 0

    hero = httpx.get(f"{BACKEND_BASE_URL}/api/analytics/hero-stats", timeout=REQUEST_TIMEOUT_SECONDS)
    hero.raise_for_status()
    hero_data = hero.json()
    blurb = (
        f"Vue d'ensemble de la plateforme NEV Climate Data : {hero_data.get('countriesCovered')} pays couverts, "
        f"{hero_data.get('sectorsTracked')} secteurs suivis, {hero_data.get('fundingRecords')} financements "
        f"reels enregistres, {hero_data.get('activeSources')} sources actives."
    )
    if rag.upsert_chunk(blurb, "api:/api/analytics/hero-stats"):
        inserted += 1

    sectors = httpx.get(f"{BACKEND_BASE_URL}/api/analytics/sector-distribution", timeout=REQUEST_TIMEOUT_SECONDS)
    sectors.raise_for_status()
    for row in sectors.json().get("data", []):
        blurb = (
            f"Repartition par secteur : le secteur {row.get('sector')} represente "
            f"{row.get('amount')} USD, soit {row.get('percentage')}% du financement total suivi."
        )
        source_ref = f"api:/api/analytics/sector-distribution#{row.get('sector')}"
        if rag.upsert_chunk(blurb, source_ref):
            inserted += 1

    countries = httpx.get(f"{BACKEND_BASE_URL}/api/analytics/country-distribution", timeout=REQUEST_TIMEOUT_SECONDS)
    countries.raise_for_status()
    for row in countries.json().get("data", []):
        blurb = (
            f"Repartition par pays : {row.get('country')} ({row.get('isoCode')}) a recu "
            f"{row.get('amount')} USD de financement climatique, soit {row.get('percentage')}% du total suivi."
        )
        source_ref = f"api:/api/analytics/country-distribution#{row.get('isoCode')}"
        if rag.upsert_chunk(blurb, source_ref):
            inserted += 1

    return inserted


def run() -> dict:
    """Runs all three ingestion sources and returns a summary - called by
    the `indexer_rag` Airflow DAG and by the standalone smoke test.
    """
    return {
        "markdown_chunks_inserted": ingest_markdown_files(),
        "report_chunks_inserted": ingest_reports(),
        "aggregate_chunks_inserted": ingest_funding_aggregates(),
    }


if __name__ == "__main__":
    print(run())
