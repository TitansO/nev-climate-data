# B1.4 — Connecteur PNUE (impact environnemental / réduction CO2)

Status: Approved
Author: Serge (with Claude)
Date: 2026-08-29
Plan reference: B1.4 (Phase B1 — Connecteurs sources officielles), `Plan_Implementation_NEV_Climate_Data.xlsx`
Spec reference: `Cahier_des_charges_NEV_Climate_Data_v2.docx`, section 6.2 (sources), 6.4 (règles de
gouvernance de la donnée)
Architecture reference: `docs/superpowers/specs/2026-08-26-volet-b-pipeline-architecture-design.md`
(shared foundation — deviations noted below, this connector does **not** reuse the shared
`nev.funding.*` topics or the `Funding` table, see decisions 2 and 3)

## Goal

Collect country-level CO2 emissions data as a proxy for environmental impact, and feed it into a
new `Emission` table via a dedicated Volet B pipeline. Matches plan B1.4's livrable: *"DAG
Airflow annuel publiant vers le topic Kafka dédié."*

## Data source decision — real-world finding, not a simple lookup

The roadmap's own framing ("API si disponible, sinon extraction de rapports") anticipated this
might not be straightforward. **Extensive live verification (12+ real checks) found no
UNEP-native API that is both alive and populated with country-level data**:

- **UNEP Environmental Data Explorer** (the historical UNEP API) — shut down June 2016. Dead.
- **UNEP Live** (`uneplive.unep.org`) — 301-redirects to WESR. Confirms it was merged/retired,
  not a distinct live platform.
- **UNEP's own SDG-branded portal** (`sdgs.unep.org`, indicator 13.2.2, series
  `EN_ATM_GHGT_AIP`/`EN_ATM_GHGT_NAIP`) — verified live via the real UN SDG API
  (`unstats.un.org/SDGAPI`): `HTTP 200`, but `totalElements: 0`. The indicator is defined but
  genuinely empty.
