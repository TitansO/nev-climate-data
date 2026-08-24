<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Enum\NotificationType;
use App\Entity\Enum\UserRole;
use App\Entity\Notification;
use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * A small set of demonstration notifications spread across the 3 demo
 * users, mixing both NotificationType values and both read/unread states
 * (via the entity's own markAsRead(), never bypassing its encapsulation).
 * Notification has no FK to Report or Funding (see Notification.php) —
 * content is free text that reads naturally, not a reference to a specific
 * fixture record.
 */
final class NotificationFixtures extends Fixture implements DependentFixtureInterface
{
    public function getDependencies(): array
    {
        return [UserFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        /** @var User $admin */
        $admin = $this->getReference(UserFixtures::userReference(UserRole::Admin), User::class);
        /** @var User $analyst */
        $analyst = $this->getReference(UserFixtures::userReference(UserRole::InternalAnalyst), User::class);
        /** @var User $partner */
        $partner = $this->getReference(UserFixtures::userReference(UserRole::ExternalPartner), User::class);

        $notifications = [
            [$admin, NotificationType::NewReport, 'The report "2025 Global Climate Finance Overview" has been published.', true],
            [$admin, NotificationType::NewData, 'New funding data was collected for 5 countries this week.', false],
            [$analyst, NotificationType::NewReport, 'The report "West Africa Regional Climate Finance Report" has been published.', true],
            [$analyst, NotificationType::NewData, 'New funding data was collected for the Renewable Energy sector.', false],
            [$analyst, NotificationType::NewReport, 'The report "Senegal — Climate Finance Country Profile" has been published.', false],
            [$partner, NotificationType::NewReport, 'The report "Nigeria — Climate Finance Country Profile" has been published.', true],
            [$partner, NotificationType::NewData, 'New funding data was collected for the Adaptation sector.', false],
        ];

        foreach ($notifications as [$user, $eventType, $content, $isRead]) {
            $notification = new Notification($user, $eventType, $content);
            if ($isRead) {
                $notification->markAsRead();
            }
            $manager->persist($notification);
        }

        $manager->flush();
    }
}
