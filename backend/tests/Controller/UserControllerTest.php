<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Enum\UserRole;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class UserControllerTest extends WebTestCase
{
    private const PASSWORD = 'correct-horse-battery-staple';

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

    private function createUser(string $email, UserRole $role = UserRole::ExternalPartner): User
    {
        $user = new User('Test User', $email, password_hash(self::PASSWORD, \PASSWORD_DEFAULT), $role);
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    /**
     * @return array{token: string, refresh_token: string}
     */
    private function loginAndGetTokens(KernelBrowser $client, string $email): array
    {
        $client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['email' => $email, 'password' => self::PASSWORD]));

        return json_decode($client->getResponse()->getContent(), true);
    }

    public function testListWithoutAuthenticationFails(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/users');

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testListAsNonSuperAdminIsForbidden(): void
    {
        $client = static::createClient();
        $this->beginTransaction($client);
        $this->createUser('users-admin@example.com', UserRole::Admin);
        $tokens = $this->loginAndGetTokens($client, 'users-admin@example.com');

        $client->request('GET', '/api/users', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token']]);

        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    public function testListAsSuperAdminReturnsEveryUser(): void
    {
        $client = static::createClient();
        $this->beginTransaction($client);
        $this->createUser('users-super@example.com', UserRole::SuperAdmin);
        $this->createUser('users-other@example.com', UserRole::InternalAnalyst);
        $tokens = $this->loginAndGetTokens($client, 'users-super@example.com');

        $client->request('GET', '/api/users', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token']]);

        self::assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertCount(2, $data);
        foreach ($data as $item) {
            self::assertSame(['id', 'name', 'email', 'role', 'createdAt'], array_keys($item));
        }
    }

    public function testCreateAsSuperAdminSucceedsAndTheNewAccountCanLogIn(): void
    {
        $client = static::createClient();
        $this->beginTransaction($client);
        $this->createUser('users-creator@example.com', UserRole::SuperAdmin);
        $tokens = $this->loginAndGetTokens($client, 'users-creator@example.com');

        $client->request(
            'POST',
            '/api/users',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token']],
            content: json_encode(['name' => 'Nouveau Membre', 'email' => 'nouveau@example.com', 'password' => 'UnMotDePasseSolide2026!', 'role' => 'internal_analyst']),
        );

        self::assertSame(201, $client->getResponse()->getStatusCode());
        $created = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('nouveau@example.com', $created['email']);
        self::assertSame('internal_analyst', $created['role']);
        self::assertArrayNotHasKey('password', $created);

        $client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['email' => 'nouveau@example.com', 'password' => 'UnMotDePasseSolide2026!']));
        self::assertResponseIsSuccessful();
    }

    public function testCreateAsNonSuperAdminIsForbidden(): void
    {
        $client = static::createClient();
        $this->beginTransaction($client);
        $this->createUser('users-admin-create@example.com', UserRole::Admin);
        $tokens = $this->loginAndGetTokens($client, 'users-admin-create@example.com');

        $client->request(
            'POST',
            '/api/users',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token']],
            content: json_encode(['name' => 'X', 'email' => 'blocked@example.com', 'password' => 'UnMotDePasseSolide2026!', 'role' => 'admin']),
        );

        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    public function testCreateWithDuplicateEmailReturns409(): void
    {
        $client = static::createClient();
        $this->beginTransaction($client);
        $this->createUser('users-dup-super@example.com', UserRole::SuperAdmin);
        $this->createUser('users-taken@example.com');
        $tokens = $this->loginAndGetTokens($client, 'users-dup-super@example.com');

        $client->request(
            'POST',
            '/api/users',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token']],
            content: json_encode(['name' => 'X', 'email' => 'users-taken@example.com', 'password' => 'UnMotDePasseSolide2026!', 'role' => 'admin']),
        );

        self::assertSame(409, $client->getResponse()->getStatusCode());
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function invalidCreatePayloadProvider(): iterable
    {
        yield 'missing name' => [['email' => 'a@example.com', 'password' => 'UnMotDePasseSolide2026!', 'role' => 'admin']];
        yield 'malformed email' => [['name' => 'X', 'email' => 'not-an-email', 'password' => 'UnMotDePasseSolide2026!', 'role' => 'admin']];
        yield 'short password' => [['name' => 'X', 'email' => 'b@example.com', 'password' => 'short', 'role' => 'admin']];
        yield 'unknown role' => [['name' => 'X', 'email' => 'c@example.com', 'password' => 'UnMotDePasseSolide2026!', 'role' => 'root']];
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('invalidCreatePayloadProvider')]
    public function testCreateWithInvalidPayloadReturns400(array $payload): void
    {
        $client = static::createClient();
        $this->beginTransaction($client);
        $this->createUser('users-validator-super@example.com', UserRole::SuperAdmin);
        $tokens = $this->loginAndGetTokens($client, 'users-validator-super@example.com');

        $client->request(
            'POST',
            '/api/users',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token']],
            content: json_encode($payload),
        );

        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    public function testUpdateRoleAsSuperAdminSucceeds(): void
    {
        $client = static::createClient();
        $this->beginTransaction($client);
        $this->createUser('users-promoter@example.com', UserRole::SuperAdmin);
        $target = $this->createUser('users-target@example.com', UserRole::ExternalPartner);
        $tokens = $this->loginAndGetTokens($client, 'users-promoter@example.com');

        $client->request(
            'PATCH',
            '/api/users/'.$target->getId().'/role',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token']],
            content: json_encode(['role' => 'internal_analyst']),
        );

        self::assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('internal_analyst', $data['role']);
    }

    public function testCannotChangeOwnRole(): void
    {
        $client = static::createClient();
        $this->beginTransaction($client);
        $self = $this->createUser('users-self-role@example.com', UserRole::SuperAdmin);
        $tokens = $this->loginAndGetTokens($client, 'users-self-role@example.com');

        $client->request(
            'PATCH',
            '/api/users/'.$self->getId().'/role',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token']],
            content: json_encode(['role' => 'admin']),
        );

        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    public function testUpdateRoleOnNonExistentUserReturns404(): void
    {
        $client = static::createClient();
        $this->beginTransaction($client);
        $this->createUser('users-404-super@example.com', UserRole::SuperAdmin);
        $tokens = $this->loginAndGetTokens($client, 'users-404-super@example.com');

        $client->request(
            'PATCH',
            '/api/users/999999/role',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token']],
            content: json_encode(['role' => 'admin']),
        );

        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testDeleteAsSuperAdminSucceeds(): void
    {
        $client = static::createClient();
        $this->beginTransaction($client);
        $this->createUser('users-deleter@example.com', UserRole::SuperAdmin);
        $target = $this->createUser('users-doomed@example.com');
        $tokens = $this->loginAndGetTokens($client, 'users-deleter@example.com');

        $client->request('DELETE', '/api/users/'.$target->getId(), server: ['HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token']]);

        self::assertSame(204, $client->getResponse()->getStatusCode());

        $client->request('GET', '/api/users', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token']]);
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertCount(1, $data);
    }

    public function testCannotDeleteOwnAccount(): void
    {
        $client = static::createClient();
        $this->beginTransaction($client);
        $self = $this->createUser('users-self-delete@example.com', UserRole::SuperAdmin);
        $tokens = $this->loginAndGetTokens($client, 'users-self-delete@example.com');

        $client->request('DELETE', '/api/users/'.$self->getId(), server: ['HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token']]);

        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    public function testDeleteOnNonExistentUserReturns404(): void
    {
        $client = static::createClient();
        $this->beginTransaction($client);
        $this->createUser('users-404-delete-super@example.com', UserRole::SuperAdmin);
        $tokens = $this->loginAndGetTokens($client, 'users-404-delete-super@example.com');

        $client->request('DELETE', '/api/users/999999', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token']]);

        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testUserEndpointsRejectApiKeyAuthentication(): void
    {
        $client = static::createClient();
        $this->beginTransaction($client);
        $this->createUser('users-apikey-reject@example.com', UserRole::SuperAdmin);
        $tokens = $this->loginAndGetTokens($client, 'users-apikey-reject@example.com');

        $client->request('POST', '/api/api-keys', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token']]);
        $created = json_decode($client->getResponse()->getContent(), true);

        $client->request('GET', '/api/users', server: ['HTTP_X_API_KEY' => $created['key']]);

        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    public function testUserEndpointsAreDocumentedInSwagger(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/doc.json');

        self::assertResponseIsSuccessful();
        $spec = json_decode($client->getResponse()->getContent(), true);
        self::assertArrayHasKey('/api/users', $spec['paths']);
        self::assertArrayHasKey('/api/users/{id}/role', $spec['paths']);
        self::assertArrayHasKey('/api/users/{id}', $spec['paths']);
    }
}
