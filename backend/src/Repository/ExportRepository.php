<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Export;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Export>
 */
class ExportRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Export::class);
    }

    /**
     * Scoped to the owning user directly in the query, rather than fetching
     * by id and checking ownership in PHP - same pattern as
     * ApiKeyRepository::findOneForUser() and
     * NotificationRepository::findOneForUser() - so a mismatched id/user
     * pair behaves identically (null) to a non-existent id.
     */
    public function findOneForUser(int $id, User $user): ?Export
    {
        return $this->createQueryBuilder('export')
            ->andWhere('export.id = :id')
            ->andWhere('export.user = :user')
            ->setParameter('id', $id)
            ->setParameter('user', $user)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
