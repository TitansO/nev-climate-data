# A1.3 — TimescaleDB Deployment & Pipeline-Ready Schema Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Swap the placeholder Postgres image for TimescaleDB and create the eight-entity, pipeline-ready Doctrine schema (Country, Sector, Source, User, Funding, Report, ApiKey, Notification) described in the approved design spec.

**Architecture:** Two dependency layers, two Doctrine migrations. Layer 1 (Country, Sector, Source, User) has no foreign keys and is migrated first. Layer 2 (Funding, Report, ApiKey, Notification) has foreign keys into layer 1 and is migrated second, with indexes on Funding's filter columns.

**Tech Stack:** PHP 8.4, Symfony 7.4, Doctrine ORM 3.6 (attribute mapping, underscore naming strategy), Doctrine Migrations Bundle 4.0, PostgreSQL-compatible TimescaleDB (`timescale/timescaledb:latest-pg16`), PHPUnit 13.3.

## Global Constraints

- All code (classes, properties, DB columns) in English — spec decision 1.
- Primary keys: auto-increment integer, not UUID — spec decision 2.
- `Funding` gets `validFrom`/`validTo`/`isCurrent` columns now; Volet A logic does plain `UPDATE`s and never populates them — spec decision 3.
- Fixed low-cardinality value sets are native PHP 8.4 backed enums mapped through Doctrine; `Country`/`Sector`/`Source` stay full entities — spec decision 4.
- `Funding.amount` is in pivot currency (USD default); `originalAmount`/`originalCurrency`/`exchangeRate` are nullable, unpopulated in Volet A — spec decision 5.
- `UserRole` has exactly three cases: `Admin`, `InternalAnalyst`, `ExternalPartner` (no "Visitor" case) — spec decision 6.
- `Report.type` is a free-text string, not an enum — spec decision 7.
- `Report.country` is a nullable FK plus a separate nullable `region` string — spec decision 8.
- Exactly two migrations, one per layer — spec decision 9.
- README updated as part of this task, documenting the schema and migration commands — spec decision 10.
- `DATABASE_URL` and `POSTGRES_*` env vars must not change when swapping the DB image (spec, Architecture section).
- Repo root for all paths below: `nev-climate-data/` (the cloned GitLab repo). All `docker compose` commands run from that root.

---

## Task 1: Swap the database image to TimescaleDB

**Files:**
- Modify: `docker-compose.yml`

**Interfaces:**
- Produces: a running `database` service reachable by the `backend` service at the same `DATABASE_URL` as before (no consumers yet — this is the foundation for every later task).

- [ ] **Step 1: Modify `docker-compose.yml`**

Change the `database` service's `image` line and drop the now-stale comment on `backend.environment.DATABASE_URL` (the swap it was anticipating is happening in this exact task):

```yaml
  backend:
    build:
      context: .
      dockerfile: docker/backend/Dockerfile
    container_name: nev-climate-data-backend
    restart: unless-stopped
    ports:
      - "${BACKEND_PORT:-8080}:80"
    environment:
      APP_ENV: ${APP_ENV:-dev}
      APP_SECRET: ${APP_SECRET}
      DATABASE_URL: postgresql://${POSTGRES_USER}:${POSTGRES_PASSWORD}@database:5432/${POSTGRES_DB}?serverVersion=16&charset=utf8
    depends_on:
      database:
        condition: service_healthy
    volumes:
      - ./backend:/var/www/html
      - backend_vendor:/var/www/html/vendor
    networks:
      - nev-network

  database:
    image: timescale/timescaledb:latest-pg16
    container_name: nev-climate-data-db
    restart: unless-stopped
    environment:
      POSTGRES_DB: ${POSTGRES_DB}
      POSTGRES_USER: ${POSTGRES_USER}
      POSTGRES_PASSWORD: ${POSTGRES_PASSWORD}
    ports:
      - "${POSTGRES_PORT:-5432}:5432"
    volumes:
      - postgres_data:/var/lib/postgresql/data
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U ${POSTGRES_USER} -d ${POSTGRES_DB}"]
      interval: 5s
      timeout: 5s
      retries: 10
    networks:
      - nev-network
```

Only the `image:` value and the removed comment change. Everything else (env vars, healthcheck, volumes, ports) stays identical, confirming the "no `DATABASE_URL` change" constraint.

- [ ] **Step 2: Recreate the stack with the new image**

Run: `docker compose up -d --build --force-recreate database`
Expected: the `nev-climate-data-db` container is recreated from `timescale/timescaledb:latest-pg16` and becomes `healthy`.

Check with: `docker compose ps`
Expected: `database` row shows image `timescale/timescaledb:latest-pg16` and status `healthy`.

- [ ] **Step 3: Verify the backend still connects**

Run: `docker compose up -d --build backend` then `docker compose exec backend php bin/console dbal:run-sql "SELECT 1 AS ok"`
Expected: no connection error, output includes a row with `ok = 1`.

- [ ] **Step 4: Verify TimescaleDB extension is actually available**

Run: `docker compose exec database psql -U ${POSTGRES_USER} -d ${POSTGRES_DB} -c "SELECT extname, extversion FROM pg_available_extensions WHERE extname = 'timescaledb';"`
Expected: one row listing `timescaledb` with a version number (confirms the image genuinely provides TimescaleDB, not just a relabeled Postgres).

- [ ] **Step 5: Commit**

```bash
git add docker-compose.yml
git commit -m "feat(a1.3): swap database service to TimescaleDB"
```

---

## Task 2: `Country` entity

**Files:**
- Create: `backend/src/Entity/Country.php`
- Create: `backend/src/Repository/CountryRepository.php`
- Test: `backend/tests/Entity/CountryTest.php`

**Interfaces:**
- Produces: `App\Entity\Country` with constructor `__construct(string $name, string $isoCode, string $region)`, `getId(): ?int`, `getName(): string`, `setName(string): static`, `getIsoCode(): string`, `setIsoCode(string): static`, `getRegion(): string`, `setRegion(string): static`. Consumed by `Funding` and `Report` in later tasks (Task 7, Task 8).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Country;
use PHPUnit\Framework\TestCase;

final class CountryTest extends TestCase
{
    public function testConstructorSetsFields(): void
    {
        $country = new Country('Senegal', 'SEN', 'West Africa');

        self::assertNull($country->getId());
        self::assertSame('Senegal', $country->getName());
        self::assertSame('SEN', $country->getIsoCode());
        self::assertSame('West Africa', $country->getRegion());
    }

