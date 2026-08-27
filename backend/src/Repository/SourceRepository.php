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
     * @return list<Source>
     */
    public function searchByName(SearchQuery $searchQuery, int $limit): array
    {
        return $this->createQueryBuilder('source')
            ->andWhere('UNACCENT(LOWER(source.name)) LIKE UNACCENT(:pattern)')
            ->setParameter('pattern', $searchQuery->likePattern())
            ->orderBy('source.name', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
