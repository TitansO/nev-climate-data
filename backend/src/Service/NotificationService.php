<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Enum\NotificationType;
use App\Entity\Notification;
use App\Entity\User;
use App\Repository\NotificationRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Central authority for notification creation and read-state (A2.4).
 * Controllers stay thin and only translate between HTTP and this service -
 * same split as App\Service\ApiKeyService.
 *
 * notify() exists for other parts of the app to raise a real Notification
 * event (e.g. a future Report-publication flow producing NewReport). A2.3's
 * export is synchronous - the client gets the file directly in the same
 * response, so there is no later "export ready" event for it to call this
 * with; see the A2.3/A2.4 implementation report for that reasoning.
 */
final class NotificationService
{
    public function __construct(
        private readonly NotificationRepository $notificationRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function notify(User $user, NotificationType $eventType, string $content): Notification
    {
        $notification = new Notification($user, $eventType, $content);

        $this->entityManager->persist($notification);
        $this->entityManager->flush();

        return $notification;
    }

    public function markAsRead(Notification $notification): void
    {
        if ($notification->isRead()) {
            return;
        }

        $notification->markAsRead();
        $this->entityManager->flush();
    }

    /**
     * @return int the number of notifications actually flipped to read
     */
    public function markAllAsRead(User $user): int
    {
        return $this->notificationRepository->markAllAsReadForUser($user);
    }
}
