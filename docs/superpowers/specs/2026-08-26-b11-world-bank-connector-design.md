# B1.1 — Connecteur Banque Mondiale (World Bank Projects & Operations API)

Status: Approved
Author: Serge (with Claude)
Date: 2026-08-26
Plan reference: B1.1 (Phase B1 — Connecteurs sources officielles), `Plan_Implementation_NEV_Climate_Data.xlsx`
Spec reference: `Cahier_des_charges_NEV_Climate_Data_v2.docx`, section 6.2 (sources), 6.4 (règles de
gouvernance de la donnée)
Architecture reference: `docs/superpowers/specs/2026-08-26-volet-b-pipeline-architecture-design.md`
(this spec fills in B1.1's specifics against that shared foundation — same DAG/Kafka/processor
pattern, `nev.funding.raw` topic, direct TimescaleDB write, upsert + historization)

## Goal

Collect World Bank climate-related project financing, by country, and feed it into the
`Funding` table via the shared Volet B pipeline. Matches plan B1.1's livrable: *"DAG Airflow
trimestriel publiant vers le topic Kafka dédié."*

## Data source decision

**World Bank Projects & Operations API** (`https://search.worldbank.org/api/v3/projects`),
filtered `mjtheme=Climate+change`, **not** the standard Indicators/WDI API. Verified directly
against the live API before deciding:

- The WDI API's own "Climate Change" topic (topic id 19) contains **zero financial
  indicators** — confirmed by listing all ~120 indicators under it: exclusively physical/
  environmental data (CO2 emissions, forest area, energy mix, land use). It cannot satisfy
  the cahier des charges' "financement public par pays" requirement (section 6.2).
- The Projects API, filtered by theme, returns real per-project financing figures — verified
  live for Senegal (`countrycode_exact=SN&mjtheme=Climate+change`): 264 projects, with fields
  `totalamt`, `idacommamt` (IDA concessional commitment), `grantamt`, `lendprojectcost`,
  `boardapprovaldate`, `countryname`/`countrycode`, plus sector/theme classification
  (`major_sectors`, `theme`) on projects that have reached at least Active status.

A pure-indicators enrichment (Option C from the earlier discussion — e.g. GDP for a
penetration-rate calculation à la CIMA) is **not built now** (YAGNI) — nothing in B1.1's own
livrable needs it; revisit only if a later analytics task asks for it.

## Decisions specific to this connector

1. **Query scope: all African countries in NEV's `Country` table (54), not Senegal-only.**
   B1.1 as signed in the plan is continent-wide (cahier des charges 6.2 doesn't scope World
   Bank to Senegal — the Senegal-priority cartography material concerns the separate Cercle
   1/2 national-institution tasks, not this one). The DAG iterates NEV's own `Country.isoCode`
   list rather than hard-coding a country list, so it stays correct if `Country` ever changes.
2. **`fundingType` is always `Multilateral`.** World Bank (IBRD/IDA) is a multilateral
   development bank by definition — no per-project classification needed for this field.
3. **`amount` = `totalamt`** (total project financing). `idacommamt`/`grantamt` are captured
   too but not summed into `amount` — they overlap with `totalamt` (components of it), summing
   them would double-count. `originalAmount`/`originalCurrency`/`exchangeRate` stay unused for
   this source (World Bank publishes `totalamt` already in USD; no conversion needed — matches
   `Funding`'s decision that these three columns are populated only for genuinely
   non-pivot-currency projects, e.g. later GCF/BAD sources reporting in other currencies).
