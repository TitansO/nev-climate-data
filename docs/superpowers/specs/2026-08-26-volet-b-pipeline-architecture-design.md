# Volet B — Architecture du pipeline de données réelles (B1 + B2)

Status: Approved
Author: Serge (with Claude)
Date: 2026-08-26
Plan reference: Phase B1 (B1.1–B1.10) and Phase B2 (B2.1–B2.8), `Plan_Implementation_NEV_Climate_Data.xlsx`
Spec reference: `Cahier_des_charges_NEV_Climate_Data_v2.docx`, sections 6.1–6.5 (architecture et
règles de gouvernance du pipeline), 9.2 (prérequis Volet B)
Reference material: `pipeline-observatoire-cima.pdf` (architecture Observatoire CIMA, un
projet non lié fourni par Serge comme référence de pattern — voir décision 1)

## Goal

Establish the shared technical foundation — infrastructure, code layout, data flow
conventions, and governance-rule implementation — that every B1 (official-source connectors)
and B2 (GreenAccess connector) task builds on. This is a foundation spec, not itself a plan:
individual implementation plans for B1.1, B1.5, B1.6, etc. are written afterward, against
this document.

## Scope

Covers B1.1–B1.10 and B2.1–B2.8 (18 tasks) as a shared architecture. Does **not** cover B3.1
(Oumar's task — exposing existing endpoints with real data) or anything in Volet A.

## Governance note — when work here may actually start

Per the plan's own dependency column, B1.1–B1.9 all depend on **A3.10** ("Rapport de
recette Volet A signé par le client"), which in turn depends on A3.5–A3.9 — none of which
have started (Oumar's scope). This matches the cahier des charges' explicit lock (sections 2
and 10): *"le Volet B ne démarre pas tant que le Volet A n'a pas fait l'objet d'une recette
formelle et d'une validation écrite du client."*

This document is **design only** — it does not itself violate that lock (the same discipline
applied throughout this project: spec before plan before code). Actually provisioning the
infrastructure described here and writing connector code should wait for either A3.10's
sign-off, or an explicit decision by Serge to start infra-only work early. **B2.1** (the
GreenAccess scoping note) is the one task with no blocking dependency and may proceed
independently, per cahier des charges 9.2's allowance for administrative anticipation.

## Decisions

1. **Reference pattern, not shared infrastructure.** Serge provided the architecture
   document for Observatoire CIMA, a project he personally worked on that is otherwise
   unrelated to NEV Climate Data. Its pipeline pattern (collecte Python/Airflow → Kafka →
   traitement → MinIO Bronze/Silver/Gold + TimescaleDB → Redis → Symfony API) is reused as a
   **proven design template** — connector structure, DAG conventions, cache-by-PDF-hash
   pattern, dead-letter topic convention. Its actual infrastructure (Kafka cluster, MinIO
   buckets, credentials) is **not** reused or connected to — NEV provisions its own, fully
   isolated stack (cahier des charges rule 6.5; also avoids a hidden dependency on another
   project's infrastructure, availability, and cost model).
2. **Connectors and processors: Python, orchestrated by Airflow, separate from the Symfony
   backend.** Matches the cahier des charges' own technology split (Python for Airflow/Kafka,
   PHP/Symfony for the API/service layer) and CIMA's proven pattern. Lives in a new top-level
   `pipeline/` directory, parallel to `backend/`, not inside it — different language runtime,
   different deployment unit.
3. **Processors write directly to TimescaleDB**, bypassing the Symfony API entirely — same
   pattern as CIMA's `validation_processor.py` writing straight to Postgres. The Symfony
   backend remains a read-mostly consumer of the warehouse for the frontend; no write API is
   built or needed for the pipeline.
4. **Kafka topics, B1 (official sources): one shared raw topic, not one per source.**
   `nev.funding.raw` (payload carries a `source` field), consumed by a single validation
   processor, rather than four separate raw topics needing four consumers. Output:
   `nev.funding.valides` / `nev.funding.rejets` (dead-letter, matching cahier des charges
   6.4's quarantine rule — a rejected record is never silently dropped).
5. **Kafka topics, B2 (GreenAccess): use the cahier des charges' own names verbatim** —
   `greenaccess.scores.raw`, `greenaccess.financements.raw`, `greenaccess.assurance.raw`
   (section 6.3) — these are already specified by the client, not redesigned here.
6. **MinIO: one bucket, `nev-climate-data`**, prefixed `bronze/{source}/{date}/...`,
   `silver/...`, `gold/...` — same key-structure pattern as CIMA, adapted to NEV's dimensions
   (country/sector/year/funding type instead of CIMA's pays/année).
7. **Upsert via a new unique DB constraint, not application-level locking.** Add
   `(source_id, country_id, sector_id, year, funding_type)` as a unique constraint on
   `funding` (new Doctrine migration — the schema was explicitly designed in A1.3 to receive
   this kind of extension without a heavy migration). The Python processor then does a single
   `INSERT ... ON CONFLICT DO UPDATE`, matching cahier des charges 6.4's deduplication key
   exactly.
8. **Historization: processor populates `validFrom`/`validTo`/`isCurrent` for real.** These
   columns exist on `Funding` since A1.3 but are unused by Volet A's own logic (spec decision
   3 there: *"Volet B will change only the write logic... not the schema"*). On a value
   change, the processor closes the current row (`validTo = now()`, `isCurrent = false`) and
   inserts a new one (`validFrom = now()`, `isCurrent = true`) instead of updating in place —
   satisfying cahier des charges 6.4's *"aucune donnée n'est écrasée"* rule.
9. **Pivot currency conversion: real daily FX rates, not a fixed table.** CIMA's
   `convertir_en_fcfa` uses a hardcoded 4-currency dict, adequate for two CFA franc zones but
   not for NEV's actual multi-currency, multi-year requirement (cahier des charges 6.4: rate
   applied at the exact collection date). NEV's processor fetches European Central Bank daily
   reference rates (free, official, no API key required) and caches them by
   `(currency, date)` — same caching shape as CIMA's `_cache_pib`, different source.
10. **No Mercure yet.** Nothing in B1/B2's own livrables requires real-time push to a
    frontend that doesn't exist in Volet B's scope; Mercure is a Volet A/A3 concern (owned by
    Oumar's phase), not added here to avoid scope creep.
11. **Redis is shared within the NEV project, not isolated per-volet.** Unlike Kafka/MinIO
    (isolated from CIMA, a different project), Redis added here is the *same* NEV Climate
    Data project's cache — if Oumar's Phase A2 also needs a Redis cache for dashboards, it's
    the same instance. This is ordinary intra-project sharing, not a violation of the
    CIMA-isolation rule (6.5), which is about a different, unrelated project.

## Architecture

```
Sources officielles (World Bank, GCF, BAD, PNUE)
  -> Airflow DAGs (pipeline/dags/) -> Kafka producer
  -> nev.funding.raw
  -> processor de validation (pipeline/processors/) : devise pivot (BCE), dédup upsert,
     historisation, quarantaine
  -> nev.funding.valides / nev.funding.rejets
  -> écriture directe TimescaleDB (table funding) + MinIO Bronze/Silver/Gold
  -> [lecture par l'API Symfony existante, sans changement de contrat — principe directeur
     section 2]

Rapports PDF (nationaux, bailleurs)
  -> pipeline/dags/extraction_pdf.py -> pipeline/collectors/ (pdfplumber + Claude API,
     cache par hash de PDF) -> nev.funding.raw (même topic, même processor en aval)

GreenAccess (événementiel)
  -> Cloud Functions Firebase (côté GreenAccess, hors de ce dépôt) -> service producteur
     (pipeline/producers/) -> greenaccess.scores.raw / .financements.raw / .assurance.raw
  -> processor d'anonymisation (pipeline/processors/) : agrégation pays/secteur/période,
     suppression de tout identifiant personnel avant toute écriture côté NEV
  -> écriture directe TimescaleDB (Gold) + cache Redis

GreenAccess (filet de sécurité batch)
  -> pipeline/dags/sync_greenaccess_batch.py : DAG Airflow quotidien, lecture Firestore REST
     seule, idempotent, comble les évènements manqués
```

## Infrastructure (`docker-compose.yml` additions)

New services: `zookeeper` + `kafka` (1 broker), `airflow` + dedicated `postgres-airflow`,
`minio`, `redis`. All on the existing `nev-network`. `mercure` is explicitly not added
(decision 10).

## Code layout

```
pipeline/
├── dags/            (collecte_worldbank.py, collecte_gcf.py, collecte_bad.py,
│                      collecte_pnue.py, extraction_pdf.py, sync_greenaccess_batch.py)
├── collectors/       (one module per API connector + the PDF/Claude extractor)
├── processors/       (validation/normalization for B1.6-7, anonymization for B2.4)
├── producers/        (the GreenAccess producer service for B2.3)
└── common/           (shared DB connection, Kafka client helper, pivot-currency config)
docker/pipeline/Dockerfile
```

## Testing approach (established when each task is planned)

Each connector/processor gets unit tests for its pure logic (currency conversion, dedup key
construction, anomaly detection) plus an integration test against the real TimescaleDB
service (same transaction-rollback pattern already used in `backend/tests/Integration/`) —
mirrors the discipline already applied throughout Volet A, adapted to Python (pytest instead
of PHPUnit).

## Documentation

`README.md` gains a "Pipeline (Volet B)" section once the first piece of this architecture is
actually implemented (not before — until then this spec is the only record, consistent with
how A1.3's spec preceded any code). `HANDOFF.md` is updated to reflect that Volet B work is
gated on A3.10 per the governance note above.
