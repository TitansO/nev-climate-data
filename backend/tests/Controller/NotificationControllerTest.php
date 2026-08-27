<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Enum\NotificationType;
use App\Entity\Enum\UserRole;
use App\Entity\Notification;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class NotificationControllerTest extends WebTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
    }

    protected function tearDown(): void
    {
        if (isset($this->entityManager)) {
            $connection = $this->entityManager->getConnection();
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $this->entityManager->close();
        }
        parent::tearDown();
    }

    private function beginTransaction(KernelBrowser $client): void
    {
        $client->disableReboot();
        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();
    }

    private function createUser(string $email): User
    {
        $user = new User('Amina Diallo', $email, password_hash('correct-horse-battery-staple', PASSWORD_DEFAULT), UserRole::ExternalPartner);
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    /**
     * @return array{token: string, refresh_token: string}
     */
    private function loginAndGetTokens(KernelBrowser $client, string $email): array
    {
        $client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['email' => $email, 'password' => 'correct-horse-battery-staple']));

        return json_decode($client->getResponse()->getContent(), true);
    }

    private function createNotification(User $user, NotificationType $type, string $content, bool $isRead = false): Notification
    {
        $notification = new Notification($user, $type, $content);
        if ($isRead) {
            $notification->markAsRead();
        }
        $this->entityManager->persist($notification);
        $this->entityManager->flush();

        return $notification;
    }

    public function testListWithoutAuthenticationFails(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/notifications');

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testListReturnsOnlyTheCallersNotificationsNewestFirst(): void
    {
        $client = static::createClient();
        $this->beginTransaction($client);
        $me = $this->createUser('notif-me@example.com');
        $someoneElse = $this->createUser('notif-someone-else@example.com');
        $tokens = $this->loginAndGetTokens($client, 'notif-me@example.com');

        $this->createNotification($me, NotificationType::NewData, 'De nouvelles données sont disponibles.');
        $this->createNotification($me, NotificationType::NewReport, 'Un nouveau rapport est publié.');
        $this->createNotification($someoneElse, NotificationType::NewData, 'Notification de quelqu\'un d\'autre.');

        $client->request('GET', '/api/notifications', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token']]);

        self::assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(2, $data['meta']['total']);
        self::assertCount(2, $data['data']);
        self::assertSame('Un nouveau rapport est publié.', $data['data'][0]['content']); // most recent first
        foreach ($data['data'] as $item) {
            self::assertSame(['id', 'eventType', 'content', 'isRead', 'createdAt', 'destination'], array_keys($item));
        }
    }

    /**
     * A2.10: a notification must be navigable, not merely informational -
     * each eventType maps to the real frontend page it's actually about.
     */
    public function testEachNotificationCarriesARealNavigableDestination(): void
    {
        $client = static::createClient();
        $this->beginTransaction($client);
        $me = $this->createUser('notif-destination@example.com');
        $tokens = $this->loginAndGetTokens($client, 'notif-destination@example.com');

        $this->createNotification($me, NotificationType::NewData, 'Nouvelles données');
        $this->createNotification($me, NotificationType::NewReport, 'Nouveau rapport');

        $client->request('GET', '/api/notifications', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token']]);

        $data = json_decode($client->getResponse()->getContent(), true);
        $byType = [];
        foreach ($data['data'] as $item) {
            $byType[$item['eventType']] = $item['destination'];
        }
        self::assertSame('data.html', $byType['new_data']);
        self::assertSame('reports.html', $byType['new_report']);
    }

    public function testListPagination(): void
    {
        $client = static::createClient();
        $this->beginTransaction($client);
        $me = $this->createUser('notif-page@example.com');
        $tokens = $this->loginAndGetTokens($client, 'notif-page@example.com');

        for ($i = 0; $i < 3; ++$i) {
            $this->createNotification($me, NotificationType::NewData, 'Notification #'.$i);
        }

        $client->request('GET', '/api/notifications?page=1&limit=2', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token']]);

        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertCount(2, $data['data']);
        self::assertSame(3, $data['meta']['total']);
        self::assertSame(2, $data['meta']['totalPages']);
    }

    public function testInvalidPageReturns400Json(): void
    {
        $client = static::createClient();
        $this->beginTransaction($client);
        $this->createUser('notif-invalid-page@example.com');
        $tokens = $this->loginAndGetTokens($client, 'notif-invalid-page@example.com');

        $client->request('GET', '/api/notifications?page=0', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token']]);

        self::assertSame(400, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(400, $data['code']);
    }

    public function testUnreadCountOnlyCountsUnreadOwnNotifications(): void
    {
        $client = static::createClient();
        $this->beginTransaction($client);
        $me = $this->createUser('notif-unread@example.com');
        $someoneElse = $this->createUser('notif-unread-other@example.com');
        $tokens = $this->loginAndGetTokens($client, 'notif-unread@example.com');

        $this->createNotification($me, NotificationType::NewData, 'Non lue 1');
        $this->createNotification($me, NotificationType::NewData, 'Non lue 2');
        $this->createNotification($me, NotificationType::NewReport, 'Déjà lue', isRead: true);
        $this->createNotification($someoneElse, NotificationType::NewData, 'Pas la mienne');

        $client->request('GET', '/api/notifications/unread-count', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token']]);

        self::assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(2, $data['count']);
    }

    public function testMarkOwnNotificationAsReadSucceeds(): void
    {
        $client = static::createClient();
        $this->beginTransaction($client);
        $me = $this->createUser('notif-mark@example.com');
        $tokens = $this->loginAndGetTokens($client, 'notif-mark@example.com');
        $notification = $this->createNotification($me, NotificationType::NewData, 'À lire');

        $client->request('PATCH', '/api/notifications/'.$notification->getId().'/read', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token']]);
        self::assertSame(204, $client->getResponse()->getStatusCode());

        $client->request('GET', '/api/notifications/unread-count', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token']]);
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(0, $data['count']);
    }

    public function testMarkingAnAlreadyReadNotificationIsIdempotent(): void
    {
        $client = static::createClient();
        $this->beginTransaction($client);
        $me = $this->createUser('notif-idempotent@example.com');
        $tokens = $this->loginAndGetTokens($client, 'notif-idempotent@example.com');
        $notification = $this->createNotification($me, NotificationType::NewData, 'Déjà lue', isRead: true);

        $client->request('PATCH', '/api/notifications/'.$notification->getId().'/read', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token']]);

        self::assertSame(204, $client->getResponse()->getStatusCode());
    }

    public function testCannotMarkAnotherUsersNotificationAsRead(): void
    {
        $client = static::createClient();
        $this->beginTransaction($client);
        $victim = $this->createUser('notif-victim@example.com');
        $attacker = $this->createUser('notif-attacker@example.com');
        $victimNotification = $this->createNotification($victim, NotificationType::NewData, 'Privée');
        $attackerTokens = $this->loginAndGetTokens($client, 'notif-attacker@example.com');

        $client->request('PATCH', '/api/notifications/'.$victimNotification->getId().'/read', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$attackerTokens['token']]);

        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testMarkingANonExistentNotificationReturns404(): void
    {
        $client = static::createClient();
        $this->beginTransaction($client);
        $this->createUser('notif-404@example.com');
        $tokens = $this->loginAndGetTokens($client, 'notif-404@example.com');

        $client->request('PATCH', '/api/notifications/999999/read', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token']]);

        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testMarkAllAsReadOnlyAffectsCallersOwnUnreadNotifications(): void
    {
        $client = static::createClient();
        $this->beginTransaction($client);
        $me = $this->createUser('notif-read-all@example.com');
        $someoneElse = $this->createUser('notif-read-all-other@example.com');
        $tokens = $this->loginAndGetTokens($client, 'notif-read-all@example.com');

        $this->createNotification($me, NotificationType::NewData, '1');
        $this->createNotification($me, NotificationType::NewData, '2');
        $this->createNotification($me, NotificationType::NewReport, 'Déjà lue', isRead: true);
        $this->createNotification($someoneElse, NotificationType::NewData, 'Pas la mienne');

        $client->request('POST', '/api/notifications/read-all', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token']]);

        self::assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(2, $data['updated']);

        $client->request('GET', '/api/notifications/unread-count', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token']]);
        self::assertSame(0, json_decode($client->getResponse()->getContent(), true)['count']);

        // The other user's notification is untouched.
        $otherTokens = $this->loginAndGetTokens($client, 'notif-read-all-other@example.com');
        $client->request('GET', '/api/notifications/unread-count', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$otherTokens['token']]);
        self::assertSame(1, json_decode($client->getResponse()->getContent(), true)['count']);
    }

    public function testNotificationEndpointsRejectApiKeyAuthentication(): void
    {
        $client = static::createClient();
        $this->beginTransaction($client);
        $this->createUser('notif-apikey-reject@example.com');
        $tokens = $this->loginAndGetTokens($client, 'notif-apikey-reject@example.com');

        $client->request('POST', '/api/api-keys', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token']]);
        $created = json_decode($client->getResponse()->getContent(), true);

        $client->request('GET', '/api/notifications', server: ['HTTP_X_API_KEY' => $created['key']]);

        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    public function testNotificationEndpointsAreDocumentedInSwagger(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/doc.json');

        self::assertResponseIsSuccessful();
        $spec = json_decode($client->getResponse()->getContent(), true);
        self::assertArrayHasKey('/api/notifications', $spec['paths']);
        self::assertArrayHasKey('/api/notifications/unread-count', $spec['paths']);
        self::assertArrayHasKey('/api/notifications/{id}/read', $spec['paths']);
        self::assertArrayHasKey('/api/notifications/read-all', $spec['paths']);
    }
}
