<?php

declare(strict_types=1);

namespace App\Repository;

use App\Dto\SearchQuery;
use App\Entity\Project;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Project>
 */
class ProjectRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Project::class);
    }

    /**
     * C1.3: GET /api/search extended to Project (F4). Matches on title
     * only, same case-/accent-insensitive UNACCENT() pattern as every
     * other searched type (App\Doctrine\DQL\UnaccentFunction). Project is
     * the one type that carries every C1.3 facet (country, sector,
     * instrument via instrumentSought, year via createdAt, amount via
     * amountSought), so all 5 are applied here - see
     * App\Service\SearchService's docblock for the full per-type matrix.
     *
     * @return list<Project>
     */
    public function searchByTitle(SearchQuery $searchQuery, int $limit): array
    {
        $qb = $this->createQueryBuilder('project')
            ->andWhere('UNACCENT(LOWER(project.title)) LIKE UNACCENT(:pattern)')
            ->setParameter('pattern', $searchQuery->likePattern());

        if (null !== $searchQuery->countryIsoCode) {
            $qb->join('project.country', 'country')
                ->andWhere('country.isoCode = :countryIsoCode')
                ->setParameter('countryIsoCode', $searchQuery->countryIsoCode);
        }

        if (null !== $searchQuery->sectorId) {
            $qb->andWhere('project.sector = :sectorId')->setParameter('sectorId', $searchQuery->sectorId);
        }

        if (null !== $searchQuery->instrument) {
            $qb->andWhere('project.instrumentSought = :instrument')->setParameter('instrument', $searchQuery->instrument);
        }

        if (null !== $searchQuery->year) {
            $qb->andWhere('YEAR(project.createdAt) = :year')->setParameter('year', $searchQuery->year);
        }

        if (null !== $searchQuery->minAmount) {
            $qb->andWhere('project.amountSought >= :minAmount')->setParameter('minAmount', $searchQuery->minAmount);
        }

        return $qb
            ->orderBy('project.title', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