    public function testSettersUpdateFields(): void
    {
        $country = new Country('Senegal', 'SEN', 'West Africa');

        $country->setName('Republic of Senegal');
        $country->setIsoCode('SN');
        $country->setRegion('Sub-Saharan Africa');

        self::assertSame('Republic of Senegal', $country->getName());
        self::assertSame('SN', $country->getIsoCode());
        self::assertSame('Sub-Saharan Africa', $country->getRegion());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -e APP_ENV=test backend php bin/phpunit tests/Entity/CountryTest.php`
Expected: FAIL — `Class "App\Entity\Country" not found`.

- [ ] **Step 3: Write the entity**

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CountryRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CountryRepository::class)]
class Country
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 3, unique: true)]
    private string $isoCode;

    #[ORM\Column(length: 255)]
    private string $region;

    public function __construct(string $name, string $isoCode, string $region)
    {
        $this->name = $name;
        $this->isoCode = $isoCode;
        $this->region = $region;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getIsoCode(): string
    {
        return $this->isoCode;
    }

    public function setIsoCode(string $isoCode): static
    {
        $this->isoCode = $isoCode;

        return $this;
    }

    public function getRegion(): string
    {
        return $this->region;
    }

    public function setRegion(string $region): static
    {
        $this->region = $region;

        return $this;
    }
}
```

- [ ] **Step 4: Write the repository**

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Country;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Country>
 */
class CountryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Country::class);
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `docker compose exec -e APP_ENV=test backend php bin/phpunit tests/Entity/CountryTest.php`
Expected: PASS (2 tests, 8 assertions).

- [ ] **Step 6: Verify the mapping metadata is valid**

Run: `docker compose exec backend php bin/console doctrine:schema:validate --skip-sync`
Expected: `[OK] The mapping files are correct.` (DB sync is skipped on purpose — no table exists yet; that comes in Task 6).

- [ ] **Step 7: Commit**

```bash
git add backend/src/Entity/Country.php backend/src/Repository/CountryRepository.php backend/tests/Entity/CountryTest.php
git commit -m "feat(a1.3): add Country entity"
```

---

## Task 3: `Sector` entity

**Files:**
- Create: `backend/src/Entity/Sector.php`
- Create: `backend/src/Repository/SectorRepository.php`
- Test: `backend/tests/Entity/SectorTest.php`

**Interfaces:**
- Produces: `App\Entity\Sector` with constructor `__construct(string $name)`, `getId(): ?int`, `getName(): string`, `setName(string): static`. Consumed by `Funding` in Task 7.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Sector;
use PHPUnit\Framework\TestCase;

final class SectorTest extends TestCase
{
    public function testConstructorSetsFields(): void
    {
        $sector = new Sector('Renewable Energy');

        self::assertNull($sector->getId());
        self::assertSame('Renewable Energy', $sector->getName());
    }

    public function testSetterUpdatesField(): void
    {
        $sector = new Sector('Renewable Energy');

        $sector->setName('Sustainable Transport');

        self::assertSame('Sustainable Transport', $sector->getName());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -e APP_ENV=test backend php bin/phpunit tests/Entity/SectorTest.php`
Expected: FAIL — `Class "App\Entity\Sector" not found`.

- [ ] **Step 3: Write the entity**

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SectorRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SectorRepository::class)]
class Sector
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255, unique: true)]
    private string $name;

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }
}
```

- [ ] **Step 4: Write the repository**

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Sector;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Sector>
 */
class SectorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Sector::class);
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `docker compose exec -e APP_ENV=test backend php bin/phpunit tests/Entity/SectorTest.php`
Expected: PASS (2 tests, 3 assertions).

- [ ] **Step 6: Verify the mapping metadata is valid**

Run: `docker compose exec backend php bin/console doctrine:schema:validate --skip-sync`
Expected: `[OK] The mapping files are correct.`

- [ ] **Step 7: Commit**

```bash
git add backend/src/Entity/Sector.php backend/src/Repository/SectorRepository.php backend/tests/Entity/SectorTest.php
git commit -m "feat(a1.3): add Sector entity"
```

---

## Task 4: `Source` entity + `SourceType`/`SourceReliability` enums

**Files:**
- Create: `backend/src/Entity/Enum/SourceType.php`
- Create: `backend/src/Entity/Enum/SourceReliability.php`
- Create: `backend/src/Entity/Source.php`
- Create: `backend/src/Repository/SourceRepository.php`
- Test: `backend/tests/Entity/SourceTest.php`

**Interfaces:**
- Produces: `App\Entity\Enum\SourceType` (cases: `OfficialApi`, `PdfReport`, `GreenAccessEvent`, `InternalDemo`), `App\Entity\Enum\SourceReliability` (cases: `Low`, `Medium`, `High`), `App\Entity\Source` with constructor `__construct(string $name, SourceType $type, SourceReliability $reliability)`, `getId(): ?int`, `getName(): string`, `setName(string): static`, `getType(): SourceType`, `setType(SourceType): static`, `getReliability(): SourceReliability`, `setReliability(SourceReliability): static`. Consumed by `Funding` in Task 7.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Enum\SourceReliability;
use App\Entity\Enum\SourceType;
use App\Entity\Source;
use PHPUnit\Framework\TestCase;

final class SourceTest extends TestCase
{
    public function testConstructorSetsFields(): void
    {
        $source = new Source('Internal Demo', SourceType::InternalDemo, SourceReliability::Medium);

        self::assertNull($source->getId());
        self::assertSame('Internal Demo', $source->getName());
        self::assertSame(SourceType::InternalDemo, $source->getType());
        self::assertSame(SourceReliability::Medium, $source->getReliability());
    }

