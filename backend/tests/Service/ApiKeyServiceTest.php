<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Enum\UserRole;
use App\Entity\User;
use App\Security\ApiKeyQuotaPolicy;
use App\Service\ApiKeyService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ApiKeyServiceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private ApiKeyService $apiKeyService;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->apiKeyService = $container->get(ApiKeyService::class);
        $this->entityManager->getConnection()->beginTransaction();
    }

    private function persistUser(UserRole $role, string $email): User
    {
        $user = new User('Amina Diallo', $email, 'hashed-password', $role);
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    public function testGenerateKeyStoresOnlyTheHashNotThePlainKey(): void
    {
        $user = $this->persistUser(UserRole::ExternalPartner, 'apikey-hash@example.com');

        $generated = $this->apiKeyService->generateKey($user);

        self::assertNotSame($generated->plainKey, $generated->apiKey->getKeyHash());
        self::assertSame($this->apiKeyService->hashKey($generated->plainKey), $generated->apiKey->getKeyHash());
        self::assertStringStartsWith('nev_', $generated->plainKey);
    }

    public function testGeneratedKeyHasHighEntropyAndIsUniquePerCall(): void
    {
        $user = $this->persistUser(UserRole::ExternalPartner, 'apikey-entropy@example.com');

        $first = $this->apiKeyService->generateKey($user);
        $second = $this->apiKeyService->generateKey($user);

        self::assertNotSame($first->plainKey, $second->plainKey);
        // "nev_" (4 chars) + 32 random bytes hex-encoded (64 chars).
        self::assertSame(68, \strlen($first->plainKey));
    }

    public function testHashKeyIsDeterministic(): void
    {
        self::assertSame(
            $this->apiKeyService->hashKey('nev_same-value'),
            $this->apiKeyService->hashKey('nev_same-value'),
        );
        self::assertSame(hash('sha256', 'nev_same-value'), $this->apiKeyService->hashKey('nev_same-value'));
    }

    public function testValidateKeyReturnsTheApiKeyForAnActiveKey(): void
    {
        $user = $this->persistUser(UserRole::ExternalPartner, 'apikey-active@example.com');
        $generated = $this->apiKeyService->generateKey($user);

        $validated = $this->apiKeyService->validateKey($generated->plainKey);

        self::assertNotNull($validated);
        self::assertSame($generated->apiKey->getId(), $validated->getId());
    }

    public function testValidateKeyReturnsNullForAnUnknownKey(): void
    {
        self::assertNull($this->apiKeyService->validateKey('nev_this-key-does-not-exist'));
    }

    public function testValidateKeyReturnsNullForARevokedKey(): void
    {
        $user = $this->persistUser(UserRole::ExternalPartner, 'apikey-revoked@example.com');
        $generated = $this->apiKeyService->generateKey($user);

        $this->apiKeyService->revokeKey($generated->apiKey);

        self::assertNull($this->apiKeyService->validateKey($generated->plainKey));
    }

    public function testQuotaIsAssignedAccordingToRoleWithAdminHighest(): void
    {
        $quotaPolicy = self::getContainer()->get(ApiKeyQuotaPolicy::class);

        $admin = $this->persistUser(UserRole::Admin, 'apikey-admin@example.com');
        $analyst = $this->persistUser(UserRole::InternalAnalyst, 'apikey-analyst@example.com');
        $partner = $this->persistUser(UserRole::ExternalPartner, 'apikey-partner@example.com');

        $adminKey = $this->apiKeyService->generateKey($admin);
        $analystKey = $this->apiKeyService->generateKey($analyst);
        $partnerKey = $this->apiKeyService->generateKey($partner);

        self::assertSame($quotaPolicy->quotaForRole(UserRole::Admin), $adminKey->apiKey->getQuota());
        self::assertSame($quotaPolicy->quotaForRole(UserRole::InternalAnalyst), $analystKey->apiKey->getQuota());
        self::assertSame($quotaPolicy->quotaForRole(UserRole::ExternalPartner), $partnerKey->apiKey->getQuota());

        self::assertGreaterThan($analystKey->apiKey->getQuota(), $adminKey->apiKey->getQuota());
        self::assertGreaterThan($partnerKey->apiKey->getQuota(), $analystKey->apiKey->getQuota());
    }

    protected function tearDown(): void
    {
        $connection = $this->entityManager->getConnection();
        if ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
        $this->entityManager->close();
        parent::tearDown();
    }
}
