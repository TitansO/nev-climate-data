<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Enum\UserRole;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ApiKeyControllerTest extends WebTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
    }

    private function createTestUser(
        KernelBrowser $client,
        string $email,
        string $plainPassword = 'correct-horse-battery-staple',
        UserRole $role = UserRole::ExternalPartner,
    ): User {
        // See AuthenticationControllerTest for why disableReboot() + a
        // shared, rolled-back transaction is required here.
        $client->disableReboot();

        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();

        $user = new User('Amina Diallo', $email, password_hash($plainPassword, PASSWORD_DEFAULT), $role);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    /**
     * @return array{token: string, refresh_token: string}
     */
    private function loginAndGetTokens(KernelBrowser $client, string $email, string $plainPassword = 'correct-horse-battery-staple'): array
    {
        $client->request(
            'POST',
            '/api/auth/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email' => $email, 'password' => $plainPassword]),
        );

        return json_decode($client->getResponse()->getContent(), true);
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

    public function testCreateWithoutAuthenticationFails(): void
    {
        $client = static::createClient();

        $client->request('POST', '/api/api-keys');

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testCreateReturnsThePlainKeyOnceAndOnlyTheHashIsStored(): void
    {
        $client = static::createClient();
        $this->createTestUser($client, 'apikey-create@example.com');
        $tokens = $this->loginAndGetTokens($client, 'apikey-create@example.com');

        $client->request('POST', '/api/api-keys', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token']]);

        self::assertSame(201, $client->getResponse()->getStatusCode());
        $data = json_decode($client->getResponse()->getContent(), true);

        self::assertArrayHasKey('key', $data);
        self::assertStringStartsWith('nev_', $data['key']);
        self::assertArrayNotHasKey('keyHash', $data);
        self::assertArrayNotHasKey('key_hash', $data);
        self::assertSame('active', $data['status']);

        // The database column truly holds a hash, never the plaintext.
        $storedHash = $this->entityManager->getConnection()
            ->fetchOne('SELECT key_hash FROM api_key WHERE id = ?', [$data['id']]);
        self::assertSame(hash('sha256', $data['key']), $storedHash);
        self::assertNotSame($data['key'], $storedHash);
    }

    public function testCreateAssignsQuotaAccordingToRole(): void
    {
        $client = static::createClient();
        $this->createTestUser($client, 'apikey-admin-quota@example.com', role: UserRole::Admin);
        $tokens = $this->loginAndGetTokens($client, 'apikey-admin-quota@example.com');

        $client->request('POST', '/api/api-keys', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token']]);

        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(100_000, $data['quota']);
    }

    public function testListWithoutAuthenticationFails(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/api-keys');

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testListReturnsOnlyTheCallersKeysWithoutHashOrPlainKey(): void
    {
        $client = static::createClient();
        $this->createTestUser($client, 'apikey-list-owner@example.com');
        $tokens = $this->loginAndGetTokens($client, 'apikey-list-owner@example.com');

        $client->request('POST', '/api/api-keys', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token']]);
        $created = json_decode($client->getResponse()->getContent(), true);

        $client->request('GET', '/api/api-keys', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token']]);

        self::assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);

        self::assertCount(1, $data);
        self::assertSame($created['id'], $data[0]['id']);
        self::assertArrayNotHasKey('key', $data[0]);
        self::assertArrayNotHasKey('keyHash', $data[0]);
        self::assertArrayNotHasKey('key_hash', $data[0]);
    }

    public function testRevokeOwnKeySucceeds(): void
    {
        $client = static::createClient();
        $this->createTestUser($client, 'apikey-revoke-owner@example.com');
        $tokens = $this->loginAndGetTokens($client, 'apikey-revoke-owner@example.com');

        $client->request('POST', '/api/api-keys', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token']]);
        $created = json_decode($client->getResponse()->getContent(), true);

        $client->request('DELETE', '/api/api-keys/'.$created['id'], server: ['HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token']]);
        self::assertSame(204, $client->getResponse()->getStatusCode());

        $client->request('GET', '/api/api-keys', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token']]);
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('revoked', $data[0]['status']);
    }

    public function testCannotRevokeAnotherUsersKey(): void
    {
        $client = static::createClient();
        $this->createTestUser($client, 'apikey-victim@example.com');
        $victimTokens = $this->loginAndGetTokens($client, 'apikey-victim@example.com');

        $client->request('POST', '/api/api-keys', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$victimTokens['token']]);
        $victimKey = json_decode($client->getResponse()->getContent(), true);

        // A second account, created within the same shared transaction.
        $attacker = new User('Kwame Mensah', 'apikey-attacker@example.com', password_hash('correct-horse-battery-staple', PASSWORD_DEFAULT), UserRole::ExternalPartner);
        $this->entityManager->persist($attacker);
        $this->entityManager->flush();
        $attackerTokens = $this->loginAndGetTokens($client, 'apikey-attacker@example.com');

        $client->request('DELETE', '/api/api-keys/'.$victimKey['id'], server: ['HTTP_AUTHORIZATION' => 'Bearer '.$attackerTokens['token']]);

        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testManagementEndpointsRejectApiKeyAuthentication(): void
    {
        $client = static::createClient();
        $this->createTestUser($client, 'apikey-self-mint@example.com');
        $tokens = $this->loginAndGetTokens($client, 'apikey-self-mint@example.com');

        $client->request('POST', '/api/api-keys', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token']]);
        $created = json_decode($client->getResponse()->getContent(), true);

        // The freshly-minted API key must not itself be usable to create,
        // list, or revoke further keys — see ApiKeyController::assertJwtAuthenticated().
        $client->request('POST', '/api/api-keys', server: ['HTTP_X_API_KEY' => $created['key']]);
        self::assertSame(403, $client->getResponse()->getStatusCode());

        $client->request('GET', '/api/api-keys', server: ['HTTP_X_API_KEY' => $created['key']]);
        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    public function testApiKeyAuthenticatesAnExistingProtectedEndpoint(): void
    {
        $client = static::createClient();
        $this->createTestUser($client, 'apikey-auth-me@example.com');
        $tokens = $this->loginAndGetTokens($client, 'apikey-auth-me@example.com');

        $client->request('POST', '/api/api-keys', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token']]);
        $created = json_decode($client->getResponse()->getContent(), true);

        $client->request('GET', '/api/auth/me', server: ['HTTP_X_API_KEY' => $created['key']]);

        self::assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('apikey-auth-me@example.com', $data['email']);
    }

    public function testUnknownApiKeyIsRejected(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/auth/me', server: ['HTTP_X_API_KEY' => 'nev_does-not-exist']);

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testRevokedApiKeyIsRejected(): void
    {
        $client = static::createClient();
        $this->createTestUser($client, 'apikey-revoked-auth@example.com');
        $tokens = $this->loginAndGetTokens($client, 'apikey-revoked-auth@example.com');

        $client->request('POST', '/api/api-keys', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token']]);
        $created = json_decode($client->getResponse()->getContent(), true);

        $client->request('DELETE', '/api/api-keys/'.$created['id'], server: ['HTTP_AUTHORIZATION' => 'Bearer '.$tokens['token']]);
        self::assertSame(204, $client->getResponse()->getStatusCode());

        $client->request('GET', '/api/auth/me', server: ['HTTP_X_API_KEY' => $created['key']]);

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testJwtLoginAndRefreshStillWorkAlongsideApiKeyAuthentication(): void
    {
        $client = static::createClient();
        $this->createTestUser($client, 'apikey-jwt-regression@example.com');

        $tokens = $this->loginAndGetTokens($client, 'apikey-jwt-regression@example.com');
        self::assertArrayHasKey('token', $tokens);
        self::assertArrayHasKey('refresh_token', $tokens);

        $client->request(
            'POST',
            '/api/auth/refresh',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['refresh_token' => $tokens['refresh_token']]),
        );
        self::assertResponseIsSuccessful();
    }
}
