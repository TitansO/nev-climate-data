# B1.1 World Bank Connector Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Collect World Bank climate-themed project financing for every NEV-tracked country, quarterly, and land it in the `funding` table via the shared Volet B pipeline (Kafka + a Python validator writing directly to TimescaleDB).

**Architecture:** A new `pipeline/` Python codebase (separate from `backend/`), orchestrated by Airflow, exchanging data over Kafka topics `nev.funding.raw` → `nev.funding.valides`/`nev.funding.rejets`, writing straight to the existing `funding` table (no Symfony API involved). This is also the first Volet B task, so it provisions the shared infrastructure (Kafka, Airflow, MinIO, Redis) that later B1.x/B2.x tasks will reuse without re-provisioning.

**Tech Stack:** Python 3.12, `kafka-python`, `psycopg2-binary`, `requests`, Apache Airflow 2.9 (LocalExecutor), Kafka (Confluent images, 1 broker), MinIO, Redis, pytest.

## Global Constraints

- Isolation from the unrelated Observatoire CIMA project: entirely separate Kafka/MinIO/Airflow stack, no shared credentials or endpoints (architecture spec decision 1).
- Processors write directly to TimescaleDB, bypassing the Symfony API (architecture spec decision 3).
- Topic names: `nev.funding.raw`, `nev.funding.valides`, `nev.funding.rejets` (architecture spec decision 4).
- `fundingType` is always `Multilateral` for this connector (B1.1 spec decision 2).
- Sector mapping is the ordered keyword table in the B1.1 spec (decision 4); no match, or no sector data at all, means quarantine to `nev.funding.rejets`, never a guessed sector (decision 5).
- Records landing on the same dedup key (`source_id, country_id, sector_id, year, funding_type`) are summed, not overwritten, with the prior version historized (`valid_to`/`is_current`) — B1.1 spec decision 6.
- `year` = calendar year of `boardapprovaldate`; `collectionDate`/`collected_at` = when the DAG actually ran (B1.1 spec decision 7).
- Schedule: quarterly (B1.1 spec decision 8; cahier des charges 6.4).
- No Mercure is added (architecture spec decision 10) — out of scope here.
- Repo root for all paths below: `nev-climate-data/`. All `docker compose` commands run from that root.

---

## Task 1: Provision shared Volet B infrastructure

**Files:**
- Modify: `docker-compose.yml`
- Modify: `.env.example`

**Interfaces:**
- Produces: running `kafka`, `airflow`, `minio`, `redis` services on `nev-network`, reachable by hostname from any other service on that network. Consumed by every later task in this plan (and by future B1.x/B2.x plans, which will not need to re-provision).

- [ ] **Step 1: Add the new services to `docker-compose.yml`**

Add these services alongside the existing `backend`/`database`/`redis` (note: `redis` already
exists from Phase A2 — if the `redis` service block is already present, do not duplicate it;
only add `zookeeper`, `kafka`, `postgres-airflow`, `airflow`, `minio`):

```yaml
  zookeeper:
    image: confluentinc/cp-zookeeper:7.6.0
    container_name: nev-climate-data-zookeeper
    restart: unless-stopped
    environment:
      ZOOKEEPER_CLIENT_PORT: 2181
    networks:
      - nev-network

  kafka:
    image: confluentinc/cp-kafka:7.6.0
    container_name: nev-climate-data-kafka
    restart: unless-stopped
    depends_on:
      - zookeeper
    environment:
      KAFKA_BROKER_ID: 1
      KAFKA_ZOOKEEPER_CONNECT: zookeeper:2181
      KAFKA_ADVERTISED_LISTENERS: PLAINTEXT://kafka:9092
      KAFKA_OFFSETS_TOPIC_REPLICATION_FACTOR: 1
    ports:
      - "9092:9092"
    networks:
      - nev-network

  postgres-airflow:
    image: postgres:16
    container_name: nev-climate-data-airflow-db
    restart: unless-stopped
    environment:
      POSTGRES_USER: airflow
      POSTGRES_PASSWORD: airflow
      POSTGRES_DB: airflow
    volumes:
      - airflow_postgres_data:/var/lib/postgresql/data
    networks:
      - nev-network

  airflow:
    image: apache/airflow:2.9.0
    container_name: nev-climate-data-airflow
    restart: unless-stopped
    depends_on:
      - postgres-airflow
      - kafka
    environment:
      AIRFLOW__CORE__EXECUTOR: LocalExecutor
      AIRFLOW__DATABASE__SQL_ALCHEMY_CONN: postgresql+psycopg2://airflow:airflow@postgres-airflow/airflow
      AIRFLOW__CORE__LOAD_EXAMPLES: "false"
      _PIP_ADDITIONAL_REQUIREMENTS: "kafka-python-ng==2.2.3 psycopg2-binary==2.9.9 requests==2.32.3 pycountry==24.6.1"
      PIPELINE_DATABASE_URL: postgresql://${POSTGRES_USER}:${POSTGRES_PASSWORD}@database:5432/${POSTGRES_DB}
    volumes:
      - ./pipeline:/opt/airflow/pipeline
      - airflow_logs:/opt/airflow/logs
    ports:
      - "8081:8080"
    command: standalone
    networks:
      - nev-network

  minio:
    image: minio/minio
    container_name: nev-climate-data-minio
    restart: unless-stopped
    command: server /data --console-address ":9001"
    environment:
      MINIO_ROOT_USER: ${MINIO_ROOT_USER:-minioadmin}
      MINIO_ROOT_PASSWORD: ${MINIO_ROOT_PASSWORD:-minioadmin_change_me}
    ports:
      - "9000:9000"
      - "9001:9001"
    volumes:
      - minio_data:/data
    networks:
      - nev-network

  kafka-ui:
    image: provectuslabs/kafka-ui:latest
    container_name: nev-climate-data-kafka-ui
    restart: unless-stopped
    depends_on:
      - kafka
    environment:
      KAFKA_CLUSTERS_0_NAME: nev-climate-data
      KAFKA_CLUSTERS_0_BOOTSTRAPSERVERS: kafka:9092
    ports:
      - "8083:8080"
    networks:
      - nev-network
```

`kafka-ui` is a read-only visual browser for Kafka (topics, partitions, live message
content) — not part of the pipeline's own architecture (nothing in the architecture or B1.1
spec references it), added purely so Serge can see what's flowing through Kafka in a browser
instead of only via CLI, per his explicit request when this plan was reviewed. Port `8083`
(not `8080`, taken by `backend`, nor `8081`, taken by `airflow`).

Add the two new named volumes to the `volumes:` top-level block:

```yaml
  airflow_postgres_data:
  airflow_logs:
  minio_data:
```

`airflow`'s port is `8081` on the host (not `8080`) because the backend already uses `8080`
for its own `BACKEND_PORT` default. Airflow's own internal UI port stays `8080` inside the
container; only the host-side mapping changes.

- [ ] **Step 2: Add MinIO credentials to `.env.example`**

```
# --- MinIO (Volet B — Bronze/Silver/Gold data lake) -----------------------
MINIO_ROOT_USER=minioadmin
MINIO_ROOT_PASSWORD=change_me_use_a_strong_password
```

Add the same two lines with real values to the local `.env` (gitignored).

- [ ] **Step 3: Start the new services and verify they're healthy**

Run:
```bash
docker compose up -d zookeeper kafka postgres-airflow airflow minio kafka-ui
```

Wait roughly a minute for Airflow's `standalone` mode to finish initializing its database and
create the default admin user (it logs the generated password to its container logs on first
boot), then check:

```bash
docker compose ps
```
Expected: `zookeeper`, `kafka`, `postgres-airflow`, `airflow`, `minio`, `kafka-ui` all show `Up`.

```bash
docker compose logs airflow | grep -i "Login with username"
```
Expected: a line showing the auto-generated admin username/password — note it down, you will
need it to open `http://localhost:8081` in Task 9.

