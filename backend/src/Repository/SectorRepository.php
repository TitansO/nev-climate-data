<?php

declare(strict_types=1);

namespace App\Repository;

use App\Dto\SearchQuery;
use App\Entity\Sector;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Sector>
 */
class SectorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Sector::class);
    }

    /**
     * A2.8. Case- and accent-insensitive (UNACCENT() on both sides - see
     * App\Doctrine\DQL\UnaccentFunction). Ordered by name for a
     * deterministic result order.
     *
     * @return list<Sector>
     */
    public function searchByName(SearchQuery $searchQuery, int $limit): array
    {
        return $this->createQueryBuilder('sector')
            ->andWhere('UNACCENT(LOWER(sector.name)) LIKE UNACCENT(:pattern)')
            ->setParameter('pattern', $searchQuery->likePattern())
            ->orderBy('sector.name', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
