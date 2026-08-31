# B1.5 — Extracteur de rapports PDF assisté par IA (Gemini)

Status: Approved
Author: Serge (with Claude)
Date: 2026-08-29
Plan reference: B1.5 (Phase B1 — Connecteurs sources officielles), `Plan_Implementation_NEV_Climate_Data.xlsx`
Spec reference: `Cahier_des_charges_NEV_Climate_Data_v2.docx`, section 6.2 (sources), 6.4 (règles de
gouvernance de la donnée)
Architecture reference: `docs/superpowers/specs/2026-08-26-volet-b-pipeline-architecture-design.md`
(shared foundation — decision 3 already specifies this connector's high-level flow: PDF →
`pipeline/dags/extraction_pdf.py` → `pipeline/collectors/` → **existing** `nev.funding.raw` topic
→ **existing** `funding_validator.py` processor. No new topic, no new validator file, unlike
B1.4 — the extracted data is financing data, same domain as `Funding`.)

## Goal

Extract real, structured project-financing data from a PDF report that has no API alternative,
using Gemini's native PDF understanding, and feed it into the `Funding` table via the shared
Volet B pipeline — with a hash-based cache so an already-processed PDF is never re-extracted.
Matches plan B1.5's livrable: *"Extracteur opérationnel avec cache (hash du PDF déjà traité)."*

## Data source decision — real-world research, not a simple lookup

Three real candidates were checked live and rejected before finding one that actually works:

1. **GCF Annual Progress Report 2024** — downloaded and tested with Gemini directly: contains
   **no per-project structured data at all**, explicitly redirects readers to `g.cf/projects`
   for that. Also redundant with B1.2, which already collects GCF's real per-project data via
   IATI.
2. **AFD (Agence Française de Développement)** — turns out to have its own real, free, public
   JSON API (`opendata.afd.fr`, 2,080 real project records, verified live) — not a PDF-extraction
   candidate at all, but a legitimate future **API connector** opportunity (out of scope here).
3. **Senegal Country Climate and Development Report (CCDR), World Bank** — downloaded and tested:
   contains real, extractable figures (financing needs, historical financing received) but only
   as **national aggregates**, not per-project rows. Wrong granularity for `Funding`.