4. **Sector mapping: keyword table against `major_sectors`/`theme`, first match wins, no
   percentage weighting.** Verified live that `sector_percent` is `"0"` (unpopulated) even on
   multi-sector projects — there is no real weighting signal to use. Mapping table (case-
   insensitive substring match, checked in this order — first hit wins):

   | NEV sector | Matches against `major_sector_name` / `theme` containing |
   |---|---|
   | Renewable Energy | "Energy Generation", "Renewable", "Solar", "Wind", "Hydropower" |
   | Sustainable Transport | "Transport", "Roads", "Urban Mobility" |
   | Agriculture | "Agriculture", "Rural Development", "Irrigation" |
   | Forestry | "Forest" |
   | Adaptation | "Adaptation" (checked in `theme`, since it is a cross-cutting theme rather
     than a `major_sector` in the API's own data — this is deliberately checked *after* the
     four sector-specific rules above, so a solar-adaptation project still lands in Renewable
     Energy, its more specific classification, rather than the generic Adaptation bucket) |

5. **No sector match, or no sector data at all (e.g. `status: "Pipeline"` projects, verified
   live to have neither `major_sectors` nor `theme`): the record is quarantined**
   (`nev.funding.rejets`), not forced into an arbitrary sector — matches the architecture
   spec's quarantine rule (cahier des charges 6.4).
6. **Multiple projects landing on the same dedup key
   (`source_id, country_id, sector_id, year, funding_type`) are summed, not replaced.**
   `Funding.amount` represents an aggregate figure per country/sector/year/type (matching how
   the cahier des charges and the entity itself are framed — an amount, not a project
   registry), so the upsert is `amount = funding.amount + EXCLUDED.amount` rather than a plain
   overwrite. Historization (architecture spec decision 8) still applies on every accumulation
   — each addition closes the previous version and opens a new one, so the incremental history
   of how a country/sector/year total grew as more World Bank projects were approved stays
   fully auditable, even though the current row is a sum.
7. **`year` = the calendar year of `boardapprovaldate`.** `collectionDate` (distinct field,
   already on `Funding` since A1.3) = the date the DAG actually ran, not the project's
   approval date — preserves the existing distinction between "when the underlying event
   happened" (`year`) and "when NEV collected it" (`collectionDate`).
8. **Schedule: quarterly**, matching cahier des charges 6.4's explicit frequency rule for
   Banque Mondiale/BAD, and the plan's own B1.1 livrable ("DAG Airflow trimestriel").
9. **Pagination**: the API returns `rows`/`os` (offset) — the DAG pages through all results
   per country (264 just for Senegal's climate-themed projects; the full 54-country sweep
   will be substantially larger) rather than assuming a single page, verified necessary since
   the default/tested page size is far smaller than the total count for even one country.

## Kafka payload shape (published to `nev.funding.raw`)

```json
{
  "source": "world_bank",
  "project_id": "P516778",
  "country_iso": "SN",
  "year": 2026,
  "amount_usd": 7420000,
  "funding_type": "multilateral",
  "raw_sectors": ["Energy Generation - Solar", "Energy Networks and Storage"],
  "raw_theme": ["Climate Change", "Adaptation"],
  "board_approval_date": "2026-09-24",
  "collected_at": "2026-08-26T00:00:00Z"
}
```

`project_id` is carried through to Bronze/Silver (MinIO) for audit traceability even though it
has no column on `Funding` itself (the aggregate table has no project-level grain) — anyone
needing to know which projects fed a given aggregate can trace it back through the Bronze
layer keyed by this field.

## Testing approach

- Unit tests (pytest) for the sector-mapping function (each of the 5 mapping rules, the
  first-match-wins order with the Renewable-Energy-before-Adaptation case explicitly covered,
  and the quarantine path for no match / missing sector data).
- Unit test for the summing-upsert SQL logic against a local test database (two projects,
  same key, assert the resulting row's `amount` is the sum, and that two historized rows
  exist with the first `isCurrent = false`).
- Integration test hitting the real World Bank API for one country (Senegal, cheap and fast)
  to catch a real API contract change, kept separate from the offline unit tests so CI can
  skip it if the external API is unreachable without failing the whole suite.

## Documentation

`README.md` gains a "Pipeline (Volet B)" section once B1.1 actually ships (per the
architecture spec's own documentation rule) — not before.
