<?php

declare(strict_types=1);

namespace App\Repository;

use App\Dto\CountryDistributionCriteria;
use App\Dto\FundingSearchCriteria;
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
}
