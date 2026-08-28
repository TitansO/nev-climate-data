# B1.2 — Connecteur Fonds Vert pour le Climat (Green Climate Fund)

Status: Approved
Author: Serge (with Claude)
Date: 2026-08-28
Plan reference: B1.2 (Phase B1 — Connecteurs sources officielles), `Plan_Implementation_NEV_Climate_Data.xlsx`
Spec reference: `Cahier_des_charges_NEV_Climate_Data_v2.docx`, section 6.2 (sources), 6.4 (règles de
gouvernance de la donnée)
Architecture reference: `docs/superpowers/specs/2026-08-26-volet-b-pipeline-architecture-design.md`
(shared foundation — same `nev.funding.raw`/`nev.funding.valides`/`nev.funding.rejets` topics,
same `funding-validator` processor, direct TimescaleDB write, upsert + historization) and
`docs/superpowers/specs/2026-08-26-b11-world-bank-connector-design.md` (B1.1, first connector
built against that foundation — B1.2 follows the same overall shape, deviations noted below).

## Goal

Collect Green Climate Fund (GCF) project financing, by country/sector, and feed it into the
`Funding` table via the shared Volet B pipeline. Matches plan B1.2's livrable: *"DAG Airflow
mensuel publiant vers le topic Kafka dédié."*

## Data source decision

**IATI Datastore** (`https://api.iatistandard.org/datastore/activity/select`), filtered to
GCF's own IATI reporting-organisation identifier `XM-DAC-41317` — **not** GCF's own in-house
Open Data Library/dashboard (`data.greenclimate.fund`) or its API portal
(`api-portal.gcfund.org`). Verified directly before deciding:

- `api-portal.gcfund.org` was unreachable on every attempt during this spec's research (503,
  then connection refused) — no contract could be verified, and no evidence found of how
  stable/documented it is even when up.
- `data.greenclimate.fund`'s "Funded Activities" page is a JS-rendered dashboard with no
  documented export/API surface visible in its static HTML.
- GCF is an IATI member and publishes its full activity-level portfolio there (financial
  commitments, disbursements, sectors, recipient countries) — a standardised format already
  used by ~2000 other organisations, actively maintained, with a real documented REST API.
  Confirmed live: reporting-org `XM-DAC-41317` returns **350 activities** (exact-phrase query
  `reporting_org_ref:"XM-DAC-41317"` — an *unquoted* value causes Solr to tokenize on the
  dashes and match ~269,000 unrelated documents; this bit us once during verification and is
  a required detail, not a style preference), matching the ~359 figure GCF's own site quotes
  publicly for its live portfolio (small gap plausibly explained by publication timing).
- Access requires a free API key (`Ocp-Apim-Subscription-Key` header), obtained via
  self-service signup at `developer.iatistandard.org` → Subscriptions → "Exploratory" product
  (no approval needed; 500 requests/day, 1/second — this connector needs one request per
  monthly run, so headroom is not a concern). Stored as `IATI_API_KEY` in the local `.env`
  (gitignored) and, once implemented, in `docker-compose.yml`'s `funding-validator`/`airflow`
  services the same way `PIPELINE_DATABASE_URL` already is.

Every field decision below was verified against the **complete real dataset** (all 350
activities pulled in one query, not a sample), using the `fl=` parameter to fetch exactly the
fields needed and a local Python analysis pass — not assumed from documentation alone.

## Decisions specific to this connector

1. **Query scope: one request for GCF's entire portfolio, not one per country.** Unlike B1.1
   (World Bank has thousands of projects; querying per-country with pagination was necessary),
   GCF's entire IATI-published portfolio is 350 activities — one request
   (`rows=350`, `fl=` restricted to the fields this connector needs) comfortably fits under the
   API's `rows` cap and rate limits. The DAG does **not** need NEV's `Country` table as an input
   to this collector at all (a deviation from B1.1, where the country list drove 54 separate API
   calls) — every activity is fetched regardless of recipient country, and "is this a NEV
   country" is decided downstream (see decision 6).

2. **`fundingType` is always `Multilateral`.** GCF is a multilateral climate fund by definition —
   same reasoning as B1.1's World Bank decision, no per-project classification needed.

