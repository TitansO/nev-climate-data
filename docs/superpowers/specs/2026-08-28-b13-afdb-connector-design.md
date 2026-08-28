# B1.3 — Connecteur BAD (Banque Africaine de Développement)

Status: Approved
Author: Serge (with Claude)
Date: 2026-08-28
Plan reference: B1.3 (Phase B1 — Connecteurs sources officielles), `Plan_Implementation_NEV_Climate_Data.xlsx`
Spec reference: `Cahier_des_charges_NEV_Climate_Data_v2.docx`, section 6.2 (sources), 6.4 (règles de
gouvernance de la donnée, notamment la devise pivot)
Architecture reference: `docs/superpowers/specs/2026-08-26-volet-b-pipeline-architecture-design.md`
(shared foundation) and `docs/superpowers/specs/2026-08-28-b12-gcf-connector-design.md` (B1.2,
same IATI Datastore API and infrastructure reused here — deviations noted below).

## Goal

Collect African Development Bank Group (BAD/AfDB) project financing, by country/sector, and
feed it into the `Funding` table via the shared Volet B pipeline. Matches plan B1.3's livrable:
*"DAG Airflow trimestriel publiant vers le topic Kafka dédié."*

## Data source decision

**IATI Datastore** (same API as B1.2, `https://api.iatistandard.org/datastore/activity/select`,
same `IATI_API_KEY`), filtered to AfDB's own IATI reporting-organisation identifier
`XM-DAC-46002`. Verified live: **5,604 real activities** (exact-phrase query
`reporting_org_ref:"XM-DAC-46002"` — same guillemet requirement as B1.2's GCF connector).
AfDB's own Data Portal (`projectsportal.afdb.org`) was considered but not used — no documented
API found, and IATI is already integrated infrastructure from B1.2, actively maintained,
publicly stable (AfDB has published to IATI since 2013 and was the first MDB to add
geocoded/private-sector data to it).

Every field decision below was verified against real data pulled from the complete portfolio
(all 5,604 activities, paginated 1,000 at a time via the Datastore's `start` offset parameter —
not a sample), the same rigor applied to B1.1 and B1.2.

## Decisions specific to this connector

1. **Query scope: paginate the full portfolio (5,604 activities), unlike B1.2's single
   request.** GCF's entire portfolio (350 activities) fit in one request; AfDB's does not.
   Uses the Datastore's standard `start`/`rows` offset pagination (`rows=1000` per page, 6
   pages). No server-side climate filter exists for this source (see decision 2) — every page
   is a client-side filtering pass, not a targeted query.

2. **No server-side climate filter is available - unlike B1.1's `mjtheme=Climate+change`.**
   Verified live: **zero** of AfDB's 5,604 activities have any `policy_marker_code` populated at
   all (`policy_marker_code:*` matches 0 documents). Climate relevance can only be determined
   client-side, from DAC sector codes (decision 6) — a materially higher quarantine rate than
   B1.1/B1.2 is expected and correct: AfDB funds general development (health, education, roads,
   budget support, mining...), not exclusively climate projects.

3. **`fundingType` is always `Multilateral`** — same reasoning as B1.1/B1.2, a regional
   multilateral development bank by definition.

