# B3.2 — Diffusion des KPIs réels via Mercure - Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rebrancher `AnalyticsService::getCo2Reduction()` sur les vraies données `Emission`
(B1.4), diffuser ce chiffre via le hub Mercure aux côtés du total de financement déjà réel, et
vérifier en direct que tout le flux "KPIs réels" (financement + CO2) fonctionne de bout en bout.

**Architecture:** `EmissionRepository` gagne deux méthodes de lecture (années disponibles, totaux
par pays pour une année) ; `AnalyticsService::getCo2Reduction()` calcule la variation entre les
deux années les plus récentes sur l'intersection des pays communs ; `PublishKpiSnapshotCommand`
ajoute ce chiffre au payload Mercure existant ; le frontend consomme la nouvelle forme de réponse
et affiche le badge "Direct" sur la tuile CO2 comme il le fait déjà pour le total de financement.

**Tech Stack:** PHP 8.4/Symfony, Doctrine ORM QueryBuilder, PHPUnit (`KernelTestCase`/
`WebTestCase`), React (hooks), Mercure/EventSource.

## Global Constraints

- Filtre `emission.isCurrent = true` obligatoire sur toute lecture (même principe que B1.7 pour
  `Funding` - voir [`2026-08-31-b17-current-funding-filter-design.md`](docs/superpowers/specs/2026-08-31-b17-current-funding-filter-design.md)).
- Jamais traiter un pays absent une année comme zéro ("Absence ≠ zéro") - seule l'intersection des
  pays présents les deux années entre dans le calcul.
