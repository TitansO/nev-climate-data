<?php

declare(strict_types=1);

namespace App\Repository;

use App\Dto\ReportSearchCriteria;
use App\Dto\SearchQuery;
use App\Entity\Enum\ReportStatus;
use App\Entity\Report;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
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
     * A2.13. Published only, same reasoning as searchPublishedByTitle()
     * below - a Draft report is a work in progress, not meant to be
     * publicly listed or downloadable yet.
     *
     * @return list<Report>
     */
    public function findPublished(ReportSearchCriteria $criteria): array
    {
        return $this->publishedQueryBuilder($criteria)
            ->addSelect('country')
            ->orderBy('report.publicationDate', 'DESC')
            ->addOrderBy('report.id', 'DESC')
            ->setFirstResult(($criteria->page - 1) * $criteria->limit)
            ->setMaxResults($criteria->limit)
            ->getQuery()
            ->getResult();
    }

    public function countPublished(ReportSearchCriteria $criteria): int
    {
        return (int) $this->publishedQueryBuilder($criteria)
            ->select('COUNT(report.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * A publicly downloadable report: Published only (see findPublished()) -
     * a direct hit on a Draft's id must 404, not leak an unpublished report.
     */
    public function findOnePublished(int $id): ?Report
    {
        return $this->createQueryBuilder('report')
            ->andWhere('report.id = :id')
            ->andWhere('report.status = :status')
            ->setParameter('id', $id)
            ->setParameter('status', ReportStatus::Published)
            ->getQuery()
            ->getOneOrNullResult();
    }

    private function publishedQueryBuilder(ReportSearchCriteria $criteria): QueryBuilder
    {
        $qb = $this->createQueryBuilder('report')
            ->leftJoin('report.country', 'country')
            ->andWhere('report.status = :status')
            ->setParameter('status', ReportStatus::Published);

        if (null !== $criteria->type) {
            $qb->andWhere('report.type = :type')
                ->setParameter('type', $criteria->type);
        }

        if (null !== $criteria->countryIsoCode) {
            $qb->andWhere('country.isoCode = :countryIsoCode')
                ->setParameter('countryIsoCode', $criteria->countryIsoCode);
        }

        return $qb;
    }

    /**
     * A2.8. Published only - a Draft report isn't meant to be publicly
     * visible yet (App\Controller\SearchController is PUBLIC_ACCESS, same
     * reasoning as GET /api/funding). Case- and accent-insensitive
     * (UNACCENT() on both sides - see App\Doctrine\DQL\UnaccentFunction).
     * Ordered by title for a deterministic result order.
     *
     * @return list<Report>
     */
    public function searchPublishedByTitle(SearchQuery $searchQuery, int $limit): array
    {
        return $this->createQueryBuilder('report')
            ->andWhere('UNACCENT(LOWER(report.title)) LIKE UNACCENT(:pattern)')
            ->andWhere('report.status = :status')
            ->setParameter('pattern', $searchQuery->likePattern())
            ->setParameter('status', ReportStatus::Published)
            ->orderBy('report.title', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