    public function testSettersUpdateFields(): void
    {
        $source = new Source('Internal Demo', SourceType::InternalDemo, SourceReliability::Medium);

        $source->setName('World Bank');
        $source->setType(SourceType::OfficialApi);
        $source->setReliability(SourceReliability::High);

        self::assertSame('World Bank', $source->getName());
        self::assertSame(SourceType::OfficialApi, $source->getType());
        self::assertSame(SourceReliability::High, $source->getReliability());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -e APP_ENV=test backend php bin/phpunit tests/Entity/SourceTest.php`
Expected: FAIL — `Class "App\Entity\Enum\SourceType" not found`.

- [ ] **Step 3: Write the enums**

```php
<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum SourceType: string
{
    case OfficialApi = 'official_api';
    case PdfReport = 'pdf_report';
    case GreenAccessEvent = 'green_access_event';
    case InternalDemo = 'internal_demo';
}
```

```php
<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum SourceReliability: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
}
```

- [ ] **Step 4: Write the entity**

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\SourceReliability;
use App\Entity\Enum\SourceType;
use App\Repository\SourceRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SourceRepository::class)]
class Source
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(type: Types::STRING, enumType: SourceType::class, length: 30)]
    private SourceType $type;

    #[ORM\Column(type: Types::STRING, enumType: SourceReliability::class, length: 10)]
    private SourceReliability $reliability;

    public function __construct(string $name, SourceType $type, SourceReliability $reliability)
    {
        $this->name = $name;
        $this->type = $type;
        $this->reliability = $reliability;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getType(): SourceType
    {
        return $this->type;
    }

    public function setType(SourceType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getReliability(): SourceReliability
    {
        return $this->reliability;
    }

    public function setReliability(SourceReliability $reliability): static
    {
        $this->reliability = $reliability;

        return $this;
    }
}
```

- [ ] **Step 5: Write the repository**

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Source;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Source>
 */
class SourceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Source::class);
    }
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `docker compose exec -e APP_ENV=test backend php bin/phpunit tests/Entity/SourceTest.php`
Expected: PASS (2 tests, 6 assertions).

- [ ] **Step 7: Verify the mapping metadata is valid**

Run: `docker compose exec backend php bin/console doctrine:schema:validate --skip-sync`
Expected: `[OK] The mapping files are correct.`

- [ ] **Step 8: Commit**

```bash
git add backend/src/Entity/Enum/SourceType.php backend/src/Entity/Enum/SourceReliability.php backend/src/Entity/Source.php backend/src/Repository/SourceRepository.php backend/tests/Entity/SourceTest.php
git commit -m "feat(a1.3): add Source entity with SourceType/SourceReliability enums"
```

---

## Task 5: `User` entity + `UserRole` enum

**Note:** `user` is a reserved keyword in PostgreSQL. Rather than relying on Doctrine's identifier-quoting fallback (which is easy to forget and breaks raw-SQL tooling later), the entity maps explicitly to table `users`.

**Files:**
- Create: `backend/src/Entity/Enum/UserRole.php`
- Create: `backend/src/Entity/User.php`
- Create: `backend/src/Repository/UserRepository.php`
- Test: `backend/tests/Entity/UserTest.php`

**Interfaces:**
- Produces: `App\Entity\Enum\UserRole` (cases: `Admin`, `InternalAnalyst`, `ExternalPartner`), `App\Entity\User` with constructor `__construct(string $name, string $email, string $passwordHash, UserRole $role)`, `getId(): ?int`, `getName(): string`, `setName(string): static`, `getEmail(): string`, `setEmail(string): static`, `getPasswordHash(): string`, `setPasswordHash(string): static`, `getRole(): UserRole`, `setRole(UserRole): static`, `getCreatedAt(): \DateTimeImmutable`. Consumed by `ApiKey` and `Notification` in Tasks 9–10.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Enum\UserRole;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class UserTest extends TestCase
{
    public function testConstructorSetsFields(): void
    {
        $user = new User('Amina Diallo', 'amina@example.com', 'hashed-password', UserRole::InternalAnalyst);

        self::assertNull($user->getId());
        self::assertSame('Amina Diallo', $user->getName());
        self::assertSame('amina@example.com', $user->getEmail());
        self::assertSame('hashed-password', $user->getPasswordHash());
        self::assertSame(UserRole::InternalAnalyst, $user->getRole());
        self::assertInstanceOf(\DateTimeImmutable::class, $user->getCreatedAt());
    }

    public function testSettersUpdateFields(): void
    {
        $user = new User('Amina Diallo', 'amina@example.com', 'hashed-password', UserRole::InternalAnalyst);

        $user->setName('Amina D.');
        $user->setEmail('amina.diallo@example.com');
        $user->setPasswordHash('new-hash');
        $user->setRole(UserRole::Admin);

        self::assertSame('Amina D.', $user->getName());
        self::assertSame('amina.diallo@example.com', $user->getEmail());
        self::assertSame('new-hash', $user->getPasswordHash());
        self::assertSame(UserRole::Admin, $user->getRole());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -e APP_ENV=test backend php bin/phpunit tests/Entity/UserTest.php`
Expected: FAIL — `Class "App\Entity\Enum\UserRole" not found`.

- [ ] **Step 3: Write the enum**

```php
<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum UserRole: string
{
    case Admin = 'admin';
    case InternalAnalyst = 'internal_analyst';
    case ExternalPartner = 'external_partner';
}
```

- [ ] **Step 4: Write the entity**

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\UserRole;
use App\Repository\UserRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Table(name: 'users')]
#[ORM\Entity(repositoryClass: UserRepository::class)]
class User
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 255, unique: true)]
    private string $email;

    #[ORM\Column(length: 255)]
    private string $passwordHash;

    #[ORM\Column(type: Types::STRING, enumType: UserRole::class, length: 20)]
    private UserRole $role;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $name, string $email, string $passwordHash, UserRole $role)
    {
        $this->name = $name;
        $this->email = $email;
        $this->passwordHash = $passwordHash;
        $this->role = $role;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getPasswordHash(): string
    {
        return $this->passwordHash;
    }

    public function setPasswordHash(string $passwordHash): static
    {
        $this->passwordHash = $passwordHash;

        return $this;
    }

    public function getRole(): UserRole
    {
        return $this->role;
    }

    public function setRole(UserRole $role): static
    {
        $this->role = $role;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
```

- [ ] **Step 5: Write the repository**

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `docker compose exec -e APP_ENV=test backend php bin/phpunit tests/Entity/UserTest.php`
Expected: PASS (2 tests, 10 assertions).

- [ ] **Step 7: Verify the mapping metadata is valid**

Run: `docker compose exec backend php bin/console doctrine:schema:validate --skip-sync`
Expected: `[OK] The mapping files are correct.`

- [ ] **Step 8: Commit**

```bash
git add backend/src/Entity/Enum/UserRole.php backend/src/Entity/User.php backend/src/Repository/UserRepository.php backend/tests/Entity/UserTest.php
git commit -m "feat(a1.3): add User entity with UserRole enum"
```

---

## Task 6: Migration 1 (layer 1) — Country, Sector, Source, users

**Files:**
- Create: `backend/migrations/VersionXXXXXXXXXXXXXX.php` (timestamp auto-generated by Doctrine — see Step 3)
- Test: `backend/tests/Integration/SchemaLayer1Test.php`

**Interfaces:**
- Consumes: `App\Entity\Country`, `App\Entity\Sector`, `App\Entity\Source` (+ `SourceType`, `SourceReliability`), `App\Entity\User` (+ `UserRole`) from Tasks 2–5.
- Produces: DB tables `country`, `sector`, `source`, `users`, ready for `Funding`/`Report`/`ApiKey`/`Notification` foreign keys in Task 11.

- [ ] **Step 1: Write the failing integration test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Country;
use App\Entity\Enum\SourceReliability;
use App\Entity\Enum\SourceType;
use App\Entity\Enum\UserRole;
use App\Entity\Sector;
use App\Entity\Source;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SchemaLayer1Test extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testCountryCanBePersistedAndFetched(): void
    {
        $country = new Country('Senegal', 'SEN', 'West Africa');
        $this->entityManager->persist($country);
        $this->entityManager->flush();
        $id = $country->getId();
        $this->entityManager->clear();

        $fetched = $this->entityManager->find(Country::class, $id);

        self::assertNotNull($fetched);
        self::assertSame('Senegal', $fetched->getName());
        self::assertSame('SEN', $fetched->getIsoCode());
    }

    public function testSectorCanBePersistedAndFetched(): void
    {
        $sector = new Sector('Renewable Energy');
        $this->entityManager->persist($sector);
        $this->entityManager->flush();
        $id = $sector->getId();
        $this->entityManager->clear();

        $fetched = $this->entityManager->find(Sector::class, $id);

        self::assertNotNull($fetched);
        self::assertSame('Renewable Energy', $fetched->getName());
    }

    public function testSourceCanBePersistedAndFetched(): void
    {
        $source = new Source('Internal Demo', SourceType::InternalDemo, SourceReliability::Medium);
        $this->entityManager->persist($source);
        $this->entityManager->flush();
        $id = $source->getId();
        $this->entityManager->clear();

        $fetched = $this->entityManager->find(Source::class, $id);

        self::assertNotNull($fetched);
        self::assertSame(SourceType::InternalDemo, $fetched->getType());
        self::assertSame(SourceReliability::Medium, $fetched->getReliability());
    }

    public function testUserCanBePersistedAndFetched(): void
    {
        $user = new User('Amina Diallo', 'amina@example.com', 'hashed-password', UserRole::InternalAnalyst);
        $this->entityManager->persist($user);
        $this->entityManager->flush();
        $id = $user->getId();
        $this->entityManager->clear();

        $fetched = $this->entityManager->find(User::class, $id);

        self::assertNotNull($fetched);
        self::assertSame('amina@example.com', $fetched->getEmail());
        self::assertSame(UserRole::InternalAnalyst, $fetched->getRole());
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->entityManager->close();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run:
```bash
docker compose exec -e APP_ENV=test backend php bin/console doctrine:database:create --if-not-exists
docker compose exec -e APP_ENV=test backend php bin/phpunit tests/Integration/SchemaLayer1Test.php
```
Expected: FAIL — each test errors with `SQLSTATE[42P01]: Undefined table` (no `country`/`sector`/`source`/`users` table exists yet).

- [ ] **Step 3: Generate the migration from the entity mappings**

Run: `docker compose exec backend php bin/console doctrine:migrations:diff --no-interaction`
Expected: output ends with `Generated new migration class to "/var/www/html/migrations/VersionYYYYMMDDHHMMSS.php"`. Note the exact filename/class name printed — you'll reference it in Step 4.

- [ ] **Step 4: Review the generated migration**

Open the generated `backend/migrations/VersionYYYYMMDDHHMMSS.php` and confirm its `up()` method creates, at minimum:
- `CREATE TABLE country (id ..., name ..., iso_code ... UNIQUE, region ...)`
- `CREATE TABLE sector (id ..., name ... UNIQUE)`
- `CREATE TABLE source (id ..., name ..., type ..., reliability ...)`
- `CREATE TABLE users (id ..., name ..., email ... UNIQUE, password_hash ..., role ..., created_at ...)`

and that `down()` drops the same four tables. If any table/column is missing or misnamed, the corresponding entity from Tasks 2–5 has a mapping mistake — fix the entity, delete the generated migration file, and re-run Step 3. Do not hand-edit the generated SQL to paper over a wrong entity mapping.

- [ ] **Step 5: Apply the migration to the test database**

Run: `docker compose exec -e APP_ENV=test backend php bin/console doctrine:migrations:migrate --no-interaction`
Expected: output confirms 1 migration executed successfully.

- [ ] **Step 6: Run test to verify it passes**

Run: `docker compose exec -e APP_ENV=test backend php bin/phpunit tests/Integration/SchemaLayer1Test.php`
Expected: PASS (4 tests).

- [ ] **Step 7: Apply the migration to the dev database and validate schema sync**

Run:
```bash
docker compose exec backend php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec backend php bin/console doctrine:schema:validate
```
Expected: `[OK] The mapping files are correct.` and `[OK] The database schema is in sync with the mapping files.`

- [ ] **Step 8: Verify the migration is safely reversible**

Run:
```bash
docker compose exec backend php bin/console doctrine:migrations:migrate prev --no-interaction
docker compose exec backend php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec backend php bin/console doctrine:schema:validate
```
Expected: rollback drops the four tables without error, re-applying recreates them, and `doctrine:schema:validate` reports `[OK]` again on both counts.

- [ ] **Step 9: Commit**

```bash
git add backend/migrations/
git commit -m "feat(a1.3): migration for layer-1 tables (country, sector, source, users)"
```

---

## Task 7: `Funding` entity + `FundingType`/`ValidationStatus` enums

**Files:**
- Create: `backend/src/Entity/Enum/FundingType.php`
- Create: `backend/src/Entity/Enum/ValidationStatus.php`
- Create: `backend/src/Entity/Funding.php`
- Create: `backend/src/Repository/FundingRepository.php`
- Test: `backend/tests/Entity/FundingTest.php`

**Interfaces:**
- Consumes: `App\Entity\Country`, `App\Entity\Sector`, `App\Entity\Source` (Tasks 2–4).
- Produces: `App\Entity\Enum\FundingType` (cases: `Public`, `Private`, `Multilateral`), `App\Entity\Enum\ValidationStatus` (cases: `Demo`, `Validated`), `App\Entity\Funding` with constructor `__construct(Country $country, Sector $sector, int $year, string $amount, FundingType $fundingType, Source $source, \DateTimeImmutable $collectionDate, ValidationStatus $validationStatus)` and getters/setters for every field listed below. Consumed by Task 11's migration only (no other entity depends on `Funding`).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Country;
use App\Entity\Enum\FundingType;
use App\Entity\Enum\SourceReliability;
use App\Entity\Enum\SourceType;
use App\Entity\Enum\ValidationStatus;
use App\Entity\Funding;
use App\Entity\Sector;
use App\Entity\Source;
use PHPUnit\Framework\TestCase;

final class FundingTest extends TestCase
{
    private function makeFunding(): Funding
    {
        return new Funding(
            new Country('Senegal', 'SEN', 'West Africa'),
            new Sector('Renewable Energy'),
            2025,
            '1000000.00',
            FundingType::Public,
            new Source('Internal Demo', SourceType::InternalDemo, SourceReliability::Medium),
            new \DateTimeImmutable('2026-08-20'),
            ValidationStatus::Demo,
        );
    }

    public function testConstructorSetsFields(): void
    {
        $funding = $this->makeFunding();

        self::assertNull($funding->getId());
        self::assertSame('Senegal', $funding->getCountry()->getName());
        self::assertSame('Renewable Energy', $funding->getSector()->getName());
        self::assertSame(2025, $funding->getYear());
        self::assertSame('1000000.00', $funding->getAmount());
        self::assertNull($funding->getOriginalAmount());
        self::assertNull($funding->getOriginalCurrency());
        self::assertNull($funding->getExchangeRate());
        self::assertSame(FundingType::Public, $funding->getFundingType());
        self::assertSame('Internal Demo', $funding->getSource()->getName());
        self::assertSame('2026-08-20', $funding->getCollectionDate()->format('Y-m-d'));
        self::assertSame(ValidationStatus::Demo, $funding->getValidationStatus());
        self::assertNull($funding->getValidFrom());
        self::assertNull($funding->getValidTo());
        self::assertTrue($funding->isCurrent());
    }

    public function testOriginalCurrencyFieldsCanBeSet(): void
    {
        $funding = $this->makeFunding();

        $funding->setOriginalAmount('850000.00');
        $funding->setOriginalCurrency('EUR');
        $funding->setExchangeRate('1.176471');

        self::assertSame('850000.00', $funding->getOriginalAmount());
        self::assertSame('EUR', $funding->getOriginalCurrency());
        self::assertSame('1.176471', $funding->getExchangeRate());
    }

    public function testHistorizationFieldsCanBeSet(): void
    {
        $funding = $this->makeFunding();
        $validFrom = new \DateTimeImmutable('2026-08-20');
        $validTo = new \DateTimeImmutable('2026-09-01');

        $funding->setValidFrom($validFrom);
        $funding->setValidTo($validTo);
        $funding->setIsCurrent(false);

        self::assertSame($validFrom, $funding->getValidFrom());
        self::assertSame($validTo, $funding->getValidTo());
        self::assertFalse($funding->isCurrent());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -e APP_ENV=test backend php bin/phpunit tests/Entity/FundingTest.php`
Expected: FAIL — `Class "App\Entity\Funding" not found`.

- [ ] **Step 3: Write the enums**

```php
<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum FundingType: string
{
    case Public = 'public';
    case Private = 'private';
    case Multilateral = 'multilateral';
}
```

```php
<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum ValidationStatus: string
{
    case Demo = 'demo';
    case Validated = 'validated';
}
```

- [ ] **Step 4: Write the entity**

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\FundingType;
use App\Entity\Enum\ValidationStatus;
use App\Repository\FundingRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Table(name: 'funding')]
#[ORM\Index(columns: ['country_id'], name: 'idx_funding_country')]
#[ORM\Index(columns: ['sector_id'], name: 'idx_funding_sector')]
#[ORM\Index(columns: ['year'], name: 'idx_funding_year')]
#[ORM\Index(columns: ['collection_date'], name: 'idx_funding_collection_date')]
#[ORM\Entity(repositoryClass: FundingRepository::class)]
class Funding
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Country::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Country $country;

    #[ORM\ManyToOne(targetEntity: Sector::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Sector $sector;

    #[ORM\Column]
    private int $year;

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2)]
    private string $amount;

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2, nullable: true)]
    private ?string $originalAmount = null;

    #[ORM\Column(length: 3, nullable: true)]
    private ?string $originalCurrency = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 6, nullable: true)]
    private ?string $exchangeRate = null;

    #[ORM\Column(type: Types::STRING, enumType: FundingType::class, length: 20)]
    private FundingType $fundingType;

    #[ORM\ManyToOne(targetEntity: Source::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Source $source;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $collectionDate;

    #[ORM\Column(type: Types::STRING, enumType: ValidationStatus::class, length: 20)]
    private ValidationStatus $validationStatus;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $validFrom = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $validTo = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $isCurrent = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        Country $country,
        Sector $sector,
        int $year,
        string $amount,
        FundingType $fundingType,
        Source $source,
        \DateTimeImmutable $collectionDate,
        ValidationStatus $validationStatus,
    ) {
        $this->country = $country;
        $this->sector = $sector;
        $this->year = $year;
        $this->amount = $amount;
        $this->fundingType = $fundingType;
        $this->source = $source;
        $this->collectionDate = $collectionDate;
        $this->validationStatus = $validationStatus;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCountry(): Country
    {
        return $this->country;
    }

    public function setCountry(Country $country): static
    {
        $this->country = $country;

        return $this;
    }

    public function getSector(): Sector
    {
        return $this->sector;
    }

    public function setSector(Sector $sector): static
    {
        $this->sector = $sector;

        return $this;
    }

    public function getYear(): int
    {
        return $this->year;
    }

    public function setYear(int $year): static
    {
        $this->year = $year;

        return $this;
    }

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function setAmount(string $amount): static
    {
        $this->amount = $amount;

        return $this;
    }

    public function getOriginalAmount(): ?string
    {
        return $this->originalAmount;
    }

    public function setOriginalAmount(?string $originalAmount): static
    {
        $this->originalAmount = $originalAmount;

        return $this;
    }

    public function getOriginalCurrency(): ?string
    {
        return $this->originalCurrency;
    }

    public function setOriginalCurrency(?string $originalCurrency): static
    {
        $this->originalCurrency = $originalCurrency;

        return $this;
    }

    public function getExchangeRate(): ?string
    {
        return $this->exchangeRate;
    }

    public function setExchangeRate(?string $exchangeRate): static
    {
        $this->exchangeRate = $exchangeRate;

        return $this;
    }

    public function getFundingType(): FundingType
    {
        return $this->fundingType;
    }

    public function setFundingType(FundingType $fundingType): static
    {
        $this->fundingType = $fundingType;

        return $this;
    }

    public function getSource(): Source
    {
        return $this->source;
    }

    public function setSource(Source $source): static
    {
        $this->source = $source;

        return $this;
    }

    public function getCollectionDate(): \DateTimeImmutable
    {
        return $this->collectionDate;
    }

    public function setCollectionDate(\DateTimeImmutable $collectionDate): static
    {
        $this->collectionDate = $collectionDate;

        return $this;
    }

    public function getValidationStatus(): ValidationStatus
    {
        return $this->validationStatus;
    }

    public function setValidationStatus(ValidationStatus $validationStatus): static
    {
        $this->validationStatus = $validationStatus;

        return $this;
    }

    public function getValidFrom(): ?\DateTimeImmutable
    {
        return $this->validFrom;
    }

    public function setValidFrom(?\DateTimeImmutable $validFrom): static
    {
        $this->validFrom = $validFrom;

        return $this;
    }

    public function getValidTo(): ?\DateTimeImmutable
    {
        return $this->validTo;
    }

    public function setValidTo(?\DateTimeImmutable $validTo): static
    {
        $this->validTo = $validTo;

        return $this;
    }

    public function isCurrent(): bool
    {
        return $this->isCurrent;
    }

    public function setIsCurrent(bool $isCurrent): static
    {
        $this->isCurrent = $isCurrent;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(): static
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }
}
```

- [ ] **Step 5: Write the repository**

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Funding;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Funding>
 */
class FundingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Funding::class);
    }
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `docker compose exec -e APP_ENV=test backend php bin/phpunit tests/Entity/FundingTest.php`
Expected: PASS (3 tests).

- [ ] **Step 7: Verify the mapping metadata is valid**

Run: `docker compose exec backend php bin/console doctrine:schema:validate --skip-sync`
Expected: `[OK] The mapping files are correct.`

- [ ] **Step 8: Commit**

```bash
git add backend/src/Entity/Enum/FundingType.php backend/src/Entity/Enum/ValidationStatus.php backend/src/Entity/Funding.php backend/src/Repository/FundingRepository.php backend/tests/Entity/FundingTest.php
git commit -m "feat(a1.3): add Funding entity with FundingType/ValidationStatus enums"
```

---

## Task 8: `Report` entity + `ReportStatus` enum

**Files:**
- Create: `backend/src/Entity/Enum/ReportStatus.php`
- Create: `backend/src/Entity/Report.php`
- Create: `backend/src/Repository/ReportRepository.php`
- Test: `backend/tests/Entity/ReportTest.php`

**Interfaces:**
- Consumes: `App\Entity\Country` (Task 2).
- Produces: `App\Entity\Enum\ReportStatus` (cases: `Draft`, `Published`), `App\Entity\Report` with constructor `__construct(string $title, string $type, string $pdfFile)` (defaults `status` to `Draft`, `downloadCount` to `0`, `country`/`region`/`publicationDate` to `null`) and getters/setters for every field below.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Country;
use App\Entity\Enum\ReportStatus;
use App\Entity\Report;
use PHPUnit\Framework\TestCase;

final class ReportTest extends TestCase
{
    public function testConstructorSetsDefaults(): void
    {
        $report = new Report('2026 Climate Finance Overview', 'Annual Report', 'reports/2026-overview.pdf');

        self::assertNull($report->getId());
        self::assertSame('2026 Climate Finance Overview', $report->getTitle());
        self::assertNull($report->getCountry());
        self::assertNull($report->getRegion());
        self::assertSame('Annual Report', $report->getType());
        self::assertNull($report->getPublicationDate());
        self::assertSame(ReportStatus::Draft, $report->getStatus());
        self::assertSame('reports/2026-overview.pdf', $report->getPdfFile());
        self::assertSame(0, $report->getDownloadCount());
    }

    public function testPublishingSetsStatusAndPublicationDate(): void
    {
        $report = new Report('2026 Climate Finance Overview', 'Annual Report', 'reports/2026-overview.pdf');
        $country = new Country('Senegal', 'SEN', 'West Africa');
        $publicationDate = new \DateTimeImmutable('2026-08-22');

        $report->setCountry($country);
        $report->setRegion('West Africa');
        $report->setPublicationDate($publicationDate);
        $report->setStatus(ReportStatus::Published);

        self::assertSame($country, $report->getCountry());
        self::assertSame('West Africa', $report->getRegion());
        self::assertSame($publicationDate, $report->getPublicationDate());
        self::assertSame(ReportStatus::Published, $report->getStatus());
    }

    public function testDownloadCountCanBeIncremented(): void
    {
        $report = new Report('2026 Climate Finance Overview', 'Annual Report', 'reports/2026-overview.pdf');

        $report->incrementDownloadCount();
        $report->incrementDownloadCount();

        self::assertSame(2, $report->getDownloadCount());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -e APP_ENV=test backend php bin/phpunit tests/Entity/ReportTest.php`
Expected: FAIL — `Class "App\Entity\Report" not found`.

- [ ] **Step 3: Write the enum**

```php
<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum ReportStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}
```

- [ ] **Step 4: Write the entity**

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\ReportStatus;
use App\Repository\ReportRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ReportRepository::class)]
class Report
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\ManyToOne(targetEntity: Country::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Country $country = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $region = null;

    #[ORM\Column(length: 255)]
    private string $type;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $publicationDate = null;

    #[ORM\Column(type: Types::STRING, enumType: ReportStatus::class, length: 20, options: ['default' => 'draft'])]
    private ReportStatus $status;

    #[ORM\Column(length: 255)]
    private string $pdfFile;

    #[ORM\Column(options: ['default' => 0])]
    private int $downloadCount = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $title, string $type, string $pdfFile)
    {
        $this->title = $title;
        $this->type = $type;
        $this->pdfFile = $pdfFile;
        $this->status = ReportStatus::Draft;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getCountry(): ?Country
    {
        return $this->country;
    }

    public function setCountry(?Country $country): static
    {
        $this->country = $country;

        return $this;
    }

    public function getRegion(): ?string
    {
        return $this->region;
    }

    public function setRegion(?string $region): static
    {
        $this->region = $region;

        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getPublicationDate(): ?\DateTimeImmutable
    {
        return $this->publicationDate;
    }

    public function setPublicationDate(?\DateTimeImmutable $publicationDate): static
    {
        $this->publicationDate = $publicationDate;

        return $this;
    }

    public function getStatus(): ReportStatus
    {
        return $this->status;
    }

    public function setStatus(ReportStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getPdfFile(): string
    {
        return $this->pdfFile;
    }

    public function setPdfFile(string $pdfFile): static
    {
        $this->pdfFile = $pdfFile;

        return $this;
    }

    public function getDownloadCount(): int
    {
        return $this->downloadCount;
    }

    public function incrementDownloadCount(): static
    {
        ++$this->downloadCount;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
```

- [ ] **Step 5: Write the repository**

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Report;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Report>
 */
class ReportRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Report::class);
    }
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `docker compose exec -e APP_ENV=test backend php bin/phpunit tests/Entity/ReportTest.php`
Expected: PASS (3 tests).

- [ ] **Step 7: Verify the mapping metadata is valid**

Run: `docker compose exec backend php bin/console doctrine:schema:validate --skip-sync`
Expected: `[OK] The mapping files are correct.`

- [ ] **Step 8: Commit**

```bash
git add backend/src/Entity/Enum/ReportStatus.php backend/src/Entity/Report.php backend/src/Repository/ReportRepository.php backend/tests/Entity/ReportTest.php
git commit -m "feat(a1.3): add Report entity with ReportStatus enum"
```

---

## Task 9: `ApiKey` entity + `ApiKeyStatus` enum

**Files:**
- Create: `backend/src/Entity/Enum/ApiKeyStatus.php`
- Create: `backend/src/Entity/ApiKey.php`
- Create: `backend/src/Repository/ApiKeyRepository.php`
- Test: `backend/tests/Entity/ApiKeyTest.php`

**Interfaces:**
- Consumes: `App\Entity\User` (Task 5).
- Produces: `App\Entity\Enum\ApiKeyStatus` (cases: `Active`, `Revoked`), `App\Entity\ApiKey` with constructor `__construct(User $user, string $keyHash, int $quota)` (defaults `status` to `Active`) and getters/setters below.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\ApiKey;
use App\Entity\Enum\ApiKeyStatus;
use App\Entity\Enum\UserRole;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class ApiKeyTest extends TestCase
{
    public function testConstructorSetsDefaults(): void
    {
        $user = new User('Amina Diallo', 'amina@example.com', 'hashed-password', UserRole::ExternalPartner);
        $apiKey = new ApiKey($user, 'hashed-key-value', 1000);

        self::assertNull($apiKey->getId());
        self::assertSame($user, $apiKey->getUser());
        self::assertSame('hashed-key-value', $apiKey->getKeyHash());
        self::assertSame(1000, $apiKey->getQuota());
        self::assertSame(ApiKeyStatus::Active, $apiKey->getStatus());
        self::assertNull($apiKey->getRevokedAt());
    }

    public function testRevokeSetsStatusAndTimestamp(): void
    {
        $user = new User('Amina Diallo', 'amina@example.com', 'hashed-password', UserRole::ExternalPartner);
        $apiKey = new ApiKey($user, 'hashed-key-value', 1000);

        $apiKey->revoke();

        self::assertSame(ApiKeyStatus::Revoked, $apiKey->getStatus());
        self::assertInstanceOf(\DateTimeImmutable::class, $apiKey->getRevokedAt());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -e APP_ENV=test backend php bin/phpunit tests/Entity/ApiKeyTest.php`
Expected: FAIL — `Class "App\Entity\ApiKey" not found`.

- [ ] **Step 3: Write the enum**

```php
<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum ApiKeyStatus: string
{
    case Active = 'active';
    case Revoked = 'revoked';
}
```

- [ ] **Step 4: Write the entity**

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\ApiKeyStatus;
use App\Repository\ApiKeyRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ApiKeyRepository::class)]
class ApiKey
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $user;

    #[ORM\Column(length: 255)]
    private string $keyHash;

    #[ORM\Column(type: Types::STRING, enumType: ApiKeyStatus::class, length: 20, options: ['default' => 'active'])]
    private ApiKeyStatus $status;

    #[ORM\Column]
    private int $quota;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    public function __construct(User $user, string $keyHash, int $quota)
    {
        $this->user = $user;
        $this->keyHash = $keyHash;
        $this->quota = $quota;
        $this->status = ApiKeyStatus::Active;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getKeyHash(): string
    {
        return $this->keyHash;
    }

    public function getStatus(): ApiKeyStatus
    {
        return $this->status;
    }

    public function getQuota(): int
    {
        return $this->quota;
    }

    public function setQuota(int $quota): static
    {
        $this->quota = $quota;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function revoke(): static
    {
        $this->status = ApiKeyStatus::Revoked;
        $this->revokedAt = new \DateTimeImmutable();

        return $this;
    }
}
```

`keyHash`/`status` have no public setter beyond `revoke()`: per cahier des charges rule 5.2.b, revocation is irreversible and the plaintext key is never stored, so the only state transition this entity exposes is one-way revocation.

- [ ] **Step 5: Write the repository**

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ApiKey;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ApiKey>
 */
class ApiKeyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ApiKey::class);
    }
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `docker compose exec -e APP_ENV=test backend php bin/phpunit tests/Entity/ApiKeyTest.php`
Expected: PASS (2 tests).

- [ ] **Step 7: Verify the mapping metadata is valid**

Run: `docker compose exec backend php bin/console doctrine:schema:validate --skip-sync`
Expected: `[OK] The mapping files are correct.`

- [ ] **Step 8: Commit**

```bash
git add backend/src/Entity/Enum/ApiKeyStatus.php backend/src/Entity/ApiKey.php backend/src/Repository/ApiKeyRepository.php backend/tests/Entity/ApiKeyTest.php
git commit -m "feat(a1.3): add ApiKey entity with ApiKeyStatus enum"
```

---

## Task 10: `Notification` entity + `NotificationType` enum

**Files:**
- Create: `backend/src/Entity/Enum/NotificationType.php`
- Create: `backend/src/Entity/Notification.php`
- Create: `backend/src/Repository/NotificationRepository.php`
- Test: `backend/tests/Entity/NotificationTest.php`

**Interfaces:**
- Consumes: `App\Entity\User` (Task 5).
- Produces: `App\Entity\Enum\NotificationType` (cases: `NewReport`, `NewData`), `App\Entity\Notification` with constructor `__construct(User $user, NotificationType $eventType, string $content)` (defaults `isRead` to `false`) and getters/setters below.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Enum\NotificationType;
use App\Entity\Enum\UserRole;
use App\Entity\Notification;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class NotificationTest extends TestCase
{
    public function testConstructorSetsDefaults(): void
    {
        $user = new User('Amina Diallo', 'amina@example.com', 'hashed-password', UserRole::InternalAnalyst);
        $notification = new Notification($user, NotificationType::NewReport, 'A new report was published.');

        self::assertNull($notification->getId());
        self::assertSame($user, $notification->getUser());
        self::assertSame(NotificationType::NewReport, $notification->getEventType());
        self::assertSame('A new report was published.', $notification->getContent());
        self::assertFalse($notification->isRead());
        self::assertInstanceOf(\DateTimeImmutable::class, $notification->getCreatedAt());
    }

    public function testMarkAsReadUpdatesFlag(): void
    {
        $user = new User('Amina Diallo', 'amina@example.com', 'hashed-password', UserRole::InternalAnalyst);
        $notification = new Notification($user, NotificationType::NewData, 'New data is available for Senegal.');

        $notification->markAsRead();

        self::assertTrue($notification->isRead());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -e APP_ENV=test backend php bin/phpunit tests/Entity/NotificationTest.php`
Expected: FAIL — `Class "App\Entity\Notification" not found`.

- [ ] **Step 3: Write the enum**

```php
<?php

declare(strict_types=1);

namespace App\Entity\Enum;

enum NotificationType: string
{
    case NewReport = 'new_report';
    case NewData = 'new_data';
}
```

- [ ] **Step 4: Write the entity**

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Enum\NotificationType;
use App\Repository\NotificationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: NotificationRepository::class)]
class Notification
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private User $user;

    #[ORM\Column(type: Types::STRING, enumType: NotificationType::class, length: 20)]
    private NotificationType $eventType;

    #[ORM\Column(type: Types::TEXT)]
    private string $content;

    #[ORM\Column(options: ['default' => false])]
    private bool $isRead = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, NotificationType $eventType, string $content)
    {
        $this->user = $user;
        $this->eventType = $eventType;
        $this->content = $content;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getEventType(): NotificationType
    {
        return $this->eventType;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function isRead(): bool
    {
        return $this->isRead;
    }

    public function markAsRead(): static
    {
        $this->isRead = true;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
```

- [ ] **Step 5: Write the repository**

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Notification;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Notification>
 */
class NotificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Notification::class);
    }
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `docker compose exec -e APP_ENV=test backend php bin/phpunit tests/Entity/NotificationTest.php`
Expected: PASS (2 tests).

- [ ] **Step 7: Verify the mapping metadata is valid**

Run: `docker compose exec backend php bin/console doctrine:schema:validate --skip-sync`
Expected: `[OK] The mapping files are correct.`

- [ ] **Step 8: Commit**

```bash
git add backend/src/Entity/Enum/NotificationType.php backend/src/Entity/Notification.php backend/src/Repository/NotificationRepository.php backend/tests/Entity/NotificationTest.php
git commit -m "feat(a1.3): add Notification entity with NotificationType enum"
```

---

## Task 11: Migration 2 (layer 2) — funding, report, api_key, notification

**Files:**
- Create: `backend/migrations/VersionXXXXXXXXXXXXXX.php` (second timestamp, auto-generated — see Step 3)
- Test: `backend/tests/Integration/SchemaLayer2Test.php`

**Interfaces:**
- Consumes: `App\Entity\Funding`, `App\Entity\Report`, `App\Entity\ApiKey`, `App\Entity\Notification` (Tasks 7–10), plus `App\Entity\Country`/`Sector`/`Source`/`User` fixtures from layer 1.
- Produces: DB tables `funding`, `report`, `api_key`, `notification` with foreign keys into layer 1 and the indexes required by cahier des charges 5.5. This is the last table of A1.3 — Task 12 only touches documentation afterward.

- [ ] **Step 1: Write the failing integration test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\ApiKey;
use App\Entity\Country;
use App\Entity\Enum\ApiKeyStatus;
use App\Entity\Enum\FundingType;
use App\Entity\Enum\NotificationType;
use App\Entity\Enum\ReportStatus;
use App\Entity\Enum\SourceReliability;
use App\Entity\Enum\SourceType;
use App\Entity\Enum\UserRole;
use App\Entity\Enum\ValidationStatus;
use App\Entity\Funding;
use App\Entity\Notification;
use App\Entity\Report;
use App\Entity\Sector;
use App\Entity\Source;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SchemaLayer2Test extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testFundingCanBePersistedWithRelationsAndFetched(): void
    {
        $country = new Country('Senegal', 'SEN', 'West Africa');
        $sector = new Sector('Renewable Energy');
        $source = new Source('Internal Demo', SourceType::InternalDemo, SourceReliability::Medium);
        $this->entityManager->persist($country);
        $this->entityManager->persist($sector);
        $this->entityManager->persist($source);

        $funding = new Funding(
            $country,
            $sector,
            2025,
            '1000000.00',
            FundingType::Public,
            $source,
            new \DateTimeImmutable('2026-08-20'),
            ValidationStatus::Demo,
        );
        $this->entityManager->persist($funding);
        $this->entityManager->flush();
        $id = $funding->getId();
        $this->entityManager->clear();

        $fetched = $this->entityManager->find(Funding::class, $id);

        self::assertNotNull($fetched);
        self::assertSame('Senegal', $fetched->getCountry()->getName());
        self::assertSame('Renewable Energy', $fetched->getSector()->getName());
        self::assertSame('1000000.00', $fetched->getAmount());
    }

    public function testReportCanBePersistedAndFetched(): void
    {
        $report = new Report('2026 Climate Finance Overview', 'Annual Report', 'reports/2026-overview.pdf');
        $report->setStatus(ReportStatus::Published);
        $this->entityManager->persist($report);
        $this->entityManager->flush();
        $id = $report->getId();
        $this->entityManager->clear();

        $fetched = $this->entityManager->find(Report::class, $id);

        self::assertNotNull($fetched);
        self::assertSame(ReportStatus::Published, $fetched->getStatus());
    }

    public function testApiKeyCanBePersistedWithUserAndFetched(): void
    {
        $user = new User('Amina Diallo', 'amina.apikey@example.com', 'hashed-password', UserRole::ExternalPartner);
        $this->entityManager->persist($user);

        $apiKey = new ApiKey($user, 'hashed-key-value', 1000);
        $this->entityManager->persist($apiKey);
        $this->entityManager->flush();
        $id = $apiKey->getId();
        $this->entityManager->clear();

        $fetched = $this->entityManager->find(ApiKey::class, $id);

        self::assertNotNull($fetched);
        self::assertSame(ApiKeyStatus::Active, $fetched->getStatus());
        self::assertSame('amina.apikey@example.com', $fetched->getUser()->getEmail());
    }

    public function testNotificationCanBePersistedWithUserAndFetched(): void
    {
        $user = new User('Amina Diallo', 'amina.notif@example.com', 'hashed-password', UserRole::InternalAnalyst);
        $this->entityManager->persist($user);

        $notification = new Notification($user, NotificationType::NewReport, 'A new report was published.');
        $this->entityManager->persist($notification);
        $this->entityManager->flush();
        $id = $notification->getId();
        $this->entityManager->clear();

        $fetched = $this->entityManager->find(Notification::class, $id);

        self::assertNotNull($fetched);
        self::assertFalse($fetched->isRead());
    }

    public function testFundingIndexesExist(): void
    {
        /** @var Connection $connection */
        $connection = $this->entityManager->getConnection();
        $indexNames = $connection->fetchFirstColumn(
            "SELECT indexname FROM pg_indexes WHERE tablename = 'funding'"
        );

        self::assertContains('idx_funding_country', $indexNames);
        self::assertContains('idx_funding_sector', $indexNames);
        self::assertContains('idx_funding_year', $indexNames);
        self::assertContains('idx_funding_collection_date', $indexNames);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->entityManager->close();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec -e APP_ENV=test backend php bin/phpunit tests/Integration/SchemaLayer2Test.php`
Expected: FAIL — each test errors with `SQLSTATE[42P01]: Undefined table` (no `funding`/`report`/`api_key`/`notification` table exists yet).

- [ ] **Step 3: Generate the migration from the entity mappings**

Run: `docker compose exec backend php bin/console doctrine:migrations:diff --no-interaction`
Expected: output ends with `Generated new migration class to "/var/www/html/migrations/VersionYYYYMMDDHHMMSS.php"` (a second, later timestamp than Task 6's).

- [ ] **Step 4: Review the generated migration**

Open the new file and confirm its `up()` method creates:
- `CREATE TABLE funding (..., country_id ... REFERENCES country, sector_id ... REFERENCES sector, source_id ... REFERENCES source, ...)` plus the four indexes named `idx_funding_country`, `idx_funding_sector`, `idx_funding_year`, `idx_funding_collection_date`
- `CREATE TABLE report (..., country_id ... REFERENCES country NULL, ...)`
- `CREATE TABLE api_key (..., user_id ... REFERENCES users, ...)`
- `CREATE TABLE notification (..., user_id ... REFERENCES users, ...)`

and that it does **not** touch `country`, `sector`, `source`, or `users` (those belong to Task 6's migration only). If anything is missing or an unrelated table is touched, fix the entity mapping from Tasks 7–10, delete the generated file, and re-run Step 3.

- [ ] **Step 5: Apply the migration to the test database**

Run: `docker compose exec -e APP_ENV=test backend php bin/console doctrine:migrations:migrate --no-interaction`
Expected: output confirms 1 migration executed successfully.

- [ ] **Step 6: Run test to verify it passes**

Run: `docker compose exec -e APP_ENV=test backend php bin/phpunit tests/Integration/SchemaLayer2Test.php`
Expected: PASS (5 tests).

- [ ] **Step 7: Apply the migration to the dev database and validate schema sync**

Run:
```bash
docker compose exec backend php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec backend php bin/console doctrine:schema:validate
```
Expected: `[OK] The mapping files are correct.` and `[OK] The database schema is in sync with the mapping files.`

- [ ] **Step 8: Verify the migration is safely reversible**

Run:
```bash
docker compose exec backend php bin/console doctrine:migrations:migrate prev --no-interaction
docker compose exec backend php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec backend php bin/console doctrine:schema:validate
```
Expected: rollback drops `funding`/`report`/`api_key`/`notification` without touching layer-1 tables, re-applying recreates them, `doctrine:schema:validate` reports `[OK]` on both counts.

- [ ] **Step 9: Run the full test suite**

Run: `docker compose exec -e APP_ENV=test backend php bin/phpunit`
Expected: all tests pass — the pre-existing `HealthControllerTest`/`ApiDocControllerTest`, all 8 entity unit test files, and both integration test files.

- [ ] **Step 10: Commit**

```bash
git add backend/migrations/
git commit -m "feat(a1.3): migration for layer-2 tables (funding, report, api_key, notification)"
```

---

## Task 12: Update README

**Files:**
- Modify: `README.md`

**Interfaces:**
- Consumes: nothing (documentation only).
- Produces: nothing consumed by later tasks — this is the final task of A1.3.

- [ ] **Step 1: Update the "Prochaine étape" section**

Replace:

```markdown
## Prochaine étape

Les fondations Docker et Symfony (Points 1 et 2) sont posées. La suite du plan (Point 3 — déploiement de TimescaleDB et schéma de données « pipeline-ready ») sera traitée séparément.
```

with:

```markdown
## Prochaine étape

Les fondations Docker et Symfony (Points 1 et 2) sont posées. TimescaleDB est déployé et le schéma « pipeline-ready » (Point 3 — A1.3) est en place : voir la section « Schéma de données » ci-dessous. La suite du plan (Point 4 — authentification JWT, A1.4) sera traitée séparément.
```

- [ ] **Step 2: Update the database note in "Variables d'environnement"**

Replace:

```markdown
> Note : le service `database` utilise pour l'instant l'image standard `postgres:16-alpine`. Elle sera remplacée par l'image `timescale/timescaledb:latest-pg16` (compatible protocole PostgreSQL) au Point 3 du plan (A1.3), sans modification des variables de connexion.
```

with:

```markdown
> Note : le service `database` utilise l'image `timescale/timescaledb:latest-pg16` (compatible protocole PostgreSQL) depuis le Point 3 du plan (A1.3). Les variables de connexion (`DATABASE_URL`, `POSTGRES_*`) n'ont pas changé lors de ce remplacement.
```

- [ ] **Step 3: Add a new "Schéma de données" section**

Insert this new section right before "## Tests automatisés":

```markdown
## Schéma de données

Le schéma est réparti en deux migrations Doctrine, correspondant à deux couches de dépendances :

**Couche 1 — sans clé étrangère** : `Country`, `Sector`, `Source`, `User` (table `users`, car `user` est un mot réservé en PostgreSQL).

**Couche 2 — dépend de la couche 1** : `Funding` (clés étrangères vers `Country`, `Sector`, `Source`), `Report` (clé étrangère optionnelle vers `Country`), `ApiKey` et `Notification` (clé étrangère vers `User`).

Toutes les valeurs à faible cardinalité et stables (rôle utilisateur, type de financement, statut de validation, statut de rapport, statut de clé API, type/fiabilité de source, type de notification) sont des enums PHP natifs mappés via Doctrine, pas des tables de référence.

`Funding` porte dès maintenant les colonnes nécessaires au Volet B sans que la logique applicative du Volet A les exploite :
- `originalAmount` / `originalCurrency` / `exchangeRate` (montant dans la devise pivot USD dans `amount`, métadonnées de conversion réservées au Volet B)
- `validFrom` / `validTo` / `isCurrent` (hooks d'historisation ; le Volet A continue de faire de simples `UPDATE` en place)

Détails complets du modèle de données et des décisions de conception : voir
[`docs/superpowers/specs/2026-08-22-a13-timescaledb-schema-design.md`](docs/superpowers/specs/2026-08-22-a13-timescaledb-schema-design.md).

### Appliquer les migrations

```bash
docker compose exec backend php bin/console doctrine:migrations:migrate
```

### Voir l'état des migrations

```bash
docker compose exec backend php bin/console doctrine:migrations:status
```

### Valider le schéma (mapping + synchronisation base)

```bash
docker compose exec backend php bin/console doctrine:schema:validate
```
```

- [ ] **Step 4: Verify the README renders sensibly**

Run: `docker compose exec backend cat README.md | head -100` (or open the file locally) and confirm the new section reads correctly in context, with no broken Markdown (matching code fence counts, no stray `#`).

- [ ] **Step 5: Commit**

```bash
git add README.md
git commit -m "docs(a1.3): document TimescaleDB schema and migration commands"
```

---

## Final check before considering A1.3 done

- [ ] Run `docker compose exec -e APP_ENV=test backend php bin/phpunit` one more time — full suite green.
- [ ] Run `docker compose exec backend php bin/console doctrine:schema:validate` — both `[OK]` lines.
- [ ] Run `git log --oneline` on `developp` — 12 new commits since Oumar's `063c482` (one per task, Tasks 1–12; Task 4/5/7/8/9/10 each being a single commit despite having enum + entity + repo + test files), each with a clean, descriptive message.
- [ ] Cross-check against the plan spreadsheet: A1.3's "Livrable attendu" was *"Schéma de base documenté avec champs source_id / date_collecte / statut_validation"* — confirm `Funding.source` (FK, plays the role of `source_id`), `Funding.collectionDate`, and `Funding.validationStatus` are all present and match.
- [ ] Per Serge's stated push strategy: do **not** run `git push` yet — wait until A1.4 (JWT) is also complete, then push both together.
