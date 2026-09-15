<?php

declare(strict_types=1);

namespace App\Repository;

use App\Dto\OpportunityCriteria;
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

    /**
     * C1.8 : GET /api/opportunities. `status` (open/upcoming/closed) is
     * never filtered here in SQL - it isn't a stored column (see
     * OpportunityCriteria's own docblock) and this table is tiny (a
     * manually-seeded handful of rows, same "no reason to optimize a
     * dozen-row table" reasoning as SearchService) - App\Service\
     * OpportunityService computes and filters it in PHP after this method
     * returns. `sector`/`country` (a free-text substring against
     * eligibilityCriteria) are applied here since they genuinely are SQL
     * filters. Ordered by closing date ascending (soonest deadline first -
     * the reading order an "opportunity radar" needs); NULLS LAST is
     * PostgreSQL's own default for ASC, so an opportunity with no closing
     * date sorts after every dated one, never fabricating an implied one.
     *
     * @return list<Opportunity>
     */
    public function findByCriteria(OpportunityCriteria $criteria): array
    {
        $qb = $this->createQueryBuilder('opportunity')
            ->addSelect('source', 'sector')
            ->join('opportunity.source', 'source')
            ->leftJoin('opportunity.sector', 'sector');

        if (null !== $criteria->sectorId) {
            $qb->andWhere('opportunity.sector = :sectorId')->setParameter('sectorId', $criteria->sectorId);
        }

        if (null !== $criteria->countryTerm) {
            $qb->andWhere('UNACCENT(LOWER(opportunity.eligibilityCriteria)) LIKE UNACCENT(:countryPattern)')
                ->setParameter('countryPattern', $criteria->countryLikePattern());
        }

        return $qb
            ->orderBy('opportunity.applicationWindowClosesAt', 'ASC')
            ->addOrderBy('opportunity.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
