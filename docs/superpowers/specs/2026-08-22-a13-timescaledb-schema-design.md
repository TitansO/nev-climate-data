# A1.3 — TimescaleDB deployment & pipeline-ready schema

Status: Approved
Author: Serge (with Claude)
Date: 2026-08-22
Plan reference: A1.3 (Phase A1 — Fondations), `Plan_Implementation_NEV_Climate_Data.xlsx`
Spec reference: `Cahier_des_charges_NEV_Climate_Data_v2.docx`, sections 5.2, 5.3, 5.5, 6.4

## Goal

Deploy TimescaleDB (replacing the placeholder `postgres:16-alpine` service) and create the
Doctrine schema for the eight entities identified in the cahier des charges section 5.3:
Funding, Country, Sector, Source, Report, User, ApiKey, Notification.

The schema must be "pipeline-ready" per the project's guiding principle (cahier des charges
section 2): Volet B (the real-data pipeline) must be able to plug in later without a heavy
migration. This means adding now the columns that Volet B will need (source tracking,
collection date, validation status, historization hooks, currency metadata), even though
Volet A's own application logic will not exercise all of them yet.

## Non-goals (out of scope for A1.3)

- Seed/fixture data — that's A1.6.
- Actual historization logic (insert-new-version-on-update) — Volet B's responsibility; A1.3
  only adds the columns.
- Currency conversion logic — Volet B's responsibility; A1.3 only adds the columns.
- Authentication/JWT wiring — A1.4.
- API endpoints — A2.x.

## Decisions made during brainstorming

1. **Naming convention: English.** All entity classes, properties, and DB columns are in
   English (`Funding`, `amount`, `validationStatus`), not French, despite the cahier des
   charges using French business vocabulary. Rationale: standard dev convention, better
   tooling/library compatibility.
2. **Primary keys: auto-increment integer/bigint**, not UUID. Volet B's deduplication will use
   a business unique constraint (`source_id, country, sector, year, funding_type`), not the
   primary key, so UUIDs bring no benefit here.
3. **Historization: columns ready, logic simple.** `Funding` gets `validFrom` (nullable),
   `validTo` (nullable), `isCurrent` (bool, default `true`) now. Volet A's application code
   keeps doing plain `UPDATE`s (these columns stay unused in practice). Volet B will change
   only the write *logic* (insert new versioned rows) — not the schema — when it lands.
4. **Enums vs. reference tables.** Fixed, low-cardinality, stable value sets use native PHP
   8.4 backed enums mapped through Doctrine (`FundingType`, `UserRole`, `ValidationStatus`,
   `ReportStatus`, `ApiKeyStatus`, `SourceType`, `SourceReliability`, `NotificationType`).
   `Country`, `Sector`, and `Source` stay full entities/tables because they are expected to
   grow (54 countries, multiple real sources added in Volet B).
5. **Currency: pivot-currency columns added now.** `Funding.amount` stores the value in the
   pivot currency. `originalAmount`, `originalCurrency`, `exchangeRate` are added now as
   nullable columns, per cahier des charges section 6.4, and stay unpopulated until Volet B.
   **Pivot currency default: USD** (a naming/documentation convention at this stage, not a
   technical constraint — trivially revisited with the client later).
6. **`User.role` has no "Visitor" case.** The cahier des charges' "Visiteur non authentifié"
   is the absence of an account, not a stored role. `UserRole` has three cases: `Admin`,
   `InternalAnalyst`, `ExternalPartner`.
7. **`Report.type` is a free-text string**, not an enum — the cahier des charges does not fix
   a list of report types, and the user confirmed no predefined list exists yet.
8. **`Report.country` is a nullable FK**, plus a separate nullable `region` string — the
   cahier des charges says "pays/région" for reports, which does not always map to a single
   `Country` row.
9. **Migrations: two, one per dependency layer** (see Architecture below), not one migration
   per entity and not a single migration for everything.
10. **README update is part of this task's deliverable** (explicit request from Serge), not
    deferred to a later documentation task.

## Architecture: two dependency layers, two migrations

**Layer 1 (no foreign keys):** `Country`, `Sector`, `Source`, `User`
**Layer 2 (FK into layer 1):** `Funding`, `Report`, `ApiKey`, `Notification`

`Migration1` creates the four layer-1 tables. `Migration2` creates the four layer-2 tables,
their foreign keys, and the indexes required by the performance requirement (cahier des
charges 5.5): `Funding` gets indexes on `country_id`, `sector_id`, `year`, `collection_date`.