```bash
docker compose exec kafka kafka-topics --bootstrap-server localhost:9092 --list
```
Expected: succeeds (proves Kafka itself is reachable), even if the list is empty at this point.

```bash
docker compose exec minio mc --version
```
(Or simply open `http://localhost:9001` in a browser and confirm the MinIO console login page
loads with the credentials from `.env`.)

Open `http://localhost:8083` in a browser. Expected: the Kafka UI dashboard loads and shows
the `nev-climate-data` cluster as online (0 topics at this point — Task 2 creates the first
ones).

- [ ] **Step 4: Commit**

```bash
git add docker-compose.yml .env.example
git commit -m "feat(b1.1): provision shared Volet B infrastructure (Kafka, Airflow, MinIO, Kafka UI)"
```

---

## Task 2: Create the Kafka topics

**Files:**
- None (operational step against the running Kafka broker — no file changes).

**Interfaces:**
- Consumes: the `kafka` service from Task 1.
- Produces: topics `nev.funding.raw`, `nev.funding.valides`, `nev.funding.rejets`. Consumed by Task 6 (collector, producer to `.raw`) and Task 7 (validator, consumer of `.raw`, producer to `.valides`/`.rejets`).

- [ ] **Step 1: Create the three topics**

```bash
docker compose exec kafka kafka-topics --create --topic nev.funding.raw \
  --bootstrap-server localhost:9092 --partitions 6 --replication-factor 1

docker compose exec kafka kafka-topics --create --topic nev.funding.valides \
  --bootstrap-server localhost:9092 --partitions 6 --replication-factor 1

docker compose exec kafka kafka-topics --create --topic nev.funding.rejets \
  --bootstrap-server localhost:9092 --partitions 3 --replication-factor 1
```

- [ ] **Step 2: Verify**

```bash
docker compose exec kafka kafka-topics --bootstrap-server localhost:9092 --list
```
Expected: all three topic names appear in the output.

