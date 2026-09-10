# B3.3 - Bascule démo → réel - Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:executing-plans. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Le frontend du Volet A ne voit plus jamais les lignes `validation_status = 'demo'` (jeu
A1.6) - uniquement les données validées par le pipeline Volet B. Un filtre systématique côté
lecture, jumeau de celui de B1.7 pour `is_current`. Aucun changement de contrat API, aucun
changement frontend.

**Architecture:** `FundingRepository::onlyValidated()` (nouveau helper privé, `andWhere
validationStatus = :validated`) appliqué à chaque requête de lecture ; `EmissionRepository` reçoit
le même `andWhere` inline ; `AnalyticsService::getHeroStats()` passe
`'validationStatus' => ValidationStatus::Validated` à `count()`.

**Tech Stack:** PHP 8.4 / Symfony, Doctrine QueryBuilder, PHPUnit.

**Décisions complètes :**
[`docs/superpowers/specs/2026-09-08-b33-demo-to-real-switch-design.md`](docs/superpowers/specs/2026-09-08-b33-demo-to-real-switch-design.md).

## Global Constraints

- Liste blanche (`= 'validated'`), pas liste noire.
- Filtre toujours actif, jamais un paramètre d'API ni un flag d'environnement.
- Hors périmètre : `report`, `notification` (aucune source réelle), `source`/`country`/`sector`
  (données de référence partagées, pas « démo »), la recherche globale (ne lit pas de lignes
  `funding`).
- Aucune migration, aucun changement de schéma (`validation_status` existe depuis A1.3).

---

### Tâche 1 : `FundingRepository` + `EmissionRepository` + `AnalyticsService`

**Files:**
- Modify: `backend/src/Repository/FundingRepository.php`
- Modify: `backend/src/Repository/EmissionRepository.php`
- Modify: `backend/src/Service/AnalyticsService.php`

- [ ] **Step 1** - `FundingRepository` : ajouter le helper privé `onlyValidated(QueryBuilder $qb,
  string $alias = 'funding'): QueryBuilder` (`andWhere('%s.validationStatus = :validated')` +
  `setParameter('validated', ValidationStatus::Validated)`), `use App\Entity\Enum\ValidationStatus`.
- [ ] **Step 2** - l'appliquer à `criteriaQueryBuilder()`, `findFinancingTrendsAggregate()`,
  `findSectorDistributionAggregate()`, `findCountryDistributionAggregate()`,
  `countDistinctCountries()`, `countDistinctSources()`.
- [ ] **Step 3** - `EmissionRepository` : `andWhere('emission.validationStatus = :validated')` +
  `setParameter` dans `distinctYearsWithCurrentData()` et `totalsByYear()`.
- [ ] **Step 4** - `AnalyticsService::getHeroStats()` :
  `count(['isCurrent' => true, 'validationStatus' => ValidationStatus::Validated])`.

### Tâche 2 : Tests

**Files:**
- Modify: `backend/tests/Controller/FundingControllerTest.php`,
  `backend/tests/Controller/AnalyticsControllerTest.php`,
  `backend/tests/Controller/FundingExportTest.php`,
  `backend/tests/Command/PublishKpiSnapshotCommandTest.php`,
  `backend/tests/Repository/EmissionRepositoryTest.php`

- [ ] **Step 1** - remplacer `ValidationStatus::Demo` → `ValidationStatus::Validated` dans les
  helpers de seed des 5 fichiers (les assertions existantes testent le comportement réel : après
  B3.3, « ce que le frontend voit » = données validées).
- [ ] **Step 2** - nouveaux tests d'exclusion :
  - `FundingControllerTest::testDemoRowsAreExcludedFromListingsAndCounts`
  - `AnalyticsControllerTest::testDemoRowsAreExcludedFromEveryAggregate`
  - `EmissionRepositoryTest::testDemoRowsAreExcludedFromBothQueries`
- [ ] **Step 3** - `docker compose exec -e APP_ENV=test backend php -d memory_limit=512M
  bin/phpunit` : suite complète verte, aucune régression au-delà des écarts documentés.

### Tâche 3 : Vérification en direct + documentation

- [ ] **Step 1** - Codespace : `curl /api/analytics/hero-stats` doit passer de
  `fundingRecords: 4085` à `3005` ; `SELECT count(*) FROM funding WHERE is_current AND
  validation_status='validated'` = somme retournée par `financing-trends`.
- [ ] **Step 2** - `README.md` : sous-section « B3.3 - Bascule démo → réel », nouveau point
  d'attention (filtre `validation_status`, jumeau de B1.7), tableau « État d'avancement » (B3.3
  ✅), note dans la checklist de reprise (« ne pas charger les fixtures sur un environnement qui
  sert du réel »).
- [ ] **Step 3** - commit + push (GitLab canonique + miroir).
