# B1.7 — Filtrer isCurrent côté lecture - Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Faire en sorte que tout endpoint qui lit des données `Funding` (listing, export,
agrégats analytics, stats du Hero) ne retourne/somme que les lignes courantes (`isCurrent =
true`), jamais les versions historisées.

**Architecture:** Un `->andWhere('funding.isCurrent = true')`/`->where(...)` ajouté à chaque
requête de `FundingRepository` qui lit `Funding`, plus un `count(['isCurrent' => true])` dans
`AnalyticsService::getHeroStats()`.

**Tech Stack:** PHP 8.4/Symfony, Doctrine ORM QueryBuilder, PHPUnit (`WebTestCase`).

## Global Constraints

- Aucun changement de schéma, aucune migration.
- Aucun changement de forme de réponse API - `isCurrent` reste un champ interne jamais exposé.
- Le filtre est **toujours actif**, pas un paramètre optionnel de l'API.
- `EmissionRepository` n'est pas concerné (aucune requête personnalisée, hors périmètre).

---

### Tâche 1 : `FundingRepository` - filtrer `isCurrent` partout

**Files:**
- Modify: `backend/src/Repository/FundingRepository.php`
- Modify: `backend/tests/Controller/FundingControllerTest.php`

**Interfaces:**
- Produces: `criteriaQueryBuilder()`, `findFinancingTrendsAggregate()`,
  `findSectorDistributionAggregate()`, `countDistinctCountries()`, `countDistinctSources()` -
  mêmes signatures, mêmes types de retour, résultats désormais limités aux lignes courantes.

- [ ] **Step 1: Write the failing test**

In `backend/tests/Controller/FundingControllerTest.php`, add after
`testResponseShapeMatchesContractAndHidesInternalFields` (à la fin du fichier) :

```php
    public function testHistorizedRowsAreExcludedFromListingsAndCounts(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);

        // A superseded (historized) row satisfying the same sector filter as
        // Group C (Kenya/Agriculture/2024/private, 10 records) - must never be
        // counted or listed, even though it matches every filter below.
        $kenya = $this->entityManager->getRepository(Country::class)->findOneBy(['isoCode' => 'KEN']);
        $agriculture = $this->entityManager->getRepository(Sector::class)->findOneBy(['name' => 'Agriculture']);
        $source = new Source('Test Source Historized', SourceType::InternalDemo, SourceReliability::Medium);
        $this->entityManager->persist($source);
        $historized = new Funding(
            $kenya,
            $agriculture,
            2024,
            '9999999.00',
            FundingType::Private,
            $source,
            new \DateTimeImmutable('2024-06-01'),
            ValidationStatus::Demo,
        );
        $historized->setIsCurrent(false);
        $this->entityManager->persist($historized);
        $this->entityManager->flush();

        $client->request('GET', '/api/funding?sector='.$this->sectorId('Agriculture').'&limit=100');

        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(10, $data['meta']['total']); // unchanged - the historized row never counts
        foreach ($data['data'] as $item) {
            self::assertNotSame('9999999.00', $item['amount']);
        }
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec backend php bin/phpunit --filter testHistorizedRowsAreExcludedFromListingsAndCounts`
Expected: FAIL - `$data['meta']['total']` is `11`, not `10` (the historized row is currently
counted and listed).

- [ ] **Step 3: Filter `isCurrent` in every read method**

In `backend/src/Repository/FundingRepository.php`, modify `criteriaQueryBuilder()`:

```php
    private function criteriaQueryBuilder(FundingSearchCriteria $criteria): QueryBuilder
    {
        $qb = $this->createQueryBuilder('funding')
            ->join('funding.country', 'country')
            ->andWhere('funding.isCurrent = true');
```

(le reste de la méthode, à partir de `if (null !== $criteria->countryIsoCode) {`, ne change pas.)

Modify `findFinancingTrendsAggregate()`:

```php
    public function findFinancingTrendsAggregate(): array
    {
        return $this->createQueryBuilder('funding')
            ->select('funding.year AS year', 'funding.fundingType AS fundingType', 'SUM(funding.amount) AS total')
            ->where('funding.isCurrent = true')
            ->groupBy('funding.year')
            ->addGroupBy('funding.fundingType')
            ->orderBy('funding.year', 'ASC')
            ->addOrderBy('funding.fundingType', 'ASC')
            ->getQuery()
            ->getResult();
    }
```

Modify `findSectorDistributionAggregate()`:

```php
    public function findSectorDistributionAggregate(): array
    {
        return $this->createQueryBuilder('funding')
            ->select('sector.id AS sectorId', 'sector.name AS sectorName', 'SUM(funding.amount) AS total')
            ->join('funding.sector', 'sector')
            ->where('funding.isCurrent = true')
            ->groupBy('sector.id')
            ->addGroupBy('sector.name')
            ->orderBy('total', 'DESC')
            ->addOrderBy('sector.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
```

Modify `countDistinctCountries()`:

```php
    public function countDistinctCountries(): int
    {
        return (int) $this->createQueryBuilder('funding')
            ->select('COUNT(DISTINCT funding.country)')
            ->where('funding.isCurrent = true')
            ->getQuery()
            ->getSingleScalarResult();
    }
```

Modify `countDistinctSources()`:

```php
    public function countDistinctSources(): int
    {
        return (int) $this->createQueryBuilder('funding')
            ->select('COUNT(DISTINCT funding.source)')
            ->where('funding.isCurrent = true')
            ->getQuery()
            ->getSingleScalarResult();
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec backend php bin/phpunit --filter testHistorizedRowsAreExcludedFromListingsAndCounts`
Expected: PASS.

- [ ] **Step 5: Run the full FundingControllerTest suite to confirm no regression**

Run: `docker compose exec backend php bin/phpunit --filter FundingControllerTest`
Expected: every test in the file PASS (the `isCurrent` filter doesn't change any existing
assertion - `seedDataset()` never sets `isCurrent = false` on any of its 25 rows, so every
existing count/filter test keeps returning exactly what it did before).

- [ ] **Step 6: Commit**

```bash
git add backend/src/Repository/FundingRepository.php backend/tests/Controller/FundingControllerTest.php
git commit -m "fix: exclude historized Funding rows from listing, search, and export"
git pull --rebase
git push
```

---

### Tâche 2 : `AnalyticsService` - stats du Hero et agrégats

**Files:**
- Modify: `backend/src/Service/AnalyticsService.php`
- Modify: `backend/tests/Controller/AnalyticsControllerTest.php`

**Interfaces:**
- Consumes: `findFinancingTrendsAggregate()`, `findSectorDistributionAggregate()`,
  `countDistinctCountries()`, `countDistinctSources()` de la Tâche 1 (déjà filtrées).
- Produces: `getHeroStats()` - même signature, `fundingRecords` désormais limité aux lignes
  courantes.

- [ ] **Step 1: Write the failing test**

In `backend/tests/Controller/AnalyticsControllerTest.php`, add after
`testSecondRequestIsServedFromCacheEvenAfterTheUnderlyingDataChanges` (à la fin du fichier) :

