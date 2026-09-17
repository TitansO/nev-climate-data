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
     * C2.6 : GET /api/countries/{iso}/scores/ndc-alignment - une casse
     * insensible (`isoCode` toujours stocké en majuscules par
     * CountryFixtures, mais l'URL ne doit pas forcer l'appelant à le
     * savoir).
     */
    public function findOneByIsoCode(string $isoCode): ?Country
    {
        return $this->findOneBy(['isoCode' => mb_strtoupper($isoCode)]);
    }

    /**
     * A2.8: matches on name only, not isoCode - "senegal" is what a user
     * types, not "sen". Case- and accent-insensitive (UNACCENT() on both
     * sides - see App\Doctrine\DQL\UnaccentFunction), so "senegal" also
     * finds "Sénégal". Ordered by name for a deterministic result order.
     *
     * C1.3: supports only the "country" facet (an exact isoCode match, on
     * top of the free-text name search above) - Country has no
     * sector/instrument/year/amount, so any other facet excludes Country
     * from the results entirely (see SearchQuery::hasFacetOutside()).
     *
     * @return list<Country>
     */
    public function searchByName(SearchQuery $searchQuery, int $limit): array
    {
        if ($searchQuery->hasFacetOutside(['country'])) {
            return [];
        }

        $qb = $this->createQueryBuilder('country')
            ->andWhere('UNACCENT(LOWER(country.name)) LIKE UNACCENT(:pattern)')
            ->setParameter('pattern', $searchQuery->likePattern());

        if (null !== $searchQuery->countryIsoCode) {
            $qb->andWhere('country.isoCode = :countryIsoCode')->setParameter('countryIsoCode', $searchQuery->countryIsoCode);
        }

        return $qb
            ->orderBy('country.name', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
