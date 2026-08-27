<?php

declare(strict_types=1);

namespace App\Repository;

use App\Dto\SearchQuery;
use App\Entity\Country;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Country>
 */
class CountryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Country::class);
    }

    /**
     * A2.8: matches on name only, not isoCode - "senegal" is what a user
     * types, not "sen". Ordered by name for a deterministic result order.
     *
     * @return list<Country>
     */
    public function searchByName(SearchQuery $searchQuery, int $limit): array
    {
        return $this->createQueryBuilder('country')
            ->andWhere('LOWER(country.name) LIKE :pattern')
            ->setParameter('pattern', $searchQuery->likePattern())
            ->orderBy('country.name', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
