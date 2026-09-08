<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Emission;
use App\Entity\Enum\ValidationStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Emission>
 */
class EmissionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Emission::class);
    }

    /**
     * Years for which at least one *current* (non-historized) row exists,
     * most recent first - the candidate pool for B3.2's year-over-year
     * comparison (AnalyticsService::getCo2Reduction()).
     *
     * @return list<int>
     */
    public function distinctYearsWithCurrentData(): array
    {
        return array_map(
            static fn (array $row): int => (int) $row['year'],
            $this->createQueryBuilder('emission')
                ->select('DISTINCT emission.year AS year')
                ->where('emission.isCurrent = true')
                ->andWhere('emission.validationStatus = :validated')
                ->setParameter('validated', ValidationStatus::Validated)
                ->orderBy('emission.year', 'DESC')
                ->getQuery()
                ->getScalarResult(),
        );
    }

    /**
     * Total valueMt per country for a given year, current rows only.
     *
     * @return array<int, float> keyed by country id
     */
    public function totalsByYear(int $year): array
    {
        $rows = $this->createQueryBuilder('emission')
            ->select('IDENTITY(emission.country) AS countryId', 'SUM(emission.valueMt) AS total')
            ->where('emission.year = :year')
            ->andWhere('emission.isCurrent = true')
            ->andWhere('emission.validationStatus = :validated')
            ->groupBy('emission.country')
            ->setParameter('year', $year)
            ->setParameter('validated', ValidationStatus::Validated)
            ->getQuery()
            ->getScalarResult();

        return array_combine(
            array_map(static fn (array $row): int => (int) $row['countryId'], $rows),
            array_map(static fn (array $row): float => (float) $row['total'], $rows),
        );
    }
}
