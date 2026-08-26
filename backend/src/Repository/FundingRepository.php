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
}
