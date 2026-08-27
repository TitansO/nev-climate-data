<?php

declare(strict_types=1);

namespace App\Repository;

use App\Dto\FundingSearchCriteria;
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
        return $this->createQueryBuilder('funding')
            ->select('funding.year AS year', 'funding.fundingType AS fundingType', 'SUM(funding.amount) AS total')
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
        return $this->createQueryBuilder('funding')
            ->select('sector.id AS sectorId', 'sector.name AS sectorName', 'SUM(funding.amount) AS total')
            ->join('funding.sector', 'sector')
            ->groupBy('sector.id')
            ->addGroupBy('sector.name')
            ->orderBy('total', 'DESC')
            ->addOrderBy('sector.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Shared WHERE-clause builder for findByCriteria()/countByCriteria(), so
     * the two queries can never apply a different set of filters. `country`
     * is always joined (needed to filter on Country.isoCode, and it's a
     * required to-one relation so it can't multiply row counts); `sector`
     * and `source` are joined only by findByCriteria(), which needs them for
     * output hydration — countByCriteria() has no reason to pay for them
     * since neither is filtered on directly.
     */
    private function criteriaQueryBuilder(FundingSearchCriteria $criteria): QueryBuilder
    {
        $qb = $this->createQueryBuilder('funding')
            ->join('funding.country', 'country');

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

        if (null !== $criteria->periodStart) {
            $qb->andWhere('funding.collectionDate >= :periodStart')
                ->setParameter('periodStart', $criteria->periodStart);
        }

        if (null !== $criteria->periodEnd) {
            $qb->andWhere('funding.collectionDate <= :periodEnd')
                ->setParameter('periodEnd', $criteria->periodEnd);
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
        return (int) $this->createQueryBuilder('funding')
            ->select('COUNT(DISTINCT funding.country)')
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
        return (int) $this->createQueryBuilder('funding')
            ->select('COUNT(DISTINCT funding.source)')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
