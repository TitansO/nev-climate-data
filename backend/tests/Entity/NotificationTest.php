<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Enum\NotificationType;
use App\Entity\Enum\UserRole;
use App\Entity\Notification;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class NotificationTest extends TestCase
{
    public function testConstructorSetsDefaults(): void
    {
        $user = new User('Amina Diallo', 'amina@example.com', 'hashed-password', UserRole::InternalAnalyst);
        $notification = new Notification($user, NotificationType::NewReport, 'A new report was published.');

        self::assertNull($notification->getId());
        self::assertSame($user, $notification->getUser());
        self::assertSame(NotificationType::NewReport, $notification->getEventType());
        self::assertSame('A new report was published.', $notification->getContent());
        self::assertFalse($notification->isRead());
        self::assertInstanceOf(\DateTimeImmutable::class, $notification->getCreatedAt());
    }

    public function testMarkAsReadUpdatesFlag(): void
    {
        $user = new User('Amina Diallo', 'amina@example.com', 'hashed-password', UserRole::InternalAnalyst);
        $notification = new Notification($user, NotificationType::NewData, 'New data is available for Senegal.');

        $notification->markAsRead();

        self::assertTrue($notification->isRead());
    }
}
