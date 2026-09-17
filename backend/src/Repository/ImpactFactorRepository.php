<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ImpactFactor;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ImpactFactor>
 */
class ImpactFactorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ImpactFactor::class);
    }

    public function findOneBySectorId(int $sectorId): ?ImpactFactor
    {
        return $this->findOneBy(['sector' => $sectorId]);
    }
}