**Approved target**: the **OPEC Fund Climate Finance Report 2024**, Annex 2 ("OPEC Fund Climate
Finance Portfolio 2018-2023"), pages 62-69 of the PDF. Real, genuinely structured, per-project
table with columns Year of Approval / Country / Project / Sector / OPEC Fund contribution (in
US$MN) / Adaptation Finance (%) / Mitigation Finance (%) / Total Climate Finance (%) — 111 real
rows spanning 2018-2023, across ~58 countries including a real, substantial subset of NEV's
tracked African countries (Senegal, Cameroon, Kenya, Malawi, Burundi, Sierra Leone, Madagascar,
Uganda, Tanzania, Zimbabwe, Ghana, Lesotho, Côte d'Ivoire, Benin, Chad, Liberia, Comoros,
Eswatini, Rwanda, Egypt, Morocco).

**Access note**: OPEC Fund's own site (`publications.opecfund.org`) only serves this report
through an interactive FlippingBook viewer with no public direct PDF download (confirmed live —
every direct-PDF-URL guess against its CloudFront-backed asset store returned `403`, signed-URL
protected). A plain, directly downloadable static PDF of the same report is mirrored at
`https://www.climatebusiness.africa/wp-content/uploads/2024/11/Climate-Finance-Report-2024.pdf`
(verified live: real PDF, 96 pages, 10.3 MB, content byte-identical to the interactive version)
— this connector downloads from that URL.

## Decisions specific to this connector

1. **Gemini model: pin `gemini-3.5-flash` explicitly — not `gemini-flash-latest`, not
   `gemini-2.5-flash`.** Verified live: `gemini-flash-latest` hit five consecutive `HTTP 503`
   ("high demand") on this exact document across two separate attempts; `gemini-2.5-flash`
   returned `HTTP 404` ("no longer available to new users... use gemini-3.6-flash"); explicitly
   pinning `gemini-3.5-flash` succeeded reliably. Floating aliases and once-current model names
   are not stable enough to build a real pipeline on — same "always pin exact versions" principle
   already applied to every other dependency in this project.

2. **Send only the relevant page range to Gemini, not the whole document.** Verified live: the
   full 96-page/10.3 MB report is not needed — a small, 8-page slice (Annex 2, pages 62-69,
   ~50 KB once extracted as its own PDF) is both sufficient and reliable. This is also a real
   robustness lesson from testing against a *different*, much larger (20 MB, image-heavy) OPEC
   report earlier in this research: large multi-page PDFs sent whole to Gemini are measurably
   more prone to `503`s and slow/flaky processing on this connection. The page range (62-69 for
   this source) is a per-source constant, alongside the source URL — a config value, not
   discovered at runtime.

3. **Extraction prompt requests a strict JSON array with 8 named fields and an explicit
   no-guessing instruction** (`year`, `country`, `project`, `sector`, `amount_usd_mn`,
   `adaptation_pct`, `mitigation_pct`, `total_climate_pct`; illegible cells become `null`, never
   an invented value). Verified live end-to-end against the real document: **111/111 rows
   extracted, spot-checked against the source table across the full 2018-2023 range (Cameroon,
   Senegal, Tajikistan, Dominican Republic, Panama-family rows, etc.) — every numeric value
   matched exactly**, zero hallucinated rows, zero invented figures.

4. **Real invariant used as a hallucination-detection guard**: verified live across every
   spot-checked row that `total_climate_pct == adaptation_pct + mitigation_pct` always holds in
   the real source table (e.g. Panama: 58.33 + 41.67 = 100.00; Colombia: 29.27 + 43.90 = 73.17).
   The collector discards (does not publish) any row where this doesn't hold within a small
   floating-point tolerance, as a defensive check against a future silent extraction error (a
   different report, a model regression) rather than trusting a single field blindly.

5. **Country resolution: `pycountry.countries.get(name=...)` first, `search_fuzzy(...)` as a
   fallback, skip (no payload) if neither matches.** Verified live against the real country names
   appearing in this table: exact `get(name=...)` correctly resolves the tricky cases directly
   — `"Côte d'Ivoire"` → CIV, `"Türkiye"` → TUR, `"Viet Nam"` → VNM — with no fuzzy matching
   needed. Only `"Kyrgyz Republic"` and `"Tanzania"` need the fuzzy fallback (both resolve
   correctly: KGZ, TZA). Regional/multi-country rows (`"Africa (regional)"`,
   `"Regional Africa"`, `"Regional Latin America and the Caribbean"`) correctly produce **no**
   match at all under either method — these are skipped pre-publish, same "nothing real to
   attribute the financing to" philosophy as B1.1-B1.4's own multi-country/no-country handling,
   not a validator-side quarantine. The full real 58-name list from this table is the test
   fixture for this resolution logic (Task-time work, not guessed generically).

6. **`funding_type` is always `Multilateral`** — the OPEC Fund is itself a multilateral
   development fund, same reasoning as B1.2/B1.3.

7. **A single real project row can produce up to two `Funding` payloads — one for its
   adaptation share, one for its mitigation share — rather than forcing one sector per
   project.** Verified live: nearly every real row in this table carries *both* a non-zero
   `adaptation_pct` and a non-zero `mitigation_pct` (e.g. Senegal's PROVALE-CV: 20% + 20%;
   Panama: 58.33% + 41.67%) — collapsing this into a single NEV sector per project would either
   discard real money or misattribute it. This mirrors B1.2's already-established precedent of a
   single source activity producing multiple `nev.funding.raw` payloads when it genuinely
   represents more than one real financing attribution (there, per recipient country; here, per
   dimension of the same OPEC Fund methodology).
   - **Adaptation payload** (when `adaptation_pct > 0`): `amount_usd = amount_usd_mn × 1,000,000
     × adaptation_pct / 100`, `sector = "Adaptation"` (NEV's existing sector, already used by
     B1.2's GCF connector — this is the first source to populate it from a real, source-computed
     percentage rather than a DAC code).
   - **Mitigation payload** (when `mitigation_pct > 0`): `amount_usd = amount_usd_mn ×
     1,000,000 × mitigation_pct / 100`, `sector` = the row's raw sector label run through
     decision 8's mapping table. If that mapping fails, only this mitigation portion is
     quarantined (`unclassifiable_sector`) — the adaptation payload for the same project is
     unaffected and still published if it maps successfully.

8. **Sector-label mapping table (first-match-wins, same mechanism as B1.1-B1.3), built from
   this report's own real label strings** — deliberately conservative, most real label values
   are left unmapped (quarantined) rather than guessed:

   | Raw sector label (as extracted) | NEV sector | Real reasoning |
   |---|---|---|
   | `Transport` | Sustainable Transport | Unambiguous at the label level, same as prior connectors. |
   | `Agriculture`, `Agriculture and Livelihoods`, `Agriculture/ Agricultural Development` | Agriculture | Unambiguous. |
   | `Fishing` | Agriculture | Real precedent: AfDB's own DAC code `31320` ("Fishery development") already maps to Agriculture in this project (B1.3 spec decision 8) — fisheries has an established home in NEV's taxonomy. |
   | `Energy`, `Energy Generation`, `Energy Generation; Distribution And Efficiency`, `Energy generation, Distribution and Efficiency` **AND** the `project` name contains an explicit renewable-technology keyword (`Wind`, `Solar`, `Hydro`, `Hydroelectric`, `Geothermal`) | Renewable Energy | The sector label alone is ambiguous (the report's own methodology explicitly allows "transitional" fossil-fuel-adjacent activities as partial mitigation finance — see Annex 1, "Transitional Activities" — so `Energy` + high mitigation % does not by itself mean renewable). The project **name** is real, unaltered source text, not an invented signal — every real row checked has an explicit, literal technology name (`Nachtigal Hydropower`, `240 MW Khizi-Absheron Wind Power Plant`, `Niger Solar Plant...`, `Achwa I 42MW Hydroelectric Power Plant`). This mirrors B1.1's already-established, already-approved keyword-based project-text matching (World Bank connector) — not a new, unprecedented mechanism. **Confirm with Serge on spec review**: this is a real judgment call, flagged explicitly rather than silently decided. |
   | Everything else (`Water`, `Water Supply`, `Basic Infrastructure`, `Financial Intermediation`, `Banking and Financial Services` and its compound variants, `Education`, `Health` and its compound variants, `Government And Civil Society`, generic `Multisector`/`Multisectoral`/`Other`/`Energy Appraising`, and any `Energy`-labeled row whose project name carries no renewable keyword) | *(unclassifiable)* | No reliable signal to map from without guessing — real, expected quarantine volume, same "don't guess" discipline as every prior connector's gaps (B1.2's Agriculture gap, B1.3's Adaptation gap). |

9. **Cache: SHA-256 hash of the downloaded PDF's raw bytes, checked against a new
   `processed_document` table before any extraction work happens.** If the hash already exists,
   the run is a real no-op (no Gemini call, no MinIO write, no Kafka publish) — this is the
   literal roadmap requirement ("cache (hash du PDF déjà traité)"). A new hash (the source
   publishes a fresh edition, e.g. the already-real "Climate Finance Report 2025") triggers full
   processing and a new cache row. Table: `processed_document(hash PK, source_name, source_url,
   minio_path, rows_extracted, processed_at)`.

10. **The raw PDF is stored in MinIO** at `bronze/opec-fund-climate-finance/{date}/{hash}.pdf` —
    the key-structure convention already decided in the shared architecture spec (decision 6),
    and the **first real use of MinIO in this project** (provisioned since B1.1, confirmed still
    completely empty — `mc ls` returns nothing — until this connector). The `nev-climate-data`
    bucket itself does not exist yet and must be created as part of this work. `minio` (the
    official Python client) is a new pipeline dependency.

11. **New `nev.funding.raw` payload shape** (reuses the shared envelope; `source` distinguishes
    this connector from every earlier one):

    ```json
    {
      "source": "opec_fund_pdf",
      "project_id": "opec-fund-climate-finance-2024:2020:Senegal:Water Valorisation For Value Chains Development Project (Provale-CV)",
      "country_iso": "SEN",
      "year": 2020,
      "amount_usd": 4000000,
      "funding_type": "multilateral",
      "sector_label_raw": "Agriculture and Livelihoods",
      "climate_dimension": "adaptation",
      "document_hash": "a3f...e91",
      "collected_at": "2026-08-29T00:00:00Z"
    }
    ```
    (This example is the adaptation payload for Senegal's PROVALE-CV row: 20 × 1,000,000 × 20% =
    4,000,000. Its mitigation counterpart is a second, separate message with `amount_usd =
    4000000`, `sector_label_raw` unchanged, `climate_dimension: "mitigation"`, mapped via
    decision 8's table.) `project_id` is synthesized (no ID exists in the source table itself) as
    `{document_hash-source-slug}:{year}:{country}:{project}` — stable across re-runs of the same
    document edition, so re-processing the same PDF (before the cache would normally skip it,
    e.g. during development) does not create duplicate `Funding` rows, consistent with the
    existing dedup key `(source_id, country_id, sector_id, year, funding_type)`.

12. **DAG: annual schedule** — the OPEC Fund's Climate Finance Report is itself an annual
    publication (a real 2025 edition already exists, confirmed live), so the DAG's own cadence
    matches the source's real update frequency, the same reasoning already used for B1.4's
    schedule decision.

## Scope boundary

This connector targets exactly one real document (the OPEC Fund Climate Finance Report,
Annex 2). The architecture (shared `pipeline/common/pdf_extraction.py` helper for
upload+extract-with-retry, hashing, and MinIO storage, separate from the source-specific
`pipeline/collectors/opec_fund_climate_finance.py`) is deliberately built so a *second* PDF
source later reuses the shared plumbing and only adds its own small collector file — but no
second source is built as part of this task (YAGNI; B1.5's own roadmap wording asks for "the
extractor," not a specific second document).

## Data model

No new entity — this connector publishes into the existing `Funding` table via the existing
`funding_validator.py` processor, exactly like B1.1-B1.3.

New Doctrine migration + entity work needed only for the cache table:
- New `App\Entity\ProcessedDocument` (or a plain pipeline-side table, decided at plan time
  depending on whether the cache check needs to be visible from the Symfony admin side at all —
  likely not, so a plain table created via a pipeline-side migration script rather than a
  Doctrine entity is the leaner choice, avoiding an entity with no real Symfony-side consumer).
- New `SourceFixtures` row: `['OPEC Fund — Climate Finance Report (PDF, Gemini-assisted)', 'opec-fund-climate-finance-pdf', SourceType::PdfReport, SourceReliability::High]` — this is the
  **first real use** of `SourceType::PdfReport` for genuinely PDF-sourced data (the existing GCF
  PDF row was reserved since B1.2 but never populated, since B1.2 ended up using IATI instead).

## Testing approach

- Unit tests for the sector-label mapping table (decision 8): each mapping rule, the
  renewable-keyword-required exclusion for generic `Energy` rows explicitly asserted (an
  `Energy`-labeled row with a project name carrying no renewable keyword must return `None`, not
  a guess), and the quarantine path for every other real label observed in this table.
- Unit tests for country resolution (decision 5) against the **complete real 58-name list**
  extracted from this table — not a sample — asserting each real country name resolves to the
  correct ISO alpha-3, and that the three real regional/multi-country strings correctly resolve
  to nothing.
- Unit tests for the invariant guard (decision 4): a row where `total_climate_pct` does not
  equal `adaptation_pct + mitigation_pct` (within tolerance) must be rejected, not silently
  accepted.
- Unit tests for the two-payload split (decision 7): a row with both percentages non-zero
  produces exactly two payloads with the expected `amount_usd`/`sector_label_raw`/
  `climate_dimension` values; a row with only one non-zero percentage produces exactly one.
- Unit tests for the cache (decision 9): a document hash already present in `processed_document`
  short-circuits before any Gemini/MinIO/Kafka call is made (mocked); a new hash proceeds through
  the full pipeline and inserts a new cache row.
- Live test (`-m live`, cheap): downloads the real page-range slice from the real mirror URL,
  calls the real Gemini API with `gemini-3.5-flash`, and asserts a non-empty, well-formed JSON
  array comes back with the expected row count — catches a real source-document change, API
  contract change, or model deprecation independent of the mocked unit tests.
- `funding_validator.py`'s existing test suite gains cases for `source: "opec_fund_pdf"`
  dispatch, mirroring the pattern already used for `world_bank`/`gcf`/`afdb`.

## Documentation

`README.md`'s "Pipeline (Volet B)" section gains a new "Extracteur PDF — OPEC Fund (B1.5)"
subsection once B1.5 ships, documenting: the real research trail that rejected three other
candidates before finding one with the right granularity, the real Gemini model-pinning lesson
(`-latest` unreliable, `2.5-flash` fully retired), the page-range-not-whole-document lesson, the
adaptation/mitigation two-payload split and why, the sector-mapping judgment call on `Energy`
rows (confirmed with Serge), and the first real use of MinIO in this project.