- Signe honnête : `reductionMt` négatif = hausse réelle des émissions, jamais tronqué à zéro.
- GreenAccess reste hors périmètre (aucune donnée n'existe - B2 "Bloqué").
- Décisions complètes : [`docs/superpowers/specs/2026-09-07-b32-mercure-realtime-kpis-design.md`](docs/superpowers/specs/2026-09-07-b32-mercure-realtime-kpis-design.md).

---

### Tâche 1 : `EmissionRepository` - années disponibles et totaux par pays

**Files:**
- Modify: `backend/src/Repository/EmissionRepository.php`
- Create: `backend/tests/Repository/EmissionRepositoryTest.php`

**Interfaces:**
- Produces: `distinctYearsWithCurrentData(): list<int>` (années `isCurrent = true`, triées
  décroissant), `totalsByYear(int $year): array<int, float>` (clé = id pays, valeur = somme
  `valueMt`, `isCurrent = true` uniquement).

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Repository/EmissionRepositoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Country;
use App\Entity\Emission;
use App\Entity\Enum\SourceReliability;
use App\Entity\Enum\SourceType;
use App\Entity\Enum\ValidationStatus;
use App\Entity\Source;
use App\Repository\EmissionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class EmissionRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private EmissionRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->repository = static::getContainer()->get(EmissionRepository::class);
        $this->entityManager->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $connection = $this->entityManager->getConnection();
        if ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
        $this->entityManager->close();
        parent::tearDown();
    }

    public function testDistinctYearsWithCurrentDataIgnoresHistorizedAndOrdersDescending(): void
    {
        $senegal = new Country('Senegal', 'SEN', "Afrique de l'Ouest");
        $source = new Source('Test Source', SourceType::InternalDemo, SourceReliability::Medium);
        $this->entityManager->persist($senegal);
        $this->entityManager->persist($source);

        $y2023 = new Emission($senegal, 2023, '10.500', $source, new \DateTimeImmutable('2023-06-01'), ValidationStatus::Demo);
        $y2024 = new Emission($senegal, 2024, '11.000', $source, new \DateTimeImmutable('2024-06-01'), ValidationStatus::Demo);
        $historizedY2025 = new Emission($senegal, 2025, '999.000', $source, new \DateTimeImmutable('2025-06-01'), ValidationStatus::Demo);
        $historizedY2025->setIsCurrent(false);
        foreach ([$y2023, $y2024, $historizedY2025] as $emission) {
            $this->entityManager->persist($emission);
        }
        $this->entityManager->flush();

        // 2025 has no *current* row - must not appear, even though a row for that year exists.
        self::assertSame([2024, 2023], $this->repository->distinctYearsWithCurrentData());
    }

    public function testTotalsByYearSumsPerCountryAndExcludesHistorizedRows(): void
    {
        $senegal = new Country('Senegal', 'SEN', "Afrique de l'Ouest");
        $kenya = new Country('Kenya', 'KEN', "Afrique de l'Est");
        $source = new Source('Test Source', SourceType::InternalDemo, SourceReliability::Medium);
        $this->entityManager->persist($senegal);
        $this->entityManager->persist($kenya);
        $this->entityManager->persist($source);

        $senegal2024 = new Emission($senegal, 2024, '11.000', $source, new \DateTimeImmutable('2024-06-01'), ValidationStatus::Demo);
        $kenya2024 = new Emission($kenya, 2024, '5.250', $source, new \DateTimeImmutable('2024-06-01'), ValidationStatus::Demo);
        $historizedSenegal2024 = new Emission($senegal, 2024, '999.000', $source, new \DateTimeImmutable('2024-01-01'), ValidationStatus::Demo);
        $historizedSenegal2024->setIsCurrent(false);
        foreach ([$senegal2024, $kenya2024, $historizedSenegal2024] as $emission) {
            $this->entityManager->persist($emission);
        }
        $this->entityManager->flush();

        $totals = $this->repository->totalsByYear(2024);

        self::assertEqualsWithDelta(11.0, $totals[$senegal->getId()], 0.001);
        self::assertEqualsWithDelta(5.25, $totals[$kenya->getId()], 0.001);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec backend php bin/phpunit tests/Repository/EmissionRepositoryTest.php`
Expected: FAIL - `Call to undefined method App\Repository\EmissionRepository::distinctYearsWithCurrentData()`.

- [ ] **Step 3: Implement both methods**

In `backend/src/Repository/EmissionRepository.php`, replace the whole class body:

```php
class EmissionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Emission::class);
    }

    /**
     * Years for which at least one *current* (non-historized) row exists,
     * most recent first - the candidate pool for B3.2's year-over-year
     * comparison (AnalyticsService::getCo2Reduction()).
     *
     * @return list<int>
     */
    public function distinctYearsWithCurrentData(): array
    {
        return array_map(
            static fn (array $row): int => (int) $row['year'],
            $this->createQueryBuilder('emission')
                ->select('DISTINCT emission.year AS year')
                ->where('emission.isCurrent = true')
                ->orderBy('emission.year', 'DESC')
                ->getQuery()
                ->getScalarResult(),
        );
    }

    /**
     * Total valueMt per country for a given year, current rows only.
     *
     * @return array<int, float> keyed by country id
     */
    public function totalsByYear(int $year): array
    {
        $rows = $this->createQueryBuilder('emission')
            ->select('IDENTITY(emission.country) AS countryId', 'SUM(emission.valueMt) AS total')
            ->where('emission.year = :year')
            ->andWhere('emission.isCurrent = true')
            ->groupBy('emission.country')
            ->setParameter('year', $year)
            ->getQuery()
            ->getScalarResult();

        return array_combine(
            array_map(static fn (array $row): int => (int) $row['countryId'], $rows),
            array_map(static fn (array $row): float => (float) $row['total'], $rows),
        );
    }
}
```

Also update the docblock above the class (currently says "No custom queries yet - ... not wired to
read from this table as part of this task" - no longer true, remove or rewrite that comment).

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec backend php bin/phpunit tests/Repository/EmissionRepositoryTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Repository/EmissionRepository.php backend/tests/Repository/EmissionRepositoryTest.php
git commit -m "feat(backend): B3.2 - EmissionRepository real aggregation queries

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
git pull --rebase
git push
```

---

### Tâche 2 : `AnalyticsService::getCo2Reduction()` - vrai calcul

**Files:**
- Modify: `backend/src/Service/AnalyticsService.php`
- Modify: `backend/tests/Controller/AnalyticsControllerTest.php`

