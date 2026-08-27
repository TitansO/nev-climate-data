<?php

declare(strict_types=1);

namespace App\Repository;

use App\Dto\SearchQuery;
use App\Entity\Enum\ReportStatus;
use App\Entity\Report;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Report>
 */
class ReportRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Report::class);
    }

    /**
     * A2.8. Published only - a Draft report isn't meant to be publicly
     * visible yet (App\Controller\SearchController is PUBLIC_ACCESS, same
     * reasoning as GET /api/funding). Ordered by title for a deterministic
     * result order.
     *
     * @return list<Report>
     */
    public function searchPublishedByTitle(SearchQuery $searchQuery, int $limit): array
    {
        return $this->createQueryBuilder('report')
            ->andWhere('LOWER(report.title) LIKE :pattern')
            ->andWhere('report.status = :status')
            ->setParameter('pattern', $searchQuery->likePattern())
            ->setParameter('status', ReportStatus::Published)
            ->orderBy('report.title', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