```php
    public function testHistorizedRowsAreExcludedFromEveryAggregate(): void
    {
        $client = static::createClient();
        $this->seedDataset($client);

        // A superseded (historized) row sharing the exact same dedup key as
        // an existing current row from seedDataset() (Senegal/Renewable
        // Energy/2024/public/"Test Source") - a real revision scenario, not
        // a hypothetical one (see the 2026-08-31 idempotency fix spec). Its
        // large amount would visibly change every aggregate below if it
        // weren't excluded.
        $senegal = $this->entityManager->getRepository(Country::class)->findOneBy(['isoCode' => 'SEN']);
        $renewableEnergy = $this->entityManager->getRepository(Sector::class)->findOneBy(['name' => 'Renewable Energy']);
        $source = $this->entityManager->getRepository(Source::class)->findOneBy(['name' => 'Test Source']);
        $historized = new Funding($senegal, $renewableEnergy, 2024, '999999.00', FundingType::Public, $source, new \DateTimeImmutable('2024-03-15'), ValidationStatus::Demo);
        $historized->setIsCurrent(false);
        $this->entityManager->persist($historized);
        $this->entityManager->flush();
        static::getContainer()->get('cache.analytics')->clear();

        $client->request('GET', '/api/analytics/financing-trends');
        $data = json_decode($client->getResponse()->getContent(), true)['data'];
        self::assertEquals(300.0, $data[0]['public']); // unchanged, not 999999 + 300

        $client->request('GET', '/api/analytics/sector-distribution');
        $sectorData = json_decode($client->getResponse()->getContent(), true)['data'];
        self::assertEquals(700.0, $sectorData[0]['amount']); // unchanged, not 999999 + 700

        $client->request('GET', '/api/analytics/hero-stats');
        $heroData = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(3, $heroData['fundingRecords']); // unchanged, not 4
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose exec backend php bin/phpunit --filter testHistorizedRowsAreExcludedFromEveryAggregate`
Expected: FAIL - `financing-trends`'s `public` is `1299.0` (999999 + 300) and `hero-stats`'s
`fundingRecords` is `4`, not `3`.

- [ ] **Step 3: Fix `getHeroStats()`**

In `backend/src/Service/AnalyticsService.php`, modify `getHeroStats()`'s inner callback:

```php
            return [
                'countriesCovered' => $this->fundingRepository->countDistinctCountries(),
                'sectorsTracked' => $this->sectorRepository->count([]),
                'fundingRecords' => $this->fundingRepository->count(['isCurrent' => true]),
                'activeSources' => $this->fundingRepository->countDistinctSources(),
            ];
```