**Interfaces:**
- Consumes: `EmissionRepository::distinctYearsWithCurrentData()`, `::totalsByYear()` (Tâche 1).
- Produces: `getCo2Reduction()` - même méthode, nouvelle forme de `data` quand `available: true`
  (voir Décision 3 de la spec).

- [ ] **Step 1: Write the failing test**

In `backend/tests/Controller/AnalyticsControllerTest.php`, add after
`testCo2ReductionExplicitlyReportsUnavailableRatherThanFabricatingAValue`:

```php
    public function testCo2ReductionComputesYearOverYearVariationOnCommonCountries(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);
        $container = static::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);

        $senegal = $entityManager->getRepository(Country::class)->findOneBy(['isoCode' => 'SEN']);
        $kenya = $entityManager->getRepository(Country::class)->findOneBy(['isoCode' => 'KEN']);
        $source = new Source('Emission Source', SourceType::InternalDemo, SourceReliability::Medium);
        $entityManager->persist($source);

        // Senegal: 20 -> 15 (reduction of 5). Kenya only has a 2024 row (no
        // 2025 row) - must be excluded from both sides of the comparison,
        // never treated as a Kenya reduction to 0.
        $entityManager->persist(new Emission($senegal, 2024, '20.000', $source, new \DateTimeImmutable('2024-06-01'), ValidationStatus::Demo));
        $entityManager->persist(new Emission($senegal, 2025, '15.000', $source, new \DateTimeImmutable('2025-06-01'), ValidationStatus::Demo));
        $entityManager->persist(new Emission($kenya, 2024, '8.000', $source, new \DateTimeImmutable('2024-06-01'), ValidationStatus::Demo));
        $entityManager->flush();
        $container->get('cache.analytics')->clear();

        $client->request('GET', '/api/analytics/co2-reduction');

        self::assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertTrue($data['available']);
        self::assertEqualsWithDelta(5.0, $data['data']['reductionMt'], 0.001);
        self::assertSame(2025, $data['data']['latestYear']);
        self::assertSame(2024, $data['data']['previousYear']);
        self::assertSame(1, $data['data']['countriesCompared']); // Senegal only - Kenya excluded
    }

    public function testCo2ReductionReportsNegativeValueOnARealIncrease(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);
        $container = static::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);

        $senegal = $entityManager->getRepository(Country::class)->findOneBy(['isoCode' => 'SEN']);
        $source = new Source('Emission Source', SourceType::InternalDemo, SourceReliability::Medium);
        $entityManager->persist($source);
        $entityManager->persist(new Emission($senegal, 2024, '10.000', $source, new \DateTimeImmutable('2024-06-01'), ValidationStatus::Demo));
        $entityManager->persist(new Emission($senegal, 2025, '14.000', $source, new \DateTimeImmutable('2025-06-01'), ValidationStatus::Demo));
        $entityManager->flush();
        $container->get('cache.analytics')->clear();

        $client->request('GET', '/api/analytics/co2-reduction');

        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertTrue($data['available']);
        // A real increase must be reported as a negative number, never
        // clamped to 0 or hidden.
        self::assertEqualsWithDelta(-4.0, $data['data']['reductionMt'], 0.001);
    }
```

Add `use App\Entity\Emission;` to the file's imports if not already present (check first -
`Funding` is already imported from the same namespace, `Emission` is not yet).

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec backend php bin/phpunit --filter testCo2ReductionComputesYearOverYearVariationOnCommonCountries`
Expected: FAIL - `available` is still `false` (hardcoded).

- [ ] **Step 3: Implement the real calculation**

In `backend/src/Service/AnalyticsService.php`:

1. Add the constructor dependency:

```php
    public function __construct(
        private readonly FundingRepository $fundingRepository,
        private readonly SectorRepository $sectorRepository,
        private readonly EmissionRepository $emissionRepository,
        #[Autowire(service: 'cache.analytics')]
        private readonly CacheInterface $cache,
    ) {
    }
