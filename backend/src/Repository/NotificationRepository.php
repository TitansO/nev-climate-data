<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Notification;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Notification>
 */
class NotificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Notification::class);
    }

    /**
     * @return list<Notification>
     */
    public function findByUser(User $user, int $page, int $limit): array
    {
        return $this->createQueryBuilder('notification')
            ->andWhere('notification.user = :user')
            ->setParameter('user', $user)
            ->orderBy('notification.createdAt', 'DESC')
            ->addOrderBy('notification.id', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countByUser(User $user): int
    {
        return (int) $this->createQueryBuilder('notification')
            ->select('COUNT(notification.id)')
            ->andWhere('notification.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countUnreadByUser(User $user): int
    {
        return (int) $this->createQueryBuilder('notification')
            ->select('COUNT(notification.id)')
            ->andWhere('notification.user = :user')
            ->andWhere('notification.isRead = false')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Scoped to the owning user directly in the query, rather than fetching
     * by id and checking ownership in PHP, so a mismatched id/user pair
     * behaves identically (null) to a non-existent id - no ownership detail
     * leaks through a different error path (same pattern as
     * ApiKeyRepository::findOneForUser()).
     */
    public function findOneForUser(int $id, User $user): ?Notification
    {
        return $this->createQueryBuilder('notification')
            ->andWhere('notification.id = :id')
            ->andWhere('notification.user = :user')
            ->setParameter('id', $id)
            ->setParameter('user', $user)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Bulk UPDATE rather than fetch-then-loop-then-flush: "mark everything
     * read" has no need to hydrate each Notification into memory just to
     * flip one column, and this stays a single round-trip regardless of how
     * many rows match. Returns the number of rows actually changed.
     */
    public function markAllAsReadForUser(User $user): int
    {
        return $this->createQueryBuilder('notification')
            ->update()
            ->set('notification.isRead', 'true')
            ->andWhere('notification.user = :user')
            ->andWhere('notification.isRead = false')
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();
    }
}