Alongside the schema work, `docker-compose.yml`'s `database` service image changes from
`postgres:16-alpine` to `timescale/timescaledb:latest-pg16`. `DATABASE_URL` and the
`POSTGRES_*` environment variables are unchanged (TimescaleDB is protocol-compatible with
PostgreSQL), matching the note Oumar already left in the compose file and README.

## Data model

### Country (layer 1)
| Field | Type | Notes |
|---|---|---|
| id | int, PK, autoincrement | |
| name | string | |
| isoCode | string, unique | ISO 3166-1 |
| region | string | |

### Sector (layer 1)
| Field | Type | Notes |
|---|---|---|
| id | int, PK, autoincrement | |
| name | string, unique | e.g. Renewable Energy, Sustainable Transport, Agriculture, Forestry, Adaptation |

### Source (layer 1)
| Field | Type | Notes |
|---|---|---|
| id | int, PK, autoincrement | |
| name | string | e.g. "Internal Demo" now; "World Bank", "GCF", etc. in Volet B |
| type | enum `SourceType` | OfficialApi / PdfReport / GreenAccessEvent / InternalDemo |
| reliability | enum `SourceReliability` | Low / Medium / High |

### User (layer 1)
| Field | Type | Notes |
|---|---|---|
| id | int, PK, autoincrement | |
| name | string | |
| email | string, unique | |
| passwordHash | string | bcrypt/argon2, per cahier des charges 5.2.a |
| role | enum `UserRole` | Admin / InternalAnalyst / ExternalPartner (no "Visitor" case — see decision 6) |
| createdAt | datetime | |

### Funding (layer 2) — "Jeu de données / Financement"
| Field | Type | Notes |
|---|---|---|
| id | int, PK, autoincrement | |
| country | ManyToOne → Country | indexed |
| sector | ManyToOne → Sector | indexed |
| year | int | indexed |
| amount | decimal(15,2) | value in pivot currency (USD default) |
| originalAmount | decimal(15,2), nullable | reserved for Volet B |
| originalCurrency | string, nullable | ISO 4217 code, reserved for Volet B |
| exchangeRate | decimal(12,6), nullable | reserved for Volet B |
| fundingType | enum `FundingType` | Public / Private / Multilateral |
| source | ManyToOne → Source | |
| collectionDate | date | indexed; "date_collecte" from cahier des charges |
| validationStatus | enum `ValidationStatus` | Demo / Validated |
| validFrom | datetime, nullable | historization hook, unused by Volet A logic |
| validTo | datetime, nullable | historization hook, unused by Volet A logic |
| isCurrent | bool, default true | historization hook |
| createdAt | datetime | |
| updatedAt | datetime | |

### Report (layer 2)
| Field | Type | Notes |
|---|---|---|
| id | int, PK, autoincrement | |
| title | string | |
| country | ManyToOne → Country, nullable | |
| region | string, nullable | for reports not tied to a single country |
| type | string | free text, no fixed list (decision 7) |
| publicationDate | date, nullable | a draft has none yet |
| status | enum `ReportStatus` | Draft / Published |
| pdfFile | string | file path/reference |
| downloadCount | int, default 0 | |
| createdAt | datetime | |
| updatedAt | datetime | |

### ApiKey (layer 2)
| Field | Type | Notes |
|---|---|---|
| id | int, PK, autoincrement | |
| user | ManyToOne → User | |
| keyHash | string | plaintext key shown once at creation only, never stored (cahier des charges 5.2.b) |
| status | enum `ApiKeyStatus` | Active / Revoked |
| quota | int | daily request quota |
| createdAt | datetime | |
| revokedAt | datetime, nullable | |

### Notification (layer 2)
| Field | Type | Notes |
|---|---|---|
| id | int, PK, autoincrement | |
| user | ManyToOne → User | |
| eventType | enum `NotificationType` | NewReport / NewData |
| content | string | |
| isRead | bool, default false | |
| createdAt | datetime | |

## Testing / validation

- `doctrine:schema:validate` after each migration.
- Migrate up, then roll back (`doctrine:migrations:migrate prev` twice), then migrate up
  again, to confirm both migrations are safely reversible before considering A1.3 done.
- Manual check that the `database` container is running the `timescale/timescaledb` image
  and that `backend` still connects successfully (`dbal:run-sql "SELECT 1 AS ok"`, as
  documented in the current README).

## Documentation

`README.md` is updated as part of this task:
- "Prochaine étape" section updated to reflect A1.3 as done and point to A1.4 (JWT) next.
- New section describing the eight entities and the two-layer migration structure.
- Migration commands documented (`doctrine:migrations:migrate`, `doctrine:migrations:status`).
- Note confirming the TimescaleDB image swap is complete (superseding the "sera remplacée"
  note Oumar left).
