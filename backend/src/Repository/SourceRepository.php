<?php

declare(strict_types=1);

namespace App\Repository;

use App\Dto\SearchQuery;
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

    /**
     * A2.8. Case- and accent-insensitive (UNACCENT() on both sides - see
     * App\Doctrine\DQL\UnaccentFunction). Ordered by name for a
     * deterministic result order.
     *
     * C1.3: Source supports none of the C1.3 facets (no country, sector,
     * instrument, year or amount of its own), so any facet at all excludes
     * Source from the results entirely (see SearchQuery::hasFacetOutside()).
     *
     * @return list<Source>
     */
    public function searchByName(SearchQuery $searchQuery, int $limit): array
    {
        if ($searchQuery->hasFacetOutside([])) {
            return [];
        }

        return $this->createQueryBuilder('source')
            ->andWhere('UNACCENT(LOWER(source.name)) LIKE UNACCENT(:pattern)')
            ->setParameter('pattern', $searchQuery->likePattern())
            ->orderBy('source.name', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
