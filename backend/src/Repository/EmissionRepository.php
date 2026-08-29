<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Emission;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Emission>
 *
 * No custom queries yet - B1.4's scope is collection only (see the spec's
 * "Scope boundary"); AnalyticsService is not wired to read from this table
 * as part of this task.
 */
class EmissionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Emission::class);
    }
}
