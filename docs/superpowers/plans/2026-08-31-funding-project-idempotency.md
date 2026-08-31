# Correction du double comptage Funding - Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Corriger un bug de production réel et actif : chaque re-publication d'un projet déjà
compté (chaque DAG re-télécharge son portefeuille entier à chaque run) fait gonfler indéfiniment
les totaux `Funding` réels, et corriger les données déjà corrompues en base.

**Architecture:** Une nouvelle table `funding_project_contribution` (une ligne par
`source + project_id + country`) retient la dernière contribution connue de chaque projet ;
`funding_validator.py` calcule un delta réel avant de toucher `funding` au lieu de sommer chaque
message aveuglément.

**Tech Stack:** PHP 8.4/Symfony (Doctrine ORM/migrations) pour le schéma, Python 3.12/psycopg2
pour la logique de validation (inchangé).

## Global Constraints

- Clé unique de `funding_project_contribution` : `(source_id, project_id, country_id)`.
- `upsert_funding()` prend désormais un paramètre `delta` (peut être négatif) au lieu de `amount` ;
  un `delta` de zéro n'écrit rien dans `funding` (ni INSERT, ni UPDATE, ni nouvelle version
  historisée).
- `apply_project_contribution()` route les 4 sources `Funding` : `world_bank`, `gcf`, `afdb`,
  `opec_fund_pdf`. Pour `opec_fund_pdf` uniquement, le `project_id` transmis à
  `apply_project_contribution()` doit inclure `climate_dimension` (ex.
  `"...:Test Project:adaptation"`) - un même `message["project_id"]` source peut légitimement
  produire deux payloads (adaptation + mitigation, décision 7 de la spec B1.5) qui doivent être
  suivis comme deux contributions distinctes, pas une seule qui écraserait l'autre.
- Aucune modification de `pipeline/processors/emission_validator.py` (PNUE, hors périmètre - voir
  la spec).
- **Aucune suppression de données réelles n'a lieu avant la Tâche 3, et la Tâche 3 ne s'exécute
  qu'après confirmation explicite de Serge**, même si ce plan dans son ensemble est déjà approuvé.

---

### Tâche 1 : Entité `FundingProjectContribution` (Doctrine + migration)

**Files:**
- Create: `backend/src/Entity/FundingProjectContribution.php`
- Create: `backend/src/Repository/FundingProjectContributionRepository.php`
- Create: `backend/migrations/VersionYYYYMMDDHHMMSS.php` (nom exact généré par Doctrine)

**Interfaces:**
- Produces: table SQL `funding_project_contribution(id, source_id, project_id, country_id,
  sector_id, year, funding_type, amount, updated_at)`, contrainte unique
  `uniq_funding_project_contribution_key` sur `(source_id, project_id, country_id)` - consommée
  directement en SQL brut par `pipeline/processors/funding_validator.py` (Tâche 2), pas par
  Doctrine lui-même (aucun accès en lecture/écriture par le PHP - même précédent que
  `ProcessedDocument`, B1.5).

- [ ] **Step 1: Créer l'entité**

Create `backend/src/Entity/FundingProjectContribution.php`:

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\FundingProjectContributionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Tracks the last-known contribution of a single source project to the
 * `funding` table's aggregated totals - one row per (source, project,
 * country). Exists to fix a real production bug: every collection DAG
 * (World Bank, GCF, AfDB) re-publishes its entire current portfolio on
 * every run, not just new/changed projects, and funding_validator.py used
 * to blindly sum every message's amount into the current total - so a
 * project already counted in a previous run got counted again on every
 * subsequent run (verified live: Senegal/Agriculture/1989 summed 8 times
 * in a row with the exact same increment). See
 * docs/superpowers/specs/2026-08-31-funding-project-idempotency-design.md.
 * No Symfony-side consumer reads this table - same rationale as
 * ProcessedDocument (B1.5): it exists so the pipeline's schema evolves
 * through the same Doctrine migration mechanism as every other table.
 */