(seule la ligne `'fundingRecords' => ...` change - `count()` est la méthode héritée de
`ServiceEntityRepository`, qui construit son propre `WHERE` à partir du tableau de critères passé.)

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose exec backend php bin/phpunit --filter testHistorizedRowsAreExcludedFromEveryAggregate`
Expected: PASS (le `financing-trends`/`sector-distribution` passent déjà grâce à la Tâche 1 -
seul `fundingRecords` nécessitait ce changement dans `AnalyticsService`).

- [ ] **Step 5: Run the full AnalyticsControllerTest suite to confirm no regression**

Run: `docker compose exec backend php bin/phpunit --filter AnalyticsControllerTest`
Expected: every test in the file PASS (même raisonnement que la Tâche 1 - `seedDataset()` ne crée
aucune ligne `isCurrent = false`).

- [ ] **Step 6: Commit**

```bash
git add backend/src/Service/AnalyticsService.php backend/tests/Controller/AnalyticsControllerTest.php
git commit -m "fix: exclude historized Funding rows from hero-stats fundingRecords count"
git pull --rebase
git push
```

---

### Tâche 3 : Vérification complète, données réelles, documentation

**Files:**
- Modify: `README.md`

**Interfaces:** n/a - vérification et documentation, aucun nouveau code.

- [ ] **Step 1: Baseline PHPUnit avant/après**

Run: `docker compose exec backend php bin/phpunit 2>&1 | tail -20`
Comparer le résultat avec la baseline documentée dans `docs/known-issues-backend-phpunit.md` (155
tests, 75 erreurs, 2 échecs au moment de sa rédaction - le nombre total de tests aura grandi avec
les 2 nouveaux tests de ce plan, mais le nombre d'erreurs/échecs préexistants et non liés à ce
travail ne doit pas augmenter).

- [ ] **Step 2: Vérifier en direct sur les vraies données**

Run:
```bash
docker compose exec database psql -U nev_admin -d nev_climate_data -c "SELECT sum(amount) FROM funding WHERE is_current = true;"
```
Puis comparer avec la vraie réponse de l'API (le serveur `backend` doit avoir été redémarré ou
avoir rechargé le code changé - vérifier que le cache Redis Analytics ne sert pas une réponse
mise en cache avant ce correctif) :
```bash
docker compose exec backend php bin/console cache:pool:clear cache.analytics
curl -s http://localhost:8000/api/analytics/financing-trends | python3 -c "import json,sys; data=json.load(sys.stdin)['data']; print(sum(row['total'] for row in data))"
```
Expected: les deux valeurs correspondent exactement (à la conversion près) - la somme SQL directe
des lignes courantes, et la somme retournée par l'API, doivent maintenant être identiques (avant
ce correctif, l'API aurait retourné ~526 milliards au lieu de ~319,5 milliards).

- [ ] **Step 3: Ajouter un nouveau point d'attention**

Dans la section "Points d'attention" de `README.md`, ajouter (numéro 36) :

```markdown
36. **Une colonne d'historisation (`isCurrent`) ne protège rien si aucune requête de lecture ne la filtre.** Bug réel de production trouvé le 2026-08-31 en creusant B1.7 (le pendant lecture du bug de double comptage du point 35) : `FundingRepository` ne filtrait `isCurrent = true` nulle part - ni dans le listing/recherche/export (`FundingController`), ni dans les agrégats analytics (`findFinancingTrendsAggregate`, `findSectorDistributionAggregate`), ni dans les stats du Hero (`countDistinctCountries`, `count([])`, `countDistinctSources`). Aucun filtre Doctrine global ne le faisait non plus. Résultat mesuré en direct : la somme de toutes les lignes `Funding` (courantes + historisées) était de 526 614 769 743, contre 319 543 983 336 pour les lignes courantes seules - un gonflement d'environ 65% sur tous les chiffres affichés par le dashboard (graphiques, stats du Hero, tableau/export). Corrigé en ajoutant `->where('funding.isCurrent = true')` (ou `count(['isCurrent' => true])`) à chaque requête de lecture de `Funding` - décisions complètes : [`docs/superpowers/specs/2026-08-31-b17-current-funding-filter-design.md`](docs/superpowers/specs/2026-08-31-b17-current-funding-filter-design.md). Une colonne d'historisation SCD2 n'est une garantie que si elle est appliquée aux deux bouts - l'écriture (qui la peuple) ET la lecture (qui doit la respecter) - vérifier systématiquement les deux avant de considérer un mécanisme d'historisation "terminé".
```

- [ ] **Step 4: Ajouter une sous-section README**

Chercher la section pertinente pour le backend/API (ex. près de "## Données de financement
(A2.1)" ou de la documentation Analytics existante) et ajouter :

```markdown
### Correction du filtrage isCurrent (B1.7, 2026-08-31)

Un vrai bug de production a été trouvé en creusant B1.7 (gestion des conflits entre sources et
historisation) : aucune requête de lecture de `Funding` ne filtrait `isCurrent = true`, mélangeant
lignes courantes et versions historisées dans le listing, l'export CSV, les agrégats analytics
(tendances de financement, répartition sectorielle) et les stats du Hero - un gonflement mesuré
d'environ 65% sur tous les chiffres affichés. Décisions complètes :
[`docs/superpowers/specs/2026-08-31-b17-current-funding-filter-design.md`](docs/superpowers/specs/2026-08-31-b17-current-funding-filter-design.md).

Corrigé dans `FundingRepository` (chaque méthode de lecture) et `AnalyticsService::getHeroStats()`
- le mécanisme d'historisation écrit par les processors Volet B (`upsert_funding()`) est
maintenant respecté de bout en bout par l'API qui le lit.
```

- [ ] **Step 5: Commit**

```bash
git add README.md
git commit -m "docs: document the isCurrent read-side bug and its fix (B1.7)"
git pull --rebase
git push
```