3. **Currency: always USD, no conversion needed.** Verified across all 350 activities'
   `transaction_value_currency` and `default_currency` fields — 100% USD. `originalAmount`/
   `originalCurrency`/`exchangeRate` stay unused for this source, same as B1.1's World Bank
   connector (the architecture's real-ECB-rate conversion path exists for a source that
   actually needs it — not this one, despite B1.1's spec having anticipated GCF might).

4. **`amount_usd` = the sum of transactions where `transaction_type = 2` (Outgoing Commitment)
   AND `transaction_description_narrative` is exactly `"GCF financing commitment"`.** This was
   the single most important finding of this spec's research, and is **not** obvious from the
   IATI schema alone:
   - A transaction's `transaction_provider_org_ref` is **always** `XM-DAC-41317` (GCF) on every
     commitment transaction in a GCF-published activity — including ones representing money
     committed by *other* organisations (co-financiers). It cannot be used to isolate GCF's own
     contribution.
   - The only reliable signal is the transaction's free-text `transaction_description_narrative`.
     Checked across the complete dataset: exactly two distinct values ever appear on a type-2
     transaction — `"GCF financing commitment"` (581 occurrences) and `"Co-financing commitment"`
     (780 occurrences) — no other phrasing exists in the current portfolio. This is free text
     by IATI's schema (not a coded field), so a future GCF publication *could* introduce a new
     phrasing; if an activity has zero transactions matching `"GCF financing commitment"`, the
     **collector** skips it (returns `None`, does not publish to `nev.funding.raw` at all) —
     same pre-publish skip as B1.1's `parse_project` returning `None` for a zero-amount World
     Bank project, not a validator-side quarantine. There is no `amount_usd` to construct a
     payload with in that case, so it can never reach the validator in the first place; a
     phrasing drift would surface as GCF activities silently stopping being collected (visible
     via the DAG's published-count XCom dropping) rather than as wrong amounts.
   - 300/350 activities have more than one type-2 transaction in total (commitment +
     co-financing mixed together), but filtering to just `"GCF financing commitment"` narrows
     that to 153/350 activities with more than one — verified these always share the same
     transaction date/year within one activity (0/350 exceptions), so summing them and using
     that shared year is unambiguous. Every one of the 350 activities has at least one
     `"GCF financing commitment"` transaction (0 exceptions) — this path should never actually
     need to fire in production against the current portfolio, but stays as a real safety net,
     not dead code, given the free-text risk above.

5. **`year` = the year of the (summed) `"GCF financing commitment"` transaction(s).** Direct
   analogue of B1.1's decision 7 (`year` = the date of the financing event itself, not a project
   lifecycle date) — confirmed these transactions always share one year per activity (see
   decision 4).

6. **Multi-country activities: split `amount_usd` proportionally using IATI's own
   `recipient_country_percentage`, one `nev.funding.raw` message per recipient country.**
   Verified live: 86/350 activities (25%) list more than one recipient country, each with a
   real percentage split supplied by GCF itself (e.g. a Kenya+Senegal activity split 50/50) —
   summing to 100% per activity in every case checked. The collector emits one payload per
   `(activity, recipient_country)` pair with `amount_usd` prorated by that country's percentage,
   for **every** recipient country found — NEV-tracked or not. Whether a given country is one
   NEV actually tracks is decided by the existing `funding_validator.lookup_country_id` /
   `unknown_country` quarantine path (see architecture spec, and B1.1's `funding_validator.py`)
   — reusing that logic rather than duplicating a country allowlist inside this collector. Of
   the 350 activities, 145 (41%) touch at least one NEV-tracked country (90 single-country, 55
   multi-country) — the rest will be quarantined as `unknown_country`, which is expected and
   fine at GCF's monthly run frequency, not worth optimizing away with a pre-filter.

7. **Sector mapping: a fixed DAC 5-digit purpose-code → NEV-sector table, first matching code
   in the activity wins (by table priority order, not by `sector_percentage`) — a new function,
   not a reuse of B1.1's keyword-based `map_to_nev_sector`.** GCF's IATI data classifies each
   activity's sectors using OECD DAC CRS purpose codes with a percentage split
   (`sector_code`/`sector_percentage`/`sector_vocabulary`), fundamentally different from World
   Bank's free-text `major_sectors` list — this needs its own matching mechanism
   (`pipeline/processors/sector_mapping_gcf.py`, function `map_gcf_sector(sector_codes:
   list[str], sector_percentages: list[float]) -> str | None`), with `funding_validator.py`'s
   `process_message` dispatching to it or to the existing `map_to_nev_sector` based on the
   message's `source` field.

   Only **eight** distinct DAC codes appear across the entire 350-activity portfolio, so the
   table is small and exhaustive against real data, not speculative:

   | NEV sector | DAC code(s) | Real meaning (OECD DAC CRS) | Occurrences (of 350) |
   |---|---|---|---|
   | Renewable Energy | `23210` | Energy generation, renewable sources — multiple technologies | 95 |
   | Renewable Energy | `23183` | Energy conservation and demand-side efficiency | 57 |
   | Sustainable Transport | `21010` | Transport policy and administrative management | 32 |
   | Forestry | `31210` | Forestry policy and administrative management | 98 |
   | Adaptation | `43060` | Disaster Risk Reduction | 105 |
   | Adaptation | `41030` | Biodiversity | 134 |

   `16010` (Social Protection, 249 occurrences) and `16050` (Multisector aid for basic social
   services, 197 occurrences) never map to any NEV sector on their own — they are GCF's most
   *frequent* codes but never the deciding one; verified an activity's dominant (highest
   `sector_percentage`) code is one of these two in 96/350 cases where the activity *also*
   carries a mappable code elsewhere — first-match-wins (ignoring `sector_percentage` entirely)
   correctly still classifies those 96, which a dominant-sector-only rule would wrongly
   quarantine. Net effect verified against the real portfolio: first-match-wins classifies
   299/350 activities (85%); dominant-sector-only would classify only 203/350 (58%).

   **Agriculture never appears** — no DAC code in GCF's real portfolio maps to it (not one of
   the eight observed codes is agriculture-specific). This is a real, verified gap in this
   source, not a bug: GCF will simply never contribute `Agriculture`-sector rows. `41030`
   (Biodiversity) → `Adaptation` is the one debatable call in this table (ecosystem-based
   adaptation is a defensible reading, but it is a judgement call, not a clean fit) — flagged
   explicitly and confirmed with Serge before building.