#[ORM\Table(name: 'funding_project_contribution')]
#[ORM\UniqueConstraint(
    name: 'uniq_funding_project_contribution_key',
    columns: ['source_id', 'project_id', 'country_id'],
)]
#[ORM\Entity(repositoryClass: FundingProjectContributionRepository::class)]
class FundingProjectContribution
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Source::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Source $source;

    #[ORM\Column(length: 255)]
    private string $projectId;

    #[ORM\ManyToOne(targetEntity: Country::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Country $country;

    #[ORM\ManyToOne(targetEntity: Sector::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Sector $sector;

    #[ORM\Column]
    private int $year;

    #[ORM\Column(length: 20)]
    private string $fundingType;

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2)]
    private string $amount;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        Source $source,
        string $projectId,
        Country $country,
        Sector $sector,
        int $year,
        string $fundingType,
        string $amount,
    ) {
        $this->source = $source;
        $this->projectId = $projectId;
        $this->country = $country;
        $this->sector = $sector;
        $this->year = $year;
        $this->fundingType = $fundingType;
        $this->amount = $amount;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSource(): Source
    {
        return $this->source;
    }

    public function getProjectId(): string
    {
        return $this->projectId;
    }

    public function getCountry(): Country
    {
        return $this->country;
    }

    public function getSector(): Sector
    {
        return $this->sector;
    }

    public function getYear(): int
    {
        return $this->year;
    }

    public function getFundingType(): string
    {
        return $this->fundingType;
    }

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
```

- [ ] **Step 2: Créer le repository**

Create `backend/src/Repository/FundingProjectContributionRepository.php`:

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\FundingProjectContribution;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FundingProjectContribution>
 */
class FundingProjectContributionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FundingProjectContribution::class);
    }
}
```

- [ ] **Step 3: Générer la migration**

Run: `docker compose exec backend php bin/console doctrine:migrations:diff`

Le fichier généré doit créer la table `funding_project_contribution` avec ses 5 clés étrangères
(`source_id`, `country_id`, `sector_id` en INT REFERENCES) et l'index unique sur
`(source_id, project_id, country_id)`. Comme pour `ProcessedDocument` (B1.5), le diff auto-généré
va probablement aussi proposer de DROP/CREATE l'index partiel de `funding`
(`uniq_funding_dedup_key_current`) et/ou des schémas internes TimescaleDB - **retirer ces lignes
manuellement** du fichier généré (faux positifs connus et documentés, README point 3), en gardant
uniquement la vraie création de `funding_project_contribution`. Le fichier final doit ressembler
à :

```php
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class VersionYYYYMMDDHHMMSS extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create funding_project_contribution (fixes the real Funding double-counting bug)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE funding_project_contribution (id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL, source_id INT NOT NULL, project_id VARCHAR(255) NOT NULL, country_id INT NOT NULL, sector_id INT NOT NULL, year INT NOT NULL, funding_type VARCHAR(20) NOT NULL, amount NUMERIC(15, 2) NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_funding_project_contribution_key ON funding_project_contribution (source_id, project_id, country_id)');
        $this->addSql('CREATE INDEX IDX_FUNDING_PROJECT_CONTRIBUTION_SOURCE ON funding_project_contribution (source_id)');
        $this->addSql('CREATE INDEX IDX_FUNDING_PROJECT_CONTRIBUTION_COUNTRY ON funding_project_contribution (country_id)');
        $this->addSql('CREATE INDEX IDX_FUNDING_PROJECT_CONTRIBUTION_SECTOR ON funding_project_contribution (sector_id)');
        $this->addSql('ALTER TABLE funding_project_contribution ADD CONSTRAINT FK_FUNDING_PROJECT_CONTRIBUTION_SOURCE FOREIGN KEY (source_id) REFERENCES source (id)');
        $this->addSql('ALTER TABLE funding_project_contribution ADD CONSTRAINT FK_FUNDING_PROJECT_CONTRIBUTION_COUNTRY FOREIGN KEY (country_id) REFERENCES country (id)');
        $this->addSql('ALTER TABLE funding_project_contribution ADD CONSTRAINT FK_FUNDING_PROJECT_CONTRIBUTION_SECTOR FOREIGN KEY (sector_id) REFERENCES sector (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE funding_project_contribution');
    }
}
```

Ajuster les noms de contraintes générées (Doctrine les nomme automatiquement, potentiellement
différemment de l'exemple ci-dessus) pour qu'ils correspondent à ce que `doctrine:migrations:diff`
a réellement produit, une fois les faux positifs retirés.

- [ ] **Step 4: Appliquer la migration**

Run: `docker compose exec backend php bin/console doctrine:migrations:migrate --no-interaction`
Expected: succès, aucune erreur.

- [ ] **Step 5: Vérifier la structure réelle**

Run: `docker compose exec database psql -U nev_admin -d nev_climate_data -c "\d funding_project_contribution"`
Expected: la table existe avec les 9 colonnes attendues et la contrainte unique sur
`(source_id, project_id, country_id)`.

- [ ] **Step 6: Commit**

```bash
git add backend/src/Entity/FundingProjectContribution.php backend/src/Repository/FundingProjectContributionRepository.php backend/migrations/
git commit -m "feat: add funding_project_contribution table (fixes real double-counting bug)"
git pull --rebase
git push
```

---

### Tâche 2 : Idempotence par projet dans `funding_validator.py`

**Files:**
- Modify: `pipeline/processors/funding_validator.py`
- Modify: `pipeline/tests/test_funding_validator.py`

**Interfaces:**
- Consumes: table `funding_project_contribution` de la Tâche 1.
- Produces: `upsert_funding(cursor, *, source_id, country_id, sector_id, year, funding_type,
  delta: Decimal, collection_date, original_amount=None, original_currency=None,
  exchange_rate=None) -> None` (paramètre renommé `amount` → `delta`, garde `delta == 0`) ;
  `apply_project_contribution(cursor, *, source_id, project_id: str, country_id, sector_id, year,
  funding_type, amount: Decimal, collection_date, original_amount=None, original_currency=None,
  exchange_rate=None) -> None` (nouvelle fonction, appelée par `process_message` à la place de
  `upsert_funding` directement).

- [ ] **Step 1: Généraliser `upsert_funding` en delta**

In `pipeline/processors/funding_validator.py`, remplacer la fonction `upsert_funding` :

```python
def upsert_funding(cursor, *, source_id: int, country_id: int, sector_id: int, year: int,
                    funding_type: str, delta: Decimal, collection_date: str,
                    original_amount: Decimal | None = None,
                    original_currency: str | None = None,
                    exchange_rate: Decimal | None = None) -> None:
    """Applies `delta` (can be negative - see apply_project_contribution's
    sector/year-change case) to the current row for this dedup key,
    historizing the previous version, or inserts a fresh row if none
    exists yet. A zero delta is a genuine no-op - see the 2026-08-31
    idempotency fix spec: it must never create a needless new historized
    version (that was the exact shape of the real double-counting bug).
    `original_amount`/`original_currency`/`exchange_rate` describe the
    latest contributing message's raw figures (not accumulated across
    historized versions, same treatment as `collection_date`) - only
    populated by connectors reporting in a non-pivot currency (B1.3's
    AfDB connector is the first; World Bank/GCF never pass them, so they
    stay NULL as before).
    """
    if delta == 0:
        return

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
        new_amount = existing_amount + delta
        cursor.execute(
            "UPDATE funding SET is_current = false, valid_to = now() WHERE id = %s",
            (existing_id,),
        )
    else:
        new_amount = delta

    cursor.execute(
        """
        INSERT INTO funding (
            country_id, sector_id, year, amount, funding_type, source_id,
            collection_date, validation_status, valid_from, is_current,
            original_amount, original_currency, exchange_rate,
            created_at, updated_at
        ) VALUES (
            %s, %s, %s, %s, %s, %s,
            %s, 'validated', now(), true,
            %s, %s, %s,
            now(), now()
        )
        """,
        (country_id, sector_id, year, new_amount, funding_type, source_id, collection_date,
         original_amount, original_currency, exchange_rate),
    )
```

- [ ] **Step 2: Ajouter `apply_project_contribution`**

Juste après `upsert_funding` dans le même fichier :

```python
def apply_project_contribution(cursor, *, source_id: int, project_id: str, country_id: int,
                                sector_id: int, year: int, funding_type: str, amount: Decimal,
                                collection_date: str,
                                original_amount: Decimal | None = None,
                                original_currency: str | None = None,
                                exchange_rate: Decimal | None = None) -> None:
    """Applies one project's real, current contribution to the `funding`
    aggregate for its dedup key - idempotently. Fixes a real production
    bug (2026-08-31): every collection DAG re-publishes its entire current
    portfolio on every run, not just new/changed projects, so summing each
    incoming message's amount blindly (the old upsert_funding contract)
    double-counted every project on every re-run. This function tracks
    each project's last-known contribution in `funding_project_contribution`
    and applies only the real delta: a project reported again with the
    exact same amount contributes nothing (delta 0 - the bug scenario); a
    project reported with a genuinely different amount contributes only
    the difference (a real revision, kept traceable in `funding`'s own
    SCD2 history); a project whose dedup key itself changed (e.g. a
    sector-mapping fix between two runs) is moved from its old key's
    aggregate to its new one rather than guessing which aggregate a raw
    delta belongs to.
    """
    cursor.execute(
        """
        SELECT sector_id, year, funding_type, amount FROM funding_project_contribution
        WHERE source_id = %s AND project_id = %s AND country_id = %s
        """,
        (source_id, project_id, country_id),
    )
    existing = cursor.fetchone()

    if existing is None:
        upsert_funding(
            cursor, source_id=source_id, country_id=country_id, sector_id=sector_id,
            year=year, funding_type=funding_type, delta=amount, collection_date=collection_date,
            original_amount=original_amount, original_currency=original_currency,
            exchange_rate=exchange_rate,
        )
        cursor.execute(
            """
            INSERT INTO funding_project_contribution
                (source_id, project_id, country_id, sector_id, year, funding_type, amount, updated_at)
            VALUES (%s, %s, %s, %s, %s, %s, %s, now())
            """,
            (source_id, project_id, country_id, sector_id, year, funding_type, amount),
        )
        return

    old_sector_id, old_year, old_funding_type, old_amount = existing
    same_key = (old_sector_id == sector_id and old_year == year and old_funding_type == funding_type)

    if same_key:
        delta = amount - old_amount
        upsert_funding(
            cursor, source_id=source_id, country_id=country_id, sector_id=sector_id,
            year=year, funding_type=funding_type, delta=delta, collection_date=collection_date,
            original_amount=original_amount, original_currency=original_currency,
            exchange_rate=exchange_rate,
        )
    else:
        upsert_funding(
            cursor, source_id=source_id, country_id=country_id, sector_id=old_sector_id,
            year=old_year, funding_type=old_funding_type, delta=-old_amount,
            collection_date=collection_date,
        )
        upsert_funding(
            cursor, source_id=source_id, country_id=country_id, sector_id=sector_id,
            year=year, funding_type=funding_type, delta=amount, collection_date=collection_date,
            original_amount=original_amount, original_currency=original_currency,
            exchange_rate=exchange_rate,
        )

    cursor.execute(
        """
        UPDATE funding_project_contribution
        SET sector_id = %s, year = %s, funding_type = %s, amount = %s, updated_at = now()
        WHERE source_id = %s AND project_id = %s AND country_id = %s
        """,
        (sector_id, year, funding_type, amount, source_id, project_id, country_id),
    )
```

- [ ] **Step 3: Router `process_message` vers `apply_project_contribution`**

In `pipeline/processors/funding_validator.py`, remplacer le corps de `process_message` :

```python
def process_message(cursor, message: dict[str, Any]) -> tuple[bool, str | None]:
    """Applies source-specific sector mapping and, on success, applies the
    project's contribution idempotently. Returns (accepted, reason) -
    `reason` is None when accepted, or a short machine-readable string
    explaining rejection when not.
    """
    source = message["source"]
    if source == "world_bank":
        nev_sector = map_to_nev_sector(message["raw_sectors"], message["raw_theme"])
        ensure_source = ensure_world_bank_source
        contribution_project_id = message["project_id"]
    elif source == "gcf":
        nev_sector = map_gcf_sector(message["raw_sector_codes"], message["raw_sector_percentages"])
        ensure_source = ensure_gcf_source
        contribution_project_id = message["project_id"]
    elif source == "afdb":
        nev_sector = map_afdb_sector(message["raw_sector_codes"])
        ensure_source = ensure_afdb_source
        contribution_project_id = message["project_id"]
    elif source == "opec_fund_pdf":
        if message["climate_dimension"] == "adaptation":
            nev_sector = "Adaptation"
        else:
            nev_sector = map_opec_sector(message["sector_label_raw"], message["project_name"])
        ensure_source = ensure_opec_fund_source
        # A single OPEC Fund table row can produce two payloads (adaptation +
        # mitigation, decision 7 of the B1.5 spec) sharing the same
        # message["project_id"] - the dimension must be folded in here so
        # each is tracked as its own contribution, not one overwriting the
        # other.
        contribution_project_id = f"{message['project_id']}:{message['climate_dimension']}"
    else:
        return False, "unknown_source"

    if nev_sector is None:
        return False, "unclassifiable_sector"

    country_id = lookup_country_id(cursor, message["country_iso"])
    if country_id is None:
        return False, "unknown_country"

    sector_id = lookup_sector_id(cursor, nev_sector)
    if sector_id is None:
        return False, "unknown_sector"

    source_id = ensure_source(cursor)

    # AfDB messages carry original_amount/original_currency/exchange_rate
    # (floats, from JSON) - convert via str() to avoid binary float
    # artifacts in the Decimal conversion (e.g. Decimal(1.370818) != 1.370818).
    # World Bank/GCF messages don't have these keys at all.
    apply_project_contribution(
        cursor,
        source_id=source_id,
        project_id=contribution_project_id,
        country_id=country_id,
        sector_id=sector_id,
        year=message["year"],
        funding_type=message["funding_type"],
        amount=Decimal(message["amount_usd"]),
        collection_date=message["collected_at"][:10],
        original_amount=Decimal(str(message["original_amount"])) if "original_amount" in message else None,
        original_currency=message.get("original_currency"),
        exchange_rate=Decimal(str(message["exchange_rate"])) if "exchange_rate" in message else None,
    )
    return True, None
```

- [ ] **Step 4: Corriger le test existant cassé par le changement de sémantique**

`test_second_message_same_key_sums_and_historizes` (dans `pipeline/tests/test_funding_validator.py`)
utilisait jusqu'ici le même `project_id` ("P-TEST") pour ses deux messages avec des montants
différents - avant ce correctif, n'importe quel montant se serait additionné aveuglément ; après,
un même `project_id` avec un montant différent est traité comme une **révision** (le nouveau
montant remplace l'ancien, il ne s'y ajoute pas). Pour continuer à vérifier que deux **projets
réellement différents** contribuant à la même clé de dédoublonnage s'additionnent bien, ce test
doit utiliser deux `project_id` distincts.

D'abord, ajouter un paramètre `project_id` optionnel à `_sample_message` (ligne 40) :

```python
def _sample_message(amount_usd: int, project_id: str = "P-TEST") -> dict:
    return {
        "source": "world_bank",
        "project_id": project_id,
        "country_iso": "SEN",
        "year": 2026,
        "amount_usd": amount_usd,
        "funding_type": "multilateral",
        "raw_sectors": ["Energy Generation - Solar"],
        "raw_theme": [],
        "board_approval_date": "2026-01-15",
        "collected_at": "2026-08-26T00:00:00Z",
    }
```

Puis renommer et corriger le test :

```python
def test_two_different_projects_same_dedup_key_sum_and_historize(db_cursor):
    process_message(db_cursor, _sample_message(1_000_000, project_id="P-TEST-1"))
    process_message(db_cursor, _sample_message(500_000, project_id="P-TEST-2"))

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
```

- [ ] **Step 5: Run test pour confirmer que ce test passe toujours (sous sa nouvelle forme)**

Run: `docker compose build funding-validator && docker compose run --rm funding-validator pytest pipeline/tests/test_funding_validator.py::test_two_different_projects_same_dedup_key_sum_and_historize -v`
Expected: PASS.

- [ ] **Step 6: Ajouter le test de régression explicite reproduisant le bug réel**

Ajouter à la fin de `pipeline/tests/test_funding_validator.py` :

```python
def test_republishing_the_same_project_eight_times_does_not_inflate_the_total(db_cursor):
    # Reproduces the real bug found live in production: World Bank's DAG
    # re-publishes its entire current portfolio on every run (not a delta),
    # and the old upsert_funding summed every message blindly - Senegal/
    # Agriculture/1989 was summed 8 times in a row with the exact same
    # increment (16.1M -> 128.8M) before this fix. Re-publishing the exact
    # same project 8 times must now produce exactly one project's worth of
    # funding, not eight.
    message = _sample_message(2_012_500, project_id="P-BUG-REPRO")
    for _ in range(8):
        process_message(db_cursor, message)

    db_cursor.execute("SELECT id FROM source WHERE name = 'World Bank Data API'")
    source_id = db_cursor.fetchone()[0]
    db_cursor.execute("SELECT id FROM country WHERE iso_code = 'SEN'")
    country_id = db_cursor.fetchone()[0]
    db_cursor.execute("SELECT id FROM sector WHERE name = 'Renewable Energy'")
    sector_id = db_cursor.fetchone()[0]

    current_row = _funding_row(db_cursor, source_id, country_id, sector_id, 2026, "multilateral")
    assert current_row == (Decimal("2012500.00"), True)

    db_cursor.execute(
        """
        SELECT count(*) FROM funding
        WHERE source_id = %s AND country_id = %s AND sector_id = %s
          AND year = %s AND funding_type = %s AND is_current = false
        """,
        (source_id, country_id, sector_id, 2026, "multilateral"),
    )
    assert db_cursor.fetchone()[0] == 0  # every repeat after the first was a genuine no-op


def test_republishing_the_same_project_with_a_revised_amount_applies_only_the_delta(db_cursor):
    process_message(db_cursor, _sample_message(1_000_000, project_id="P-REVISED"))
    process_message(db_cursor, _sample_message(1_500_000, project_id="P-REVISED"))

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
    assert db_cursor.fetchone()[0] == 1  # the original 1,000,000 version, real revision kept traceable


def test_same_project_reclassified_to_a_different_sector_moves_its_contribution(db_cursor):
    message = _sample_message(1_000_000, project_id="P-RECLASSIFIED")
    process_message(db_cursor, message)  # raw_sectors -> Renewable Energy

    message["raw_sectors"] = ["Agriculture"]
    process_message(db_cursor, message)  # same project, now maps to Agriculture

    db_cursor.execute("SELECT id FROM source WHERE name = 'World Bank Data API'")
    source_id = db_cursor.fetchone()[0]
    db_cursor.execute("SELECT id FROM country WHERE iso_code = 'SEN'")
    country_id = db_cursor.fetchone()[0]
    db_cursor.execute("SELECT id FROM sector WHERE name = 'Renewable Energy'")
    old_sector_id = db_cursor.fetchone()[0]
    db_cursor.execute("SELECT id FROM sector WHERE name = 'Agriculture'")
    new_sector_id = db_cursor.fetchone()[0]

    old_row = _funding_row(db_cursor, source_id, country_id, old_sector_id, 2026, "multilateral")
    assert old_row == (Decimal("0.00"), True)

    new_row = _funding_row(db_cursor, source_id, country_id, new_sector_id, 2026, "multilateral")
    assert new_row == (Decimal("1000000.00"), True)


def test_opec_adaptation_and_mitigation_from_the_same_row_are_tracked_as_separate_contributions(db_cursor):
    # Both payloads share the same message["project_id"] (decision 7, B1.5
    # spec) - re-publishing the same document (e.g. a re-triggered DAG
    # before the cache check) must not let one dimension's contribution
    # overwrite the other's.
    adaptation = _opec_sample_message(1_000_000, climate_dimension="adaptation")
    mitigation = _opec_sample_message(2_000_000, climate_dimension="mitigation", sector_label_raw="Transport")
    process_message(db_cursor, adaptation)
    process_message(db_cursor, mitigation)
    # Re-publish both again unchanged (simulates a re-run) - neither must move.
    process_message(db_cursor, adaptation)
    process_message(db_cursor, mitigation)

    db_cursor.execute("SELECT id FROM source WHERE name = 'OPEC Fund — Climate Finance Report (PDF, Gemini-assisted)'")
    source_id = db_cursor.fetchone()[0]
    db_cursor.execute("SELECT id FROM country WHERE iso_code = 'SEN'")
    country_id = db_cursor.fetchone()[0]
    db_cursor.execute("SELECT id FROM sector WHERE name = 'Adaptation'")
    adaptation_sector_id = db_cursor.fetchone()[0]
    db_cursor.execute("SELECT id FROM sector WHERE name = 'Sustainable Transport'")
    mitigation_sector_id = db_cursor.fetchone()[0]

    adaptation_row = _funding_row(db_cursor, source_id, country_id, adaptation_sector_id, 2026, "multilateral")
    assert adaptation_row == (Decimal("1000000.00"), True)

    mitigation_row = _funding_row(db_cursor, source_id, country_id, mitigation_sector_id, 2026, "multilateral")
    assert mitigation_row == (Decimal("2000000.00"), True)
```

- [ ] **Step 7: Run test to verify it fails, then rebuild and confirm it passes**

Run: `docker compose run --rm funding-validator pytest pipeline/tests/test_funding_validator.py -v`
Expected (before rebuild): collection or assertion errors - the new functions/columns don't exist
in the running image yet.

Run: `docker compose build funding-validator && docker compose run --rm funding-validator pytest pipeline/tests/test_funding_validator.py -v`
Expected: all tests PASS, including the 4 new ones and the corrected
`test_two_different_projects_same_dedup_key_sum_and_historize`.

- [ ] **Step 8: Run the full offline suite to confirm no regression elsewhere**

Run:
```bash
docker compose run --rm funding-validator pytest pipeline/tests/ -m "not live" \
  --ignore=pipeline/tests/test_dag_worldbank_tasks.py \
  --ignore=pipeline/tests/test_dag_gcf_tasks.py \
  --ignore=pipeline/tests/test_dag_afdb_tasks.py \
  --ignore=pipeline/tests/test_dag_pnue_tasks.py \
  --ignore=pipeline/tests/test_dag_extraction_pdf_tasks.py -v
```
Expected: all green.

- [ ] **Step 9: Commit**

```bash
git add pipeline/processors/funding_validator.py pipeline/tests/test_funding_validator.py
git commit -m "fix: track per-project contributions to stop Funding double-counting on DAG re-runs"
git pull --rebase
git push
```

---

### Tâche 3 : [CONFIRMATION EXPLICITE REQUISE] Correction des données réelles corrompues

**⚠️ Cette tâche supprime des données réelles de la base de production locale. Ne pas exécuter le
Step 2 (la suppression) sans confirmation explicite de Serge à ce moment précis, même si ce plan a
déjà été approuvé dans son ensemble.**

**Files:** aucun fichier de code - opérations SQL directes + déclenchements Airflow réels.

**Interfaces:** consomme le code corrigé de la Tâche 2 (doit être déployé et vérifié avant cette
tâche - sinon les DAGs redéclenchés au Step 3 reproduiraient le même bug immédiatement).

- [ ] **Step 1: Mesurer l'état avant correction (preuve du avant/après)**

Run:
```bash
docker compose exec database psql -U nev_admin -d nev_climate_data -c "
SELECT s.name, count(*) FILTER (WHERE f.is_current = false) AS historized, count(*) AS total
FROM funding f JOIN source s ON s.id = f.source_id
WHERE s.name IN ('World Bank Data API', 'Green Climate Fund — IATI Datastore', 'African Development Bank Group — IATI Datastore')
GROUP BY s.name;
"
docker compose exec database psql -U nev_admin -d nev_climate_data -c "
SELECT amount FROM funding f
JOIN source s ON s.id = f.source_id JOIN country c ON c.id = f.country_id JOIN sector sec ON sec.id = f.sector_id
WHERE s.name = 'World Bank Data API' AND c.iso_code = 'SEN' AND sec.name = 'Agriculture' AND f.year = 1989 AND f.is_current = true;
"
```
Noter les résultats (attendu : ~19 668/21 549 historisées pour Banque Mondiale, montant courant
Sénégal/Agriculture/1989 autour de 128 800 000).

- [ ] **Step 2: [DEMANDER CONFIRMATION EXPLICITE] Supprimer les lignes corrompues**

**Arrêter ici et demander à Serge de confirmer explicitement avant d'exécuter cette commande.**
Une fois confirmé :

```bash
docker compose exec database psql -U nev_admin -d nev_climate_data -c "
DELETE FROM funding WHERE source_id IN (
  SELECT id FROM source WHERE name IN (
    'World Bank Data API',
    'Green Climate Fund — IATI Datastore',
    'African Development Bank Group — IATI Datastore'
  )
);
"
```

Ne touche pas à `OPEC Fund — Climate Finance Report (PDF, Gemini-assisted)` (non corrompu, cache
protégé), ni aux sources de démonstration Volet A (fixtures).

- [ ] **Step 3: Redéclencher chacun des 3 DAGs une seule fois**

```bash
docker compose exec airflow airflow dags trigger collecte_worldbank
docker compose exec airflow airflow dags trigger collecte_gcf
docker compose exec airflow airflow dags trigger collecte_afdb
```

Suivre le même pattern de sondage que la vérification end-to-end du refactoring multi-tâches
(`airflow dags list-runs -d <dag_id> --output json`) jusqu'à ce que les 3 runs atteignent
`success`.

- [ ] **Step 4: Vérifier la reconstruction**

Run:
```bash
docker compose exec database psql -U nev_admin -d nev_climate_data -c "
SELECT amount FROM funding f
JOIN source s ON s.id = f.source_id JOIN country c ON c.id = f.country_id JOIN sector sec ON sec.id = f.sector_id
WHERE s.name = 'World Bank Data API' AND c.iso_code = 'SEN' AND sec.name = 'Agriculture' AND f.year = 1989 AND f.is_current = true;
"
```
Expected: `16100000.00` exactement (la valeur d'un seul portefeuille, pas 128 800 000).

Run:
```bash
docker compose exec database psql -U nev_admin -d nev_climate_data -c "
SELECT s.name, count(*) FILTER (WHERE f.is_current = false) AS historized, count(*) AS total
FROM funding f JOIN source s ON s.id = f.source_id
WHERE s.name IN ('World Bank Data API', 'Green Climate Fund — IATI Datastore', 'African Development Bank Group — IATI Datastore')
GROUP BY s.name;
"
```
Expected: `historized = 0` pour les 3 sources (un seul run chacune depuis la reconstruction, aucune
version historisée pour l'instant).

- [ ] **Step 5: Documenter le résultat de la correction dans le message de commit**

Aucun fichier à committer ici (opération de données, pas de code) - le résultat (valeurs avant/
après) est reporté à Serge directement dans la conversation, et résumé dans le point d'attention
README de la Tâche 4.

---

### Tâche 4 : Documentation

**Files:**
- Modify: `README.md`

**Interfaces:** n/a - documentation uniquement.

- [ ] **Step 1: Ajouter un nouveau point d'attention**

Dans la section "Points d'attention" de `README.md`, ajouter (numéro suivant le dernier existant,
34) :

```markdown
35. **Un connecteur qui republie l'intégralité de son portefeuille à chaque exécution rend un validateur qui "additionne chaque message reçu" dangereux, pas seulement redondant.** Bug réel de production trouvé le 2026-08-31 en creusant B1.7 : chaque DAG de collecte (World Bank, GCF, AfDB) re-télécharge et republie tout son portefeuille courant à chaque run - pas seulement les nouveautés. `upsert_funding()` additionnait pourtant aveuglément chaque message reçu au total courant, sans jamais vérifier si le `project_id` avait déjà contribué lors d'un run précédent. Résultat vérifié en base réelle : Sénégal/Agriculture/1989 (Banque Mondiale) a été additionné 8 fois de suite avec exactement le même incrément (16,1M → 128,8M), et la quasi-totalité des lignes `Funding` réelles de ces 3 sources (jusqu'à 92% pour la Banque Mondiale) étaient des versions historisées par ce bug, pas de vraies révisions. PNUE (sémantique remplacement, pas addition) et OPEC Fund PDF (protégé par son cache SHA-256 de document) n'étaient pas affectés. Corrigé par une vraie idempotence par projet (`funding_project_contribution`, décisions complètes : [`docs/superpowers/specs/2026-08-31-funding-project-idempotency-design.md`](docs/superpowers/specs/2026-08-31-funding-project-idempotency-design.md)) plutôt qu'un correctif superficiel. Avant de faire sommer un validateur, toujours vérifier ce que fait réellement le collecteur en amont à chaque exécution - "récupère les nouveautés" et "récupère tout l'état courant" ont des implications d'idempotence radicalement différentes en aval.
```

- [ ] **Step 2: Ajouter une sous-section dans "Pipeline (Volet B)"**

Dans `README.md`, après la sous-section "Processor de validation et normalisation (B1.6)", ajouter :

```markdown
### Correction du double comptage Funding (2026-08-31)

Un vrai bug de production a été trouvé en creusant B1.7 (gestion des conflits entre sources) :
chaque DAG de collecte re-publie l'intégralité de son portefeuille à chaque exécution, et
`funding_validator.py` additionnait aveuglément chaque message reçu sans vérifier si le projet
avait déjà contribué lors d'un run précédent - gonflant indéfiniment les totaux réels à chaque
nouveau déclenchement. Décisions complètes et preuves détaillées :
[`docs/superpowers/specs/2026-08-31-funding-project-idempotency-design.md`](docs/superpowers/specs/2026-08-31-funding-project-idempotency-design.md).

Corrigé par une nouvelle table `funding_project_contribution` qui suit la dernière contribution
connue de chaque projet et applique un vrai delta (zéro si republié à l'identique, la différence
réelle si le montant a changé) au lieu de sommer aveuglément. Les données déjà corrompues des 3
sources concernées (Banque Mondiale, GCF, BAD) ont été supprimées et reconstruites à partir d'un
seul run propre par connecteur - OPEC Fund PDF (protégé par son cache) et PNUE (sémantique
remplacement, jamais affecté) n'ont pas eu besoin de correction.
```

- [ ] **Step 3: Commit**

```bash
git add README.md
git commit -m "docs: document the real Funding double-counting bug and its fix"
git pull --rebase
git push
```
