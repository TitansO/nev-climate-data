<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Enum\UserRole;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AuthenticationControllerTest extends WebTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
    }

    private function createTestUser(
        KernelBrowser $client,
        string $email = 'amina@example.com',
        string $plainPassword = 'correct-horse-battery-staple',
        UserRole $role = UserRole::InternalAnalyst,
    ): User {
        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->entityManager->getConnection()->beginTransaction();

        // Hashed directly via PHP's native password_hash() rather than fetched
        // from the container: Symfony's "auto" hasher resolves to a hasher
        // whose verify() delegates to password_verify(), which auto-detects
        // the algorithm from the hash itself — so a plain PASSWORD_DEFAULT
        // hash verifies correctly regardless of which concrete algorithm
        // "auto" picks, without depending on a container service that may be
        // compiled away if nothing else in the app graph references it.
        $user = new User('Amina Diallo', $email, password_hash($plainPassword, PASSWORD_DEFAULT), $role);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
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

    public function testLoginWithValidCredentialsReturnsTokenPair(): void
    {
        $client = static::createClient();
        $this->createTestUser($client, 'amina@example.com', 'correct-horse-battery-staple');

        $client->request(
            'POST',
            '/api/auth/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email' => 'amina@example.com', 'password' => 'correct-horse-battery-staple']),
        );

        self::assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertArrayHasKey('token', $data);
        self::assertArrayHasKey('refresh_token', $data);
    }

    public function testLoginWithWrongPasswordFails(): void
    {
        $client = static::createClient();
        $this->createTestUser($client, 'amina2@example.com', 'correct-horse-battery-staple');

        $client->request(
            'POST',
            '/api/auth/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email' => 'amina2@example.com', 'password' => 'wrong-password']),
        );

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testLoginWithUnknownEmailFails(): void
    {
        $client = static::createClient();

        $client->request(
            'POST',
            '/api/auth/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email' => 'nobody@example.com', 'password' => 'whatever']),
        );

        self::assertSame(401, $client->getResponse()->getStatusCode());
    }
}