```

(add `use App\Repository\EmissionRepository;` to the imports.)

2. Replace `getCo2Reduction()`:

```php
    /**
     * B3.2: year-over-year variation in total CO2 emissions (Mt), computed
     * only over countries with a *current* Emission row in both of the two
     * most recent years with any data - a country missing one of the two
     * years is excluded from both sides, never treated as a drop to zero
     * ("Absence ≠ zéro", cahier des charges 6.4). Positive = a real
     * reduction; negative = a real increase, reported honestly rather than
     * clamped. Falls back to the same {available: false} shape as before
     * when fewer than 2 years of data exist, or no country has data in
     * both of the 2 most recent years.
     *
     * @return array{available: bool, data: array{reductionMt: float, latestYear: int, previousYear: int, countriesCompared: int}|null, reason: string|null}
     */
    public function getCo2Reduction(): array
    {
        return $this->cache->get(self::CACHE_KEY_CO2_REDUCTION, function (ItemInterface $item): array {
            $item->expiresAfter(self::CACHE_TTL_SECONDS);

            $years = $this->emissionRepository->distinctYearsWithCurrentData();
            if (count($years) < 2) {
                return [
                    'available' => false,
                    'data' => null,
                    'reason' => 'Moins de deux années de données d\'émissions disponibles pour calculer une variation.',
                ];
            }

            [$latestYear, $previousYear] = [$years[0], $years[1]];
            $latestTotals = $this->emissionRepository->totalsByYear($latestYear);
            $previousTotals = $this->emissionRepository->totalsByYear($previousYear);
            $commonCountryIds = array_intersect_key($latestTotals, $previousTotals);

            if ([] === $commonCountryIds) {
                return [
                    'available' => false,
                    'data' => null,
                    'reason' => sprintf('Aucun pays avec une donnée d\'émissions à la fois en %d et %d.', $previousYear, $latestYear),
                ];
            }

            $latestSum = array_sum(array_intersect_key($latestTotals, $commonCountryIds));
            $previousSum = array_sum(array_intersect_key($previousTotals, $commonCountryIds));

            return [
                'available' => true,
                'data' => [
                    'reductionMt' => round($previousSum - $latestSum, 3),
                    'latestYear' => $latestYear,
                    'previousYear' => $previousYear,
                    'countriesCompared' => count($commonCountryIds),
                ],
                'reason' => null,
            ];
        });
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec backend php bin/phpunit --filter AnalyticsControllerTest`
Expected: all pass, including the 2 new tests and the pre-existing
`testCo2ReductionExplicitlyReportsUnavailableRatherThanFabricatingAValue` (no Emission rows seeded
there → still `available: false`, now for the real reason "moins de deux années...").

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/AnalyticsService.php backend/tests/Controller/AnalyticsControllerTest.php
git commit -m "feat(backend): B3.2 - real getCo2Reduction() from Emission data

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
git pull --rebase
git push
```

---

### Tâche 3 : Documentation Swagger

**Files:**
- Modify: `backend/src/Controller/AnalyticsController.php`

**Interfaces:** aucun changement de code fonctionnel - documentation seulement.

- [ ] **Step 1: Mettre à jour l'attribut `#[OA\Get]` de `co2Reduction()`**

```php
    #[Route('/api/analytics/co2-reduction', name: 'api_analytics_co2_reduction', methods: ['GET'])]
    #[OA\Get(
        summary: 'Variation CO2 d\'une année sur l\'autre (mis en cache 15 min)',
        description: 'Variation totale des émissions entre les deux années les plus récentes disposant de données (B1.4/B3.2), calculée uniquement sur les pays ayant une donnée les deux années (jamais un pays manquant traité comme zéro). `reductionMt` positif = réduction réelle, négatif = hausse réelle - jamais tronqué. Retourne `available: false` si moins de 2 années de données existent, ou aucun pays commun entre les deux années les plus récentes. Réponse servie depuis un cache Redis dédié, TTL 900 secondes.',
        tags: ['Analytics'],
        security: [],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Variation CO2 disponible ou indisponibilité explicite',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'available', type: 'boolean', example: true),
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            nullable: true,
                            properties: [
                                new OA\Property(property: 'reductionMt', type: 'number', format: 'float', example: 12.7),
                                new OA\Property(property: 'latestYear', type: 'integer', example: 2025),
                                new OA\Property(property: 'previousYear', type: 'integer', example: 2024),
                                new OA\Property(property: 'countriesCompared', type: 'integer', example: 28),
                            ]
                        ),
                        new OA\Property(property: 'reason', type: 'string', nullable: true, example: null),
                    ]
                )
            ),
        ]
    )]
    public function co2Reduction(): JsonResponse
    {
        return $this->json($this->analyticsService->getCo2Reduction());
    }
```

- [ ] **Step 2: Vérifier que la suite de tests Swagger existante passe toujours**

Run: `docker compose exec backend php bin/phpunit --filter testAnalyticsEndpointsAreDocumentedInSwagger`
Expected: PASS (le test ne vérifie que la présence du path/verbe, pas la forme exacte du schéma).

- [ ] **Step 3: Commit**

```bash
git add backend/src/Controller/AnalyticsController.php
git commit -m "docs(backend): B3.2 - update co2-reduction Swagger schema

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
git pull --rebase
git push
```

---

### Tâche 4 : Mercure - ajouter `co2ReductionMt` au payload

**Files:**
- Modify: `backend/src/Command/PublishKpiSnapshotCommand.php`
- Modify: `backend/tests/Command/PublishKpiSnapshotCommandTest.php`

**Interfaces:**
- Consumes: `AnalyticsService::getCo2Reduction()` (Tâche 2).
- Produces: payload Mercure enrichi d'un champ `co2ReductionMt` (float ou `null`), même topic,
  même cadence.

- [ ] **Step 1: Write the failing test**

In `backend/tests/Command/PublishKpiSnapshotCommandTest.php`, add after
`testOncePublishesASnapshotMatchingTheRealAnalyticsAggregates`:

```php
    public function testSnapshotIncludesCo2ReductionWhenEmissionDataExists(): void
    {
        $senegal = $this->entityManager->getRepository(Country::class)->findOneBy(['isoCode' => 'SEN']);
        $kenya = $this->entityManager->getRepository(Country::class)->findOneBy(['isoCode' => 'KEN']);
        $source = new \App\Entity\Source('Emission Source', SourceType::InternalDemo, SourceReliability::Medium);
        $this->entityManager->persist($source);
        $this->entityManager->persist(new \App\Entity\Emission($senegal, 2024, '20.000', $source, new \DateTimeImmutable('2024-06-01'), ValidationStatus::Demo));
        $this->entityManager->persist(new \App\Entity\Emission($senegal, 2025, '15.000', $source, new \DateTimeImmutable('2025-06-01'), ValidationStatus::Demo));
        $this->entityManager->flush();

        $published = null;
        $hub = $this->createMock(HubInterface::class);
        $hub->method('publish')->willReturnCallback(function (Update $update) use (&$published): string {
            $published = $update;

            return 'mock-id';
        });

        $command = new PublishKpiSnapshotCommand(static::getContainer()->get(AnalyticsService::class), $hub);
        $application = new Application(self::$kernel);
        $application->addCommand($command);
        (new CommandTester($application->find('app:mercure:publish-kpis')))->execute(['--once' => true]);

        $payload = json_decode($published->getData(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertEqualsWithDelta(5.0, $payload['co2ReductionMt'], 0.001);
    }

    public function testSnapshotHasNullCo2ReductionWhenNoEmissionData(): void
    {
        $published = null;
        $hub = $this->createMock(HubInterface::class);
        $hub->method('publish')->willReturnCallback(function (Update $update) use (&$published): string {
            $published = $update;

            return 'mock-id';
        });

        $command = new PublishKpiSnapshotCommand(static::getContainer()->get(AnalyticsService::class), $hub);
        $application = new Application(self::$kernel);
        $application->addCommand($command);
        (new CommandTester($application->find('app:mercure:publish-kpis')))->execute(['--once' => true]);

        $payload = json_decode($published->getData(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('co2ReductionMt', $payload);
        self::assertNull($payload['co2ReductionMt']);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec backend php bin/phpunit --filter PublishKpiSnapshotCommandTest`
Expected: FAIL - `co2ReductionMt` key does not exist in the payload yet.

- [ ] **Step 3: Extend the payload**

In `backend/src/Command/PublishKpiSnapshotCommand.php`, modify `publishSnapshot()`:

```php
    private function publishSnapshot(SymfonyStyle $io): void
    {
        try {
            $trends = $this->analyticsService->getFinancingTrends();
            $heroStats = $this->analyticsService->getHeroStats();
            $co2Reduction = $this->analyticsService->getCo2Reduction();

            $fundingTotalUsd = array_sum(array_map(static fn (array $row): float => (float) $row['total'], $trends));

            $payload = json_encode([
                'fundingTotalUsd' => $fundingTotalUsd,
                'countriesCovered' => $heroStats['countriesCovered'],
                'co2ReductionMt' => $co2Reduction['available'] ? $co2Reduction['data']['reductionMt'] : null,
                'publishedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            ], \JSON_THROW_ON_ERROR);
```

(le reste de la méthode, `$this->hub->publish(...)` et le `catch`, ne change pas - mettre à jour
seulement le message `writeln` de succès si on veut y refléter le CO2, optionnel.)

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec backend php bin/phpunit --filter PublishKpiSnapshotCommandTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Command/PublishKpiSnapshotCommand.php backend/tests/Command/PublishKpiSnapshotCommandTest.php
git commit -m "feat(backend): B3.2 - publish co2ReductionMt over Mercure

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
git pull --rebase
git push
```

---

### Tâche 5 : Frontend - tuile CO2 réelle + badge "Direct"

**Files:**
- Modify: `frontend/src/react/pages/VisualizationsPage.jsx`

**Interfaces:** aucun test frontend n'existe dans ce dépôt (constat déjà fait pour A3.11) -
vérification par build + inspection visuelle réelle sur le Codespace.

- [ ] **Step 1: Réécrire `useCo2Reduction()`**

```jsx
/** Local hook for /api/analytics/co2-reduction - its {available: false} shape doesn't fit the generic empty/error/success chart-card pattern (it's a single value tile, not a chart), same as analytics.js's loadCo2Reduction(). B3.2: `data` is now a structured object ({reductionMt, latestYear, previousYear, countriesCompared}), never a bare number. */
function useCo2Reduction() {
  const { API_BASE_URL } = useAuth();
  const [value, setValue] = useState("…");
  const [note, setNote] = useState("Chargement…");

  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        const response = await fetch(API_BASE_URL + "/api/analytics/co2-reduction", { headers: { Accept: "application/json" } });
        if (!response.ok) {
          throw new Error("Une erreur est survenue (" + response.status + ").");
        }
        const body = await response.json();
        if (cancelled) {
          return;
        }
        if (!body.available) {
          setValue("-");
          setNote("Donnée non disponible.");
          return;
        }
        const { reductionMt, latestYear, previousYear, countriesCompared } = body.data;
        setValue(reductionMt.toLocaleString("fr-FR", { maximumFractionDigits: 1, signDisplay: "exceptZero" }));
        setNote(
          (reductionMt < 0 ? "Hausse des émissions - " : "") +
            previousYear + " → " + latestYear + " · " + countriesCompared + " pays comparés"
        );
      } catch (error) {
        if (!cancelled) {
          setValue("-");
          setNote(error.message);
        }
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [API_BASE_URL]);

  return { value, note };
}
```

- [ ] **Step 2: Brancher le badge "Direct" Mercure sur la tuile CO2**

Juste après la ligne existante `const fundingTotalUsd = mercureKpis.snapshot ? ... : null;`, ajouter :

```jsx
  const co2ReductionMt = mercureKpis.snapshot && null !== mercureKpis.snapshot.co2ReductionMt && undefined !== mercureKpis.snapshot.co2ReductionMt
    ? mercureKpis.snapshot.co2ReductionMt
    : null;