4. **Currency: real conversion required — the first Volet B connector that actually needs
   it.** Verified live across the complete portfolio: **100% of AfDB's activities are
   denominated in XDR** (IMF Special Drawing Rights), never USD. `Funding.originalAmount`/
   `originalCurrency`/`exchangeRate` (reserved since A1.3, unpopulated by every connector so
   far) are populated for real for the first time by this connector.

   **The cahier des charges 6.4 requirement ("devise pivot USD, taux BCE réels") cannot be
   satisfied literally for this currency**: verified live that the ECB's official daily
   reference-rate feed (`eurofxref-daily.xml`) covers 28 world currencies and does not include
   XDR at all — a structural gap, not a wrong URL (XDR is an IMF-defined basket unit, not a
   national currency the ECB quotes). The IMF itself publishes the authoritative daily SDR
   valuation, but only as an HTML page with no API; its structured SDMX API
   (`api.imf.org/external/sdmx/3.0`) was explored in depth (real, working, no-auth-required)
   but its `ER` dataflow's SDR-related series are balance-of-payments reserve-asset
   accounting (allocations/holdings), not a queryable XDR-per-USD exchange-rate series — and
   the API showed real intermittent timeouts during this exploration.

   **Approved deviation**: convert via `https://open.er-api.com/v6/latest/XDR` (backed by
   exchangerate-api.com's free/open tier) — verified live, returns real current XDR-based rates
   for ~160 currencies including USD, no API key required, updates daily, and its free-tier
   terms (`exchangerate-api.com/docs/free`) explicitly support this connector's request
   pattern (quarterly — far under its "once per 24h" soft limit). `exchangeRate` stores the
   XDR→USD rate used at collection time; `originalAmount`/`originalCurrency` store the
   as-reported XDR figures; `amount` stores the converted USD value.

5. **No multi-country splitting logic (unlike B1.2's decision 6) — every activity has exactly
   one recipient country or none.** Verified live across the complete portfolio: **zero**
   multi-country activities (compare B1.2's GCF, where 25% were multi-country). 986/5,604
   activities (18%) have no recipient country at all and are skipped pre-publish (nothing to
   attribute the financing to) — same pre-publish-skip philosophy as B1.1/B1.2's zero-amount
   skip, not a validator-side quarantine.

6. **The raw commitment amount, still in XDR (converted to `amount_usd` per decision 4), is the
   sum of transactions where `transaction_type = "2"` (Outgoing Commitment) AND
   `transaction_provider_org_ref` is one of a fixed allowlist of AfDB Group entity IATI
   identifiers** — a different mechanism from
   B1.2's free-text description matching (AfDB's `transaction_description_narrative` is sparse
   and inconsistently populated, confirmed live: transaction description/value/type/date
   sub-arrays are not always the same length, unlike GCF's always-parallel arrays — description
   text is not a reliable signal here).

   **Real complexity found**: `transaction_provider_org_ref` on a "Commitment" transaction is
   **not always AfDB's own money** — verified live that AfDB's IATI feed carries financing from
   several distinct entities under one reporting-org feed, including recipient-country
   government counterpart commitments (`SL-COA-GOV`, `TZ-COA-GOV`, etc. — real government
   entity codes, confirmed present) and, critically, **other independent multilateral funds
   that merely use AfDB as an implementing entity** — `XM-DAC-GCF` (Green Climate Fund) appears
   as a transaction provider on 44 real AfDB-reported activities. Counting that money as "AfDB
   financing" would double-count it against B1.2's GCF connector, which already tracks GCF's
   own financing directly from GCF's own IATI feed.

   **Allowlist** (confirmed with Serge, real org names verified via IATI's own
   `participating_org_narrative` field, not assumed from codes alone):

   | IATI ref | Real name | Included? |
   |---|---|---|
   | `XM-DAC-46002` | African Development Bank | ✅ the Bank itself |
   | `XM-DAC-46003` | African Development Fund | ✅ concessional window |
   | `XM-DAC-NTF` | Nigerian Trust Fund | ✅ the Group's third official window |
   | `XM-DAC-TSF`, `XM-DAC-MIC Fund`, `XM-DAC-SRF`, `XM-DAC-AWF`, `XM-DAC-CBFF`, `XM-DAC-SEFA`, `XM-DAC-FAPA`, `XM-DAC-AGTF`, `XM-DAC-NEPAD-IPPF`, `XM-DAC-RWSSI`, `XM-DAC-TFCT`, `XM-DAC-SCF`, `XM-DAC-CAW` | Various AfDB-*administered* multi-donor trust funds, capitalised by third-party donors (UK, Norway, China, etc.) | ❌ excluded — real money, but not AfDB Group's own capital (same "who's the real payer" reasoning as B1.2's GCF-vs-co-financing decision) |
   | `XM-DAC-GCF`, `XM-DAC-GEF`, `XM-DAC-EU-AIP`, `XM-DAC-GAFSP`, government `*-COA-GOV` codes | Independent external funders/counterparts that merely use AfDB as an implementing entity | ❌ excluded — not AfDB Group financing at all |

   `activity_status_code = "5"` (Cancelled, 206/5,604 activities, 153 of which have a real
   allowlisted commitment) is **not** filtered out — a formal Board commitment is a real
   historical financing event regardless of a project's later cancellation (confirmed with
   Serge).

   Multiple allowlisted Commitment transactions per activity are summed (same accumulation
   philosophy as B1.2 decision 4) — expected: AfDB Group projects are frequently co-financed
   across its own windows (e.g. an ADF loan plus an AfDB-proper loan on the same activity).

7. **`year` = the year of the earliest allowlisted Commitment transaction**, mirroring B1.2
   decision 5's reasoning (the financing-approval event, not a project lifecycle date).

8. **Sector mapping: a DAC 5-digit code table, same first-match-wins mechanism as B1.2's
   `map_gcf_sector`, but a materially larger and more careful table** — reusing the mechanism,
   not the table (AfDB's portfolio spans **55 distinct DAC codes** among really-financed
   activities, versus GCF's 8, because AfDB funds general development, not only climate
   projects). Verified against the complete allowlisted-and-financed portfolio (4,287
   activities):

   | NEV sector | DAC code(s) | Real meaning | Occurrences |
   |---|---|---|---|
   | Renewable Energy | `23230` | Solar energy for centralised grids | 21 |
   | Renewable Energy | `23260` | Geothermal | 3 |
   | Renewable Energy | `23220` | Hydro-electric power plants | 1 |
   | Renewable Energy | `23240` | Wind power | 1 |
   | Sustainable Transport | `21023` | National road construction | 469 |
   | Sustainable Transport | `21012` | Public transport services | 113 |
   | Sustainable Transport | `21050` | Air transport | 54 |
   | Sustainable Transport | `21030` | Rail transport | 38 |
   | Sustainable Transport | `21040` | Water transport | 28 |
   | Agriculture | `31110` | Agricultural policy and administrative management | 618 |
   | Agriculture | `31140` | Agricultural water resources | 76 |
   | Agriculture | `31163` | Livestock | 58 |
   | Agriculture | `31320` | Fishery development | 59 |
   | Agriculture | `32161` | Agro-industries | 56 |
   | Forestry | `31220` | Forestry development | 22 |

   **Deliberately excluded, real reasoning verified against this portfolio, not guessed**:
   - `23111` (Energy sector policy, planning and administration, 315 occurrences — the 4th most
     frequent code overall): generic energy-sector policy, does **not** specify renewable vs.
     non-renewable. Including it risks silently classifying non-renewable financing as
     "Renewable Energy".
   - `23310` (Energy generation, non-renewable sources) and `23320` (Coal-fired electric power
     plants): confirmed **present** in AfDB's real portfolio — proof this connector must not
     use a loose "energy" keyword match the way B1.1's World Bank connector does; a coal plant
     must never land in `Renewable Energy`.
   - `23630` (Electric power transmission/distribution, centralised grids): grid
     infrastructure, technology-agnostic — could carry power from either renewable or
     non-renewable generation, no way to tell which from this code alone.
   - `41010` (Environmental policy and administrative management, the only "environmental" code
     present in this portfolio): too generic to imply climate adaptation specifically —
     confirmed with Serge to exclude rather than guess (mirrors the care taken over B1.2's
     Biodiversity/Adaptation judgement call, resolved the other way here since even that
     judgement call's specificity is missing).
   - `43042` (Rural development): broader than agriculture specifically (can include roads,
     schools, resettlement in rural areas) — excluded for the same "don't guess" reasoning.
   - Neither `43060` (Disaster Risk Reduction) nor `41030` (Biodiversity) — the two DAC codes
     B1.2's `Adaptation` bucket relies on for GCF — appear **anywhere** in AfDB's real
     portfolio (verified: 0 occurrences each). **`Adaptation` will not be populated by this
     connector at all** — a real, verified gap in this source, not a bug, exactly like B1.2's
     verified `Agriculture` gap for GCF.

9. **`nev.funding.raw` payload shape** (extends the shared envelope, `source` distinguishes all
   three connectors now):

   ```json
   {
     "source": "afdb",
     "project_id": "46002-P-KE-FA0-009",
     "country_iso": "KEN",
     "year": 2019,
     "amount_usd": 87732352,
     "original_amount": 64000000.0,
     "original_currency": "XDR",
     "exchange_rate": 1.370818,
     "funding_type": "multilateral",
     "raw_sector_codes": ["31110", "23111"],
     "board_approval_date": "2019-04-12",
     "collected_at": "2026-08-28T00:00:00Z"
   }
   ```
   (`amount_usd = original_amount × exchange_rate`, consistent with the example figures above.)

   `original_amount`/`original_currency`/`exchange_rate` are new fields on the raw payload —
   B1.1/B1.2 never needed them (always-USD sources). `funding_validator.py`'s `upsert_funding`
   must be extended to accept and persist them into `Funding.originalAmount`/
   `originalCurrency`/`exchangeRate` (currently `NULL` for every row in the table — this
   connector is the first to populate them). No `raw_sector_percentages` field, unlike B1.2's
   GCF payload — `map_afdb_sector` (decision 8) is first-match-wins over `raw_sector_codes`
   alone, continuing B1.2's established policy rather than re-deriving a dominant-vs-any-match
   comparison for this source; nothing downstream would consume a percentage value.

## Testing approach

- Unit tests for `map_afdb_sector` (new function, same shape as B1.2's `map_gcf_sector`): each
  mapping rule, the coal/non-renewable exclusion explicitly asserted (a `23320`-only activity
  must return `None`, not `Renewable Energy`), the generic-energy-policy exclusion (`23111`
  alone → `None`), and the quarantine path.
- Unit tests for the AfDB collector's parsing: the allowlist filter (an activity whose only
  Commitment transaction is provided by `XM-DAC-GCF` or a `*-COA-GOV` government code must
  produce zero payloads — not just "wrong amount", genuinely nothing to publish), the
  multi-window summing case (two allowlisted providers on one activity), the pagination logic
  (mocked two-page response), and the currency-conversion arithmetic (a fixture with a known
  XDR amount and a known mocked rate, asserting the exact resulting USD figure) — all built
  from real payload shapes captured during this spec's research.
- Integration test hitting the real IATI Datastore API for a small `rows=` slice of AfDB's
  portfolio (kept cheap, not the full 5,604), `-m live`, same pattern as B1.1/B1.2.
- A separate live check for the currency API (`open.er-api.com`), asserting it returns a
  positive numeric USD rate for XDR — cheap, catches a real provider outage or contract change
  independent of IATI.
- `funding_validator.py`'s existing test suite gains cases for `source: "afdb"` dispatch to
  `map_afdb_sector`, and for `upsert_funding` correctly persisting `originalAmount`/
  `originalCurrency`/`exchangeRate` (never exercised by any earlier connector).

## Documentation

`README.md`'s "Pipeline (Volet B)" section gains a short AfDB subsection once B1.3 ships,
noting: the pagination requirement (unlike B1.2), the currency-conversion deviation from the
cahier des charges' literal "BCE" wording (ECB structurally cannot serve XDR) and its
documented replacement source, the entity allowlist and why it exists (real double-counting
risk with B1.2's GCF connector, concretely observed), and the verified sector gaps
(`Adaptation` never populated by this source).