8. **`nev.funding.raw` payload shape** (extends B1.1's shape — same topic, same envelope
   fields, `source` distinguishes the two):

   ```json
   {
     "source": "gcf",
     "project_id": "XM-DAC-41317-FP049",
     "country_iso": "SEN",
     "year": 2026,
     "amount_usd": 9983521,
     "funding_type": "multilateral",
     "raw_sector_codes": ["16010", "23210"],
     "raw_sector_percentages": [48.0, 52.0],
     "board_approval_date": "2017-10-02",
     "collected_at": "2026-08-28T00:00:00Z"
   }
   ```

   `project_id` carries the IATI activity identifier (`XM-DAC-41317-FPxxx`) for the same
   Bronze-layer audit-traceability reason as B1.1's `project_id`. `raw_sector_codes`/
   `raw_sector_percentages` replace B1.1's `raw_sectors`/`raw_theme` (different source schema,
   same purpose: carry enough raw classification data for `map_gcf_sector` — and later manual
   audit — without needing to re-fetch IATI). `country_iso` is already converted to alpha-3
   here (via `pycountry`, same as B1.1 — IATI's `recipient_country_code` is alpha-2, confirmed
   live, e.g. `"SN"`/`"BT"`/`"SV"` in the sample data) so downstream code stays source-agnostic.

## Testing approach

- Unit tests (pytest) for `map_gcf_sector`: each of the six DAC-code mapping rules, the
  first-match-wins order (an activity carrying both a mappable code and `16010`/`16050`
  classifies correctly), and the quarantine path (only `16010`/`16050`, or no codes at all).
- Unit tests for the GCF collector's parsing: extracting `amount_usd`/`year` from a fixture
  activity with mixed GCF/co-financing commitment transactions (asserting co-financing amounts
  are excluded from the sum), the multi-commitment-summing case, the multi-country percentage
  split (asserting one payload per recipient country with correctly prorated amounts), and the
  pre-publish skip when zero `"GCF financing commitment"` transactions exist (mirrors B1.1's
  zero-amount test) — all built from the real payload shapes captured during this spec's
  research, not invented shapes.
- Integration test hitting the real IATI Datastore API (reporting-org `XM-DAC-41317`, kept
  separate from the offline unit tests, `-m live`, same pattern as B1.1) to catch a real API
  contract change.
- `funding_validator.py`'s existing test suite gains cases for `source: "gcf"` dispatch to
  `map_gcf_sector`, reusing the existing `unknown_country`/`unknown_sector` quarantine paths
  as-is — no new quarantine-reason plumbing needed there at all.

## Documentation

`README.md`'s "Pipeline (Volet B)" section (added for B1.1) gains a short GCF subsection once
B1.2 actually ships — not before — noting the `IATI_API_KEY` requirement and the DAC
sector-mapping table's two known gaps (no `Agriculture` coverage, `41030`→`Adaptation` as a
judgement call) so a future reader doesn't have to re-derive them.