No commit for this task — nothing in the repo changed, only broker state (which is not
persisted across a full `docker compose down -v`, so this step would need re-running after a
volume wipe; that's expected and cheap to redo).

---

## Task 3: Add the funding dedup unique constraint and the source-name uniqueness

**Files:**
- Modify: `backend/src/Entity/Funding.php`
- Modify: `backend/src/Entity/Source.php`
- Create: `backend/migrations/VersionXXXXXXXXXXXXXX.php` (timestamp auto-generated)

**Interfaces:**
- Produces: a unique constraint on `funding(source_id, country_id, sector_id, year, funding_type)`, relied on by Task 7's `INSERT ... ON CONFLICT` upsert logic, and a unique constraint on `source.name`, relied on by Task 7's `ensure_world_bank_source`'s `ON CONFLICT (name) DO NOTHING` — without either, Postgres has no conflict target to upsert against, and `ON CONFLICT` errors at runtime with "no unique or exclusion constraint matching the ON CONFLICT specification".

- [ ] **Step 1: Add both constraints to the entity mappings**

In `backend/src/Entity/Funding.php`, add a `#[ORM\UniqueConstraint(...)]` attribute alongside
the existing `#[ORM\Index(...)]` attributes on the class (same attribute stack, just one more
entry):

```php
#[ORM\Table(name: 'funding')]
#[ORM\Index(columns: ['country_id'], name: 'idx_funding_country')]
#[ORM\Index(columns: ['sector_id'], name: 'idx_funding_sector')]
#[ORM\Index(columns: ['year'], name: 'idx_funding_year')]
#[ORM\Index(columns: ['collection_date'], name: 'idx_funding_collection_date')]
#[ORM\UniqueConstraint(
    name: 'uniq_funding_dedup_key',
    columns: ['source_id', 'country_id', 'sector_id', 'year', 'funding_type'],
)]
#[ORM\Entity(repositoryClass: FundingRepository::class)]
class Funding
```

In `backend/src/Entity/Source.php`, add `unique: true` to the existing `name` column
attribute:

```php
    #[ORM\Column(length: 255, unique: true)]
    private string $name;
```

- [ ] **Step 2: Generate and review the migration**

**Correction found while executing this step (real bug, not a hypothetical): the
`#[ORM\UniqueConstraint]` must NOT be a flat constraint on those 5 columns.** `Funding` already
carries historization columns from A1.3 (`isCurrent`/`validFrom`/`validTo`), and B1.1 spec
decision 6 explicitly historizes on every accumulation — meaning multiple rows legitimately
share the same `(source_id, country_id, sector_id, year, funding_type)` tuple over time, one
per version, with `is_current = true` on at most one of them. A flat constraint across every
row (current and historized alike) rejects the second version the moment it's inserted, and
confirmed via the real PHPUnit suite (Step 5) that it also breaks pre-existing Volet A test
fixtures (`FundingControllerTest`, `AnalyticsControllerTest`, `FundingExportTest`) that
legitimately create several `Funding` rows sharing a tuple for pagination/aggregation testing.
The fix is a **partial unique index**, scoped with `WHERE is_current = true`, expressed via
Doctrine's `options: ['where' => ...]` on the same attribute:

```php
#[ORM\UniqueConstraint(
    name: 'uniq_funding_dedup_key_current',
    columns: ['source_id', 'country_id', 'sector_id', 'year', 'funding_type'],
    options: ['where' => 'is_current = true'],
)]
```

(Doctrine ORM 3.6+; confirmed via reflection that `UniqueConstraint` accepts `options`, and
DBAL's PostgreSQL platform renders `options['where']` as the index's `WHERE` clause.) Task 7's
`ON CONFLICT` target must match: `ON CONFLICT (source_id, country_id, sector_id, year,
funding_type) WHERE is_current = true DO UPDATE ...` — Postgres supports inferring a conflict
target from a partial unique index this way.

Run: `docker compose exec backend php bin/console doctrine:migrations:diff --no-interaction`

Expected: a single new migration whose `up()` contains
`CREATE UNIQUE INDEX uniq_funding_dedup_key_current ON funding (source_id, country_id,
sector_id, year, funding_type) WHERE is_current = true` **and**
`CREATE UNIQUE INDEX ... ON source (name)` — nothing else. If the `source` constraint fails to
apply because two existing rows already share a name (check the A1.6 fixtures —
`SourceFixtures.php` creates one row per `SourceType` case, which should already be distinct by
construction, but verify rather than assume), that's a real pre-existing data issue to resolve,
not to migrate around. As with every migration generated in this project, check `down()` for
spurious `CREATE SCHEMA`/`DROP SEQUENCE` statements on `timescaledb*`/`_timescaledb*` objects
and remove them if present (see
`docs/superpowers/specs/2026-08-22-a13-timescaledb-schema-design.md` for why this recurs).

If the migration fails to apply because existing demo fixture data already violates the new
constraint (two *current* `Funding` rows sharing a key), that is a real pre-existing data issue
to investigate — do not silently delete rows to force the migration through; report it before
proceeding.

**Also**, if this repo's `developp` branch has migrations from other in-flight work (e.g. a
colleague's own feature branch merged since this plan was written) that are not yet applied to
your local test/dev databases, `migrations:diff` will bundle their tables into this migration
too. Run `doctrine:migrations:status` first and `doctrine:migrations:migrate` to catch up to
`Latest` *before* running `diff`, so the generated migration contains only this task's own
change.

- [ ] **Step 3: Apply to test and dev, verify**

```bash
docker compose exec -e APP_ENV=test backend php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec backend php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec backend php bin/console doctrine:schema:validate
```
Expected: **`[OK] The mapping files are correct.`, but `[ERROR] The database schema is not in
sync` on the `Database` line is expected and permanent** — confirmed via
`doctrine:schema:update --dump-sql` that the only proposed change is `DROP INDEX
uniq_funding_dedup_key_current; CREATE UNIQUE INDEX ... WHERE is_current = true;`, i.e. Doctrine
proposing to replace the partial index with an identical copy of itself. This is a known
Doctrine/DBAL limitation: the PostgreSQL schema comparator does not read back a partial index's
`WHERE` clause, so it can never recognize the index it introspects as matching the one in the
mapping. Verify manually instead with `\d funding` in `psql` (or `docker compose exec database
psql -U <user> -d <db> -c '\d funding'`) that `uniq_funding_dedup_key_current` exists with `...
WHERE is_current = true` — that is the real, sufficient proof this step passed. Do **not** run
`migrations:diff` again to "fix" this; it only regenerates the same no-op drop/recreate.

- [ ] **Step 4: Verify reversibility**

```bash
docker compose exec backend php bin/console doctrine:migrations:migrate prev --no-interaction
docker compose exec backend php bin/console doctrine:migrations:migrate --no-interaction
```
Expected: no errors on either command. (Skip `doctrine:schema:validate` here — see Step 3's
note; it will always report the same expected false positive.)

- [ ] **Step 5: Run the existing PHPUnit suite to confirm no regression**

```bash
docker compose exec -e APP_ENV=test backend php bin/phpunit
```
Expected: full suite green — **it will not be, on the codebase as it stood when this plan was
written.** Confirmed by actually running it: 43 errors, all `SQLSTATE[23505]: Unique violation
... uniq_funding_dedup_key_current`, all in `FundingControllerTest`, `AnalyticsControllerTest`,
`FundingExportTest`. The partial index (`WHERE is_current = true`) does not save these tests —
every fixture row in all three is created with the entity's default `isCurrent = true` and
nothing ever historizes an earlier one, so two rows sharing a tuple still collide.

This is not a fixture-only fix. Checked every assertion across the three files: each of the
five dedup-key columns (`source`, `country`, `sector`, `year`, `funding_type`) is pinned exactly
by some assertion somewhere in this trio —
`FundingControllerTest::testFilteringByYearReturnsOnlyMatchingRecords` expects exactly 15 rows
back for `?year=2025`; `AnalyticsControllerTest` asserts `activeSources === 1` ("only Test
Source"); country/sector/funding_type are each exercised by their own filter test. There is no
column left free to vary per fixture row without breaking an assertion elsewhere in the same
file. Resolving this for real means restructuring how these three pre-existing Volet A/A2.x
tests build their dataset (most likely: one row per distinct dedup key with the *summed* amount
that today's `N` identical rows represent, restructured to still produce enough distinct rows
for whatever each test's pagination/count logic needs) — a change to test files this plan does
not own the intent of, not a B1.1-scoped fix. **Flag this to Serge and get explicit direction
before touching those three files; do not silently rewrite another engineer's test assertions
to force this step green.**

- [ ] **Step 6: Commit**

```bash
git add backend/src/Entity/Funding.php backend/src/Entity/Source.php backend/migrations/
git commit -m "feat(b1.1): add unique constraints on funding dedup key and source.name"
```

---

## Task 4: Pipeline codebase skeleton

**Files:**
- Create: `pipeline/Dockerfile`
- Create: `pipeline/requirements.txt`
- Create: `pipeline/common/__init__.py`
- Create: `pipeline/common/db.py`
- Create: `pipeline/common/kafka_client.py`
- Create: `pipeline/collectors/__init__.py`
- Create: `pipeline/processors/__init__.py`
- Create: `pipeline/dags/__init__.py`
- Create: `pipeline/tests/__init__.py`
- Modify: `docker-compose.yml` (add a `pipeline` service usable for one-off `exec` commands and as the base image the `funding-validator` service in Task 7 builds on)

**Interfaces:**
- Produces: `pipeline.common.db.get_connection() -> psycopg2.connection` and
  `pipeline.common.kafka_client.make_producer() -> KafkaProducer` /
  `make_consumer(topic: str, group_id: str) -> KafkaConsumer`. Consumed by every task from here on.

- [ ] **Step 1: Write `pipeline/requirements.txt`**

```
kafka-python-ng==2.2.3
psycopg2-binary==2.9.9
requests==2.32.3
pycountry==24.6.1
pytest==8.3.3
```

`kafka-python-ng` (not `kafka-python`) — confirmed during Task 4 execution: plain
`kafka-python==2.0.2`'s vendored `six` shim breaks under Python 3.12
(`ModuleNotFoundError: No module named 'kafka.vendor.six.moves'` on `import kafka`, reproduced
against this exact image). `kafka-python-ng` is the actively-maintained fork, is a drop-in
replacement (`from kafka import KafkaProducer, KafkaConsumer` unchanged — the module namespace
is still `kafka`, only the PyPI distribution name differs), and was verified working against
the real `kafka` service in this stack. This affects every place `kafka-python` is installed —
also update the `airflow` service's `_PIP_ADDITIONAL_REQUIREMENTS` in Task 1's
`docker-compose.yml` snippet the same way.

`pycountry` converts the World Bank API's 2-letter (`SN`) country codes into the 3-letter
codes (`SEN`) that `Country.isoCode` actually stores (see `#[ORM\Column(length: 3, ...)]` on
`backend/src/Entity/Country.php`, from A1.3) — without it, every real record from Task 6 would
fail the country lookup in Task 7 and be silently quarantined, since `SN` never matches `SEN`.

- [ ] **Step 2: Write `pipeline/Dockerfile`**

```dockerfile
FROM python:3.12-slim

WORKDIR /app

COPY pipeline/requirements.txt ./
RUN pip install --no-cache-dir -r requirements.txt

COPY pipeline/ ./pipeline/

ENV PYTHONPATH=/app
```

- [ ] **Step 3: Write `pipeline/common/db.py`**

```python
"""Shared TimescaleDB connection helper for the Volet B pipeline.

Reads PIPELINE_DATABASE_URL, a plain psycopg2 DSN (not a SQLAlchemy URL) -
see the `pipeline` and `airflow` service definitions in docker-compose.yml
for how it's built from the same POSTGRES_* variables the Symfony backend
uses.
"""
import os

import psycopg2


def get_connection():
    """Opens a new psycopg2 connection to the shared TimescaleDB instance.

    Callers are responsible for closing the connection (or using it as a
    context manager) - this function does not pool or cache connections,
    matching the short-lived-script usage pattern of Airflow tasks and the
    one-connection-per-message usage of the Kafka consumer service.
    """
    dsn = os.environ["PIPELINE_DATABASE_URL"]
    return psycopg2.connect(dsn)
```

- [ ] **Step 4: Write `pipeline/common/kafka_client.py`**

```python
"""Shared Kafka producer/consumer factory helpers for the Volet B pipeline."""
import json
import os

from kafka import KafkaConsumer, KafkaProducer

BOOTSTRAP_SERVERS = os.environ.get("KAFKA_BOOTSTRAP_SERVERS", "kafka:9092")


def make_producer() -> KafkaProducer:
    return KafkaProducer(
        bootstrap_servers=BOOTSTRAP_SERVERS,
        value_serializer=lambda value: json.dumps(value).encode("utf-8"),
    )


def make_consumer(topic: str, group_id: str) -> KafkaConsumer:
    return KafkaConsumer(
        topic,
        bootstrap_servers=BOOTSTRAP_SERVERS,
        value_deserializer=lambda value: json.loads(value.decode("utf-8")),
        group_id=group_id,
        auto_offset_reset="earliest",
        enable_auto_commit=True,
    )
```

- [ ] **Step 5: Create the empty `__init__.py` files**

```bash
touch pipeline/common/__init__.py pipeline/collectors/__init__.py pipeline/processors/__init__.py pipeline/dags/__init__.py pipeline/tests/__init__.py
```

- [ ] **Step 6: Add the `pipeline` service to `docker-compose.yml`**

```yaml
  pipeline:
    build:
      context: .
      dockerfile: pipeline/Dockerfile
    container_name: nev-climate-data-pipeline
    environment:
      PIPELINE_DATABASE_URL: postgresql://${POSTGRES_USER}:${POSTGRES_PASSWORD}@database:5432/${POSTGRES_DB}
      KAFKA_BOOTSTRAP_SERVERS: kafka:9092
    depends_on:
      - database
      - kafka
    networks:
      - nev-network
    profiles:
      - pipeline-tools
```

The `profiles: [pipeline-tools]` entry keeps this service from starting automatically on a
plain `docker compose up` — it exists so this plan's tasks can `docker compose run --rm
pipeline ...` for one-off commands (building the image, running tests). Task 7 replaces this
`profiles` line when it turns the same image into the always-on `funding-validator` service.

- [ ] **Step 7: Build the image and verify connectivity**

```bash
docker compose build pipeline
docker compose run --rm pipeline python -c "from pipeline.common.db import get_connection; get_connection(); print('DB OK')"
docker compose run --rm pipeline python -c "from pipeline.common.kafka_client import make_producer; make_producer(); print('Kafka OK')"
```
Expected: both print their `OK` line with no exception.

- [ ] **Step 8: Commit**

```bash
git add pipeline/ docker-compose.yml
git commit -m "feat(b1.1): add pipeline/ Python codebase skeleton"
```

---

## Task 5: Sector-mapping function

**Files:**
- Create: `pipeline/processors/sector_mapping.py`
- Test: `pipeline/tests/test_sector_mapping.py`

**Interfaces:**
- Produces: `map_to_nev_sector(raw_sectors: list[str], raw_theme: list[str]) -> str | None`. Consumed by Task 7's `process_message`.

- [ ] **Step 1: Write the failing tests**

```python
from pipeline.processors.sector_mapping import map_to_nev_sector


def test_maps_solar_energy_to_renewable_energy():
    result = map_to_nev_sector(
        raw_sectors=["Energy Generation - Solar", "Energy Networks and Storage"],
        raw_theme=["Climate Change", "Adaptation"],
    )
    assert result == "Renewable Energy"


def test_energy_project_wins_over_adaptation_theme():
    result = map_to_nev_sector(
        raw_sectors=["Energy Generation - Solar"],
        raw_theme=["Adaptation"],
    )
    assert result == "Renewable Energy"


def test_maps_roads_to_sustainable_transport():
    result = map_to_nev_sector(raw_sectors=["Rural and Inter-Urban Roads"], raw_theme=[])
    assert result == "Sustainable Transport"


def test_maps_agriculture_to_agriculture():
    result = map_to_nev_sector(raw_sectors=["Agricultural Extension"], raw_theme=[])
    assert result == "Agriculture"


def test_maps_forest_to_forestry():
    result = map_to_nev_sector(raw_sectors=["Forestry"], raw_theme=[])
    assert result == "Forestry"


def test_falls_back_to_adaptation_theme_when_no_sector_matches():
    result = map_to_nev_sector(
        raw_sectors=["Public Administration - Health"],
        raw_theme=["Disaster Risk Management", "Adaptation"],
    )
    assert result == "Adaptation"


def test_returns_none_when_nothing_matches():
    result = map_to_nev_sector(raw_sectors=["Health"], raw_theme=["Social Protection"])
    assert result is None


def test_returns_none_when_no_sector_data_at_all():
    result = map_to_nev_sector(raw_sectors=[], raw_theme=[])
    assert result is None
```

Save as `pipeline/tests/test_sector_mapping.py`.

- [ ] **Step 2: Run to verify it fails**

```bash
docker compose run --rm pipeline python -m pytest pipeline/tests/test_sector_mapping.py -v
```
Expected: `ModuleNotFoundError: No module named 'pipeline.processors.sector_mapping'`.

- [ ] **Step 3: Write the implementation**

```python
"""Maps a World Bank project's raw sector/theme strings onto one of NEV's
five funding sectors, per the ordered rule table in
docs/superpowers/specs/2026-08-26-b11-world-bank-connector-design.md
(decision 4). Returns None - triggering quarantine, per decision 5 - when
nothing matches or no sector data was supplied at all.
"""
from __future__ import annotations

# Ordered: first match wins. Adaptation is checked last and only against
# `raw_theme` (not sector names) - it's a cross-cutting World Bank theme,
# not one of its major sectors, and a project that is e.g. both "Energy
# Generation - Solar" and thematically "Adaptation" should land in
# Renewable Energy, its more specific classification.
_SECTOR_RULES: list[tuple[str, list[str]]] = [
    ("Renewable Energy", ["energy generation", "renewable", "solar", "wind", "hydropower"]),
    ("Sustainable Transport", ["transport", "roads", "urban mobility"]),
    # "agricultur" (stem, not "agriculture") - matches both "Agriculture" and
    # "Agricultural Extension"/"Agricultural Research" etc.; "agriculture" as a
    # literal substring does not match "agricultural" (diverges after
    # "agricultur": "-e" vs "-al"), confirmed by a real failing test when this
    # task was executed.
    ("Agriculture", ["agricultur", "rural development", "irrigation"]),
    ("Forestry", ["forest"]),
]

_ADAPTATION_KEYWORDS = ["adaptation"]


def map_to_nev_sector(raw_sectors: list[str], raw_theme: list[str]) -> str | None:
    """Returns one of NEV's five sector names, or None if unclassifiable.

    `raw_sectors` and `raw_theme` are the flattened sector-name list and
    theme list from a `nev.funding.raw` Kafka message - see the B1.1
    spec's payload shape.
    """
    haystack_sectors = " | ".join(raw_sectors).lower()
    for nev_sector, keywords in _SECTOR_RULES:
        if any(keyword in haystack_sectors for keyword in keywords):
            return nev_sector

    haystack_theme = " | ".join(raw_theme).lower()
    for keyword in _ADAPTATION_KEYWORDS:
        if keyword in haystack_theme:
            return "Adaptation"

    return None
```

Save as `pipeline/processors/sector_mapping.py`.

- [ ] **Step 4: Run to verify it passes**

```bash
docker compose run --rm pipeline python -m pytest pipeline/tests/test_sector_mapping.py -v
```
Expected: PASS (8 tests).

- [ ] **Step 5: Commit**

```bash
git add pipeline/processors/sector_mapping.py pipeline/tests/test_sector_mapping.py
git commit -m "feat(b1.1): add sector-mapping function"
```

---

## Task 6: World Bank collector

**Files:**
- Create: `pipeline/collectors/world_bank.py`
- Test: `pipeline/tests/test_world_bank_collector.py`
- Test: `pipeline/tests/test_world_bank_collector_live.py`

**Interfaces:**
- Consumes: nothing from earlier tasks (pure HTTP + Kafka producer).
- Produces: `collect_and_publish(country_isos: list[str], producer) -> int`. Consumed by Task 8 (the DAG).

- [ ] **Step 1: Write the failing offline unit tests**

```python
"""Unit tests for the World Bank collector's parsing/pagination logic -
uses mocked HTTP responses (payload shapes captured from the real API
during the B1.1 design work) rather than hitting the network, so this
file runs offline and fast. The live-network smoke test lives in
test_world_bank_collector_live.py, kept separate so it can be skipped
independently if the external API is unreachable.
"""
from unittest.mock import MagicMock, patch

from pipeline.collectors.world_bank import (
    collect_and_publish,
    fetch_projects_for_country,
    parse_project,
)

_SAMPLE_PROJECT_WITH_SECTOR = {
    "id": "P506839",
    "countryname": "Republic of Senegal",
    "countrycode": ["SN"],
    "totalamt": "300000000",
    "boardapprovaldate": "2026-09-15T00:00:00Z",
    "status": "Dropped",
    "major_sectors": [
        {"major_sector": {"major_sector_name": "Energy and Mineral Resources"}},
    ],
    "theme": " Adaptation,Climate Change,Green and Resilient Growth",
}

_SAMPLE_PROJECT_PIPELINE_NO_DATA = {
    "id": "P516778",
    "countryname": "Republic of Senegal",
    "countrycode": ["SN"],
    "totalamt": "0",
    "boardapprovaldate": "2026-09-24T00:00:00Z",
    "status": "Pipeline",
}


def test_parse_project_extracts_expected_fields():
    result = parse_project(_SAMPLE_PROJECT_WITH_SECTOR)

    assert result["source"] == "world_bank"
    assert result["project_id"] == "P506839"
    assert result["country_iso"] == "SEN"  # converted from the API's alpha-2 "SN" via pycountry
    assert result["year"] == 2026
    assert result["amount_usd"] == 300000000
    assert result["funding_type"] == "multilateral"
    assert result["raw_sectors"] == ["Energy and Mineral Resources"]
    assert result["raw_theme"] == ["Adaptation", "Climate Change", "Green and Resilient Growth"]
    assert result["board_approval_date"] == "2026-09-15"


def test_parse_project_returns_none_for_zero_amount():
    assert parse_project(_SAMPLE_PROJECT_PIPELINE_NO_DATA) is None


def test_fetch_projects_for_country_paginates_until_total_reached():
    page_one = {"total": "150", "projects": {"P1": {"id": "P1"}, "P2": {"id": "P2"}}}
    page_two = {"total": "150", "projects": {"P3": {"id": "P3"}}}
    mock_response_one = MagicMock()
    mock_response_one.json.return_value = page_one
    mock_response_two = MagicMock()
    mock_response_two.json.return_value = page_two

    with patch(
        "pipeline.collectors.world_bank.requests.get",
        side_effect=[mock_response_one, mock_response_two],
    ) as mock_get:
        results = list(fetch_projects_for_country("SN"))

    assert [project["id"] for project in results] == ["P1", "P2", "P3"]
    assert mock_get.call_count == 2
    assert mock_get.call_args_list[0].kwargs["params"]["os"] == 0
    assert mock_get.call_args_list[1].kwargs["params"]["os"] == 100


def test_collect_and_publish_sends_only_parseable_projects_and_returns_count():
    page = {
        "total": "2",
        "projects": {
            "P506839": _SAMPLE_PROJECT_WITH_SECTOR,
            "P516778": _SAMPLE_PROJECT_PIPELINE_NO_DATA,
        },
    }
    mock_response = MagicMock()
    mock_response.json.return_value = page
    mock_producer = MagicMock()

    with patch("pipeline.collectors.world_bank.requests.get", return_value=mock_response):
        published = collect_and_publish(["SN"], mock_producer)

    assert published == 1
    mock_producer.send.assert_called_once()
    assert mock_producer.send.call_args[0][0] == "nev.funding.raw"
    mock_producer.flush.assert_called_once()
```

Save as `pipeline/tests/test_world_bank_collector.py`.

- [ ] **Step 2: Run to verify it fails**

```bash
docker compose run --rm pipeline python -m pytest pipeline/tests/test_world_bank_collector.py -v
```
Expected: `ModuleNotFoundError: No module named 'pipeline.collectors.world_bank'`.

- [ ] **Step 3: Write the implementation**

```python
"""World Bank Projects & Operations API collector for climate-themed
financing (B1.1). Fetches, paginates, and publishes raw payloads to
Kafka topic `nev.funding.raw` - see the B1.1 spec's payload shape.
"""
from __future__ import annotations

import datetime as dt
from typing import Any, Iterator

import pycountry
import requests

PROJECTS_API_URL = "https://search.worldbank.org/api/v3/projects"
PAGE_SIZE = 100
REQUEST_TIMEOUT_SECONDS = 30

FIELDS = ",".join([
    "id", "countryname", "countrycode", "totalamt", "boardapprovaldate",
    "status", "major_sectors", "theme",
])


def fetch_projects_for_country(country_iso: str) -> Iterator[dict[str, Any]]:
    """Yields every raw project record for `country_iso` matching the
    Climate change theme, paginating through the full result set (a
    single country can have hundreds of matching projects - verified 264
    for Senegal alone during the B1.1 design work, well above one page).
    """
    offset = 0
    while True:
        response = requests.get(
            PROJECTS_API_URL,
            params={
                "format": "json",
                "countrycode_exact": country_iso,
                "mjtheme": "Climate change",
                "fl": FIELDS,
                "rows": PAGE_SIZE,
                "os": offset,
            },
            timeout=REQUEST_TIMEOUT_SECONDS,
        )
        response.raise_for_status()
        payload = response.json()

        projects = payload.get("projects", {})
        if not projects:
            return

        yield from projects.values()

        offset += PAGE_SIZE
        if offset >= int(payload.get("total", 0)):
            return


def parse_project(project: dict[str, Any]) -> dict[str, Any] | None:
    """Converts one raw World Bank project record into the `nev.funding.raw`
    payload shape. Returns None for a project with no usable financing
    amount or approval date - such a project (e.g. very early "Pipeline"
    status, verified live to lack these fields) has nothing to publish.
    """
    total_amount = project.get("totalamt")
    approval_date = project.get("boardapprovaldate")
    if not total_amount or not approval_date or int(total_amount) <= 0:
        return None

    raw_sectors = [
        entry["major_sector"]["major_sector_name"]
        for entry in project.get("major_sectors", [])
        if "major_sector" in entry
    ]
    raw_theme = [theme.strip() for theme in project.get("theme", "").split(",") if theme.strip()]

    country_codes = project.get("countrycode") or [""]
    alpha2 = country_codes[0]
    country = pycountry.countries.get(alpha_2=alpha2)
    # Falls back to the raw alpha-2 code if pycountry doesn't recognize it -
    # that value will simply never match a `Country.isoCode` (all 3 letters)
    # downstream, so the record is quarantined as unknown_country rather
    # than silently mis-mapped. Not expected in practice: every World Bank
    # member country has a valid ISO 3166-1 alpha-2 code.
    country_iso = country.alpha_3 if country is not None else alpha2

    return {
        "source": "world_bank",
        "project_id": project["id"],
        "country_iso": country_iso,
        "year": int(approval_date[:4]),
        "amount_usd": int(total_amount),
        "funding_type": "multilateral",
        "raw_sectors": raw_sectors,
        "raw_theme": raw_theme,
        "board_approval_date": approval_date[:10],
        "collected_at": dt.datetime.now(dt.timezone.utc).isoformat(),
    }


def collect_and_publish(country_isos: list[str], producer) -> int:
    """Fetches climate-themed World Bank projects for every country in
    `country_isos` and publishes each parseable one to `nev.funding.raw`
    via `producer` (a `kafka.KafkaProducer`, e.g. from
    `pipeline.common.kafka_client.make_producer()`). Returns the number of
    messages actually published.
    """
    published = 0
    for country_iso in country_isos:
        for raw_project in fetch_projects_for_country(country_iso):
            payload = parse_project(raw_project)
            if payload is None:
                continue
            producer.send("nev.funding.raw", payload)
            published += 1
    producer.flush()
    return published
```

Save as `pipeline/collectors/world_bank.py`.

- [ ] **Step 4: Run to verify it passes**

```bash
docker compose run --rm pipeline python -m pytest pipeline/tests/test_world_bank_collector.py -v
```
Expected: PASS (4 tests).

- [ ] **Step 5: Write the live smoke test**

```python
"""Live smoke test against the real World Bank API - not mocked. Kept
separate from test_world_bank_collector.py so it can be skipped in an
environment without outbound network access without failing the rest of
the suite; run explicitly with `-m live`.
"""
import pytest

from pipeline.collectors.world_bank import fetch_projects_for_country, parse_project


@pytest.mark.live
def test_senegal_returns_at_least_one_parseable_project():
    projects = fetch_projects_for_country("SN")
    parsed = [parse_project(project) for project in projects]
    parsed = [item for item in parsed if item is not None]

    assert len(parsed) > 0
    first = parsed[0]
    assert first["country_iso"] == "SEN"  # converted from the API's alpha-2 "SN"
    assert first["funding_type"] == "multilateral"
    assert first["amount_usd"] > 0
```

Save as `pipeline/tests/test_world_bank_collector_live.py`.

- [ ] **Step 6: Register the `live` marker and run it**

Create `pipeline/pytest.ini`:

```ini
[pytest]
markers =
    live: hits a real external API - requires network access
```

Run:
```bash
docker compose run --rm pipeline python -m pytest pipeline/tests/test_world_bank_collector_live.py -v -m live
```
Expected: PASS (1 test) — this proves the real API contract still matches what Task 3's
implementation assumes, not just the mocked fixtures.

- [ ] **Step 7: Commit**

```bash
git add pipeline/collectors/world_bank.py pipeline/tests/test_world_bank_collector.py pipeline/tests/test_world_bank_collector_live.py pipeline/pytest.ini
git commit -m "feat(b1.1): add World Bank collector"
```

---

## Task 7: Funding validator processor

**Files:**
- Create: `pipeline/processors/funding_validator.py`
- Test: `pipeline/tests/test_funding_validator.py`
- Modify: `docker-compose.yml` (add the always-on `funding-validator` service, replacing Task 4's `profiles`-gated `pipeline` service definition — see Step 6)

**Interfaces:**
- Consumes: `map_to_nev_sector` from Task 5, `get_connection`/`make_consumer`/`make_producer` from Task 4, the `uniq_funding_dedup_key` constraint from Task 3.
- Produces: `process_message(cursor, message: dict) -> tuple[bool, str | None]`. Consumed by this task's own `run()` entry point and by Task 9's end-to-end verification.

- [ ] **Step 1: Write the failing integration tests**

These require the demo fixtures loaded (Senegal + the 5 sectors must exist) — run
`docker compose exec backend php bin/console doctrine:fixtures:load --no-interaction` first if
not already done.

```python
"""Integration tests for the funding-validator processor's DB logic -
runs against the real TimescaleDB service (same instance the Symfony
backend uses), wrapped in a transaction rolled back in teardown so the
suite stays re-runnable, matching the pattern already established in
backend/tests/Integration/ for the PHP side. Requires the demo fixtures
loaded (Senegal + the 5 sectors must exist).
"""
import os
from decimal import Decimal

import psycopg2
import pytest

from pipeline.processors.funding_validator import process_message


@pytest.fixture()
def db_cursor():
    connection = psycopg2.connect(os.environ["PIPELINE_DATABASE_URL"])
    connection.autocommit = False
    cursor = connection.cursor()
    yield cursor
    connection.rollback()
    cursor.close()
    connection.close()


def _funding_row(cursor, source_id, country_id, sector_id, year, funding_type):
    cursor.execute(
        """
        SELECT amount, is_current FROM funding
        WHERE source_id = %s AND country_id = %s AND sector_id = %s
          AND year = %s AND funding_type = %s AND is_current = true
        """,
        (source_id, country_id, sector_id, year, funding_type),
    )
    return cursor.fetchone()


def _sample_message(amount_usd: int) -> dict:
    return {
        "source": "world_bank",
        "project_id": "P-TEST",
        "country_iso": "SEN",
        "year": 2026,
        "amount_usd": amount_usd,
        "funding_type": "multilateral",
        "raw_sectors": ["Energy Generation - Solar"],
        "raw_theme": [],
        "board_approval_date": "2026-01-15",
        "collected_at": "2026-08-26T00:00:00Z",
    }


def test_first_message_inserts_a_new_funding_row(db_cursor):
    accepted, reason = process_message(db_cursor, _sample_message(1_000_000))

    assert accepted is True
    assert reason is None

    db_cursor.execute("SELECT id FROM source WHERE name = 'World Bank Data API'")
    source_id = db_cursor.fetchone()[0]
    db_cursor.execute("SELECT id FROM country WHERE iso_code = 'SEN'")
    country_id = db_cursor.fetchone()[0]
    db_cursor.execute("SELECT id FROM sector WHERE name = 'Renewable Energy'")
    sector_id = db_cursor.fetchone()[0]

    row = _funding_row(db_cursor, source_id, country_id, sector_id, 2026, "multilateral")
    assert row == (Decimal("1000000.00"), True)


def test_second_message_same_key_sums_and_historizes(db_cursor):
    process_message(db_cursor, _sample_message(1_000_000))
    process_message(db_cursor, _sample_message(500_000))

    db_cursor.execute("SELECT id FROM source WHERE name = 'World Bank Data API'")
    source_id = db_cursor.fetchone()[0]
    db_cursor.execute("SELECT id FROM country WHERE iso_code = 'SEN'")
    country_id = db_cursor.fetchone()[0]
    db_cursor.execute("SELECT id FROM sector WHERE name = 'Renewable Energy'")
    sector_id = db_cursor.fetchone()[0]

    current_row = _funding_row(db_cursor, source_id, country_id, sector_id, 2026, "multilateral")
    assert current_row == (Decimal("1500000.00"), True)

    db_cursor.execute(
        """
        SELECT count(*) FROM funding
        WHERE source_id = %s AND country_id = %s AND sector_id = %s
          AND year = %s AND funding_type = %s AND is_current = false
        """,
        (source_id, country_id, sector_id, 2026, "multilateral"),
    )
    assert db_cursor.fetchone()[0] == 1


def test_unclassifiable_sector_is_rejected_without_writing(db_cursor):
    message = _sample_message(1_000_000)
    message["raw_sectors"] = ["Health"]
    message["raw_theme"] = ["Social Protection"]

    accepted, reason = process_message(db_cursor, message)

    assert accepted is False
    assert reason == "unclassifiable_sector"
```

Save as `pipeline/tests/test_funding_validator.py`. Note: the fixture data's Senegal row uses
ISO code `SEN` (3-letter, per `backend/src/DataFixtures/CountryFixtures.php` from A1.6) — check
that file if any test fails on a country lookup, rather than assuming 2-letter codes.

- [ ] **Step 2: Run to verify it fails**

```bash
docker compose run --rm pipeline python -m pytest pipeline/tests/test_funding_validator.py -v
```
Expected: `ModuleNotFoundError: No module named 'pipeline.processors.funding_validator'`.

- [ ] **Step 3: Write the implementation**

```python
"""Consumes `nev.funding.raw`, applies the B1.1 sector-mapping rule, and
writes to the `funding` table directly (bypassing the Symfony backend
entirely - see architecture spec decision 3). Publishes each record to
`nev.funding.valides` or `nev.funding.rejets` depending on outcome.

Long-running service - see the `funding-validator` entry in
docker-compose.yml.
"""
from __future__ import annotations

from decimal import Decimal
from typing import Any

from pipeline.common.db import get_connection
from pipeline.common.kafka_client import make_consumer, make_producer
from pipeline.processors.sector_mapping import map_to_nev_sector

WORLD_BANK_SOURCE_NAME = "World Bank Data API"  # matches backend/src/DataFixtures/SourceFixtures.php - reuses that row instead of creating a duplicate


def ensure_world_bank_source(cursor) -> int:
    """Idempotently ensures the `World Bank` row exists in `source`, and
    returns its id.
    """
    cursor.execute(
        """
        INSERT INTO source (name, type, reliability)
        VALUES (%s, 'official_api', 'high')
        ON CONFLICT (name) DO NOTHING
        """,
        (WORLD_BANK_SOURCE_NAME,),
    )
    cursor.execute("SELECT id FROM source WHERE name = %s", (WORLD_BANK_SOURCE_NAME,))
    return cursor.fetchone()[0]


def lookup_country_id(cursor, country_iso: str) -> int | None:
    cursor.execute("SELECT id FROM country WHERE iso_code = %s", (country_iso,))
    row = cursor.fetchone()
    return row[0] if row else None


def lookup_sector_id(cursor, sector_name: str) -> int | None:
    cursor.execute("SELECT id FROM sector WHERE name = %s", (sector_name,))
    row = cursor.fetchone()
    return row[0] if row else None


def upsert_funding(cursor, *, source_id: int, country_id: int, sector_id: int, year: int,
                    funding_type: str, amount: Decimal, collection_date: str) -> None:
    """Sums `amount` into the current row for this dedup key if one
    exists (closing it out and inserting a new historized version), or
    inserts a fresh row otherwise - see B1.1 spec decision 6.
    """
    cursor.execute(
        """
        SELECT id, amount FROM funding
        WHERE source_id = %s AND country_id = %s AND sector_id = %s
          AND year = %s AND funding_type = %s AND is_current = true
        """,
        (source_id, country_id, sector_id, year, funding_type),
    )
    existing = cursor.fetchone()

    if existing is not None:
        existing_id, existing_amount = existing
        new_amount = existing_amount + amount
        cursor.execute(
            "UPDATE funding SET is_current = false, valid_to = now() WHERE id = %s",
            (existing_id,),
        )
    else:
        new_amount = amount

    cursor.execute(
        """
        INSERT INTO funding (
            country_id, sector_id, year, amount, funding_type, source_id,
            collection_date, validation_status, valid_from, is_current,
            created_at, updated_at
        ) VALUES (
            %s, %s, %s, %s, %s, %s,
            %s, 'validated', now(), true,
            now(), now()
        )
        """,
        (country_id, sector_id, year, new_amount, funding_type, source_id, collection_date),
    )


def process_message(cursor, message: dict[str, Any]) -> tuple[bool, str | None]:
    """Applies sector mapping and, on success, upserts. Returns
    (accepted, reason) - `reason` is None when accepted, or a short
    machine-readable string explaining rejection when not.
    """
    nev_sector = map_to_nev_sector(message["raw_sectors"], message["raw_theme"])
    if nev_sector is None:
        return False, "unclassifiable_sector"

    country_id = lookup_country_id(cursor, message["country_iso"])
    if country_id is None:
        return False, "unknown_country"

    sector_id = lookup_sector_id(cursor, nev_sector)
    if sector_id is None:
        return False, "unknown_sector"

    source_id = ensure_world_bank_source(cursor)

    upsert_funding(
        cursor,
        source_id=source_id,
        country_id=country_id,
        sector_id=sector_id,
        year=message["year"],
        funding_type=message["funding_type"],
        amount=Decimal(message["amount_usd"]),
        collection_date=message["collected_at"][:10],
    )
    return True, None


def run() -> None:
    consumer = make_consumer("nev.funding.raw", group_id="funding-validator")
    producer = make_producer()

    for kafka_message in consumer:
        message = kafka_message.value
        connection = get_connection()
        try:
            with connection:
                with connection.cursor() as cursor:
                    accepted, reason = process_message(cursor, message)
        finally:
            connection.close()

        if accepted:
            producer.send("nev.funding.valides", message)
        else:
            producer.send("nev.funding.rejets", {**message, "rejection_reason": reason})

    producer.flush()


if __name__ == "__main__":
    run()
```

Save as `pipeline/processors/funding_validator.py`.

- [ ] **Step 4: Run to verify it passes**

```bash
docker compose run --rm pipeline python -m pytest pipeline/tests/test_funding_validator.py -v
```
Expected: PASS (3 tests). If `test_first_message_inserts_a_new_funding_row` fails on the
`country`/`sector` lookups, run
`docker compose exec backend php bin/console doctrine:fixtures:load --no-interaction` first
(the demo dataset must exist) and re-run.

- [ ] **Step 5: Run the full pipeline test suite together**

```bash
docker compose run --rm pipeline python -m pytest pipeline/tests/ -v -m "not live"
```
Expected: PASS (15 tests: 8 sector-mapping + 4 collector-offline + 3 validator; the `live`
marker exclusion skips the World Bank network smoke test here, already verified separately in
Task 6).

- [ ] **Step 6: Turn the `pipeline` service into the always-on `funding-validator` service**

In `docker-compose.yml`, replace the `pipeline` service block added in Task 4 with:

```yaml
  funding-validator:
    build:
      context: .
      dockerfile: pipeline/Dockerfile
    container_name: nev-climate-data-funding-validator
    restart: unless-stopped
    environment:
      PIPELINE_DATABASE_URL: postgresql://${POSTGRES_USER}:${POSTGRES_PASSWORD}@database:5432/${POSTGRES_DB}
      KAFKA_BOOTSTRAP_SERVERS: kafka:9092
    command: python -m pipeline.processors.funding_validator
    depends_on:
      - database
      - kafka
    networks:
      - nev-network
```

This drops the `profiles: [pipeline-tools]` gate — `funding-validator` now starts
automatically with `docker compose up -d` like every other core service, since it's a
permanent consumer, not a one-off tooling container. One-off commands from earlier tasks
(`docker compose run --rm pipeline ...`) become `docker compose run --rm funding-validator ...`
from this point on — same image, just renamed to reflect its real, permanent role.

- [ ] **Step 7: Start it and verify it's running**

```bash
docker compose up -d funding-validator
docker compose ps funding-validator
```
Expected: `Up`.

```bash
docker compose logs funding-validator --tail 20
```
Expected: no crash/traceback (an empty/idle consumer loop produces little to no output, which
is normal — it's just waiting for messages).

- [ ] **Step 8: Commit**

```bash
git add pipeline/processors/funding_validator.py pipeline/tests/test_funding_validator.py docker-compose.yml
git commit -m "feat(b1.1): add funding-validator processor service"
```

---

## Task 8: Airflow DAG

**Files:**
- Create: `pipeline/dags/collecte_worldbank.py`

**Interfaces:**
- Consumes: `collect_and_publish` from Task 6, `get_connection` from Task 4, `make_producer` from Task 4.
- Produces: nothing consumed by a later task in this plan — Task 9 verifies this DAG end-to-end.

- [ ] **Step 1: Write the DAG**

```python
"""Airflow DAG: quarterly collection of World Bank climate-themed project
financing for every country NEV tracks - see the B1.1 spec, decision 1
(the country list comes from NEV's own `country` table, not a
hard-coded list) and decision 8 (quarterly schedule).
"""
from datetime import datetime, timedelta

from airflow import DAG
from airflow.operators.python import PythonOperator

from pipeline.collectors.world_bank import collect_and_publish
from pipeline.common.db import get_connection
from pipeline.common.kafka_client import make_producer

default_args = {
    "owner": "nev-climate-data",
    "retries": 3,
    "retry_delay": timedelta(minutes=5),
}


def _collect(**context) -> None:
    connection = get_connection()
    try:
        with connection.cursor() as cursor:
            cursor.execute("SELECT iso_code FROM country ORDER BY iso_code")
            country_isos = [row[0] for row in cursor.fetchall()]
    finally:
        connection.close()

    producer = make_producer()
    published = collect_and_publish(country_isos, producer)
    context["ti"].xcom_push(key="published_count", value=published)


with DAG(
    dag_id="collecte_worldbank",
    default_args=default_args,
    schedule_interval="0 3 1 1,4,7,10 *",  # 1er jour de chaque trimestre, 03h00
    start_date=datetime(2026, 1, 1),
    catchup=False,
    tags=["b1.1", "collecte", "world-bank"],
) as dag:
    collecter = PythonOperator(
        task_id="collecter_financements_banque_mondiale",
        python_callable=_collect,
    )
```

Save as `pipeline/dags/collecte_worldbank.py`. Recall from Task 1 that `./pipeline` is
volume-mounted to `/opt/airflow/pipeline` inside the `airflow` container, and
`_PIP_ADDITIONAL_REQUIREMENTS` already installs `kafka-python`/`psycopg2-binary`/`requests`
there — this file needs no separate packaging step.

- [ ] **Step 2: Point Airflow at the DAGs folder, and put its parent on PYTHONPATH**

Airflow's default `AIRFLOW__CORE__DAGS_FOLDER` is `/opt/airflow/dags`, but this plan mounts
the whole `pipeline/` tree at `/opt/airflow/pipeline`. This was anticipated and already added
to the `airflow` service's environment during Task 1 (`AIRFLOW__CORE__DAGS_FOLDER:
/opt/airflow/pipeline/dags`) — confirm it's present:

```bash
docker compose exec airflow printenv AIRFLOW__CORE__DAGS_FOLDER
```
Expected: `/opt/airflow/pipeline/dags`.

**Real bug found while executing this step**: that alone is not enough. Airflow puts
`DAGS_FOLDER` itself on `sys.path`, not its parent — so `collecte_worldbank.py`'s `from
pipeline.collectors.world_bank import ...` (a package one level *above* `DAGS_FOLDER`) fails
with `ModuleNotFoundError: No module named 'pipeline'`, confirmed live via `airflow dags
list-import-errors`. Add `PYTHONPATH: /opt/airflow` to the `airflow` service's environment in
`docker-compose.yml` (that's where `./pipeline` is mounted, so this makes the `pipeline`
package importable — the same way it already is inside the `funding-validator`/pipeline-tools
image via its own `ENV PYTHONPATH=/app` in `pipeline/Dockerfile`). Then recreate the container:

```bash
docker compose up -d --force-recreate airflow
```
Wait for it to finish reinstalling `_PIP_ADDITIONAL_REQUIREMENTS` and become healthy again
(standalone mode re-runs the pip install and re-initializes on every start) before continuing.

- [ ] **Step 3: Verify the DAG is recognized**

```bash
docker compose exec airflow airflow dags list
```
Expected: `collecte_worldbank` appears in the list, with no import errors.

```bash
docker compose exec airflow airflow dags list-import-errors
```
Expected: empty output (no import errors for this or any other DAG).

- [ ] **Step 4: Commit**

```bash
git add pipeline/dags/collecte_worldbank.py docker-compose.yml
git commit -m "feat(b1.1): add quarterly World Bank collection DAG"
```

---

## Task 9: End-to-end verification

**Files:**
- None (verification-only task).

**Interfaces:**
- Consumes: the entire pipeline built in Tasks 1-8.
- Produces: nothing — this is the plan's acceptance check.

- [ ] **Step 1: Trigger the DAG manually**

```bash
docker compose exec airflow airflow dags trigger collecte_worldbank
```

Wait for it to finish (a minute or two — 54 countries, each potentially several pages of
projects), then check:

```bash
docker compose exec airflow airflow dags list-runs -d collecte_worldbank
```
Expected: the most recent run shows state `success`.

If it fails, check the task log:
```bash
docker compose exec airflow airflow tasks logs collecte_worldbank collecter_financements_banque_mondiale <execution_date_from_the_list-runs_output>
```

- [ ] **Step 2: Verify real data landed in `funding`**

```bash
docker compose exec backend php bin/console dbal:run-sql "SELECT count(*) FROM funding WHERE source_id = (SELECT id FROM source WHERE name = 'World Bank Data API')"
```
Expected: a count greater than 0.

```bash
docker compose exec backend php bin/console dbal:run-sql "SELECT country_id, sector_id, year, amount, funding_type, is_current FROM funding WHERE source_id = (SELECT id FROM source WHERE name = 'World Bank Data API') ORDER BY amount DESC LIMIT 5"
```
Expected: rows with plausible amounts, `funding_type = multilateral`, `is_current = t`.

- [ ] **Step 3: Verify the historization/summing behavior for real**

```bash
docker compose exec airflow airflow dags trigger collecte_worldbank
```

Wait for it to complete again, then re-run the query from Step 2. Expected: for any
country/sector/year that had a match in both runs, `amount` has grown (summed), and a query
for `is_current = false` rows on that same key now returns at least one row (the prior
version, historized) — confirming decision 6 works against real, not just test, data.

```bash
docker compose exec backend php bin/console dbal:run-sql "SELECT count(*) FROM funding WHERE source_id = (SELECT id FROM source WHERE name = 'World Bank Data API') AND is_current = false"
```
Expected: a count greater than 0 (assuming the two DAG runs happened close enough together
that the World Bank API returned the same projects both times, which is the normal case for
two runs minutes apart).

- [ ] **Step 4: Verify quarantine is reachable**

```bash
docker compose exec kafka kafka-console-consumer --bootstrap-server localhost:9092 --topic nev.funding.rejets --from-beginning --max-messages 1 --timeout-ms 5000
```
Expected: either a JSON message with a `rejection_reason` field (if any project genuinely
failed classification during the real runs above — plausible, since many World Bank projects
touch unrelated sectors like Health or Public Administration even under the Climate change
theme filter), or a timeout with no message if every real project happened to classify
successfully. Either outcome is acceptable — this step confirms the topic is reachable and,
if populated, that rejected messages carry a real reason.

- [ ] **Step 5: No commit** — this task only ran verification commands; nothing in the repo changed.

---

## Task 10: Documentation

**Files:**
- Modify: `README.md`

**Interfaces:**
- Consumes: nothing. Produces: nothing consumed elsewhere — final task of this plan.

- [ ] **Step 1: Add a "Pipeline (Volet B)" section**

Insert a new section after "## État d'avancement" (or wherever the current README places its
last major section — check the file first, since Phase A2 work has been adding sections since
this plan was written):

```markdown
## Pipeline (Volet B)

Premier connecteur du Volet B (données réelles) : collecte trimestrielle des financements
climat de la Banque Mondiale. Architecture complète et décisions de conception :
[`docs/superpowers/specs/2026-08-26-volet-b-pipeline-architecture-design.md`](docs/superpowers/specs/2026-08-26-volet-b-pipeline-architecture-design.md)
(fondation partagée) et
[`docs/superpowers/specs/2026-08-26-b11-world-bank-connector-design.md`](docs/superpowers/specs/2026-08-26-b11-world-bank-connector-design.md)
(ce connecteur).

### Services

`docker compose up -d` démarre désormais aussi `zookeeper`, `kafka`, `postgres-airflow`,
`airflow`, `minio` et `funding-validator`, en plus des services existants.

- Interface Airflow : `http://localhost:8081` (identifiants générés au premier démarrage —
  voir `docker compose logs airflow | grep "Login with username"`)
- Console MinIO : `http://localhost:9001`

### Déclencher la collecte manuellement

```bash
docker compose exec airflow airflow dags trigger collecte_worldbank
```

### Lancer les tests du pipeline

```bash
docker compose run --rm funding-validator python -m pytest pipeline/tests/ -v -m "not live"
```

Le test marqué `live` (`pipeline/tests/test_world_bank_collector_live.py`) appelle la vraie
API Banque Mondiale — à exécuter séparément (`-m live`) plutôt qu'en routine, pour ne pas
rendre la suite dépendante du réseau.
```

- [ ] **Step 2: Add a "Points d'attention" entry**

Append as the next numbered point in that section:

```markdown
11. **Le pipeline Python (`pipeline/`) écrit directement en base, sans passer par l'API Symfony.** Toute évolution du schéma `funding` (colonnes, contraintes) impacte donc potentiellement deux codebases séparées (`backend/src/Entity/Funding.php` ET `pipeline/processors/funding_validator.py`) qui doivent rester manuellement synchronisées — rien ne le vérifie automatiquement. La contrainte unique `uniq_funding_dedup_key` (ajoutée en B1.1) est un exemple : elle existe à la fois dans l'attribut Doctrine et dans la logique `ON CONFLICT` du processor Python.
```

- [ ] **Step 3: Commit**

```bash
git add README.md
git commit -m "docs(b1.1): document the pipeline services and World Bank connector"
```

---

## Final check before considering B1.1 done

- [ ] `docker compose run --rm funding-validator python -m pytest pipeline/tests/ -v -m "not live"` — all green.
- [ ] `docker compose run --rm funding-validator python -m pytest pipeline/tests/test_world_bank_collector_live.py -v -m live` — green (real API still matches assumptions).
- [ ] `docker compose exec backend php bin/phpunit` (full existing suite) — still green, confirming Task 3's schema change caused no regression.
- [ ] Two manual DAG triggers (Task 9) produced real `Funding` rows with `source = World Bank`, `funding_type = multilateral`, correct summing/historization on the second run.
- [ ] Cross-check against the plan spreadsheet: B1.1's "Livrable attendu" was *"DAG Airflow trimestriel publiant vers le topic Kafka dédié"* — confirmed: `collecte_worldbank` DAG exists, scheduled quarterly, publishes to `nev.funding.raw`.
