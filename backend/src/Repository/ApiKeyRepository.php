<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ApiKey;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ApiKey>
 */
class ApiKeyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ApiKey::class);
    }

    /**
     * Status-agnostic on purpose: ApiKeyService::validateKey() looks the key
     * up first, then checks its status separately, so a revoked key is
     * distinguishable from one that never existed if that distinction is
     * ever needed (e.g. audit logging) without a second query.
     */
    public function findOneByHash(string $keyHash): ?ApiKey
    {
        return $this->createQueryBuilder('apiKey')
            ->andWhere('apiKey.keyHash = :keyHash')
            ->setParameter('keyHash', $keyHash)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return list<ApiKey>
     */
    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('apiKey')
            ->andWhere('apiKey.user = :user')
            ->setParameter('user', $user)
            ->orderBy('apiKey.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Scoped to the owning user directly in the query, rather than fetching
     * by id and checking ownership in PHP, so a mismatched id/user pair
     * behaves identically (null) to a non-existent id — no ownership detail
     * leaks through a different error path.
     */
    public function findOneForUser(int $id, User $user): ?ApiKey
    {
        return $this->createQueryBuilder('apiKey')
            ->andWhere('apiKey.id = :id')
            ->andWhere('apiKey.user = :user')
            ->setParameter('id', $id)
            ->setParameter('user', $user)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