```

Remplacer la tuile CO2 existante (actuellement `<NevKpi label={...} value={co2.value} context={co2.note} state="success" />`) par :

```jsx
            <NevKpi
              label={t("vizPage.co2Reduction", "Réduction CO2 (M tonnes/an)")}
              value={null !== co2ReductionMt ? co2ReductionMt.toLocaleString("fr-FR", { maximumFractionDigits: 1, signDisplay: "exceptZero" }) : co2.value}
              context={co2.note}
              state="success"
              liveBadge={mercureKpis.connected && null !== co2ReductionMt}
              liveLabel="Direct"
            />
```

(même patron exact que la tuile financement juste au-dessus - `liveBadge`/`liveLabel` déjà des
props existantes de `NevKpi`, aucun changement du composant lui-même nécessaire.)

- [ ] **Step 3: Build et vérification visuelle sur le Codespace**

```bash
cd frontend && npm run build
```
Expected: build sans erreur. Puis vérifier dans un navigateur réel (port forwardé) que
`visualizations.html` affiche un chiffre réel (pas "-") sur la tuile CO2 une fois que
`GET /api/analytics/co2-reduction` retourne `available: true`, et que le badge "Direct" apparaît
une fois le hub Mercure connecté (même comportement visuel que la tuile financement, déjà vérifiée
en A3.2).

- [ ] **Step 4: Commit**

```bash
git add frontend/src/react/pages/VisualizationsPage.jsx
git commit -m "feat(frontend): B3.2 - real CO2 reduction tile + Mercure Direct badge

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
git pull --rebase
git push
```

---

### Tâche 6 : Vérification bout-en-bout, données réelles, documentation

**Files:**
- Modify: `README.md`

**Interfaces:** n/a - vérification et documentation, aucun nouveau code.

- [ ] **Step 1: Suite complète PHPUnit avant/après**

Run: `docker compose exec -e APP_ENV=test backend php bin/phpunit 2>&1 | tail -20`
Expected: aucune régression au-delà de ce qui est déjà documenté comme préexistant/non lié.

- [ ] **Step 2: Vérifier que le flux financement Mercure est déjà réel (Contexte de la spec, point 2)**

```bash
curl -s "http://localhost:8080/.well-known/mercure?topic=https://nev-climate-data.local/kpis/analytics" -N | head -1
```
Comparer `fundingTotalUsd` avec `SELECT sum(amount) FROM funding WHERE is_current = true;` (SQL
direct). Expected : valeurs identiques - confirme qu'aucun changement de code n'était nécessaire
pour la partie financement de B3.2.

- [ ] **Step 3: Vérifier le CO2 en direct sur les vraies données PNUE**

```bash
docker compose exec database psql -U nev_admin -d nev_climate_data -c \
  "SELECT year, sum(value_mt) FROM emission WHERE is_current = true GROUP BY year ORDER BY year DESC LIMIT 5;"
```
Comparer avec `curl -s http://localhost:8080/api/analytics/co2-reduction | jq`. Expected : le
`reductionMt` retourné correspond à la différence entre les deux années les plus récentes ayant
des données (calcul à refaire à la main sur l'intersection des pays pour confirmer).

- [ ] **Step 4: Nouvelle section README**

Ajouter une sous-section "B3.2 — Diffusion des KPIs réels via Mercure" (près de la section
Pipeline/Volet B existante), documentant : le rebranchement CO2 réel (définition retenue, exemple
de valeur réelle constatée), la confirmation que le flux financement était déjà réel, et
l'exclusion explicite de GreenAccess. Mettre à jour le tableau "État d'avancement" (ligne B3.2 :
✅ Fait).

- [ ] **Step 5: Commit**

```bash
git add README.md
git commit -m "docs: B3.2 - vérification bout-en-bout et documentation

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
git pull --rebase
git push
```