- **WESR** (`wesr.unep.org`, UNEP's current flagship federated platform) — no discoverable public
  REST API after repeated attempts: its resources page is a JS shell with no fetchable content,
  and the one "Guidelines for Interoperability" PDF found via search returns `HTTP 404`.
- **MapX** (`unep-grid/mapx` on GitHub) — the one platform that is both genuinely UNEP-run
  (UNEP/GRID-Geneva) and has a real technical API. Its own GitHub discussion (#662) states it
  "exposes an express API for its own needs, but it's not designed to be used from third-party
  applications." Also geospatial (map layers), not a country×year×CO2 tabular endpoint.
- **`unep.org` itself blocks automated fetching** (`HTTP 403` on every direct page fetch
  attempted, including `/data-resources` and the Africa adaptation report page) — a structural
  pattern, not a one-off failure.
- **UNEP's flagship reports don't have the required granularity.** The Emissions Gap Report 2025
  PDF (76 pages, downloaded and inspected directly) was checked byte-for-byte: zero occurrences
  of "Senegal" or "Sahel"; its one data table (Table 2.1) is a global aggregate by gas, and its
  one per-region figure (Figure 2.3) breaks out only the 6 largest emitters + "rest of G20",
  lumping every other country — Senegal included — into a single "Rest of World" bucket. The
  topically-closest regional report ("Africa's adaptation gap") is blocked by the same `403` and
  is about adaptation finance, not CO2 emissions, regardless.

**Approved deviation** (confirmed with Serge after presenting these findings): use the **UN SDG
Global Database API** (`https://unstats.un.org/SDGAPI`, indicator 9.4.1, series `EN_ATM_CO2`,
"Carbon dioxide emissions from fuel combustion"). This is a real, public, no-key REST API,
verified live to return actual populated country-level data for Senegal (24 years, 2000+).
**Its data is explicitly attributed to the IEA** (`source` field on every record: *"IEA (2025),
Greenhouse gas emissions from energy"*), not UNEP directly — the same kind of documented,
approved deviation as B1.3's XDR currency source (open.er-api.com instead of the ECB). The UN SDG
framework is the internationally-recognized indicator infrastructure UNEP itself reports through
for SDG 13 (Climate Action); this connector's `Source` row and README documentation state the
IEA attribution explicitly rather than implying the data originates at UNEP.

## Decisions specific to this connector

1. **Endpoint and series.** `GET https://unstats.un.org/SDGAPI/v1/sdg/Series/Data`, query params
   `seriesCode=EN_ATM_CO2`, `areaCode=<M49 code>`, `pageSize=<n>`. No API key. Verified live:
   sub-second responses, `HTTP 200` on 5 back-to-back requests across different countries
   (Senegal, Burkina Faso, Mali, Togo, Benin) — **no rate limit encountered**, unlike B1.3's IATI
   Datastore. No artificial delay is added between country requests; this was verified, not
   assumed.

2. **`areaCode` is the UN M49 numeric code, which is identical to the ISO 3166-1 numeric code**
   for every country (verified: Senegal's ISO 3166-1 numeric code is 686, and querying
   `areaCode=686` correctly returns Senegal's data). `pycountry.countries.get(alpha_3=...).numeric`
   provides this directly — no new country-code mapping table needed, consistent with B1.1's
   alpha-3→alpha-2 pycountry conversion pattern. The country list itself is read live from the
   `country` table at DAG run time (54 African countries, same pattern as
   `collecte_worldbank.py`), not hard-coded.

3. **Dimension filter: `dimensions.Activity == "TOTAL"` only.** The series carries two
   `Activity` dimension values per country/year: `TOTAL` ("No breakdown" — the real national
   figure) and `ISIC4_C10T32X19` (a manufacturing-sector subset). Verified live (Senegal, year
   2000): `TOTAL` → 3.52 million tonnes, `ISIC4_C10T32X19` → 0.5 million tonnes — these are
   different numbers for the same year, and only `TOTAL` is the national total this connector
   needs. Any row whose `dimensions.Activity != "TOTAL"` is discarded during parsing, not
   published.

4. **Units: millions of tonnes of CO2 (`TONNES_M`, confirmed in the API's own `attributes`
   metadata)**, stored as a decimal on the new `Emission.valueMt` column. No currency/pivot
   conversion applies (this is not a monetary figure).

5. **No sector dimension.** Unlike `Funding`, this is a national-level statistic, not a
   sector-attributed one. The dedup/business key is `(source_id, country_id, year)` — no
   `sector_id`.

6. **Historization semantics differ deliberately from `Funding`: replace, not sum.** `Funding`
   accumulates multiple financing events reported for the same key (each message is a distinct
   transaction). A national annual CO2 figure is a single authoritative statistic per
   (country, year) that the IEA periodically **revises** — a second message for the same key
   means "here is the corrected estimate," not "here is additional emissions to add." The new
   `emission_validator.py`'s upsert logic sets the new value as the `is_current = true` row and
   historizes the previous one (SCD2, same partial-unique-index mechanism as `Funding`), but
   never sums two values together.

7. **`funding_type` does not apply** — this is not financing data. The `Emission` entity has no
   equivalent column.

8. **Quarantine rule: `unknown_country`** — a `geoAreaCode` that does not resolve to a row in
   NEV's `country` table (same "we only asked the API about our own 54 tracked countries, so
   this should not normally happen, but is handled defensively" reasoning as B1.1). No
   equivalent to `unclassifiable_sector` exists here (decision 5).

9. **Kafka: new, dedicated topics — not `nev.funding.*`.** The roadmap's own wording for B1.4
   ("topic Kafka **dédié**") differs from B1.1–B1.3's shared-topic wording, and the data itself
   is a different domain (environmental impact, not financing) that does not belong in the
   `Funding` table. New topics: `nev.emissions.raw`, `nev.emissions.valides`,
   `nev.emissions.rejets`. These are auto-created by the Kafka broker on first publish, exactly
   like the existing `nev.funding.*` topics — no `docker-compose.yml`/broker config change
   needed (confirmed: no explicit topic declaration exists anywhere in the current infra for the
   funding topics either).

10. **New processor file, not an extension of `funding_validator.py`.** `funding_validator.py`
    already dispatches 3 sources into the `Funding` table; mixing a different data domain
    (emissions) into that dispatch would break its single responsibility. A new
    `pipeline/processors/emission_validator.py` consumes `nev.emissions.raw` (already filtered to
    `Activity == "TOTAL"` by the collector, per decision 3 — the validator's job is country
    resolution and persistence, not source-format filtering, same division of labor as
    `funding_validator.py`), applies decisions 6 and 8 above, and publishes to
    `nev.emissions.valides`/`nev.emissions.rejets`. It runs as an additional permanent consumer
    process (same container pattern as the existing `funding-validator` service in
    `docker-compose.yml`, parameterized or duplicated — decided at plan time).

11. **New `nev.emissions.raw` payload shape:**

    ```json
    {
      "source": "pnue",
      "country_iso": "SEN",
      "year": 2023,
      "value_mt": 12.4,
      "collected_at": "2026-08-29T00:00:00Z"
    }
    ```

12. **DAG: annual schedule**, matching the roadmap's explicit "DAG Airflow annuel" wording —
    `schedule_interval="0 3 1 1 *"` (1st of January, 03:00), same `start_date`/`catchup=False`
    conventions as the existing DAGs.

## Scope boundary (confirmed with Serge)

B1.4 delivers **collection only**: the collector, the new `Emission` table, the validator, and
the annual DAG publishing to the dedicated Kafka topics — matching B1.1–B1.3's precedent, where
none of those connectors modified `AnalyticsService`. `AnalyticsService::getCo2Reduction()`
(currently a hard-coded `available: false` stub) is **not** rewired to consume the new table as
part of this task; that remains a separate, later task if/when assigned.

## Data model

New entity `App\Entity\Emission`, mirroring `Funding`'s historization shape:

- `id`, `country` (FK, `Country`), `year` (int), `valueMt` (decimal, precision 10 scale 3),
  `source` (FK, `Source`), `collectionDate`, `validationStatus`, `validFrom`/`validTo`/
  `isCurrent` (SCD2), `createdAt`/`updatedAt`.
- Partial unique index `uniq_emission_dedup_key_current` on `(source_id, country_id, year)`
  `WHERE is_current = true` — same Postgres mechanism as `Funding`'s, and the same known
  Doctrine/DBAL false-positive on `schema:validate` documented there.
- New `EmissionRepository`.
- New `SourceFixtures` row: `['UN SDG Global Database — Indicator 9.4.1 (IEA)', 'un-sdg-en-atm-co2', SourceType::OfficialApi, SourceReliability::High]`.
- New Doctrine migration creating the `emission` table and its indexes.

## Testing approach

- Unit tests for the PNUE collector's parsing: the `Activity == "TOTAL"` filter (a fixture
  containing both dimension values for the same year must keep only the `TOTAL` one), the M49
  code conversion, and the payload shape — built from the real payload shape captured during
  this spec's research.
- Live check (`-m live`) against the real SDG API for a small, cheap slice (one or two
  countries), asserting real data comes back and the `TOTAL` filter behaves as expected —
  same pattern as B1.1–B1.3's live smoke tests.
- Unit tests for `emission_validator.py`: `unknown_country` quarantine, and the
  replace-not-sum historization behavior (two messages for the same key — assert the second
  message's value replaces the first as `is_current`, and the first is historized with
  `is_current = false`, not summed).
- Integration tests against the real dev TimescaleDB (same transaction-rollback pattern as
  `test_funding_validator.py`), scoped precisely to the exact dedup key under test — the lesson
  from B1.3's test-isolation bugs (README point 23) applies here too, since this table will
  accumulate real rows from real DAG runs during development just like `funding` did.

## Documentation

`README.md`'s "Pipeline (Volet B)" section gains a new "PNUE (impact environnemental)"
subsection once B1.4 ships, documenting: the extensive real-source research and why the UN SDG
API (IEA-attributed) was chosen over any UNEP-native option, the `Activity == "TOTAL"` dimension
pitfall, the replace-vs-sum historization difference from `Funding`, the dedicated
`nev.emissions.*` topics (vs. the shared `nev.funding.*` used by B1.1–B1.3), and the explicit
scope boundary (collection only, `AnalyticsService` not rewired).
