<?php

declare(strict_types=1);

namespace App\Repository;

use App\Dto\CountryDistributionCriteria;
use App\Dto\FundingFlowCriteria;
use App\Dto\FundingSearchCriteria;
use App\Entity\Enum\FinancingTrendsGroupBy;
use App\Entity\Enum\FundingInstrument;
use App\Entity\Enum\ValidationStatus;
use App\Entity\Funding;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
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

    /**
     * B3.3: every read path the frontend consumes excludes demonstration
     * rows (`validation_status = 'demo'`, A1.6 fixtures) - only pipeline
     * data validated by the Volet B processors ('validated') is ever
     * served. Whitelist (`= 'validated'`), not blacklist: a future status
     * would not leak by accident. Always on, never an API parameter - same
     * discipline as the `is_current = true` filter (B1.7). `alias` is the
     * root alias used by the calling query builder.
     */
    private function onlyValidated(QueryBuilder $qb, string $alias = 'funding'): QueryBuilder
    {
        return $qb
            ->andWhere(sprintf('%s.validationStatus = :validated', $alias))
            ->setParameter('validated', ValidationStatus::Validated);
    }

    /**
     * @return list<Funding>
     */
    public function findByCriteria(FundingSearchCriteria $criteria): array
    {
        return $this->criteriaQueryBuilder($criteria)
            ->addSelect('country')
            ->join('funding.sector', 'sector')->addSelect('sector')
            ->join('funding.source', 'source')->addSelect('source')
            ->orderBy('funding.collectionDate', 'DESC')
            ->addOrderBy('funding.id', 'DESC')
            ->setFirstResult(($criteria->page - 1) * $criteria->limit)
            ->setMaxResults($criteria->limit)
            ->getQuery()
            ->getResult();
    }

    public function countByCriteria(FundingSearchCriteria $criteria): int
    {
        return (int) $this->criteriaQueryBuilder($criteria)
            ->select('COUNT(funding.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Sum of `amount` (the USD pivot column, DECIMAL(15,2)) across every row
     * matching the same filters as findByCriteria()/countByCriteria() - not
     * just the current page (2026-09-29, DataPage's "total filtré"). Shares
     * criteriaQueryBuilder() with those two so the sum can never drift from
     * what the paginated list/count actually cover. COALESCE(..., 0):
     * SUM() over zero matching rows is SQL NULL, and this total is always
     * meaningful as "0.00" for an empty result, never absent. Returned as
     * a string, like Funding::$amount itself - PHP float would risk
     * precision loss on a DECIMAL(15,2) column summed across many rows.
     */
    public function sumAmountByCriteria(FundingSearchCriteria $criteria): string
    {
        return (string) $this->criteriaQueryBuilder($criteria)
            ->select('COALESCE(SUM(funding.amount), 0) AS totalAmount')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Every row matching the same filters as findByCriteria(), ignoring
     * $criteria->page/limit (A2.3 exports the whole filtered result set, not
     * one page of it) - everything else is shared with findByCriteria() via
     * criteriaQueryBuilder() so export and listing can never apply a
     * different filter for the same query string. toIterable() rather than
     * getResult(): a CSV export streams rows out one at a time, so there is
     * no reason to hold the full result set as hydrated entities in memory
     * at once.
     *
     * @return iterable<Funding>
     */
    public function streamByCriteria(FundingSearchCriteria $criteria): iterable
    {
        return $this->criteriaQueryBuilder($criteria)
            ->addSelect('country')
            ->join('funding.sector', 'sector')->addSelect('sector')
            ->join('funding.source', 'source')->addSelect('source')
            ->orderBy('funding.collectionDate', 'DESC')
            ->addOrderBy('funding.id', 'DESC')
            ->getQuery()
            ->toIterable();
    }

    /**
     * A2.5 (financing-trends aggregate). One row per (year, fundingType)
     * combination, summed in SQL (not in PHP) - never loads Funding rows
     * individually. Ordered by year then fundingType purely so the SQL
     * output is stable/reproducible for a given dataset; the actual
     * per-year reshaping into {public, private, multilateral, total} is
     * App\Service\AnalyticsService's job, not this repository's.
     *
     * @return list<array{year: int, fundingType: string, total: string}>
     */
    public function findFinancingTrendsAggregate(): array
    {
        return $this->onlyValidated($this->createQueryBuilder('funding')
            ->select('funding.year AS year', 'funding.fundingType AS fundingType', 'SUM(funding.amount) AS total')
            ->where('funding.isCurrent = true'))
            ->groupBy('funding.year')
            ->addGroupBy('funding.fundingType')
            ->orderBy('funding.year', 'ASC')
            ->addOrderBy('funding.fundingType', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * C1.6: same data as findFinancingTrendsAggregate() (one row per year,
     * summed in SQL), broken down by an arbitrary dimension instead of the
     * hardcoded fundingType - "groupKey" is that dimension's own label
     * (sector name, instrument value, country ISO code, or fundingType
     * value), shaped identically regardless of which one was requested so
     * AnalyticsService::computeFinancingTrendsGroupedBy() can reshape any
     * of the 4 the same way.
     *
     * @return list<array{year: int, groupKey: string, total: string}>
     */
    public function findFinancingTrendsAggregateGroupedBy(FinancingTrendsGroupBy $groupBy): array
    {
        $qb = $this->onlyValidated($this->createQueryBuilder('funding')
            ->select('funding.year AS year', 'SUM(funding.amount) AS total')
            ->where('funding.isCurrent = true'))
            ->groupBy('funding.year');

        switch ($groupBy) {
            case FinancingTrendsGroupBy::Sector:
                $qb->addSelect('sector.name AS groupKey')
                    ->join('funding.sector', 'sector')
                    ->addGroupBy('sector.name');
                break;
            case FinancingTrendsGroupBy::Instrument:
                $qb->addSelect('funding.instrument AS groupKey')
                    ->addGroupBy('funding.instrument');
                break;
            case FinancingTrendsGroupBy::Country:
                $qb->addSelect('country.isoCode AS groupKey')
                    ->join('funding.country', 'country')
                    ->addGroupBy('country.isoCode');
                break;
            case FinancingTrendsGroupBy::FundingType:
                $qb->addSelect('funding.fundingType AS groupKey')
                    ->addGroupBy('funding.fundingType');
                break;
        }

        return $qb
            ->orderBy('funding.year', 'ASC')
            ->addOrderBy('groupKey', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * A2.5 (sector-distribution aggregate). One row per sector, summed in
     * SQL. Ordered by total DESC (largest sector first, the natural reading
     * order for a distribution chart), with sector.id ASC as a tiebreaker
     * so the order stays fully deterministic even if two sectors tie
     * exactly on amount.
     *
     * @return list<array{sectorId: int, sectorName: string, total: string}>
     */
    public function findSectorDistributionAggregate(): array
    {
        return $this->onlyValidated($this->createQueryBuilder('funding')
            ->select('sector.id AS sectorId', 'sector.name AS sectorName', 'SUM(funding.amount) AS total')
            ->join('funding.sector', 'sector')
            ->where('funding.isCurrent = true'))
            ->groupBy('sector.id')
            ->addGroupBy('sector.name')
            ->orderBy('total', 'DESC')
            ->addOrderBy('sector.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * C1.7 (instrument-distribution) : un instrument par ligne, summed en
     * SQL - même forme/raisonnement que findSectorDistributionAggregate().
     *
     * @return list<array{instrument: string, total: string}>
     */
    public function findInstrumentDistributionAggregate(): array
    {
        return $this->onlyValidated($this->createQueryBuilder('funding')
            ->select('funding.instrument AS instrument', 'SUM(funding.amount) AS total')
            ->where('funding.isCurrent = true'))
            ->groupBy('funding.instrument')
            ->orderBy('total', 'DESC')
            ->addOrderBy('instrument', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * C1.7 (instrument-distribution) : croisement instrument x secteur -
     * une ligne par combinaison réellement présente, summed en SQL. Sert à
     * explorer, par exemple, quels secteurs sont majoritairement financés
     * par don plutôt que par prêt (part de concessionnalité).
     *
     * @return list<array{instrument: string, sectorId: int, sectorName: string, total: string}>
     */
    public function findInstrumentSectorCrossTab(): array
    {
        return $this->onlyValidated($this->createQueryBuilder('funding')
            ->select('funding.instrument AS instrument', 'sector.id AS sectorId', 'sector.name AS sectorName', 'SUM(funding.amount) AS total')
            ->join('funding.sector', 'sector')
            ->where('funding.isCurrent = true'))
            ->groupBy('funding.instrument')
            ->addGroupBy('sector.id')
            ->addGroupBy('sector.name')
            ->orderBy('total', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * C1.7 (instrument-distribution) : croisement instrument x pays - même
     * raisonnement que findInstrumentSectorCrossTab(), par pays plutôt que
     * par secteur.
     *
     * @return list<array{instrument: string, isoCode: string, countryName: string, total: string}>
     */
    public function findInstrumentCountryCrossTab(): array
    {
        return $this->onlyValidated($this->createQueryBuilder('funding')
            ->select('funding.instrument AS instrument', 'country.isoCode AS isoCode', 'country.name AS countryName', 'SUM(funding.amount) AS total')
            ->join('funding.country', 'country')
            ->where('funding.isCurrent = true'))
            ->groupBy('funding.instrument')
            ->addGroupBy('country.isoCode')
            ->addGroupBy('country.name')
            ->orderBy('total', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * C1.5 (funding-flows Sankey) : première "couche" du flux - bailleur ->
     * pays, un montant agrégé par (source, pays) réellement présent. Le
     * top-N + regroupement "Autres" (par bailleur ET par pays) est fait en
     * PHP par App\Service\FundingFlowService, pas ici : c'est une
     * opération de mise en forme de graphe (fusionner plusieurs lignes vers
     * un même nœud "Autres"), pas un agrégat SQL supplémentaire.
     *
     * @return list<array{sourceId: int, sourceName: string, isoCode: string, countryName: string, total: string}>
     */
    /**
     * Audit Prompt Maitre #3 (point 2, 2026-10-12) : remplace l'ancienne
     * findSourceCountryFlowAggregate() (bailleur -> pays directement) -
     * desormais le flux passe par la couche "objectif climatique"
     * (Adaptation/Attenuation, voir FundingFlowService::objectiveKeyForSector())
     * intercalee entre bailleur et pays, deduite du secteur ici selectionne.
     * Memes filtres/raisonnement que findCountryToSectorFlowAggregate().
     *
     * @return list<array{sourceId: int, sourceName: string, sectorId: int, sectorName: string, total: string}>
     */
    public function findSourceToSectorFlowAggregate(FundingFlowCriteria $criteria): array
    {
        $qb = $this->onlyValidated($this->createQueryBuilder('funding')
            ->select('source.id AS sourceId', 'source.name AS sourceName', 'sector.id AS sectorId', 'sector.name AS sectorName', 'SUM(funding.amount) AS total')
            ->join('funding.source', 'source')
            ->join('funding.sector', 'sector')
            ->where('funding.isCurrent = true'));

        if (null !== $criteria->instrument) {
            $qb->andWhere('funding.instrument = :instrument')->setParameter('instrument', $criteria->instrument);
        }
        if (null !== $criteria->year) {
            $qb->andWhere('funding.year = :year')->setParameter('year', $criteria->year);
        }

        return $qb
            ->groupBy('source.id')
            ->addGroupBy('source.name')
            ->addGroupBy('sector.id')
            ->addGroupBy('sector.name')
            ->orderBy('total', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * C1.5 (funding-flows Sankey) : couche finale du flux - pays ->
     * secteur. Mêmes filtres/raisonnement que findSourceToSectorFlowAggregate()
     * juste au-dessus - sert aussi à dériver le lien objectif -> pays
     * (audit Prompt Maitre #3, point 2) puisque chaque ligne porte déjà le
     * secteur nécessaire pour ça.
     *
     * @return list<array{isoCode: string, countryName: string, sectorId: int, sectorName: string, total: string}>
     */
    public function findCountryToSectorFlowAggregate(FundingFlowCriteria $criteria): array
    {
        $qb = $this->onlyValidated($this->createQueryBuilder('funding')
            ->select('country.isoCode AS isoCode', 'country.name AS countryName', 'sector.id AS sectorId', 'sector.name AS sectorName', 'SUM(funding.amount) AS total')
            ->join('funding.country', 'country')
            ->join('funding.sector', 'sector')
            ->where('funding.isCurrent = true'));

        if (null !== $criteria->instrument) {
            $qb->andWhere('funding.instrument = :instrument')->setParameter('instrument', $criteria->instrument);
        }
        if (null !== $criteria->year) {
            $qb->andWhere('funding.year = :year')->setParameter('year', $criteria->year);
        }

        return $qb
            ->groupBy('country.isoCode')
            ->addGroupBy('country.name')
            ->addGroupBy('sector.id')
            ->addGroupBy('sector.name')
            ->orderBy('total', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Country-distribution aggregate (visualizations.html's funding map, on
     * top of the A2.5 perimeter - same shape/reasoning as
     * findSectorDistributionAggregate(), country instead of sector). One
     * row per country, summed in SQL, ordered by total DESC with
     * country.id ASC as a deterministic tiebreaker.
     *
     * C1.2: $criteria's sector/instrument/year/fundingType filters are all
     * optional and cumulable, applied the same way criteriaQueryBuilder()
     * applies GET /api/funding's own filters - an all-null $criteria adds
     * no WHERE clause at all, so an unfiltered call behaves exactly as it
     * did before C1.2.
     *
     * @return list<array{isoCode: string, countryName: string, total: string}>
     */
    public function findCountryDistributionAggregate(CountryDistributionCriteria $criteria): array
    {
        $qb = $this->onlyValidated($this->createQueryBuilder('funding')
            ->select('country.isoCode AS isoCode', 'country.name AS countryName', 'SUM(funding.amount) AS total')
            ->join('funding.country', 'country')
            ->where('funding.isCurrent = true'));

        if (null !== $criteria->sectorId) {
            $qb->andWhere('funding.sector = :sectorId')->setParameter('sectorId', $criteria->sectorId);
        }

        if (null !== $criteria->instrument) {
            $qb->andWhere('funding.instrument = :instrument')->setParameter('instrument', $criteria->instrument);
        }

        if (null !== $criteria->year) {
            $qb->andWhere('funding.year = :year')->setParameter('year', $criteria->year);
        }

        if (null !== $criteria->fundingType) {
            $qb->andWhere('funding.fundingType = :fundingType')->setParameter('fundingType', $criteria->fundingType);
        }

        return $qb
            ->groupBy('country.id')
            ->addGroupBy('country.isoCode')
            ->addGroupBy('country.name')
            ->orderBy('total', 'DESC')
            ->addOrderBy('country.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * C1.1 : dernière collecte réelle connue par source (tuile de
     * fraîcheur des données). Une ligne par source, `lastCollectionDate`
     * étant le MAX sur ses lignes courantes validées - reflète quand cette
     * source a été vue pour la dernière fois, pas une moyenne.
     *
     * @return list<array{sourceId: int, sourceName: string, lastCollectionDate: \DateTimeImmutable}>
     */
    public function findSourceFreshnessAggregate(): array
    {
        return $this->onlyValidated($this->createQueryBuilder('funding')
            ->select('source.id AS sourceId', 'source.name AS sourceName', 'MAX(funding.collectionDate) AS lastCollectionDate')
            ->join('funding.source', 'source')
            ->where('funding.isCurrent = true'))
            ->groupBy('source.id')
            ->addGroupBy('source.name')
            ->orderBy('lastCollectionDate', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * C1.4 (institution profile - "portefeuille") : vue d'ensemble des
     * financements d'une source donnée - montant total, nombre
     * d'enregistrements, et nombre de pays/secteurs distincts touchés.
     * `null` si cette source n'a aucun financement réel (jamais un zéro
     * fabriqué qui laisserait croire qu'elle a été vérifiée et trouvée
     * vide).
     *
     * @return array{totalAmount: string, fundingRecordCount: int, countriesFundedCount: int, sectorsFundedCount: int}|null
     */
    public function findInstitutionPortfolioSummary(int $sourceId): ?array
    {
        $result = $this->onlyValidated($this->createQueryBuilder('funding')
            ->select(
                'SUM(funding.amount) AS totalAmount',
                'COUNT(funding.id) AS fundingRecordCount',
                'COUNT(DISTINCT funding.country) AS countriesFundedCount',
                'COUNT(DISTINCT funding.sector) AS sectorsFundedCount',
            )
            ->where('funding.isCurrent = true')
            ->andWhere('funding.source = :sourceId'))
            ->setParameter('sourceId', $sourceId)
            ->getQuery()
            ->getSingleResult();

        if (null === $result['totalAmount']) {
            return null;
        }

        return [
            'totalAmount' => $result['totalAmount'],
            'fundingRecordCount' => (int) $result['fundingRecordCount'],
            'countriesFundedCount' => (int) $result['countriesFundedCount'],
            'sectorsFundedCount' => (int) $result['sectorsFundedCount'],
        ];
    }

    /**
     * C1.4 (institution profile - "répartition" par secteur), scoped to a
     * single source - same shape/reasoning as findSectorDistributionAggregate().
     *
     * @return list<array{sectorId: int, sectorName: string, total: string}>
     */
    public function findInstitutionSectorBreakdown(int $sourceId): array
    {
        return $this->onlyValidated($this->createQueryBuilder('funding')
            ->select('sector.id AS sectorId', 'sector.name AS sectorName', 'SUM(funding.amount) AS total')
            ->join('funding.sector', 'sector')
            ->where('funding.isCurrent = true')
            ->andWhere('funding.source = :sourceId'))
            ->setParameter('sourceId', $sourceId)
            ->groupBy('sector.id')
            ->addGroupBy('sector.name')
            ->orderBy('total', 'DESC')
            ->addOrderBy('sector.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * C1.4 (institution profile - "répartition" par pays), scoped to a
     * single source - same shape/reasoning as findCountryDistributionAggregate().
     *
     * @return list<array{isoCode: string, countryName: string, total: string}>
     */
    public function findInstitutionCountryBreakdown(int $sourceId): array
    {
        return $this->onlyValidated($this->createQueryBuilder('funding')
            ->select('country.isoCode AS isoCode', 'country.name AS countryName', 'SUM(funding.amount) AS total')
            ->join('funding.country', 'country')
            ->where('funding.isCurrent = true')
            ->andWhere('funding.source = :sourceId'))
            ->setParameter('sourceId', $sourceId)
            ->groupBy('country.id')
            ->addGroupBy('country.isoCode')
            ->addGroupBy('country.name')
            ->orderBy('total', 'DESC')
            ->addOrderBy('country.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * C1.4 (institution profile - "évolution"), scoped to a single source -
     * one row per year, summed in SQL, chronological.
     *
     * @return list<array{year: int, total: string}>
     */
    public function findInstitutionYearlyTrend(int $sourceId): array
    {
        return $this->onlyValidated($this->createQueryBuilder('funding')
            ->select('funding.year AS year', 'SUM(funding.amount) AS total')
            ->where('funding.isCurrent = true')
            ->andWhere('funding.source = :sourceId'))
            ->setParameter('sourceId', $sourceId)
            ->groupBy('funding.year')
            ->orderBy('funding.year', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * C1.4 (institution profile - "transparence") : dernière collecte
     * réelle connue pour cette seule source - même donnée que
     * findSourceFreshnessAggregate() (C1.1), mais scoped à une source pour
     * éviter de charger l'agrégat des ~5 sources entières juste pour en
     * lire une. `null` si cette source n'a aucun financement réel.
     */
    public function findLastCollectionDateForSource(int $sourceId): ?\DateTimeImmutable
    {
        $date = $this->onlyValidated($this->createQueryBuilder('funding')
            ->select('MAX(funding.collectionDate) AS lastCollectionDate')
            ->where('funding.isCurrent = true')
            ->andWhere('funding.source = :sourceId'))
            ->setParameter('sourceId', $sourceId)
            ->getQuery()
            ->getSingleScalarResult();

        return null !== $date ? ($date instanceof \DateTimeImmutable ? $date : new \DateTimeImmutable((string) $date)) : null;
    }

    /**
     * C3.6 (complétude, 0,3) : sur les lignes courantes validées de cette
     * source, combien renseignent réellement les 2 champs optionnels du
     * schéma (`instrument` hors valeur par défaut "unknown" ET
     * `originalAmount` non NULL) - les seuls champs de App\Entity\Funding
     * qui peuvent légitimement être absents (tout le reste est NOT NULL en
     * base, donc toujours "complet").
     *
     * @return array{total: int, complete: int}
     */
    public function findCompletenessStatsForSource(int $sourceId): array
    {
        $result = $this->onlyValidated($this->createQueryBuilder('funding')
            ->select(
                'COUNT(funding.id) AS total',
                'SUM(CASE WHEN funding.instrument != :unknown AND funding.originalAmount IS NOT NULL THEN 1 ELSE 0 END) AS complete'
            )
            ->where('funding.isCurrent = true')
            ->andWhere('funding.source = :sourceId')
            ->setParameter('sourceId', $sourceId)
            ->setParameter('unknown', FundingInstrument::Unknown))
            ->getQuery()
            ->getSingleResult();

        return ['total' => (int) $result['total'], 'complete' => (int) ($result['complete'] ?? 0)];
    }

    /**
     * Shared WHERE-clause builder for findByCriteria()/countByCriteria(), so
     * the two queries can never apply a different set of filters. `country`
     * is always joined (needed to filter on Country.isoCode, and it's a
     * required to-one relation so it can't multiply row counts); `sector`
     * and `source` are joined only by findByCriteria(), which needs them for
     * output hydration - countByCriteria() has no reason to pay for them
     * since neither is filtered on directly.
     */
    private function criteriaQueryBuilder(FundingSearchCriteria $criteria): QueryBuilder
    {
        $qb = $this->createQueryBuilder('funding')
            ->join('funding.country', 'country')
            ->andWhere('funding.isCurrent = true');
        $this->onlyValidated($qb);

        if (null !== $criteria->countryIsoCode) {
            $qb->andWhere('country.isoCode = :countryIsoCode')
                ->setParameter('countryIsoCode', $criteria->countryIsoCode);
        }

        if (null !== $criteria->sectorId) {
            $qb->andWhere('funding.sector = :sectorId')
                ->setParameter('sectorId', $criteria->sectorId);
        }

        if (null !== $criteria->year) {
            $qb->andWhere('funding.year = :year')
                ->setParameter('year', $criteria->year);
        }

        if (null !== $criteria->fundingType) {
            $qb->andWhere('funding.fundingType = :fundingType')
                ->setParameter('fundingType', $criteria->fundingType);
        }

        // "periodStart"/"periodEnd" filter the funding's own `year` (the
        // period the funding actually covers), not `collectionDate` (when
        // NEV's pipeline happened to ingest the row) - a real bug found
        // during B3.1's real-data verification: with only demo fixtures,
        // collectionDate and year happened to roughly line up, hiding the
        // mismatch. Real pipeline data is all ingested "today" (collectionDate)
        // while `year` genuinely spans 1967-2027, which made the date-range
        // filter a near no-op against collectionDate. A date-range UI field
        // next to a Year filter is what a user of a funding-browsing tool
        // expects to scope by the funding's own period, not by an internal
        // ingestion timestamp they have no reason to know about.
        if (null !== $criteria->periodStart) {
            $qb->andWhere('funding.year >= :periodStartYear')
                ->setParameter('periodStartYear', (int) $criteria->periodStart->format('Y'));
        }

        if (null !== $criteria->periodEnd) {
            $qb->andWhere('funding.year <= :periodEndYear')
                ->setParameter('periodEndYear', (int) $criteria->periodEnd->format('Y'));
        }

        return $qb;
    }

    /**
     * A2.7 (Hero "Pays couverts"): countries actually appearing in at least
     * one Funding record, not Country's total row count - a country known
     * to the system but with no funding data yet shouldn't read as
     * "covered".
     */
    public function countDistinctCountries(): int
    {
        return (int) $this->onlyValidated($this->createQueryBuilder('funding')
            ->select('COUNT(DISTINCT funding.country)')
            ->where('funding.isCurrent = true'))
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * A2.7 (Hero "Sources actives"): same reasoning as
     * countDistinctCountries() - Source has no active/inactive flag, so
     * "active" is read as "currently contributing data", not "registered".
     */
    public function countDistinctSources(): int
    {
        return (int) $this->onlyValidated($this->createQueryBuilder('funding')
            ->select('COUNT(DISTINCT funding.source)')
            ->where('funding.isCurrent = true'))
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * C2.4/C2.8 (risque pays - volatilité des flux) : total réel par année
     * pour un pays donné - le score de risque calcule le coefficient de
     * variation sur ce résultat (App\Service\Scoring\CountryRiskCalculator),
     * pas ici : c'est une opération statistique, pas un agrégat SQL
     * supplémentaire.
     *
     * @return list<array{year: int, total: string}>
     */
    public function findYearlyTotalsForCountry(int $countryId): array
    {
        return $this->onlyValidated($this->createQueryBuilder('funding')
            ->select('funding.year AS year', 'SUM(funding.amount) AS total')
            ->where('funding.isCurrent = true')
            ->andWhere('funding.country = :countryId')
            ->setParameter('countryId', $countryId))
            ->groupBy('funding.year')
            ->orderBy('funding.year', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Audit post-C3 2026-10-01 : meme resultat que findYearlyTotalsForCountry()
     * applique a tous les pays a la fois (une seule requete groupee par pays
     * ET annee), pour App\Service\AlertEvaluationService::evaluateAbnormalFlow()
     * - c'etait le seul endroit du code qui appelait findYearlyTotalsForCountry()
     * en boucle (une requete par pays, ~54 requetes par execution quotidienne
     * de app:alerts:evaluate), toutes les autres suites de requetes par-sujet
     * de la plateforme (EquityScoreService, RecommendationService) ayant deja
     * ete identifiees et mitigees par cache lors de l'audit pre-production du
     * 2026-09-22. Pas de cache ici : ce chemin ne tourne qu'une fois par jour
     * (cron Airflow), une requete groupee suffit, inutile d'ajouter une
     * couche de cache pour un appel aussi rare.
     *
     * @return list<array{countryId: int, year: int, total: string}>
     */
    public function findYearlyTotalsGroupedByCountry(): array
    {
        return $this->onlyValidated($this->createQueryBuilder('funding')
            ->select('IDENTITY(funding.country) AS countryId', 'funding.year AS year', 'SUM(funding.amount) AS total')
            ->where('funding.isCurrent = true'))
            ->groupBy('funding.country')
            ->addGroupBy('funding.year')
            ->orderBy('funding.country', 'ASC')
            ->addOrderBy('funding.year', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * C2.4/C2.8 (risque pays - concentration des bailleurs) : total réel
     * par bailleur pour un pays donné - le calcul de l'indice de
     * concentration (HHI) se fait dans CountryRiskCalculator, pas ici.
     *
     * @return list<array{sourceId: int, total: string}>
     */
    public function findSourceTotalsForCountry(int $countryId): array
    {
        return $this->onlyValidated($this->createQueryBuilder('funding')
            ->select('IDENTITY(funding.source) AS sourceId', 'SUM(funding.amount) AS total')
            ->where('funding.isCurrent = true')
            ->andWhere('funding.country = :countryId')
            ->setParameter('countryId', $countryId))
            ->groupBy('funding.source')
            ->getQuery()
            ->getResult();
    }

    /**
     * C2.6 (couverture des secteurs prioritaires CDN) : identifiants des
     * secteurs ayant réellement reçu du financement validé pour un pays
     * donné - jamais un simple COUNT, le détail sert à distinguer "aucun
     * secteur couvert" d'un vrai calcul de couverture.
     *
     * @return list<int>
     */
    public function findFundedSectorIdsForCountry(int $countryId): array
    {
        $rows = $this->onlyValidated($this->createQueryBuilder('funding')
            ->select('DISTINCT IDENTITY(funding.sector) AS sectorId')
            ->where('funding.isCurrent = true')
            ->andWhere('funding.country = :countryId')
            ->setParameter('countryId', $countryId))
            ->getQuery()
            ->getResult();

        return array_map(static fn (array $row): int => (int) $row['sectorId'], $rows);
    }

    /**
     * C2.6 (ratio reçu/besoin CDN) + C2.4 (risque pays) : total réel reçu
     * (tous secteurs) par un pays donné, `null` si aucune ligne validée
     * n'existe encore pour lui (jamais un zéro fabriqué, même distinction
     * que App\Repository\FundingRepository::findInstitutionPortfolioSummary()).
     */
    public function findTotalReceivedForCountry(int $countryId): ?string
    {
        $result = $this->onlyValidated($this->createQueryBuilder('funding')
            ->select('SUM(funding.amount) AS total')
            ->where('funding.isCurrent = true')
            ->andWhere('funding.country = :countryId')
            ->setParameter('countryId', $countryId))
            ->getQuery()
            ->getSingleResult();

        return $result['total'] ?? null;
    }

    /**
     * C3.1 (Gap Detector, by=sector) : total réel reçu par un secteur,
     * tous pays confondus - même raisonnement que
     * findTotalReceivedForCountry(), à l'échelle du secteur.
     */
    public function findTotalReceivedForSector(int $sectorId): ?string
    {
        $result = $this->onlyValidated($this->createQueryBuilder('funding')
            ->select('SUM(funding.amount) AS total')
            ->where('funding.isCurrent = true')
            ->andWhere('funding.sector = :sectorId')
            ->setParameter('sectorId', $sectorId))
            ->getQuery()
            ->getSingleResult();

        return $result['total'] ?? null;
    }

    /**
     * C3.1 (Gap Detector, by=country-sector) : total réel reçu pour une
     * paire (pays, secteur) précise.
     */
    public function findTotalReceivedForCountryAndSector(int $countryId, int $sectorId): ?string
    {
        $result = $this->onlyValidated($this->createQueryBuilder('funding')
            ->select('SUM(funding.amount) AS total')
            ->where('funding.isCurrent = true')
            ->andWhere('funding.country = :countryId')
            ->andWhere('funding.sector = :sectorId')
            ->setParameter('countryId', $countryId)
            ->setParameter('sectorId', $sectorId))
            ->getQuery()
            ->getSingleResult();

        return $result['total'] ?? null;
    }

    /**
     * C2.4 (instrument réaliste pour le pays) : ce pays a-t-il déjà reçu un
     * financement réel avec cet instrument précis ? Distinct de
     * findInstrumentCountryCrossTab() (C1.7, tout le crosstab
     * plateforme) - ici une seule paire (pays, instrument), la question
     * posée par le score de bancabilité d'un projet donné.
     */
    public function hasValidatedFundingForCountryAndInstrument(int $countryId, FundingInstrument $instrument): bool
    {
        $count = $this->onlyValidated($this->createQueryBuilder('funding')
            ->select('COUNT(funding.id)')
            ->where('funding.isCurrent = true')
            ->andWhere('funding.country = :countryId')
            ->andWhere('funding.instrument = :instrument')
            ->setParameter('countryId', $countryId)
            ->setParameter('instrument', $instrument))
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count > 0;
    }

    /**
     * C2.4 (cohérence montant/secteur vs comparables réels) : moyenne et
     * nombre de financements réels validés pour un secteur donné (tous
     * pays confondus) - le "comparable réel" auquel le montant demandé
     * d'un projet est confronté (App\Service\Scoring\Bankability\
     * AmountSectorCoherenceIndicator). `null` si moins de 3 financements
     * réels existent pour ce secteur : une moyenne sur 1-2 lignes n'est
     * pas un comparable fiable, mieux vaut le dire explicitement que de
     * fabriquer un score à partir de presque rien.
     *
     * @return array{average: string, count: int}|null
     */
    public function findSectorAmountBenchmark(int $sectorId): ?array
    {
        $result = $this->onlyValidated($this->createQueryBuilder('funding')
            ->select('AVG(funding.amount) AS average', 'COUNT(funding.id) AS count')
            ->where('funding.isCurrent = true')
            ->andWhere('funding.sector = :sectorId')
            ->setParameter('sectorId', $sectorId))
            ->getQuery()
            ->getSingleResult();

        if ((int) $result['count'] < 3) {
            return null;
        }

        return ['average' => $result['average'], 'count' => (int) $result['count']];
    }

    /**
     * C2.8 (risque secteur - maturité) : total réel par année pour un
     * secteur donné, tous pays confondus - même raisonnement que
     * findYearlyTotalsForCountry(), à l'échelle du secteur.
     *
     * @return list<array{year: int, total: string}>
     */
    public function findYearlyTotalsForSector(int $sectorId): array
    {
        return $this->onlyValidated($this->createQueryBuilder('funding')
            ->select('funding.year AS year', 'SUM(funding.amount) AS total')
            ->where('funding.isCurrent = true')
            ->andWhere('funding.sector = :sectorId')
            ->setParameter('sectorId', $sectorId))
            ->groupBy('funding.year')
            ->orderBy('funding.year', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * C2.8 (risque secteur - dépendance à un instrument) : total réel par
     * instrument pour un secteur donné - même raisonnement que
     * findSourceTotalsForCountry(), l'indice de concentration (HHI) se
     * calcule dans App\Service\Scoring\SectorRiskCalculator, pas ici.
     *
     * @return list<array{instrument: string, total: string}>
     */
    public function findInstrumentTotalsForSector(int $sectorId): array
    {
        return $this->onlyValidated($this->createQueryBuilder('funding')
            ->select('funding.instrument AS instrument', 'SUM(funding.amount) AS total')
            ->where('funding.isCurrent = true')
            ->andWhere('funding.sector = :sectorId')
            ->setParameter('sectorId', $sectorId))
            ->groupBy('funding.instrument')
            ->getQuery()
            ->getResult();
    }

    /**
     * C3.2 (Local Finance Tracker) : un pays par ligne, `totalUsd` (tout
     * financement validé confondu) et `estimatedLocalUsd` (uniquement les
     * lignes `instrument = grant` ET `amount <= $smallAmountThresholdUsd`
     * - le seul proxy disponible dans ce schéma, voir
     * App\Service\LocalFinanceService pour pourquoi le proxy par mots-clés
     * prévu au plan n'est pas réalisable ici). `$countryId` optionnel
     * restreint à un seul pays plutôt que les 54.
     *
     * @return list<array{isoCode: string, countryName: string, totalUsd: string, estimatedLocalUsd: string}>
     */
    public function findLocalFinanceAggregate(float $smallAmountThresholdUsd, ?int $countryId = null): array
    {
        $qb = $this->onlyValidated($this->createQueryBuilder('funding')
            ->select(
                'country.isoCode AS isoCode',
                'country.name AS countryName',
                'SUM(funding.amount) AS totalUsd',
                'SUM(CASE WHEN funding.instrument = :grant AND funding.amount <= :threshold THEN funding.amount ELSE 0 END) AS estimatedLocalUsd'
            )
            ->join('funding.country', 'country')
            ->where('funding.isCurrent = true')
            ->setParameter('grant', FundingInstrument::Grant)
            ->setParameter('threshold', $smallAmountThresholdUsd));

        if (null !== $countryId) {
            $qb->andWhere('country.id = :countryId')->setParameter('countryId', $countryId);
        }

        return $qb
            ->groupBy('country.id')
            ->addGroupBy('country.isoCode')
            ->addGroupBy('country.name')
            ->orderBy('totalUsd', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
