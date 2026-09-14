<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\NdcTarget;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<NdcTarget>
 */
class NdcTargetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NdcTarget::class);
    }

    public function findOneByCountry(int $countryId): ?NdcTarget
    {
        return $this->findOneBy(['country' => $countryId]);
    }
}
