<?php

declare(strict_types=1);

namespace App\Repository;

use App\Dto\SearchQuery;
use App\Entity\Opportunity;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Opportunity>
 */
class OpportunityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Opportunity::class);
    }

    /**
     * C1.3: GET /api/search extended to Opportunity (F4). Matches on title
     * only, same case-/accent-insensitive UNACCENT() pattern as every
     * other searched type. Opportunity has no country and no instrument of
     * its own (see the entity's docblock: eligibility is free text, not a
     * structured field) - either facet excludes it from the results
     * entirely (see SearchQuery::hasFacetOutside()). "year" matches
     * `applicationWindowOpensAt`, itself nullable - an opportunity with no
     * open date simply never matches a year filter.
     *
     * @return list<Opportunity>
     */
    public function searchByTitle(SearchQuery $searchQuery, int $limit): array
    {
        if ($searchQuery->hasFacetOutside(['sector', 'year', 'minAmount'])) {
            return [];
        }

        $qb = $this->createQueryBuilder('opportunity')
            ->andWhere('UNACCENT(LOWER(opportunity.title)) LIKE UNACCENT(:pattern)')
            ->setParameter('pattern', $searchQuery->likePattern());

        if (null !== $searchQuery->sectorId) {
            $qb->andWhere('opportunity.sector = :sectorId')->setParameter('sectorId', $searchQuery->sectorId);
        }

        if (null !== $searchQuery->year) {
            $qb->andWhere('opportunity.applicationWindowOpensAt >= :yearStart')->andWhere('opportunity.applicationWindowOpensAt < :yearEnd')
                ->setParameter('yearStart', $searchQuery->yearStart())
                ->setParameter('yearEnd', $searchQuery->yearEnd());
        }

        if (null !== $searchQuery->minAmount) {
            $qb->andWhere('opportunity.envelopeAmount >= :minAmount')->setParameter('minAmount', $searchQuery->minAmount);
        }

        return $qb
            ->orderBy('opportunity.title', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
